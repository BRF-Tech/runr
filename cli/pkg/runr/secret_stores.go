package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"strings"
)

// Secret stores of the organization (GET /secret-stores, docs/secret-stores.md): Vault / OpenBao,
// AWS Secrets Manager, GCP Secret Manager and Azure Key Vault. A store holds settings only —
// addresses, mounts, roles —, never a credential: it trusts the region cluster's service account
// issuer and the service account RunR names (SecretStoreTrust). A project secret refers to a
// value in it (SetSecretReference).

// SecretStoreRegionStatus is a store's state in one region: ready, failed or pending.
type SecretStoreRegionStatus struct {
	Status    string `json:"status"`
	Message   string `json:"message,omitempty"`
	CheckedAt string `json:"checked_at,omitempty"`
}

// SecretStore is one store of the organization.
type SecretStore struct {
	ID              string            `json:"id"`
	Name            string            `json:"name"`
	Provider        string            `json:"provider"`
	Auth            string            `json:"auth"`
	Settings        map[string]string `json:"settings"`
	RefreshInterval int               `json:"refresh_interval"`
	// KubernetesName is the SecretStore and service account in a region's project namespace.
	KubernetesName string `json:"kubernetes_name"`
	Audience       string `json:"audience,omitempty"`
	// Regions is the store's status per region ID.
	Regions    map[string]SecretStoreRegionStatus `json:"regions"`
	References int                                `json:"references"`
	CreatedAt  string                             `json:"created_at,omitempty"`
	UpdatedAt  string                             `json:"updated_at,omitempty"`
}

// UnmarshalJSON tolerates regions and settings encoded as [] (empty PHP arrays).
func (s *SecretStore) UnmarshalJSON(b []byte) error {
	type plain SecretStore
	var v struct {
		plain
		Settings json.RawMessage `json:"settings"`
		Regions  json.RawMessage `json:"regions"`
	}
	if err := json.Unmarshal(b, &v); err != nil {
		return err
	}
	*s = SecretStore(v.plain)
	s.Settings, s.Regions = nil, nil
	if len(v.Settings) > 0 && v.Settings[0] == '{' {
		_ = json.Unmarshal(v.Settings, &s.Settings)
	}
	if len(v.Regions) > 0 && v.Regions[0] == '{' {
		_ = json.Unmarshal(v.Regions, &s.Regions)
	}
	return nil
}

// SecretStoreTrust is what a store has to trust so a region reads from it without a credential.
type SecretStoreTrust struct {
	Region struct {
		ID   string `json:"id"`
		Name string `json:"name"`
	} `json:"region"`
	// Issuer is the region cluster's service account issuer; PubliclyReachable says whether a
	// cloud provider could fetch its discovery document (a hint, the provider decides).
	Issuer            string          `json:"issuer"`
	JWKSURI           string          `json:"jwks_uri,omitempty"`
	JWKS              json.RawMessage `json:"jwks,omitempty"`
	PEMKeys           []string        `json:"pem_keys"`
	PubliclyReachable bool            `json:"publicly_reachable"`
	Namespace         string          `json:"namespace"`
	ServiceAccount    string          `json:"service_account"`
	Subject           string          `json:"subject"`
	Audience          string          `json:"audience,omitempty"`
	// ESOStatus is External Secrets Operator's state in the region (ready, not_installed, …).
	ESOStatus string `json:"eso_status,omitempty"`
}

// ListSecretStores returns the organization's secret stores sorted by name. ErrUnsupported on
// APIs without them.
func (c *Client) ListSecretStores(ctx context.Context) ([]SecretStore, error) {
	var raw json.RawMessage
	err := c.do(ctx, request{method: http.MethodGet, path: "/secret-stores"}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var stores []SecretStore
	if err := decodeList(raw, &stores, nil); err != nil {
		return nil, fmt.Errorf("decode GET /secret-stores: %w", err)
	}
	sort.Slice(stores, func(i, j int) bool { return stores[i].Name < stores[j].Name })
	if stores == nil {
		stores = []SecretStore{}
	}
	return stores, nil
}

// FindSecretStore resolves a store by ID or name.
func (c *Client) FindSecretStore(ctx context.Context, ref string) (*SecretStore, error) {
	stores, err := c.ListSecretStores(ctx)
	if err != nil {
		return nil, err
	}
	return PickSecretStore(stores, ref)
}

// PickSecretStore applies FindSecretStore's rule to a list: the ID, else the name (any case).
func PickSecretStore(stores []SecretStore, ref string) (*SecretStore, error) {
	ref = strings.TrimSpace(ref)
	for i := range stores {
		if stores[i].ID == ref {
			return &stores[i], nil
		}
	}
	for i := range stores {
		if strings.EqualFold(stores[i].Name, ref) {
			return &stores[i], nil
		}
	}
	names := make([]string, 0, len(stores))
	for _, s := range stores {
		names = append(names, s.Name)
	}
	if len(names) == 0 {
		return nil, fmt.Errorf("secret store %q not found: the organization has none (create one in Settings → Secret stores)", ref)
	}
	return nil, fmt.Errorf("secret store %q not found (stores: %s)", ref, strings.Join(names, ", "))
}

// SecretStoreTrust returns what a store has to trust for a region: the cluster's issuer, its keys,
// the service account and namespace, the subject and audience.
func (c *Client) SecretStoreTrust(ctx context.Context, storeID, regionID string) (*SecretStoreTrust, error) {
	var out struct {
		Data SecretStoreTrust `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/secret-stores/" + seg(storeID) + "/trust",
		query: url.Values{"region_id": {regionID}}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SecretStoreProviders are the providers of secret stores and the ways each authenticates, the
// default first (SecretStore::PROVIDERS). A vault store serves OpenBao as well.
var SecretStoreProviders = map[string][]string{
	"vault": {"kubernetes", "jwt"},
	"aws":   {"workload_identity"},
	"gcp":   {"workload_identity"},
	"azure": {"workload_identity"},
}

// SecretStoreSettings are the settings each provider takes, name → required
// (SecretStore::SETTINGS). None is a credential: the store trusts the region's identity.
var SecretStoreSettings = map[string]map[string]bool{
	"vault": {"server": true, "mount": false, "kv_version": false, "vault_namespace": false, "ca_bundle": false,
		"auth_mount": false, "role": true, "audience": false},
	"aws":   {"region": true, "role_arn": true, "audience": false},
	"gcp":   {"project_id": true, "audience": true, "service_account_email": false},
	"azure": {"vault_url": true, "tenant_id": true, "client_id": true},
}

// SecretStoreInput is a new store (POST /secret-stores): the provider's settings, never a
// credential.
type SecretStoreInput struct {
	Name     string            `json:"name"`
	Provider string            `json:"provider"`
	Auth     string            `json:"auth,omitempty"`
	Settings map[string]string `json:"settings"`
	// RefreshInterval is the seconds between syncs (60–86400); 0 leaves the installation's.
	RefreshInterval int `json:"refresh_interval,omitempty"`
}

// SecretStoreUpdate changes a store (PUT /secret-stores/{secretStore}); nil fields stay as they
// are. The provider cannot change.
type SecretStoreUpdate struct {
	Name *string
	Auth *string
	// Settings are merged into the store's: a nil value removes the setting.
	Settings map[string]*string
	// RefreshInterval 0 goes back to the installation's interval.
	RefreshInterval *int
}

func (u SecretStoreUpdate) body() map[string]any {
	body := map[string]any{}
	if u.Name != nil {
		body["name"] = *u.Name
	}
	if u.Auth != nil {
		body["auth"] = *u.Auth
	}
	if u.Settings != nil {
		settings := map[string]any{}
		for k, v := range u.Settings {
			if v == nil {
				settings[k] = nil
			} else {
				settings[k] = *v
			}
		}
		body["settings"] = settings
	}
	if u.RefreshInterval != nil {
		if *u.RefreshInterval == 0 {
			body["refresh_interval"] = nil
		} else {
			body["refresh_interval"] = *u.RefreshInterval
		}
	}
	return body
}

// GetSecretStore returns one store by its ID.
func (c *Client) GetSecretStore(ctx context.Context, id string) (*SecretStore, error) {
	var out struct {
		Data SecretStore `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/secret-stores/" + seg(id)}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// CreateSecretStore creates a store of the organization (secret_stores.manage).
func (c *Client) CreateSecretStore(ctx context.Context, in SecretStoreInput) (*SecretStore, error) {
	if in.Settings == nil {
		in.Settings = map[string]string{}
	}
	var out struct {
		Data SecretStore `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/secret-stores", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateSecretStore changes a store. The stores in the regions follow with the next deploy of a
// project that refers to it.
func (c *Client) UpdateSecretStore(ctx context.Context, id string, u SecretStoreUpdate) (*SecretStore, error) {
	var out struct {
		Data SecretStore `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/secret-stores/" + seg(id), body: u.body()}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteSecretStore deletes a store. The API refuses it (409, naming the projects) while a
// secret variable refers to it.
func (c *Client) DeleteSecretStore(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/secret-stores/" + seg(id)}, nil)
}
