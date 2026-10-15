package runr

import (
	"context"
	"net/http"
)

// Binding limits of one organization (TenantBindingQuotaController; platform administrators of
// a multi-tenant installation): its overrides, the installation's defaults, the limits that
// apply and what its bindings claim.

// BindingQuotaKeys are the binding limits, in display order (BindingQuotas::KEYS): bindings,
// MiB of KV memory, GiB of object storage, GiB of shared Postgres, dedicated Postgres clusters.
var BindingQuotaKeys = []string{"max_bindings", "kv_mb", "object_gb", "postgres_gb", "dedicated_postgres"}

// TenantBindingQuota is GET|PUT /tenants/{tenant}/binding-quotas. An override of nil uses the
// default; a limit of 0 is unlimited.
type TenantBindingQuota struct {
	TenantID  string            `json:"tenant_id"`
	Overrides ObjectMap[*int64] `json:"overrides"`
	Defaults  ObjectMap[int64]  `json:"defaults"`
	Limits    ObjectMap[int64]  `json:"limits"`
	Used      ObjectMap[int64]  `json:"used"`
}

// TenantBindingQuota returns the binding limits of an organization (tenant id).
func (c *Client) TenantBindingQuota(ctx context.Context, tenantID string) (*TenantBindingQuota, error) {
	var out struct {
		Data TenantBindingQuota `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/tenants/" + seg(tenantID) + "/binding-quotas"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetTenantBindingQuota changes the given overrides of an organization (a key → a limit, 0 =
// unlimited, or nil to use the default again); keys left out keep theirs.
func (c *Client) SetTenantBindingQuota(ctx context.Context, tenantID string, change map[string]*int64) (*TenantBindingQuota, error) {
	body := map[string]any{}
	for k, v := range change {
		if v == nil {
			body[k] = nil
		} else {
			body[k] = *v
		}
	}
	var out struct {
		Data TenantBindingQuota `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/tenants/" + seg(tenantID) + "/binding-quotas", body: body}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}
