package runr

import (
	"context"
	"net/http"
	"net/url"
)

// GPU quota of model services and GPU projects (docs/ai.md).

// GPUQuota is the organization's GPU quota on a region: Quota nil means no limit, Remaining
// nil likewise.
type GPUQuota struct {
	RegionID  string `json:"region_id,omitempty"`
	Quota     *int   `json:"quota"`
	Used      int    `json:"used"`
	Remaining *int   `json:"remaining"`
}

// GPUQuota returns how many GPUs the organization may reserve on a region and how many its
// projects and model services hold (models.view).
func (c *Client) GPUQuota(ctx context.Context, regionID string) (*GPUQuota, error) {
	q := url.Values{}
	q.Set("knative_region_id", regionID)
	var out GPUQuota
	if err := c.do(ctx, request{method: http.MethodGet, path: "/models/gpu-quota", query: q}, &out); err != nil {
		return nil, err
	}
	out.RegionID = regionID
	return &out, nil
}

// TenantGPUQuota is the answer of PUT /tenants/{tenant}/regions/{region}/gpu-quota.
type TenantGPUQuota struct {
	TenantID string `json:"tenant_id"`
	RegionID string `json:"region_id"`
	GPUQuota *int   `json:"gpu_quota"`
}

// SetTenantGPUQuota sets an organization's GPU quota on a region (platform administrators,
// multi-tenant installations): nil removes the limit, 0 allows no GPUs.
func (c *Client) SetTenantGPUQuota(ctx context.Context, tenantID, regionID string, quota *int) (*TenantGPUQuota, error) {
	body := map[string]any{"gpu_quota": nil}
	if quota != nil {
		body["gpu_quota"] = *quota
	}
	var out TenantGPUQuota
	err := c.do(ctx, request{method: http.MethodPut, path: "/tenants/" + seg(tenantID) + "/regions/" + seg(regionID) + "/gpu-quota",
		body: body}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}
