package runr

import (
	"context"
	"encoding/json"
	"net/http"
)

// Secret variables of a project (GET/PUT/DELETE /projects/{project}/secrets…): values are
// write-only; reading one back takes secrets.view_values and is recorded in the audit log.

// Limits of secret variables (App\Models\ProjectSecret).
const (
	MaxSecretsPerProject = 100
	MaxSecretBytes       = 32768
)

// SecretRef is the user who last changed a secret.
type SecretRef struct {
	ID   FlexID `json:"id"`
	Name string `json:"name"`
}

// Secret is one secret variable: its name and when and by whom it was last changed.
type Secret struct {
	Name      string     `json:"name"`
	UpdatedAt string     `json:"updated_at,omitempty"`
	UpdatedBy *SecretRef `json:"updated_by,omitempty"`
}

// SecretList is the answer of GET /projects/{project}/secrets.
type SecretList struct {
	Secrets []Secret `json:"secrets"`
	// CanViewValues tells whether the caller may read values back (secrets.view_values).
	CanViewValues bool `json:"can_view_values"`
}

// ListSecrets returns the names of a project's secret variables.
func (c *Client) ListSecrets(ctx context.Context, projectID string) (*SecretList, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/secrets"}, &raw); err != nil {
		return nil, err
	}
	out := &SecretList{}
	if err := decodeList(raw, &out.Secrets, nil); err != nil {
		return nil, err
	}
	var meta struct {
		CanViewValues bool `json:"can_view_values"`
	}
	_ = json.Unmarshal(raw, &meta)
	out.CanViewValues = meta.CanViewValues
	if out.Secrets == nil {
		out.Secrets = []Secret{}
	}
	return out, nil
}

// SetSecret creates or replaces a secret variable. It reaches the workload with the next deploy.
func (c *Client) SetSecret(ctx context.Context, projectID, name, value string) (*Secret, error) {
	var out struct {
		Data Secret `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/secrets/" + seg(name),
		body: map[string]string{"value": value}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteSecret removes a secret variable.
func (c *Client) DeleteSecret(ctx context.Context, projectID, name string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/secrets/" + seg(name)}, nil)
}

// SecretValue reads a secret value back; the API requires secrets.view_values and audits it.
func (c *Client) SecretValue(ctx context.Context, projectID, name string) (string, error) {
	var out struct {
		Name  string `json:"name"`
		Value string `json:"value"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/secrets/" + seg(name) + "/value"}, &out)
	return out.Value, err
}

// ErrSecretValuesForbidden is what RevealEnv returns when the caller may not read values back.
func ErrSecretValuesForbidden(p *Project) error {
	return &APIError{StatusCode: 403, Method: "GET", Path: "/projects/" + p.ID + "/secrets",
		Message:     "reading secret values of " + p.Name + " is not allowed (requires secrets.view_values)",
		Permissions: []string{"secrets.view_values"}}
}

// EnvDetails returns the variables of p with the secrets' change dates; reveal reads secret
// values back as well (secrets.view_values). On an API without the secrets endpoints it
// returns EnvList(p).
func (c *Client) EnvDetails(ctx context.Context, p *Project, reveal bool) ([]EnvVar, error) {
	if p.Config.EnvVarsHidden {
		return nil, ErrEnvHidden(p)
	}
	vars := EnvList(p)
	if !SupportsSecretEnv(p) {
		return vars, nil
	}
	list, err := c.ListSecrets(ctx, p.ID)
	if IsNotFound(err) {
		return vars, nil
	}
	if err != nil {
		return nil, err
	}
	if reveal && len(list.Secrets) > 0 && !list.CanViewValues {
		return nil, ErrSecretValuesForbidden(p)
	}
	byName := map[string]Secret{}
	for _, s := range list.Secrets {
		byName[s.Name] = s
	}
	// The list is authoritative for secret names (the project config may be older).
	out := make([]EnvVar, 0, len(vars)+len(list.Secrets))
	for _, v := range vars {
		if !v.Secret {
			if _, isSecret := byName[v.Name]; !isSecret {
				out = append(out, v)
			}
		}
	}
	for _, s := range list.Secrets {
		v := EnvVar{Name: s.Name, Secret: true, UpdatedAt: s.UpdatedAt, UpdatedBy: s.UpdatedBy}
		if reveal {
			val, err := c.SecretValue(ctx, p.ID, s.Name)
			if err != nil {
				return nil, err
			}
			v.Value, v.Revealed = val, true
		}
		out = append(out, v)
	}
	sortEnv(out)
	return out, nil
}

func sortEnv(vars []EnvVar) {
	for i := 1; i < len(vars); i++ {
		for j := i; j > 0 && vars[j].Name < vars[j-1].Name; j-- {
			vars[j], vars[j-1] = vars[j-1], vars[j]
		}
	}
}
