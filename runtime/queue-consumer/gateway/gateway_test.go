package gateway

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// The real sources import this package, so they cannot be linked into its test binary: the
// tests register stand-ins. "redis-stream" is what FromLegacyEnv produces; "test" hands out the
// source a test registered with useSource.
var (
	testSourcesMu sync.Mutex
	testSources   = map[string]source.Source{}
)

func init() {
	source.Register("redis-stream", func(json.RawMessage) (source.Source, error) {
		return statsSource{stats: map[string]any{"pending": int64(3)}}, nil
	})
	source.Register("test", func(raw json.RawMessage) (source.Source, error) {
		var c struct {
			ID string `json:"id"`
		}
		if err := json.Unmarshal(raw, &c); err != nil {
			return nil, err
		}
		testSourcesMu.Lock()
		defer testSourcesMu.Unlock()
		s, ok := testSources[c.ID]
		if !ok {
			return nil, fmt.Errorf("no test source %q", c.ID)
		}
		return s, nil
	})
	RegisterDeadLetter("memory", func(raw json.RawMessage) (DeadLetter, error) {
		var c struct {
			FailWrites int `json:"fail_writes"`
		}
		json.Unmarshal(raw, &c)
		m := &memoryDeadLetter{}
		m.failWrites.Store(int64(c.FailWrites))
		return m, nil
	})
}

// useSource registers s for the running test and returns the "source" object that selects it.
func useSource(t *testing.T, s source.Source) json.RawMessage {
	id := t.Name() + "/" + strconv.Itoa(int(sourceSeq.Add(1)))
	testSourcesMu.Lock()
	testSources[id] = s
	testSourcesMu.Unlock()
	t.Cleanup(func() {
		testSourcesMu.Lock()
		delete(testSources, id)
		testSourcesMu.Unlock()
	})
	b, _ := json.Marshal(map[string]string{"id": id})
	return b
}

var sourceSeq atomic.Int64

// idleSource emits nothing.
type idleSource struct{}

func (idleSource) Name() string { return "idle" }

func (idleSource) Run(ctx context.Context, _ func(context.Context, source.Event) error) error {
	<-ctx.Done()
	return nil
}

type statsSource struct {
	idleSource
	stats map[string]any
}

func (s statsSource) Stats(context.Context) map[string]any { return s.stats }

// memoryDeadLetter keeps its records; its first fail_writes writes fail.
type memoryDeadLetter struct {
	mu         sync.Mutex
	recs       []DeadLetterRecord
	writes     atomic.Int64
	failWrites atomic.Int64
}

func (m *memoryDeadLetter) Write(_ context.Context, rec DeadLetterRecord) error {
	m.writes.Add(1)
	if m.failWrites.Add(-1) >= 0 {
		return errors.New("dead letter store down")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	m.recs = append(m.recs, rec)
	return nil
}

func (m *memoryDeadLetter) records() []DeadLetterRecord {
	m.mu.Lock()
	defer m.mu.Unlock()
	return append([]DeadLetterRecord(nil), m.recs...)
}

func (m *memoryDeadLetter) Len(context.Context) (int64, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	return int64(len(m.recs)), nil
}

// testGateway builds a gateway of one trigger "orders" (type queue) delivering to sink, with
// short backoffs, no retries and a memory dead letter unless mutate changes them.
func testGateway(t *testing.T, sink string, src source.Source, mutate func(*TriggerConfig)) *Gateway {
	t.Helper()
	if src == nil {
		src = idleSource{}
	}
	zero := 0
	tc := TriggerConfig{
		Name:            "orders",
		Type:            "queue",
		Kind:            "test",
		Sink:            sink,
		MaxRetries:      &zero,
		RetryBackoff:    Duration(20 * time.Millisecond),
		RetryBackoffMax: Duration(100 * time.Millisecond),
		DeliveryTimeout: Duration(time.Second),
		DeadLetter:      &DeadLetterConfig{Kind: "memory", Raw: json.RawMessage(`{"kind":"memory"}`)},
		Source:          useSource(t, src),
	}
	if mutate != nil {
		mutate(&tc)
	}
	g, err := New(&Config{Version: 1, Triggers: []TriggerConfig{tc}})
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	g.restartMin, g.restartMax = 10*time.Millisecond, 40*time.Millisecond
	t.Cleanup(g.close)
	return g
}

func retries(n int) func(*TriggerConfig) {
	return func(tc *TriggerConfig) { tc.MaxRetries = &n }
}

// runGateway runs g until until() holds or d passed, then shuts it down and waits.
func runGateway(t *testing.T, g *Gateway, d time.Duration, until func() bool) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), d)
	defer cancel()
	done := make(chan struct{})
	go func() { g.Run(ctx); close(done) }()
	for ctx.Err() == nil && !until() {
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	<-done
}

// flakySource fails its first runs, panics once, then waits for ctx; it records what the core
// put on Run's ctx.
type flakySource struct {
	runs        atomic.Int64
	failures    int64
	concurrency atomic.Int64
	trigger     atomic.Value
	nudges      atomic.Bool
}

func (f *flakySource) Name() string { return "flaky" }

func (f *flakySource) Run(ctx context.Context, _ func(context.Context, source.Event) error) error {
	n := f.runs.Add(1)
	f.concurrency.Store(int64(source.Concurrency(ctx)))
	f.trigger.Store(source.TriggerName(ctx))
	f.nudges.Store(source.Nudges(ctx) != nil)
	switch {
	case n == f.failures+1:
		panic("source bug")
	case n <= f.failures:
		return fmt.Errorf("broker unreachable (run %d)", n)
	}
	<-ctx.Done()
	return nil
}

func TestSourceIsRestartedAfterErrorAndPanic(t *testing.T) {
	src := &flakySource{failures: 2}
	g := testGateway(t, "http://sink.invalid", src, func(tc *TriggerConfig) { tc.Concurrency = 4 })
	runGateway(t, g, 5*time.Second, func() bool { return src.runs.Load() == 4 })

	if got := src.runs.Load(); got != 4 {
		t.Fatalf("source ran %d times, want 4 (2 errors + 1 panic + the healthy run)", got)
	}
	if got := g.TriggerStats()[0].Restarts; got != 3 {
		t.Errorf("source_restarts = %d, want 3", got)
	}
	if src.concurrency.Load() != 4 || src.trigger.Load() != "orders" || !src.nudges.Load() {
		t.Errorf("Run ctx: concurrency=%d trigger=%v nudges=%v", src.concurrency.Load(), src.trigger.Load(), src.nudges.Load())
	}
}

// nudgeSource counts the nudges it receives.
type nudgeSource struct{ got atomic.Int64 }

func (n *nudgeSource) Name() string { return "nudge" }

func (n *nudgeSource) Run(ctx context.Context, _ func(context.Context, source.Event) error) error {
	for {
		select {
		case <-ctx.Done():
			return nil
		case <-source.Nudges(ctx):
			n.got.Add(1)
		}
	}
}

func TestNudgeReachesOnlyTheNamedTrigger(t *testing.T) {
	a, b := &nudgeSource{}, &nudgeSource{}
	cfg := &Config{Version: 1, Sink: "http://sink.invalid", Triggers: []TriggerConfig{
		{Name: "a", Kind: "test", Source: useSource(t, a)},
		{Name: "b", Kind: "test", Source: useSource(t, b)},
	}}
	g, err := New(cfg)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { g.Run(ctx); close(done) }()
	defer func() { cancel(); <-done }()

	if g.Nudge("missing") {
		t.Error("Nudge of an unknown trigger reported true")
	}
	g.Nudge("a")
	waitFor(t, func() bool { return a.got.Load() == 1 })
	g.Nudge("")
	waitFor(t, func() bool { return a.got.Load() == 2 && b.got.Load() == 1 })
}

func waitFor(t *testing.T, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !cond() {
		if time.Now().After(deadline) {
			t.Fatal("condition not reached within 5s")
		}
		time.Sleep(5 * time.Millisecond)
	}
}

// emitSource emits its events once, from one goroutine each, and records the results.
type emitSource struct {
	events  []source.Event
	mu      sync.Mutex
	results map[string]error
}

func (e *emitSource) Name() string { return "emit" }

func (e *emitSource) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	var wg sync.WaitGroup
	for _, ev := range e.events {
		wg.Add(1)
		go func() {
			defer wg.Done()
			err := emit(ctx, ev)
			e.mu.Lock()
			e.results[ev.ID] = err
			e.mu.Unlock()
		}()
	}
	wg.Wait()
	<-ctx.Done()
	return nil
}

func (e *emitSource) result(id string) (returned bool, err error) {
	e.mu.Lock()
	defer e.mu.Unlock()
	err, returned = e.results[id]
	return returned, err
}

func TestRunDeliversWhatTheSourceEmits(t *testing.T) {
	var got atomic.Value
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got.Store(r.Header.Get("ce-id") + " " + r.Header.Get("X-RunR-Trigger"))
		w.WriteHeader(http.StatusNoContent)
	}))
	defer srv.Close()

	src := &emitSource{events: []source.Event{{ID: "ev-1", Type: "t", Source: "/s"}}, results: map[string]error{}}
	g := testGateway(t, srv.URL, src, nil)
	runGateway(t, g, 5*time.Second, func() bool { returned, _ := src.result("ev-1"); return returned })

	if returned, err := src.result("ev-1"); !returned || err != nil {
		t.Fatalf("emit returned=%v err=%v, want nil", returned, err)
	}
	if got.Load() != "ev-1 orders" {
		t.Errorf("sink saw %v", got.Load())
	}
	if st := g.TriggerStats()[0]; st.Delivered != 1 || st.Failed != 0 {
		t.Errorf("stats %+v", st)
	}
}

func TestStringSummaryHasNoSecrets(t *testing.T) {
	g := testGateway(t, "http://user:hunter2@sink.example/fn", nil, nil)
	s := g.String()
	if strings.Contains(s, "hunter2") {
		t.Errorf("summary leaks the sink password: %q", s)
	}
	if !strings.Contains(s, "orders(type=queue source=idle sink=http://user:xxxxx@sink.example/fn") {
		t.Errorf("summary %q", s)
	}
}
