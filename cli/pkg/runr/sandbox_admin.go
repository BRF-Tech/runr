package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
)

// Sandbox API settings (SandboxAdminController): warm pools, organization quotas, usage and
// metrics.

// SandboxPool is a warm pool: pre-started sandboxes of one runtime and size in a region, handed
// out by POST /sandboxes when a request matches.
type SandboxPool struct {
	ID             string `json:"id"`
	Region         *Ref   `json:"region,omitempty"`
	Runtime        string `json:"runtime"`
	RuntimeVersion string `json:"runtime_version,omitempty"`
	SandboxClass   string `json:"sandbox_class,omitempty"`
	CPUm           int    `json:"cpu_m"`
	MemoryMB       int    `json:"memory_mb"`
	DiskMB         int    `json:"disk_mb"`
	Size           int    `json:"size"`
	IsEnabled      bool   `json:"is_enabled"`
	Warm           int    `json:"warm"`
	Starting       int    `json:"starting"`
	Hits           int    `json:"hits"`
	Misses         int    `json:"misses"`
	LastError      string `json:"last_error,omitempty"`
	TenantID       FlexID `json:"tenant_id,omitempty"`
	CreatedAt      string `json:"created_at,omitempty"`
}

// RegionName returns the pool's region name, else its id.
func (p *SandboxPool) RegionName() string {
	if p.Region == nil {
		return ""
	}
	if p.Region.Name != "" {
		return p.Region.Name
	}
	return p.Region.ID
}

// CreateSandboxPoolInput is the body of POST /sandbox-pools.
type CreateSandboxPoolInput struct {
	// Region is a region name or id.
	Region       string `json:"region"`
	Runtime      string `json:"runtime"`
	Version      string `json:"version,omitempty"`
	Size         int    `json:"size"`
	CPU          string `json:"cpu,omitempty"`
	Memory       string `json:"memory,omitempty"`
	Disk         string `json:"disk,omitempty"`
	SandboxClass string `json:"sandbox_class,omitempty"`
}

// UpdateSandboxPoolInput is the body of PUT /sandbox-pools/{pool}; nil fields are kept.
type UpdateSandboxPoolInput struct {
	Size      *int  `json:"size,omitempty"`
	IsEnabled *bool `json:"is_enabled,omitempty"`
}

func decodePool(raw json.RawMessage) (*SandboxPool, error) {
	var p SandboxPool
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode the pool: %w", err)
	}
	return &p, nil
}

// ListSandboxPools returns the warm pools of the organization.
func (c *Client) ListSandboxPools(ctx context.Context) ([]SandboxPool, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandbox-pools"}, &raw); err != nil {
		return nil, err
	}
	list := []SandboxPool{}
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode the pools: %w", err)
	}
	return list, nil
}

// CreateSandboxPool creates a warm pool; RunR starts its sandboxes in the background.
func (c *Client) CreateSandboxPool(ctx context.Context, in CreateSandboxPoolInput) (*SandboxPool, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPost, path: "/sandbox-pools", body: in}, &raw); err != nil {
		return nil, err
	}
	return decodePool(raw)
}

// UpdateSandboxPool changes the size of a pool or switches it on or off.
func (c *Client) UpdateSandboxPool(ctx context.Context, id string, in UpdateSandboxPoolInput) (*SandboxPool, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPut, path: "/sandbox-pools/" + seg(id), body: in}, &raw); err != nil {
		return nil, err
	}
	return decodePool(raw)
}

// DeleteSandboxPool removes a pool and its warm sandboxes.
func (c *Client) DeleteSandboxPool(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/sandbox-pools/" + seg(id)}, nil)
}

// SandboxQuotaLimits are an organization's sandbox limits; 0 means unlimited.
type SandboxQuotaLimits struct {
	MaxConcurrent          int      `json:"max_concurrent"`
	MaxCPUm                int      `json:"max_cpu_m"`
	MaxMemoryMB            int      `json:"max_memory_mb"`
	MaxSandboxSecondsMonth int      `json:"max_sandbox_seconds_month"`
	MaxPoolSize            int      `json:"max_pool_size"`
	AllowedClasses         []string `json:"allowed_classes"`
}

// SandboxQuotaUsage is what an organization uses now (and this month).
type SandboxQuotaUsage struct {
	Concurrent          int `json:"concurrent"`
	CPUm                int `json:"cpu_m"`
	MemoryMB            int `json:"memory_mb"`
	SandboxSecondsMonth int `json:"sandbox_seconds_month"`
	PoolSize            int `json:"pool_size"`
}

// SandboxQuota is GET /sandbox-quotas: the limits and usage of the current organization (or,
// on a single-tenant installation, of the installation).
type SandboxQuota struct {
	TenantID FlexID             `json:"tenant_id,omitempty"`
	Limits   SandboxQuotaLimits `json:"limits"`
	Usage    *SandboxQuotaUsage `json:"usage"`
}

// SandboxTenantQuota is one row of GET /sandbox-quotas/tenants: the defaults row (Tenant nil)
// or an organization.
type SandboxTenantQuota struct {
	Tenant *struct {
		ID   FlexID `json:"id"`
		Name string `json:"name"`
		Slug string `json:"slug"`
	} `json:"tenant"`
	Limits SandboxQuotaLimits `json:"limits"`
	Usage  *SandboxQuotaUsage `json:"usage"`
	// Custom is set when the row has limits of its own (not only the defaults).
	Custom bool `json:"custom"`
}

// GetSandboxQuota returns the limits and usage of the current organization.
func (c *Client) GetSandboxQuota(ctx context.Context) (*SandboxQuota, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandbox-quotas"}, &raw); err != nil {
		return nil, err
	}
	var q SandboxQuota
	if err := decodeData(raw, &q); err != nil {
		return nil, fmt.Errorf("decode the quota: %w", err)
	}
	return &q, nil
}

// ListSandboxQuotas returns the defaults row and every organization's limits and usage
// (platform administrators; every user of a single-tenant installation with sandboxes.manage).
func (c *Client) ListSandboxQuotas(ctx context.Context) ([]SandboxTenantQuota, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandbox-quotas/tenants"}, &raw); err != nil {
		return nil, err
	}
	list := []SandboxTenantQuota{}
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode the quotas: %w", err)
	}
	return list, nil
}

// SandboxQuotaChange sets limits: a key with a nil value goes back to the default, keys left
// out are kept. Keys are max_concurrent, max_cpu_m, max_memory_mb, max_sandbox_seconds_month,
// max_pool_size and allowed_classes.
type SandboxQuotaChange map[string]any

// SetSandboxQuota changes the limits of an organization ("default": the defaults row).
func (c *Client) SetSandboxQuota(ctx context.Context, tenant string, change SandboxQuotaChange) (*SandboxQuota, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPut, path: "/sandbox-quotas/" + seg(tenant), body: map[string]any(change)}, &raw); err != nil {
		return nil, err
	}
	var q SandboxQuota
	if err := decodeData(raw, &q); err != nil {
		return nil, fmt.Errorf("decode the quota: %w", err)
	}
	return &q, nil
}

// ResetSandboxQuota removes an organization's own limits ("default": the defaults row), so the
// defaults apply again.
func (c *Client) ResetSandboxQuota(ctx context.Context, tenant string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/sandbox-quotas/" + seg(tenant)}, nil)
}

// SandboxUsageRecord is one day of sandbox usage in a region: quantities only (what they cost is
// on the bills of the Billing module, GET /billing/usage).
type SandboxUsageRecord struct {
	RecordDate     string `json:"record_date"`
	RegionID       string `json:"region_id,omitempty"`
	SandboxCount   int    `json:"sandbox_count"`
	SandboxSeconds int64  `json:"sandbox_seconds"`
	CPUmcMS        int64  `json:"cpu_mc_ms"`
	MemoryMiBMS    int64  `json:"memory_mib_ms"`
}

// SandboxUsage returns the daily usage of the organization between from and to (dates,
// "" = this month).
func (c *Client) SandboxUsage(ctx context.Context, from, to string) ([]SandboxUsageRecord, error) {
	q := url.Values{}
	if from != "" {
		q.Set("from", from)
	}
	if to != "" {
		q.Set("to", to)
	}
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandbox-usage", query: q}, &raw); err != nil {
		return nil, err
	}
	list := []SandboxUsageRecord{}
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode the usage: %w", err)
	}
	return list, nil
}

// SandboxStartTimes is a start-time histogram.
type SandboxStartTimes struct {
	Count int   `json:"count"`
	SumMS int64 `json:"sum_ms"`
}

// SandboxMetrics is GET /sandbox-metrics: active and warm sandboxes, pool hit rate and start
// times in a window.
type SandboxMetrics struct {
	Window string `json:"window"`
	Active int    `json:"active"`
	Warm   int    `json:"warm"`
	Pool   struct {
		Hits    int      `json:"hits"`
		Misses  int      `json:"misses"`
		HitRate *float64 `json:"hit_rate"`
	} `json:"pool"`
	Starts struct {
		PoolHit int `json:"pool_hit"`
		Cold    int `json:"cold"`
	} `json:"starts"`
	StartTime struct {
		PoolHit SandboxStartTimes `json:"pool_hit"`
		Cold    SandboxStartTimes `json:"cold"`
	} `json:"start_time"`
}

// SandboxMetricsOptions filter GET /sandbox-metrics.
type SandboxMetricsOptions struct {
	// Window is 1h, 24h (default), 7d or 30d.
	Window string
	// Region is a region id.
	Region string
	// AllTenants sums every organization (platform administrators).
	AllTenants bool
}

// GetSandboxMetrics returns the sandbox metrics of the organization.
func (c *Client) GetSandboxMetrics(ctx context.Context, o SandboxMetricsOptions) (*SandboxMetrics, error) {
	q := url.Values{}
	if o.Window != "" {
		q.Set("window", o.Window)
	}
	if o.Region != "" {
		q.Set("region", o.Region)
	}
	if o.AllTenants {
		q.Set("all_tenants", "1")
	}
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandbox-metrics", query: q}, &raw); err != nil {
		return nil, err
	}
	var m SandboxMetrics
	if err := decodeData(raw, &m); err != nil {
		return nil, fmt.Errorf("decode the metrics: %w", err)
	}
	return &m, nil
}

// AverageMS is the mean start time, 0 without starts.
func (s SandboxStartTimes) AverageMS() int64 {
	if s.Count == 0 {
		return 0
	}
	return s.SumMS / int64(s.Count)
}
