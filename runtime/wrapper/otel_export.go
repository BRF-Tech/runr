package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"sync"
	"time"
)

const (
	exportQueueSize = 2048
	exportBatchSize = 256
	exportInterval  = time.Second
	exportTimeout   = 5 * time.Second
)

// exporter batches finished spans and POSTs them as OTLP/HTTP JSON (the encoding every
// OpenTelemetry Collector's otlp receiver accepts on /v1/traces). A full queue drops spans; an
// export error drops the batch after logging it — tracing never holds up an invocation.
type exporter struct {
	url      string
	headers  map[string]string
	resource []attribute
	client   *http.Client

	mu      sync.Mutex
	queue   []*span
	dropped int

	kick chan struct{}
	stop chan struct{}
	done chan struct{}
}

func newExporter(cfg traceConfig) *exporter {
	e := &exporter{
		url:      cfg.Endpoint,
		headers:  cfg.Headers,
		resource: cfg.Resource,
		client:   &http.Client{Timeout: exportTimeout},
		kick:     make(chan struct{}, 1),
		stop:     make(chan struct{}),
		done:     make(chan struct{}),
	}
	go e.loop()
	return e
}

// enqueue adds a finished span to the next batch.
func (e *exporter) enqueue(s *span) {
	e.mu.Lock()
	if len(e.queue) >= exportQueueSize {
		e.dropped++
		e.mu.Unlock()
		return
	}
	e.queue = append(e.queue, s)
	full := len(e.queue) >= exportBatchSize
	e.mu.Unlock()

	if full {
		select {
		case e.kick <- struct{}{}:
		default:
		}
	}
}

func (e *exporter) loop() {
	defer close(e.done)
	ticker := time.NewTicker(exportInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ticker.C:
			e.flush(context.Background())
		case <-e.kick:
			e.flush(context.Background())
		case <-e.stop:
			return
		}
	}
}

// flush sends everything queued, in batches.
func (e *exporter) flush(ctx context.Context) {
	for {
		e.mu.Lock()
		n := min(len(e.queue), exportBatchSize)
		batch := e.queue[:n:n]
		e.queue = append([]*span(nil), e.queue[n:]...)
		dropped := e.dropped
		e.dropped = 0
		e.mu.Unlock()

		if dropped > 0 {
			logger.Printf("tracing: export queue full, %d span(s) dropped", dropped)
		}
		if n == 0 {
			return
		}
		if err := e.send(ctx, batch); err != nil {
			logger.Printf("tracing: export of %d span(s) failed: %v", n, err)
		}
	}
}

// shutdown stops the background loop and sends what is left within timeout.
func (e *exporter) shutdown(timeout time.Duration) {
	close(e.stop)
	<-e.done
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	e.flush(ctx)
}

func (e *exporter) send(ctx context.Context, batch []*span) error {
	body, err := json.Marshal(otlpRequest(e.resource, batch))
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, e.url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	for k, v := range e.headers {
		req.Header.Set(k, v)
	}

	resp, err := e.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 64<<10))

	if resp.StatusCode >= 300 {
		return fmt.Errorf("collector answered %d", resp.StatusCode)
	}
	return nil
}

// ── OTLP JSON encoding (opentelemetry-proto, JSON mapping: hex ids, int64 as strings) ─────────

type otlpTraces struct {
	ResourceSpans []otlpResourceSpans `json:"resourceSpans"`
}

type otlpResourceSpans struct {
	Resource   otlpResource     `json:"resource"`
	ScopeSpans []otlpScopeSpans `json:"scopeSpans"`
}

type otlpResource struct {
	Attributes []otlpKeyValue `json:"attributes"`
}

type otlpScopeSpans struct {
	Scope otlpScope  `json:"scope"`
	Spans []otlpSpan `json:"spans"`
}

type otlpScope struct {
	Name    string `json:"name"`
	Version string `json:"version"`
}

type otlpSpan struct {
	TraceID           string         `json:"traceId"`
	SpanID            string         `json:"spanId"`
	ParentSpanID      string         `json:"parentSpanId,omitempty"`
	Name              string         `json:"name"`
	Kind              int            `json:"kind"`
	StartTimeUnixNano string         `json:"startTimeUnixNano"`
	EndTimeUnixNano   string         `json:"endTimeUnixNano"`
	Attributes        []otlpKeyValue `json:"attributes"`
	Events            []otlpEvent    `json:"events,omitempty"`
	Status            otlpStatus     `json:"status"`
}

type otlpEvent struct {
	TimeUnixNano string         `json:"timeUnixNano"`
	Name         string         `json:"name"`
	Attributes   []otlpKeyValue `json:"attributes,omitempty"`
}

type otlpStatus struct {
	Code    int    `json:"code"`
	Message string `json:"message,omitempty"`
}

type otlpKeyValue struct {
	Key   string    `json:"key"`
	Value otlpValue `json:"value"`
}

type otlpValue struct {
	StringValue *string  `json:"stringValue,omitempty"`
	BoolValue   *bool    `json:"boolValue,omitempty"`
	IntValue    *string  `json:"intValue,omitempty"`
	DoubleValue *float64 `json:"doubleValue,omitempty"`
}

func otlpRequest(resource []attribute, spans []*span) otlpTraces {
	out := make([]otlpSpan, 0, len(spans))
	for _, s := range spans {
		js := otlpSpan{
			TraceID:           s.TraceID.String(),
			SpanID:            s.SpanID.String(),
			Name:              s.Name,
			Kind:              s.Kind,
			StartTimeUnixNano: strconv.FormatInt(s.Start.UnixNano(), 10),
			EndTimeUnixNano:   strconv.FormatInt(s.End.UnixNano(), 10),
			Attributes:        otlpAttributes(s.Attrs),
			Status:            otlpStatus{Code: s.StatusCode, Message: s.StatusMessage},
		}
		if !s.ParentSpanID.isZero() {
			js.ParentSpanID = s.ParentSpanID.String()
		}
		for _, ev := range s.Events {
			js.Events = append(js.Events, otlpEvent{
				TimeUnixNano: strconv.FormatInt(ev.Time.UnixNano(), 10),
				Name:         ev.Name,
				Attributes:   otlpAttributes(ev.Attrs),
			})
		}
		out = append(out, js)
	}

	return otlpTraces{ResourceSpans: []otlpResourceSpans{{
		Resource: otlpResource{Attributes: otlpAttributes(resource)},
		ScopeSpans: []otlpScopeSpans{{
			Scope: otlpScope{Name: "runr-wrapper", Version: version},
			Spans: out,
		}},
	}}}
}

func otlpAttributes(attrs []attribute) []otlpKeyValue {
	out := make([]otlpKeyValue, 0, len(attrs))
	for _, a := range attrs {
		var v otlpValue
		switch val := a.Value.(type) {
		case string:
			v.StringValue = &val
		case bool:
			v.BoolValue = &val
		case int64:
			s := strconv.FormatInt(val, 10)
			v.IntValue = &s
		case int:
			s := strconv.Itoa(val)
			v.IntValue = &s
		case float64:
			v.DoubleValue = &val
		default:
			s := fmt.Sprint(val)
			v.StringValue = &s
		}
		out = append(out, otlpKeyValue{Key: a.Key, Value: v})
	}
	return out
}
