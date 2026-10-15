package runr

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"sort"
)

// ErrUnsupported means the API has no such endpoint (an older RunR version).
var ErrUnsupported = errors.New("not supported by this RunR API")

// Permissions is the answer of GET /auth/permissions: what the caller may do. With a scoped
// API token the lists hold only what the token's scopes allow.
type Permissions struct {
	Edition       string              `json:"edition,omitempty"`
	PlatformAdmin bool                `json:"platform_admin"`
	Organization  bool                `json:"organization"`
	Role          string              `json:"role,omitempty"`
	Roles         []string            `json:"roles,omitempty"`
	Permissions   []string            `json:"permissions"`
	Projects      map[string][]string `json:"projects,omitempty"`
	TokenScoped   bool                `json:"token_scoped"`
}

// UnmarshalJSON tolerates projects encoded as [] (empty PHP array).
func (p *Permissions) UnmarshalJSON(b []byte) error {
	type plain struct {
		Edition       string          `json:"edition"`
		PlatformAdmin bool            `json:"platform_admin"`
		Organization  bool            `json:"organization"`
		Role          string          `json:"role"`
		Roles         []string        `json:"roles"`
		Permissions   []string        `json:"permissions"`
		Projects      json.RawMessage `json:"projects"`
		TokenScoped   bool            `json:"token_scoped"`
	}
	var v plain
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	*p = Permissions{Edition: v.Edition, PlatformAdmin: v.PlatformAdmin, Organization: v.Organization,
		Role: v.Role, Roles: v.Roles, Permissions: v.Permissions, TokenScoped: v.TokenScoped}
	if len(v.Projects) > 0 && v.Projects[0] == '{' {
		_ = json.Unmarshal(v.Projects, &p.Projects)
	}
	return nil
}

// Has reports whether the caller holds permission org-wide or on projectID ("" = org-wide).
func (p *Permissions) Has(permission, projectID string) bool {
	if p.PlatformAdmin && !p.TokenScoped {
		return true
	}
	for _, x := range p.Permissions {
		if x == permission || x == "*" {
			return true
		}
	}
	if projectID != "" {
		for _, x := range p.Projects[projectID] {
			if x == permission {
				return true
			}
		}
	}
	return false
}

// Permissions returns the caller's effective permissions; ErrUnsupported when the API has no
// role-based access control.
func (c *Client) Permissions(ctx context.Context) (*Permissions, error) {
	var out Permissions
	err := c.do(ctx, request{method: http.MethodGet, path: "/auth/permissions"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	sort.Strings(out.Permissions)
	return &out, nil
}

// MissingPermissions returns the permissions a 403 answer names (any of them would do).
func MissingPermissions(err error) []string {
	var ae *APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusForbidden {
		return ae.Permissions
	}
	return nil
}

// Scopes for API tokens. Each set is the least a tool needs; the contract (Endpoint.Permission)
// names what every endpoint requires.
var (
	// ScopesDeploy lets CI deploy existing projects: upload code, change config and the
	// variables of runr.yaml (env.edit includes reading them, env.view), --image, deploy and
	// wait.
	ScopesDeploy = []string{"projects.view", "regions.view", "projects.update", "env.edit", "code.edit", "projects.deploy"}
	// ScopesDeployCreate additionally creates the project on the first deploy.
	ScopesDeployCreate = append(append([]string{}, ScopesDeploy...), "projects.create")
	// ScopesTerraform covers every resource of the Terraform provider.
	ScopesTerraform = []string{"projects.view", "regions.view", "projects.create", "projects.update", "projects.delete",
		"env.edit", "domains.manage", "projects.deploy"}
)
