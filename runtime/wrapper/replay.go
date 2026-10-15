package main

// Failed-request capture for RunR's request replay: when RunR turned it on for the project
// (RUNR_REPLAY_URL + RUNR_REPLAY_TOKEN), every invocation answered with a 5xx or a timeout is
// sent to RunR — method, path, query, headers without credentials, the body up to a size limit
// and the response. Redaction happens here, before anything leaves the pod; RunR applies the
// same rules again when it stores the capture.

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/url"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"
)

const (
	// replayHeader marks a request RunR replays: it is never captured again.
	replayHeader = "X-Runr-Replay"

	defaultReplayMaxBody   = 64 << 10
	defaultReplayPerMinute = 60
	replayQueueSize        = 64
	replaySendTimeout      = 5 * time.Second
	redactedValue          = "REDACTED"
)

// defaultRedactedHeaders are never captured, whatever the project configured.
var defaultRedactedHeaders = []string{
	"authorization", "proxy-authorization", "cookie", "set-cookie",
	"x-api-key", "x-auth-token", "x-csrf-token", "x-xsrf-token",
	"x-amz-security-token", "x-goog-iap-jwt-assertion",
}

// hopByHopHeaders describe the connection, not the request.
var hopByHopHeaders = []string{
	"connection", "keep-alive", "proxy-connection", "te", "trailer", "transfer-encoding", "upgrade",
}

// defaultRedactedQuery are query parameters whose values are replaced by REDACTED.
var defaultRedactedQuery = []string{"access_token", "api_key", "apikey", "password", "secret", "token"}

// secretName: names RunR's SecretRedactor treats as secrets (anchored at the end, "-" read as
// "_"): x-api-key, x-auth-token, client_secret … are never captured, whatever is configured.
var secretName = regexp.MustCompile(`(?i)(password|passwd|secret|token|kubeconfig|private_?key|access_?key|secret_?key|api_?key|credentials?|authorization|cookie)$`)

func isSecretName(name string) bool {
	return secretName.MatchString(strings.ReplaceAll(name, "-", "_"))
}

// replayConfig is the capture configuration, read from the environment (loadReplayConfig).
type replayConfig struct {
	URL         string
	Token       string
	MaxBody     int
	PerMinute   int
	Redact      map[string]bool // lower-case header names
	RedactQuery map[string]bool // lower-case query parameter names
}

// loadReplayConfig: ok is false (capture off) without RUNR_REPLAY_URL or RUNR_REPLAY_TOKEN.
func loadReplayConfig(getenv func(string) string) (replayConfig, bool) {
	cfg := replayConfig{
		URL:         strings.TrimSpace(getenv("RUNR_REPLAY_URL")),
		Token:       strings.TrimSpace(getenv("RUNR_REPLAY_TOKEN")),
		MaxBody:     defaultReplayMaxBody,
		PerMinute:   defaultReplayPerMinute,
		Redact:      map[string]bool{},
		RedactQuery: map[string]bool{},
	}
	if cfg.URL == "" || cfg.Token == "" {
		return cfg, false
	}

	if n, err := strconv.Atoi(strings.TrimSpace(getenv("RUNR_REPLAY_MAX_BODY"))); err == nil && n >= 0 {
		cfg.MaxBody = n
	}
	if n, err := strconv.Atoi(strings.TrimSpace(getenv("RUNR_REPLAY_PER_MINUTE"))); err == nil && n > 0 {
		cfg.PerMinute = n
	}

	for _, h := range append(append([]string{}, defaultRedactedHeaders...), hopByHopHeaders...) {
		cfg.Redact[h] = true
	}
	for _, h := range strings.Split(getenv("RUNR_REPLAY_REDACT_HEADERS"), ",") {
		if h = strings.ToLower(strings.TrimSpace(h)); h != "" {
			cfg.Redact[h] = true
		}
	}
	for _, q := range defaultRedactedQuery {
		cfg.RedactQuery[q] = true
	}
	for _, q := range strings.Split(getenv("RUNR_REPLAY_REDACT_QUERY"), ",") {
		if q = strings.ToLower(strings.TrimSpace(q)); q != "" {
			cfg.RedactQuery[q] = true
		}
	}

	return cfg, true
}

// capture is one failed request as RunR stores it (POST RUNR_REPLAY_URL).
type capture struct {
	CapturedAt      string            `json:"captured_at"`
	Trigger         string            `json:"trigger"`
	RequestID       string            `json:"request_id,omitempty"`
	TraceID         string            `json:"trace_id,omitempty"`
	Revision        string            `json:"revision,omitempty"`
	Method          string            `json:"method"`
	Path            string            `json:"path"`
	Query           string            `json:"query"`
	Headers         map[string]string `json:"headers"`
	RedactedHeaders []string          `json:"redacted_headers"`
	Body            string            `json:"body"`
	BodyBase64      bool              `json:"body_base64"`
	BodySize        int               `json:"body_size"`
	BodyTruncated   bool              `json:"body_truncated"`
	Status          int               `json:"status"`
	TimedOut        bool              `json:"timed_out"`
	DurationMs      float64           `json:"duration_ms"`

	ResponseHeaders   map[string]string `json:"response_headers"`
	ResponseBody      string            `json:"response_body"`
	ResponseBase64    bool              `json:"response_body_base64"`
	ResponseTruncated bool              `json:"response_body_truncated"`
}

// buildCapture applies the redaction rules and the body limit to one failed invocation.
func buildCapture(cfg replayConfig, req request, status int, respHeader http.Header, respBody []byte, timedOut bool, dur time.Duration, traceID, revision string, at time.Time) *capture {
	headers, redacted := redactHeaders(req.Header, cfg.Redact)
	respHeaders, _ := redactHeaders(respHeader, cfg.Redact)

	c := &capture{
		CapturedAt:      at.UTC().Format(time.RFC3339Nano),
		Trigger:         req.Trigger,
		RequestID:       req.ID,
		TraceID:         traceID,
		Revision:        revision,
		Method:          req.Method,
		Path:            req.Path,
		Query:           redactQuery(req.RawQuery, cfg.RedactQuery),
		Headers:         headers,
		RedactedHeaders: redacted,
		BodySize:        len(req.Body),
		Status:          status,
		TimedOut:        timedOut,
		DurationMs:      float64(dur.Microseconds()) / 1000,
		ResponseHeaders: respHeaders,
	}
	c.Body, c.BodyBase64, c.BodyTruncated = limitedBody(req.Body, cfg.MaxBody)
	c.ResponseBody, c.ResponseBase64, c.ResponseTruncated = limitedBody(respBody, cfg.MaxBody)
	return c
}

// redactHeaders flattens headers (lower-case names, repeated values joined) without the redacted
// ones; the second result lists the names that were left out.
func redactHeaders(h http.Header, redact map[string]bool) (map[string]string, []string) {
	out := map[string]string{}
	removed := []string{}
	for k, vs := range h {
		name := strings.ToLower(k)
		if redact[name] || isSecretName(name) {
			removed = append(removed, name)
			continue
		}
		out[name] = strings.Join(vs, ", ")
	}
	sort.Strings(removed)
	return out, removed
}

// redactQuery replaces the values of redacted parameters, keeping the order of the query.
func redactQuery(raw string, redact map[string]bool) string {
	if raw == "" {
		return ""
	}
	parts := strings.Split(raw, "&")
	for i, p := range parts {
		k, _, hasValue := strings.Cut(p, "=")
		name := k
		if dk, err := url.QueryUnescape(k); err == nil {
			name = dk
		}
		if hasValue && (redact[strings.ToLower(name)] || isSecretName(name)) {
			parts[i] = k + "=" + redactedValue
		}
	}
	return strings.Join(parts, "&")
}

// limitedBody: the body (base64 when it is not UTF-8) cut to max bytes.
func limitedBody(b []byte, max int) (string, bool, bool) {
	truncated := false
	if len(b) > max {
		b = b[:max]
		truncated = true
	}
	if utf8.Valid(b) {
		return string(b), false, truncated
	}
	return base64.StdEncoding.EncodeToString(b), true, truncated
}

// ── Sender ──────────────────────────────────────────────────────────────────────────────────

// replayer sends captures in the background: a bounded queue, at most PerMinute captures a
// minute (a failing function under load does not flood RunR), dropped when either is full.
type replayer struct {
	cfg    replayConfig
	client *http.Client

	mu     sync.Mutex
	tokens float64
	last   time.Time

	queue  chan []byte
	done   chan struct{}
	closed bool
}

func newReplayer(cfg replayConfig) *replayer {
	r := &replayer{
		cfg:    cfg,
		client: &http.Client{Timeout: replaySendTimeout},
		tokens: float64(cfg.PerMinute),
		last:   time.Now(),
		queue:  make(chan []byte, replayQueueSize),
		done:   make(chan struct{}),
	}
	go r.loop()
	return r
}

// allow takes one token of the per-minute budget.
func (r *replayer) allow(now time.Time) bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.tokens = min(float64(r.cfg.PerMinute), r.tokens+now.Sub(r.last).Minutes()*float64(r.cfg.PerMinute))
	r.last = now
	if r.tokens < 1 {
		return false
	}
	r.tokens--
	return true
}

func (r *replayer) capture(c *capture) {
	if !r.allow(time.Now()) {
		logger.Printf("replay: capture budget (%d/min) used up, request %s not captured", r.cfg.PerMinute, c.RequestID)
		return
	}
	body, err := json.Marshal(c)
	if err != nil {
		return
	}

	r.mu.Lock()
	defer r.mu.Unlock()
	if r.closed {
		return
	}
	select {
	case r.queue <- body:
	default:
		logger.Printf("replay: capture queue full, request %s not captured", c.RequestID)
	}
}

func (r *replayer) loop() {
	defer close(r.done)
	for body := range r.queue {
		if err := r.send(body); err != nil {
			logger.Printf("replay: capture not delivered: %v", err)
		}
	}
}

func (r *replayer) send(body []byte) error {
	req, err := http.NewRequest(http.MethodPost, r.cfg.URL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+r.cfg.Token)
	req.Header.Set("User-Agent", "runr-wrapper/"+version)

	resp, err := r.client.Do(req)
	if err != nil {
		return err
	}
	resp.Body.Close()
	if resp.StatusCode >= 300 {
		return &httpStatusError{status: resp.StatusCode}
	}
	return nil
}

// shutdown delivers the queued captures, waiting at most timeout.
func (r *replayer) shutdown(timeout time.Duration) {
	r.mu.Lock()
	if !r.closed {
		r.closed = true
		close(r.queue)
	}
	r.mu.Unlock()

	select {
	case <-r.done:
	case <-time.After(timeout):
	}
}

type httpStatusError struct{ status int }

func (e *httpStatusError) Error() string { return "RunR answered " + strconv.Itoa(e.status) }
