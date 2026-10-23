// Package redisstream is the gateway source of kind "redis-stream": it reads a Redis Stream
// through a consumer group — a RunR queue, or a topic subscription fed by the topic publisher.
//
// Configuration (the trigger's "source" object):
//
//	{"address": "redis://redis:6379", "password": "...", "stream": "orders", "group": "billing",
//	 "consumer": "<host name>", "start": "0", "format": "queue", "topic": "", "batch": 10,
//	 "claim_min_idle": "30s", "block": "5s"}
//
// address is host:port, redis://host:port or rediss://host:port (TLS); start is where a new
// group begins ("0": the whole stream, "$": new entries only); format "queue" delivers the entry
// fields as a JSON object, "topic" the event the publisher stored; block is how long one
// XREADGROUP waits for new entries.
//
// Delivery guarantees:
//   - An entry is acknowledged (XACK) only when the gateway settled it: delivered with a 2xx, or
//     dead-lettered after its retries. An entry in delivery at shutdown stays pending.
//   - A dead letter on the same Redis (the queue's <stream>-dlq) writes the record and
//     acknowledges the entry in one MULTI/EXEC (redisx.EntryAck through
//     source.WithDeadLetterAck), as the queue consumer did: never one without the other.
//   - Pending entries that no delivery of this process holds — another consumer's (a pod that
//     was restarted or evicted mid-delivery) or this consumer's own from before a container
//     restart — are claimed with XCLAIM once idle for claim_min_idle and delivered again; the
//     delivery count Redis keeps is passed on as the prior attempts, so retries are not reset.
//   - The entries in delivery are kept fresh (XCLAIM JUSTID, every claim_min_idle/3), so a
//     delivery that takes long with its retries is not claimed by another consumer meanwhile.
//   - A pending entry deleted from the stream (XDEL, MAXLEN trim) is acknowledged.
//   - A group that disappeared (NOGROUP: the stream was flushed) is created again.
package redisstream

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/conf"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/redisx"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func init() { source.Register("redis-stream", New) }

// Config is the "source" object of a redis-stream trigger.
type Config struct {
	Address      string           `json:"address"`
	Password     string           `json:"password"`
	Stream       string           `json:"stream"`
	Group        string           `json:"group"`
	Consumer     string           `json:"consumer"`       // default: the host (pod) name
	Start        string           `json:"start"`          // "0" (default) or "$"
	Format       string           `json:"format"`         // "queue" (default) or "topic"
	Topic        string           `json:"topic"`          // topic name for ce-source; default: the stream name without "runr.topic."
	Batch        int64            `json:"batch"`          // entries per XREADGROUP, default 10
	ClaimMinIdle gateway.Duration `json:"claim_min_idle"` // default 30s
	Block        gateway.Duration `json:"block"`          // default 5s
}

// Source reads one stream through one consumer group.
type Source struct {
	cfg          Config
	topic        string
	rdb          *redis.Client
	claimMinIdle time.Duration
	block        time.Duration

	mu       sync.Mutex
	inFlight map[string]struct{} // entry ids handed to emit and not yet acknowledged or given up
}

// New builds a redis-stream source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	if c.Address == "" {
		return nil, errors.New("address is required")
	}
	if c.Stream == "" || c.Group == "" {
		return nil, errors.New("stream and group are required")
	}
	if c.Consumer == "" {
		c.Consumer = conf.Hostname()
	}
	if c.Start == "" {
		c.Start = "0"
	}
	switch c.Format {
	case "":
		c.Format = "queue"
	case "queue", "topic":
	default:
		return nil, fmt.Errorf("format %q: want queue or topic", c.Format)
	}
	if c.Batch <= 0 {
		c.Batch = 10
	}
	s := &Source{
		cfg:          c,
		topic:        c.Topic,
		rdb:          redisx.NewClient(c.Address, c.Password),
		claimMinIdle: c.ClaimMinIdle.D(),
		block:        c.Block.D(),
		inFlight:     map[string]struct{}{},
	}
	if s.topic == "" {
		s.topic = strings.TrimPrefix(c.Stream, "runr.topic.")
	}
	if s.claimMinIdle <= 0 {
		s.claimMinIdle = 30 * time.Second
	}
	if s.block <= 0 {
		s.block = 5 * time.Second
	}
	return s, nil
}

// Name is "redis-stream <stream>/<group>".
func (s *Source) Name() string { return "redis-stream " + s.cfg.Stream + "/" + s.cfg.Group }

// Close closes the Redis connections.
func (s *Source) Close() error { return s.rdb.Close() }

// Stats reports the group's pending count.
func (s *Source) Stats(ctx context.Context) map[string]any {
	p, err := s.rdb.XPending(ctx, s.cfg.Stream, s.cfg.Group).Result()
	if err != nil {
		return nil
	}
	return map[string]any{"pending": p.Count}
}

// Run reads the stream until ctx ends: each pass claims pending entries that nobody delivers any
// more, then reads new entries, as many as the in-flight window (2×concurrency) has room for.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	win := wait.NewWindow(2 * source.Concurrency(ctx))
	var wg sync.WaitGroup
	defer wg.Wait()

	s.ensureGroup(ctx)

	wg.Add(1)
	go func() {
		defer wg.Done()
		s.keepAlive(ctx)
	}()

	dispatch := func(msg redis.XMessage, prior int) {
		s.track(msg.ID)
		win.Take(1)
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer win.Done()
			s.deliver(ctx, emit, msg, prior)
		}()
	}

	for ctx.Err() == nil {
		if win.Free() == 0 {
			win.WaitFree(ctx, time.Second)
			continue
		}
		s.reclaim(ctx, win, dispatch)
		if free := win.Free(); free > 0 {
			s.readNew(ctx, min(s.cfg.Batch, int64(free)), dispatch)
		}
	}
	return nil
}

// deliver hands one entry to emit and acknowledges it when it is settled. The entry leaves the
// in-flight set only after the XACK, so a reclaim pass never sees it unacknowledged and free.
func (s *Source) deliver(ctx context.Context, emit func(context.Context, source.Event) error, msg redis.XMessage, prior int) {
	defer s.untrack(msg.ID)
	ev, fields := s.event(msg)
	ectx := source.WithPriorAttempts(ctx, prior)
	ectx = source.WithDeadLetterFields(ectx, fields)
	ectx = source.WithDeadLetterAck(ectx, redisx.EntryAck{Address: s.cfg.Address, Stream: s.cfg.Stream, Group: s.cfg.Group, ID: msg.ID})
	if !source.Settled(emit(ectx, ev)) {
		return // shutting down: the entry stays pending and is claimed again later
	}
	// Settled: acknowledge even when shutdown began meanwhile.
	actx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
	defer cancel()
	if err := s.rdb.XAck(actx, s.cfg.Stream, s.cfg.Group, msg.ID).Err(); err != nil {
		log.Printf("%s: XACK %s error (delivered again after claim_min_idle): %v", s.Name(), msg.ID, err)
	}
}

func (s *Source) ensureGroup(ctx context.Context) {
	err := s.rdb.XGroupCreateMkStream(ctx, s.cfg.Stream, s.cfg.Group, s.cfg.Start).Err()
	if err != nil && !strings.Contains(err.Error(), "BUSYGROUP") {
		log.Printf("warning: %s: XGroupCreate: %v", s.Name(), err)
	}
	s.removeIdleConsumers(ctx)
}

// removeIdleConsumers drops consumers of earlier pods that hold no pending entries (consumers
// that still hold some keep them until their entries are claimed).
func (s *Source) removeIdleConsumers(ctx context.Context) {
	consumers, err := s.rdb.XInfoConsumers(ctx, s.cfg.Stream, s.cfg.Group).Result()
	if err != nil {
		return
	}
	for _, info := range consumers {
		if info.Name != s.cfg.Consumer && info.Pending == 0 {
			s.rdb.XGroupDelConsumer(ctx, s.cfg.Stream, s.cfg.Group, info.Name)
		}
	}
}

// readNew reads up to count entries never delivered to this group before.
func (s *Source) readNew(ctx context.Context, count int64, dispatch func(redis.XMessage, int)) {
	streams, err := s.rdb.XReadGroup(ctx, &redis.XReadGroupArgs{
		Group:    s.cfg.Group,
		Consumer: s.cfg.Consumer,
		Streams:  []string{s.cfg.Stream, ">"},
		Count:    count,
		Block:    s.block,
	}).Result()
	if err != nil {
		if ctx.Err() != nil || errors.Is(err, redis.Nil) {
			return
		}
		log.Printf("%s: XReadGroup error: %v", s.Name(), err)
		if strings.Contains(err.Error(), "NOGROUP") {
			s.ensureGroup(ctx)
		}
		wait.Sleep(ctx, time.Second)
		return
	}
	for _, stream := range streams {
		for _, msg := range stream.Messages {
			dispatch(msg, 0)
		}
	}
}

// reclaim claims the pending entries idle for claim_min_idle that no delivery of this process
// holds, and dispatches them with their delivery count as prior attempts.
func (s *Source) reclaim(ctx context.Context, win *wait.Window, dispatch func(redis.XMessage, int)) {
	free := win.Free()
	if free <= 0 {
		return
	}
	pending, err := s.rdb.XPendingExt(ctx, &redis.XPendingExtArgs{
		Stream: s.cfg.Stream,
		Group:  s.cfg.Group,
		Idle:   s.claimMinIdle,
		Start:  "-",
		End:    "+",
		Count:  100,
	}).Result()
	if err != nil {
		if ctx.Err() == nil && !errors.Is(err, redis.Nil) && !strings.Contains(err.Error(), "NOGROUP") {
			log.Printf("%s: XPENDING error: %v", s.Name(), err)
		}
		return
	}

	for _, p := range pending {
		if free <= 0 || ctx.Err() != nil {
			return
		}
		if s.tracked(p.ID) {
			continue
		}
		// XCLAIM with MinIdle: only one consumer wins an entry; its delivery count goes up by one
		msgs, err := s.rdb.XClaim(ctx, &redis.XClaimArgs{
			Stream:   s.cfg.Stream,
			Group:    s.cfg.Group,
			Consumer: s.cfg.Consumer,
			MinIdle:  s.claimMinIdle,
			Messages: []string{p.ID},
		}).Result()
		if err != nil {
			log.Printf("%s: XCLAIM %s error: %v", s.Name(), p.ID, err)
			continue
		}
		if len(msgs) == 1 && msgs[0].Values != nil {
			if p.Consumer != s.cfg.Consumer {
				log.Printf("%s: claimed id=%s from consumer %s (idle %s, delivered %d times)",
					s.Name(), p.ID, p.Consumer, p.Idle.Round(time.Millisecond), p.RetryCount)
			}
			// p.RetryCount is the delivery count before this claim: the claim's count minus one
			dispatch(msgs[0], int(p.RetryCount))
			free--
			continue
		}
		// Not returned: deleted from the stream (Redis < 7 answers it with nil fields, Redis 7
		// leaves it out) or claimed by another consumer meanwhile.
		if s.gone(ctx, p.ID) {
			s.rdb.XAck(ctx, s.cfg.Stream, s.cfg.Group, p.ID)
			log.Printf("%s: pending id=%s no longer exists in the stream, acknowledged", s.Name(), p.ID)
		}
	}
}

func (s *Source) gone(ctx context.Context, id string) bool {
	msgs, err := s.rdb.XRangeN(ctx, s.cfg.Stream, id, id, 1).Result()
	return err == nil && len(msgs) == 0
}

// keepAlive resets the idle time of the entries in delivery every claim_min_idle/3, so another
// consumer does not claim an entry whose delivery (with its retries) takes longer than
// claim_min_idle. XCLAIM ... JUSTID does not raise the delivery count.
func (s *Source) keepAlive(ctx context.Context) {
	tick := time.NewTicker(max(s.claimMinIdle/3, 10*time.Millisecond))
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-tick.C:
		}
		ids := s.trackedIDs()
		if len(ids) == 0 {
			continue
		}
		err := s.rdb.XClaimJustID(ctx, &redis.XClaimArgs{
			Stream:   s.cfg.Stream,
			Group:    s.cfg.Group,
			Consumer: s.cfg.Consumer,
			Messages: ids,
		}).Err()
		if err != nil && ctx.Err() == nil && !errors.Is(err, redis.Nil) {
			log.Printf("%s: keeping %d entries in delivery fresh failed: %v", s.Name(), len(ids), err)
		}
	}
}

func (s *Source) track(id string) {
	s.mu.Lock()
	s.inFlight[id] = struct{}{}
	s.mu.Unlock()
}

func (s *Source) untrack(id string) {
	s.mu.Lock()
	delete(s.inFlight, id)
	s.mu.Unlock()
}

func (s *Source) tracked(id string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, ok := s.inFlight[id]
	return ok
}

func (s *Source) trackedIDs() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	ids := make([]string, 0, len(s.inFlight))
	for id := range s.inFlight {
		ids = append(ids, id)
	}
	return ids
}

// event maps a stream entry to the event to deliver and the fields a dead letter stores.
//
// format "queue": the id is the entry id (a retried delivery keeps it), the type
// dev.runr.queue.message, the source /redisstream/<stream>/<group>, the data the entry fields as
// a JSON object, the time the entry id's timestamp.
//
// format "topic" (entries written by the topic publisher): id, type, subject, datacontenttype,
// time and data come from the entry's fields of those names, the source is /topics/<topic>, and
// the publishing project ("source" field) becomes the extension runrpublisher.
func (s *Source) event(msg redis.XMessage) (source.Event, map[string]string) {
	fields := make(map[string]string, len(msg.Values))
	for k, v := range msg.Values {
		if str, ok := v.(string); ok {
			fields[k] = str
		} else {
			fields[k] = fmt.Sprint(v)
		}
	}
	if s.cfg.Format == "topic" {
		return s.topicEvent(msg.ID, fields), fields
	}
	data, _ := json.Marshal(fields)
	return source.Event{
		ID:              msg.ID,
		Type:            "dev.runr.queue.message",
		Source:          fmt.Sprintf("/redisstream/%s/%s", s.cfg.Stream, s.cfg.Group),
		DataContentType: "application/json",
		Time:            entryTime(msg.ID),
		Data:            data,
	}, fields
}

func (s *Source) topicEvent(entryID string, f map[string]string) source.Event {
	ev := source.Event{
		ID:              f["id"],
		Type:            f["type"],
		Source:          "/topics/" + s.topic,
		Subject:         f["subject"],
		DataContentType: f["datacontenttype"],
		Data:            []byte(f["data"]),
	}
	if ev.ID == "" {
		ev.ID = entryID
	}
	if ev.Type == "" {
		ev.Type = "dev.runr.topic.message"
	}
	if ev.DataContentType == "" {
		ev.DataContentType = "application/json"
	}
	if t, err := time.Parse(time.RFC3339Nano, f["time"]); err == nil {
		ev.Time = t
	} else {
		ev.Time = entryTime(entryID)
	}
	if pub := f["source"]; pub != "" {
		ev.Extensions = map[string]string{"runrpublisher": pub}
	}
	return ev
}

// entryTime is the millisecond timestamp of a stream entry id ("1700000000000-0"); zero (the
// gateway then uses the delivery time) when the id has another form.
func entryTime(id string) time.Time {
	ms, _, _ := strings.Cut(id, "-")
	n, err := strconv.ParseInt(ms, 10, 64)
	if err != nil {
		return time.Time{}
	}
	return time.UnixMilli(n).UTC()
}
