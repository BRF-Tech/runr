package main

// OpenTelemetry tracing of the wrapper, standard library only: W3C Trace Context propagation,
// a deterministic trace-id ratio sampler and one SERVER span per invocation, exported as OTLP/HTTP
// JSON (otel_export.go) to the region's OpenTelemetry Collector. RunR turns it on per project
// (RUNR_TRACING=1 plus the standard OTEL_* variables, docs/observability.md).

import (
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// traceConfig is the tracing configuration, read from the environment (loadTraceConfig).
type traceConfig struct {
	Enabled     bool
	Endpoint    string            // OTLP/HTTP traces URL (…/v1/traces)
	Headers     map[string]string // OTEL_EXPORTER_OTLP(_TRACES)_HEADERS
	Ratio       float64           // sampling ratio 0..1 for invocations without a sampled parent
	ServiceName string            // OTEL_SERVICE_NAME
	Resource    []attribute       // OTEL_RESOURCE_ATTRIBUTES + service / SDK / pod attributes

	Project  string // RUNR_PROJECT (slug)
	Revision string // K_REVISION (set by Knative)
	Runtime  string // RUNR_RUNTIME
}

// loadTraceConfig reads the tracing configuration through getenv. Tracing is on when RunR set
// RUNR_TRACING=1 and an OTLP endpoint, unless the SDK switches (OTEL_SDK_DISABLED=true,
// OTEL_TRACES_EXPORTER=none) turn it off.
func loadTraceConfig(getenv func(string) string) traceConfig {
	cfg := traceConfig{
		Project:  strings.TrimSpace(getenv("RUNR_PROJECT")),
		Revision: strings.TrimSpace(getenv("K_REVISION")),
		Runtime:  strings.TrimSpace(getenv("RUNR_RUNTIME")),
		Ratio:    1,
	}

	cfg.Endpoint = strings.TrimSpace(getenv("OTEL_EXPORTER_OTLP_TRACES_ENDPOINT"))
	if cfg.Endpoint == "" {
		if base := strings.TrimRight(strings.TrimSpace(getenv("OTEL_EXPORTER_OTLP_ENDPOINT")), "/"); base != "" {
			cfg.Endpoint = base + "/v1/traces"
		}
	}

	on := isTrue(getenv("RUNR_TRACING"))
	if isTrue(getenv("OTEL_SDK_DISABLED")) || strings.EqualFold(strings.TrimSpace(getenv("OTEL_TRACES_EXPORTER")), "none") {
		on = false
	}
	cfg.Enabled = on && cfg.Endpoint != ""

	headers := getenv("OTEL_EXPORTER_OTLP_TRACES_HEADERS")
	if strings.TrimSpace(headers) == "" {
		headers = getenv("OTEL_EXPORTER_OTLP_HEADERS")
	}
	cfg.Headers = map[string]string{}
	for _, kv := range parseKeyValues(headers) {
		cfg.Headers[kv[0]] = kv[1]
	}

	cfg.Ratio = samplerRatio(getenv("OTEL_TRACES_SAMPLER"), getenv("OTEL_TRACES_SAMPLER_ARG"))

	cfg.ServiceName = firstNonEmpty(strings.TrimSpace(getenv("OTEL_SERVICE_NAME")), cfg.Project, "runr-function")

	resource := map[string]string{}
	order := []string{}
	set := func(k, v string) {
		if v == "" {
			return
		}
		if _, ok := resource[k]; !ok {
			order = append(order, k)
		}
		resource[k] = v
	}
	for _, kv := range parseKeyValues(getenv("OTEL_RESOURCE_ATTRIBUTES")) {
		set(kv[0], kv[1])
	}
	set("service.name", cfg.ServiceName)
	set("service.instance.id", strings.TrimSpace(getenv("HOSTNAME")))
	set("telemetry.sdk.name", "runr-wrapper")
	set("telemetry.sdk.language", "go")
	set("telemetry.sdk.version", version)
	if cfg.Revision != "" {
		set("faas.version", cfg.Revision)
	}
	for _, k := range order {
		cfg.Resource = append(cfg.Resource, strAttr(k, resource[k]))
	}

	return cfg
}

// samplerRatio maps OTEL_TRACES_SAMPLER / OTEL_TRACES_SAMPLER_ARG to a ratio. The wrapper samples
// an invocation when its parent is sampled, and otherwise by trace id at this ratio — an upstream
// hop that did not sample (Knative's queue-proxy at its own rate) does not switch tracing off.
func samplerRatio(sampler, arg string) float64 {
	switch strings.ToLower(strings.TrimSpace(sampler)) {
	case "always_off", "parentbased_always_off":
		return 0
	case "always_on", "parentbased_always_on":
		return 1
	}

	if strings.TrimSpace(arg) == "" {
		return 1
	}
	r, err := strconv.ParseFloat(strings.TrimSpace(arg), 64)
	if err != nil || r < 0 {
		return 1
	}
	return min(r, 1)
}

// parseKeyValues reads the W3C-baggage-like "k1=v1,k2=v2" lists of OTEL_RESOURCE_ATTRIBUTES and
// OTEL_EXPORTER_OTLP_HEADERS; keys and values are percent-decoded, empty keys skipped.
func parseKeyValues(raw string) [][2]string {
	var out [][2]string
	for _, part := range strings.Split(raw, ",") {
		k, v, ok := strings.Cut(part, "=")
		if !ok {
			continue
		}
		k, v = strings.TrimSpace(k), strings.TrimSpace(v)
		if dk, err := url.PathUnescape(k); err == nil {
			k = dk
		}
		if dv, err := url.PathUnescape(v); err == nil {
			v = dv
		}
		if k != "" {
			out = append(out, [2]string{k, v})
		}
	}
	return out
}

func isTrue(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "1", "true", "yes", "on":
		return true
	}
	return false
}

// ── Trace context ────────────────────────────────────────────────────────────────────────────

type traceID [16]byte
type spanID [8]byte

func (t traceID) String() string { return hex.EncodeToString(t[:]) }
func (s spanID) String() string  { return hex.EncodeToString(s[:]) }

func (t traceID) isZero() bool { return t == traceID{} }
func (s spanID) isZero() bool  { return s == spanID{} }

// spanContext is the propagated part of a span (W3C traceparent).
type spanContext struct {
	TraceID traceID
	SpanID  spanID
	Sampled bool
}

// parseTraceparent reads a W3C traceparent header ("00-<32 hex>-<16 hex>-<2 hex flags>"). ok is
// false for a malformed value or the all-zero ids; versions above 00 are read by their first
// four fields, as the specification asks.
func parseTraceparent(v string) (spanContext, bool) {
	v = strings.TrimSpace(v)
	parts := strings.Split(v, "-")
	if len(parts) < 4 {
		return spanContext{}, false
	}
	ver, tid, sid, flags := parts[0], parts[1], parts[2], parts[3]
	if len(ver) != 2 || ver == "ff" || !isLowerHex(ver) {
		return spanContext{}, false
	}
	if ver == "00" && len(parts) != 4 {
		return spanContext{}, false
	}
	if len(tid) != 32 || len(sid) != 16 || len(flags) != 2 || !isLowerHex(tid) || !isLowerHex(sid) || !isLowerHex(flags) {
		return spanContext{}, false
	}

	var sc spanContext
	_, _ = hex.Decode(sc.TraceID[:], []byte(tid))
	_, _ = hex.Decode(sc.SpanID[:], []byte(sid))
	if sc.TraceID.isZero() || sc.SpanID.isZero() {
		return spanContext{}, false
	}
	f, _ := strconv.ParseUint(flags, 16, 8)
	sc.Sampled = f&1 == 1
	return sc, true
}

// traceparent formats a span context as a version 00 traceparent.
func (sc spanContext) traceparent() string {
	flags := "00"
	if sc.Sampled {
		flags = "01"
	}
	return "00-" + sc.TraceID.String() + "-" + sc.SpanID.String() + "-" + flags
}

func isLowerHex(s string) bool {
	for _, c := range s {
		if !((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f')) {
			return false
		}
	}
	return s != ""
}

func newTraceID() traceID {
	var t traceID
	for t.isZero() {
		if _, err := rand.Read(t[:]); err != nil {
			binary.BigEndian.PutUint64(t[8:], uint64(time.Now().UnixNano()))
		}
	}
	return t
}

func newSpanID() spanID {
	var s spanID
	for s.isZero() {
		if _, err := rand.Read(s[:]); err != nil {
			binary.BigEndian.PutUint64(s[:], uint64(time.Now().UnixNano()))
		}
	}
	return s
}

// ratioSampled is OpenTelemetry's TraceIdRatioBased decision: deterministic on the trace id, so
// every hop of a trace sampling at the same ratio decides the same way.
func ratioSampled(t traceID, ratio float64) bool {
	if ratio >= 1 {
		return true
	}
	if ratio <= 0 {
		return false
	}
	bound := uint64(ratio * (1 << 63))
	x := binary.BigEndian.Uint64(t[8:16]) >> 1
	return x < bound
}

// ── Spans ────────────────────────────────────────────────────────────────────────────────────

// attribute is one OTLP key/value; Value is a string, bool, int64 or float64.
type attribute struct {
	Key   string
	Value any
}

func strAttr(k, v string) attribute       { return attribute{Key: k, Value: v} }
func boolAttr(k string, v bool) attribute { return attribute{Key: k, Value: v} }
func intAttr(k string, v int64) attribute { return attribute{Key: k, Value: v} }

// spanEvent is an event of a span (e.g. "exception").
type spanEvent struct {
	Name  string
	Time  time.Time
	Attrs []attribute
}

const (
	spanKindServer = 2 // OTLP SPAN_KIND_SERVER

	statusUnset = 0
	statusOK    = 1
	statusError = 2
)

// span is a finished span as the exporter sends it.
type span struct {
	TraceID       traceID
	SpanID        spanID
	ParentSpanID  spanID
	Name          string
	Kind          int
	Start, End    time.Time
	Attrs         []attribute
	Events        []spanEvent
	StatusCode    int
	StatusMessage string
}

// envValue returns the value of name in a KEY=VALUE environment list ("" when absent).
func envValue(env []string, name string) string {
	for i := len(env) - 1; i >= 0; i-- {
		if k, v, ok := strings.Cut(env[i], "="); ok && k == name {
			return v
		}
	}
	return ""
}
