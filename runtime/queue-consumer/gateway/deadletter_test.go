package gateway

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/redisx"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// testRedis is an in-process miniredis by default, a real Redis ≥ 6.2 with
// QC_TEST_REDIS=host:port go test ./...; the second result tells which.
func testRedis(t *testing.T) (addr string, mini bool) {
	t.Helper()
	if addr := os.Getenv("QC_TEST_REDIS"); addr != "" {
		return addr, false
	}
	return miniredis.RunT(t).Addr(), true
}

func redisDeadLetterFor(t *testing.T, addr, stream string, extra string) *redisDeadLetter {
	t.Helper()
	dl, err := newRedisDeadLetter(json.RawMessage(fmt.Sprintf(`{"kind":"redis-stream","address":%q,"stream":%q%s}`, addr, stream, extra)))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		dl.(*redisDeadLetter).rdb.Del(context.Background(), stream)
		dl.(*redisDeadLetter).Close()
	})
	return dl.(*redisDeadLetter)
}

func TestRedisDeadLetterKeepsTheSourceFields(t *testing.T) {
	addr, _ := testRedis(t)
	stream := fmt.Sprintf("qc-test-dlq-%d", time.Now().UnixNano())
	dl := redisDeadLetterFor(t, addr, stream, `,"meta":{"runr.dlq.stream":"orders","runr.dlq.group":"billing"}`)

	ctx := context.Background()
	err := dl.Write(ctx, DeadLetterRecord{
		Trigger:  "queue",
		Event:    source.Event{ID: "1700000000000-0"},
		Fields:   map[string]string{"order": "2"},
		Attempts: 6,
		Error:    strings.Repeat("x", 600),
		FailedAt: time.Date(2026, 10, 21, 12, 0, 0, 0, time.UTC),
	})
	if err != nil {
		t.Fatal(err)
	}
	entries := dl.rdb.XRange(ctx, stream, "-", "+").Val()
	if len(entries) != 1 {
		t.Fatalf("DLQ holds %d entries, want 1", len(entries))
	}
	v := entries[0].Values
	want := map[string]string{
		"order":                "2",
		"runr.dlq.original_id": "1700000000000-0",
		"runr.dlq.trigger":     "queue",
		"runr.dlq.stream":      "orders",
		"runr.dlq.group":       "billing",
		"runr.dlq.attempts":    "6",
		"runr.dlq.failed_at":   "2026-10-21T12:00:00Z",
	}
	for k, w := range want {
		if v[k] != w {
			t.Errorf("%s = %v, want %q", k, v[k], w)
		}
	}
	if e, _ := v["runr.dlq.error"].(string); len(e) != 500 {
		t.Errorf("runr.dlq.error has %d bytes, want it cut to 500", len(e))
	}
	if _, ok := v["ce_id"]; ok {
		t.Error("envelope fields written although the source passed its own")
	}
	if n, err := dl.Len(ctx); err != nil || n != 1 {
		t.Errorf("Len = %d, %v", n, err)
	}
}

func TestRedisDeadLetterStoresTheEnvelope(t *testing.T) {
	addr, _ := testRedis(t)
	stream := fmt.Sprintf("qc-test-dlq-%d", time.Now().UnixNano())
	dl := redisDeadLetterFor(t, addr, stream, "")

	ctx := context.Background()
	err := dl.Write(ctx, DeadLetterRecord{
		Trigger: "hooks",
		Event: source.Event{
			ID: "ev-9", Type: "dev.runr.webhook", Source: "/webhooks/github", Subject: "push",
			DataContentType: "application/json", Time: time.Date(2026, 10, 21, 12, 0, 0, 500, time.UTC),
			Data: []byte(`{"ref":"main"}`),
		},
		Attempts: 1,
		Error:    "POST http://fn: status 500",
		FailedAt: time.Now(),
	})
	if err != nil {
		t.Fatal(err)
	}
	entries := dl.rdb.XRange(ctx, stream, "-", "+").Val()
	if len(entries) != 1 {
		t.Fatalf("DLQ holds %d entries, want 1", len(entries))
	}
	v := entries[0].Values
	want := map[string]string{
		"ce_id": "ev-9", "ce_type": "dev.runr.webhook", "ce_source": "/webhooks/github", "ce_subject": "push",
		"ce_time": "2026-10-21T12:00:00.0000005Z", "ce_datacontenttype": "application/json", "data": `{"ref":"main"}`,
		"runr.dlq.original_id": "ev-9", "runr.dlq.trigger": "hooks", "runr.dlq.attempts": "1",
		"runr.dlq.error": "POST http://fn: status 500",
	}
	for k, w := range want {
		if v[k] != w {
			t.Errorf("%s = %v, want %q", k, v[k], w)
		}
	}
	if _, ok := v["runr.dlq.stream"]; ok {
		t.Error("runr.dlq.stream written without meta")
	}
}

// An entry of a redis-stream source on the same Redis is acknowledged in the dead letter's
// MULTI/EXEC; an entry of another Redis is left to its source.
func TestRedisDeadLetterAcknowledgesTheEntryInOneTransaction(t *testing.T) {
	addr, _ := testRedis(t)
	ctx := context.Background()
	suffix := time.Now().UnixNano()
	queue, dlqStream := fmt.Sprintf("qc-test-q-%d", suffix), fmt.Sprintf("qc-test-q-%d-dlq", suffix)
	dl := redisDeadLetterFor(t, addr, dlqStream, "")
	rdb := dl.rdb
	t.Cleanup(func() { rdb.Del(context.Background(), queue) })

	if err := rdb.XGroupCreateMkStream(ctx, queue, "g", "0").Err(); err != nil {
		t.Fatal(err)
	}
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: queue, Values: map[string]any{"order": "1"}}).Val()
	other := rdb.XAdd(ctx, &redis.XAddArgs{Stream: queue, Values: map[string]any{"order": "2"}}).Val()
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "c", Streams: []string{queue, ">"}, Count: 2}).Err(); err != nil {
		t.Fatal(err)
	}

	// the same Redis, written as the source has it (scheme, userinfo)
	err := dl.Write(ctx, DeadLetterRecord{
		Trigger: "queue", Event: source.Event{ID: id}, Fields: map[string]string{"order": "1"}, FailedAt: time.Now(),
		Ack: redisx.EntryAck{Address: "redis://:pw@" + addr, Stream: queue, Group: "g", ID: id},
	})
	if err != nil {
		t.Fatal(err)
	}
	// another Redis: the record is written, the entry stays the source's to acknowledge
	err = dl.Write(ctx, DeadLetterRecord{
		Trigger: "queue", Event: source.Event{ID: other}, Fields: map[string]string{"order": "2"}, FailedAt: time.Now(),
		Ack: redisx.EntryAck{Address: "elsewhere:6379", Stream: queue, Group: "g", ID: other},
	})
	if err != nil {
		t.Fatal(err)
	}

	if n := rdb.XLen(ctx, dlqStream).Val(); n != 2 {
		t.Fatalf("DLQ holds %d entries, want 2", n)
	}
	pending := rdb.XPendingExt(ctx, &redis.XPendingExtArgs{Stream: queue, Group: "g", Start: "-", End: "+", Count: 10}).Val()
	if len(pending) != 1 || pending[0].ID != other {
		t.Fatalf("pending %+v, want only %s (the entry of another Redis)", pending, other)
	}
}

func TestRedisDeadLetterTrimsToMaxLen(t *testing.T) {
	addr, mini := testRedis(t)
	if !mini {
		t.Skip("a real Redis trims MAXLEN ~ by whole nodes, not to the entry")
	}
	stream := fmt.Sprintf("qc-test-dlq-%d", time.Now().UnixNano())
	dl := redisDeadLetterFor(t, addr, stream, `,"max_len":2`)
	for i := range 5 {
		if err := dl.Write(context.Background(), DeadLetterRecord{Event: source.Event{ID: fmt.Sprint(i)}, FailedAt: time.Now()}); err != nil {
			t.Fatal(err)
		}
	}
	if n := dl.rdb.XLen(context.Background(), stream).Val(); n != 2 {
		t.Fatalf("DLQ holds %d entries, want max_len 2", n)
	}
}

func TestDeadLetterConfiguration(t *testing.T) {
	dl, err := newDeadLetter(nil)
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := dl.(logDeadLetter); !ok {
		t.Errorf("default dead letter %T, want log", dl)
	}
	if err := dl.Write(context.Background(), DeadLetterRecord{Trigger: "x", Event: source.Event{ID: "1"}}); err != nil {
		t.Errorf("log dead letter: %v", err)
	}

	for _, bad := range []string{
		`{"kind":"redis-stream","stream":"s"}`,               // no address
		`{"kind":"redis-stream","address":"localhost:6379"}`, // no stream
	} {
		if _, err := newDeadLetter(&DeadLetterConfig{Kind: "redis-stream", Raw: json.RawMessage(bad)}); err == nil {
			t.Errorf("dead_letter %s accepted", bad)
		}
	}

	rd, err := newDeadLetter(&DeadLetterConfig{Kind: "redis-stream", Raw: json.RawMessage(`{"kind":"redis-stream","address":"redis://:pw@localhost:6379","stream":"s"}`)})
	if err != nil {
		t.Fatal(err)
	}
	defer rd.(*redisDeadLetter).Close()
	if r := rd.(*redisDeadLetter); r.maxLen != 10000 || r.rdb.Options().Addr != "localhost:6379" {
		t.Errorf("maxLen=%d addr=%s, want the 10000 default and the address without userinfo", r.maxLen, r.rdb.Options().Addr)
	}

	defer func() {
		if recover() == nil {
			t.Error("registering a dead letter kind twice did not panic")
		}
	}()
	RegisterDeadLetter("log", newLogDeadLetter)
}
