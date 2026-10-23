package runr

import (
	"context"
	"net/http"
	"net/url"
)

// Private connections of a project to a managed PostgreSQL database through Skupper
// (PrivateDatabaseBindingController): of its own workload, or of one of its environments
// (docs/environments.md#private-database-connections). The workload gets the connection's
// <prefix>DATABASE_URL, <prefix>PGHOST, … from a Secret in its region; the credentials never come
// back from the API.

// PrivateConnection is a private connection as the API shows it.
type PrivateConnection struct {
	ID           string   `json:"id"`
	ProjectID    string   `json:"project_id"`
	Name         string   `json:"name"`
	ResourceName string   `json:"resource_name"`
	EnvPrefix    string   `json:"env_prefix"`
	RoutingKey   string   `json:"routing_key"`
	Port         int      `json:"port"`
	SSLMode      string   `json:"sslmode"`
	LinkKind     string   `json:"link_kind"`
	Host         string   `json:"host"`
	SecretName   string   `json:"secret_name"`
	EnvNames     []string `json:"env_names"`
	// EnvironmentID and Environment name the environment the connection belongs to; empty: the
	// project's own workload (production).
	EnvironmentID string `json:"environment_id,omitempty"`
	Environment   string `json:"environment,omitempty"`
	CreatedAt     string `json:"created_at,omitempty"`
}

// EnvironmentName is the environment of the connection: PrimaryEnvironment for the project's own
// workload.
func (p *PrivateConnection) EnvironmentName() string {
	switch {
	case p.EnvironmentID == "":
		return PrimaryEnvironment
	case p.Environment != "":
		return p.Environment
	default:
		return p.EnvironmentID
	}
}

// PrivateConnectionInput creates a private connection. LinkToken is the Skupper Link or
// AccessToken (YAML) the database's site issued; Database, Username and Password go into the
// connection's Secret in the region only.
type PrivateConnectionInput struct {
	Environment string `json:"environment,omitempty"`
	Name        string `json:"name"`
	EnvPrefix   string `json:"env_prefix,omitempty"`
	RoutingKey  string `json:"routing_key"`
	Port        int    `json:"port,omitempty"`
	SSLMode     string `json:"sslmode,omitempty"`
	LinkToken   string `json:"link_token"`
	Database    string `json:"database"`
	Username    string `json:"username"`
	Password    string `json:"password"`
}

// PrivateConnectionStatus is what the region reports about a connection's Link and Listener.
type PrivateConnectionStatus struct {
	// State is ready, pending or error.
	State    string         `json:"state"`
	Link     map[string]any `json:"link,omitempty"`
	Listener map[string]any `json:"listener,omitempty"`
	// AccessToken: a connection made from an AccessToken (redeemed into a Link).
	AccessToken map[string]any `json:"access_token,omitempty"`
}

// ListPrivateConnections returns the private connections of the project's own workload, or of one
// of its environments (name or id).
func (c *Client) ListPrivateConnections(ctx context.Context, projectID, environment string) ([]PrivateConnection, error) {
	var out struct {
		Data []PrivateConnection `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + seg(projectID) + "/private-connections", query: environmentQuery(environment)}, &out); err != nil {
		if environment != "" {
			return nil, environmentErr(err, environment)
		}
		return nil, err
	}
	return out.Data, nil
}

// FindPrivateConnection resolves a connection of the project's own workload, or of one of its
// environments, by id or name.
func (c *Client) FindPrivateConnection(ctx context.Context, projectID, environment, ref string) (*PrivateConnection, error) {
	list, err := c.ListPrivateConnections(ctx, projectID, environment)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == ref || list[i].Name == ref || list[i].ResourceName == ref {
			return &list[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "private connection", Ref: ref}
}

// CreatePrivateConnection creates a connection and applies it in the region of the workload;
// envSynced says whether the running workload already got its variables (else the next deploy
// gives them).
func (c *Client) CreatePrivateConnection(ctx context.Context, projectID string, in PrivateConnectionInput) (*PrivateConnection, bool, error) {
	var out struct {
		Data      PrivateConnection `json:"data"`
		EnvSynced bool              `json:"env_synced"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + seg(projectID) + "/private-connections", body: in}, &out); err != nil {
		if in.Environment != "" {
			return nil, false, environmentErr(err, in.Environment)
		}
		return nil, false, err
	}
	return &out.Data, out.EnvSynced, nil
}

// GetPrivateConnectionStatus returns a connection with what its region reports about it.
func (c *Client) GetPrivateConnectionStatus(ctx context.Context, projectID, id string) (*PrivateConnection, *PrivateConnectionStatus, error) {
	var out struct {
		Data   PrivateConnection       `json:"data"`
		Status PrivateConnectionStatus `json:"status"`
	}
	path := "/projects/" + seg(projectID) + "/private-connections/" + url.PathEscape(id) + "/status"
	if err := c.do(ctx, request{method: http.MethodGet, path: path}, &out); err != nil {
		return nil, nil, err
	}
	return &out.Data, &out.Status, nil
}

// DeletePrivateConnection removes a connection from its region; its variables leave the workload.
func (c *Client) DeletePrivateConnection(ctx context.Context, projectID, id string) error {
	path := "/projects/" + seg(projectID) + "/private-connections/" + url.PathEscape(id)
	return c.do(ctx, request{method: http.MethodDelete, path: path}, nil)
}
