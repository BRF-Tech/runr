// queue-consumer reads a Redis Stream through a consumer group and POSTs every message as a
// CloudEvent to the project's Knative Service (SINK_URL).
//
// Delivery guarantees:
//   - A message is ACKed only after the sink answered 2xx (redirects are not followed).
//   - A failed delivery stays pending and is retried with exponential backoff
//     (RETRY_BACKOFF, doubled per attempt, capped at RETRY_BACKOFF_MAX).
//   - After MAX_RETRIES retries (MAX_RETRIES+1 attempts) the message is copied to the dead-letter
//     stream DLQ_STREAM (default "<STREAM>-dlq", with runr.dlq.* metadata fields) and ACKed.
//   - Pending messages of any consumer of the group — e.g. a pod that was restarted or evicted
//     mid-delivery — are found with XPENDING and claimed with XCLAIM, so nothing is lost.
//
// GET /healthz answers "ok"; GET /stats returns delivery counters, the group's pending count
// and the DLQ length as JSON.
package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
)

func main() {
	cfg := loadConfig()
	log.Printf("queue-consumer starting stream=%s group=%s consumer=%s sink=%s max_retries=%d backoff=%s..%s dlq=%s",
		cfg.Stream, cfg.Group, cfg.Consumer, cfg.SinkURL, cfg.MaxRetries, cfg.RetryBackoff, cfg.RetryBackoffMax, cfg.DLQStream)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	go handleSignals(cancel)

	c := newConsumer(cfg, newRedisClient(cfg))
	go healthServer(ctx, c)

	c.ensureGroup(ctx)
	c.run(ctx)

	log.Println("queue-consumer shutdown complete")
}

// ── Configuration ────────────────────────────────────────────────────────────

type config struct {
	RedisAddress    string
	RedisPassword   string
	Stream          string
	Group           string
	Consumer        string
	SinkURL         string
	DLQStream       string
	DLQMaxLen       int64
	MaxRetries      int64
	RetryBackoff    time.Duration
	RetryBackoffMax time.Duration
	DeliveryTimeout time.Duration
	BatchSize       int64
	IdlePoll        time.Duration
}

func loadConfig() config {
	c := config{
		RedisAddress:    envOrDefault("REDIS_ADDRESS", "localhost:6379"),
		RedisPassword:   os.Getenv("REDIS_PASSWORD"),
		Stream:          envOrDefault("STREAM", "default-stream"),
		Group:           envOrDefault("GROUP", "default-group"),
		Consumer:        envOrDefault("CONSUMER", defaultConsumerName()),
		SinkURL:         envOrDefault("SINK_URL", "http://localhost:8080"),
		DLQMaxLen:       envInt("DLQ_MAXLEN", 10000),
		MaxRetries:      envInt("MAX_RETRIES", 5),
		RetryBackoff:    envDuration("RETRY_BACKOFF", 2*time.Second),
		RetryBackoffMax: envDuration("RETRY_BACKOFF_MAX", 5*time.Minute),
		DeliveryTimeout: envDuration("DELIVERY_TIMEOUT", 30*time.Second),
		BatchSize:       envInt("BATCH_SIZE", 10),
		IdlePoll:        5 * time.Second,
	}
	c.DLQStream = envOrDefault("DLQ_STREAM", c.Stream+"-dlq")

	if c.RedisAddress == "" {
		log.Fatal("REDIS_ADDRESS is required")
	}
	if c.SinkURL == "" {
		log.Fatal("SINK_URL is required")
	}
	if c.DLQStream == c.Stream {
		log.Fatal("DLQ_STREAM must differ from STREAM")
	}
	if c.MaxRetries < 0 {
		c.MaxRetries = 0
	}
	if c.RetryBackoff <= 0 {
		c.RetryBackoff = time.Second
	}
	if c.RetryBackoffMax < c.RetryBackoff {
		c.RetryBackoffMax = c.RetryBackoff
	}
	if c.BatchSize <= 0 {
		c.BatchSize = 10
	}
	return c
}

// defaultConsumerName is the pod name, so a restarted pod's pending messages are visible as
// another consumer's and get claimed.
func defaultConsumerName() string {
	if h, err := os.Hostname(); err == nil && h != "" {
		return h
	}
	return "consumer-0"
}

func envOrDefault(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func envInt(key string, def int64) int64 {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return def
	}
	n, err := strconv.ParseInt(v, 10, 64)
	if err != nil {
		log.Printf("warning: %s=%q is not an integer, using %d", key, v, def)
		return def
	}
	return n
}

// envDuration accepts Go durations ("2s", "500ms", "1m") or plain seconds ("2").
func envDuration(key string, def time.Duration) time.Duration {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return def
	}
	d, err := parseDuration(v)
	if err != nil {
		log.Printf("warning: %s=%q: %v, using %s", key, v, err, def)
		return def
	}
	return d
}

func parseDuration(v string) (time.Duration, error) {
	if n, err := strconv.ParseFloat(v, 64); err == nil {
		if n < 0 {
			return 0, errors.New("negative duration")
		}
		return time.Duration(n * float64(time.Second)), nil
	}
	d, err := time.ParseDuration(v)
	if err != nil {
		return 0, err
	}
	if d < 0 {
		return 0, errors.New("negative duration")
	}
	return d, nil
}

// newRedisClient connects to REDIS_ADDRESS: host:port, redis://host:port or rediss://host:port.
// rediss:// is Redis over TLS: the server certificate is verified against the system roots for
// the address's host name. The password is REDIS_PASSWORD; userinfo in the URL is ignored.
func newRedisClient(cfg config) *redis.Client {
	addr := cfg.RedisAddress
	useTLS := strings.HasPrefix(addr, "rediss://")
	addr = strings.TrimPrefix(addr, "redis://")
	addr = strings.TrimPrefix(addr, "rediss://")

	// Strip userinfo (e.g. :password@host:port)
	if idx := strings.LastIndex(addr, "@"); idx != -1 {
		addr = addr[idx+1:]
	}

	opts := &redis.Options{
		Addr:     addr,
		Password: cfg.RedisPassword,
	}
	if useTLS {
		host := addr
		if h, _, err := net.SplitHostPort(addr); err == nil {
			host = h
		}
		opts.TLSConfig = &tls.Config{MinVersion: tls.VersionTLS12, ServerName: host}
	}

	return redis.NewClient(opts)
}

// ── Consumer ─────────────────────────────────────────────────────────────────

type stats struct {
	Delivered    atomic.Int64
	Failed       atomic.Int64
	Retried      atomic.Int64
	DeadLettered atomic.Int64
}

type consumer struct {
	cfg     config
	rdb     *redis.Client
	http    *http.Client
	stats   stats
	lastDLQ int64
}

func newConsumer(cfg config, rdb *redis.Client) *consumer {
	return &consumer{
		cfg: cfg,
		rdb: rdb,
		http: &http.Client{
			Timeout: cfg.DeliveryTimeout,
			// A redirect is not an acknowledgement: following it would turn the POST into a
			// body-less GET whose 2xx ACKed a message the sink never received.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		},
		lastDLQ: -1,
	}
}

func (c *consumer) ensureGroup(ctx context.Context) {
	err := c.rdb.XGroupCreateMkStream(ctx, c.cfg.Stream, c.cfg.Group, "0").Err()
	if err != nil && !strings.Contains(err.Error(), "BUSYGROUP") {
		log.Printf("warning: XGroupCreate: %v", err)
	}
	c.removeIdleConsumers(ctx)
}

// removeIdleConsumers drops consumers of earlier pods that hold no pending messages
// (consumers that still hold some keep them until their messages are claimed).
func (c *consumer) removeIdleConsumers(ctx context.Context) {
	consumers, err := c.rdb.XInfoConsumers(ctx, c.cfg.Stream, c.cfg.Group).Result()
	if err != nil {
		return
	}
	for _, info := range consumers {
		if info.Name != c.cfg.Consumer && info.Pending == 0 {
			c.rdb.XGroupDelConsumer(ctx, c.cfg.Stream, c.cfg.Group, info.Name)
		}
	}
}

// backoff is the wait before attempt deliveries+1: RETRY_BACKOFF × 2^(deliveries-1), capped.
func (c *consumer) backoff(deliveries int64) time.Duration {
	d := c.cfg.RetryBackoff
	for i := int64(1); i < deliveries && d < c.cfg.RetryBackoffMax; i++ {
		d *= 2
	}
	if d > c.cfg.RetryBackoffMax {
		d = c.cfg.RetryBackoffMax
	}
	return d
}

func (c *consumer) run(ctx context.Context) {
	lastReport := time.Time{}
	for ctx.Err() == nil {
		waiting := c.processPending(ctx)

		block := c.cfg.IdlePoll
		if waiting {
			// a retry is due soon: poll the pending list more often
			block = c.cfg.RetryBackoff
			if block > time.Second {
				block = time.Second
			}
			if block < 50*time.Millisecond {
				block = 50 * time.Millisecond
			}
		}
		c.readNew(ctx, block)

		if time.Since(lastReport) > time.Minute {
			c.reportDLQ(ctx)
			lastReport = time.Now()
		}
	}
}

// readNew delivers messages never delivered to this group before.
func (c *consumer) readNew(ctx context.Context, block time.Duration) {
	streams, err := c.rdb.XReadGroup(ctx, &redis.XReadGroupArgs{
		Group:    c.cfg.Group,
		Consumer: c.cfg.Consumer,
		Streams:  []string{c.cfg.Stream, ">"},
		Count:    c.cfg.BatchSize,
		Block:    block,
	}).Result()
	if err != nil {
		if ctx.Err() != nil || errors.Is(err, redis.Nil) {
			return
		}
		log.Printf("XReadGroup error: %v", err)
		if strings.Contains(err.Error(), "NOGROUP") {
			c.ensureGroup(ctx)
		}
		sleep(ctx, time.Second)
		return
	}

	for _, stream := range streams {
		for _, msg := range stream.Messages {
			c.handle(ctx, msg, 1)
		}
	}
}

// processPending retries the pending messages whose backoff is over and dead-letters those that
// used up their retries. It returns true while pending messages wait for their next attempt.
func (c *consumer) processPending(ctx context.Context) bool {
	pending, err := c.rdb.XPendingExt(ctx, &redis.XPendingExtArgs{
		Stream: c.cfg.Stream,
		Group:  c.cfg.Group,
		Start:  "-",
		End:    "+",
		Count:  100,
	}).Result()
	if err != nil {
		if ctx.Err() == nil && !errors.Is(err, redis.Nil) {
			log.Printf("XPENDING error: %v", err)
		}
		return false
	}

	waiting := false
	for _, p := range pending {
		if ctx.Err() != nil {
			return false
		}

		// Out of retries (the process stopped between the last failure and the DLQ write)
		if p.RetryCount > c.cfg.MaxRetries {
			msgs, err := c.rdb.XRangeN(ctx, c.cfg.Stream, p.ID, p.ID, 1).Result()
			if err != nil {
				log.Printf("XRANGE %s error: %v", p.ID, err)
				continue
			}
			if len(msgs) == 0 {
				c.ackGone(ctx, p.ID)
				continue
			}
			c.deadLetter(ctx, msgs[0], p.RetryCount, errors.New("retries exhausted"))
			continue
		}

		wait := c.backoff(p.RetryCount)
		if p.Idle < wait {
			waiting = true
			continue
		}

		// XCLAIM with MinIdle: only one consumer wins a message; its delivery count goes up by one
		msgs, err := c.rdb.XClaim(ctx, &redis.XClaimArgs{
			Stream:   c.cfg.Stream,
			Group:    c.cfg.Group,
			Consumer: c.cfg.Consumer,
			MinIdle:  wait,
			Messages: []string{p.ID},
		}).Result()
		if err != nil {
			log.Printf("XCLAIM %s error: %v", p.ID, err)
			continue
		}
		if len(msgs) == 0 {
			continue // claimed by another consumer meanwhile
		}
		if msgs[0].Values == nil {
			c.ackGone(ctx, p.ID) // deleted from the stream (XDEL / MAXLEN trim)
			continue
		}
		c.stats.Retried.Add(1)
		c.handle(ctx, msgs[0], p.RetryCount+1)
	}

	return waiting || len(pending) == 100
}

// handle delivers one message; deliveries counts this attempt.
func (c *consumer) handle(ctx context.Context, msg redis.XMessage, deliveries int64) {
	err := c.deliver(ctx, msg, deliveries)
	if err == nil {
		if err := c.rdb.XAck(ctx, c.cfg.Stream, c.cfg.Group, msg.ID).Err(); err != nil {
			log.Printf("XACK %s error: %v", msg.ID, err)
		}
		c.stats.Delivered.Add(1)
		return
	}
	if ctx.Err() != nil {
		return // shutting down: the message stays pending and is retried later
	}

	c.stats.Failed.Add(1)
	if deliveries > c.cfg.MaxRetries {
		c.deadLetter(ctx, msg, deliveries, err)
		return
	}
	log.Printf("delivery failed id=%s attempt=%d/%d, retry in %s: %v",
		msg.ID, deliveries, c.cfg.MaxRetries+1, c.backoff(deliveries), err)
}

// deadLetter copies the message to the DLQ stream (original fields + runr.dlq.* metadata) and
// ACKs it in the same MULTI/EXEC. If that fails the message stays pending and is dead-lettered
// on the next pass.
func (c *consumer) deadLetter(ctx context.Context, msg redis.XMessage, attempts int64, cause error) {
	values := make(map[string]interface{}, len(msg.Values)+6)
	for k, v := range msg.Values {
		values[k] = v
	}
	values["runr.dlq.original_id"] = msg.ID
	values["runr.dlq.stream"] = c.cfg.Stream
	values["runr.dlq.group"] = c.cfg.Group
	values["runr.dlq.attempts"] = strconv.FormatInt(attempts, 10)
	values["runr.dlq.error"] = truncate(cause.Error(), 500)
	values["runr.dlq.failed_at"] = time.Now().UTC().Format(time.RFC3339)

	_, err := c.rdb.TxPipelined(ctx, func(pipe redis.Pipeliner) error {
		pipe.XAdd(ctx, &redis.XAddArgs{
			Stream: c.cfg.DLQStream,
			MaxLen: c.cfg.DLQMaxLen,
			Approx: c.cfg.DLQMaxLen > 0,
			Values: values,
		})
		pipe.XAck(ctx, c.cfg.Stream, c.cfg.Group, msg.ID)
		return nil
	})
	if err != nil {
		log.Printf("dead-letter of id=%s failed (stays pending): %v", msg.ID, err)
		return
	}

	c.stats.DeadLettered.Add(1)
	n, _ := c.rdb.XLen(ctx, c.cfg.DLQStream).Result()
	c.lastDLQ = n
	log.Printf("dead-lettered id=%s after %d attempts to %s (%d messages in the DLQ): %v",
		msg.ID, attempts, c.cfg.DLQStream, n, cause)
}

func (c *consumer) ackGone(ctx context.Context, id string) {
	c.rdb.XAck(ctx, c.cfg.Stream, c.cfg.Group, id)
	log.Printf("pending id=%s no longer exists in %s, acknowledged", id, c.cfg.Stream)
}

// reportDLQ logs the DLQ length when it changed.
func (c *consumer) reportDLQ(ctx context.Context) {
	n, err := c.rdb.XLen(ctx, c.cfg.DLQStream).Result()
	if err != nil || n == c.lastDLQ {
		return
	}
	if n > 0 || c.lastDLQ > 0 {
		log.Printf("dead-letter stream %s holds %d messages", c.cfg.DLQStream, n)
	}
	c.lastDLQ = n
}

func (c *consumer) deliver(ctx context.Context, msg redis.XMessage, attempt int64) error {
	body, err := json.Marshal(msg.Values)
	if err != nil {
		return fmt.Errorf("marshal: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.cfg.SinkURL, strings.NewReader(string(body)))
	if err != nil {
		return fmt.Errorf("new request: %w", err)
	}

	// CloudEvent headers; the id is the stream entry id, so a retried delivery keeps its id
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("ce-specversion", "1.0")
	req.Header.Set("ce-type", "dev.runr.queue.message")
	req.Header.Set("ce-source", fmt.Sprintf("/redisstream/%s/%s", c.cfg.Stream, c.cfg.Group))
	req.Header.Set("ce-id", msg.ID)
	req.Header.Set("ce-time", time.Now().UTC().Format(time.RFC3339))
	req.Header.Set("X-RunR-Delivery-Attempt", strconv.FormatInt(attempt, 10))

	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("POST %s: %w", c.cfg.SinkURL, err)
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))

	if resp.StatusCode >= 300 {
		return fmt.Errorf("POST %s: status %d", c.cfg.SinkURL, resp.StatusCode)
	}
	return nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

func sleep(ctx context.Context, d time.Duration) {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	}
}

// ── Process plumbing ─────────────────────────────────────────────────────────

func handleSignals(cancel context.CancelFunc) {
	ch := make(chan os.Signal, 1)
	signal.Notify(ch, syscall.SIGTERM, syscall.SIGINT)
	sig := <-ch
	log.Printf("received signal %v, shutting down", sig)
	cancel()
}

func (c *consumer) statsJSON(ctx context.Context) map[string]interface{} {
	out := map[string]interface{}{
		"stream":        c.cfg.Stream,
		"group":         c.cfg.Group,
		"consumer":      c.cfg.Consumer,
		"dlq_stream":    c.cfg.DLQStream,
		"max_retries":   c.cfg.MaxRetries,
		"delivered":     c.stats.Delivered.Load(),
		"failed":        c.stats.Failed.Load(),
		"retried":       c.stats.Retried.Load(),
		"dead_lettered": c.stats.DeadLettered.Load(),
	}
	if p, err := c.rdb.XPending(ctx, c.cfg.Stream, c.cfg.Group).Result(); err == nil {
		out["pending"] = p.Count
	}
	if n, err := c.rdb.XLen(ctx, c.cfg.DLQStream).Result(); err == nil {
		out["dlq_length"] = n
	}
	return out
}

func healthServer(ctx context.Context, c *consumer) {
	srv := &http.Server{Addr: ":8081", Handler: c.healthHandler()}
	go func() {
		<-ctx.Done()
		srv.Shutdown(context.Background())
	}()

	if err := srv.ListenAndServe(); err != http.ErrServerClosed {
		log.Printf("health server error: %v", err)
	}
}

// healthHandler serves GET /healthz and GET /stats.
func (c *consumer) healthHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("ok"))
	})
	mux.HandleFunc("/stats", func(w http.ResponseWriter, r *http.Request) {
		rctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
		defer cancel()
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(c.statsJSON(rctx))
	})
	return mux
}
