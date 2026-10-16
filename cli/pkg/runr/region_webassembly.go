package runr

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"time"
)

// WebAssembly support of a region (WasmController, docs/wasm.md): runwasi shims on selected
// nodes, which the wasm-* runtimes deploy on.

// WebAssembly shims RunR installs: spin (the shim of the wasm-* runtimes) and wasmtime.
var WasmShims = []string{"spin", "wasmtime"}

// WasmShim is one shim RunR can install.
type WasmShim struct {
	ID           string `json:"id"`
	Name         string `json:"name"`
	Version      string `json:"version"`
	RuntimeClass string `json:"runtime_class"`
	Handler      string `json:"handler"`
	Description  string `json:"description,omitempty"`
}

// WasmNode is a node of the cluster as WebAssembly support sees it.
type WasmNode struct {
	Name           string   `json:"name"`
	Arch           string   `json:"arch,omitempty"`
	Flavor         string   `json:"flavor,omitempty"`
	RuntimeVersion string   `json:"runtime_version,omitempty"`
	Eligible       bool     `json:"eligible"`
	Reason         string   `json:"reason,omitempty"`
	Target         bool     `json:"target"`
	Shims          []string `json:"shims"`
}

// WasmInstaller is the state of the installer DaemonSet.
type WasmInstaller struct {
	Desired int      `json:"desired"`
	Ready   int      `json:"ready"`
	Shims   []string `json:"shims"`
}

// WasmCluster is what the cluster reports: the nodes, the RuntimeClasses, the Knative features
// still to enable and the installer.
type WasmCluster struct {
	Nodes                   []WasmNode     `json:"nodes"`
	RuntimeClasses          any            `json:"runtime_classes,omitempty"`
	KnativeFeaturesToEnable []string       `json:"knative_features_to_enable"`
	Installer               *WasmInstaller `json:"installer,omitempty"`
}

// RegionWasm is GET /knative-regions/{region}/wasm.
type RegionWasm struct {
	// Status is not_installed, installing, ready, uninstalling or error.
	Status  string `json:"status"`
	Message string `json:"message,omitempty"`
	// Shims are the installed shims; Nodes the selected nodes (nil: every eligible node).
	Shims          []string   `json:"shims"`
	Nodes          []string   `json:"nodes"`
	AvailableShims []WasmShim `json:"available_shims"`
	SpinRuntime    string     `json:"spin_runtime,omitempty"`
	// Warning is shown before an install: it restarts the container runtime of the nodes.
	Warning   string `json:"warning,omitempty"`
	CanManage bool   `json:"can_manage"`
	// Cluster is nil without a cluster read (cluster=false, a demo region, ClusterError).
	Cluster      *WasmCluster `json:"cluster,omitempty"`
	ClusterError string       `json:"cluster_error,omitempty"`
}

// UnmarshalJSON tolerates nodes as an object or a scalar (anything but a list reads as nil).
func (w *RegionWasm) UnmarshalJSON(b []byte) error {
	type plain RegionWasm
	var raw struct {
		plain
		Nodes json.RawMessage `json:"nodes"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*w = RegionWasm(raw.plain)
	w.Nodes = nil
	if n := raw.Nodes; len(n) > 0 && n[0] == '[' {
		_ = json.Unmarshal(n, &w.Nodes)
	}
	if w.Shims == nil {
		w.Shims = []string{}
	}
	return nil
}

// RegionWasm returns the WebAssembly support of a region (regions.view); cluster adds the
// nodes' eligibility, the RuntimeClasses and the installer read from the cluster.
func (c *Client) RegionWasm(ctx context.Context, regionID string, cluster bool) (*RegionWasm, error) {
	q := url.Values{}
	if !cluster {
		q.Set("cluster", "0")
	}
	var out RegionWasm
	if err := c.do(ctx, request{method: http.MethodGet, path: regionPath(regionID, "/wasm"), query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// WasmInstallInput is the body of POST /knative-regions/{region}/wasm.
type WasmInstallInput struct {
	// Shims to install (spin, wasmtime); at least one.
	Shims []string `json:"shims"`
	// Nodes to install on; nil: every eligible node.
	Nodes []string `json:"nodes,omitempty"`
}

// InstallRegionWasm installs WebAssembly support, or changes its shims or nodes (sandbox.manage):
// queued; it restarts the container runtime once on every selected node.
func (c *Client) InstallRegionWasm(ctx context.Context, regionID string, in WasmInstallInput) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodPost, regionPath(regionID, "/wasm"), in)
}

// UninstallRegionWasm removes WebAssembly support from the region's nodes (sandbox.manage);
// refused while Wasm pods run.
func (c *Client) UninstallRegionWasm(ctx context.Context, regionID string) (*RegionOperation, error) {
	return c.regionOperation(ctx, http.MethodDelete, regionPath(regionID, "/wasm"), nil)
}

// WaitRegionWasm polls WebAssembly support until its install or removal ends: ready or
// not_installed, a *FailedError for error.
func (c *Client) WaitRegionWasm(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionWasm, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "WebAssembly support of " + regionName
	last := ""
	for {
		cur, err := c.RegionWasm(ctx, regionID, false)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case KnativeInstalling, KnativeUninstalling:
		case KnativeError:
			return cur, &FailedError{What: "WebAssembly support of region", Ref: regionName, Status: cur.Status, Message: lastLine(cur.Message)}
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
