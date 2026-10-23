package inbox

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// fakeAPI is the RunR API's side of the inbox protocol: it offers the queued events on claim
// (at most limit) and records claims and acks.
type fakeAPI struct {
	mu     sync.Mutex
	queue  []claimedEvent
	claims []claimRequest
	acks   []ackResult
	status int // when set, every call answers it
	token  string
}

func newFakeAPI(t *testing.T) (*fakeAPI, string) {
	api := &fakeAPI{token: "tok-secret"}
	srv := httptest.NewServer(http.HandlerFunc(api.serve))
	t.Cleanup(srv.Close)
	return api, srv.URL + "/api/internal/triggers/webhooks/prj-1/" + api.token
}

func (a *fakeAPI) serve(w http.ResponseWriter, r *http.Request) {
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.status != 0 {
		w.WriteHeader(a.status)
		return
	}
	if r.Method != http.MethodPost || r.Header.Get("Content-Type") != "application/json" || r.Header.Get("Accept") != "application/json" {
		w.WriteHeader(http.StatusBadRequest)
		return
	}
	switch {
	case strings.HasSuffix(r.URL.Path, a.token+"/claim"):
		var req claimRequest
		json.NewDecoder(r.Body).Decode(&req)
		a.claims = append(a.claims, req)
		n := min(req.Limit, len(a.queue))
		events := a.queue[:n]
		a.queue = a.queue[n:]
		json.NewEncoder(w).Encode(claimResponse{Events: events})
	case strings.HasSuffix(r.URL.Path, a.token+"/ack"):
		var req ackRequest
		json.NewDecoder(r.Body).Decode(&req)
		a.acks = append(a.acks, req.Results...)
		w.WriteHeader(http.StatusNoContent)
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

func (a *fakeAPI) offer(events ...claimedEvent) {
	a.mu.Lock()
	a.queue = append(a.queue, events...)
	a.mu.Unlock()
}

func (a *fakeAPI) snapshot() ([]claimRequest, []ackResult) {
	a.mu.Lock()
	defer a.mu.Unlock()
	return append([]claimRequest(nil), a.claims...), append([]ackResult(nil), a.acks...)
}

func newTestSource(t *testing.T, url string, extra string) *Source {
	t.Helper()
	s, err := New(json.RawMessage(fmt.Sprintf(`{"url": %q, "trigger": "github"%s}`, url, extra)))
	if err != nil {
		t.Fatal(err)
	}
	return s.(*Source)
}

func event(id string, attempts int, data string) claimedEvent {
	return claimedEvent{
		ID: id, Type: "dev.runr.webhook", Source: "/webhooks/github", Subject: "push",
		Time: "2026-10-21T10:00:00.123456Z", DataContentType: "application/json",
		DataBase64: base64.StdEncoding.EncodeToString([]byte(data)),
		Extensions: map[string]string{"runrdelivery": "d-1"}, Attempts: attempts,
	}
}

// run runs s until until() holds or 5s passed, then stops it and waits for Run.
func run(t *testing.T, s *Source, ctx context.Context, emit func(context.Context, source.Event) error, until func() bool) {
	t.Helper()
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()
	for ctx.Err() == nil && !until() {
		time.Sleep(5 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
}

func TestClaimEmitAck(t *testing.T) {
	api, url := newFakeAPI(t)
	api.offer(event("ok-1", 0, `{"ref":"main"}`), event("bad-1", 2, `{}`), event("ok-2", 0, `{}`))
	// batch 1 and a poll interval of an hour: the source must claim again at once after each
	// full batch, or the test times out
	s := newTestSource(t, url, `, "batch": 1, "lease_seconds": 120, "poll_interval": "1h"`)

	var mu sync.Mutex
	got := map[string]source.Event{}
	prior := map[string]int{}
	emit := func(ctx context.Context, ev source.Event) error {
		mu.Lock()
		got[ev.ID] = ev
		prior[ev.ID] = source.PriorAttempts(ctx)
		mu.Unlock()
		if ev.ID == "bad-1" {
			return fmt.Errorf("%w after 3 attempts: POST http://fn: status 500", source.ErrDeadLettered)
		}
		return nil
	}
	run(t, s, context.Background(), emit, func() bool { _, acks := api.snapshot(); return len(acks) == 3 })

	claims, acks := api.snapshot()
	if len(acks) != 3 {
		t.Fatalf("acks %+v, want 3", acks)
	}
	status := map[string]ackResult{}
	for _, a := range acks {
		status[a.ID] = a
	}
	if status["ok-1"].Status != "delivered" || status["ok-2"].Status != "delivered" || status["ok-1"].Error != "" {
		t.Errorf("delivered acks %+v", acks)
	}
	if status["bad-1"].Status != "failed" || !strings.Contains(status["bad-1"].Error, "status 500") {
		t.Errorf("failed ack %+v, want status failed with the last error", status["bad-1"])
	}
	if len(claims) < 4 || claims[0].Trigger != "github" || claims[0].Limit != 1 || claims[0].LeaseSeconds != 120 {
		t.Errorf("claims %+v, want trigger github, limit 1, lease 120 and a claim after every full batch", claims)
	}

	mu.Lock()
	defer mu.Unlock()
	ev := got["ok-1"]
	if ev.Type != "dev.runr.webhook" || ev.Source != "/webhooks/github" || ev.Subject != "push" || ev.DataContentType != "application/json" ||
		string(ev.Data) != `{"ref":"main"}` || ev.Extensions["runrdelivery"] != "d-1" ||
		!ev.Time.Equal(time.Date(2026, 10, 21, 10, 0, 0, 123456000, time.UTC)) {
		t.Errorf("event %+v", ev)
	}
	if prior["bad-1"] != 2 || prior["ok-1"] != 0 {
		t.Errorf("prior attempts %v, want the API's attempts", prior)
	}
}

func TestNoAckOnShutdown(t *testing.T) {
	api, url := newFakeAPI(t)
	api.offer(event("slow", 0, `{}`))
	s := newTestSource(t, url, `, "poll_interval": "1h"`)

	var started atomic.Bool
	emit := func(ctx context.Context, _ source.Event) error {
		started.Store(true)
		<-ctx.Done() // the gateway shuts down mid-delivery
		return ctx.Err()
	}
	run(t, s, context.Background(), emit, started.Load)

	if _, acks := api.snapshot(); len(acks) != 0 {
		t.Fatalf("acks %+v, want none: the lease expires and the API offers the event again", acks)
	}
}

func TestSettledEventIsAckedDuringShutdown(t *testing.T) {
	api, url := newFakeAPI(t)
	api.offer(event("late", 0, `{}`))
	s := newTestSource(t, url, `, "poll_interval": "1h"`)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	emit := func(context.Context, source.Event) error {
		cancel() // shutdown begins while the sink answers 2xx
		return nil
	}
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()
	<-done

	if _, acks := api.snapshot(); len(acks) != 1 || acks[0].Status != "delivered" {
		t.Fatalf("acks %+v, want the delivered event acknowledged", acks)
	}
}

func TestNudgeWakesThePoller(t *testing.T) {
	api, url := newFakeAPI(t)
	s := newTestSource(t, url, `, "poll_interval": "1h"`)

	nudge := make(chan struct{}, 1)
	ctx := source.WithNudges(context.Background(), nudge)
	var emitted atomic.Int64
	emit := func(context.Context, source.Event) error { emitted.Add(1); return nil }

	go func() {
		for {
			if claims, _ := api.snapshot(); len(claims) >= 1 {
				break
			}
			time.Sleep(5 * time.Millisecond)
		}
		api.offer(event("new", 0, `{}`)) // the API stored a webhook call ...
		nudge <- struct{}{}              // ... and nudged the gateway
	}()
	run(t, s, ctx, emit, func() bool { return emitted.Load() == 1 })

	if emitted.Load() != 1 {
		t.Fatal("the nudge did not wake the poller before its 1h poll interval")
	}
}

func TestUnknownTokenKeepsPolling(t *testing.T) {
	api, url := newFakeAPI(t)
	api.mu.Lock()
	api.status = http.StatusNotFound
	api.mu.Unlock()
	s := newTestSource(t, url, `, "poll_interval": "20ms"`)

	var calls atomic.Int64
	api2 := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		api.serve(w, r)
	}))
	defer api2.Close()
	s.cfg.URL = api2.URL + "/api/internal/triggers/webhooks/prj-1/" + api.token

	run(t, s, context.Background(), func(context.Context, source.Event) error { return nil }, func() bool { return calls.Load() >= 3 })
	if calls.Load() < 3 {
		t.Fatalf("%d claims, want the source to keep polling after 404", calls.Load())
	}
}

func TestMalformedEventIsReportedFailed(t *testing.T) {
	api, url := newFakeAPI(t)
	bad := event("garbled", 0, "")
	bad.DataBase64 = "%%%not base64"
	api.offer(bad)
	s := newTestSource(t, url, `, "poll_interval": "1h"`)

	var emitted atomic.Int64
	run(t, s, context.Background(), func(context.Context, source.Event) error { emitted.Add(1); return nil },
		func() bool { _, acks := api.snapshot(); return len(acks) == 1 })

	_, acks := api.snapshot()
	if emitted.Load() != 0 || len(acks) != 1 || acks[0].Status != "failed" || !strings.Contains(acks[0].Error, "data_base64") {
		t.Fatalf("emitted=%d acks=%+v", emitted.Load(), acks)
	}
}

func TestConfigAndNameKeepTheTokenSecret(t *testing.T) {
	for _, bad := range []string{`{}`, `{"url": "ftp://api/x"}`, `{"url": "not a url"}`} {
		if _, err := New(json.RawMessage(bad)); err == nil {
			t.Errorf("config %s accepted", bad)
		}
	}
	s, err := New(json.RawMessage(`{"url": "https://api.example/api/internal/triggers/webhooks/prj-1/tok-secret/"}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	if src.cfg.Batch != 10 || src.cfg.LeaseSeconds != 600 || src.poll != 15*time.Second || src.errWait != 5*time.Second {
		t.Errorf("defaults %+v poll=%s errWait=%s", src.cfg, src.poll, src.errWait)
	}
	if strings.Contains(src.Name(), "tok-secret") || !strings.Contains(src.Name(), "api.example") {
		t.Errorf("Name %q", src.Name())
	}

	// a transport error must not carry the url (and its token) either
	src.cfg.URL = "http://127.0.0.1:1/x/tok-secret"
	err = src.post(context.Background(), "/claim", claimRequest{}, nil)
	if err == nil || strings.Contains(err.Error(), "tok-secret") {
		t.Errorf("post error %v", err)
	}
}
