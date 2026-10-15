package main

import (
	"encoding/json"
	"strings"
	"testing"
)

// envMap (tick_test.go) serves as the getenv of the configuration tests.

func TestParseTraceparent(t *testing.T) {
	sc, ok := parseTraceparent("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01")
	if !ok || !sc.Sampled || sc.TraceID.String() != "4bf92f3577b34da6a3ce929d0e0e4736" || sc.SpanID.String() != "00f067aa0ba902b7" {
		t.Fatalf("sampled traceparent: %+v ok=%t", sc, ok)
	}

	sc, ok = parseTraceparent("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00")
	if !ok || sc.Sampled {
		t.Fatalf("unsampled traceparent: %+v ok=%t", sc, ok)
	}

	// a later version is read by its first four fields
	if _, ok := parseTraceparent("01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra"); !ok {
		t.Error("version 01 with an extra field must parse")
	}

	for _, bad := range []string{
		"",
		"garbage",
		"00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7",      // no flags
		"00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-x", // version 00 with 5 fields
		"ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01",   // invalid version
		"00-00000000000000000000000000000000-00f067aa0ba902b7-01",   // zero trace id
		"00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01",   // zero span id
		"00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01",   // upper-case hex
		"00-4bf92f3577b34da6a3ce929d0e0e47-00f067aa0ba902b7-01",     // short trace id
		"00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902zz-01",   // not hex
	} {
		if _, ok := parseTraceparent(bad); ok {
			t.Errorf("%q must not parse", bad)
		}
	}
}

func TestTraceparentRoundTrip(t *testing.T) {
	sc := spanContext{TraceID: newTraceID(), SpanID: newSpanID(), Sampled: true}
	back, ok := parseTraceparent(sc.traceparent())
	if !ok || back != sc {
		t.Fatalf("round trip %q → %+v", sc.traceparent(), back)
	}
	sc.Sampled = false
	if !strings.HasSuffix(sc.traceparent(), "-00") {
		t.Errorf("unsampled flags: %q", sc.traceparent())
	}
}

func TestRatioSampler(t *testing.T) {
	id := newTraceID()
	if !ratioSampled(id, 1) || ratioSampled(id, 0) {
		t.Fatal("ratio 1 samples everything, ratio 0 nothing")
	}
	if ratioSampled(id, 0.3) != ratioSampled(id, 0.3) {
		t.Fatal("the decision is deterministic on the trace id")
	}

	sampled := 0
	const n = 20000
	for i := 0; i < n; i++ {
		if ratioSampled(newTraceID(), 0.25) {
			sampled++
		}
	}
	if got := float64(sampled) / n; got < 0.22 || got > 0.28 {
		t.Errorf("ratio 0.25 sampled %.3f", got)
	}

	// a lower ratio samples a subset of a higher one (consistent across hops)
	for i := 0; i < 1000; i++ {
		tid := newTraceID()
		if ratioSampled(tid, 0.1) && !ratioSampled(tid, 0.5) {
			t.Fatal("a trace sampled at 0.1 must be sampled at 0.5")
		}
	}
}

func TestSamplerRatio(t *testing.T) {
	cases := map[[2]string]float64{
		{"always_on", ""}:                   1,
		{"always_off", "0.5"}:               0,
		{"parentbased_always_off", ""}:      0,
		{"traceidratio", "0.2"}:             0.2,
		{"parentbased_traceidratio", "0.5"}: 0.5,
		{"traceidratio", ""}:                1,
		{"traceidratio", "nonsense"}:        1,
		{"traceidratio", "7"}:               1,
		{"", ""}:                            1,
	}
	for in, want := range cases {
		if got := samplerRatio(in[0], in[1]); got != want {
			t.Errorf("samplerRatio(%q, %q) = %v, want %v", in[0], in[1], got, want)
		}
	}
}

func TestLoadTraceConfig(t *testing.T) {
	env := map[string]string{
		"RUNR_TRACING":                "1",
		"OTEL_EXPORTER_OTLP_ENDPOINT": "http://otel-collector.observability.svc.cluster.local:4318/",
		"OTEL_EXPORTER_OTLP_HEADERS":  "x-scope-orgid=acme,authorization=Bearer%20abc",
		"OTEL_SERVICE_NAME":           "orders-acme",
		"OTEL_RESOURCE_ATTRIBUTES":    "runr.project_id=01JPROJECT,runr.tenant=acme,service.namespace=runr,deployment.environment=prod%20eu",
		"OTEL_TRACES_SAMPLER":         "traceidratio",
		"OTEL_TRACES_SAMPLER_ARG":     "0.5",
		"RUNR_PROJECT":                "orders-acme",
		"RUNR_RUNTIME":                "python",
		"K_REVISION":                  "orders-acme-00003",
		"HOSTNAME":                    "orders-acme-00003-deployment-abc",
	}
	cfg := loadTraceConfig(envMap(env))

	if !cfg.Enabled {
		t.Fatal("RUNR_TRACING=1 with an endpoint enables tracing")
	}
	if cfg.Endpoint != "http://otel-collector.observability.svc.cluster.local:4318/v1/traces" {
		t.Errorf("endpoint %q", cfg.Endpoint)
	}
	if cfg.Headers["x-scope-orgid"] != "acme" || cfg.Headers["authorization"] != "Bearer abc" {
		t.Errorf("headers %v", cfg.Headers)
	}
	if cfg.Ratio != 0.5 || cfg.ServiceName != "orders-acme" || cfg.Revision != "orders-acme-00003" || cfg.Runtime != "python" {
		t.Errorf("config %+v", cfg)
	}

	res := map[string]any{}
	for _, a := range cfg.Resource {
		res[a.Key] = a.Value
	}
	for k, v := range map[string]string{
		"runr.project_id":        "01JPROJECT",
		"runr.tenant":            "acme",
		"deployment.environment": "prod eu",
		"service.name":           "orders-acme",
		"service.instance.id":    "orders-acme-00003-deployment-abc",
		"telemetry.sdk.name":     "runr-wrapper",
		"faas.version":           "orders-acme-00003",
	} {
		if res[k] != v {
			t.Errorf("resource %s = %v, want %q", k, res[k], v)
		}
	}

	env["OTEL_EXPORTER_OTLP_TRACES_ENDPOINT"] = "http://collector:4318/custom/traces"
	if got := loadTraceConfig(envMap(env)).Endpoint; got != "http://collector:4318/custom/traces" {
		t.Errorf("the traces endpoint is used as it is: %q", got)
	}
}

func TestTracingStaysOff(t *testing.T) {
	base := map[string]string{"RUNR_TRACING": "1", "OTEL_EXPORTER_OTLP_ENDPOINT": "http://c:4318"}

	for name, change := range map[string][2]string{
		"no RUNR_TRACING":   {"RUNR_TRACING", ""},
		"no endpoint":       {"OTEL_EXPORTER_OTLP_ENDPOINT", ""},
		"SDK disabled":      {"OTEL_SDK_DISABLED", "true"},
		"exporter none":     {"OTEL_TRACES_EXPORTER", "none"},
		"RUNR_TRACING=0":    {"RUNR_TRACING", "0"},
		"RUNR_TRACING=junk": {"RUNR_TRACING", "maybe"},
	} {
		env := map[string]string{}
		for k, v := range base {
			env[k] = v
		}
		env[change[0]] = change[1]
		if loadTraceConfig(envMap(env)).Enabled {
			t.Errorf("%s: tracing must be off", name)
		}
	}
}

func TestOTLPJSONEncoding(t *testing.T) {
	tid, _ := parseTraceparent("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01")
	s := &span{
		TraceID:      tid.TraceID,
		SpanID:       spanID{1, 2, 3, 4, 5, 6, 7, 8},
		ParentSpanID: tid.SpanID,
		Name:         "GET",
		Kind:         spanKindServer,
		Attrs:        []attribute{strAttr("runr.project", "orders"), boolAttr("runr.cold_start", true), intAttr("http.response.status_code", 500)},
		Events:       []spanEvent{{Name: "exception", Attrs: []attribute{strAttr("exception.message", "boom")}}},
		StatusCode:   statusError,
	}

	raw, err := json.Marshal(otlpRequest([]attribute{strAttr("service.name", "orders")}, []*span{s}))
	if err != nil {
		t.Fatal(err)
	}
	js := string(raw)
	for _, want := range []string{
		`"traceId":"4bf92f3577b34da6a3ce929d0e0e4736"`,
		`"spanId":"0102030405060708"`,
		`"parentSpanId":"00f067aa0ba902b7"`,
		`"kind":2`,
		`{"key":"runr.cold_start","value":{"boolValue":true}}`,
		`{"key":"http.response.status_code","value":{"intValue":"500"}}`,
		`{"key":"service.name","value":{"stringValue":"orders"}}`,
		`"status":{"code":2}`,
		`"name":"exception"`,
		`"scope":{"name":"runr-wrapper"`,
	} {
		if !strings.Contains(js, want) {
			t.Errorf("OTLP JSON lacks %s\n%s", want, js)
		}
	}
}
