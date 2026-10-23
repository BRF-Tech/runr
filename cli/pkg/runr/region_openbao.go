package runr

import (
	"context"
	"net/http"
	"time"
)

// The region's own OpenBao (OpenBaoController, docs/secret-stores.md "OpenBao in the region"):
// RunR installs and operates it; while it runs, new secret values of the region's projects go
// into it, and Postgres bindings can get dynamic credentials from it. It needs External Secrets
// Operator in the region. Reading needs regions.view, changes regions.manage.

// RegionOpenBao is the answer of GET /knative-regions/{region}/openbao.
type RegionOpenBao struct {
	// Status: not_installed, installing, ready, uninstalling or error.
	Status  string `json:"status"`
	Message string `json:"message,omitempty"`
	// Version is the OpenBao version the region runs, ReleaseVersion the one this RunR pins.
	Version        string `json:"version,omitempty"`
	ReleaseVersion string `json:"release_version,omitempty"`
	InstalledAt    string `json:"installed_at,omitempty"`
	// DefaultForValues: new secret values of the region's projects go to OpenBao.
	DefaultForValues bool `json:"default_for_values"`
	// Values is how many secret variables of the region's projects live in it.
	Values int `json:"values"`
	// ESOStatus is the status of External Secrets Operator in the region (OpenBao needs it).
	ESOStatus string `json:"eso_status,omitempty"`
	CanManage bool   `json:"can_manage"`
}

// RegionOpenBaoResult is the answer of an install or a removal (queued): the message and the
// status at that moment.
type RegionOpenBaoResult struct {
	Message string        `json:"message"`
	Data    RegionOpenBao `json:"data"`
}

// RegionOpenBao returns the state of the region's OpenBao (regions.view).
func (c *Client) RegionOpenBao(ctx context.Context, regionID string) (*RegionOpenBao, error) {
	var out struct {
		Data RegionOpenBao `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/openbao")}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// InstallRegionOpenBao installs the region's OpenBao, or brings it to this release's image
// (regions.manage): queued. Refused (422) without External Secrets Operator in the region.
func (c *Client) InstallRegionOpenBao(ctx context.Context, regionID string) (*RegionOpenBaoResult, error) {
	var out RegionOpenBaoResult
	if err := c.do(ctx, request{method: http.MethodPost, path: regionPath(regionID, "/openbao")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UninstallRegionOpenBao removes the region's OpenBao with its volume (regions.manage): queued;
// refused (409) while secret values of the region's projects live in it.
func (c *Client) UninstallRegionOpenBao(ctx context.Context, regionID string) (*RegionOpenBaoResult, error) {
	var out RegionOpenBaoResult
	if err := c.do(ctx, request{method: http.MethodDelete, path: regionPath(regionID, "/openbao")}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// SetRegionOpenBaoDefault chooses where new secret values of the region's projects go: OpenBao
// (true) or the project's Kubernetes Secret (false). Values already written stay where they are.
func (c *Client) SetRegionOpenBaoDefault(ctx context.Context, regionID string, on bool) (*RegionOpenBao, error) {
	var out struct {
		Data RegionOpenBao `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: regionPath(regionID, "/openbao"),
		body: map[string]bool{"default_for_values": on}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// WaitRegionOpenBao polls the region's OpenBao until its install or removal ends: ready or
// not_installed, a *FailedError for error.
func (c *Client) WaitRegionOpenBao(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionOpenBao, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "OpenBao of " + regionName
	last := ""
	for {
		cur, err := c.RegionOpenBao(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case KnativeInstalling, KnativeUninstalling:
		case KnativeError:
			return cur, &FailedError{What: "OpenBao of region", Ref: regionName, Status: cur.Status, Message: lastLine(cur.Message)}
		default:
			return cur, nil
		}
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}
