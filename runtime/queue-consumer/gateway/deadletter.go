package gateway

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"sort"
	"strconv"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/redisx"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// DeadLetter stores the events of a trigger that used up their retries. Write is retried with
// backoff until it succeeds or the gateway shuts down (the event is then not settled and comes
// back from its origin), so a dead letter must tolerate the same record twice.
type DeadLetter interface {
	Write(ctx context.Context, rec DeadLetterRecord) error
}

// DeadLetterLen is implemented by a dead letter that can count its records; GET /stats reports
// the count as dlq_length.
type DeadLetterLen interface {
	Len(ctx context.Context) (int64, error)
}

// DeadLetterRecord is one dead-lettered event.
type DeadLetterRecord struct {
	Trigger  string
	Event    source.Event
	Fields   map[string]string // source.DeadLetterFields of the emit ctx; nil: store the CloudEvent envelope
	Attempts int               // delivery attempts made, prior attempts included
	Error    string            // the last attempt's error, at most 500 bytes
	FailedAt time.Time         // UTC
	Ack      any               // source.DeadLetterAck of the emit ctx: the origin's acknowledgement, for a dead letter that runs it with its write
}

var (
	deadLetterMu       sync.RWMutex
	deadLetterRegistry = map[string]func(cfg json.RawMessage) (DeadLetter, error){
		"log":          newLogDeadLetter,
		"redis-stream": newRedisDeadLetter,
	}
)

// RegisterDeadLetter makes a dead letter kind available to the "dead_letter" object of a
// trigger; the factory receives the whole object (its "kind" included). It panics on an empty
// kind, a nil factory or a kind registered twice.
func RegisterDeadLetter(kind string, factory func(cfg json.RawMessage) (DeadLetter, error)) {
	if kind == "" || factory == nil {
		panic("gateway: RegisterDeadLetter needs a kind and a factory")
	}
	deadLetterMu.Lock()
	defer deadLetterMu.Unlock()
	if _, dup := deadLetterRegistry[kind]; dup {
		panic("gateway: dead letter kind " + kind + " registered twice")
	}
	deadLetterRegistry[kind] = factory
}

func deadLetterKnown(kind string) bool {
	deadLetterMu.RLock()
	defer deadLetterMu.RUnlock()
	_, ok := deadLetterRegistry[kind]
	return ok
}

func deadLetterKinds() []string {
	deadLetterMu.RLock()
	defer deadLetterMu.RUnlock()
	kinds := make([]string, 0, len(deadLetterRegistry))
	for k := range deadLetterRegistry {
		kinds = append(kinds, k)
	}
	sort.Strings(kinds)
	return kinds
}

// newDeadLetter builds the dead letter of a trigger; no "dead_letter" object means kind "log".
func newDeadLetter(cfg *DeadLetterConfig) (DeadLetter, error) {
	kind := cfg.kind()
	deadLetterMu.RLock()
	factory := deadLetterRegistry[kind]
	deadLetterMu.RUnlock()
	if factory == nil {
		return nil, fmt.Errorf("unknown dead_letter kind %q", kind)
	}
	raw := json.RawMessage(`{"kind":"log"}`)
	if cfg != nil && len(cfg.Raw) > 0 {
		raw = cfg.Raw
	}
	dl, err := factory(raw)
	if err != nil {
		return nil, fmt.Errorf("dead_letter %s: %w", kind, err)
	}
	return dl, nil
}

// logDeadLetter only logs the event id and the error; the trigger's dead_lettered counter
// counts it.
type logDeadLetter struct{}

func newLogDeadLetter(json.RawMessage) (DeadLetter, error) { return logDeadLetter{}, nil }

func (logDeadLetter) Write(_ context.Context, rec DeadLetterRecord) error {
	log.Printf("trigger %s: dead letter (log only) id=%s type=%s source=%s attempts=%d: %s",
		rec.Trigger, rec.Event.ID, rec.Event.Type, rec.Event.Source, rec.Attempts, rec.Error)
	return nil
}

// redisDeadLetter appends records to a Redis stream (XADD MAXLEN ~ max_len). A record holds the
// source's dead letter fields (the original stream entry, for a redis-stream queue) or else the
// CloudEvent envelope (ce_id, ce_type, ce_source, ce_subject, ce_time, ce_datacontenttype, data),
// the configured meta fields, and runr.dlq.original_id, runr.dlq.trigger, runr.dlq.attempts,
// runr.dlq.error and runr.dlq.failed_at.
//
// An event of a redis-stream source on the same Redis (the record's Ack is a redisx.EntryAck)
// is acknowledged in the same MULTI/EXEC as the XADD — the queue consumer's guarantee: the entry
// is either in the dead-letter stream and acknowledged, or neither, never copied twice by a
// gateway that stopped between the two commands.
type redisDeadLetter struct {
	rdb     *redis.Client
	address string
	stream  string
	maxLen  int64
	meta    map[string]string
}

type redisDeadLetterConfig struct {
	Address  string            `json:"address"`
	Password string            `json:"password"`
	Stream   string            `json:"stream"`
	MaxLen   int64             `json:"max_len"` // 0: 10000; negative: no trimming
	Meta     map[string]string `json:"meta"`    // merged into every record (legacy: runr.dlq.stream, runr.dlq.group)
}

func newRedisDeadLetter(raw json.RawMessage) (DeadLetter, error) {
	var c redisDeadLetterConfig
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	if c.Address == "" {
		return nil, errors.New("address is required")
	}
	if c.Stream == "" {
		return nil, errors.New("stream is required")
	}
	if c.MaxLen == 0 {
		c.MaxLen = 10000
	}
	return &redisDeadLetter{rdb: redisx.NewClient(c.Address, c.Password), address: c.Address, stream: c.Stream, maxLen: c.MaxLen, meta: c.Meta}, nil
}

func (d *redisDeadLetter) Write(ctx context.Context, rec DeadLetterRecord) error {
	values := make(map[string]any, len(rec.Fields)+len(d.meta)+12)
	if rec.Fields != nil {
		for k, v := range rec.Fields {
			values[k] = v
		}
	} else {
		ev := rec.Event
		values["ce_id"] = ev.ID
		values["ce_type"] = ev.Type
		values["ce_source"] = ev.Source
		if ev.Subject != "" {
			values["ce_subject"] = ev.Subject
		}
		values["ce_time"] = ev.Time.UTC().Format(time.RFC3339Nano)
		values["ce_datacontenttype"] = ev.DataContentType
		values["data"] = string(ev.Data)
	}
	for k, v := range d.meta {
		values[k] = v
	}
	values["runr.dlq.original_id"] = rec.Event.ID
	values["runr.dlq.trigger"] = rec.Trigger
	values["runr.dlq.attempts"] = strconv.Itoa(rec.Attempts)
	values["runr.dlq.error"] = truncate(rec.Error, 500)
	values["runr.dlq.failed_at"] = rec.FailedAt.UTC().Format(time.RFC3339)

	args := &redis.XAddArgs{
		Stream: d.stream,
		MaxLen: max(d.maxLen, 0),
		Approx: d.maxLen > 0,
		Values: values,
	}
	if ack, ok := rec.Ack.(redisx.EntryAck); ok && ack.ID != "" && redisx.SameServer(ack.Address, d.address) {
		_, err := d.rdb.TxPipelined(ctx, func(pipe redis.Pipeliner) error {
			pipe.XAdd(ctx, args)
			pipe.XAck(ctx, ack.Stream, ack.Group, ack.ID)
			return nil
		})
		return err
	}
	return d.rdb.XAdd(ctx, args).Err()
}

func (d *redisDeadLetter) Len(ctx context.Context) (int64, error) {
	return d.rdb.XLen(ctx, d.stream).Result()
}

func (d *redisDeadLetter) String() string { return "redis-stream " + d.stream }

func (d *redisDeadLetter) Close() error { return d.rdb.Close() }
