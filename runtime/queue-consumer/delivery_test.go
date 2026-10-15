package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"
)

// ── deliver: CloudEvent request and status handling ─────────────────────────────

func TestDeliverPostsCloudEvent(t *testing.T) {
	var got *http.Request
	var body []byte
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Clone(context.Background())
		body, _ = io.ReadAll(r.Body)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer srv.Close()

	c := newConsumer(config{Stream: "orders", Group: "billing", SinkURL: srv.URL, DeliveryTimeout: time.Second}, nil)
	msg := redis.XMessage{ID: "1700000000000-0", Values: map[string]interface{}{"order": "17", "qty": "2"}}
	if err := c.deliver(context.Background(), msg, 3); err != nil {
		t.Fatalf("deliver: %v", err)
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
		"X-RunR-Delivery-Attempt": "3",
	}
	for k, want := range headers {
		if v := got.Header.Get(k); v != want {
			t.Errorf("header %s = %q, want %q", k, v, want)
		}
	}
	if _, err := time.Parse(time.RFC3339, got.Header.Get("ce-time")); err != nil {
		t.Errorf("ce-time %q is not RFC 3339", got.Header.Get("ce-time"))
	}
	var payload map[string]string
	if err := json.Unmarshal(body, &payload); err != nil || payload["order"] != "17" || payload["qty"] != "2" {
		t.Errorf("body %s (%v), want the stream entry fields as JSON", body, err)
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
			srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				if tc.status == http.StatusFound {
					w.Header().Set("Location", "/elsewhere")
				}
				w.WriteHeader(tc.status)
			}))
			defer srv.Close()
			c := newConsumer(config{SinkURL: srv.URL, DeliveryTimeout: time.Second}, nil)

			err := c.deliver(context.Background(), redis.XMessage{ID: "1-0", Values: map[string]interface{}{}}, 1)
			if (err == nil) != tc.ok {
				t.Fatalf("deliver err = %v, want ok=%v", err, tc.ok)
			}
			if err != nil && !strings.Contains(err.Error(), "status") {
				t.Fatalf("error %q does not name the status", err)
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

	c := newConsumer(config{SinkURL: slow.URL, DeliveryTimeout: 50 * time.Millisecond}, nil)
	began := time.Now()
	if err := c.deliver(context.Background(), redis.XMessage{ID: "1-0"}, 1); err == nil {
		t.Fatal("slow sink accepted")
	}
	if d := time.Since(began); d > 2*time.Second {
		t.Fatalf("DELIVERY_TIMEOUT not applied: took %v", d)
	}

	gone := httptest.NewServer(http.NotFoundHandler())
	url := gone.URL
	gone.Close()
	c = newConsumer(config{SinkURL: url, DeliveryTimeout: time.Second}, nil)
	if err := c.deliver(context.Background(), redis.XMessage{ID: "1-0"}, 1); err == nil {
		t.Fatal("unreachable sink accepted")
	}
}

// ── configuration ──────────────────────────────────────────────────────────────

func TestLoadConfigNormalisesInvalidValues(t *testing.T) {
	t.Setenv("STREAM", "jobs")
	t.Setenv("DLQ_STREAM", "jobs-dead")
	t.Setenv("MAX_RETRIES", "-3")
	t.Setenv("RETRY_BACKOFF", "0")
	t.Setenv("RETRY_BACKOFF_MAX", "100ms")
	t.Setenv("BATCH_SIZE", "0")
	t.Setenv("DELIVERY_TIMEOUT", "1.5")
	t.Setenv("DLQ_MAXLEN", "abc") // not an integer: default
	t.Setenv("CONSUMER", "pod-7")

	cfg := loadConfig()
	if cfg.DLQStream != "jobs-dead" {
		t.Errorf("DLQStream = %q", cfg.DLQStream)
	}
	if cfg.MaxRetries != 0 {
		t.Errorf("MaxRetries = %d, want 0 (negative clamped)", cfg.MaxRetries)
	}
	if cfg.RetryBackoff != time.Second {
		t.Errorf("RetryBackoff = %v, want 1s (non-positive replaced)", cfg.RetryBackoff)
	}
	if cfg.RetryBackoffMax != time.Second {
		t.Errorf("RetryBackoffMax = %v, want raised to RetryBackoff", cfg.RetryBackoffMax)
	}
	if cfg.BatchSize != 10 {
		t.Errorf("BatchSize = %d, want 10", cfg.BatchSize)
	}
	if cfg.DeliveryTimeout != 1500*time.Millisecond {
		t.Errorf("DeliveryTimeout = %v, want 1.5s", cfg.DeliveryTimeout)
	}
	if cfg.DLQMaxLen != 10000 {
		t.Errorf("DLQMaxLen = %d, want default 10000", cfg.DLQMaxLen)
	}
	if cfg.Consumer != "pod-7" {
		t.Errorf("Consumer = %q", cfg.Consumer)
	}
}

func TestEnvDurationFallsBackOnInvalidValues(t *testing.T) {
	for _, v := range []string{"soon", "-5", "-1m"} {
		t.Setenv("QC_DURATION", v)
		if got := envDuration("QC_DURATION", 7*time.Second); got != 7*time.Second {
			t.Errorf("envDuration(%q) = %v, want the default", v, got)
		}
	}
	t.Setenv("QC_DURATION", " 250ms ")
	if got := envDuration("QC_DURATION", time.Second); got != 250*time.Millisecond {
		t.Errorf("envDuration(250ms) = %v", got)
	}
}

func TestNewRedisClientStripsURLParts(t *testing.T) {
	cases := map[string]string{
		"redis:6379":                      "redis:6379",
		"redis://redis.runr:6379":         "redis.runr:6379",
		"rediss://cache.example:6380":     "cache.example:6380",
		"redis://:s3cret@10.0.0.5:6379":   "10.0.0.5:6379",
		"redis://user:p@ss@10.0.0.5:6379": "10.0.0.5:6379",
	}
	for in, want := range cases {
		rdb := newRedisClient(config{RedisAddress: in, RedisPassword: "pw"})
		if got := rdb.Options().Addr; got != want {
			t.Errorf("newRedisClient(%q).Addr = %q, want %q", in, got, want)
		}
		if rdb.Options().Password != "pw" {
			t.Errorf("password from REDIS_PASSWORD not used for %q", in)
		}
		rdb.Close()
	}
}

func TestNewRedisClientUsesTLSForRediss(t *testing.T) {
	rdb := newRedisClient(config{RedisAddress: "rediss://user:pw@cache.example:6380"})
	defer rdb.Close()
	tlsCfg := rdb.Options().TLSConfig
	if tlsCfg == nil {
		t.Fatal("rediss:// connected without TLS")
	}
	if tlsCfg.ServerName != "cache.example" {
		t.Errorf("TLS ServerName = %q, want the address host", tlsCfg.ServerName)
	}
	if tlsCfg.InsecureSkipVerify {
		t.Error("the server certificate is not verified")
	}
	if tlsCfg.MinVersion < tls.VersionTLS12 {
		t.Errorf("TLS MinVersion = %#x, want at least TLS 1.2", tlsCfg.MinVersion)
	}

	for _, in := range []string{"redis://redis.runr:6379", "redis:6379"} {
		plain := newRedisClient(config{RedisAddress: in})
		if plain.Options().TLSConfig != nil {
			t.Errorf("newRedisClient(%q) uses TLS", in)
		}
		plain.Close()
	}
}

func TestBackoffLargeAttemptCountsStayCapped(t *testing.T) {
	c := newConsumer(config{RetryBackoff: 2 * time.Second, RetryBackoffMax: 5 * time.Minute}, nil)
	for _, n := range []int64{0, 1} {
		if got := c.backoff(n); got != 2*time.Second {
			t.Errorf("backoff(%d) = %v, want the base delay", n, got)
		}
	}
	for _, n := range []int64{9, 64, 1 << 40} {
		if got := c.backoff(n); got != 5*time.Minute {
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
}

// ── stream behaviour ───────────────────────────────────────────────────────────

func TestExhaustedPendingMessageIsDeadLetteredWithoutDelivery(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "5"}}).Val()
	// the last attempt failed and the pod died before the DLQ write: delivery count 1, MAX_RETRIES=0
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}

	c := testConsumer(rdb, stream, srv.URL, "pod-b", 0)
	runFor(c, 5*time.Second, func() bool { return c.stats.DeadLettered.Load() == 1 })

	if n := s.count(id); n != 0 {
		t.Fatalf("exhausted message delivered %d times, want 0", n)
	}
	dlq := rdb.XRange(ctx, stream+"-dlq", "-", "+").Val()
	if len(dlq) != 1 || dlq[0].Values["runr.dlq.error"] != "retries exhausted" || dlq[0].Values["runr.dlq.original_id"] != id {
		t.Fatalf("DLQ = %v", dlq)
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
}

func TestPendingMessageDeletedFromStreamIsAcknowledged(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "6"}}).Val()
	keep := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "7"}}).Val()
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}
	rdb.XDel(ctx, stream, id) // trimmed / deleted while pending

	c := testConsumer(rdb, stream, srv.URL, "pod-b", 0)
	runFor(c, 5*time.Second, func() bool {
		return c.stats.Delivered.Load() == 1 && rdb.XPending(ctx, stream, "g").Val().Count == 0
	})

	if s.count(id) != 0 || c.stats.DeadLettered.Load() != 0 {
		t.Fatalf("deleted message delivered %d times / dead-lettered %d", s.count(id), c.stats.DeadLettered.Load())
	}
	if s.count(keep) != 1 {
		t.Fatalf("remaining message delivered %d times, want 1", s.count(keep))
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
}

func TestEnsureGroupIsIdempotentAndDropsIdleConsumers(t *testing.T) {
	rdb, stream := testRedis(t)
	ctx := context.Background()
	c := testConsumer(rdb, stream, "http://unused", "pod-new", 3)

	c.ensureGroup(ctx) // creates stream + group
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"n": "1"}})
	// busy-pod holds a pending message, idle-pod holds none
	rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "busy-pod", Streams: []string{stream, ">"}, Count: 1})
	rdb.XGroupCreateConsumer(ctx, stream, "g", "idle-pod")

	c.ensureGroup(ctx) // BUSYGROUP is ignored, idle consumers are removed

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
		t.Errorf("consumer with pending messages removed: %v", names)
	}
	groups := rdb.XInfoGroups(ctx, stream).Val()
	if len(groups) != 1 || groups[0].Name != "g" {
		t.Fatalf("groups = %v", groups)
	}
}

func TestHealthAndStatsEndpoints(t *testing.T) {
	rdb, stream := testRedis(t)
	ctx := context.Background()
	c := testConsumer(rdb, stream, "http://unused", "pod-a", 4)
	c.ensureGroup(ctx)
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"n": "1"}})
	rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "pod-a", Streams: []string{stream, ">"}, Count: 1})
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream + "-dlq", Values: map[string]interface{}{"n": "0"}})
	c.stats.Delivered.Add(7)
	c.stats.Failed.Add(2)

	srv := httptest.NewServer(c.healthHandler())
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
		"max_retries": float64(4), "delivered": float64(7), "failed": float64(2),
		"retried": float64(0), "dead_lettered": float64(0), "pending": float64(1), "dlq_length": float64(1),
	}
	for k, v := range want {
		if st[k] != v {
			t.Errorf("/stats %s = %v, want %v", k, st[k], v)
		}
	}
}

func TestConsumerRecreatesDeletedGroup(t *testing.T) {
	rdb, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	c := testConsumer(rdb, stream, srv.URL, "pod-a", 3)
	c.ensureGroup(ctx)
	if err := rdb.XGroupDestroy(ctx, stream, "g").Err(); err != nil { // e.g. the stream was flushed and recreated
		t.Fatal(err)
	}
	if n := len(rdb.XInfoGroups(ctx, stream).Val()); n != 0 {
		t.Fatalf("%d groups after XGROUP DESTROY", n)
	}

	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "8"}}).Val()
	runFor(c, 8*time.Second, func() bool { return c.stats.Delivered.Load() == 1 })

	if s.count(id) != 1 {
		t.Fatalf("message delivered %d times after the group was recreated, want 1", s.count(id))
	}
}

func TestShutdownMidDeliveryLeavesMessagePending(t *testing.T) {
	rdb, stream := testRedis(t)
	started := make(chan struct{}, 1)
	release := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		io.Copy(io.Discard, r.Body) // lets the server notice the client hanging up
		select {
		case started <- struct{}{}:
		default:
		}
		select { // never answers
		case <-r.Context().Done():
		case <-release:
		}
	}))
	defer srv.Close()
	defer close(release)

	ctx := context.Background()
	rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "9"}})
	c := testConsumer(rdb, stream, srv.URL, "pod-a", 0)
	runFor(c, 5*time.Second, func() bool {
		select {
		case <-started:
			return true
		default:
			return false
		}
	})

	if c.stats.Failed.Load() != 0 || c.stats.DeadLettered.Load() != 0 {
		t.Fatalf("interrupted delivery counted as failure (failed=%d dead=%d)", c.stats.Failed.Load(), c.stats.DeadLettered.Load())
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 1 {
		t.Fatalf("%d pending, want the interrupted message to stay pending", p.Count)
	}
}
