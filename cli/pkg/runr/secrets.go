package runr

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
)

// Secret variables of a project (GET/PUT/DELETE /projects/{project}/secrets…): values are
// write-only; reading one back takes secrets.view_values and is recorded in the audit log.
// A value goes straight into the Kubernetes Secret of the project's region (pass-through): the
// API keeps the name, a version and a keyed fingerprint. A variable can also be a reference to a
// value in one of the organization's secret stores (docs/secret-stores.md); RunR never sees it.

// Limits of secret variables (App\Models\ProjectSecret).
const (
	MaxSecretsPerProject = 100
	MaxSecretBytes       = 32768
)

// Sources of a secret variable (project_secrets.source): a value in the project's Kubernetes
// Secret, a reference to a secret store, or a value in the region's own OpenBao.
const (
	SecretSourceRegion   = "region"
	SecretSourceExternal = "external"
	SecretSourceOpenBao  = "openbao"
)

// SecretRef is the user who last changed a secret.
type SecretRef struct {
	ID   FlexID `json:"id"`
	Name string `json:"name"`
}

// SecretReference is where the value of an external secret lives: a store, a path in it and an
// optional key inside the secret.
type SecretReference struct {
	StoreID   string `json:"store_id"`
	StoreName string `json:"store_name,omitempty"`
	Provider  string `json:"provider,omitempty"`
	Path      string `json:"path"`
	Key       string `json:"key,omitempty"`
}

// String is "store:path#key" (the form `runr env ref` takes).
func (r *SecretReference) String() string {
	if r == nil {
		return ""
	}
	store := r.StoreName
	if store == "" {
		store = r.StoreID
	}
	s := store + ":" + r.Path
	if r.Key != "" {
		s += "#" + r.Key
	}
	return s
}

// SecretSync is what External Secrets Operator reported last for a reference: pending, synced
// or failed.
type SecretSync struct {
	Status   string `json:"status"`
	Message  string `json:"message,omitempty"`
	SyncedAt string `json:"synced_at,omitempty"`
}

// Secret is one secret variable: its name, where its value lives and when and by whom it was
// last changed. Source, Version, StoredIn and Fingerprint are empty on APIs before pass-through.
type Secret struct {
	Name      string     `json:"name"`
	Source    string     `json:"source,omitempty"`
	Version   int        `json:"version,omitempty"`
	UpdatedAt string     `json:"updated_at,omitempty"`
	UpdatedBy *SecretRef `json:"updated_by,omitempty"`
	// StoredIn is "region" (the project's Kubernetes Secret), "openbao" (the region's OpenBao), or
	// "database" for a value written before pass-through that secrets:move-to-region has not
	// moved yet. Not set for references.
	StoredIn string `json:"stored_in,omitempty"`
	// Fingerprint is the first 12 hex digits of the value's keyed fingerprint (never the value).
	Fingerprint string           `json:"fingerprint,omitempty"`
	Reference   *SecretReference `json:"reference,omitempty"`
	Sync        *SecretSync      `json:"sync,omitempty"`
}

// External reports whether the variable is a reference to a secret store.
func (s Secret) External() bool { return s.Source == SecretSourceExternal }

// SecretList is the answer of GET /projects/{project}/secrets.
type SecretList struct {
	Secrets []Secret `json:"secrets"`
	// CanViewValues tells whether the caller may read values back (secrets.view_values).
	CanViewValues bool `json:"can_view_values"`
	// Storage is where new values go: "region" (pass-through) or "database"; empty on older APIs.
	Storage string `json:"storage,omitempty"`
}

// ListSecrets returns the names of a project's secret variables.
func (c *Client) ListSecrets(ctx context.Context, projectID string) (*SecretList, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/secrets"}, &raw); err != nil {
		return nil, err
	}
	return decodeSecretList(raw)
}

func decodeSecretList(raw json.RawMessage) (*SecretList, error) {
	out := &SecretList{}
	if err := decodeList(raw, &out.Secrets, nil); err != nil {
		return nil, err
	}
	var meta struct {
		CanViewValues bool   `json:"can_view_values"`
		Storage       string `json:"storage"`
	}
	_ = json.Unmarshal(raw, &meta)
	out.CanViewValues = meta.CanViewValues
	out.Storage = meta.Storage
	if out.Secrets == nil {
		out.Secrets = []Secret{}
	}
	return out, nil
}

// SetSecret creates or replaces a secret variable. The value goes into the project's region; new
// containers read it at once, every container with the next deploy.
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

// SecretReferenceInput names the value a reference points at: the store (its ID), the path in it
// and an optional key inside the secret.
type SecretReferenceInput struct {
	StoreID string `json:"store_id"`
	Path    string `json:"path"`
	Key     string `json:"key,omitempty"`
}

// ParseSecretReference splits "store:path[#key]". The store is a name or an ID (no colon); the
// path may hold colons (an AWS secret ARN), the key follows the last '#'.
func ParseSecretReference(s string) (store, path, key string, ok bool) {
	store, rest, found := strings.Cut(strings.TrimSpace(s), ":")
	if !found || store == "" || rest == "" {
		return "", "", "", false
	}
	path = rest
	if i := strings.LastIndex(rest, "#"); i >= 0 {
		path, key = rest[:i], rest[i+1:]
	}
	if path == "" || strings.ContainsAny(path, " \t") || strings.ContainsAny(key, " \t") {
		return "", "", "", false
	}
	return store, path, key, true
}

// SetSecretReference makes a secret variable a reference to a secret store; it takes effect with
// the next deploy. An API before secret stores answers 422 (it asks for a value).
func (c *Client) SetSecretReference(ctx context.Context, projectID, name string, ref SecretReferenceInput) (*Secret, error) {
	var out struct {
		Data Secret `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/secrets/" + seg(name),
		body: map[string]any{"reference": ref}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// RefreshSecrets asks the region to sync the project's references now and returns the list with
// their status. ErrUnsupported on APIs without secret stores.
func (c *Client) RefreshSecrets(ctx context.Context, projectID string) (*SecretList, error) {
	var raw json.RawMessage
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/secrets/refresh"}, &raw)
	if IsNotFound(err) || statusIs(err, http.StatusMethodNotAllowed) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return decodeSecretList(raw)
}

// DeleteSecret removes a secret variable.
func (c *Client) DeleteSecret(ctx context.Context, projectID, name string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/secrets/" + seg(name)}, nil)
}

// SecretValue reads a secret value back; the API requires secrets.view_values and audits it. The
// API reads it from the project's region (503 when the region does not answer).
func (c *Client) SecretValue(ctx context.Context, projectID, name string) (string, error) {
	v, err := c.RevealSecret(ctx, projectID, name)
	if err != nil {
		return "", err
	}
	return v.Value, nil
}

// RevealedSecret is the answer of GET …/secrets/{name}/value.
type RevealedSecret struct {
	Name  string `json:"name"`
	Value string `json:"value"`
	// MatchesFingerprint is false when the region holds another value than the one RunR wrote
	// (changed in the cluster); nil when there is nothing to compare with or on older APIs.
	MatchesFingerprint *bool `json:"matches_fingerprint,omitempty"`
}

// RevealSecret reads a secret value back with the fingerprint check of the API.
func (c *Client) RevealSecret(ctx context.Context, projectID, name string) (*RevealedSecret, error) {
	var out RevealedSecret
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/secrets/" + seg(name) + "/value"}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// ErrSecretValuesForbidden is what RevealEnv returns when the caller may not read values back.
func ErrSecretValuesForbidden(p *Project) error {
	return &APIError{StatusCode: 403, Method: "GET", Path: "/projects/" + p.ID + "/secrets",
		Message:     "reading secret values of " + p.Name + " is not allowed (requires secrets.view_values)",
		Permissions: []string{"secrets.view_values"}}
}

// EnvDetails returns the variables of p with the secrets' change dates and sources; reveal reads
// secret values back as well (secrets.view_values) — not those of references, whose values live
// in their secret store. On an API without the secrets endpoints it returns EnvList(p).
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
		v := EnvVar{Name: s.Name, Secret: true, UpdatedAt: s.UpdatedAt, UpdatedBy: s.UpdatedBy,
			Source: s.Source, StoredIn: s.StoredIn, Reference: s.Reference, Sync: s.Sync}
		if reveal && !s.External() {
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
