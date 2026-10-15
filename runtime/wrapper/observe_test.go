package main

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeCollector is an OTLP/HTTP endpoint that keeps every exported span.
type fakeCollector struct {
	srv   *httptest.Server
	mu    sync.Mutex
	spans []otlpSpan
	res   []otlpKeyValue
}

func newFakeCollector(t *testing.T) *fakeCollector {
	t.Helper()
	c := &fakeCollector{}
	c.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/traces" || r.Header.Get("Content-Type") != "application/json" {
			http.Error(w, "unexpected request", http.StatusBadRequest)
			return
		}
		var req otlpTraces
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		c.mu.Lock()
		for _, rs := range req.ResourceSpans {
			c.res = rs.Resource.Attributes
			for _, ss := range rs.ScopeSpans {
				c.spans = append(c.spans, ss.Spans...)
			}
		}
		c.mu.Unlock()
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(c.srv.Close)
	return c
}

func (c *fakeCollector) all() []otlpSpan {
	c.mu.Lock()
	defer c.mu.Unlock()
	return append([]otlpSpan(nil), c.spans...)
}

func (c *fakeCollector) resource() map[string]string {
	c.mu.Lock()
	defer c.mu.Unlock()
	return attrMap(c.res)
}

// attrMap renders OTLP attributes as strings ("true", "500", "orders").
func attrMap(kvs []otlpKeyValue) map[string]string {
	out := map[string]string{}
	for _, kv := range kvs {
		switch {
		case kv.Value.StringValue != nil:
			out[kv.Key] = *kv.Value.StringValue
		case kv.Value.BoolValue != nil:
			if *kv.Value.BoolValue {
				out[kv.Key] = "true"
			} else {
				out[kv.Key] = "false"
			}
		case kv.Value.IntValue != nil:
			out[kv.Key] = *kv.Value.IntValue
		}
	}
	return out
}

// tracingEnv: tracing on towards the fake collector, ratio 1, a python project revision.
func tracingEnv(c *fakeCollector, extra map[string]string) func(string) string {
	env := map[string]string{
		"RUNR_TRACING":                "1",
		"OTEL_EXPORTER_OTLP_ENDPOINT": c.srv.URL,
		"OTEL_SERVICE_NAME":           "orders",
		"OTEL_RESOURCE_ATTRIBUTES":    "runr.project_id=01JPROJECT",
		"RUNR_PROJECT":                "orders",
		"RUNR_RUNTIME":                "bash",
		"K_REVISION":                  "orders-00002",
	}
	for k, v := range extra {
		env[k] = v
	}
	return envMap(env)
}

// stubInvoker answers every invocation with resp and records the envelope it got.
type stubInvoker struct {
	resp *Response
	mu   sync.Mutex
	got  []*Envelope
}

func (s *stubInvoker) Invoke(_ context.Context, env *Envelope, _ []byte) *Response {
	s.mu.Lock()
	s.got = append(s.got, env)
	s.mu.Unlock()
	h := http.Header{}
	for k, v := range s.resp.Header {
		h[k] = v
	}
	return &Response{Status: s.resp.Status, Header: h, Body: s.resp.Body}
}

func (s *stubInvoker) Close() {}

func httpEnvelope(method, path string, headers map[string]string) *Envelope {
	h := map[string]string{"host": "orders.example.com", "user-agent": "curl/8"}
	for k, v := range headers {
		h[k] = v
	}
	return &Envelope{Runr: 1, Trigger: triggerHTTP, ID: "req-1", Method: method, Path: path, Query: map[string]string{}, Headers: h}
}

func TestWrapIsANoOpWithoutTracingOrReplay(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, "cat")
	o := setupObservability(cfg, envMap(map[string]string{}))
	inner := &stubInvoker{resp: &Response{Status: 200}}
	if o.active() || o.wrap(inner) != invoker(inner) {
		t.Fatal("without RUNR_TRACING / RUNR_REPLAY_URL the invoker must stay as it is")
	}
}

func TestServerSpanPerInvocation(t *testing.T) {
	c := newFakeCollector(t)
	cfg, _, stderr := testConfig(t, modeExec, "cat")
	o := setupObservability(cfg, tracingEnv(c, nil))
	inner := &stubInvoker{resp: &Response{Status: 201, Header: http.Header{}, Body: []byte("created")}}
	inv := o.wrap(inner)

	parent := "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"
	resp := inv.Invoke(context.Background(), httpEnvelope("POST", "/orders", map[string]string{"traceparent": parent}), []byte(`{"id":1}`))
	inv.Invoke(context.Background(), httpEnvelope("GET", "/orders/1", nil), nil)
	o.shutdown()

	spans := c.all()
	if len(spans) != 2 {
		t.Fatalf("2 invocations → 2 spans, got %d", len(spans))
	}

	first := spans[0]
	if first.TraceID != "4bf92f3577b34da6a3ce929d0e0e4736" || first.ParentSpanID != "00f067aa0ba902b7" {
		t.Errorf("the incoming trace is continued: trace %s parent %s", first.TraceID, first.ParentSpanID)
	}
	if first.Kind != spanKindServer || first.Name != "POST" {
		t.Errorf("kind %d name %q", first.Kind, first.Name)
	}
	attrs := attrMap(first.Attributes)
	for k, v := range map[string]string{
		"runr.project":              "orders",
		"runr.revision":             "orders-00002",
		"runr.runtime":              "bash",
		"runr.trigger":              "http",
		"runr.cold_start":           "true",
		"runr.status":               "ok",
		"faas.coldstart":            "true",
		"faas.trigger":              "http",
		"faas.invocation_id":        "req-1",
		"http.request.method":       "POST",
		"url.path":                  "/orders",
		"http.response.status_code": "201",
		"server.address":            "orders.example.com",
		"user_agent.original":       "curl/8",
	} {
		if attrs[k] != v {
			t.Errorf("attribute %s = %q, want %q", k, attrs[k], v)
		}
	}

	second := attrMap(spans[1].Attributes)
	if second["runr.cold_start"] != "false" {
		t.Errorf("only the first invocation of the process is a cold start: %q", second["runr.cold_start"])
	}
	if spans[1].ParentSpanID != "" || spans[1].TraceID == first.TraceID {
		t.Errorf("an invocation without traceparent starts a new trace: %+v", spans[1])
	}

	// propagation out: the handler sees the wrapper span as its parent, the client gets traceresponse
	sent, ok := parseTraceparent(inner.got[0].Headers["traceparent"])
	if !ok || sent.TraceID.String() != first.TraceID || sent.SpanID.String() != first.SpanID || !sent.Sampled {
		t.Errorf("traceparent to the handler %q, span %s/%s", inner.got[0].Headers["traceparent"], first.TraceID, first.SpanID)
	}
	if resp.Header.Get("Traceresponse") != inner.got[0].Headers["traceparent"] {
		t.Errorf("traceresponse %q", resp.Header.Get("Traceresponse"))
	}

	if res := c.resource(); res["service.name"] != "orders" || res["runr.project_id"] != "01JPROJECT" {
		t.Errorf("resource %v", res)
	}

	if !strings.Contains(stderr.String(), "trace_id="+first.TraceID+" span_id="+first.SpanID+" sampled=true POST /orders 201") {
		t.Errorf("access line with the trace id missing:\n%s", stderr.String())
	}
}

func TestErrorAndTimeoutSpans(t *testing.T) {
	c := newFakeCollector(t)
	cfg, _, _ := testConfig(t, modeExec, "cat")
	o := setupObservability(cfg, tracingEnv(c, nil))

	o.wrap(&stubInvoker{resp: errorResponse(500, "database unreachable")}).Invoke(context.Background(), httpEnvelope("GET", "/", nil), nil)
	o.wrap(&stubInvoker{resp: errorResponse(504, "handler timed out after 30s")}).Invoke(context.Background(), httpEnvelope("GET", "/slow", nil), nil)
	o.shutdown()

	spans := c.all()
	if len(spans) != 2 {
		t.Fatalf("spans: %d", len(spans))
	}

	if spans[0].Status.Code != statusError || attrMap(spans[0].Attributes)["runr.status"] != "error" {
		t.Errorf("5xx span: %+v", spans[0])
	}
	if len(spans[0].Events) != 1 || spans[0].Events[0].Name != "exception" || attrMap(spans[0].Events[0].Attributes)["exception.message"] != "database unreachable" {
		t.Errorf("exception event: %+v", spans[0].Events)
	}

	if attrMap(spans[1].Attributes)["runr.status"] != "timeout" || spans[1].Status.Code != statusError {
		t.Errorf("timeout span: %+v", spans[1])
	}
	if attrMap(spans[1].Events[0].Attributes)["exception.type"] != "timeout" {
		t.Errorf("timeout event: %+v", spans[1].Events)
	}
}

func TestSamplingDecision(t *testing.T) {
	c := newFakeCollector(t)
	cfg, _, stderr := testConfig(t, modeExec, "cat")

	// ratio 0: only a sampled parent gets a span; the access line is written either way
	o := setupObservability(cfg, tracingEnv(c, map[string]string{"OTEL_TRACES_SAMPLER": "traceidratio", "OTEL_TRACES_SAMPLER_ARG": "0"}))
	inner := &stubInvoker{resp: &Response{Status: 200}}
	inv := o.wrap(inner)
	inv.Invoke(context.Background(), httpEnvelope("GET", "/a", nil), nil)
	inv.Invoke(context.Background(), httpEnvelope("GET", "/b", map[string]string{"traceparent": "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"}), nil)
	o.shutdown()

	spans := c.all()
	if len(spans) != 1 || attrMap(spans[0].Attributes)["url.path"] != "/b" {
		t.Fatalf("only the sampled parent's invocation is exported: %+v", spans)
	}
	if !strings.Contains(stderr.String(), "sampled=false GET /a 200") {
		t.Errorf("unsampled invocation still logs its trace id:\n%s", stderr.String())
	}
	if sc, _ := parseTraceparent(inner.got[0].Headers["traceparent"]); sc.Sampled {
		t.Error("an unsampled invocation propagates flags 00")
	}

	// ratio 1: an unsampled parent (queue-proxy at a low rate) does not switch tracing off
	c2 := newFakeCollector(t)
	o2 := setupObservability(cfg, tracingEnv(c2, nil))
	o2.wrap(&stubInvoker{resp: &Response{Status: 200}}).Invoke(context.Background(),
		httpEnvelope("GET", "/c", map[string]string{"traceparent": "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00"}), nil)
	o2.shutdown()
	if got := c2.all(); len(got) != 1 || got[0].TraceID != "4bf92f3577b34da6a3ce929d0e0e4736" {
		t.Errorf("unsampled parent at ratio 1: %+v", got)
	}
}

func TestQueueAndScheduleSpans(t *testing.T) {
	c := newFakeCollector(t)
	cfg, _, _ := testConfig(t, modeExec, "cat")
	o := setupObservability(cfg, tracingEnv(c, nil))
	inv := o.wrap(&stubInvoker{resp: &Response{Status: 200}})

	queue := httpEnvelope("POST", "/", map[string]string{"ce-type": "dev.runr.queue.message"})
	queue.Trigger = triggerQueue
	inv.Invoke(context.Background(), queue, nil)
	inv.Invoke(context.Background(), scheduleEnvelope(time.Now()), nil)
	o.shutdown()

	spans := c.all()
	if len(spans) != 2 {
		t.Fatalf("spans %d", len(spans))
	}
	q, s := attrMap(spans[0].Attributes), attrMap(spans[1].Attributes)
	if spans[0].Name != "queue message" || q["faas.trigger"] != "pubsub" || q["runr.trigger"] != "queue" || q["cloudevents.event_type"] != "dev.runr.queue.message" {
		t.Errorf("queue span %q %v", spans[0].Name, q)
	}
	if spans[1].Name != "schedule tick" || s["faas.trigger"] != "timer" || s["runr.trigger"] != "schedule" {
		t.Errorf("schedule span %q %v", spans[1].Name, s)
	}
}

func TestHTTPModeScheduleTickIsTraced(t *testing.T) {
	c := newFakeCollector(t)
	cfg, _, _ := testConfig(t, modeHTTP, "true")
	cfg.Trigger = triggerSchedule
	o := setupObservability(cfg, tracingEnv(c, nil))

	var seen string
	code := o.traceTick(cfg, func(cfg config) int {
		seen = envValue(cfg.Env, tickTraceparentEnv)
		return 1
	})
	o.shutdown()

	spans := c.all()
	if code != 1 || len(spans) != 1 {
		t.Fatalf("code %d spans %d", code, len(spans))
	}
	if sc, ok := parseTraceparent(seen); !ok || sc.SpanID.String() != spans[0].SpanID {
		t.Errorf("the tick gets the span's traceparent: %q vs %s", seen, spans[0].SpanID)
	}
	if spans[0].Status.Code != statusError || spans[0].Name != "schedule tick" {
		t.Errorf("a failed tick is an error span: %+v", spans[0])
	}
}

func TestExporterRetriesNothingAndNeverBlocks(t *testing.T) {
	// a collector that answers 500: the batch is dropped after logging, invocations go on
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body)
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	cfg, _, _ := testConfig(t, modeExec, "cat")
	o := setupObservability(cfg, envMap(map[string]string{"RUNR_TRACING": "1", "OTEL_EXPORTER_OTLP_ENDPOINT": srv.URL}))
	inv := o.wrap(&stubInvoker{resp: &Response{Status: 200}})

	start := time.Now()
	for i := 0; i < exportQueueSize+10; i++ {
		inv.Invoke(context.Background(), httpEnvelope("GET", "/", nil), nil)
	}
	if time.Since(start) > 5*time.Second {
		t.Error("exporting must not slow invocations down")
	}
	o.shutdown()
}
