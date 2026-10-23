// Package source is the extension point of the RunR event gateway. A Source reads the events of
// one origin (a Redis stream, the webhook inbox, a cron schedule, a broker, ...) and hands each
// one to the gateway's delivery core through emit; the core delivers it to the trigger's sink as
// a CloudEvent, retries it and dead-letters it.
//
// A source package registers its kind in init():
//
//	func init() { source.Register("redis-stream", New) }
//
// and the gateway binary blank-imports the package. The core builds one Source per trigger with
// New(kind, cfg), where cfg is the trigger's "source" object from gateway.json, verbatim.
//
// Acknowledgement contract: emit blocks until the event is settled — delivered to the sink with
// a 2xx, or written to the trigger's dead letter after its retries — or until ctx ends. A source
// acknowledges / commits an event at its origin exactly when Settled(emit(...)) is true. Any other
// error (the context ending at shutdown) means the event is not settled: the source leaves it at
// the origin for redelivery. Delivery is therefore at-least-once: an event can reach the sink
// twice (a crash between the sink's 2xx and the acknowledgement), never zero times.
//
// emit is safe for concurrent use. The core admits at most Concurrency(ctx) delivery attempts of
// a trigger at once; a slot is held during the HTTP request only, not during the backoff between
// retries. A source that wants parallel deliveries calls emit from several goroutines and bounds
// how many events it holds unsettled (e.g. 2×Concurrency(ctx)) so it does not read far ahead.
//
// A Source may also implement
//
//	Stats(ctx context.Context) map[string]any
//
// whose keys the gateway adds to the trigger's entry of GET /stats (the redis-stream source
// reports its group's pending count), and io.Closer, which the gateway calls once at shutdown.
package source

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"sync"
	"time"
)

// Event is one event of an origin, delivered as a CloudEvent (HTTP binary mode): the attributes
// become ce-* headers, DataContentType the Content-Type and Data the request body.
type Event struct {
	ID, Type, Source, Subject, DataContentType string
	Time                                       time.Time
	Data                                       []byte
	Extensions                                 map[string]string // CloudEvents extension attributes (lowercase a-z0-9, ≤ 20 chars)
}

// Source reads the events of one trigger's origin.
type Source interface {
	// Name describes the source for logs (never a secret: no password, no token).
	Name() string
	// Run reads events until ctx ends and hands each one to emit. It returns nil on ctx end;
	// any other error makes the gateway log it and call Run again after a backoff. Run does not
	// return before every emit it started has returned.
	Run(ctx context.Context, emit func(context.Context, Event) error) error
}

var (
	registryMu sync.RWMutex
	registry   = map[string]func(cfg json.RawMessage) (Source, error){}
)

// Register makes a source kind available to New. It panics when the kind is empty, the factory
// is nil or the kind is registered twice (both are programming errors of an init function).
func Register(kind string, factory func(cfg json.RawMessage) (Source, error)) {
	if kind == "" || factory == nil {
		panic("source: Register needs a kind and a factory")
	}
	registryMu.Lock()
	defer registryMu.Unlock()
	if _, dup := registry[kind]; dup {
		panic("source: kind " + kind + " registered twice")
	}
	registry[kind] = factory
}

// New builds a source of the registered kind from its configuration (the trigger's "source"
// object; empty or null is passed on as {}). An unknown kind is an error.
func New(kind string, cfg json.RawMessage) (Source, error) {
	registryMu.RLock()
	factory := registry[kind]
	registryMu.RUnlock()
	if factory == nil {
		return nil, fmt.Errorf("source: unknown kind %q (known: %s)", kind, strings.Join(Kinds(), ", "))
	}
	if len(cfg) == 0 || string(cfg) == "null" {
		cfg = json.RawMessage("{}")
	}
	s, err := factory(cfg)
	if err != nil {
		return nil, fmt.Errorf("source %s: %w", kind, err)
	}
	return s, nil
}

// Kinds lists the registered source kinds, sorted.
func Kinds() []string {
	registryMu.RLock()
	defer registryMu.RUnlock()
	kinds := make([]string, 0, len(registry))
	for k := range registry {
		kinds = append(kinds, k)
	}
	sort.Strings(kinds)
	return kinds
}

// ErrDeadLettered is what emit returns (possibly wrapped, with the last delivery error) when the
// event used up its retries and was written to the trigger's dead letter. The event is settled:
// the source acknowledges it like a delivered one.
var ErrDeadLettered = errors.New("source: event dead-lettered")

// Settled reports whether emit's result settles the event — delivered (nil) or dead-lettered —
// so the source acknowledges it at its origin. Any other error leaves the event for redelivery.
func Settled(err error) bool {
	return err == nil || errors.Is(err, ErrDeadLettered)
}

type ctxKey int

const (
	concurrencyKey ctxKey = iota
	triggerNameKey
	nudgesKey
	priorAttemptsKey
	deadLetterFieldsKey
	deadLetterAckKey
)

// WithConcurrency is set by the core on Run's ctx: the trigger's concurrency.
func WithConcurrency(ctx context.Context, n int) context.Context {
	return context.WithValue(ctx, concurrencyKey, n)
}

// Concurrency is the trigger's concurrency (at least 1), so a source can size its in-flight
// window.
func Concurrency(ctx context.Context) int {
	if n, ok := ctx.Value(concurrencyKey).(int); ok && n >= 1 {
		return n
	}
	return 1
}

// WithTriggerName is set by the core on Run's ctx: the trigger's name.
func WithTriggerName(ctx context.Context, name string) context.Context {
	return context.WithValue(ctx, triggerNameKey, name)
}

// TriggerName is the name of the trigger the source runs for ("" outside the core).
func TriggerName(ctx context.Context) string {
	name, _ := ctx.Value(triggerNameKey).(string)
	return name
}

// WithNudges is set by the core on Run's ctx: the channel that receives when
// POST /v1/nudge?trigger=<name> (or POST /v1/nudge for all triggers) arrives.
func WithNudges(ctx context.Context, ch <-chan struct{}) context.Context {
	return context.WithValue(ctx, nudgesKey, ch)
}

// Nudges receives when someone asks the trigger to look for new events now (the API stored a
// webhook event, say). The core sends without blocking into a channel buffered for one nudge, so
// nudges that arrive while the source is busy collapse into one. Without a nudge channel on ctx
// it returns nil, which never receives.
func Nudges(ctx context.Context) <-chan struct{} {
	ch, _ := ctx.Value(nudgesKey).(<-chan struct{})
	return ch
}

// WithPriorAttempts is set by a source on the ctx it passes to emit: the deliveries already
// made before this one (a broker's redelivery count). The core counts them against max_retries:
// the first attempt is numbered n+1, and an event whose n already exceeds max_retries is
// dead-lettered without another attempt.
func WithPriorAttempts(ctx context.Context, n int) context.Context {
	return context.WithValue(ctx, priorAttemptsKey, n)
}

// PriorAttempts is the source's count of earlier deliveries of the event (0 when unset).
func PriorAttempts(ctx context.Context) int {
	if n, ok := ctx.Value(priorAttemptsKey).(int); ok && n > 0 {
		return n
	}
	return 0
}

// WithDeadLetterFields is set by a source on the ctx it passes to emit: the record a dead letter
// stores for the event instead of its CloudEvent envelope (the redis-stream source passes the
// original stream entry fields, so the DLQ keeps the queue consumer's record shape).
func WithDeadLetterFields(ctx context.Context, fields map[string]string) context.Context {
	return context.WithValue(ctx, deadLetterFieldsKey, fields)
}

// DeadLetterFields is the record set with WithDeadLetterFields (nil when unset).
func DeadLetterFields(ctx context.Context) map[string]string {
	fields, _ := ctx.Value(deadLetterFieldsKey).(map[string]string)
	return fields
}

// WithDeadLetterAck is set by a source on the ctx it passes to emit: how the event is
// acknowledged at its origin, for a dead letter that can do it in the same transaction as its
// write (the redis-stream source passes a redisx.EntryAck, which a redis-stream dead letter on the
// same Redis runs as XADD + XACK in one MULTI/EXEC). A dead letter that does not know the value
// ignores it; the source acknowledges a dead-lettered event itself either way (a second XACK of
// an entry is a no-op).
func WithDeadLetterAck(ctx context.Context, ack any) context.Context {
	return context.WithValue(ctx, deadLetterAckKey, ack)
}

// DeadLetterAck is the value set with WithDeadLetterAck (nil when unset).
func DeadLetterAck(ctx context.Context) any {
	return ctx.Value(deadLetterAckKey)
}
