package runrtest

import (
	"fmt"
	"sort"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Secret variables of a project (ProjectSecretController, ProjectSecretWriter): names are
// listed, values are write-only and readable with secrets.view_values only. Values live in
// project.secrets, change dates in secretsState.

type secretsState struct {
	// updated holds when and by whom each secret was last written: project id → name → meta.
	updated map[string]map[string]secretMeta
	// reveals counts GET …/value calls (each is an audited read).
	reveals int
	// canViewValues is the can_view_values flag of the list for unscoped tokens.
	canViewValues bool
}

type secretMeta struct {
	at string
	by runr.User
}

func (s *Server) secretsState() *secretsState {
	return featureState(s, "secrets", func() *secretsState {
		return &secretsState{updated: map[string]map[string]secretMeta{}, canViewValues: true}
	})
}

// SeedSecret stores a secret variable directly in the fake's state.
func (s *Server) SeedSecret(projectName, name, value string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	s.applySecretEnv(p, map[string]any{name: value})
}

// SecretReveals returns how often a secret value was read back.
func (s *Server) SecretReveals() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.secretsState().reveals
}

// DenySecretValues makes the list answer can_view_values false and GET …/value 403 for every
// token, like a role without secrets.view_values.
func (s *Server) DenySecretValues() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.secretsState().canViewValues = false
}

// splitSecretEnv returns a copy of a config without secret_env_vars and the secret map.
func splitSecretEnv(cfg map[string]any) (map[string]any, map[string]any, bool) {
	raw, ok := cfg["secret_env_vars"]
	if !ok {
		return cfg, nil, false
	}
	rest := make(map[string]any, len(cfg))
	for k, v := range cfg {
		if k != "secret_env_vars" {
			rest[k] = v
		}
	}
	m, _ := raw.(map[string]any)
	return rest, m, true
}

func secretRemoves(v any) bool {
	if v == nil {
		return true
	}
	str, ok := v.(string)
	return ok && str == ""
}

// secretEnvErrors validates config.secret_env_vars like UpdateProjectRequest /
// ProjectSecretWriter::errors: names, values, the limit and no plain variable of the same name
// (the plain variables after the request: config.env_vars when sent).
func (s *Server) secretEnvErrors(p *project, cfg map[string]any, secrets map[string]any) *apiErr {
	plain := map[string]any{}
	if env, ok := cfg["env_vars"].(map[string]any); ok {
		plain = env
	} else if p != nil {
		if env, ok := p.config["env_vars"].(map[string]any); ok {
			plain = env
		}
	}
	names := map[string]bool{}
	if p != nil {
		for k := range p.secrets {
			names[k] = true
		}
		// A plain name that is a secret, unless the request removes the secret.
		if env, ok := cfg["env_vars"].(map[string]any); ok {
			for k := range env {
				sv, sent := secrets[k]
				if _, isSecret := p.secrets[k]; isSecret && !(sent && secretRemoves(sv)) {
					return errValidation("config.env_vars", fmt.Sprintf("%q is a secret variable of this project; change it under Secret variables.", k))
				}
			}
		}
	}
	keys := make([]string, 0, len(secrets))
	for k := range secrets {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		field := "config.secret_env_vars." + k
		v := secrets[k]
		if secretRemoves(v) {
			delete(names, k)
			continue
		}
		if e := secretNameError(k, plain); e != "" {
			return errValidation(field, e)
		}
		str, ok := v.(string)
		if !ok {
			return errValidation(field, "The "+k+" field must be a string.")
		}
		if len(str) > runr.MaxSecretBytes {
			return errValidation(field, fmt.Sprintf("A secret value may be at most %d KiB.", runr.MaxSecretBytes/1024))
		}
		names[k] = true
	}
	if len(names) > runr.MaxSecretsPerProject {
		return errValidation("config.secret_env_vars", fmt.Sprintf("A project may have at most %d secret variables.", runr.MaxSecretsPerProject))
	}
	return nil
}

// secretNameError is ProjectSecretWriter::nameError.
func secretNameError(name string, plain map[string]any) string {
	if err := runr.ValidateEnvName(name); err != nil {
		if strings.Contains(err.Error(), "set by RunR") {
			return "The environment variable \"" + name + "\" is set by the platform and cannot be overridden."
		}
		return "Invalid environment variable name \"" + name + "\": use letters, digits and \"_\", not starting with a digit."
	}
	if _, ok := plain[name]; ok {
		return "\"" + name + "\" is already a plain environment variable of this project; remove it there first."
	}
	return ""
}

// applySecretEnv sets and removes the secrets of a validated change set.
func (s *Server) applySecretEnv(p *project, secrets map[string]any) {
	st := s.secretsState()
	if st.updated[p.data.ID] == nil {
		st.updated[p.data.ID] = map[string]secretMeta{}
	}
	for k, v := range secrets {
		if secretRemoves(v) {
			delete(p.secrets, k)
			delete(st.updated[p.data.ID], k)
			continue
		}
		p.secrets[k] = fmt.Sprint(v)
		st.updated[p.data.ID][k] = secretMeta{at: nowRFC3339(), by: s.User}
	}
}

func (s *Server) secretJSON(p *project, name string) map[string]any {
	out := map[string]any{"name": name, "updated_at": nil, "updated_by": nil}
	if m, ok := s.secretsState().updated[p.data.ID][name]; ok {
		out["updated_at"] = m.at
		id := 0
		_, _ = fmt.Sscan(m.by.ID.String(), &id)
		out["updated_by"] = map[string]any{"id": id, "name": m.by.Name}
	}
	return out
}

func (s *Server) secretsProject(q *featureReq) (*project, *apiErr) {
	if !s.SecretEnv {
		return nil, errNotFound()
	}
	return s.projectOf(q)
}

func init() {
	handle("GET /projects/{project}/secrets", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.secretsProject(q)
		if err != nil {
			return 0, nil, err
		}
		names := make([]string, 0, len(p.secrets))
		for k := range p.secrets {
			names = append(names, k)
		}
		sort.Strings(names)
		list := make([]any, 0, len(names))
		for _, n := range names {
			list = append(list, s.secretJSON(p, n))
		}
		return 200, map[string]any{"data": list, "can_view_values": s.secretsState().canViewValues && s.holds("secrets.view_values")}, nil
	})

	handle("PUT /projects/{project}/secrets/{name}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.secretsProject(q)
		if err != nil {
			return 0, nil, err
		}
		name := q.Segs[3]
		value, ok := q.Body["value"].(string)
		if !ok || value == "" {
			return 0, nil, errValidation("value", "The value field is required.")
		}
		plain, _ := p.config["env_vars"].(map[string]any)
		if e := secretNameError(name, plain); e != "" {
			return 0, nil, errValidation("name", e)
		}
		if len(value) > runr.MaxSecretBytes {
			return 0, nil, errValidation("value", fmt.Sprintf("A secret value may be at most %d KiB.", runr.MaxSecretBytes/1024))
		}
		_, existing := p.secrets[name]
		if !existing && len(p.secrets) >= runr.MaxSecretsPerProject {
			return 0, nil, errValidation("name", fmt.Sprintf("A project may have at most %d secret variables.", runr.MaxSecretsPerProject))
		}
		s.applySecretEnv(p, map[string]any{name: value})
		status := 201
		if existing {
			status = 200
		}
		return status, map[string]any{"data": s.secretJSON(p, name), "requires_deploy": true}, nil
	})

	handle("DELETE /projects/{project}/secrets/{name}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.secretsProject(q)
		if err != nil {
			return 0, nil, err
		}
		if _, ok := p.secrets[q.Segs[3]]; !ok {
			return 0, nil, errNotFound()
		}
		s.applySecretEnv(p, map[string]any{q.Segs[3]: nil})
		return 200, map[string]any{"requires_deploy": true}, nil
	})

	handle("GET /projects/{project}/secrets/{name}/value", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.secretsProject(q)
		if err != nil {
			return 0, nil, err
		}
		if !s.secretsState().canViewValues {
			return 0, nil, &apiErr{status: 403, message: "You do not have permission to do this (requires secrets.view_values).",
				permissions: []string{"secrets.view_values"}}
		}
		v, ok := p.secrets[q.Segs[3]]
		if !ok {
			return 0, nil, errNotFound()
		}
		s.secretsState().reveals++
		return 200, map[string]any{"name": q.Segs[3], "value": v}, nil
	})
}
