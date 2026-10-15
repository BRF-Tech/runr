package runr

import (
	"context"
	"io"
	"net/http"
	"net/url"
)

// More of the Sandbox API (docs/sandbox-api.md): removing files and creating directories, the
// pod behind a sandbox (GET /sandboxes/{id}/logs?pod=1) and the Prometheus metrics of every
// sandbox (GET /sandbox-metrics/prometheus).

// SandboxPathResult is the answer of DELETE /sandboxes/{id}/files and POST …/files/mkdir.
type SandboxPathResult struct {
	Path    string `json:"path"`
	Deleted bool   `json:"deleted,omitempty"`
}

// RemoveSandboxPath deletes a file, a link or an empty directory of a sandbox; recursive also
// deletes a directory with its contents. A path that does not exist is a 404.
func (c *Client) RemoveSandboxPath(ctx context.Context, id, path string, recursive bool) (*SandboxPathResult, error) {
	q := url.Values{"path": {path}}
	if recursive {
		q.Set("recursive", "1")
	}
	var out struct {
		Data SandboxPathResult `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodDelete, path: "/sandboxes/" + seg(id) + "/files", query: q}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// MakeSandboxDir creates a directory of a sandbox with its parents (like mkdir -p).
func (c *Client) MakeSandboxDir(ctx context.Context, id, path string) (*SandboxPathResult, error) {
	var out struct {
		Data SandboxPathResult `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/files/mkdir",
		body: map[string]string{"path": path}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SandboxPod is the Kubernetes pod of a running sandbox: its phase and conditions, the
// cluster's events about it and the last lines of its log.
type SandboxPod struct {
	Name       string                `json:"name"`
	Namespace  string                `json:"namespace"`
	Node       string                `json:"node,omitempty"`
	Phase      string                `json:"phase,omitempty"`
	Conditions []SandboxPodCondition `json:"conditions"`
	Events     []SandboxPodEvent     `json:"events"`
	Log        string                `json:"log,omitempty"`
}

// SandboxPodCondition is one condition of the pod (Ready, PodScheduled, …).
type SandboxPodCondition struct {
	Type    string `json:"type"`
	Status  string `json:"status"`
	Reason  string `json:"reason,omitempty"`
	Message string `json:"message,omitempty"`
}

// SandboxPodEvent is one Kubernetes event about the pod.
type SandboxPodEvent struct {
	Type    string `json:"type"`
	Reason  string `json:"reason,omitempty"`
	Message string `json:"message,omitempty"`
	At      string `json:"at,omitempty"`
}

// SandboxLogsWithPod returns the activity log of a sandbox and its pod. The pod is nil for an
// ended sandbox or when the region could not be asked.
func (c *Client) SandboxLogsWithPod(ctx context.Context, id string) ([]SandboxEvent, *SandboxPod, error) {
	var out struct {
		Data struct {
			Events []SandboxEvent `json:"events"`
			Pod    *SandboxPod    `json:"pod"`
		} `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/logs", query: url.Values{"pod": {"1"}}}, &out)
	if err != nil {
		return nil, nil, err
	}
	if out.Data.Events == nil {
		out.Data.Events = []SandboxEvent{}
	}
	return out.Data.Events, out.Data.Pod, nil
}

// WriteSandboxPrometheus writes the sandbox metrics of the installation in the Prometheus text
// exposition format to w (platform administrators; on a single-tenant installation every user
// with sandboxes.manage).
func (c *Client) WriteSandboxPrometheus(ctx context.Context, w io.Writer) error {
	return c.do(ctx, request{method: http.MethodGet, path: "/sandbox-metrics/prometheus", accept: "text/plain"}, &Download{W: w})
}
