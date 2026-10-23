package main

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"
)

// ── legacy env mode: retries, dead letters and recovery ───────────────────────────

func TestFailingMessageIsRetriedThenDeadLettered(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()
	s.fail.Store(true)

	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "2"}}).Val()
	g := legacyGateway(t, addr, stream, srv.URL, "pod-a", 2)
	runFor(g, 10*time.Second, func() bool {
		return stats(g).DeadLettered == 1 && rdb.XPending(ctx, stream, "g").Val().Count == 0
	})

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
	if v["order"] != "2" || v["runr.dlq.original_id"] != id || v["runr.dlq.attempts"] != "3" ||
		v["runr.dlq.stream"] != stream || v["runr.dlq.group"] != "g" || v["runr.dlq.error"] == nil || v["runr.dlq.failed_at"] == nil {
		t.Fatalf("unexpected DLQ entry %v", v)
	}
}

func TestPendingMessagesOfAStoppedConsumerAreRecovered(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "3"}}).Val()
	// A pod read the message and died before delivering / acking it
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}

	g := legacyGateway(t, addr, stream, srv.URL, "pod-b", 3)
	runFor(g, 5*time.Second, func() bool { return stats(g).Delivered == 1 && rdb.XPending(ctx, stream, "g").Val().Count == 0 })

	if s.count(id) != 1 {
		t.Fatalf("recovered message delivered %d times, want 1", s.count(id))
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
}

func TestSinkRecoveringBeforeRetriesRunOut(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()
	s.fail.Store(true)

	ctx := context.Background()
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "4"}}).Val()
	g := legacyGateway(t, addr, stream, srv.URL, "pod-a", 5)
	go func() {
		for s.count(id) < 2 {
			time.Sleep(10 * time.Millisecond)
		}
		s.fail.Store(false)
	}()
	runFor(g, 10*time.Second, func() bool { return stats(g).Delivered == 1 && rdb.XPending(ctx, stream, "g").Val().Count == 0 })

	if stats(g).DeadLettered != 0 || rdb.XLen(ctx, stream+"-dlq").Val() != 0 {
		t.Fatal("message was dead-lettered although the sink recovered")
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 0 {
		t.Fatalf("%d messages still pending", p.Count)
	}
}

func TestExhaustedPendingMessageIsDeadLetteredWithoutDelivery(t *testing.T) {
	rdb, addr, stream := testRedis(t)
	s, srv := newSink()
	defer srv.Close()

	ctx := context.Background()
	rdb.XGroupCreateMkStream(ctx, stream, "g", "0")
	id := rdb.XAdd(ctx, &redis.XAddArgs{Stream: stream, Values: map[string]interface{}{"order": "5"}}).Val()
	// the last attempt failed and the pod died before the DLQ write: delivery count 1, MAX_RETRIES=0
	if err := rdb.XReadGroup(ctx, &redis.XReadGroupArgs{Group: "g", Consumer: "dead-pod", Streams: []string{stream, ">"}, Count: 1}).Err(); err != nil {
		t.Fatal(err)
	}

	g := legacyGateway(t, addr, stream, srv.URL, "pod-b", 0)
	runFor(g, 5*time.Second, func() bool {
		return stats(g).DeadLettered == 1 && rdb.XPending(ctx, stream, "g").Val().Count == 0
	})

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

func TestShutdownMidDeliveryLeavesMessagePending(t *testing.T) {
	rdb, addr, stream := testRedis(t)
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
	g := legacyGateway(t, addr, stream, srv.URL, "pod-a", 0)
	runFor(g, 5*time.Second, func() bool {
		select {
		case <-started:
			return true
		default:
			return false
		}
	})

	if st := stats(g); st.Failed != 0 || st.DeadLettered != 0 {
		t.Fatalf("interrupted delivery counted as failure (failed=%d dead=%d)", st.Failed, st.DeadLettered)
	}
	if p := rdb.XPending(ctx, stream, "g").Val(); p.Count != 1 {
		t.Fatalf("%d pending, want the interrupted message to stay pending", p.Count)
	}
}
