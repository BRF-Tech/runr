package runr

import (
	"context"
	"net/http"
)

// The security policy of an organization (ResidencyController::show / update; multi-tenant
// installations): its data residency (allowed regions and data locations, default region,
// enforced) and its own policy gate, stricter than the regions' where it is set.

// TenantSecurityPolicy is GET|PUT /tenants/{tenant}/security-policy.
type TenantSecurityPolicy struct {
	TenantID          string   `json:"tenant_id"`
	ResidencyEnforced bool     `json:"residency_enforced"`
	AllowedRegionIDs  []string `json:"allowed_region_ids"`
	AllowedLocations  []string `json:"allowed_locations"`
	DefaultRegionID   string   `json:"default_region_id,omitempty"`
	// Restricted is set when the lists restrict the regions (enforced and not empty).
	Restricted      bool   `json:"restricted"`
	GateMode        string `json:"gate_mode,omitempty"`
	GateMaxCritical *int   `json:"gate_max_critical"`
	GateMaxHigh     *int   `json:"gate_max_high"`
	GateRequireScan *bool  `json:"gate_require_scan"`
	UpdatedBy       string `json:"updated_by,omitempty"`
	UpdatedAt       string `json:"updated_at,omitempty"`
	Regions         []struct {
		ID           string `json:"id"`
		Name         string `json:"name"`
		DataLocation string `json:"data_location,omitempty"`
		RegionMode   string `json:"region_mode,omitempty"`
	} `json:"regions"`
	Locations []string `json:"locations"`
	Abilities struct {
		Residency bool `json:"residency"`
		Gate      bool `json:"gate"`
	} `json:"abilities"`
}

// TenantSecurityPolicyChange is the body of PUT …/security-policy: only the given keys change
// (residency_enforced, allowed_region_ids, allowed_locations, default_region_id — needs
// tenants.residency.manage; gate_mode, gate_max_critical, gate_max_high, gate_require_scan —
// needs regions.supply_chain.manage). A nil value clears a setting.
type TenantSecurityPolicyChange map[string]any

// TenantSecurityPolicy returns the security policy of an organization (tenant id).
func (c *Client) TenantSecurityPolicy(ctx context.Context, tenantID string) (*TenantSecurityPolicy, error) {
	var out struct {
		Data TenantSecurityPolicy `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/tenants/" + seg(tenantID) + "/security-policy"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateTenantSecurityPolicy changes the security policy of an organization.
func (c *Client) UpdateTenantSecurityPolicy(ctx context.Context, tenantID string, change TenantSecurityPolicyChange) (*TenantSecurityPolicy, error) {
	var out struct {
		Data TenantSecurityPolicy `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPut, path: "/tenants/" + seg(tenantID) + "/security-policy",
		body: map[string]any(change)}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}
