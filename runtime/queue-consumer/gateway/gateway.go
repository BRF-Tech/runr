// Package gateway is the delivery core of the RunR event gateway. It runs one source (package
// source) per trigger and delivers every event a source emits to the trigger's sink — the
// project's Knative Service — as a CloudEvent in HTTP binary mode.
//
// Delivery guarantees:
//   - An event is settled when the sink answered 2xx (redirects are not followed) or when it was
//     written to the trigger's dead letter after its retries. emit returns only then, or when the
//     gateway shuts down, and the source acknowledges the event at its origin only when it is
//     settled; an event in flight at shutdown stays at the origin and is delivered again later.
//     Delivery is at-least-once.
//   - A failed attempt is retried after retry_backoff × 2^(attempt-1), capped at
//     retry_backoff_max, up to max_retries retries (max_retries+1 attempts). Attempts the source
//     reports as made before (source.PriorAttempts, e.g. a broker's redelivery count) count
//     against them.
//   - At most `concurrency` attempts of a trigger run at once. A slot is held during the HTTP
//     request only, so an event waiting for its retry does not hold up the others.
//   - A dead-letter write that fails is retried with backoff; the event is settled only once the
//     record is written.
//   - A source whose Run returns an error (or panics) is started again after a backoff of 1s,
//     doubled up to 30s; the other triggers keep running.
package gateway

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// Gateway runs the triggers of one configuration.
type Gateway struct {
	legacy   *LegacyEnv
	triggers []*trigger
	byName   map[string]*trigger

	restartMin time.Duration // first wait before a failed source is started again
	restartMax time.Duration // cap of that wait; a source that ran this long starts over at restartMin
	dlqReport  time.Duration // how often a changed dead letter length is logged
}

// trigger is a TriggerConfig with its defaults applied, its source, dead letter and counters.
type trigger struct {
	name, typ, kind string
	sink            string
	sinkForLogs     string // sink without URL userinfo
	concurrency     int
	maxRetries      int
	retryBackoff    time.Duration
	retryBackoffMax time.Duration
	timeout         time.Duration

	src    source.Source
	dl     DeadLetter
	dlName string
	client *http.Client
	slots  chan struct{} // one per concurrent attempt
	nudge  chan struct{} // buffered(1): POST /v1/nudge
	m      triggerMetrics
}

// New validates cfg and builds the source and the dead letter of every trigger. Nothing
// connects yet: the sources start in Run.
func New(cfg *Config) (*Gateway, error) {
	if err := cfg.Validate(); err != nil {
		return nil, err
	}
	g := &Gateway{
		legacy:     cfg.Legacy,
		byName:     make(map[string]*trigger, len(cfg.Triggers)),
		restartMin: time.Second,
		restartMax: 30 * time.Second,
		dlqReport:  time.Minute,
	}
	for _, tc := range cfg.Triggers {
		t, err := newTrigger(cfg, tc)
		if err != nil {
			g.close()
			return nil, fmt.Errorf("trigger %s: %w", tc.Name, err)
		}
		g.triggers = append(g.triggers, t)
		g.byName[t.name] = t
	}
	return g, nil
}

func newTrigger(cfg *Config, tc TriggerConfig) (*trigger, error) {
	t := &trigger{
		name:            tc.Name,
		typ:             tc.Type,
		kind:            tc.Kind,
		sink:            tc.Sink,
		concurrency:     max(tc.Concurrency, 1),
		maxRetries:      DefaultMaxRetries,
		retryBackoff:    tc.RetryBackoff.D(),
		retryBackoffMax: tc.RetryBackoffMax.D(),
		timeout:         tc.DeliveryTimeout.D(),
		nudge:           make(chan struct{}, 1),
	}
	if t.sink == "" {
		t.sink = cfg.Sink
	}
	t.sinkForLogs = t.sink
	if u, err := url.Parse(t.sink); err == nil {
		t.sinkForLogs = u.Redacted()
	}
	if tc.MaxRetries != nil {
		t.maxRetries = max(*tc.MaxRetries, 0)
	}
	if t.retryBackoff <= 0 {
		t.retryBackoff = DefaultRetryBackoff
	}
	if t.retryBackoffMax <= 0 {
		t.retryBackoffMax = DefaultRetryBackoffMax
	}
	t.retryBackoffMax = max(t.retryBackoffMax, t.retryBackoff)
	if t.timeout <= 0 {
		t.timeout = cfg.DeliveryTimeout.D()
	}
	if t.timeout <= 0 {
		t.timeout = DefaultDeliveryTimeout
	}
	t.slots = make(chan struct{}, t.concurrency)
	t.client = newDeliveryClient(t.timeout, t.concurrency)

	dl, err := newDeadLetter(tc.DeadLetter)
	if err != nil {
		return nil, err
	}
	t.dl = dl
	t.dlName = tc.DeadLetter.kind()
	if s, ok := dl.(fmt.Stringer); ok {
		t.dlName = s.String()
	}

	src, err := source.New(tc.Kind, tc.Source)
	if err != nil {
		closeIfCloser(dl)
		return nil, err
	}
	t.src = src
	return t, nil
}

// String is the one-line summary main logs at start (no secrets: source names carry none and
// sink URLs lose their userinfo).
func (g *Gateway) String() string {
	parts := make([]string, 0, len(g.triggers))
	for _, t := range g.triggers {
		parts = append(parts, fmt.Sprintf("%s(type=%s source=%s sink=%s concurrency=%d max_retries=%d backoff=%s..%s timeout=%s dead_letter=%s)",
			t.name, t.typ, t.src.Name(), t.sinkForLogs, t.concurrency, t.maxRetries, t.retryBackoff, t.retryBackoffMax, t.timeout, t.dlName))
	}
	return fmt.Sprintf("%d trigger(s): %s", len(g.triggers), strings.Join(parts, ", "))
}

// Run runs every trigger's source until ctx ends, then waits for them to stop and closes the
// sources and dead letters that hold connections. It returns nil after the shutdown.
func (g *Gateway) Run(ctx context.Context) error {
	var wg sync.WaitGroup
	for _, t := range g.triggers {
		wg.Add(2)
		go func() {
			defer wg.Done()
			g.runSource(ctx, t)
		}()
		go func() {
			defer wg.Done()
			g.reportDeadLetter(ctx, t)
		}()
	}
	wg.Wait()
	g.close()
	return nil
}

// runSource runs t's source, restarting it after an error with backoff.
func (g *Gateway) runSource(ctx context.Context, t *trigger) {
	sctx := source.WithConcurrency(ctx, t.concurrency)
	sctx = source.WithTriggerName(sctx, t.name)
	sctx = source.WithNudges(sctx, t.nudge)

	next := g.restartMin
	for {
		began := time.Now()
		err := runOnce(sctx, t)
		if ctx.Err() != nil {
			return
		}
		if err == nil {
			err = errors.New("stopped before shutdown")
		}
		t.m.restarts.Add(1)
		if time.Since(began) >= g.restartMax {
			next = g.restartMin // it ran fine for a while: this is a new failure, not a crash loop
		}
		log.Printf("trigger %s: source %s failed, restarting in %s: %v", t.name, t.src.Name(), next, err)
		if !wait.Sleep(ctx, next) {
			return
		}
		next = min(next*2, g.restartMax)
	}
}

func runOnce(ctx context.Context, t *trigger) (err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("panic: %v", r)
		}
	}()
	return t.src.Run(ctx, t.emit)
}

// reportDeadLetter logs the dead letter's length when it changed, like the queue consumer did.
func (g *Gateway) reportDeadLetter(ctx context.Context, t *trigger) {
	dl, ok := t.dl.(DeadLetterLen)
	if !ok {
		return
	}
	last := int64(-1)
	for wait.Sleep(ctx, g.dlqReport) {
		n, err := dl.Len(ctx)
		if err != nil || n == last {
			continue
		}
		if n > 0 || last > 0 {
			log.Printf("trigger %s: dead letter %s holds %d records", t.name, t.dlName, n)
		}
		last = n
	}
}

// Nudge wakes the source of trigger name, or of every trigger when name is empty; it reports
// whether the trigger exists. A nudge never blocks: nudges that arrive while the source is busy
// collapse into one.
func (g *Gateway) Nudge(name string) bool {
	if name == "" {
		for _, t := range g.triggers {
			t.wake()
		}
		return true
	}
	t, ok := g.byName[name]
	if ok {
		t.wake()
	}
	return ok
}

func (t *trigger) wake() {
	select {
	case t.nudge <- struct{}{}:
	default:
	}
}

func (g *Gateway) close() {
	for _, t := range g.triggers {
		closeIfCloser(t.src)
		closeIfCloser(t.dl)
	}
}

func closeIfCloser(v any) {
	if c, ok := v.(io.Closer); ok {
		c.Close()
	}
}
