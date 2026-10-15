package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
)

// The network of a running sandbox (PUT /sandboxes/{id}/network) and the sandbox settings of
// the regions (GET /sandbox-regions, PUT /sandbox-regions/{region}; SandboxAdminController,
// SandboxRegions::describe).

// SetSandboxNetwork replaces the network of a sandbox: "none" (nil or an empty allow list) or an
// egress allowlist of addresses, CIDRs or host names, each with an optional port. Host names are
// resolved again by the API.
func (c *Client) SetSandboxNetwork(ctx context.Context, id string, allow []string) (*Sandbox, error) {
	var network any = "none"
	if len(allow) > 0 {
		network = SandboxEgress(allow)
	}
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPut, path: "/sandboxes/" + seg(id) + "/network",
		body: map[string]any{"network": network}}, &raw); err != nil {
		return nil, err
	}
	var s Sandbox
	if err := decodeData(raw, &s); err != nil {
		return nil, fmt.Errorf("decode the sandbox: %w", err)
	}
	return &s, nil
}

// SandboxRegionSettings are the sandbox settings of a region with the values that apply: the
// limits are the region's own or the platform's, whichever is lower.
type SandboxRegionSettings struct {
	Region  *Ref `json:"region,omitempty"`
	Enabled bool `json:"enabled"`
	// InstalledClasses are the sandbox runtime classes the region's nodes offer.
	InstalledClasses []string `json:"installed_classes"`
	// AllowedClasses is the region's allowlist; nil allows every installed class.
	AllowedClasses []string `json:"allowed_classes"`
	// EffectiveClasses are the classes the current organization gets (region and quota).
	EffectiveClasses      []string `json:"effective_classes"`
	DefaultClass          string   `json:"default_class,omitempty"`
	EffectiveDefaultClass string   `json:"effective_default_class,omitempty"`
	AllowRunc             bool     `json:"allow_runc"`
	DefaultTTLSeconds     int      `json:"default_ttl_seconds"`
	MaxTTLSeconds         int      `json:"max_ttl_seconds"`
	MaxCPUm               int      `json:"max_cpu_m"`
	MaxMemoryMB           int      `json:"max_memory_mb"`
	MaxDiskMB             int      `json:"max_disk_mb"`
	// CanManage is set when the caller may change the region's settings.
	CanManage bool `json:"can_manage"`
}

// RegionName returns the region's name, else its id.
func (r *SandboxRegionSettings) RegionName() string {
	if r.Region == nil {
		return ""
	}
	if r.Region.Name != "" {
		return r.Region.Name
	}
	return r.Region.ID
}

// SandboxRegionChange is the body of PUT /sandbox-regions/{region}: only the given keys change
// (enabled, allowed_classes, default_class, allow_runc, default_ttl_seconds, max_ttl_seconds,
// max_cpu_m, max_memory_mb, max_disk_mb); a nil value puts a setting back to the platform's.
type SandboxRegionChange map[string]any

// ListSandboxRegions returns the sandbox settings of every region the caller can use.
func (c *Client) ListSandboxRegions(ctx context.Context) ([]SandboxRegionSettings, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandbox-regions"}, &raw); err != nil {
		return nil, err
	}
	list := []SandboxRegionSettings{}
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode the sandbox regions: %w", err)
	}
	return list, nil
}

// FindSandboxRegion returns the sandbox settings of a region by id or name.
func (c *Client) FindSandboxRegion(ctx context.Context, ref string) (*SandboxRegionSettings, error) {
	list, err := c.ListSandboxRegions(ctx)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if r := list[i].Region; r != nil && (r.ID == ref || strings.EqualFold(r.Name, ref)) {
			return &list[i], nil
		}
	}
	return nil, &APIError{StatusCode: http.StatusNotFound, Method: http.MethodGet, Path: "/sandbox-regions",
		Message: fmt.Sprintf("no region %q offers sandboxes", ref)}
}

// UpdateSandboxRegion changes the sandbox settings of a region (by id) and returns them.
func (c *Client) UpdateSandboxRegion(ctx context.Context, regionID string, change SandboxRegionChange) (*SandboxRegionSettings, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPut, path: "/sandbox-regions/" + seg(regionID), body: map[string]any(change)}, &raw); err != nil {
		return nil, err
	}
	var r SandboxRegionSettings
	if err := decodeData(raw, &r); err != nil {
		return nil, fmt.Errorf("decode the sandbox region: %w", err)
	}
	return &r, nil
}
