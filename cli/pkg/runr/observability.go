package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Observability (docs/observability.md): a project's tracing and request replay switches,
// traces read from the region's Tempo, captured failed requests and their replay, uptime
// checks with incidents, the organization's status page, alert channels, rules, fired alerts
// and the delivery log.

// ObsAttrs is an attribute or settings object (span attributes, channel config, rule params).
// PHP encodes an empty one as []; ObsAttrs accepts that and null.
type ObsAttrs map[string]any

// UnmarshalJSON accepts an object, an empty array or null.
func (m *ObsAttrs) UnmarshalJSON(b []byte) error {
	trimmed := bytes.TrimSpace(b)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) || trimmed[0] == '[' {
		*m = ObsAttrs{}
		return nil
	}
	var raw map[string]any
	if err := json.Unmarshal(trimmed, &raw); err != nil {
		return err
	}
	*m = raw
	return nil
}

// Keys returns the keys sorted.
func (m ObsAttrs) Keys() []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// String renders a value for a table cell.
func (m ObsAttrs) String(key string) string {
	v, ok := m[key]
	if !ok || v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return strconv.FormatFloat(t, 'f', -1, 64)
	case bool:
		return strconv.FormatBool(t)
	}
	enc, _ := json.Marshal(v)
	return string(enc)
}

// ---------------------------------------------------------------------------------------
// Project switches

// ProjectObservability is GET /projects/{project}/observability.
type ProjectObservability struct {
	ProjectID             string              `json:"project_id"`
	TracingEnabled        bool                `json:"tracing_enabled"`
	TracingSampleRatio    float64             `json:"tracing_sample_ratio"`
	ReplayEnabled         bool                `json:"replay_enabled"`
	ReplayMaxBodyBytes    int                 `json:"replay_max_body_bytes"`
	ReplayRetentionDays   int                 `json:"replay_retention_days"`
	ReplayRedactHeaders   []string            `json:"replay_redact_headers"`
	ReplayRedactQuery     []string            `json:"replay_redact_query"`
	PlatformRedactHeaders []string            `json:"platform_redact_headers,omitempty"`
	PlatformRedactQuery   []string            `json:"platform_redact_query,omitempty"`
	RegionTracing         *RegionTracingState `json:"region_tracing"`
	ReplayAvailable       bool                `json:"replay_available"`
	ReplayTarget          string              `json:"replay_target,omitempty"`
	// RequiresRedeploy is set on the answer of a change: the project has a live deployment,
	// and the change applies with the next deployment.
	RequiresRedeploy bool `json:"requires_redeploy,omitempty"`
}

// RegionTracingState is the tracing backend of the project's region.
type RegionTracingState struct {
	Mode         string `json:"mode"`
	Status       string `json:"status"`
	Queryable    bool   `json:"queryable"`
	AcceptsSpans bool   `json:"accepts_spans"`
}

// ReplaySettings is the body of PUT /projects/{project}/observability/replay. Nil fields keep
// the stored value.
type ReplaySettings struct {
	Enabled       bool      `json:"enabled"`
	MaxBodyBytes  *int      `json:"max_body_bytes,omitempty"`
	RetentionDays *int      `json:"retention_days,omitempty"`
	RedactHeaders *[]string `json:"redact_headers,omitempty"`
	RedactQuery   *[]string `json:"redact_query,omitempty"`
}

// ProjectObservability returns a project's tracing and replay settings.
func (c *Client) ProjectObservability(ctx context.Context, projectID string) (*ProjectObservability, error) {
	var out struct {
		Data ProjectObservability `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/observability"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetTracing switches a project's tracing on or off; sampleRatio nil keeps the stored ratio.
// The change applies with the next deployment (RequiresRedeploy).
func (c *Client) SetTracing(ctx context.Context, projectID string, enabled bool, sampleRatio *float64) (*ProjectObservability, error) {
	body := map[string]any{"enabled": enabled}
	if sampleRatio != nil {
		body["sample_ratio"] = *sampleRatio
	}
	return c.putObservability(ctx, "/projects/"+projectID+"/observability/tracing", body)
}

// SetReplay switches the capture of failed requests on or off and sets its limits.
func (c *Client) SetReplay(ctx context.Context, projectID string, in ReplaySettings) (*ProjectObservability, error) {
	return c.putObservability(ctx, "/projects/"+projectID+"/observability/replay", in)
}

func (c *Client) putObservability(ctx context.Context, path string, body any) (*ProjectObservability, error) {
	var out struct {
		Data             ProjectObservability `json:"data"`
		RequiresRedeploy bool                 `json:"requires_redeploy"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: path, body: body}, &out); err != nil {
		return nil, err
	}
	out.Data.RequiresRedeploy = out.RequiresRedeploy
	return &out.Data, nil
}

// ---------------------------------------------------------------------------------------
// Traces

// Trace search statuses.
const (
	TraceStatusAll   = "all"
	TraceStatusError = "error"
	TraceStatusOK    = "ok"
)

// TraceFilter narrows GET /projects/{project}/traces. Zero values are not sent.
type TraceFilter struct {
	// Status is all, error or ok.
	Status        string
	MinDurationMs float64
	// Path is a substring of the request path.
	Path     string
	From, To time.Time
	// Limit is 1-200 (the API's default is 50).
	Limit int
}

// TraceSearch is the answer of a trace search.
type TraceSearch struct {
	TraceQL string         `json:"traceql"`
	From    int64          `json:"from"`
	To      int64          `json:"to"`
	Traces  []TraceSummary `json:"traces"`
}

// TraceSummary is one trace of a search: its root and the project's server span.
type TraceSummary struct {
	TraceID     string            `json:"trace_id"`
	RootService string            `json:"root_service,omitempty"`
	RootName    string            `json:"root_name,omitempty"`
	Start       string            `json:"start,omitempty"`
	StartMs     int64             `json:"start_ms"`
	DurationMs  *float64          `json:"duration_ms,omitempty"`
	StatusCode  *int              `json:"status_code,omitempty"`
	Path        string            `json:"path,omitempty"`
	ColdStart   any               `json:"cold_start,omitempty"`
	Error       bool              `json:"error"`
	Matched     int               `json:"matched"`
	Spans       []TraceSearchSpan `json:"spans,omitempty"`
}

// TraceSearchSpan is a matched span of a search result with the selected attributes.
type TraceSearchSpan struct {
	SpanID     string   `json:"span_id"`
	DurationMs float64  `json:"duration_ms"`
	Attributes ObsAttrs `json:"attributes"`
}

// Trace is one trace: its spans in waterfall order (depth-first, children by start time).
type Trace struct {
	TraceID    string   `json:"trace_id"`
	Start      string   `json:"start,omitempty"`
	StartMs    int64    `json:"start_ms"`
	EndMs      int64    `json:"end_ms"`
	DurationMs float64  `json:"duration_ms"`
	SpanCount  int      `json:"span_count"`
	ErrorCount int      `json:"error_count"`
	Services   []string `json:"services"`
	Spans      []Span   `json:"spans"`
}

// Span is one span of a trace.
type Span struct {
	TraceID       string      `json:"trace_id"`
	SpanID        string      `json:"span_id"`
	ParentSpanID  string      `json:"parent_span_id,omitempty"`
	Name          string      `json:"name"`
	Kind          string      `json:"kind"`
	Service       string      `json:"service"`
	Scope         string      `json:"scope,omitempty"`
	StartNs       string      `json:"start_ns"`
	EndNs         string      `json:"end_ns"`
	Status        SpanStatus  `json:"status"`
	Attributes    ObsAttrs    `json:"attributes"`
	Events        []SpanEvent `json:"events,omitempty"`
	Resource      ObsAttrs    `json:"resource,omitempty"`
	StartOffsetMs float64     `json:"start_offset_ms"`
	DurationMs    float64     `json:"duration_ms"`
	Depth         int         `json:"depth"`
	// MissingParent marks a span whose parent is not part of the trace (sampled out upstream).
	MissingParent bool `json:"missing_parent,omitempty"`
}

// SpanStatus is unset, ok or error with an optional message.
type SpanStatus struct {
	Code    string `json:"code"`
	Message string `json:"message,omitempty"`
}

// SpanEvent is an event of a span (an exception, a log record).
type SpanEvent struct {
	Name       string   `json:"name"`
	TimeNs     string   `json:"time_ns"`
	OffsetMs   float64  `json:"offset_ms"`
	Attributes ObsAttrs `json:"attributes"`
}

// TraceLogLine is a log line of the project's pods that carries the trace id.
type TraceLogLine struct {
	TsMs      int64  `json:"ts_ms"`
	Pod       string `json:"pod"`
	Container string `json:"container,omitempty"`
	Line      string `json:"line"`
}

// SearchTraces searches a project's traces. A region whose tracing backend RunR cannot query
// answers 409; one whose Tempo does not answer 503.
func (c *Client) SearchTraces(ctx context.Context, projectID string, f TraceFilter) (*TraceSearch, error) {
	q := url.Values{}
	if f.Status != "" {
		q.Set("status", f.Status)
	}
	if f.MinDurationMs > 0 {
		q.Set("min_duration_ms", strconv.FormatFloat(f.MinDurationMs, 'f', -1, 64))
	}
	if f.Path != "" {
		q.Set("path", f.Path)
	}
	if !f.From.IsZero() {
		q.Set("from", strconv.FormatInt(f.From.Unix(), 10))
	}
	if !f.To.IsZero() {
		q.Set("to", strconv.FormatInt(f.To.Unix(), 10))
	}
	if f.Limit > 0 {
		q.Set("limit", strconv.Itoa(f.Limit))
	}
	var out struct {
		Data TraceSearch `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/traces", query: q}, &out); err != nil {
		return nil, err
	}
	if out.Data.Traces == nil {
		out.Data.Traces = []TraceSummary{}
	}
	return &out.Data, nil
}

// ValidTraceID reports whether id is a 32-digit hex trace id (W3C trace context).
func ValidTraceID(id string) bool {
	if len(id) != 32 {
		return false
	}
	for _, r := range id {
		if !strings.ContainsRune("0123456789abcdefABCDEF", r) {
			return false
		}
	}
	return true
}

// GetTrace returns one trace of a project.
func (c *Client) GetTrace(ctx context.Context, projectID, traceID string) (*Trace, error) {
	if !ValidTraceID(traceID) {
		return nil, &NotFoundError{Kind: "trace", Ref: traceID}
	}
	var out struct {
		Data Trace `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/traces/" + strings.ToLower(traceID)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "trace", Ref: traceID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// TraceLogs returns the log lines of a trace (needs logs.view besides traces.view).
func (c *Client) TraceLogs(ctx context.Context, projectID, traceID string) ([]TraceLogLine, error) {
	if !ValidTraceID(traceID) {
		return nil, &NotFoundError{Kind: "trace", Ref: traceID}
	}
	var out struct {
		Data []TraceLogLine `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/traces/" + strings.ToLower(traceID) + "/logs"}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "trace", Ref: traceID}
	}
	if err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []TraceLogLine{}
	}
	return out.Data, nil
}

// ---------------------------------------------------------------------------------------
// Request replay

// ReplayCapture is one captured failed request (summary; the detail adds the request and
// response).
type ReplayCapture struct {
	ID               string   `json:"id"`
	CapturedAt       string   `json:"captured_at,omitempty"`
	ExpiresAt        string   `json:"expires_at,omitempty"`
	Trigger          string   `json:"trigger,omitempty"`
	Method           string   `json:"method"`
	Path             string   `json:"path"`
	Query            string   `json:"query,omitempty"`
	Status           int      `json:"status"`
	TimedOut         bool     `json:"timed_out"`
	DurationMs       *float64 `json:"duration_ms,omitempty"`
	TraceID          string   `json:"trace_id,omitempty"`
	RequestID        string   `json:"request_id,omitempty"`
	Revision         string   `json:"revision,omitempty"`
	BodySize         int      `json:"body_size"`
	ReplayCount      int      `json:"replay_count"`
	LastReplayedAt   string   `json:"last_replayed_at,omitempty"`
	LastReplayStatus *int     `json:"last_replay_status,omitempty"`

	Headers            ObsAttrs        `json:"headers,omitempty"`
	RedactedHeaders    []string        `json:"redacted_headers,omitempty"`
	Body               string          `json:"body,omitempty"`
	BodyBase64         bool            `json:"body_base64,omitempty"`
	BodyTruncated      bool            `json:"body_truncated,omitempty"`
	ResponseHeaders    ObsAttrs        `json:"response_headers,omitempty"`
	ResponseBody       string          `json:"response_body,omitempty"`
	ResponseBodyBase64 bool            `json:"response_body_base64,omitempty"`
	ResponseTruncated  bool            `json:"response_truncated,omitempty"`
	LastReplay         json.RawMessage `json:"last_replay,omitempty"`
}

// ReplayResponse is the original or the replayed answer.
type ReplayResponse struct {
	Status     int      `json:"status"`
	Headers    ObsAttrs `json:"headers"`
	Body       string   `json:"body"`
	BodyBase64 bool     `json:"body_base64"`
	Truncated  bool     `json:"truncated"`
	Error      string   `json:"error,omitempty"`
	DurationMs float64  `json:"duration_ms,omitempty"`
	ReplayedAt string   `json:"replayed_at,omitempty"`
	URL        string   `json:"url,omitempty"`
	Version    *int     `json:"version,omitempty"`
}

// ReplayDiff compares the original answer with the replay.
type ReplayDiff struct {
	Status struct {
		Before  int  `json:"before"`
		After   int  `json:"after"`
		Changed bool `json:"changed"`
	} `json:"status"`
	Headers struct {
		Added   ObsAttrs `json:"added"`
		Removed ObsAttrs `json:"removed"`
		// Changed maps a header to [before, after].
		Changed ObsAttrs `json:"changed"`
	} `json:"headers"`
	Body struct {
		Changed   bool `json:"changed"`
		Truncated bool `json:"truncated"`
		Lines     []struct {
			Op   string `json:"op"`
			Text string `json:"text"`
		} `json:"lines"`
	} `json:"body"`
	Changed bool `json:"changed"`
}

// ReplayResult is the answer of a replay.
type ReplayResult struct {
	Capture  ReplayCapture  `json:"capture"`
	Original ReplayResponse `json:"original"`
	Replay   ReplayResponse `json:"replay"`
	Diff     ReplayDiff     `json:"diff"`
}

// ListReplays returns a page of a project's captures, newest first; status (an HTTP status)
// and path (substring) filter when set.
func (c *Client) ListReplays(ctx context.Context, projectID string, status int, path string, page int) ([]ReplayCapture, *Page, error) {
	q := url.Values{}
	if status > 0 {
		q.Set("status", strconv.Itoa(status))
	}
	if path != "" {
		q.Set("path", path)
	}
	if page > 1 {
		q.Set("page", strconv.Itoa(page))
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/replays", query: q}, &raw); err != nil {
		return nil, nil, err
	}
	list := []ReplayCapture{}
	var pg Page
	if err := decodeList(raw, &list, &pg); err != nil {
		return nil, nil, fmt.Errorf("decode replays: %w", err)
	}
	return list, &pg, nil
}

// GetReplay returns one capture with its (redacted) request and response.
func (c *Client) GetReplay(ctx context.Context, projectID, captureID string) (*ReplayCapture, error) {
	var out struct {
		Data ReplayCapture `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/replays/" + seg(captureID)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "captured request", Ref: captureID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// Replay sends a captured request again to the project's current revision. headers are added
// for this replay only (never stored); query replaces the captured query when not nil.
func (c *Client) Replay(ctx context.Context, projectID, captureID string, headers map[string]string, query *string) (*ReplayResult, error) {
	body := map[string]any{}
	if len(headers) > 0 {
		body["headers"] = headers
	}
	if query != nil {
		body["query"] = *query
	}
	var out struct {
		Data ReplayResult `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/replays/" + seg(captureID) + "/replay", body: body}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "captured request", Ref: captureID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteReplay deletes one capture.
func (c *Client) DeleteReplay(ctx context.Context, projectID, captureID string) error {
	err := c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/replays/" + seg(captureID)}, nil)
	if IsNotFound(err) {
		return &NotFoundError{Kind: "captured request", Ref: captureID}
	}
	return err
}

// DeleteAllReplays deletes every capture of a project and returns how many there were.
func (c *Client) DeleteAllReplays(ctx context.Context, projectID string) (int, error) {
	var out struct {
		Deleted int `json:"deleted"`
	}
	if err := c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/replays"}, &out); err != nil {
		return 0, err
	}
	return out.Deleted, nil
}

// ---------------------------------------------------------------------------------------
// Uptime checks

// Uptime check locations and states.
const (
	UptimeControlPlane = "control_plane"
	UptimeRegions      = "regions"
	UptimeUp           = "up"
	UptimeDown         = "down"
	UptimeUnknown      = "unknown"
)

// UptimeCheck is one synthetic check of a project. The list adds the uptime figures.
type UptimeCheck struct {
	ID                string          `json:"id"`
	ProjectID         string          `json:"project_id"`
	Name              string          `json:"name"`
	URL               string          `json:"url,omitempty"`
	Path              string          `json:"path,omitempty"`
	Method            string          `json:"method,omitempty"`
	HeaderNames       []string        `json:"header_names,omitempty"`
	IntervalSeconds   int             `json:"interval_seconds"`
	TimeoutSeconds    int             `json:"timeout_seconds"`
	ExpectedStatus    string          `json:"expected_status,omitempty"`
	BodyRegex         string          `json:"body_regex,omitempty"`
	Locations         string          `json:"locations,omitempty"`
	RegionIDs         []string        `json:"region_ids,omitempty"`
	FailureThreshold  int             `json:"failure_threshold"`
	RecoveryThreshold int             `json:"recovery_threshold"`
	IsEnabled         bool            `json:"is_enabled"`
	ShowOnStatusPage  bool            `json:"show_on_status_page"`
	StatusPageName    string          `json:"status_page_name,omitempty"`
	StatusPageOrder   int             `json:"status_page_order"`
	Status            string          `json:"status"`
	LocationState     json.RawMessage `json:"location_state,omitempty"`
	LastCheckedAt     string          `json:"last_checked_at,omitempty"`
	NextCheckAt       string          `json:"next_check_at,omitempty"`
	LastResponseMs    *int            `json:"last_response_ms,omitempty"`
	LastStatusCode    *int            `json:"last_status_code,omitempty"`
	LastError         string          `json:"last_error,omitempty"`
	Uptime24h         *float64        `json:"uptime_24h,omitempty"`
	Uptime7d          *float64        `json:"uptime_7d,omitempty"`
	Uptime30d         *float64        `json:"uptime_30d,omitempty"`
	AvgResponseMs24h  *int            `json:"avg_response_ms_24h,omitempty"`
}

// Target returns the absolute URL or the path the check probes.
func (u *UptimeCheck) Target() string {
	if u.URL != "" {
		return u.URL
	}
	if u.Path != "" {
		return u.Path
	}
	return "/"
}

// UptimeCheckInput is the body of POST and PUT …/uptime-checks. Nil fields are not sent (an
// update keeps them; on create the API's defaults apply).
type UptimeCheckInput struct {
	Name              *string            `json:"name,omitempty"`
	URL               *string            `json:"url,omitempty"`
	Path              *string            `json:"path,omitempty"`
	Method            *string            `json:"method,omitempty"`
	Headers           *map[string]string `json:"headers,omitempty"`
	IntervalSeconds   *int               `json:"interval_seconds,omitempty"`
	TimeoutSeconds    *int               `json:"timeout_seconds,omitempty"`
	ExpectedStatus    *string            `json:"expected_status,omitempty"`
	BodyRegex         *string            `json:"body_regex,omitempty"`
	Locations         *string            `json:"locations,omitempty"`
	RegionIDs         *[]string          `json:"region_ids,omitempty"`
	FailureThreshold  *int               `json:"failure_threshold,omitempty"`
	RecoveryThreshold *int               `json:"recovery_threshold,omitempty"`
	IsEnabled         *bool              `json:"is_enabled,omitempty"`
	ShowOnStatusPage  *bool              `json:"show_on_status_page,omitempty"`
	StatusPageName    *string            `json:"status_page_name,omitempty"`
	StatusPageOrder   *int               `json:"status_page_order,omitempty"`
}

// UptimeResult is one probe of a check from one location.
type UptimeResult struct {
	Location   string `json:"location"`
	OK         bool   `json:"ok"`
	StatusCode *int   `json:"status_code,omitempty"`
	ResponseMs *int   `json:"response_ms,omitempty"`
	Error      string `json:"error,omitempty"`
	CheckedAt  string `json:"checked_at,omitempty"`
}

// UptimeDay is one daily bar (uptime % of the day's results).
type UptimeDay struct {
	Date   string   `json:"date"`
	Total  int      `json:"total"`
	Up     int      `json:"up"`
	Uptime *float64 `json:"uptime"`
}

// UptimeIncident is a down period of a check.
type UptimeIncident struct {
	ID              string `json:"id"`
	Status          string `json:"status"`
	Title           string `json:"title"`
	Message         string `json:"message,omitempty"`
	StartedAt       string `json:"started_at,omitempty"`
	ResolvedAt      string `json:"resolved_at,omitempty"`
	DurationSeconds *int   `json:"duration_seconds,omitempty"`
	UptimeCheckID   string `json:"uptime_check_id,omitempty"`
	ProjectID       string `json:"project_id,omitempty"`
	Cause           string `json:"cause,omitempty"`
	FailedChecks    int    `json:"failed_checks,omitempty"`
}

// Open reports whether the incident is not resolved.
func (i *UptimeIncident) Open() bool { return i.ResolvedAt == "" }

// UptimeReport is the answer of GET …/uptime-checks/{check}/results.
type UptimeReport struct {
	Check     UptimeCheck    `json:"check"`
	Results   []UptimeResult `json:"results"`
	Locations []string       `json:"locations"`
	Uptime    struct {
		H24 *float64 `json:"24h"`
		D7  *float64 `json:"7d"`
		D30 *float64 `json:"30d"`
	} `json:"uptime"`
	AvgResponseMs24h *int             `json:"avg_response_ms_24h,omitempty"`
	Days             []UptimeDay      `json:"days"`
	Incidents        []UptimeIncident `json:"incidents"`
}

// UptimeRun is the answer of a probe started by hand.
type UptimeRun struct {
	OK         bool        `json:"ok"`
	StatusCode *int        `json:"status_code,omitempty"`
	ResponseMs *int        `json:"response_ms,omitempty"`
	Error      string      `json:"error,omitempty"`
	Check      UptimeCheck `json:"check"`
}

func uptimePath(projectID string) string { return "/projects/" + projectID + "/uptime-checks" }

// ListUptimeChecks returns a project's checks with their uptime of 24 h, 7 d and 30 d.
func (c *Client) ListUptimeChecks(ctx context.Context, projectID string) ([]UptimeCheck, error) {
	var out struct {
		Data []UptimeCheck `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: uptimePath(projectID)}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []UptimeCheck{}
	}
	return out.Data, nil
}

// FindUptimeCheck resolves a check of a project by id or name.
func (c *Client) FindUptimeCheck(ctx context.Context, projectID, ref string) (*UptimeCheck, error) {
	list, err := c.ListUptimeChecks(ctx, projectID)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == ref {
			return &list[i], nil
		}
	}
	var match []*UptimeCheck
	for i := range list {
		if strings.EqualFold(list[i].Name, ref) {
			match = append(match, &list[i])
		}
	}
	switch len(match) {
	case 1:
		return match[0], nil
	case 0:
		return nil, &NotFoundError{Kind: "uptime check", Ref: ref}
	}
	return nil, fmt.Errorf("%d uptime checks are named %q; use the id", len(match), ref)
}

// CreateUptimeCheck creates a check (name is required).
func (c *Client) CreateUptimeCheck(ctx context.Context, projectID string, in UptimeCheckInput) (*UptimeCheck, error) {
	var out struct {
		Data UptimeCheck `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: uptimePath(projectID), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateUptimeCheck changes the fields set in in.
func (c *Client) UpdateUptimeCheck(ctx context.Context, projectID, checkID string, in UptimeCheckInput) (*UptimeCheck, error) {
	var out struct {
		Data UptimeCheck `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: uptimePath(projectID) + "/" + seg(checkID), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteUptimeCheck deletes a check with its results and incidents.
func (c *Client) DeleteUptimeCheck(ctx context.Context, projectID, checkID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: uptimePath(projectID) + "/" + seg(checkID)}, nil)
}

// UptimeResults returns a check's results of the last hours (24 when 0), optionally of one
// location, with its uptime, daily bars and incidents.
func (c *Client) UptimeResults(ctx context.Context, projectID, checkID string, hours int, location string) (*UptimeReport, error) {
	q := url.Values{}
	if hours > 0 {
		q.Set("hours", strconv.Itoa(hours))
	}
	if location != "" {
		q.Set("location", location)
	}
	var out struct {
		Data UptimeReport `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: uptimePath(projectID) + "/" + seg(checkID) + "/results", query: q}, &out); err != nil {
		return nil, err
	}
	if out.Data.Results == nil {
		out.Data.Results = []UptimeResult{}
	}
	return &out.Data, nil
}

// RunUptimeCheck probes a check now from the control plane.
func (c *Client) RunUptimeCheck(ctx context.Context, projectID, checkID string) (*UptimeRun, error) {
	var out struct {
		Data UptimeRun `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: uptimePath(projectID) + "/" + seg(checkID) + "/run"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ListUptimeIncidents returns a project's incidents, newest first (at most 100).
func (c *Client) ListUptimeIncidents(ctx context.Context, projectID string) ([]UptimeIncident, error) {
	var out struct {
		Data []UptimeIncident `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/uptime-incidents"}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []UptimeIncident{}
	}
	return out.Data, nil
}

// UpdateUptimeIncident sets the public title and message of an incident (nil keeps them).
func (c *Client) UpdateUptimeIncident(ctx context.Context, projectID, incidentID string, title, message *string) (*UptimeIncident, error) {
	body := map[string]any{}
	if title != nil {
		body["title"] = *title
	}
	if message != nil {
		body["message"] = *message
	}
	var out struct {
		Data UptimeIncident `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: "/projects/" + projectID + "/uptime-incidents/" + seg(incidentID), body: body}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "uptime incident", Ref: incidentID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ---------------------------------------------------------------------------------------
// Status page

// StatusPage is the organization's public status page.
type StatusPage struct {
	ID                  string `json:"id,omitempty"`
	Slug                string `json:"slug"`
	Title               string `json:"title"`
	Description         string `json:"description,omitempty"`
	LogoURL             string `json:"logo_url,omitempty"`
	CustomDomain        string `json:"custom_domain,omitempty"`
	IsPublic            bool   `json:"is_public"`
	ShowIncidentHistory bool   `json:"show_incident_history"`
	HistoryDays         int    `json:"history_days"`
	// Exists is false until the page was saved once (the answer then holds the defaults).
	Exists bool `json:"exists"`
}

// StatusPageComponent is an uptime check as a component of the status page.
type StatusPageComponent struct {
	ID               string `json:"id"`
	Name             string `json:"name"`
	ProjectID        string `json:"project_id"`
	ProjectName      string `json:"project_name,omitempty"`
	ShowOnStatusPage bool   `json:"show_on_status_page"`
	StatusPageName   string `json:"status_page_name,omitempty"`
	StatusPageOrder  int    `json:"status_page_order"`
	Status           string `json:"status"`
}

// StatusPageSettings is GET /observability/status-page.
type StatusPageSettings struct {
	Page       StatusPage            `json:"page"`
	Components []StatusPageComponent `json:"components"`
	Preview    json.RawMessage       `json:"preview,omitempty"`
	PublicPath string                `json:"public_path"`
}

// StatusPageInput is the body of PUT /observability/status-page; slug, title and is_public are
// always sent (the API replaces the page settings).
type StatusPageInput struct {
	Slug                string                     `json:"slug"`
	Title               string                     `json:"title"`
	Description         *string                    `json:"description,omitempty"`
	LogoURL             *string                    `json:"logo_url,omitempty"`
	CustomDomain        *string                    `json:"custom_domain,omitempty"`
	IsPublic            bool                       `json:"is_public"`
	ShowIncidentHistory *bool                      `json:"show_incident_history,omitempty"`
	HistoryDays         *int                       `json:"history_days,omitempty"`
	Components          []StatusPageComponentInput `json:"components,omitempty"`
}

// StatusPageComponentInput shows or hides an uptime check on the page.
type StatusPageComponentInput struct {
	ID               string  `json:"id"`
	ShowOnStatusPage bool    `json:"show_on_status_page"`
	StatusPageName   *string `json:"status_page_name,omitempty"`
	StatusPageOrder  *int    `json:"status_page_order,omitempty"`
}

// StatusPage returns the status page settings, its components and the public path.
func (c *Client) StatusPage(ctx context.Context) (*StatusPageSettings, error) {
	var out struct {
		Data StatusPageSettings `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/observability/status-page"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetStatusPage saves the status page settings.
func (c *Client) SetStatusPage(ctx context.Context, in StatusPageInput) (*StatusPageSettings, error) {
	var out struct {
		Data StatusPageSettings `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/observability/status-page", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ---------------------------------------------------------------------------------------
// Alerting

// Alert channel types, rule types and severities.
var (
	AlertChannelTypes = []string{"email", "slack", "teams", "telegram", "webhook"}
	AlertRuleTypes    = []string{"resource_threshold", "uptime_down", "error_rate", "p95_latency", "cold_start_rate"}
	AlertSeverities   = []string{"info", "warning", "critical"}
)

// AlertChannel is where alerts are sent. Config is the masked summary: secrets (webhook URLs,
// bot tokens, signing secrets) are write-only and never returned.
type AlertChannel struct {
	ID            string   `json:"id"`
	Name          string   `json:"name"`
	Type          string   `json:"type"`
	Config        ObsAttrs `json:"config"`
	IsEnabled     bool     `json:"is_enabled"`
	LastTestAt    string   `json:"last_test_at,omitempty"`
	LastTestOK    *bool    `json:"last_test_ok,omitempty"`
	LastTestError string   `json:"last_test_error,omitempty"`
	CreatedAt     string   `json:"created_at,omitempty"`
}

// AlertChannelInput is the body of POST and PUT …/alert-channels. On update Type is not sent
// and config keys left out keep their stored (secret) values.
type AlertChannelInput struct {
	Name      *string        `json:"name,omitempty"`
	Type      *string        `json:"type,omitempty"`
	Config    map[string]any `json:"config,omitempty"`
	IsEnabled *bool          `json:"is_enabled,omitempty"`
}

// AlertChannelTest is the answer of a test message.
type AlertChannelTest struct {
	OK           bool         `json:"ok"`
	ResponseCode *int         `json:"response_code,omitempty"`
	Error        string       `json:"error,omitempty"`
	Channel      AlertChannel `json:"channel"`
}

// AlertRule decides when an alert fires and where it goes.
type AlertRule struct {
	ID              string   `json:"id"`
	Name            string   `json:"name"`
	Type            string   `json:"type"`
	ProjectID       string   `json:"project_id,omitempty"`
	RegionID        string   `json:"region_id,omitempty"`
	Params          ObsAttrs `json:"params"`
	Severity        string   `json:"severity"`
	CooldownMinutes int      `json:"cooldown_minutes"`
	ChannelIDs      []string `json:"channel_ids"`
	IsEnabled       bool     `json:"is_enabled"`
	State           string   `json:"state"`
	LastValue       *float64 `json:"last_value,omitempty"`
	LastEvaluatedAt string   `json:"last_evaluated_at,omitempty"`
	LastFiredAt     string   `json:"last_fired_at,omitempty"`
}

// AlertRuleInput is the body of POST and PUT …/alert-rules. Type is fixed after creation.
type AlertRuleInput struct {
	Name            *string        `json:"name,omitempty"`
	Type            *string        `json:"type,omitempty"`
	ProjectID       *string        `json:"project_id,omitempty"`
	RegionID        *string        `json:"region_id,omitempty"`
	Params          map[string]any `json:"params,omitempty"`
	Severity        *string        `json:"severity,omitempty"`
	CooldownMinutes *int           `json:"cooldown_minutes,omitempty"`
	ChannelIDs      *[]string      `json:"channel_ids,omitempty"`
	IsEnabled       *bool          `json:"is_enabled,omitempty"`
}

// AlertEvent is a fired (or resolved) alert.
type AlertEvent struct {
	ID          string   `json:"id"`
	AlertRuleID string   `json:"alert_rule_id,omitempty"`
	Type        string   `json:"type"`
	Severity    string   `json:"severity"`
	Status      string   `json:"status"`
	Title       string   `json:"title"`
	Message     string   `json:"message,omitempty"`
	Data        ObsAttrs `json:"data,omitempty"`
	ProjectID   string   `json:"project_id,omitempty"`
	FiredAt     string   `json:"fired_at,omitempty"`
	ResolvedAt  string   `json:"resolved_at,omitempty"`
}

// AlertDelivery is one message of an alert to one channel.
type AlertDelivery struct {
	ID             string `json:"id"`
	AlertEventID   string `json:"alert_event_id,omitempty"`
	AlertChannelID string `json:"alert_channel_id"`
	ChannelName    string `json:"channel_name,omitempty"`
	ChannelType    string `json:"channel_type,omitempty"`
	Kind           string `json:"kind"`
	Status         string `json:"status"`
	Attempts       int    `json:"attempts"`
	ResponseCode   *int   `json:"response_code,omitempty"`
	LastError      string `json:"last_error,omitempty"`
	NextAttemptAt  string `json:"next_attempt_at,omitempty"`
	SentAt         string `json:"sent_at,omitempty"`
	CreatedAt      string `json:"created_at,omitempty"`
	Event          *struct {
		Title    string `json:"title"`
		Severity string `json:"severity"`
		Status   string `json:"status"`
	} `json:"event,omitempty"`
}

const alertsPath = "/observability"

// ListAlertChannels returns the organization's alert channels.
func (c *Client) ListAlertChannels(ctx context.Context) ([]AlertChannel, error) {
	var out struct {
		Data []AlertChannel `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: alertsPath + "/alert-channels"}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []AlertChannel{}
	}
	return out.Data, nil
}

// FindAlertChannel resolves a channel by id or name.
func (c *Client) FindAlertChannel(ctx context.Context, ref string) (*AlertChannel, error) {
	list, err := c.ListAlertChannels(ctx)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == ref {
			return &list[i], nil
		}
	}
	for i := range list {
		if strings.EqualFold(list[i].Name, ref) {
			return &list[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "alert channel", Ref: ref}
}

// CreateAlertChannel creates a channel (name, type and config are required).
func (c *Client) CreateAlertChannel(ctx context.Context, in AlertChannelInput) (*AlertChannel, error) {
	var out struct {
		Data AlertChannel `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: alertsPath + "/alert-channels", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateAlertChannel changes a channel; config keys left out keep their stored values.
func (c *Client) UpdateAlertChannel(ctx context.Context, channelID string, in AlertChannelInput) (*AlertChannel, error) {
	in.Type = nil
	var out struct {
		Data AlertChannel `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: alertsPath + "/alert-channels/" + seg(channelID), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteAlertChannel deletes a channel; the rules stop routing to it.
func (c *Client) DeleteAlertChannel(ctx context.Context, channelID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: alertsPath + "/alert-channels/" + seg(channelID)}, nil)
}

// TestAlertChannel sends a test message through a channel at once.
func (c *Client) TestAlertChannel(ctx context.Context, channelID string) (*AlertChannelTest, error) {
	var out struct {
		Data AlertChannelTest `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: alertsPath + "/alert-channels/" + seg(channelID) + "/test"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ListAlertRules returns the organization's alert rules.
func (c *Client) ListAlertRules(ctx context.Context) ([]AlertRule, error) {
	var out struct {
		Data []AlertRule `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: alertsPath + "/alert-rules"}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []AlertRule{}
	}
	return out.Data, nil
}

// FindAlertRule resolves a rule by id or name.
func (c *Client) FindAlertRule(ctx context.Context, ref string) (*AlertRule, error) {
	list, err := c.ListAlertRules(ctx)
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == ref {
			return &list[i], nil
		}
	}
	for i := range list {
		if strings.EqualFold(list[i].Name, ref) {
			return &list[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "alert rule", Ref: ref}
}

// CreateAlertRule creates a rule (name and type are required; params depend on the type).
func (c *Client) CreateAlertRule(ctx context.Context, in AlertRuleInput) (*AlertRule, error) {
	var out struct {
		Data AlertRule `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: alertsPath + "/alert-rules", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// UpdateAlertRule changes a rule; its type cannot change.
func (c *Client) UpdateAlertRule(ctx context.Context, ruleID string, in AlertRuleInput) (*AlertRule, error) {
	in.Type = nil
	var out struct {
		Data AlertRule `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: alertsPath + "/alert-rules/" + seg(ruleID), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteAlertRule deletes a rule; its firing alerts are closed without a message.
func (c *Client) DeleteAlertRule(ctx context.Context, ruleID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: alertsPath + "/alert-rules/" + seg(ruleID)}, nil)
}

// ListAlertEvents returns fired alerts, newest first (at most 200); status is firing or
// resolved ("" = both).
func (c *Client) ListAlertEvents(ctx context.Context, status string) ([]AlertEvent, error) {
	q := url.Values{}
	if status != "" {
		q.Set("status", status)
	}
	var out struct {
		Data []AlertEvent `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: alertsPath + "/alert-events", query: q}, &out); err != nil {
		return nil, err
	}
	if out.Data == nil {
		out.Data = []AlertEvent{}
	}
	return out.Data, nil
}

// ListAlertDeliveries returns the newest 50 entries of the delivery log; channelID and status
// (pending, sent, failed) filter when set. The page tells the total.
func (c *Client) ListAlertDeliveries(ctx context.Context, channelID, status string) ([]AlertDelivery, *Page, error) {
	q := url.Values{}
	if channelID != "" {
		q.Set("channel_id", channelID)
	}
	if status != "" {
		q.Set("status", status)
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: alertsPath + "/alert-deliveries", query: q}, &raw); err != nil {
		return nil, nil, err
	}
	list := []AlertDelivery{}
	var pg Page
	if err := decodeList(raw, &list, &pg); err != nil {
		return nil, nil, fmt.Errorf("decode alert deliveries: %w", err)
	}
	return list, &pg, nil
}

// RetryAlertDelivery queues a failed delivery again (409 for one that did not fail).
func (c *Client) RetryAlertDelivery(ctx context.Context, deliveryID string) (*AlertDelivery, error) {
	var out struct {
		Data AlertDelivery `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: alertsPath + "/alert-deliveries/" + seg(deliveryID) + "/retry"}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "alert delivery", Ref: deliveryID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}
