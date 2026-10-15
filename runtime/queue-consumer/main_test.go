package main

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

func TestParseDuration(t *testing.T) {
	cases := map[string]time.Duration{
		"2":     2 * time.Second,
		"0.5":   500 * time.Millisecond,
		"250ms": 250 * time.Millisecond,
		"1m":    time.Minute,
	}
	for in, want := range cases {
		got, err := parseDuration(in)
		if err != nil || got != want {
			t.Errorf("parseDuration(%q) = %v, %v; want %v", in, got, err, want)
		}
	}
	for _, bad := range []string{"-1", "abc", "-2s"} {
		if _, err := parseDuration(bad); err == nil {
			t.Errorf("parseDuration(%q) accepted", bad)
		}
	}
}

func TestBackoffDoublesAndCaps(t *testing.T) {
	c := newConsumer(config{RetryBackoff: 2 * time.Second, RetryBackoffMax: 10 * time.Second}, nil)
	want := []time.Duration{2 * time.Second, 4 * time.Second, 8 * time.Second, 10 * time.Second, 10 * time.Second}
	for i, w := range want {
		if got := c.backoff(int64(i + 1)); got != w {
			t.Errorf("backoff(%d) = %v, want %v", i+1, got, w)
		}
	}
}

func TestLoadConfigDefaults(t *testing.T) {
	os.Setenv("STREAM", "orders")
	defer os.Unsetenv("STREAM")
	cfg := loadConfig()
	if cfg.DLQStream != "orders-dlq" || cfg.MaxRetries != 5 || cfg.RetryBackoff != 2*time.Second || cfg.RetryBackoffMax != 5*time.Minute {
		t.Fatalf("unexpected defaults: %+v", cfg)
	}
}

// ── Stream tests: an in-process miniredis by default, a real Redis ≥ 6.2 with
// QC_TEST_REDIS=host:port go test ./... ───────────────────────────────────────────

type sink struct {
	mu       sync.Mutex
	attempts map[string][]string // ce-id -> X-RunR-Delivery-Attempt values
	fail     atomic.Bool
}

func newSink() (*sink, *httptest.Server) {
	s := &sink{attempts: map[string][]string{}}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		id := r.Header.Get("ce-id")
		s.attempts[id] = append(s.attempts[id], r.Header.Get("X-RunR-Delivery-Attempt"))
		s.mu.Unlock()
		if s.fail.Load() {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	return s, srv
}

func (s *sink) count(id string) int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return len(s.attempts[id])
}

func testRedis(t *testing.T) (*redis.Client, string) {
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
		rdb.Del(context.Background(), stream, stream+"-dlq")
		rdb.Close()
	})
	return rdb, stream
}

func testConsumer(rdb *redis.Client, stream, sinkURL, name string, maxRetries int64) *consumer {
	return newConsumer(config{
		Stream:          stream,
		Group:           "g",
		Consumer:        name,
		SinkURL:         sinkURL,
		DLQStream:       stream + "-dlq",
		DLQMaxLen:       1000,
		MaxRetries:      maxRetries,
		RetryBackoff:    40 * time.Millisecond,
		RetryBackoffMax: 200 * time.Millisecond,
		DeliveryTimeout: 2 * time.Second,
		BatchSize:       10,
		IdlePoll:        100 * time.Millisecond,
	}, rdb)
}

func runFor(c *consumer, d time.Duration, until func() bool) {
	ctx, cancel := context.WithTimeout(context.Background(), d)
	defer cancel()
	c.ensureGroup(ctx)
	done := make(chan struct{})
	go func() { c.run(ctx); close(done) }()
	for ctx.Err() == nil && !until() {
		time.Sleep(20 * time.Millisecond)
	}
	cancel()
	<-done
}

func TestDeliveredMessageIsAcked(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "1"}}).Val()
	c := testConsumer(rdb, stream, srv.URL, "pod-a", 3)
	runFor(c, 5*time.Second, func() bool { return c.stats.Delivered.Load() == 1 })

	if s.count(id) != 1 {
		t.Fatalf("sink got %d deliveries, want 1", s.count(id))
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
	if n := rdb.XLen(ctx, stream+"-dlq").Val(); n != 0 {
		t.Fatalf("DLQ holds %d messages", n)
	}
}

func TestFailingMessageIsRetriedThenDeadLettered(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()
	s.fail.Store(true)

	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "2"}}).Val()
	c := testConsumer(rdb, stream, srv.URL, "pod-a", 2)
	runFor(c, 10*time.Second, func() bool { return c.stats.DeadLettered.Load() == 1 })

	if got := s.count(id); got != 3 {
		t.Fatalf("sink got %d attempts, want 3 (1 + MAX_RETRIES=2)", got)
	}
	s.mu.Lock()
	attempts := fmt.Sprint(s.attempts[id])
	s.mu.Unlock()
	if attempts != "[1 2 3]" {
		t.Fatalf("attempt headers %s, want [1 2 3]", attempts)
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending after dead-lettering", p.Count)
	}
	dlq := rdb.XRange(ctx, stream+"-dlq", "-", "+").Val()
	if len(dlq) != 1 {
		t.Fatalf("DLQ holds %d messages, want 1", len(dlq))
	}
	v := dlq[0].Values
	if v["order"] != "2" || v["runr.dlq.original_id"] != id || v["runr.dlq.attempts"] != "3" || v["runr.dlq.stream"] != stream {
		t.Fatalf("unexpected DLQ entry %v", v)
	}
}

func TestPendingMessagesOfAStoppedConsumerAreRecovered(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "3"}}).Val()
	// A pod read the message and died before delivering / acking it
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}

	c := testConsumer(rdb, stream, srv.URL, "pod-b", 3)
	runFor(c, 5*time.Second, func() bool { return c.stats.Delivered.Load() == 1 })

	if s.count(id) != 1 {
		t.Fatalf("recovered message delivered %d times, want 1", s.count(id))
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
}

func TestSinkRecoveringBeforeRetriesRunOut(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()
	s.fail.Store(true)

	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "4"}}).Val()
	c := testConsumer(rdb, stream, srv.URL, "pod-a", 5)
	go func() {
		for s.count(id) < 2 {
			time.Sleep(10 * time.Millisecond)
		}
		s.fail.Store(false)
	}()
	runFor(c, 10*time.Second, func() bool { return c.stats.Delivered.Load() == 1 })

	if c.stats.DeadLettered.Load() != 0 || rdb.XLen(ctx, stream+"-dlq").Val() != 0 {
		t.Fatal("message was dead-lettered although the sink recovered")
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
}
