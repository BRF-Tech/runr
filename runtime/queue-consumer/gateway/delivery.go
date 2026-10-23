package gateway

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/wait"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

// newDeliveryClient is the HTTP client of a trigger's deliveries: timeout is the timeout of one
// attempt, and redirects are not followed.
func newDeliveryClient(timeout time.Duration, concurrency int) *http.Client {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.MaxIdleConnsPerHost = max(concurrency, 2)
	return &http.Client{
		Timeout:   timeout,
		Transport: transport,
		// A redirect is not an acknowledgement: following it would turn the POST into a
		// body-less GET whose 2xx settled an event the sink never received.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}

// emit is the function the trigger's source hands its events to (see package source). It
// returns nil when the sink answered 2xx, an error wrapping source.ErrDeadLettered when the event
// used up its retries and was written to the dead letter, and ctx.Err() when the gateway shuts
// down first (the event is not settled; an interrupted attempt is not counted as failed).
func (t *trigger) emit(ctx context.Context, ev source.Event) error {
	if ev.Time.IsZero() {
		ev.Time = time.Now() // fixed once, so every attempt carries the same ce-time
	}
	prior := source.PriorAttempts(ctx)
	if prior > t.maxRetries {
		// An earlier run used up the retries and stopped before the dead-letter write.
		return t.deadLetter(ctx, ev, prior, errors.New("retries exhausted"))
	}
	for n := prior + 1; ; n++ {
		err := t.attempt(ctx, ev, n)
		if err == nil {
			t.m.delivered.Add(1)
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		t.m.failed.Add(1)
		if n > t.maxRetries {
			return t.deadLetter(ctx, ev, n, err)
		}
		next := t.backoff(n)
		log.Printf("trigger %s: delivery failed id=%s attempt=%d/%d, retry in %s: %v",
			t.name, ev.ID, n, t.maxRetries+1, next, err)
		if !wait.Sleep(ctx, next) {
			return ctx.Err()
		}
	}
}

// backoff is the wait after failed attempt n (1-based): retry_backoff × 2^(n-1), capped at
// retry_backoff_max.
func (t *trigger) backoff(n int) time.Duration {
	d := t.retryBackoff
	for i := 1; i < n && d < t.retryBackoffMax; i++ {
		d *= 2
	}
	return min(d, t.retryBackoffMax)
}

// attempt makes delivery attempt n of ev in one of the trigger's concurrency slots.
func (t *trigger) attempt(ctx context.Context, ev source.Event, n int) error {
	select {
	case t.slots <- struct{}{}:
	case <-ctx.Done():
		return ctx.Err()
	}
	defer func() { <-t.slots }()

	t.m.inFlight.Add(1)
	defer t.m.inFlight.Add(-1)
	if n > 1 {
		t.m.retried.Add(1)
	}
	began := time.Now()
	err := t.post(ctx, ev, n)
	t.m.observe(time.Since(began))
	return err
}

// post sends ev to the sink as a CloudEvent in HTTP binary mode: the attributes as ce-* headers,
// Data as the body. Only a 2xx answer is a delivery.
func (t *trigger) post(ctx context.Context, ev source.Event, attempt int) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, t.sink, bytes.NewReader(ev.Data))
	if err != nil {
		return fmt.Errorf("new request: %w", err)
	}
	setCloudEventHeaders(req.Header, ev)
	req.Header.Set("X-RunR-Delivery-Attempt", strconv.Itoa(attempt))
	req.Header.Set("X-RunR-Trigger", t.name)
	if s := activeSigner(); s != nil {
		if err := s.Sign(req, ev.Data); err != nil {
			return fmt.Errorf("sign: %w", err)
		}
	}

	resp, err := t.client.Do(req)
	if err != nil {
		return fmt.Errorf("POST %s: %w", t.sinkForLogs, err)
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("POST %s: status %d", t.sinkForLogs, resp.StatusCode)
	}
	return nil
}

// setCloudEventHeaders writes the CloudEvents 1.0 binary-mode headers of ev. The id stays the
// same on every attempt, so a sink can recognise a repeated delivery. Values are percent-encoded
// where the HTTP binding asks for it (space, '"', '%' and bytes outside printable ASCII).
func setCloudEventHeaders(h http.Header, ev source.Event) {
	h.Set("ce-specversion", "1.0")
	h.Set("ce-id", ceValue(ev.ID))
	h.Set("ce-type", ceValue(ev.Type))
	h.Set("ce-source", ceValue(ev.Source))
	h.Set("ce-time", ev.Time.UTC().Format(time.RFC3339Nano))
	if ev.Subject != "" {
		h.Set("ce-subject", ceValue(ev.Subject))
	}
	for name, v := range ev.Extensions {
		if validExtension(name) {
			h.Set("ce-"+name, ceValue(v))
		}
	}
	ct := ev.DataContentType
	if ct == "" {
		ct = "application/json"
	}
	h.Set("Content-Type", ct)
}

var extensionNameRE = regexp.MustCompile(`^[a-z0-9]{1,20}$`)

// validExtension reports whether name is a CloudEvents extension attribute name (lowercase
// a-z0-9, at most 20 characters) that does not shadow a context attribute.
func validExtension(name string) bool {
	switch name {
	case "specversion", "id", "type", "source", "subject", "time", "datacontenttype", "dataschema", "data":
		return false
	}
	return extensionNameRE.MatchString(name)
}

func ceValue(v string) string {
	var b strings.Builder
	for i := 0; i < len(v); i++ {
		c := v[i]
		if c <= ' ' || c >= 0x7f || c == '"' || c == '%' {
			fmt.Fprintf(&b, "%%%02X", c)
			continue
		}
		b.WriteByte(c)
	}
	return b.String()
}

// deadLetter writes ev to the trigger's dead letter after its last failed attempt. A failing
// write is retried with the trigger's backoff until it succeeds or the gateway shuts down; the
// event is settled only once the record is written.
func (t *trigger) deadLetter(ctx context.Context, ev source.Event, attempts int, cause error) error {
	rec := DeadLetterRecord{
		Trigger:  t.name,
		Event:    ev,
		Fields:   source.DeadLetterFields(ctx),
		Ack:      source.DeadLetterAck(ctx),
		Attempts: attempts,
		Error:    truncate(cause.Error(), 500),
		FailedAt: time.Now().UTC(),
	}
	for i := 1; ; i++ {
		err := t.dl.Write(ctx, rec)
		if err == nil {
			t.m.deadLettered.Add(1)
			log.Printf("trigger %s: dead-lettered id=%s after %d attempts to %s: %v", t.name, ev.ID, attempts, t.dlName, cause)
			return fmt.Errorf("%w after %d attempts: %v", source.ErrDeadLettered, attempts, cause)
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		next := t.backoff(i)
		log.Printf("trigger %s: dead-letter write of id=%s failed, retry in %s: %v", t.name, ev.ID, next, err)
		if !wait.Sleep(ctx, next) {
			return ctx.Err()
		}
	}
}

// truncate cuts s to at most n bytes without splitting a UTF-8 sequence.
func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	for n > 0 && !utf8.RuneStart(s[n]) {
		n--
	}
	return s[:n]
}
