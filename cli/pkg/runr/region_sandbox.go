package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// The sandbox runtimes of a region (SandboxController; docs/sandbox-api.md § Regions): Kata
// Containers with its hypervisors and gVisor, installed on the region's nodes in the
// background, the hardware virtualization Kata needs, and which runtime the projects of each
// type run under.

// Sandbox runtimes RunR installs on a region.
const (
	SandboxRuntimeKata   = "kata"
	SandboxRuntimeGVisor = "gvisor"
)

// KataHypervisors are the Kata hypervisors RunR installs (KataRuntime::HYPERVISORS).
var KataHypervisors = []string{"clh", "qemu", "dragonball", "fc"}

// Statuses of a sandbox runtime install.
const (
	SandboxRuntimeNotInstalled = "not_installed"
	SandboxRuntimeInstalling   = "installing"
	SandboxRuntimeUninstalling = "uninstalling"
	SandboxRuntimeReady        = "ready"
	SandboxRuntimeError        = "error"
)

// ObjectMap is a JSON object keyed by name that PHP sends as [] when it is empty.
type ObjectMap[V any] map[string]V

// UnmarshalJSON accepts an object, [] or null.
func (m *ObjectMap[V]) UnmarshalJSON(b []byte) error {
	t := bytes.TrimSpace(b)
	if bytes.Equal(t, []byte("null")) || bytes.Equal(t, []byte("[]")) {
		*m = ObjectMap[V]{}
		return nil
	}
	var plain map[string]V
	if err := json.Unmarshal(t, &plain); err != nil {
		return err
	}
	*m = plain
	return nil
}

// SandboxRuntimeStatus is the install state of one sandbox runtime on a region. Message is
// the install log (the last line is the latest step).
type SandboxRuntimeStatus struct {
	Status  string `json:"status"`
	Message string `json:"message,omitempty"`
	// Hypervisors are the installed Kata hypervisors (Kata only).
	Hypervisors []string `json:"hypervisor,omitempty"`
}

// LastLine returns the latest line of the install log.
func (s SandboxRuntimeStatus) LastLine() string {
	lines := strings.Split(strings.TrimSpace(s.Message), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}

// SandboxMapping names the runtime class the projects of each type run under: runc, gvisor
// or kata-<hypervisor>.
type SandboxMapping struct {
	XaaS  string `json:"xaas"`
	FaaS  string `json:"faas"`
	CaaS  string `json:"caas"`
	Build string `json:"build"`
}

// RegionSandboxStatus is GET /knative-regions/{region}/sandbox-status.
type RegionSandboxStatus struct {
	Kata    SandboxRuntimeStatus `json:"kata"`
	GVisor  SandboxRuntimeStatus `json:"gvisor"`
	Mapping SandboxMapping       `json:"mapping"`
}

// Runtime returns the status of runtime (kata or gvisor).
func (s *RegionSandboxStatus) Runtime(runtime string) SandboxRuntimeStatus {
	if runtime == SandboxRuntimeKata {
		return s.Kata
	}
	return s.GVisor
}

// RegionSandboxStatus returns the sandbox runtimes of a region and its mapping.
func (c *Client) RegionSandboxStatus(ctx context.Context, regionID string) (*RegionSandboxStatus, error) {
	var out RegionSandboxStatus
	if err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions/" + seg(regionID) + "/sandbox-status"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// KataShim is one Kata hypervisor and whether the region's nodes can run it.
type KataShim struct {
	Label       string   `json:"label"`
	Runtime     string   `json:"runtime"`
	Category    string   `json:"category,omitempty"`
	Arches      []string `json:"arches,omitempty"`
	Available   bool     `json:"available"`
	Reason      string   `json:"reason,omitempty"`
	Recommended bool     `json:"recommended"`
}

// RegionKVM is the hardware virtualization (/dev/kvm) of the nodes Kata would run on: state
// yes, mixed, no or unknown; Nodes maps a node to true, false or null (not checked yet).
type RegionKVM struct {
	State    string            `json:"state"`
	Nodes    ObjectMap[*bool]  `json:"nodes"`
	KVMNodes []string          `json:"kvm_nodes"`
	Checked  ObjectMap[string] `json:"checked"`
	Probing  bool              `json:"probing"`
}

// RegionKataShims is GET /knative-regions/{region}/kata-shims.
type RegionKataShims struct {
	Shims     ObjectMap[KataShim] `json:"shims"`
	KVM       RegionKVM           `json:"kvm"`
	Warning   string              `json:"warning,omitempty"`
	FCWarning string              `json:"fc_warning,omitempty"`
	Devmapper struct {
		DefaultPoolGB int `json:"default_pool_gb"`
		MinPoolGB     int `json:"min_pool_gb"`
		MaxPoolGB     int `json:"max_pool_gb"`
	} `json:"devmapper"`
}

// RegionKataShims returns which Kata hypervisors the region's nodes can run and their
// hardware virtualization. recheck checks /dev/kvm on every node again in the background
// (nodes not checked yet are always checked); KVM.Probing is then set.
func (c *Client) RegionKataShims(ctx context.Context, regionID string, recheck bool) (*RegionKataShims, error) {
	var q url.Values
	if recheck {
		q = url.Values{"refresh": {"1"}}
	}
	var out RegionKataShims
	if err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions/" + seg(regionID) + "/kata-shims", query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// InstallSandboxRuntimeInput is the body of POST /knative-regions/{region}/install-sandbox.
type InstallSandboxRuntimeInput struct {
	Runtime string `json:"runtime"`
	// Hypervisors of Kata (default clh); fc needs the devmapper thin-pool.
	Hypervisors []string `json:"hypervisors,omitempty"`
	// DevmapperPoolGB is the thin-pool size for fc (default 20 GiB, sparse).
	DevmapperPoolGB int `json:"devmapper_pool_gb,omitempty"`
}

// SandboxRuntimeChange is the answer of an install or uninstall: the API's message and, for
// Kata, its warning about the container runtime restart.
type SandboxRuntimeChange struct {
	Message string `json:"message"`
	Warning string `json:"warning,omitempty"`
}

// InstallSandboxRuntime queues the install of a sandbox runtime on a region (it runs in the
// background; WaitSandboxRuntime follows it).
func (c *Client) InstallSandboxRuntime(ctx context.Context, regionID string, in InstallSandboxRuntimeInput) (*SandboxRuntimeChange, error) {
	var out SandboxRuntimeChange
	if err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/" + seg(regionID) + "/install-sandbox", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UninstallSandboxRuntime queues the removal of a sandbox runtime from a region.
func (c *Client) UninstallSandboxRuntime(ctx context.Context, regionID, runtime string) (*SandboxRuntimeChange, error) {
	var out SandboxRuntimeChange
	err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/" + seg(regionID) + "/uninstall-sandbox",
		body: map[string]string{"runtime": runtime}}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// SetSandboxMapping sets the runtime class of every project type of a region (all four are
// sent; a class must be installed and ready, runc always is).
func (c *Client) SetSandboxMapping(ctx context.Context, regionID string, m SandboxMapping) error {
	body := map[string]string{
		"sandbox_xaas_runtime": m.XaaS, "sandbox_faas_runtime": m.FaaS,
		"sandbox_caas_runtime": m.CaaS, "sandbox_build_runtime": m.Build,
	}
	return c.do(ctx, request{method: http.MethodPut, path: "/knative-regions/" + seg(regionID) + "/sandbox-mapping", body: body}, nil)
}

// WaitSandboxRuntime polls a region's sandbox status until the install or uninstall of
// runtime ends: the status, or a *FailedError when it ended in error.
func (c *Client) WaitSandboxRuntime(ctx context.Context, regionID, regionName, runtime string, opts WaitOptions) (*RegionSandboxStatus, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := runtime + " on " + regionName
	last := ""
	for {
		cur, err := c.RegionSandboxStatus(ctx, regionID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		st := cur.Runtime(runtime)
		switch st.Status {
		case SandboxRuntimeError:
			return cur, &FailedError{What: "sandbox runtime " + runtime + " of region", Ref: regionName, Status: st.Status, Message: st.LastLine()}
		case SandboxRuntimeInstalling, SandboxRuntimeUninstalling:
		default:
			return cur, nil
		}
		if st.Status != last {
			last = st.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: fmt.Sprintf("%s (%s)", last, st.LastLine())}
		case <-time.After(opts.Interval):
		}
	}
}
