package main

// Observability of invocations: tracing (otel.go) and failed-request capture for replay
// (replay.go). Hooked in at two places only: the invoker of exec / persistent mode is wrapped
// (observedInvoker) and an http-mode runtime is fronted by a reverse proxy (proxy.go) instead of
// being exec'd — both only when RunR turned tracing or replay capture on for the project.

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync/atomic"
	"time"
)

const (
	shutdownFlush = 3 * time.Second
	maxErrorEvent = 512
)

// observability is the tracing and replay state of the wrapper process.
type observability struct {
	trace  traceConfig
	exp    *exporter // nil: tracing off
	replay *replayer // nil: replay capture off
	log    io.Writer // access lines with the trace id

	invocations atomic.Int64 // started invocations: the first one is the cold start
	now         func() time.Time
}

// setupObservability reads the tracing and replay configuration; both off is the default.
func setupObservability(cfg config, getenv func(string) string) *observability {
	o := &observability{trace: loadTraceConfig(getenv), log: cfg.Stderr, now: time.Now}
	if o.log == nil {
		o.log = io.Discard
	}
	if o.trace.Enabled {
		o.exp = newExporter(o.trace)
	}
	if rc, ok := loadReplayConfig(getenv); ok {
		o.replay = newReplayer(rc)
	}
	return o
}

// active: tracing or replay capture is on.
func (o *observability) active() bool {
	return o != nil && (o.exp != nil || o.replay != nil)
}

// shutdown sends the spans and captures still queued.
func (o *observability) shutdown() {
	if o == nil {
		return
	}
	if o.exp != nil {
		o.exp.shutdown(shutdownFlush)
	}
	if o.replay != nil {
		o.replay.shutdown(shutdownFlush)
	}
}

// wrap adds tracing / capture to an invoker; the invoker itself when both are off.
func (o *observability) wrap(inner invoker) invoker {
	if !o.active() {
		return inner
	}
	return &observedInvoker{inner: inner, o: o}
}

// request is what tracing and capture need to know about one invocation.
type request struct {
	Trigger  string
	ID       string
	Method   string
	Path     string
	RawQuery string
	Host     string
	Header   http.Header
	Body     []byte
}

// invocation is one observed invocation between begin and finish.
type invocation struct {
	o         *observability
	req       request
	start     time.Time
	sc        spanContext // the invocation's own span, propagated downstream
	parent    spanID
	hasParent bool
	cold      bool
}

// begin starts an invocation: continues the incoming trace (traceparent) or starts one, and
// decides sampling — a sampled parent is followed, otherwise the trace id ratio decides.
func (o *observability) begin(req request) *invocation {
	inv := &invocation{o: o, req: req, start: o.now(), cold: o.invocations.Add(1) == 1}

	if parent, ok := parseTraceparent(req.Header.Get("Traceparent")); ok {
		inv.hasParent = true
		inv.parent = parent.SpanID
		inv.sc.TraceID = parent.TraceID
		inv.sc.Sampled = parent.Sampled || ratioSampled(parent.TraceID, o.trace.Ratio)
	} else {
		inv.sc.TraceID = newTraceID()
		inv.sc.Sampled = ratioSampled(inv.sc.TraceID, o.trace.Ratio)
	}
	inv.sc.SpanID = newSpanID()
	if o.exp == nil {
		inv.sc.Sampled = false
	}

	return inv
}

// traceparent to send downstream (the runtime's server or the handler process).
func (inv *invocation) traceparent() string { return inv.sc.traceparent() }

// tracing reports whether spans are exported at all (the per-invocation decision is Sampled).
func (inv *invocation) tracing() bool { return inv.o.exp != nil }

// finish ends the invocation with the response: exports the span (when sampled), writes the
// access line with the trace id and captures a failed request (5xx or timeout) for replay.
func (inv *invocation) finish(status int, header http.Header, body []byte, timedOut bool) {
	o := inv.o
	end := o.now()
	dur := end.Sub(inv.start)

	outcome := "ok"
	switch {
	case timedOut:
		outcome = "timeout"
	case status >= 500:
		outcome = "error"
	}

	if o.exp != nil {
		if inv.sc.Sampled {
			o.exp.enqueue(inv.span(status, outcome, body, end))
		}
		fmt.Fprintf(o.log, "[runr-wrapper] trace_id=%s span_id=%s sampled=%t %s %s %d %.1fms cold_start=%t\n",
			inv.sc.TraceID, inv.sc.SpanID, inv.sc.Sampled, inv.req.Method, inv.req.Path, status, float64(dur.Microseconds())/1000, inv.cold)
	}

	if o.replay != nil && (status >= 500 || timedOut) && inv.req.Trigger != triggerSchedule && inv.req.Header.Get(replayHeader) == "" {
		traceID := ""
		if o.exp != nil {
			traceID = inv.sc.TraceID.String()
		}
		o.replay.capture(buildCapture(o.replay.cfg, inv.req, status, header, body, timedOut, dur, traceID, o.trace.Revision, inv.start))
	}
}

// span of the invocation with RunR's attributes and the HTTP / FaaS semantic conventions.
func (inv *invocation) span(status int, outcome string, body []byte, end time.Time) *span {
	t := inv.o.trace
	req := inv.req

	s := &span{
		TraceID: inv.sc.TraceID,
		SpanID:  inv.sc.SpanID,
		Name:    spanName(req),
		Kind:    spanKindServer,
		Start:   inv.start,
		End:     end,
	}
	if inv.hasParent {
		s.ParentSpanID = inv.parent
	}

	attrs := []attribute{
		strAttr("runr.project", t.Project),
		strAttr("runr.revision", t.Revision),
		strAttr("runr.runtime", t.Runtime),
		strAttr("runr.trigger", req.Trigger),
		boolAttr("runr.cold_start", inv.cold),
		strAttr("runr.status", outcome),
		strAttr("faas.trigger", faasTrigger(req.Trigger)),
		boolAttr("faas.coldstart", inv.cold),
		strAttr("faas.invocation_id", req.ID),
		strAttr("http.request.method", req.Method),
		strAttr("url.path", req.Path),
		intAttr("http.response.status_code", int64(status)),
		intAttr("http.request.body.size", int64(len(req.Body))),
	}
	if req.Host != "" {
		attrs = append(attrs, strAttr("server.address", req.Host))
	}
	if ua := req.Header.Get("User-Agent"); ua != "" {
		attrs = append(attrs, strAttr("user_agent.original", ua))
	}
	if ce := req.Header.Get("Ce-Type"); ce != "" {
		attrs = append(attrs, strAttr("cloudevents.event_type", ce))
	}
	for _, a := range attrs {
		if v, ok := a.Value.(string); ok && v == "" {
			continue
		}
		s.Attrs = append(s.Attrs, a)
	}

	switch outcome {
	case "timeout":
		s.StatusCode = statusError
		s.StatusMessage = "handler timed out"
		s.Events = append(s.Events, spanEvent{Name: "exception", Time: end, Attrs: []attribute{
			strAttr("exception.type", "timeout"),
			strAttr("exception.message", errorMessage(body, "handler timed out")),
		}})
	case "error":
		s.StatusCode = statusError
		s.StatusMessage = fmt.Sprintf("HTTP %d", status)
		s.Events = append(s.Events, spanEvent{Name: "exception", Time: end, Attrs: []attribute{
			strAttr("exception.type", fmt.Sprintf("http.%d", status)),
			strAttr("exception.message", errorMessage(body, fmt.Sprintf("HTTP %d", status))),
		}})
	default:
		s.StatusCode = statusUnset
	}

	return s
}

// spanName: the method for HTTP requests (no route template is known; paths would make the span
// name unbounded), the trigger for queue deliveries and schedule ticks.
func spanName(req request) string {
	switch req.Trigger {
	case triggerQueue:
		return "queue message"
	case triggerSchedule:
		return "schedule tick"
	}
	if req.Method == "" {
		return "invocation"
	}
	return req.Method
}

func faasTrigger(trigger string) string {
	switch trigger {
	case triggerQueue:
		return "pubsub"
	case triggerSchedule:
		return "timer"
	}
	return "http"
}

// errorMessage: the "error" of a JSON error body ({"error": "..."}), else the start of the body,
// else fallback.
func errorMessage(body []byte, fallback string) string {
	var e struct {
		Error any `json:"error"`
	}
	if err := json.Unmarshal(body, &e); err == nil && e.Error != nil {
		if s, ok := e.Error.(string); ok && s != "" {
			return truncate([]byte(s), maxErrorEvent)
		}
	}
	if b := strings.TrimSpace(string(body)); b != "" {
		return truncate([]byte(b), maxErrorEvent)
	}
	return fallback
}

// ── http-mode schedule ticks ─────────────────────────────────────────────────────────────────

// tickTraceparentEnv carries the tick's traceparent to runProxySchedule (and to the server
// process, where an OpenTelemetry SDK reading the environment carrier finds it).
const tickTraceparentEnv = "TRACEPARENT"

// traceTick runs an http-mode schedule tick (runProxySchedule) as one traced invocation: the
// tick request carries its traceparent, the exit code decides the span status.
func (o *observability) traceTick(cfg config, tick func(config) int) int {
	if o == nil || o.exp == nil {
		return tick(cfg)
	}

	inv := o.begin(request{Trigger: triggerSchedule, Method: http.MethodPost, Path: "/", Header: http.Header{}})
	cfg.Env = append(append([]string(nil), cfg.Env...), tickTraceparentEnv+"="+inv.traceparent())

	code := tick(cfg)
	status := http.StatusOK
	if code != 0 {
		status = http.StatusInternalServerError
	}
	inv.finish(status, nil, nil, false)
	return code
}

// ── exec / persistent mode ───────────────────────────────────────────────────────────────────

// observedInvoker traces / captures the invocations of an exec or persistent invoker. The
// handler sees the invocation's traceparent in the envelope headers (and in exec mode as
// RUNR_HEADER_TRACEPARENT), so an instrumented program continues the trace.
type observedInvoker struct {
	inner invoker
	o     *observability
}

func (w *observedInvoker) Invoke(ctx context.Context, env *Envelope, rawBody []byte) *Response {
	header := http.Header{}
	for k, v := range env.Headers {
		header.Set(k, v)
	}

	inv := w.o.begin(request{
		Trigger:  env.Trigger,
		ID:       env.ID,
		Method:   env.Method,
		Path:     env.Path,
		RawQuery: env.RawQuery,
		Host:     env.Headers["host"],
		Header:   header,
		Body:     rawBody,
	})
	if inv.tracing() {
		if env.Headers == nil {
			env.Headers = map[string]string{}
		}
		env.Headers["traceparent"] = inv.traceparent()
	}

	resp := w.inner.Invoke(ctx, env, rawBody)

	inv.finish(resp.Status, resp.Header, resp.Body, resp.Status == http.StatusGatewayTimeout)
	if inv.tracing() {
		if resp.Header == nil {
			resp.Header = http.Header{}
		}
		resp.Header.Set("Traceresponse", inv.traceparent())
	}
	return resp
}

func (w *observedInvoker) Close() { w.inner.Close() }
