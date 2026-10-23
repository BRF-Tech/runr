package gateway

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// ── post: CloudEvent request and status handling ─────────────────────────────────

func TestDeliverPostsCloudEvent(t *testing.T) {
	var got *http.Request
	var body []byte
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Clone(context.Background())
		body, _ = io.ReadAll(r.Body)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer srv.Close()

	tr := testGateway(t, srv.URL, nil, nil).triggers[0]
	ev := source.Event{
		ID:              "1700000000000-0",
		Type:            "dev.runr.queue.message",
		Source:          "/redisstream/orders/billing",
		Subject:         "order-17",
		DataContentType: "application/json",
		Time:            time.Date(2023, 11, 14, 22, 13, 20, 0, time.UTC),
		Data:            []byte(`{"order":"17","qty":"2"}`),
	}
	if err := tr.post(context.Background(), ev, 3); err != nil {
		t.Fatalf("post: %v", err)
	}

	if got.Method != http.MethodPost {
		t.Errorf("method %s", got.Method)
	}
	headers := map[string]string{
		"Content-Type":            "application/json",
		"ce-specversion":          "1.0",
		"ce-type":                 "dev.runr.queue.message",
		"ce-source":               "/redisstream/orders/billing",
		"ce-id":                   "1700000000000-0",
		"ce-subject":              "order-17",
		"ce-time":                 "2023-11-14T22:13:20Z",
		"X-RunR-Delivery-Attempt": "3",
		"X-RunR-Trigger":          "orders",
	}
	for k, want := range headers {
		if v := got.Header.Get(k); v != want {
			t.Errorf("header %s = %q, want %q", k, v, want)
		}
	}
	if string(body) != `{"order":"17","qty":"2"}` {
		t.Errorf("body %s, want the event data", body)
	}
	if got.Header.Get(TokenHeader) != "" {
		t.Error("sent a token without RUNR_INTERNAL_TOKEN")
	}
}

func TestDeliverDefaultsContentTypeAndTime(t *testing.T) {
	var got http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Header.Clone()
	}))
	defer srv.Close()

	tr := testGateway(t, srv.URL, nil, nil).triggers[0]
	before := time.Now().Add(-time.Second)
	if err := tr.emit(context.Background(), source.Event{ID: "x", Type: "t", Source: "/s"}); err != nil {
		t.Fatal(err)
	}
	if ct := got.Get("Content-Type"); ct != "application/json" {
		t.Errorf("Content-Type %q, want the default application/json", ct)
	}
	ts, err := time.Parse(time.RFC3339Nano, got.Get("ce-time"))
	if err != nil || ts.Before(before) {
		t.Errorf("ce-time %q (%v), want the delivery time for an event without a time", got.Get("ce-time"), err)
	}
	if got.Get("ce-subject") != "" {
		t.Error("ce-subject sent for an event without a subject")
	}
}

func TestDeliverStatusHandling(t *testing.T) {
	cases := []struct {
		status int
		ok     bool
	}{
		{http.StatusOK, true},
		{http.StatusNoContent, true},
		{http.StatusFound, false}, // a redirect is not followed and not an acknowledgement
		{http.StatusBadRequest, false},
		{http.StatusTooManyRequests, false},
		{http.StatusBadGateway, false},
	}
	for _, tc := range cases {
		t.Run(http.StatusText(tc.status), func(t *testing.T) {
			var followed atomic.Bool
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if r.URL.Path == "/elsewhere" {
					followed.Store(true)
					return
				}
				if tc.status == http.StatusFound {
					w.Header().Set("Location", "/elsewhere")
				}
				w.WriteHeader(tc.status)
			}))
			defer srv.Close()
			tr := testGateway(t, srv.URL, nil, nil).triggers[0]

			err := tr.post(context.Background(), source.Event{ID: "1-0"}, 1)
			if (err == nil) != tc.ok {
				t.Fatalf("post err = %v, want ok=%v", err, tc.ok)
			}
			if err != nil && !strings.Contains(err.Error(), "status") {
				t.Fatalf("error %q does not name the status", err)
			}
			if followed.Load() {
				t.Fatal("redirect followed")
			}
		})
	}
}

func TestDeliverTimeoutAndUnreachableSink(t *testing.T) {
	block := make(chan struct{})
	slow := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		io.Copy(io.Discard, r.Body)
		select {
		case <-block:
		case <-r.Context().Done():
		}
	}))
	defer slow.Close()
	defer close(block)

	tr := testGateway(t, slow.URL, nil, func(tc *TriggerConfig) { tc.DeliveryTimeout = Duration(50 * time.Millisecond) }).triggers[0]
	began := time.Now()
	if err := tr.post(context.Background(), source.Event{ID: "1-0"}, 1); err == nil {
		t.Fatal("slow sink accepted")
	}
	if d := time.Since(began); d > 2*time.Second {
		t.Fatalf("delivery_timeout not applied: took %v", d)
	}

	gone := httptest.NewServer(http.NotFoundHandler())
	url := gone.URL
	gone.Close()
	tr = testGateway(t, url, nil, nil).triggers[0]
	if err := tr.post(context.Background(), source.Event{ID: "1-0"}, 1); err == nil {
		t.Fatal("unreachable sink accepted")
	}
}

func TestExtensionsBecomeCEHeaders(t *testing.T) {
	var got http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Header.Clone()
	}))
	defer srv.Close()

	tr := testGateway(t, srv.URL, nil, nil).triggers[0]
	ev := source.Event{ID: "e-1", Type: "t", Source: "/s", Extensions: map[string]string{
		"runrpublisher":           "/projects/p1",
		"traceparent":             "00-abc-01",
		"Upper":                   "dropped: not lowercase",
		"with-dash":               "dropped: not a-z0-9",
		"averyveryverylongname21": "dropped: longer than 20",
		"id":                      "dropped: shadows ce-id",
		"":                        "dropped: empty",
	}}
	if err := tr.post(context.Background(), ev, 1); err != nil {
		t.Fatal(err)
	}
	if got.Get("ce-runrpublisher") != "/projects/p1" || got.Get("ce-traceparent") != "00-abc-01" {
		t.Errorf("valid extensions missing: %v", got)
	}
	if got.Get("ce-id") != "e-1" {
		t.Errorf("ce-id = %q: an extension overrode it", got.Get("ce-id"))
	}
	for name := range got {
		lower := strings.ToLower(name)
		if strings.HasPrefix(lower, "ce-") {
			switch strings.TrimPrefix(lower, "ce-") {
			case "specversion", "id", "type", "source", "time", "runrpublisher", "traceparent":
			default:
				t.Errorf("unexpected header %s", name)
			}
		}
	}
}

func TestHeaderValuesArePercentEncoded(t *testing.T) {
	var got http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Header.Clone()
	}))
	defer srv.Close()

	tr := testGateway(t, srv.URL, nil, nil).triggers[0]
	ev := source.Event{ID: "e-1", Type: "t", Source: "/s", Subject: "a b\"c%d\né"}
	if err := tr.post(context.Background(), ev, 1); err != nil {
		t.Fatalf("post: %v", err)
	}
	if want := "a%20b%22c%25d%0A%C3%A9"; got.Get("ce-subject") != want {
		t.Errorf("ce-subject = %q, want %q", got.Get("ce-subject"), want)
	}
}

// The sink is a Knative Service on the region's internal network: the gateway sends the
// project's RUNR_INTERNAL_TOKEN, which its ForwardAuth admits as the project's own call.
func TestTokenHeader(t *testing.T) {
	var got http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Header.Clone()
	}))
	defer srv.Close()

	SetSigner(TokenSigner{Token: "rit1.payload.signature"})
	t.Cleanup(func() { SetSigner(nil) })

	tr := testGateway(t, srv.URL+"/hook", nil, nil).triggers[0]
	for attempt := 1; attempt <= 2; attempt++ {
		got = nil
		if err := tr.post(context.Background(), source.Event{ID: "e", Data: []byte(`{"a":1}`)}, attempt); err != nil {
			t.Fatal(err)
		}
		if v := got.Get(TokenHeader); v != "rit1.payload.signature" {
			t.Errorf("attempt %d: %s = %q, want the project's token", attempt, TokenHeader, v)
		}
	}

	if SignerFromEnv(func(string) string { return "" }) != nil {
		t.Error("SignerFromEnv without RUNR_INTERNAL_TOKEN returned a signer")
	}
	s := SignerFromEnv(func(k string) string {
		return map[string]string{"RUNR_INTERNAL_TOKEN": "abc"}[k]
	})
	req := httptest.NewRequest(http.MethodPost, "http://fn.example/", nil)
	if err := s.Sign(req, nil); err != nil {
		t.Fatal(err)
	}
	if v := req.Header.Get(TokenHeader); v != "abc" {
		t.Errorf("%s = %q, want abc", TokenHeader, v)
	}
}

// ── retries, dead letters, concurrency ──────────────────────────────────────────

// countingSink answers 503 to the first failures requests of every event id, then 200; it
// records the X-RunR-Delivery-Attempt values per ce-id.
type countingSink struct {
	mu       sync.Mutex
	attempts map[string][]string
	failures int // -1: always fail
}

func newCountingSink(t *testing.T, failures int) (*countingSink, string) {
	s := &countingSink{attempts: map[string][]string{}, failures: failures}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		id := r.Header.Get("ce-id")
		s.attempts[id] = append(s.attempts[id], r.Header.Get("X-RunR-Delivery-Attempt"))
		n := len(s.attempts[id])
		s.mu.Unlock()
		if s.failures < 0 || n <= s.failures {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(srv.Close)
	return s, srv.URL
}

func (s *countingSink) seen(id string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return fmt.Sprint(s.attempts[id])
}

func TestRetryThenSuccess(t *testing.T) {
	sink, url := newCountingSink(t, 2)
	tr := testGateway(t, url, nil, retries(5)).triggers[0]

	if err := tr.emit(context.Background(), source.Event{ID: "a"}); err != nil {
		t.Fatalf("emit = %v, want delivered", err)
	}
	if got := sink.seen("a"); got != "[1 2 3]" {
		t.Errorf("attempt headers %s, want [1 2 3]", got)
	}
	if d, f, r := tr.m.delivered.Load(), tr.m.failed.Load(), tr.m.retried.Load(); d != 1 || f != 2 || r != 2 {
		t.Errorf("delivered=%d failed=%d retried=%d, want 1 2 2", d, f, r)
	}
}

func TestExhaustedEventIsDeadLettered(t *testing.T) {
	sink, url := newCountingSink(t, -1)
	g := testGateway(t, url, nil, retries(2))
	tr := g.triggers[0]
	dl := tr.dl.(*memoryDeadLetter)

	fields := map[string]string{"order": "2"}
	ctx := source.WithDeadLetterFields(context.Background(), fields)
	err := tr.emit(ctx, source.Event{ID: "b", Type: "t"})
	if !errors.Is(err, source.ErrDeadLettered) || !source.Settled(err) {
		t.Fatalf("emit = %v, want ErrDeadLettered (settled)", err)
	}
	if !strings.Contains(err.Error(), "status 503") {
		t.Errorf("emit error %q does not carry the last delivery error", err)
	}
	if got := sink.seen("b"); got != "[1 2 3]" {
		t.Errorf("attempt headers %s, want [1 2 3] (1 + max_retries=2)", got)
	}
	recs := dl.records()
	if len(recs) != 1 {
		t.Fatalf("%d dead letter records, want 1", len(recs))
	}
	r := recs[0]
	if r.Trigger != "orders" || r.Event.ID != "b" || r.Attempts != 3 || r.Fields["order"] != "2" || !strings.Contains(r.Error, "status 503") || r.FailedAt.IsZero() {
		t.Errorf("record %+v", r)
	}
	if st := g.TriggerStats()[0]; st.DeadLettered != 1 || st.Failed != 3 || st.Delivered != 0 {
		t.Errorf("stats %+v", st)
	}
}

func TestPriorAttemptsAreCounted(t *testing.T) {
	sink, url := newCountingSink(t, -1)
	tr := testGateway(t, url, nil, retries(3)).triggers[0]
	dl := tr.dl.(*memoryDeadLetter)

	err := tr.emit(source.WithPriorAttempts(context.Background(), 2), source.Event{ID: "c"})
	if !errors.Is(err, source.ErrDeadLettered) {
		t.Fatalf("emit = %v", err)
	}
	if got := sink.seen("c"); got != "[3 4]" {
		t.Errorf("attempt headers %s, want [3 4]: the 2 prior attempts count against max_retries=3", got)
	}

	// Retries used up before: dead-lettered without another attempt.
	err = tr.emit(source.WithPriorAttempts(context.Background(), 4), source.Event{ID: "d"})
	if !errors.Is(err, source.ErrDeadLettered) {
		t.Fatalf("emit = %v", err)
	}
	if got := sink.seen("d"); got != "[]" {
		t.Errorf("exhausted event delivered: %s", got)
	}
	recs := dl.records()
	if len(recs) != 2 || recs[0].Attempts != 4 || recs[1].Attempts != 4 || recs[1].Error != "retries exhausted" {
		t.Errorf("records %+v", recs)
	}
}

func TestDeadLetterWriteIsRetried(t *testing.T) {
	_, url := newCountingSink(t, -1)
	tr := testGateway(t, url, nil, func(tc *TriggerConfig) {
		tc.DeadLetter = &DeadLetterConfig{Kind: "memory", Raw: []byte(`{"kind":"memory","fail_writes":2}`)}
	}).triggers[0]
	dl := tr.dl.(*memoryDeadLetter)

	if err := tr.emit(context.Background(), source.Event{ID: "e"}); !errors.Is(err, source.ErrDeadLettered) {
		t.Fatalf("emit = %v", err)
	}
	if dl.writes.Load() != 3 || len(dl.records()) != 1 {
		t.Errorf("writes=%d records=%d, want 3 and 1", dl.writes.Load(), len(dl.records()))
	}

	// A dead letter that stays down: shutdown leaves the event unsettled.
	dl.failWrites.Store(1 << 30)
	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	err := tr.emit(ctx, source.Event{ID: "f"})
	if source.Settled(err) || !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("emit = %v, want the context error (not settled)", err)
	}
}

func TestShutdownDuringBackoffIsNotSettled(t *testing.T) {
	sink, url := newCountingSink(t, -1)
	tr := testGateway(t, url, nil, func(tc *TriggerConfig) {
		n := 5
		tc.MaxRetries = &n
		tc.RetryBackoff = Duration(time.Hour)
	}).triggers[0]

	ctx, cancel := context.WithCancel(context.Background())
	go func() {
		for sink.seen("g") == "[]" {
			time.Sleep(5 * time.Millisecond)
		}
		cancel()
	}()
	err := tr.emit(ctx, source.Event{ID: "g"})
	if source.Settled(err) || !errors.Is(err, context.Canceled) {
		t.Fatalf("emit = %v, want context.Canceled", err)
	}
	if tr.m.deadLettered.Load() != 0 || len(tr.dl.(*memoryDeadLetter).records()) != 0 {
		t.Error("event dead-lettered at shutdown")
	}
}

func TestShutdownMidRequestIsNotCountedAsFailure(t *testing.T) {
	started := make(chan struct{}, 1)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		io.Copy(io.Discard, r.Body)
		started <- struct{}{}
		<-r.Context().Done() // never answers
	}))
	defer srv.Close()
	tr := testGateway(t, srv.URL, nil, func(tc *TriggerConfig) { tc.DeliveryTimeout = Duration(time.Minute) }).triggers[0]

	ctx, cancel := context.WithCancel(context.Background())
	go func() { <-started; cancel() }()
	if err := tr.emit(ctx, source.Event{ID: "h"}); source.Settled(err) {
		t.Fatalf("emit = %v, want not settled", err)
	}
	if tr.m.failed.Load() != 0 || tr.m.deadLettered.Load() != 0 {
		t.Fatalf("interrupted delivery counted (failed=%d dead=%d)", tr.m.failed.Load(), tr.m.deadLettered.Load())
	}
}

func TestConcurrencyLimitIsNeverExceeded(t *testing.T) {
	var current, peak atomic.Int64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		n := current.Add(1)
		defer current.Add(-1)
		for {
			p := peak.Load()
			if n <= p || peak.CompareAndSwap(p, n) {
				break
			}
		}
		time.Sleep(40 * time.Millisecond)
	}))
	defer srv.Close()
	tr := testGateway(t, srv.URL, nil, func(tc *TriggerConfig) { tc.Concurrency = 3 }).triggers[0]

	var wg sync.WaitGroup
	for i := range 12 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if err := tr.emit(context.Background(), source.Event{ID: fmt.Sprint(i)}); err != nil {
				t.Errorf("emit %d: %v", i, err)
			}
		}()
	}
	wg.Wait()
	if p := peak.Load(); p > 3 || p < 2 {
		t.Fatalf("peak concurrent requests %d, want 2..3 (concurrency 3)", p)
	}
	if tr.m.delivered.Load() != 12 || tr.m.inFlight.Load() != 0 {
		t.Fatalf("delivered=%d in_flight=%d", tr.m.delivered.Load(), tr.m.inFlight.Load())
	}
}

func TestBackoffDoesNotHoldTheSlot(t *testing.T) {
	var aSeen atomic.Int64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("ce-id") == "a" && aSeen.Add(1) == 1 {
			w.WriteHeader(http.StatusServiceUnavailable)
		}
	}))
	defer srv.Close()
	tr := testGateway(t, srv.URL, nil, func(tc *TriggerConfig) {
		n := 1
		tc.MaxRetries = &n
		tc.Concurrency = 1
		tc.RetryBackoff = Duration(2 * time.Second)
	}).triggers[0]

	aDone := make(chan error, 1)
	go func() { aDone <- tr.emit(context.Background(), source.Event{ID: "a"}) }()
	waitFor(t, func() bool { return aSeen.Load() == 1 && tr.m.failed.Load() == 1 })

	began := time.Now()
	if err := tr.emit(context.Background(), source.Event{ID: "b"}); err != nil {
		t.Fatalf("emit b: %v", err)
	}
	if d := time.Since(began); d > time.Second {
		t.Fatalf("b waited %v: a's backoff held the only slot", d)
	}
	if err := <-aDone; err != nil {
		t.Fatalf("emit a: %v", err)
	}
}

func TestBackoffDoublesAndCaps(t *testing.T) {
	tr := &trigger{retryBackoff: 2 * time.Second, retryBackoffMax: 10 * time.Second}
	want := []time.Duration{2 * time.Second, 4 * time.Second, 8 * time.Second, 10 * time.Second, 10 * time.Second}
	for i, w := range want {
		if got := tr.backoff(i + 1); got != w {
			t.Errorf("backoff(%d) = %v, want %v", i+1, got, w)
		}
	}
}

func TestBackoffLargeAttemptCountsStayCapped(t *testing.T) {
	tr := &trigger{retryBackoff: 2 * time.Second, retryBackoffMax: 5 * time.Minute}
	for _, n := range []int{0, 1} {
		if got := tr.backoff(n); got != 2*time.Second {
			t.Errorf("backoff(%d) = %v, want the base delay", n, got)
		}
	}
	for _, n := range []int{9, 64, 1 << 40} {
		if got := tr.backoff(n); got != 5*time.Minute {
			t.Errorf("backoff(%d) = %v, want the cap", n, got)
		}
	}
}

func TestTruncate(t *testing.T) {
	if got := truncate("abcdef", 3); got != "abc" {
		t.Errorf("truncate = %q", got)
	}
	if got := truncate("ab", 3); got != "ab" {
		t.Errorf("truncate = %q", got)
	}
	if got := truncate("aé", 2); got != "a" {
		t.Errorf("truncate split a UTF-8 sequence: %q", got)
	}
}
