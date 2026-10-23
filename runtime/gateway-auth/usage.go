package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"sync"
	"time"
)

// Usage: every decision is counted per hour (UTC), organization, gateway, route, consumer, key
// and JWT subject, and reported to RunR every FLUSH_SECONDS (POST
// /api/internal/gateway-auth/{region}/usage, App\Services\ApiProduct\ApiUsageIngest). A report has
// a batch id; one that failed is sent again with the same id (RunR applies a batch once) before
// anything newer, and calls counted meanwhile wait in the next report.
//
// The metering proxy (proxy.go) adds what the function answered to the same rows: the status
// class, the latency until the answer started (a histogram, so RunR can merge pods, reports and
// hours and still read percentiles), the bytes of the request and of the answer, and the calls
// that got no answer at all (upstream errors).

const (
	CounterRequests      = "requests"
	CounterUnauthorized  = "unauthorized"
	CounterForbidden     = "forbidden"
	CounterRateLimited   = "rate_limited"
	CounterQuotaExceeded = "quota_exceeded"
)

// LatencyBoundsMS are the upper bounds (milliseconds, inclusive) of the latency buckets; one more
// bucket counts what is above the last. App\Services\ApiProduct\ApiLatency::BOUNDS_MS is the same
// list: change both together.
var LatencyBoundsMS = []float64{2, 5, 10, 20, 30, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 2000, 3000, 5000, 10000, 30000}

// latencyBucket is the bucket of a latency.
func latencyBucket(d time.Duration) int {
	ms := float64(d) / float64(time.Millisecond)
	for i, bound := range LatencyBoundsMS {
		if ms <= bound {
			return i
		}
	}
	return len(LatencyBoundsMS)
}

// Response is what the metering proxy saw of one call.
type Response struct {
	// Status of the function's answer; 0: no answer (connection refused, timeout) — the proxy
	// answered 502 / 504 itself
	Status   int
	Latency  time.Duration // until the answer's headers arrived (an upgrade: its handshake)
	BytesIn  int64         // request body (an upgraded connection: Recorder.AddBytes when it closes)
	BytesOut int64         // answer body
}

// UsageKey identifies one row of api_usage_hours.
type UsageKey struct {
	Scope      string
	GatewayID  string
	RouteID    string
	ConsumerID string
	KeyID      string
	Subject    string
	Hour       string
}

// UsageEntry is one row of a report.
type UsageEntry struct {
	Scope         string  `json:"scope"`
	GatewayID     string  `json:"gateway_id"`
	RouteID       *string `json:"route_id"`
	ConsumerID    *string `json:"consumer_id"`
	KeyID         *string `json:"key_id"`
	Subject       *string `json:"subject"`
	Hour          string  `json:"hour"`
	Requests      int64   `json:"requests"`
	Unauthorized  int64   `json:"unauthorized"`
	Forbidden     int64   `json:"forbidden"`
	RateLimited   int64   `json:"rate_limited"`
	QuotaExceeded int64   `json:"quota_exceeded"`

	// what the function answered (metering proxy); 1xx (a WebSocket's 101) counts as 2xx
	Status2xx      int64 `json:"status_2xx"`
	Status3xx      int64 `json:"status_3xx"`
	Status4xx      int64 `json:"status_4xx"`
	Status5xx      int64 `json:"status_5xx"`
	UpstreamErrors int64 `json:"upstream_errors"`
	// sum of the answers' latencies in microseconds, and their histogram (LatencyBoundsMS)
	LatencyUSSum   int64   `json:"latency_us_sum"`
	LatencyBuckets []int64 `json:"latency_buckets,omitempty"`
	BytesIn        int64   `json:"bytes_in"`
	BytesOut       int64   `json:"bytes_out"`
}

type usageReport struct {
	Batch   string       `json:"batch"`
	Entries []UsageEntry `json:"entries"`
}

// Recorder counts calls and reports them.
type Recorder struct {
	mu      sync.Mutex
	pending map[UsageKey]*UsageEntry
	retry   *usageReport
	seq     uint64
	prefix  string

	URL    string
	Token  string
	Client *http.Client
}

// NewRecorder: batch ids start with pod + boot time, unique across restarts.
func NewRecorder(url, token, pod string, client *http.Client) *Recorder {
	return &Recorder{
		pending: map[UsageKey]*UsageEntry{},
		prefix:  fmt.Sprintf("%s-%d", pod, time.Now().UnixNano()),
		URL:     url,
		Token:   token,
		Client:  client,
	}
}

func optional(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

// entry is the pending row of k (call with r.mu held).
func (r *Recorder) entry(k UsageKey) *UsageEntry {
	e := r.pending[k]
	if e == nil {
		e = &UsageEntry{
			Scope: k.Scope, GatewayID: k.GatewayID, Hour: k.Hour,
			RouteID: optional(k.RouteID), ConsumerID: optional(k.ConsumerID), KeyID: optional(k.KeyID), Subject: optional(k.Subject),
		}
		r.pending[k] = e
	}
	return e
}

// Observe adds what the function answered to a call (the metering proxy).
func (r *Recorder) Observe(k UsageKey, resp Response) {
	r.mu.Lock()
	defer r.mu.Unlock()
	e := r.entry(k)
	e.BytesIn += max(0, resp.BytesIn)
	e.BytesOut += max(0, resp.BytesOut)
	switch {
	case resp.Status <= 0:
		e.UpstreamErrors++
		return
	case resp.Status < 300:
		e.Status2xx++
	case resp.Status < 400:
		e.Status3xx++
	case resp.Status < 500:
		e.Status4xx++
	default:
		e.Status5xx++
	}
	if e.LatencyBuckets == nil {
		e.LatencyBuckets = make([]int64, len(LatencyBoundsMS)+1)
	}
	latency := max(0, resp.Latency)
	e.LatencyBuckets[latencyBucket(latency)]++
	e.LatencyUSSum += latency.Microseconds()
}

// AddBytes adds the bytes of an upgraded connection (a WebSocket) once it has closed: in from the
// caller, out from the function. Its answer (the 101) was observed when the tunnel opened.
func (r *Recorder) AddBytes(k UsageKey, in, out int64) {
	if in <= 0 && out <= 0 {
		return
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	e := r.entry(k)
	e.BytesIn += max(0, in)
	e.BytesOut += max(0, out)
}

// Add counts one call under counter.
func (r *Recorder) Add(k UsageKey, counter string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	e := r.entry(k)
	switch counter {
	case CounterRequests:
		e.Requests++
	case CounterUnauthorized:
		e.Unauthorized++
	case CounterForbidden:
		e.Forbidden++
	case CounterRateLimited:
		e.RateLimited++
	case CounterQuotaExceeded:
		e.QuotaExceeded++
	}
}

// Flush sends the report that failed last time (same batch id), then what was counted since.
func (r *Recorder) Flush(ctx context.Context) error {
	// at most the retried report and one new one: under steady traffic Flush still returns
	for range 2 {
		r.mu.Lock()
		report := r.retry
		retried := report != nil
		if report == nil {
			if len(r.pending) == 0 {
				r.mu.Unlock()
				return nil
			}
			r.seq++
			report = &usageReport{Batch: fmt.Sprintf("%s-%d", r.prefix, r.seq)}
			for _, e := range r.pending {
				report.Entries = append(report.Entries, *e)
			}
			r.pending = map[UsageKey]*UsageEntry{}
			r.retry = report
		}
		r.mu.Unlock()

		if err := r.send(ctx, report); err != nil {
			return err
		}
		r.mu.Lock()
		if r.retry == report {
			r.retry = nil
		}
		r.mu.Unlock()
		if !retried {
			return nil
		}
	}
	return nil
}

// Pending reports how many rows wait (tests, metrics).
func (r *Recorder) Pending() int {
	r.mu.Lock()
	defer r.mu.Unlock()
	n := len(r.pending)
	if r.retry != nil {
		n += len(r.retry.Entries)
	}
	return n
}

func (r *Recorder) send(ctx context.Context, report *usageReport) error {
	body, err := json.Marshal(report)
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, r.URL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+r.Token)
	resp, err := r.Client.Do(req)
	if err != nil {
		return fmt.Errorf("usage report: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("usage report answered %d", resp.StatusCode)
	}
	return nil
}

// Run flushes every interval until ctx ends, then once more.
func (r *Recorder) Run(ctx context.Context, interval time.Duration, onError func(error)) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			final, cancel := context.WithTimeout(context.Background(), 10*time.Second)
			if err := r.Flush(final); err != nil && onError != nil {
				onError(err)
			}
			cancel()
			return
		case <-ticker.C:
			if err := r.Flush(ctx); err != nil && onError != nil {
				onError(err)
			}
		}
	}
}
