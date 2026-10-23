package redisstream

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// ── Stream tests: an in-process miniredis by default, a real Redis ≥ 6.2 with
// QC_TEST_REDIS=host:port go test ./... ───────────────────────────────────────────

func testRedis(t *testing.T) (*redis.Client, string, string) {
	t.Helper()
	addr := os.Getenv("QC_TEST_REDIS")
	if addr == "" {
		addr = miniredis.RunT(t).Addr()
	}
	rdb := redis.NewClient(&redis.Options{Addr: addr})
	if err := rdb.Ping(context.Background()).Err(); err != nil {
		t.Fatalf("redis %s: %v", addr, err)
	}
	stream := fmt.Sprintf("qc-test-%d", time.Now().UnixNano())
	t.Cleanup(func() {
		rdb.Del(context.Background(), stream)
		rdb.Close()
	})
	return rdb, addr, stream
}

// newTestSource is a source of group "g" with short waits: XREADGROUP blocks 50ms, entries are
// claimed after 100ms idle.
func newTestSource(t *testing.T, addr, stream, consumer string, extra map[string]any) *Source {
	t.Helper()
	cfg := map[string]any{
		"address": addr, "stream": stream, "group": "g", "consumer": consumer,
		"block": "50ms", "claim_min_idle": "100ms",
	}
	for k, v := range extra {
		cfg[k] = v
	}
	raw, _ := json.Marshal(cfg)
	s, err := New(raw)
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	t.Cleanup(func() { s.(*Source).Close() })
	return s.(*Source)
}

// recorder is an emit that records what it is handed; result decides each event's outcome
// (nil: delivered).
type recorder struct {
	mu      sync.Mutex
	events  []source.Event
	prior   map[string]int
	fields  map[string]map[string]string
	result  func(ctx context.Context, ev source.Event) error
	active  atomic.Int64
	peak    atomic.Int64
	settled atomic.Int64
}

func newRecorder(result func(context.Context, source.Event) error) *recorder {
	return &recorder{prior: map[string]int{}, fields: map[string]map[string]string{}, result: result}
}

func (r *recorder) emit(ctx context.Context, ev source.Event) error {
	n := r.active.Add(1)
	defer r.active.Add(-1)
	for {
		p := r.peak.Load()
		if n <= p || r.peak.CompareAndSwap(p, n) {
			break
		}
	}
	r.mu.Lock()
	r.events = append(r.events, ev)
	r.prior[ev.ID] = source.PriorAttempts(ctx)
	r.fields[ev.ID] = source.DeadLetterFields(ctx)
	r.mu.Unlock()
	err := error(nil)
	if r.result != nil {
		err = r.result(ctx, ev)
	}
	if source.Settled(err) {
		r.settled.Add(1)
	}
	return err
}

func (r *recorder) count(id string) int {
	r.mu.Lock()
	defer r.mu.Unlock()
	n := 0
	for _, ev := range r.events {
		if ev.ID == id {
			n++
		}
	}
	return n
}

func (r *recorder) event(id string) (source.Event, int, map[string]string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	for _, ev := range r.events {
		if ev.ID == id {
			return ev, r.prior[id], r.fields[id]
		}
	}
	return source.Event{}, -1, nil
}

// runFor runs s until until() holds or d passed, then stops it and waits for Run to return.
func runFor(t *testing.T, s *Source, emit func(context.Context, source.Event) error, concurrency int, d time.Duration, until func() bool) {
	t.Helper()
	ctx, cancel := context.WithTimeout(source.WithConcurrency(context.Background(), concurrency), d)
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- s.Run(ctx, emit) }()
	for ctx.Err() == nil && !until() {
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v, want nil on shutdown", err)
	}
}

func pending(rdb *redis.Client, stream string) int64 {
	return rdb.XPending(context.Background(), stream, "g").Val().Count
}

func TestQueueEntryIsEmittedAndAcked(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "1", "qty": "2"}}).Val()

	s := newTestSource(t, addr, stream, "pod-a", nil)
	rec := newRecorder(nil)
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.settled.Load() == 1 && pending(rdb, stream) == 0 })

	ev, prior, fields := rec.event(id)
	if ev.Type != "dev.runr.queue.message" || ev.Source != "/redisstream/"+stream+"/g" || ev.DataContentType != "application/json" {
		t.Errorf("event attributes %+v", ev)
	}
	var data map[string]string
	if err := json.Unmarshal(ev.Data, &data); err != nil || data["order"] != "1" || data["qty"] != "2" {
		t.Errorf("data %s (%v), want the entry fields as JSON", ev.Data, err)
	}
	if ev.Time.IsZero() || ev.Time.UnixMilli() != entryTime(id).UnixMilli() {
		t.Errorf("time %v, want the entry id's timestamp", ev.Time)
	}
	if prior != 0 || fields["order"] != "1" || fields["qty"] != "2" {
		t.Errorf("prior=%d fields=%v", prior, fields)
	}
	if n := pending(rdb, stream); n != 0 {
		t.Fatalf("%d entries still pending", n)
	}
}

func TestDeadLetteredEntryIsAcked(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	rdb.XAdd(context.Background(), &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "2"}})

	s := newTestSource(t, addr, stream, "pod-a", nil)
	rec := newRecorder(func(context.Context, source.Event) error {
		return fmt.Errorf("%w after 3 attempts: status 503", source.ErrDeadLettered)
	})
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.settled.Load() == 1 && pending(rdb, stream) == 0 })
	if n := pending(rdb, stream); n != 0 {
		t.Fatalf("%d entries still pending after dead-lettering", n)
	}
}

func TestShutdownMidDeliveryLeavesEntryPending(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	rdb.XAdd(context.Background(), &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "9"}})

	s := newTestSource(t, addr, stream, "pod-a", nil)
	rec := newRecorder(func(ctx context.Context, _ source.Event) error {
		<-ctx.Done() // the gateway shuts down mid-delivery
		return ctx.Err()
	})
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.active.Load() == 1 })

	if n := pending(rdb, stream); n != 1 {
		t.Fatalf("%d pending, want the interrupted entry to stay pending", n)
	}
}

func TestPendingEntryOfAStoppedConsumerIsClaimed(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "3"}}).Val()
	// A pod read the entry and died before delivering / acknowledging it
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}

	s := newTestSource(t, addr, stream, "pod-b", nil)
	rec := newRecorder(nil)
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.settled.Load() == 1 && pending(rdb, stream) == 0 })

	if n := rec.count(id); n != 1 {
		t.Fatalf("recovered entry emitted %d times, want 1", n)
	}
	if _, prior, _ := rec.event(id); prior != 1 {
		t.Errorf("prior attempts %d, want 1 (the dead pod's delivery)", prior)
	}
	if n := pending(rdb, stream); n != 0 {
		t.Fatalf("%d entries still pending", n)
	}
}

func TestOwnPendingEntryFromBeforeARestartIsClaimed(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "10"}}).Val()
	// The container restarted in the same pod: the same consumer name holds the entry
	rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "pod-a", Streams: []string{stream, ">"}, Count: 1})

	s := newTestSource(t, addr, stream, "pod-a", nil)
	rec := newRecorder(nil)
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.settled.Load() == 1 && pending(rdb, stream) == 0 })

	if n := rec.count(id); n != 1 {
		t.Fatalf("own pending entry emitted %d times, want 1", n)
	}
}

func TestEntryInDeliveryIsNotClaimedByAnotherConsumer(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	id := rdb.XAdd(context.Background(), &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "11"}}).Val()
	extra := map[string]any{"claim_min_idle": "300ms"}

	// pod-a takes the entry and needs 1.2s (4 × claim_min_idle) to settle it
	a := newTestSource(t, addr, stream, "pod-a", extra)
	slow := newRecorder(func(ctx context.Context, _ source.Event) error {
		select {
		case <-time.After(1200 * time.Millisecond):
		case <-ctx.Done():
			return ctx.Err()
		}
		return nil
	})
	b := newTestSource(t, addr, stream, "pod-b", extra)
	other := newRecorder(nil)

	aCtx, aCancel := context.WithCancel(source.WithConcurrency(context.Background(), 1))
	aDone := make(chan struct{})
	go func() { a.Run(aCtx, slow.emit); close(aDone) }()
	for slow.count(id) == 0 {
		time.Sleep(5 * time.Millisecond)
	}
	runFor(t, b, other.emit, 1, 3*time.Second, func() bool { return slow.settled.Load() == 1 })
	aCancel()
	<-aDone

	if n := other.count(id); n != 0 {
		t.Fatalf("pod-b claimed the entry pod-a was still delivering (%d times)", n)
	}
	if n := pending(rdb, stream); n != 0 {
		t.Fatalf("%d entries still pending", n)
	}
}

func TestPendingEntryDeletedFromStreamIsAcknowledged(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "6"}}).Val()
	keep := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "7"}}).Val()
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}
	rdb.XDel(ctx, stream, id) // trimmed / deleted while pending

	s := newTestSource(t, addr, stream, "pod-b", nil)
	rec := newRecorder(nil)
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.settled.Load() == 1 && pending(rdb, stream) == 0 })

	if rec.count(id) != 0 {
		t.Fatalf("deleted entry emitted %d times", rec.count(id))
	}
	if rec.count(keep) != 1 {
		t.Fatalf("remaining entry emitted %d times, want 1", rec.count(keep))
	}
	if n := pending(rdb, stream); n != 0 {
		t.Fatalf("%d entries still pending", n)
	}
}

func TestEnsureGroupIsIdempotentAndDropsIdleConsumers(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	s := newTestSource(t, addr, stream, "pod-new", nil)

	s.ensureGroup(ctx) // creates stream + group
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"n": "1"}})
	// busy-pod holds a pending entry, idle-pod holds none
	rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "busy-pod", Streams: []string{stream, ">"}, Count: 1})
	rdb.XGroupCreateConsumer(ctx, stream, "g", "idle-pod")

	s.ensureGroup(ctx) // BUSYGROUP is ignored, idle consumers are removed

	consumers, err := rdb.XInfoConsumers(ctx, stream, "g").Result()
	if err != nil {
		t.Fatal(err)
	}
	names := map[string]bool{}
	for _, ci := range consumers {
		names[ci.Name] = true
	}
	if names["idle-pod"] {
		t.Errorf("idle consumer kept: %v", names)
	}
	if !names["busy-pod"] {
		t.Errorf("consumer with pending entries removed: %v", names)
	}
	groups := rdb.XInfoGroups(ctx, stream).Val()
	if len(groups) != 1 || groups[0].Name != "g" {
		t.Fatalf("groups = %v", groups)
	}
}

func TestGroupIsRecreatedAfterNOGROUP(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	s := newTestSource(t, addr, stream, "pod-a", nil)
	s.ensureGroup(ctx)
	if err := rdb.XGroupDestroy(ctx, stream, "g").Err(); err != nil { // e.g. the stream was flushed and recreated
		t.Fatal(err)
	}
	if n := len(rdb.XInfoGroups(ctx, stream).Val()); n != 0 {
		t.Fatalf("%d groups after XGROUP DESTROY", n)
	}

	rec := newRecorder(nil)
	ctx2, cancel := context.WithTimeout(source.WithConcurrency(ctx, 1), 8*time.Second)
	defer cancel()
	done := make(chan struct{})
	go func() { s.Run(ctx2, rec.emit); close(done) }()
	// Run created the group at start; destroy it again so the read hits NOGROUP
	for len(rdb.XInfoGroups(ctx, stream).Val()) == 0 {
		time.Sleep(5 * time.Millisecond)
	}
	rdb.XGroupDestroy(ctx, stream, "g")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"order": "8"}}).Val()
	for ctx2.Err() == nil && rec.settled.Load() == 0 {
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	<-done

	if n := rec.count(id); n != 1 {
		t.Fatalf("entry emitted %d times after the group was recreated, want 1", n)
	}
}

func TestTopicFormatMapping(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: []any{
		"id", "01JB0000000000000000000000",
		"type", "com.acme.order.created",
		"subject", "order-17",
		"source", "/projects/prj-1",
		"time", "2026-10-21T10:00:00.123456Z",
		"datacontenttype", "text/plain",
		"data", "hello",
	}}).Val()
	bare := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: []any{"data", `{"a":1}`}}).Val()

	s := newTestSource(t, addr, stream, "pod-a", map[string]any{"format": "topic", "topic": "orders", "start": "0"})
	rec := newRecorder(nil)
	runFor(t, s, rec.emit, 2, 5*time.Second, func() bool { return rec.settled.Load() == 2 })

	ev, _, fields := rec.event("01JB0000000000000000000000")
	if ev.Type != "com.acme.order.created" || ev.Source != "/topics/orders" || ev.Subject != "order-17" ||
		ev.DataContentType != "text/plain" || string(ev.Data) != "hello" ||
		!ev.Time.Equal(time.Date(2026, 10, 21, 10, 0, 0, 123456000, time.UTC)) ||
		ev.Extensions["runrpublisher"] != "/projects/prj-1" {
		t.Errorf("topic event %+v", ev)
	}
	if fields["data"] != "hello" || fields["id"] != "01JB0000000000000000000000" {
		t.Errorf("dead letter fields %v, want the entry fields", fields)
	}

	ev, _, _ = rec.event(bare)
	if ev.ID != bare || ev.Type != "dev.runr.topic.message" || ev.DataContentType != "application/json" ||
		string(ev.Data) != `{"a":1}` || ev.Extensions != nil || ev.Time.UnixMilli() != entryTime(bare).UnixMilli() {
		t.Errorf("fallbacks of a bare topic entry %+v", ev)
	}

	// Without "topic" the stream name, minus the publisher's prefix, names the topic
	raw, _ := json.Marshal(map[string]any{"address": addr, "stream": "runr.topic.billing-acme", "group": "g", "format": "topic"})
	src, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	defer src.(*Source).Close()
	if got := src.(*Source).topicEvent("1-0", map[string]string{}).Source; got != "/topics/billing-acme" {
		t.Errorf("source %q", got)
	}
}

func TestWindowBoundsEntriesInDelivery(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	for i := range 6 {
		rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"n": fmt.Sprint(i)}})
	}

	s := newTestSource(t, addr, stream, "pod-a", nil)
	release := make(chan struct{})
	rec := newRecorder(func(ctx context.Context, _ source.Event) error {
		select {
		case <-release:
			return nil
		case <-ctx.Done():
			return ctx.Err()
		}
	})
	go func() {
		for rec.active.Load() < 2 {
			time.Sleep(5 * time.Millisecond)
		}
		time.Sleep(200 * time.Millisecond) // the source would read more now if the window let it
		close(release)
	}()
	runFor(t, s, rec.emit, 1, 5*time.Second, func() bool { return rec.settled.Load() == 6 && pending(rdb, stream) == 0 })

	if p := rec.peak.Load(); p != 2 {
		t.Fatalf("peak events in delivery %d, want 2 (2 × concurrency 1)", p)
	}
	if n := pending(rdb, stream); n != 0 {
		t.Fatalf("%d entries still pending", n)
	}
}

func TestStatsReportsPending(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	s := newTestSource(t, addr, stream, "pod-a", nil)
	s.ensureGroup(ctx)
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]any{"n": "1"}})
	rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "pod-a", Streams: []string{stream, ">"}, Count: 1})

	if got := s.Stats(ctx)["pending"]; got != int64(1) {
		t.Fatalf("Stats pending = %v, want 1", got)
	}
}

func TestConfigValidation(t *testing.T) {
	for _, bad := range []string{
		`{"stream": "s", "group": "g"}`,
		`{"address": "redis:6379", "group": "g"}`,
		`{"address": "redis:6379", "stream": "s"}`,
		`{"address": "redis:6379", "stream": "s", "group": "g", "format": "avro"}`,
		`{"address": "redis:6379", "stream": "s", "group": "g", "claim_min_idle": "-1s"}`,
	} {
		if s, err := New(json.RawMessage(bad)); err == nil {
			s.(*Source).Close()
			t.Errorf("config %s accepted", bad)
		}
	}
	s, err := New(json.RawMessage(`{"address": "redis://:pw@redis:6379", "stream": "s", "group": "g"}`))
	if err != nil {
		t.Fatal(err)
	}
	defer s.(*Source).Close()
	src := s.(*Source)
	if src.cfg.Consumer == "" || src.cfg.Start != "0" || src.cfg.Format != "queue" || src.cfg.Batch != 10 ||
		src.claimMinIdle != 30*time.Second || src.block != 5*time.Second {
		t.Errorf("defaults %+v claim_min_idle=%s block=%s", src.cfg, src.claimMinIdle, src.block)
	}
	if name := src.Name(); name != "redis-stream s/g" {
		t.Errorf("Name = %q", name)
	}
}
