package runr

import (
	"context"
	"net/http"
	"net/url"
	"strconv"
	"time"
)

// Load tests (routes/observability.php, docs/load-test.md): a k6 Job in the project's region
// sends requests to the project's own URL; the results are latency percentiles, error rate,
// requests per second, the pods the project scaled to and its cold starts.

// Load test states.
const (
	LoadTestPending   = "pending"
	LoadTestRunning   = "running"
	LoadTestCompleted = "completed"
	LoadTestFailed    = "failed"
	LoadTestCancelled = "cancelled"
)

// LoadTestLatency is the latency of a run in milliseconds (nil: k6 measured none).
type LoadTestLatency struct {
	Avg *float64 `json:"avg"`
	Min *float64 `json:"min"`
	P50 *float64 `json:"p50"`
	P95 *float64 `json:"p95"`
	P99 *float64 `json:"p99"`
	Max *float64 `json:"max"`
}

// LoadTestResults are the measurements of a completed run.
type LoadTestResults struct {
	Requests       int             `json:"requests"`
	FailedRequests int             `json:"failed_requests"`
	RPS            float64         `json:"rps"`
	ErrorRate      float64         `json:"error_rate"`
	LatencyMs      LoadTestLatency `json:"latency_ms"`
	DataReceived   int64           `json:"data_received"`
	DataSent       int64           `json:"data_sent"`
	PodsPeak       *int            `json:"pods_peak"`
	PodsSource     string          `json:"pods_source,omitempty"`
	ColdStarts     *int            `json:"cold_starts"`
}

// LoadTest is one load test of a project.
type LoadTest struct {
	ID              string           `json:"id"`
	ProjectID       string           `json:"project_id"`
	DeploymentID    string           `json:"deployment_id,omitempty"`
	Status          string           `json:"status"`
	Method          string           `json:"method"`
	Host            string           `json:"host"`
	Path            string           `json:"path"`
	URL             string           `json:"url"`
	HasBody         bool             `json:"has_body"`
	Body            string           `json:"body,omitempty"`
	ContentType     string           `json:"content_type,omitempty"`
	Users           int              `json:"users"`
	DurationSeconds int              `json:"duration_seconds"`
	Results         *LoadTestResults `json:"results,omitempty"`
	Error           string           `json:"error,omitempty"`
	StartedAt       string           `json:"started_at,omitempty"`
	FinishedAt      string           `json:"finished_at,omitempty"`
	CreatedAt       string           `json:"created_at,omitempty"`
}

// Done reports whether the test has ended (completed, failed or cancelled).
func (t *LoadTest) Done() bool {
	return t.Status == LoadTestCompleted || t.Status == LoadTestFailed || t.Status == LoadTestCancelled
}

// LoadTestOptions is what a new test can choose, and the organization's count of the day.
type LoadTestOptions struct {
	Enabled      bool     `json:"enabled"`
	Users        []int    `json:"users"`
	Durations    []int    `json:"durations"`
	Methods      []string `json:"methods"`
	Targets      []string `json:"targets"`
	DailyLimit   int      `json:"daily_limit"`
	UsedToday    int      `json:"used_today"`
	MaxBodyBytes int      `json:"max_body_bytes"`
	Metered      bool     `json:"metered"`
}

// LoadTestInput is the body of POST …/load-tests. Empty strings are not sent (the API's
// defaults apply: the function host, /, GET).
type LoadTestInput struct {
	Host            string `json:"host,omitempty"`
	Path            string `json:"path,omitempty"`
	Method          string `json:"method,omitempty"`
	Body            string `json:"body,omitempty"`
	ContentType     string `json:"content_type,omitempty"`
	Users           int    `json:"users"`
	DurationSeconds int    `json:"duration_seconds"`
}

func loadTestPath(projectID string) string { return "/projects/" + projectID + "/load-tests" }

// ListLoadTests returns a project's latest tests, newest first (limit 0: the API's default of
// 10), with what a new test can choose.
func (c *Client) ListLoadTests(ctx context.Context, projectID string, limit int) ([]LoadTest, *LoadTestOptions, error) {
	q := url.Values{}
	if limit > 0 {
		q.Set("limit", strconv.Itoa(limit))
	}
	var out struct {
		Data []LoadTest      `json:"data"`
		Meta LoadTestOptions `json:"meta"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: loadTestPath(projectID), query: q}, &out); err != nil {
		return nil, nil, err
	}
	if out.Data == nil {
		out.Data = []LoadTest{}
	}
	return out.Data, &out.Meta, nil
}

// GetLoadTest returns one test of a project.
func (c *Client) GetLoadTest(ctx context.Context, projectID, id string) (*LoadTest, error) {
	var out struct {
		Data LoadTest `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: loadTestPath(projectID) + "/" + seg(id)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "load test", Ref: id}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// StartLoadTest starts a test (202): its k6 Job starts in the region in the background.
func (c *Client) StartLoadTest(ctx context.Context, projectID string, in LoadTestInput) (*LoadTest, error) {
	var out struct {
		Data LoadTest `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: loadTestPath(projectID), body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// CancelLoadTest stops a pending or running test (a finished one is returned as it is).
func (c *Client) CancelLoadTest(ctx context.Context, projectID, id string) (*LoadTest, error) {
	var out struct {
		Data LoadTest `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: loadTestPath(projectID) + "/" + seg(id) + "/cancel"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// WaitLoadTest polls a test until it has ended. A failed or cancelled test is returned with a
// *FailedError.
func (c *Client) WaitLoadTest(ctx context.Context, projectID string, t *LoadTest, opts WaitOptions) (*LoadTest, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	cur := t
	for {
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		switch cur.Status {
		case LoadTestCompleted:
			return cur, nil
		case LoadTestFailed, LoadTestCancelled:
			return cur, &FailedError{What: "load test", Ref: cur.ID, Status: cur.Status, Message: cur.Error}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: "load test " + cur.ID, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
		next, err := c.GetLoadTest(ctx, projectID, cur.ID)
		if err != nil {
			if ctx.Err() != nil {
				return cur, &TimeoutError{What: "load test " + cur.ID, Timeout: opts.Timeout, Last: last}
			}
			return cur, err
		}
		cur = next
	}
}
