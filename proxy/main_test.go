package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// memQueue is an in-memory queue (the Redis list in production).
type memQueue struct {
	mu    sync.Mutex
	items [][]byte
	err   error // returned by every call when set
}

func (q *memQueue) Push(_ context.Context, item []byte) error {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.err != nil {
		return q.err
	}
	q.items = append(q.items, item)
	return nil
}

func (q *memQueue) PushFront(_ context.Context, item []byte) error {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.err != nil {
		return q.err
	}
	q.items = append([][]byte{item}, q.items...)
	return nil
}

func (q *memQueue) Pop(_ context.Context) ([]byte, error) {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.err != nil {
		return nil, q.err
	}
	if len(q.items) == 0 {
		return nil, errEmpty
	}
	item := q.items[0]
	q.items = q.items[1:]
	return item, nil
}

func (q *memQueue) Len(_ context.Context) (int64, error) {
	q.mu.Lock()
	defer q.mu.Unlock()
	if q.err != nil {
		return 0, q.err
	}
	return int64(len(q.items)), nil
}

// fail makes every later call return err (nil: work again).
func (q *memQueue) fail(err error) {
	q.mu.Lock()
	defer q.mu.Unlock()
	q.err = err
}

func (q *memQueue) decoded(t *testing.T) []queueItem {
	t.Helper()
	q.mu.Lock()
	defer q.mu.Unlock()
	out := make([]queueItem, len(q.items))
	for i, raw := range q.items {
		if err := json.Unmarshal(raw, &out[i]); err != nil {
			t.Fatalf("queued item %d: %v", i, err)
		}
	}
	return out
}

func env(vars map[string]string) func(string) string {
	return func(k string) string { return vars[k] }
}

func newTestProxy(q queue, dsns ...string) *proxy {
	vars := map[string]string{}
	keys := []string{"SENTRY_LARAVEL_DSN", "VITE_SENTRY_DSN"}
	for i, d := range dsns {
		vars[keys[i]] = d
	}
	return &proxy{
		queue:      q,
		targets:    targetsFromEnv(env(vars)),
		client:     &http.Client{Timeout: 2 * time.Second},
		retryDelay: 20 * time.Millisecond,
		idleDelay:  5 * time.Millisecond,
	}
}

func queued(t *testing.T, url string, body string, headers map[string]string) []byte {
	t.Helper()
	raw, err := json.Marshal(queueItem{URL: url, Headers: headers, Body: base64.StdEncoding.EncodeToString([]byte(body))})
	if err != nil {
		t.Fatal(err)
	}
	return raw
}

// ── Configuration ─────────────────────────────────────────────────────────────

func TestTargetsFromEnv(t *testing.T) {
	got := targetsFromEnv(env(map[string]string{
		"SENTRY_LARAVEL_DSN": "https://key@o1.ingest.sentry.io/42",
		"VITE_SENTRY_DSN":    "http://pub@sentry.local:9000/prefix/7",
	}))
	want := []target{
		{scheme: "https", host: "o1.ingest.sentry.io"},
		{scheme: "http", host: "sentry.local:9000", prefix: "/prefix"},
	}
	if len(got) != 2 || got[0] != want[0] || got[1] != want[1] {
		t.Fatalf("targets = %+v, want %+v", got, want)
	}

	// The same DSN host for API and UI is one target; invalid DSNs are ignored
	same := targetsFromEnv(env(map[string]string{
		"SENTRY_LARAVEL_DSN": "https://a@o1.ingest.sentry.io/1",
		"VITE_SENTRY_DSN":    "https://b@o1.ingest.sentry.io/2",
	}))
	if len(same) != 1 {
		t.Fatalf("same host: %d targets, want 1", len(same))
	}
	for _, bad := range []string{"not a url", "ftp://x@host/1", "https://key@host/", "https://key@host/abc", "https:///1"} {
		if ts := targetsFromEnv(env(map[string]string{"VITE_SENTRY_DSN": bad})); len(ts) != 0 {
			t.Errorf("DSN %q accepted: %+v", bad, ts)
		}
	}
}

func TestRetryDelayFromEnv(t *testing.T) {
	cases := map[string]time.Duration{
		"":      defaultRetryDelay,
		"250":   250 * time.Millisecond,
		"0":     defaultRetryDelay, // would retry in a busy loop
		"-5":    defaultRetryDelay,
		"5s":    defaultRetryDelay,
		" 100 ": 100 * time.Millisecond,
	}
	for in, want := range cases {
		if got := retryDelayFromEnv(env(map[string]string{"RETRY_INTERVAL": in})); got != want {
			t.Errorf("RETRY_INTERVAL=%q: %v, want %v", in, got, want)
		}
	}
}

// ── Ingest URL ────────────────────────────────────────────────────────────────

func TestResolveIngestURL(t *testing.T) {
	p := newTestProxy(&memQueue{}, "https://key@o1.ingest.sentry.io/42", "http://pub@sentry.local:9000/prefix/7")
	envelope := func(dsn string) []byte { return []byte(`{"dsn":"` + dsn + `","sent_at":"x"}` + "\n{}\n") }

	cases := []struct {
		name, path string
		body       []byte
		want, err  string
	}{
		{"php sdk path", "/api/42/envelope/", nil, "https://o1.ingest.sentry.io/api/42/envelope/", ""},
		{"js sdk, host of the UI DSN", "/", envelope("http://pub@sentry.local:9000/prefix/7"), "http://sentry.local:9000/prefix/api/7/envelope/", ""},
		// A host the proxy is not configured for is never contacted
		{"js sdk, other host", "/", envelope("https://k@attacker.example/99"), "https://o1.ingest.sentry.io/api/99/envelope/", ""},
		{"no dsn header", "/", []byte("{}\n{}"), "", "missing dsn"},
		{"not json", "/", []byte("garbage"), "", "missing dsn"},
		{"dsn without project id", "/", envelope("https://k@o1.ingest.sentry.io/"), "", "no project id"},
		{"path in the project id", "/", envelope("https://k@o1.ingest.sentry.io/1/../../admin"), "", "no project id"},
		{"dsn without scheme", "/", envelope("o1.ingest.sentry.io/42"), "", "http(s) host"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got, err := p.resolveIngestURL(c.path, c.body)
			if c.err != "" {
				if err == nil || !strings.Contains(err.Error(), c.err) {
					t.Fatalf("err = %v, want %q", err, c.err)
				}
				return
			}
			if err != nil || got != c.want {
				t.Fatalf("got %q, %v; want %q", got, err, c.want)
			}
		})
	}

	if _, err := newTestProxy(&memQueue{}).resolveIngestURL("/api/42/envelope/", nil); !errors.Is(err, errNoDSN) {
		t.Fatalf("without a DSN: %v, want errNoDSN", err)
	}
}

// ── HTTP handlers ─────────────────────────────────────────────────────────────

func TestEnvelopeIsQueued(t *testing.T) {
	q := &memQueue{}
	srv := httptest.NewServer(newTestProxy(q, "https://key@o1.ingest.sentry.io/42").routes())
	defer srv.Close()

	body := `{"dsn":"https://key@o1.ingest.sentry.io/42"}` + "\n" + `{"type":"event"}` + "\n{}"
	req, _ := http.NewRequest(http.MethodPost, srv.URL+"/", strings.NewReader(body))
	req.Header.Set("X-Sentry-Auth", "Sentry sentry_key=key")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusAccepted {
		t.Fatalf("status %d, want 202", resp.StatusCode)
	}
	if resp.Header.Get("Access-Control-Allow-Origin") != "*" {
		t.Error("no CORS header on the envelope response")
	}

	items := q.decoded(t)
	if len(items) != 1 {
		t.Fatalf("%d items queued, want 1", len(items))
	}
	it := items[0]
	decoded, _ := base64.StdEncoding.DecodeString(it.Body)
	if it.URL != "https://o1.ingest.sentry.io/api/42/envelope/" || string(decoded) != body {
		t.Errorf("queued %+v (body %q)", it, decoded)
	}
	if it.Headers["content-type"] != "application/x-sentry-envelope" || it.Headers["x-sentry-auth"] != "Sentry sentry_key=key" {
		t.Errorf("headers %v", it.Headers)
	}
}

func TestEnvelopeRefusals(t *testing.T) {
	big := `{"dsn":"https://key@o1.ingest.sentry.io/42"}` + "\n" + strings.Repeat("x", maxEnvelopeBytes)
	cases := []struct {
		name string
		dsns []string
		body string
		want int
	}{
		// Cut off at the limit, it would reach Sentry as a broken envelope
		{"too large", []string{"https://key@o1.ingest.sentry.io/42"}, big, http.StatusRequestEntityTooLarge},
		{"invalid dsn", []string{"https://key@o1.ingest.sentry.io/42"}, `{"dsn":"javascript:alert(1)"}`, http.StatusBadRequest},
		// Nowhere to forward to: refused instead of filling Redis
		{"no dsn configured", nil, `{"dsn":"https://key@o1.ingest.sentry.io/42"}`, http.StatusServiceUnavailable},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			q := &memQueue{}
			srv := httptest.NewServer(newTestProxy(q, c.dsns...).routes())
			defer srv.Close()
			resp, err := http.Post(srv.URL+"/", "text/plain", strings.NewReader(c.body))
			if err != nil {
				t.Fatal(err)
			}
			resp.Body.Close()
			if resp.StatusCode != c.want {
				t.Fatalf("status %d, want %d", resp.StatusCode, c.want)
			}
			if n, _ := q.Len(context.Background()); n != 0 {
				t.Fatalf("%d items queued, want none", n)
			}
		})
	}
}

func TestEnvelopeQueueDown(t *testing.T) {
	srv := httptest.NewServer(newTestProxy(&memQueue{err: errors.New("redis down")}, "https://key@o1.ingest.sentry.io/42").routes())
	defer srv.Close()
	resp, err := http.Post(srv.URL+"/api/42/envelope/", "application/x-sentry-envelope", strings.NewReader("{}"))
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusInternalServerError {
		t.Fatalf("status %d, want 500", resp.StatusCode)
	}
}

func TestHealth(t *testing.T) {
	q := &memQueue{items: [][]byte{[]byte("a"), []byte("b")}}
	srv := httptest.NewServer(newTestProxy(q).routes())
	defer srv.Close()

	resp, err := http.Get(srv.URL + "/health")
	if err != nil {
		t.Fatal(err)
	}
	var got map[string]any
	_ = json.NewDecoder(resp.Body).Decode(&got)
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK || got["ok"] != true || got["queueLength"] != float64(2) {
		t.Fatalf("health %d %v", resp.StatusCode, got)
	}

	// Redis unreachable: not healthy (it answered ok with length 0 before)
	q.fail(errors.New("redis down"))
	resp, err = http.Get(srv.URL + "/health")
	if err != nil {
		t.Fatal(err)
	}
	_ = json.NewDecoder(resp.Body).Decode(&got)
	resp.Body.Close()
	if resp.StatusCode != http.StatusServiceUnavailable || got["ok"] != false {
		t.Fatalf("health with Redis down %d %v", resp.StatusCode, got)
	}
}

func TestPreflight(t *testing.T) {
	srv := httptest.NewServer(newTestProxy(&memQueue{}).routes())
	defer srv.Close()
	req, _ := http.NewRequest(http.MethodOptions, srv.URL+"/api/42/envelope/", nil)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if resp.StatusCode != http.StatusNoContent || resp.Header.Get("Access-Control-Allow-Headers") != "*" {
		t.Fatalf("preflight %d %v", resp.StatusCode, resp.Header)
	}
}

// ── Drain ─────────────────────────────────────────────────────────────────────

type sentry struct {
	srv      *httptest.Server
	statuses []int // answered in order, then 200
	mu       sync.Mutex
	posts    []string
}

func newSentry(t *testing.T, statuses ...int) *sentry {
	s := &sentry{statuses: statuses}
	s.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodHead {
			return
		}
		b, _ := io.ReadAll(r.Body)
		s.mu.Lock()
		s.posts = append(s.posts, r.URL.Path+" "+r.Header.Get("X-Sentry-Auth")+" "+string(b))
		status := http.StatusOK
		if len(s.statuses) > 0 {
			status, s.statuses = s.statuses[0], s.statuses[1:]
		}
		s.mu.Unlock()
		w.WriteHeader(status)
	}))
	t.Cleanup(s.srv.Close)
	return s
}

func (s *sentry) received() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.posts...)
}

func TestDrainForwards(t *testing.T) {
	s := newSentry(t)
	q := &memQueue{}
	p := newTestProxy(q)
	_ = q.Push(context.Background(), queued(t, s.srv.URL+"/api/42/envelope/", "envelope-1", map[string]string{"x-sentry-auth": "Sentry k"}))

	if !p.drainOnce(context.Background()) {
		t.Fatal("nothing forwarded")
	}
	if got := s.received(); len(got) != 1 || got[0] != "/api/42/envelope/ Sentry k envelope-1" {
		t.Fatalf("sentry received %q", got)
	}
	if p.drainOnce(context.Background()) {
		t.Fatal("empty queue reported an item")
	}
}

func TestDrainDropsWhatSentryRefuses(t *testing.T) {
	for _, status := range []int{http.StatusBadRequest, http.StatusRequestEntityTooLarge, http.StatusTooManyRequests} {
		s := newSentry(t, status)
		q := &memQueue{}
		_ = q.Push(context.Background(), queued(t, s.srv.URL+"/api/1/envelope/", "e", nil))
		newTestProxy(q).drainOnce(context.Background())
		if n, _ := q.Len(context.Background()); n != 0 || len(s.received()) != 1 {
			t.Errorf("HTTP %d: queue %d, posts %d; want dropped after one attempt", status, n, len(s.received()))
		}
	}
}

func TestDrainRetriesServerErrorsInOrder(t *testing.T) {
	s := newSentry(t, http.StatusBadGateway)
	q := &memQueue{}
	p := newTestProxy(q)
	for _, b := range []string{"first", "second"} {
		_ = q.Push(context.Background(), queued(t, s.srv.URL+"/api/1/envelope/", b, nil))
	}

	start := time.Now()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	for len(s.received()) < 3 && ctx.Err() == nil {
		p.drainOnce(ctx)
	}

	got := s.received()
	want := []string{"/api/1/envelope/  first", "/api/1/envelope/  first", "/api/1/envelope/  second"}
	if strings.Join(got, "|") != strings.Join(want, "|") {
		t.Fatalf("sentry received %q, want %q (a failed envelope is retried before the next)", got, want)
	}
	// It used to retry at once: Sentry answering 5xx was hammered in a busy loop
	if elapsed := time.Since(start); elapsed < p.retryDelay {
		t.Fatalf("retried after %v, want a pause of %v", elapsed, p.retryDelay)
	}
}

func TestDrainKeepsEnvelopesWhileSentryIsUnreachable(t *testing.T) {
	s := newSentry(t)
	url := s.srv.URL + "/api/1/envelope/"
	s.srv.Close() // connection refused

	q := &memQueue{}
	p := newTestProxy(q)
	_ = q.Push(context.Background(), queued(t, url, "kept", nil))

	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Millisecond)
	defer cancel()
	p.drainOnce(ctx) // fails, waits for Sentry until ctx ends

	items := q.decoded(t)
	if len(items) != 1 || items[0].URL != url {
		t.Fatalf("queue after a failed forward: %+v, want the envelope back at the head", items)
	}
}

func TestDrainDropsBrokenItems(t *testing.T) {
	q := &memQueue{}
	p := newTestProxy(q)
	for _, raw := range [][]byte{
		[]byte("not json"),
		[]byte(`{"url":"https://o1.ingest.sentry.io/api/1/envelope/","body":"%%%"}`),
		// An URL no request can be built for: dropped, not retried for ever (the probe of its
		// host used to dereference a nil request and crash the proxy on every start)
		queued(t, "://no-scheme/api/1/envelope/", "x", nil),
	} {
		_ = q.Push(context.Background(), raw)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	for i := 0; i < 3; i++ {
		if !p.drainOnce(ctx) {
			t.Fatalf("item %d not taken", i)
		}
	}
	if n, _ := q.Len(ctx); n != 0 {
		t.Fatalf("%d broken items left in the queue", n)
	}
}

func TestDrainStopsWithItsContext(t *testing.T) {
	s := newSentry(t)
	q := &memQueue{}
	p := newTestProxy(q)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { p.drain(ctx); close(done) }()

	_ = q.Push(context.Background(), queued(t, s.srv.URL+"/api/1/envelope/", "late", nil))
	deadline := time.Now().Add(2 * time.Second)
	for len(s.received()) == 0 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	if len(s.received()) != 1 {
		t.Fatal("the worker did not pick up an envelope queued while it was idle")
	}

	cancel()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("drain did not stop after its context was cancelled")
	}
}
