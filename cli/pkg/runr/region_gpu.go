package runr

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"time"
)

// GPU support of a region and its model storage (GpuController; docs/ai.md): the NVIDIA device
// plugin or GPU operator installed on the region's GPU nodes, their GPUs and allocation, and
// the volume model services keep their weights on. Reading needs regions.view, changes
// regions.gpu.manage on a region the caller may change.

// GPU support modes of a region.
const (
	GPUModeNone         = "none"
	GPUModeDevicePlugin = "device-plugin"
	GPUModeOperator     = "operator"
	GPUModeExternal     = "external"
)

// RegionGPUSettings are the GPU settings of a region.
type RegionGPUSettings struct {
	TimeSlicingReplicas *int `json:"time_slicing_replicas"`
	// RuntimeClass is the RuntimeClass GPU pods use (nvidia or none); RuntimeClassChoice what
	// was chosen (auto, nvidia, none).
	RuntimeClass       *string  `json:"runtime_class"`
	RuntimeClassChoice string   `json:"runtime_class_choice"`
	GVisorNvproxy      bool     `json:"gvisor_nvproxy"`
	OperatorDriver     bool     `json:"operator_driver"`
	OperatorToolkit    bool     `json:"operator_toolkit"`
	RestrictToGPUNodes bool     `json:"restrict_to_gpu_nodes"`
	GPUNodes           []string `json:"gpu_nodes"`
}

// GPUProduct is one GPU model of the region's nodes.
type GPUProduct struct {
	Product     *string `json:"product"`
	MemoryMB    *int    `json:"memory_mb"`
	Count       int     `json:"count"`
	Allocatable int     `json:"allocatable"`
	Nodes       int     `json:"nodes"`
}

// GPUNode is one node with GPUs (or GPU hardware without a device plugin).
type GPUNode struct {
	Name              string          `json:"name"`
	Ready             bool            `json:"ready"`
	Product           *string         `json:"product"`
	MemoryMB          *int            `json:"memory_mb"`
	Count             int             `json:"count"`
	Capacity          int             `json:"capacity"`
	Allocatable       int             `json:"allocatable"`
	Allocated         int             `json:"allocated"`
	Replicas          *int            `json:"replicas"`
	ComputeCapability *string         `json:"compute_capability"`
	DriverVersion     *string         `json:"driver_version"`
	CUDAVersion       *string         `json:"cuda_version"`
	HasDevicePlugin   bool            `json:"has_device_plugin"`
	HardwareDetected  bool            `json:"hardware_detected"`
	Taints            json.RawMessage `json:"taints,omitempty"`
}

// GPUSandboxSupport is whether GPU pods of a project type run under its sandbox runtime.
type GPUSandboxSupport struct {
	RuntimeClass *string `json:"runtime_class"`
	// GPU is ok, nvproxy (gVisor with nvproxy) or unsupported.
	GPU     string  `json:"gpu"`
	Message *string `json:"message"`
}

// RegionGPU is GET /knative-regions/{region}/gpu.
type RegionGPU struct {
	Mode          string            `json:"mode"`
	Status        string            `json:"status"`
	StatusMessage string            `json:"status_message,omitempty"`
	Settings      RegionGPUSettings `json:"settings"`
	Summary       struct {
		GPUNodes              int          `json:"gpu_nodes"`
		Total                 int          `json:"total"`
		Allocatable           int          `json:"allocatable"`
		Allocated             int          `json:"allocated"`
		Available             int          `json:"available"`
		Products              []GPUProduct `json:"products"`
		HardwareWithoutPlugin []string     `json:"hardware_without_plugin"`
	} `json:"summary"`
	Nodes      []GPUNode `json:"nodes"`
	Allocation struct {
		Total  int              `json:"total"`
		ByNode ObjectMap[int64] `json:"by_node"`
	} `json:"allocation"`
	Sandbox    ObjectMap[GPUSandboxSupport] `json:"sandbox"`
	DetectedAt string                       `json:"detected_at,omitempty"`
}

// UnmarshalJSON tolerates a summary sent as [] (no inventory yet).
func (g *RegionGPU) UnmarshalJSON(b []byte) error {
	type plain RegionGPU
	var raw struct {
		plain
		Summary json.RawMessage `json:"summary"`
	}
	if err := json.Unmarshal(b, &raw); err != nil {
		return err
	}
	*g = RegionGPU(raw.plain)
	if len(raw.Summary) > 0 && raw.Summary[0] == '{' {
		if err := json.Unmarshal(raw.Summary, &g.Summary); err != nil {
			return err
		}
	}
	return nil
}

// RegionGPU returns the GPU support of a region; refresh reads the nodes from the cluster now.
func (c *Client) RegionGPU(ctx context.Context, regionID string, refresh bool) (*RegionGPU, error) {
	var q url.Values
	if refresh {
		q = url.Values{"refresh": {"1"}}
	}
	var out RegionGPU
	if err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions/" + seg(regionID) + "/gpu", query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// GPUChange is the answer of an install, uninstall or settings change: the API's message and
// the region's GPU support.
type GPUChange struct {
	Message string     `json:"message"`
	GPU     *RegionGPU `json:"gpu"`
}

// InstallGPUSupport queues the install of GPU support (mode device-plugin or operator) with the
// given options (time_slicing_replicas, runtime_class, operator_driver, operator_toolkit,
// restrict_to_gpu_nodes, gpu_nodes).
func (c *Client) InstallGPUSupport(ctx context.Context, regionID string, body map[string]any) (*GPUChange, error) {
	var out GPUChange
	if err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/" + seg(regionID) + "/gpu/install", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UninstallGPUSupport queues the removal of the region's GPU support.
func (c *Client) UninstallGPUSupport(ctx context.Context, regionID string) (*GPUChange, error) {
	var out GPUChange
	if err := c.do(ctx, request{method: http.MethodPost, path: "/knative-regions/" + seg(regionID) + "/gpu/uninstall"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdateGPUSettings changes GPU settings; changes the installed chart needs are applied in the
// background (the answer's status is then installing).
func (c *Client) UpdateGPUSettings(ctx context.Context, regionID string, body map[string]any) (*GPUChange, error) {
	var out GPUChange
	if err := c.do(ctx, request{method: http.MethodPut, path: "/knative-regions/" + seg(regionID) + "/gpu/settings", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// WaitRegionGPU polls a region's GPU support until an install, removal or reconfiguration
// ended; a *FailedError when it ended in error.
func (c *Client) WaitRegionGPU(ctx context.Context, regionID, regionName string, opts WaitOptions) (*RegionGPU, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "GPU support of " + regionName
	last := ""
	for {
		cur, err := c.RegionGPU(ctx, regionID, false)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		switch cur.Status {
		case "error":
			return cur, &FailedError{What: "GPU support of region", Ref: regionName, Status: cur.Status, Message: lastLine(cur.StatusMessage)}
		case "installing", "uninstalling":
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

// lastLine returns the last non-empty line of an install log.
func lastLine(s string) string {
	return SandboxRuntimeStatus{Message: s}.LastLine()
}

// ModelStorage is GET|PUT /knative-regions/{region}/model-storage: the volume claim model
// services keep their weights on, its settings and state (absent, pending, bound or error).
type ModelStorage struct {
	StorageClass string  `json:"storage_class"`
	SizeGi       int     `json:"size_gi"`
	AccessMode   string  `json:"access_mode"`
	PVCName      string  `json:"pvc_name"`
	Status       string  `json:"status"`
	Message      *string `json:"message"`
	Capacity     *string `json:"capacity"`
}

// RegionModelStorage returns the model storage of a region.
func (c *Client) RegionModelStorage(ctx context.Context, regionID string) (*ModelStorage, error) {
	var out ModelStorage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions/" + seg(regionID) + "/model-storage"}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdateModelStorage changes the model storage (storage_class, size_gi, access_mode). Once the
// claim exists only a larger size applies.
func (c *Client) UpdateModelStorage(ctx context.Context, regionID string, body map[string]any) (*ModelStorage, error) {
	var out ModelStorage
	if err := c.do(ctx, request{method: http.MethodPut, path: "/knative-regions/" + seg(regionID) + "/model-storage", body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
