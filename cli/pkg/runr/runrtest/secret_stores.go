package runrtest

import (
	"fmt"
	"net/url"
	"regexp"
	"sort"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The organization's secret stores (SecretStoreController, App\Models\SecretStore): settings
// per provider, never a credential. A store is refused deletion while a secret variable refers
// to it. Its state per region is set with SetSecretStoreStatus (verify in the API).

type fakeSecretStore struct {
	data runr.SecretStore
	// refresh is the stored refresh_interval (nil: the installation's, secretStoreRefresh)
	refresh *int
}

// secretStoreRefresh is the installation's refresh interval (secrets.external.refresh_interval).
const secretStoreRefresh = 3600

// secretStoreSettings are the settings each provider takes, name → required (SecretStore::SETTINGS).
var secretStoreSettings = runr.SecretStoreSettings

var (
	secretStoreNamePattern = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`)
	secretStoreRoleARN     = regexp.MustCompile(`^arn:aws[a-z-]*:iam::\d{12}:role/.+$`)
)

func (s *Server) fakeSecretStore(id string) *fakeSecretStore {
	for _, fs := range s.secretsState().stores {
		if fs.data.ID == id {
			return fs
		}
	}
	return nil
}

// SeedSecretStore adds a secret store of the organization and returns its ID.
func (s *Server) SeedSecretStore(name, provider, auth string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.addSecretStore(name, provider, auth, map[string]string{}, nil).data.ID
}

func (s *Server) addSecretStore(name, provider, auth string, settings map[string]string, refresh *int) *fakeSecretStore {
	st := s.secretsState()
	s.seq++
	id := fmt.Sprintf("01SS%022d", s.seq)
	now := nowRFC3339()
	fs := &fakeSecretStore{data: runr.SecretStore{ID: id, Name: name, Provider: provider, Auth: auth, Settings: settings,
		KubernetesName: "runr-ss-" + strings.ToLower(id), Regions: map[string]runr.SecretStoreRegionStatus{},
		CreatedAt: now, UpdatedAt: now}, refresh: refresh}
	st.stores = append(st.stores, fs)
	return fs
}

// SecretStore returns a store of the organization by name.
func (s *Server) SecretStore(name string) (runr.SecretStore, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, fs := range s.secretsState().stores {
		if fs.data.Name == name {
			out := s.secretStoreData(fs)
			return out, true
		}
	}
	return runr.SecretStore{}, false
}

// SetSecretStoreStatus records a store's state in a region (what POST …/verify records).
func (s *Server) SetSecretStoreStatus(name, regionName, status, message string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	for _, fs := range s.secretsState().stores {
		if fs.data.Name == name && reg != nil {
			fs.data.Regions[reg.ID] = runr.SecretStoreRegionStatus{Status: status, Message: message, CheckedAt: nowRFC3339()}
		}
	}
}

// secretStoreRefs returns the names of the existing projects whose secrets refer to a store.
func (s *Server) secretStoreRefs(id string) (int, []string) {
	n := 0
	var projects []string
	for projectID, byName := range s.secretsState().refs {
		p := s.projectByID(projectID)
		if p == nil {
			continue // deleted with the project
		}
		named := false
		for _, r := range byName {
			if r.StoreID == id {
				n++
				if !named {
					projects = append(projects, p.data.Name)
					named = true
				}
			}
		}
	}
	sort.Strings(projects)
	return n, projects
}

// secretStoreAudience is SecretStore::audience().
func secretStoreAudience(provider, auth string, settings map[string]string) string {
	switch provider {
	case "gcp":
		return settings["audience"]
	case "vault":
		if auth != "jwt" {
			return ""
		}
		if a := settings["audience"]; a != "" {
			return a
		}
		return "vault"
	}
	if a := settings["audience"]; a != "" {
		return a
	}
	return map[string]string{"aws": "sts.amazonaws.com", "azure": "api://AzureADTokenExchange"}[provider]
}

func (s *Server) secretStoreData(fs *fakeSecretStore) runr.SecretStore {
	out := fs.data
	out.RefreshInterval = secretStoreRefresh
	if fs.refresh != nil {
		out.RefreshInterval = max(60, *fs.refresh)
	}
	out.Audience = secretStoreAudience(out.Provider, out.Auth, out.Settings)
	out.References, _ = s.secretStoreRefs(out.ID)
	return out
}

// secretStoreJSON is SecretStore::toApiArray() with the number of references; empty settings
// are a JSON array, as PHP encodes an empty array.
func (s *Server) secretStoreJSON(fs *fakeSecretStore) map[string]any {
	d := s.secretStoreData(fs)
	var settings any = d.Settings
	if len(d.Settings) == 0 {
		settings = []any{}
	}
	regions := map[string]any{}
	for id, st := range d.Regions {
		regions[id] = map[string]any{"status": st.Status, "message": nilIfEmpty(st.Message), "checked_at": st.CheckedAt}
	}
	return map[string]any{"id": d.ID, "name": d.Name, "provider": d.Provider, "auth": d.Auth, "settings": settings,
		"refresh_interval": d.RefreshInterval, "kubernetes_name": d.KubernetesName, "audience": nilIfEmpty(d.Audience),
		"regions": regions, "created_at": d.CreatedAt, "updated_at": d.UpdatedAt, "references": d.References}
}

// secretStoreErrors validates a create (store nil) or an update like
// SecretStoreController::validated: all field errors at once.
func (s *Server) secretStoreErrors(body map[string]any, store *fakeSecretStore) *apiErr {
	errs := map[string][]string{}
	add := func(field, msg string) { errs[field] = append(errs[field], msg) }
	provider := ""
	if store != nil {
		provider = store.data.Provider
	} else {
		provider, _ = body["provider"].(string)
	}
	known := secretStoreSettings[provider]

	if raw, ok := body["name"]; ok || store == nil {
		name, isStr := raw.(string)
		switch {
		case !ok || raw == nil || (isStr && strings.TrimSpace(name) == ""):
			add("name", "The name field is required.")
		case !isStr:
			add("name", "The name field must be a string.")
		case len(name) > 63:
			add("name", "The name field must not be greater than 63 characters.")
		case !secretStoreNamePattern.MatchString(name):
			add("name", "The name field format is invalid.")
		default:
			for _, other := range s.secretsState().stores {
				if other.data.Name == name && (store == nil || other != store) {
					add("name", "The name has already been taken.")
				}
			}
		}
	}
	if store != nil {
		if _, ok := body["provider"]; ok {
			add("provider", "The provider field is prohibited.")
		}
	} else if _, ok := runr.SecretStoreProviders[provider]; !ok {
		if provider == "" {
			add("provider", "The provider field is required.")
		} else {
			add("provider", "The selected provider is invalid.")
		}
	}
	if raw, ok := body["auth"]; ok && raw != nil {
		auth, _ := raw.(string)
		valid := false
		for _, a := range runr.SecretStoreProviders[provider] {
			valid = valid || a == auth
		}
		if !valid {
			add("auth", "The selected auth is invalid.")
		}
	}
	var settings map[string]any
	if raw, ok := body["settings"]; ok || store == nil {
		switch v := raw.(type) {
		case map[string]any:
			settings = v
		case []any:
			if len(v) == 0 {
				settings = map[string]any{}
			} else {
				add("settings", "The settings field must be an array.")
			}
		default:
			if !ok || raw == nil {
				add("settings", "The settings field is required.")
			} else {
				add("settings", "The settings field must be an array.")
			}
		}
	}
	if raw, ok := body["refresh_interval"]; ok && raw != nil {
		f, isNum := raw.(float64)
		switch {
		case !isNum || f != float64(int(f)):
			add("refresh_interval", "The refresh interval field must be an integer.")
		case f < 60:
			add("refresh_interval", "The refresh interval field must be at least 60.")
		case f > 86400:
			add("refresh_interval", "The refresh interval field must not be greater than 86400.")
		}
	}
	keys := make([]string, 0, len(known))
	for k := range known {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, key := range keys {
		field := "settings." + key
		raw, present := settings[key]
		str, isStr := raw.(string)
		if !present || raw == nil || (isStr && strings.TrimSpace(str) == "") {
			if known[key] && store == nil {
				add(field, "The "+field+" field is required.")
			}
			continue
		}
		if !isStr {
			add(field, "The "+field+" field must be a string.")
			continue
		}
		limit := 512
		if key == "ca_bundle" {
			limit = 16384
		}
		if len(str) > limit {
			add(field, fmt.Sprintf("The %s field must not be greater than %d characters.", field, limit))
		}
		switch key {
		case "server", "vault_url":
			u, err := url.Parse(str)
			ok := err == nil && u.Host != "" && (u.Scheme == "https" || (key == "server" && u.Scheme == "http"))
			if !ok {
				add(field, "The "+field+" field must be a valid URL.")
			}
		case "kv_version":
			if str != "v1" && str != "v2" {
				add(field, "The selected "+field+" is invalid.")
			}
		case "role_arn":
			if !secretStoreRoleARN.MatchString(str) {
				add(field, "The "+field+" field format is invalid.")
			}
		case "ca_bundle":
			if !strings.Contains(str, "BEGIN CERTIFICATE") || strings.Contains(str, "PRIVATE KEY") {
				add(field, "The CA bundle must be PEM certificates (BEGIN CERTIFICATE) and nothing else.")
			}
		}
	}
	unknown := make([]string, 0)
	for key := range settings {
		if _, ok := known[key]; !ok {
			unknown = append(unknown, key)
		}
	}
	sort.Strings(unknown)
	for _, key := range unknown {
		add("settings."+key, fmt.Sprintf(`"%s" is not a setting of a %s store. A store trusts the region's identity; RunR takes no credential for it.`, key, provider))
	}
	if len(errs) == 0 {
		return nil
	}
	fields := make([]string, 0, len(errs))
	for f := range errs {
		fields = append(fields, f)
	}
	sort.Strings(fields)
	return &apiErr{status: 422, message: errs[fields[0]][0], errors: errs}
}

// filterSecretStoreSettings is SecretStoreController::settings: the provider's settings,
// trimmed, empty ones dropped.
func filterSecretStoreSettings(provider string, in map[string]any) map[string]string {
	out := map[string]string{}
	for key := range secretStoreSettings[provider] {
		if v, ok := in[key].(string); ok && strings.TrimSpace(v) != "" {
			out[key] = strings.TrimSpace(v)
		}
	}
	return out
}

// trimInput is what Laravel's TrimStrings and ConvertEmptyStringsToNull middleware do to a
// request: strings trimmed, empty ones null.
func trimInput(v any) any {
	switch t := v.(type) {
	case string:
		if s := strings.TrimSpace(t); s != "" {
			return s
		}
		return nil
	case map[string]any:
		for k, x := range t {
			t[k] = trimInput(x)
		}
	case []any:
		for i, x := range t {
			t[i] = trimInput(x)
		}
	}
	return v
}

func refreshOf(body map[string]any) *int {
	if f, ok := body["refresh_interval"].(float64); ok {
		n := int(f)
		return &n
	}
	return nil
}

func init() {
	handle("GET /secret-stores", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.secretsState()
		stores := append([]*fakeSecretStore{}, st.stores...)
		sort.SliceStable(stores, func(i, j int) bool { return stores[i].data.Name < stores[j].data.Name })
		list := make([]any, 0, len(stores))
		for _, fs := range stores {
			list = append(list, s.secretStoreJSON(fs))
		}
		providers := []any{}
		for _, name := range []string{"vault", "aws", "gcp", "azure"} {
			providers = append(providers, map[string]any{"provider": name, "auth": runr.SecretStoreProviders[name],
				"settings": secretStoreSettings[name]})
		}
		return 200, map[string]any{"data": list, "providers": providers}, nil
	})

	handle("POST /secret-stores", func(s *Server, q *featureReq) (int, any, *apiErr) {
		trimInput(q.Body)
		if err := s.secretStoreErrors(q.Body, nil); err != nil {
			return 0, nil, err
		}
		provider := q.str("provider")
		auth := q.str("auth")
		if auth == "" {
			auth = runr.SecretStoreProviders[provider][0]
		}
		settings, _ := q.Body["settings"].(map[string]any)
		fs := s.addSecretStore(q.str("name"), provider, auth, filterSecretStoreSettings(provider, settings), refreshOf(q.Body))
		return 201, map[string]any{"data": s.secretStoreJSON(fs)}, nil
	})

	handle("GET /secret-stores/{secretStore}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		fs := s.fakeSecretStore(q.Segs[1])
		if fs == nil {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": s.secretStoreJSON(fs)}, nil
	})

	handle("PUT /secret-stores/{secretStore}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		fs := s.fakeSecretStore(q.Segs[1])
		if fs == nil {
			return 0, nil, errNotFound()
		}
		trimInput(q.Body)
		if err := s.secretStoreErrors(q.Body, fs); err != nil {
			return 0, nil, err
		}
		// array_filter: a null or empty name / auth leaves it
		if name := q.str("name"); name != "" {
			fs.data.Name = name
		}
		if auth := q.str("auth"); auth != "" {
			fs.data.Auth = auth
		}
		if raw, ok := q.Body["settings"].(map[string]any); ok {
			merged := map[string]any{}
			for k, v := range fs.data.Settings {
				merged[k] = v
			}
			for k, v := range raw {
				merged[k] = v
			}
			fs.data.Settings = filterSecretStoreSettings(fs.data.Provider, merged)
		}
		if _, ok := q.Body["refresh_interval"]; ok {
			fs.refresh = refreshOf(q.Body)
		}
		fs.data.UpdatedAt = nowRFC3339()
		return 200, map[string]any{"data": s.secretStoreJSON(fs)}, nil
	})

	handle("DELETE /secret-stores/{secretStore}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		fs := s.fakeSecretStore(q.Segs[1])
		if fs == nil {
			return 0, nil, errNotFound()
		}
		if n, projects := s.secretStoreRefs(fs.data.ID); n > 0 {
			return 0, nil, &apiErr{status: 409, message: "Secret variables of " + strings.Join(projects, ", ") +
				" refer to this store: change them first.", extra: map[string]any{"projects": projects}}
		}
		st := s.secretsState()
		for i, other := range st.stores {
			if other == fs {
				st.stores = append(st.stores[:i], st.stores[i+1:]...)
				break
			}
		}
		return 204, nil, nil
	})

	handle("GET /secret-stores/{secretStore}/trust", func(s *Server, q *featureReq) (int, any, *apiErr) {
		fs := s.fakeSecretStore(q.Segs[1])
		if fs == nil {
			return 0, nil, errNotFound()
		}
		regionID := q.query("region_id")
		if regionID == "" {
			return 0, nil, errValidation("region_id", "The region id field is required.")
		}
		region := map[string]any{"id": regionID, "name": regionID}
		if reg := s.regionByID(regionID); reg != nil {
			region["name"] = reg.Name
		}
		d := s.secretStoreData(fs)
		ns := "runr-projects"
		return 200, map[string]any{"data": map[string]any{
			"region":             region,
			"issuer":             "https://kubernetes.default.svc.cluster.local",
			"jwks_uri":           "https://203.0.113.10:6443/openid/v1/jwks",
			"jwks":               map[string]any{"keys": []any{}},
			"pem_keys":           []string{"-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----"},
			"publicly_reachable": false,
			"namespace":          ns,
			"service_account":    d.KubernetesName,
			"subject":            "system:serviceaccount:" + ns + ":" + d.KubernetesName,
			"audience":           nilIfEmpty(d.Audience),
			"eso_status":         "ready",
		}}, nil
	})
}
