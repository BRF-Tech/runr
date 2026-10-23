package main

// Legacy env mode end to end: the queue consumer's environment, the real redis-stream source and
// the gateway's delivery core, against an in-process miniredis by default or a real Redis ≥ 6.2
// with QC_TEST_REDIS=host:port go test ./...

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
)

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

func testRedis(t *testing.T) (*redis.Client, string, string) {
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
	return rdb, addr, stream
}

// legacyGateway is the gateway of legacy env mode for stream, group "g", with short backoffs.
// Only the source's waits are shortened (XREADGROUP blocks 50ms, entries are claimed after
// 100ms idle); legacy env mode has no variables for them.
func legacyGateway(t *testing.T, addr, stream, sinkURL, consumer string, maxRetries int) *gateway.Gateway {
	t.Helper()
	env := map[string]string{
		"REDIS_ADDRESS":     addr,
		"STREAM":            stream,
		"GROUP":             "g",
		"CONSUMER":          consumer,
		"SINK_URL":          sinkURL,
		"DLQ_MAXLEN":        "1000",
		"MAX_RETRIES":       fmt.Sprint(maxRetries),
		"RETRY_BACKOFF":     "40ms",
		"RETRY_BACKOFF_MAX": "200ms",
		"DELIVERY_TIMEOUT":  "2s",
	}
	cfg, err := gateway.FromLegacyEnv(func(k string) string { return env[k] })
	if err != nil {
		t.Fatal(err)
	}
	var src map[string]any
	json.Unmarshal(cfg.Triggers[0].Source, &src)
	src["block"] = "50ms"
	src["claim_min_idle"] = "100ms"
	cfg.Triggers[0].Source, _ = json.Marshal(src)
	g, err := gateway.New(cfg)
	if err != nil {
		t.Fatal(err)
	}
	return g
}

func stats(g *gateway.Gateway) gateway.TriggerStats { return g.TriggerStats()[0] }

// runFor runs g until until() holds or d passed, then shuts it down and waits.
func runFor(g *gateway.Gateway, d time.Duration, until func() bool) {
	ctx, cancel := context.WithTimeout(context.Background(), d)
	defer cancel()
	done := make(chan struct{})
	go func() { g.Run(ctx); close(done) }()
	for ctx.Err() == nil && !until() {
		time.Sleep(20 * time.Millisecond)
	}
	cancel()
	<-done
}

func TestDeliveredMessageIsAcked(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "1"}}).Val()
	g := legacyGateway(t, addr, stream, srv.URL, "pod-a", 3)
	runFor(g, 5*time.Second, func() bool { return stats(g).Delivered == 1 && rdb.XPending(ctx, stream, "g").Val().Count == 0 })

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

func TestHealthAndStatsEndpoints(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"n": "1"}})
	rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "pod-a", Streams: []string{stream, ">"}, Count: 1})
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream + "-dlq", Values: map[string]interface{}{"n": "0"}})

	g := legacyGateway(t, addr, stream, "http://unused.invalid", "pod-a", 4)
	srv := httptest.NewServer(g.Handler())
	defer srv.Close()

	resp, err := http.Get(srv.URL + "/healthz")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != http.StatusOK || string(b) != "ok" {
		t.Fatalf("/healthz = %d %q", resp.StatusCode, b)
	}

	resp, err = http.Get(srv.URL + "/stats")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	if ct := resp.Header.Get("Content-Type"); ct != "application/json" {
		t.Errorf("/stats Content-Type %q", ct)
	}
	var st map[string]interface{}
	if err := json.NewDecoder(resp.Body).Decode(&st); err != nil {
		t.Fatal(err)
	}
	want := map[string]interface{}{
		"stream": stream, "group": "g", "consumer": "pod-a", "dlq_stream": stream + "-dlq",
		"max_retries": float64(4), "delivered": float64(0), "failed": float64(0),
		"retried": float64(0), "dead_lettered": float64(0), "pending": float64(1), "dlq_length": float64(1),
	}
	for k, v := range want {
		if st[k] != v {
			t.Errorf("/stats %s = %v, want %v", k, st[k], v)
		}
	}
}

func TestRunRejectsBadConfiguration(t *testing.T) {
	dir := t.TempDir()
	broken := filepath.Join(dir, "gateway.json")
	os.WriteFile(broken, []byte(`{"version": 1, "triggers": []}`), 0o600)

	cases := map[string]map[string]string{
		"unknown mode":      {"GATEWAY_MODE": "relay"},
		"publisher key":     {"GATEWAY_MODE": "publisher"},
		"legacy dlq":        {"SINK_URL": "http://fn", "STREAM": "a", "DLQ_STREAM": "a"},
		"missing file":      {"GATEWAY_CONFIG": filepath.Join(dir, "missing.json")},
		"file w/o triggers": {"GATEWAY_CONFIG": broken},
	}
	for name, env := range cases {
		err := run(context.Background(), func(k string) string { return env[k] })
		if err == nil {
			t.Errorf("%s: run accepted %v", name, env)
		}
	}
}

func TestRunWithAConfigFile(t *testing.T) {
	_, addr, stream := testRedis(t)
	path := filepath.Join(t.TempDir(), "gateway.json")
	cfg := fmt.Sprintf(`{"version": 1, "sink": "http://fn.invalid", "triggers": [
		{"name": "orders", "type": "queue", "kind": "redis-stream", "source": {"address": %q, "stream": %q, "group": "g", "block": "100ms"}},
		{"name": "nightly", "type": "schedule", "kind": "schedule", "source": {"cron": "@daily"}},
		{"name": "hooks", "type": "webhook", "kind": "inbox", "source": {"url": "http://api.invalid/t/x", "poll_interval": "1h"}}
	]}`, addr, stream)
	os.WriteFile(path, []byte(cfg), 0o600)

	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	err := run(ctx, func(k string) string {
		if k == "GATEWAY_CONFIG" {
			return path
		}
		return ""
	})
	if err != nil {
		t.Fatalf("run = %v, want a clean shutdown", err)
	}
}
