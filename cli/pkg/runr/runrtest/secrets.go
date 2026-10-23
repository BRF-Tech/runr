package runrtest

import (
	"fmt"
	"sort"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Secret variables of a project (ProjectSecretController, ProjectSecretWriter): names are
// listed, values are write-only and readable with secrets.view_values only. Values live in
// project.secrets, change dates in secretsState.

type secretsState struct {
	// updated holds when and by whom each secret was last written: project id → name → meta.
	updated map[string]map[string]secretMeta
	// refs are the secrets that refer to a secret store: project id → name → reference. Their
	// p.secrets entry is "" (RunR never holds the value).
	refs map[string]map[string]runr.SecretReference
	// stores are the organization's secret stores (secret_stores.go), in creation order.
	stores []*fakeSecretStore
	// refreshes counts POST …/secrets/refresh calls.
	refreshes int
	// reveals counts GET …/value calls (each is an audited read).
	reveals int
	// canViewValues is the can_view_values flag of the list for unscoped tokens.
	canViewValues bool
}

type secretMeta struct {
	at      string
	by      runr.User
	version int
}

func (s *Server) secretsState() *secretsState {
	return featureState(s, "secrets", func() *secretsState {
		return &secretsState{updated: map[string]map[string]secretMeta{},
			refs: map[string]map[string]runr.SecretReference{}, canViewValues: true}
	})
}

// SecretReferences returns the references of a project, name → reference.
func (s *Server) SecretReferences(projectName string) map[string]runr.SecretReference {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	out := map[string]runr.SecretReference{}
	for k, v := range s.secretsState().refs[p.data.ID] {
		out[k] = v
	}
	return out
}

// SecretRefreshes returns how often a project's references were synced on demand.
func (s *Server) SecretRefreshes() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.secretsState().refreshes
}

func (s *Server) secretStore(id string) *runr.SecretStore {
	if fs := s.fakeSecretStore(id); fs != nil {
		return &fs.data
	}
	return nil
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
			delete(st.refs[p.data.ID], k)
			continue
		}
		p.secrets[k] = fmt.Sprint(v)
		delete(st.refs[p.data.ID], k)
		st.updated[p.data.ID][k] = secretMeta{at: nowRFC3339(), by: s.User, version: st.updated[p.data.ID][k].version + 1}
	}
}

// applySecretRef makes a secret a reference (ProjectSecrets::setReference): no value is kept.
func (s *Server) applySecretRef(p *project, name string, ref runr.SecretReference) {
	st := s.secretsState()
	if st.updated[p.data.ID] == nil {
		st.updated[p.data.ID] = map[string]secretMeta{}
	}
	if st.refs[p.data.ID] == nil {
		st.refs[p.data.ID] = map[string]runr.SecretReference{}
	}
	p.secrets[name] = ""
	st.refs[p.data.ID][name] = ref
	st.updated[p.data.ID][name] = secretMeta{at: nowRFC3339(), by: s.User, version: st.updated[p.data.ID][name].version + 1}
}

// secretJSON is ProjectSecret::toPublicArray: metadata, never the value.
func (s *Server) secretJSON(p *project, name string) map[string]any {
	st := s.secretsState()
	out := map[string]any{"name": name, "source": runr.SecretSourceRegion, "version": 1, "updated_at": nil, "updated_by": nil}
	if m, ok := st.updated[p.data.ID][name]; ok {
		out["updated_at"] = m.at
		out["version"] = max(m.version, 1)
		id := 0
		_, _ = fmt.Sscan(m.by.ID.String(), &id)
		out["updated_by"] = map[string]any{"id": id, "name": m.by.Name}
	}
	if ref, ok := st.refs[p.data.ID][name]; ok {
		out["source"] = runr.SecretSourceExternal
		store := s.secretStore(ref.StoreID)
		storeName, provider := "", ""
		if store != nil {
			storeName, provider = store.Name, store.Provider
		}
		out["reference"] = map[string]any{"store_id": ref.StoreID, "store_name": storeName, "provider": provider,
			"path": ref.Path, "key": nilIfEmpty(ref.Key)}
		out["sync"] = map[string]any{"status": "pending", "message": "Not synced yet: deploy the project to apply the reference.", "synced_at": nil}
		return out
	}
	out["stored_in"] = "region"
	out["fingerprint"] = "0123456789ab"
	return out
}

// secretListJSON is the answer of GET …/secrets (and POST …/secrets/refresh).
func (s *Server) secretListJSON(p *project) map[string]any {
	names := make([]string, 0, len(p.secrets))
	for k := range p.secrets {
		names = append(names, k)
	}
	sort.Strings(names)
	list := make([]any, 0, len(names))
	for _, n := range names {
		list = append(list, s.secretJSON(p, n))
	}
	return map[string]any{"data": list, "can_view_values": s.secretsState().canViewValues && s.holds("secrets.view_values"),
		"storage": "region"}
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
		return 200, s.secretListJSON(p), nil
	})

	handle("POST /projects/{project}/secrets/refresh", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.secretsProject(q)
		if err != nil {
			return 0, nil, err
		}
		s.secretsState().refreshes++
		return 200, s.secretListJSON(p), nil
	})

	handle("PUT /projects/{project}/secrets/{name}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.secretsProject(q)
		if err != nil {
			return 0, nil, err
		}
		name := q.Segs[3]
		if refBody, isRef := q.Body["reference"]; isRef {
			return s.putSecretRef(p, name, refBody)
		}
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
		if ref, isRef := s.secretsState().refs[p.data.ID][q.Segs[3]]; isRef {
			store := s.secretStore(ref.StoreID)
			name := ref.StoreID
			if store != nil {
				name = store.Name
			}
			return 0, nil, &apiErr{status: 422, message: "This variable refers to the secret store \"" + name + "\": read its value there. RunR never holds it."}
		}
		s.secretsState().reveals++
		return 200, map[string]any{"name": q.Segs[3], "value": v, "matches_fingerprint": true}, nil
	})
}

// putSecretRef is PUT …/secrets/{name} with {"reference": {store_id, path, key}}.
func (s *Server) putSecretRef(p *project, name string, raw any) (int, any, *apiErr) {
	body, _ := raw.(map[string]any)
	storeID, _ := body["store_id"].(string)
	path, _ := body["path"].(string)
	key, _ := body["key"].(string)
	if storeID == "" {
		return 0, nil, errValidation("reference.store_id", "The reference.store id field is required.")
	}
	if path == "" {
		return 0, nil, errValidation("reference.path", "The reference.path field is required.")
	}
	if len(path) > 512 || strings.ContainsAny(path, " \t\r\n") {
		return 0, nil, errValidation("reference.path", "The reference.path field format is invalid.")
	}
	if len(key) > 255 || strings.ContainsAny(key, " \t\r\n") {
		return 0, nil, errValidation("reference.key", "The reference.key field format is invalid.")
	}
	if s.secretStore(storeID) == nil {
		return 0, nil, errValidation("reference.store_id", "No secret store of this organization has this ID.")
	}
	plain, _ := p.config["env_vars"].(map[string]any)
	if e := secretNameError(name, plain); e != "" {
		return 0, nil, errValidation("name", e)
	}
	_, existing := p.secrets[name]
	if !existing && len(p.secrets) >= runr.MaxSecretsPerProject {
		return 0, nil, errValidation("name", fmt.Sprintf("A project may have at most %d secret variables.", runr.MaxSecretsPerProject))
	}
	s.applySecretRef(p, name, runr.SecretReference{StoreID: storeID, Path: path, Key: key})
	status := 201
	if existing {
		status = 200
	}
	return status, map[string]any{"data": s.secretJSON(p, name), "requires_deploy": true}, nil
}
