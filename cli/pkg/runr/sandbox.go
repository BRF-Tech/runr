package runr

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"time"
)

// Sandboxes (docs/sandbox-api.md): short-lived isolated pods (Kata micro-VMs or gVisor) of an
// organization, created, used through exec / code runs / files, and removed again.

// Sandbox states.
const (
	SandboxPending     = "pending"
	SandboxRunning     = "running"
	SandboxPaused      = "paused"
	SandboxTerminating = "terminating"
	SandboxTerminated  = "terminated"
	SandboxFailed      = "failed"
)

// Sandbox is SandboxResource.
type Sandbox struct {
	ID                 string           `json:"id"`
	Status             string           `json:"status"`
	Region             *Ref             `json:"region,omitempty"`
	Runtime            string           `json:"runtime,omitempty"`
	RuntimeVersion     string           `json:"runtime_version,omitempty"`
	Image              string           `json:"image,omitempty"`
	SandboxClass       string           `json:"sandbox_class,omitempty"`
	Isolation          string           `json:"isolation,omitempty"`
	Resources          SandboxResources `json:"resources"`
	Network            SandboxNetwork   `json:"network"`
	ReadOnlyRoot       bool             `json:"read_only_root"`
	EnvKeys            []string         `json:"env_keys,omitempty"`
	Metadata           map[string]any   `json:"metadata,omitempty"`
	TTLSeconds         int              `json:"ttl_seconds,omitempty"`
	IdleTimeoutSeconds int              `json:"idle_timeout_seconds,omitempty"`
	ExpiresAt          string           `json:"expires_at,omitempty"`
	IdleExpiresAt      string           `json:"idle_expires_at,omitempty"`
	LastActivityAt     string           `json:"last_activity_at,omitempty"`
	CreatedAt          string           `json:"created_at,omitempty"`
	StartedAt          string           `json:"started_at,omitempty"`
	PausedAt           string           `json:"paused_at,omitempty"`
	EndedAt            string           `json:"ended_at,omitempty"`
	EndReason          string           `json:"end_reason,omitempty"`
	FromPool           bool             `json:"from_pool"`
	StartMS            *int             `json:"start_ms,omitempty"`
	Error              string           `json:"error,omitempty"`
	CreatedBy          FlexID           `json:"created_by,omitempty"`
	TenantID           FlexID           `json:"tenant_id,omitempty"`
}

// SandboxResources are the limits of a sandbox.
type SandboxResources struct {
	CPU       string `json:"cpu,omitempty"`
	CPUm      int    `json:"cpu_m,omitempty"`
	MemoryMB  int    `json:"memory_mb,omitempty"`
	DiskMB    int    `json:"disk_mb,omitempty"`
	PidsLimit int    `json:"pids_limit,omitempty"`
}

// SandboxNetwork is the network of a sandbox: "none" or an egress allowlist.
type SandboxNetwork struct {
	Mode        string   `json:"mode,omitempty"`
	EgressAllow []string `json:"egress_allow,omitempty"`
}

// RegionName returns the sandbox's region name, else its id.
func (s *Sandbox) RegionName() string {
	if s.Region == nil {
		return ""
	}
	if s.Region.Name != "" {
		return s.Region.Name
	}
	return s.Region.ID
}

// Ended reports whether the sandbox is gone (terminated or failed).
func (s *Sandbox) Ended() bool {
	return s.Status == SandboxTerminated || s.Status == SandboxFailed
}

// SandboxFileInput is a file written into a new sandbox before it is returned.
type SandboxFileInput struct {
	Path          string `json:"path"`
	ContentBase64 string `json:"content_base64"`
	Mode          string `json:"mode,omitempty"`
}

// CreateSandboxInput is the body of POST /sandboxes; every field is optional.
type CreateSandboxInput struct {
	Runtime     string `json:"runtime,omitempty"`
	Version     string `json:"version,omitempty"`
	Image       string `json:"image,omitempty"`
	CPU         string `json:"cpu,omitempty"`
	Memory      string `json:"memory,omitempty"`
	Disk        string `json:"disk,omitempty"`
	TTLSeconds  int    `json:"ttl_seconds,omitempty"`
	IdleTimeout *int   `json:"idle_timeout,omitempty"`
	// Network is "none" or {"egress_allow": [...]} (SandboxEgress); nil = the default (none).
	Network      any                `json:"network,omitempty"`
	Env          map[string]string  `json:"env,omitempty"`
	Files        []SandboxFileInput `json:"files,omitempty"`
	Region       string             `json:"region,omitempty"`
	SandboxClass string             `json:"sandbox_class,omitempty"`
	ReadOnlyRoot *bool              `json:"read_only_root,omitempty"`
	PidsLimit    int                `json:"pids_limit,omitempty"`
	Metadata     map[string]string  `json:"metadata,omitempty"`
	Wait         *bool              `json:"wait,omitempty"`
	StartTimeout int                `json:"start_timeout,omitempty"`
}

// SandboxEgress returns the network value of an egress allowlist (addresses, CIDRs or host
// names, each with an optional port).
func SandboxEgress(allow []string) map[string]any {
	return map[string]any{"egress_allow": allow}
}

// CreateSandbox creates a sandbox. The API waits until it runs (201) unless in.Wait is false
// or the start takes longer than start_timeout (202, still pending: WaitSandbox). A sandbox
// that could not start is a FailedError.
func (c *Client) CreateSandbox(ctx context.Context, in CreateSandboxInput) (*Sandbox, error) {
	var raw json.RawMessage
	err := c.do(ctx, request{method: http.MethodPost, path: "/sandboxes", body: in}, &raw)
	var ae *APIError
	if errors.As(err, &ae) && ae.StatusCode == http.StatusBadGateway && ae.Message != "" {
		// start_failed: the cluster could not start the pod.
		ref := in.Runtime
		if in.Image != "" {
			ref = in.Image
		}
		if ref == "" {
			ref = "of the default runtime"
		}
		return nil, &FailedError{What: "sandbox", Ref: ref, Status: SandboxFailed, Message: ae.Message}
	}
	if err != nil {
		return nil, err
	}
	var s Sandbox
	if err := decodeData(raw, &s); err != nil {
		return nil, fmt.Errorf("decode POST /sandboxes: %w", err)
	}
	if s.Status == SandboxFailed {
		return &s, &FailedError{What: "sandbox", Ref: s.ID, Status: s.Status, Message: s.Error}
	}
	return &s, nil
}

// GetSandbox returns one sandbox.
func (c *Client) GetSandbox(ctx context.Context, id string) (*Sandbox, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id)}, &raw); err != nil {
		return nil, err
	}
	var s Sandbox
	if err := decodeData(raw, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

// SandboxListOptions filter GET /sandboxes.
type SandboxListOptions struct {
	// Status is active (default: pending, running, paused), running, paused, pending, ended or all.
	Status  string
	Region  string
	Runtime string
	Page    int
	PerPage int
}

// SandboxPage is the meta of a sandbox list.
type SandboxPage struct {
	Total   int `json:"total"`
	Page    int `json:"page"`
	PerPage int `json:"per_page"`
}

// ListSandboxes returns the organization's sandboxes, newest first.
func (c *Client) ListSandboxes(ctx context.Context, o SandboxListOptions) ([]Sandbox, *SandboxPage, error) {
	q := url.Values{}
	if o.Status != "" {
		q.Set("status", o.Status)
	}
	if o.Region != "" {
		q.Set("region", o.Region)
	}
	if o.Runtime != "" {
		q.Set("runtime", o.Runtime)
	}
	if o.Page > 0 {
		q.Set("page", strconv.Itoa(o.Page))
	}
	if o.PerPage > 0 {
		q.Set("per_page", strconv.Itoa(o.PerPage))
	}
	var out struct {
		Data []Sandbox   `json:"data"`
		Meta SandboxPage `json:"meta"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes", query: q}, &out); err != nil {
		return nil, nil, err
	}
	if out.Data == nil {
		out.Data = []Sandbox{}
	}
	return out.Data, &out.Meta, nil
}

// DeleteSandbox removes a sandbox (idempotent) and returns its final state.
func (c *Client) DeleteSandbox(ctx context.Context, id string) (*Sandbox, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodDelete, path: "/sandboxes/" + seg(id)}, &raw); err != nil {
		return nil, err
	}
	var s Sandbox
	if len(raw) > 0 {
		if err := decodeData(raw, &s); err != nil {
			return nil, err
		}
	}
	return &s, nil
}

// WaitSandbox polls a pending sandbox until it runs. It fails with FailedError when the
// sandbox failed or ended, and with TimeoutError after o.Timeout.
func (c *Client) WaitSandbox(ctx context.Context, id string, o WaitOptions) (*Sandbox, error) {
	interval := o.Interval
	if interval <= 0 {
		interval = time.Second
	}
	var deadline time.Time
	if o.Timeout > 0 {
		deadline = time.Now().Add(o.Timeout)
	}
	last := ""
	for {
		s, err := c.GetSandbox(ctx, id)
		if err != nil {
			return nil, err
		}
		if s.Status != last && o.Progress != nil {
			o.Progress(s.Status)
		}
		last = s.Status
		switch {
		case s.Status == SandboxRunning || s.Status == SandboxPaused:
			return s, nil
		case s.Ended() || s.Status == SandboxTerminating:
			msg := s.Error
			if msg == "" && s.EndReason != "" {
				msg = "ended: " + s.EndReason
			}
			return s, &FailedError{What: "sandbox", Ref: s.ID, Status: s.Status, Message: msg}
		}
		if !deadline.IsZero() && time.Now().After(deadline) {
			return s, &TimeoutError{What: "sandbox " + id + " to start", Timeout: o.Timeout, Last: s.Status}
		}
		select {
		case <-ctx.Done():
			return s, ctx.Err()
		case <-time.After(interval):
		}
	}
}

// SandboxEvent is one entry of a sandbox's activity log.
type SandboxEvent struct {
	ID        FlexID         `json:"id"`
	Type      string         `json:"type"`
	Message   string         `json:"message,omitempty"`
	Data      map[string]any `json:"data,omitempty"`
	UserID    FlexID         `json:"user_id,omitempty"`
	CreatedAt string         `json:"created_at,omitempty"`
}

// UnmarshalJSON tolerates data encoded as [] (an empty PHP array).
func (e *SandboxEvent) UnmarshalJSON(b []byte) error {
	type plain struct {
		ID        FlexID          `json:"id"`
		Type      string          `json:"type"`
		Message   string          `json:"message"`
		Data      json.RawMessage `json:"data"`
		UserID    FlexID          `json:"user_id"`
		CreatedAt string          `json:"created_at"`
	}
	var p plain
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*e = SandboxEvent{ID: p.ID, Type: p.Type, Message: p.Message, UserID: p.UserID, CreatedAt: p.CreatedAt}
	if len(p.Data) > 0 && p.Data[0] == '{' {
		_ = json.Unmarshal(p.Data, &e.Data)
	}
	return nil
}

// SandboxLogs returns a sandbox's activity log, newest first.
func (c *Client) SandboxLogs(ctx context.Context, id string) ([]SandboxEvent, error) {
	var out struct {
		Data struct {
			Events []SandboxEvent `json:"events"`
		} `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/logs"}, &out); err != nil {
		return nil, err
	}
	if out.Data.Events == nil {
		out.Data.Events = []SandboxEvent{}
	}
	return out.Data.Events, nil
}

// SandboxExecInput is the body of POST /sandboxes/{id}/exec.
type SandboxExecInput struct {
	// Cmd is a string (run by /bin/sh -c) or an argv list ([]string).
	Cmd         any               `json:"cmd"`
	StdinBase64 string            `json:"stdin_base64,omitempty"`
	Timeout     int               `json:"timeout,omitempty"`
	Env         map[string]string `json:"env,omitempty"`
	Cwd         string            `json:"cwd,omitempty"`
	Stream      bool              `json:"stream,omitempty"`
}

// SandboxRunInput is the body of POST /sandboxes/{id}/run (the code interpreter).
type SandboxRunInput struct {
	Code     string            `json:"code"`
	Language string            `json:"language,omitempty"`
	Timeout  int               `json:"timeout,omitempty"`
	Env      map[string]string `json:"env,omitempty"`
	Cwd      string            `json:"cwd,omitempty"`
	Stream   bool              `json:"stream,omitempty"`
}

// SandboxExecResult is the result of a command or a code run. Output that is not valid UTF-8
// arrives as StdoutBase64 / StderrBase64 (Stdout / Stderr are then empty); Bytes decodes both.
type SandboxExecResult struct {
	Language     string              `json:"language,omitempty"`
	ExitCode     int                 `json:"exit_code"`
	Stdout       string              `json:"stdout"`
	Stderr       string              `json:"stderr"`
	StdoutBase64 string              `json:"stdout_base64,omitempty"`
	StderrBase64 string              `json:"stderr_base64,omitempty"`
	TimedOut     bool                `json:"timed_out"`
	DurationMS   int64               `json:"duration_ms"`
	Truncated    bool                `json:"truncated"`
	FilesChanged []SandboxFileChange `json:"files_changed,omitempty"`
	Outputs      []SandboxOutput     `json:"outputs,omitempty"`
}

// SandboxFileChange is a file a code run created or changed under /workspace or /output.
type SandboxFileChange struct {
	Path string `json:"path"`
	Size int64  `json:"size"`
}

// SandboxOutput is a rich output (an image a code run wrote to /output).
type SandboxOutput struct {
	Path       string `json:"path"`
	Mime       string `json:"mime"`
	Size       int64  `json:"size"`
	DataBase64 string `json:"data_base64"`
}

// StdoutBytes returns stdout, decoding stdout_base64 when the output was not UTF-8.
func (r *SandboxExecResult) StdoutBytes() []byte { return textOrBase64(r.Stdout, r.StdoutBase64) }

// StderrBytes returns stderr, decoding stderr_base64 when the output was not UTF-8.
func (r *SandboxExecResult) StderrBytes() []byte { return textOrBase64(r.Stderr, r.StderrBase64) }

func textOrBase64(text, b64 string) []byte {
	if b64 != "" {
		if b, err := base64.StdEncoding.DecodeString(b64); err == nil {
			return b
		}
	}
	return []byte(text)
}

// ExecSandbox runs a command and returns its result as one JSON answer (no streaming).
func (c *Client) ExecSandbox(ctx context.Context, id string, in SandboxExecInput) (*SandboxExecResult, error) {
	in.Stream = false
	var out struct {
		Data SandboxExecResult `json:"data"`
	}
	if err := c.long().do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/exec", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// RunSandboxCode runs code with the language's interpreter and returns the structured result.
func (c *Client) RunSandboxCode(ctx context.Context, id string, in SandboxRunInput) (*SandboxExecResult, error) {
	in.Stream = false
	var out struct {
		Data SandboxExecResult `json:"data"`
	}
	if err := c.long().do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/run", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SandboxFileEntry is one entry of a directory listing.
type SandboxFileEntry struct {
	Name       string `json:"name"`
	Path       string `json:"path"`
	Type       string `json:"type"`
	Size       int64  `json:"size"`
	Mode       string `json:"mode,omitempty"`
	ModifiedAt string `json:"modified_at,omitempty"`
}

// SandboxListing is GET /sandboxes/{id}/files: a directory's entries, or the one entry of a
// file path (IsDir false).
type SandboxListing struct {
	Path      string             `json:"path"`
	IsDir     bool               `json:"is_dir"`
	Entries   []SandboxFileEntry `json:"entries"`
	Truncated bool               `json:"truncated"`
}

// SandboxFiles lists a directory (or describes a file) of a sandbox.
func (c *Client) SandboxFiles(ctx context.Context, id, path string) (*SandboxListing, error) {
	q := url.Values{}
	if path != "" {
		q.Set("path", path)
	}
	var out struct {
		Data SandboxListing `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/files", query: q}, &out); err != nil {
		return nil, err
	}
	if out.Data.Entries == nil {
		out.Data.Entries = []SandboxFileEntry{}
	}
	return &out.Data, nil
}

// SandboxWriteResult is the answer of a file or archive upload.
type SandboxWriteResult struct {
	Path  string `json:"path"`
	Size  int64  `json:"size,omitempty"`
	Bytes int64  `json:"bytes,omitempty"`
}

// ReadSandboxFile streams a file of a sandbox to w.
func (c *Client) ReadSandboxFile(ctx context.Context, id, path string, w io.Writer) (*Download, error) {
	d := &Download{W: w}
	q := url.Values{"path": {path}}
	if err := c.long().do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/files/content", query: q}, d); err != nil {
		return nil, err
	}
	return d, nil
}

// WriteSandboxFile writes content to path in a sandbox (parent directories are created);
// mode is an octal string such as "0755" or "".
func (c *Client) WriteSandboxFile(ctx context.Context, id, path string, content []byte, mode string) (*SandboxWriteResult, error) {
	q := url.Values{"path": {path}}
	if mode != "" {
		q.Set("mode", mode)
	}
	if content == nil {
		content = []byte{}
	}
	var out struct {
		Data SandboxWriteResult `json:"data"`
	}
	err := c.long().do(ctx, request{method: http.MethodPut, path: "/sandboxes/" + seg(id) + "/files/content", query: q,
		raw: content, contentType: "application/octet-stream", retry: true}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DownloadSandboxArchive streams a tar of a sandbox directory's contents (entries relative
// to it) to w.
func (c *Client) DownloadSandboxArchive(ctx context.Context, id, path string, w io.Writer) (*Download, error) {
	d := &Download{W: w}
	q := url.Values{"path": {path}}
	if err := c.long().do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/files/archive", query: q}, d); err != nil {
		return nil, err
	}
	return d, nil
}

// UploadSandboxArchive extracts a tar into a sandbox directory (created when needed).
func (c *Client) UploadSandboxArchive(ctx context.Context, id, path string, tar []byte) (*SandboxWriteResult, error) {
	q := url.Values{"path": {path}}
	var out struct {
		Data SandboxWriteResult `json:"data"`
	}
	err := c.long().do(ctx, request{method: http.MethodPut, path: "/sandboxes/" + seg(id) + "/files/archive", query: q,
		raw: tar, contentType: "application/x-tar", retry: true}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// long returns a copy of the client without the overall HTTP timeout: commands, code runs and
// transfers last as long as their own timeout (bounded by ctx).
func (c *Client) long() *Client {
	cp := *c
	hc := *c.http
	hc.Timeout = 0
	cp.http = &hc
	return &cp
}
