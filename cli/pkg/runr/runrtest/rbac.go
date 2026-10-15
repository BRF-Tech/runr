package runrtest

import (
	"fmt"
	"sort"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Catalog is the permission catalog the fake knows (api/config/permissions.php).
var Catalog = []string{
	"audit.export", "audit.view", "bindings.manage", "bindings.view", "bindings.view_credentials",
	"branding.manage", "code.edit", "connections.manage", "domains.manage", "env.edit", "env.view",
	"events.send", "groups.manage", "logs.view", "members.invite", "members.remove", "members.update",
	"members.view", "metrics.view", "models.deploy", "models.manage", "models.view", "projects.create",
	"projects.delete", "projects.deploy", "projects.manage_access", "projects.update", "projects.view",
	"regions.data_services.manage", "regions.gpu.manage", "regions.manage", "regions.supply_chain.manage",
	"regions.view", "registry.manage", "roles.manage", "runtimes.manage", "runtimes.use_custom",
	"sandbox.manage", "secrets.view_values", "security.override_policy", "security.view",
	"settings.manage", "shell.exec", "sso.manage", "tokens.manage", "workflows.manage",
	"workflows.run", "workflows.view",
	"git.manage", "git.pull", "git.push", "git_providers.manage", "previews.manage",
	"alerts.manage", "replay.manage", "status_page.manage", "traces.view", "uptime.manage",
	"sandboxes.create", "sandboxes.exec", "sandboxes.manage", "sandboxes.view",
	"mcp.servers.call", "mcp.servers.manage",
	"regions.observability.manage",
}

// implies lists what a permission includes (permissions.php "implies").
var implies = map[string][]string{
	"projects.update": {"projects.view"}, "projects.delete": {"projects.view"},
	"projects.deploy": {"projects.view"}, "projects.manage_access": {"projects.view"},
	"code.edit": {"projects.view"},
	"env.view":  {"projects.view"}, "env.edit": {"env.view", "projects.view"},
	"secrets.view_values": {"env.view"},
	"domains.manage":      {"projects.view"}, "connections.manage": {"projects.view"},
	"shell.exec": {"projects.view"}, "logs.view": {"projects.view"}, "metrics.view": {"projects.view"},
	"workflows.view": {"projects.view"}, "workflows.run": {"workflows.view"}, "workflows.manage": {"workflows.view"},
	"security.view": {"projects.view"}, "security.override_policy": {"security.view", "projects.deploy"},
	"regions.manage": {"regions.view"}, "sandbox.manage": {"regions.view"}, "registry.manage": {"regions.view"},
	"regions.data_services.manage": {"regions.view"}, "regions.supply_chain.manage": {"regions.view"},
	"regions.gpu.manage": {"regions.view"},
	"models.deploy":      {"models.view"}, "models.manage": {"models.view"},
	"bindings.manage": {"bindings.view"}, "bindings.view_credentials": {"bindings.view"},
	"members.invite": {"members.view"}, "members.update": {"members.view"}, "members.remove": {"members.view"},
	"roles.manage": {"members.view"}, "groups.manage": {"members.view"},
	"audit.export": {"audit.view"},
	"git.pull":     {"projects.view"}, "git.push": {"git.pull", "projects.view"},
	"git.manage": {"git.pull", "projects.view"}, "previews.manage": {"git.pull", "projects.view"},
	"traces.view": {"projects.view"}, "replay.manage": {"projects.view"},
	"uptime.manage":    {"metrics.view", "projects.view"},
	"sandboxes.create": {"sandboxes.view"}, "sandboxes.exec": {"sandboxes.view"},
	"sandboxes.manage": {"sandboxes.view"},
	"mcp.servers.call": {"projects.view"}, "mcp.servers.manage": {"projects.view"},
	"regions.observability.manage": {"regions.view"},
}

func expand(perms []string) []string {
	set := map[string]bool{}
	var add func(p string)
	add = func(p string) {
		if set[p] {
			return
		}
		set[p] = true
		for _, q := range implies[p] {
			add(q)
		}
	}
	for _, p := range perms {
		add(p)
	}
	out := make([]string, 0, len(set))
	for p := range set {
		out = append(out, p)
	}
	sort.Strings(out)
	return out
}

// ScopedToken creates an API token limited to scopes and returns its plain value.
func (s *Server) ScopedToken(name string, scopes ...string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.seq++
	plain := fmt.Sprintf("%d|scoped_token_%d", s.seq, s.seq)
	s.valid[plain] = true
	s.scopes[plain] = expand(scopes)
	return plain
}

// holds reports whether the current bearer has permission (unscoped tokens hold all).
func (s *Server) holds(permission string) bool {
	scopes, scoped := s.scopes[s.bearer]
	if !scoped || !s.RBAC {
		return true
	}
	for _, have := range scopes {
		if have == permission || have == "*" {
			return true
		}
	}
	return false
}

// forbidden checks the endpoint's permission against the bearer's scopes (nil = allowed).
// Like UpdateProjectRequest, a project update that carries variables needs env.edit.
func (s *Server) forbidden(ep *runr.Endpoint, bearer string, body map[string]any) *apiErr {
	scopes, scoped := s.scopes[bearer]
	if !scoped || ep.Permission == "" || !s.RBAC {
		return nil
	}
	if ep.Method == "PUT" && ep.Path == "/projects/{project}" {
		if cfg, ok := body["config"].(map[string]any); ok {
			_, env := cfg["env_vars"]
			_, secret := cfg["secret_env_vars"]
			if (env || secret) && !s.holds("env.edit") {
				return &apiErr{status: 403, message: "You do not have permission to do this (requires env.edit).", permissions: []string{"env.edit"}}
			}
		}
	}
	if all := strings.Split(ep.Permission, "+"); len(all) > 1 {
		// "a+b": every one of them (route_permissions.php ['a', 'b']).
		var lacking []string
		for _, need := range all {
			held := false
			for _, have := range scopes {
				held = held || have == need || have == "*"
			}
			if !held {
				lacking = append(lacking, need)
			}
		}
		if len(lacking) == 0 {
			return nil
		}
		return &apiErr{status: 403, message: "You do not have permission to do this (requires " + strings.Join(all, " and ") + ").", permissions: lacking}
	}
	options := strings.Split(ep.Permission, "|")
	for _, need := range options {
		for _, have := range scopes {
			if have == need || have == "*" {
				return nil
			}
		}
	}
	return &apiErr{status: 403, message: "You do not have permission to do this (requires " + strings.Join(options, " | ") + ").", permissions: options}
}

func (s *Server) permissionsJSON() map[string]any {
	perms, scoped := s.scopes[s.bearer]
	if !scoped {
		perms = append([]string{}, Catalog...)
	}
	return map[string]any{
		"edition": s.rbacEdition(), "platform_admin": false, "organization": true, "role": "developer",
		"roles": []string{"developer"}, "permissions": perms, "projects": map[string]any{}, "token_scoped": scoped,
	}
}
