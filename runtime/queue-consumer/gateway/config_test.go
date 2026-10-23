package gateway

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func env(m map[string]string) func(string) string {
	return func(k string) string { return m[k] }
}

// ── gateway.json ─────────────────────────────────────────────────────────────────

func TestLoadConfigFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "gateway.json")
	os.WriteFile(path, []byte(`{
		"version": 1,
		"sink": "http://orders.fn.svc.cluster.local",
		"delivery_timeout": 12,
		"rendered_by": "api",
		"triggers": [
			{
				"name": "orders", "type": "queue", "kind": "redis-stream", "project": "prj-1",
				"concurrency": 4, "max_retries": 0, "retry_backoff": "500ms", "retry_backoff_max": "1m",
				"dead_letter": {"kind": "log"},
				"source": {"address": "redis:6379", "stream": "orders", "group": "billing"}
			},
			{
				"name": "nightly", "type": "schedule", "kind": "test", "sink": "https://other.example/run",
				"delivery_timeout": "2.5", "retry_backoff": "1h", "retry_backoff_max": "1s",
				"source": {"id": "unused"}
			}
		]
	}`), 0o600)

	cfg, err := Load(path)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if len(cfg.Triggers) != 2 || cfg.DeliveryTimeout.D() != 12*time.Second {
		t.Fatalf("config %+v", cfg)
	}
	var src map[string]string
	if err := json.Unmarshal(cfg.Triggers[0].Source, &src); err != nil || src["stream"] != "orders" {
		t.Errorf("source object not kept verbatim: %s", cfg.Triggers[0].Source)
	}

	cfg.Triggers[1].Source = useSource(t, idleSource{})
	g, err := New(cfg)
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	defer g.close()
	a, b := g.triggers[0], g.triggers[1]
	if a.sink != "http://orders.fn.svc.cluster.local" || a.concurrency != 4 || a.maxRetries != 0 ||
		a.retryBackoff != 500*time.Millisecond || a.retryBackoffMax != time.Minute || a.timeout != 12*time.Second || a.dlName != "log" {
		t.Errorf("orders resolved to %+v", a)
	}
	if b.sink != "https://other.example/run" || b.concurrency != 1 || b.maxRetries != DefaultMaxRetries ||
		b.retryBackoff != time.Hour || b.retryBackoffMax != time.Hour || b.timeout != 2500*time.Millisecond || b.dlName != "log" {
		t.Errorf("nightly resolved to %+v (max raised to the backoff, defaults applied)", b)
	}
}

func TestDefaultsOfAMinimalTrigger(t *testing.T) {
	g := testGateway(t, "http://fn.example", nil, func(tc *TriggerConfig) {
		*tc = TriggerConfig{Name: "t", Kind: "test", Sink: tc.Sink, Source: tc.Source}
	})
	tr := g.triggers[0]
	if tr.concurrency != 1 || tr.maxRetries != DefaultMaxRetries || tr.retryBackoff != DefaultRetryBackoff ||
		tr.retryBackoffMax != DefaultRetryBackoffMax || tr.timeout != DefaultDeliveryTimeout || tr.dlName != "log" {
		t.Errorf("defaults %+v", tr)
	}
}

func TestValidateRejects(t *testing.T) {
	ok := func() TriggerConfig { return TriggerConfig{Name: "a", Kind: "test", Sink: "http://fn"} }
	cases := map[string]struct {
		cfg  Config
		want string
	}{
		"no triggers":       {Config{Sink: "http://fn"}, "no triggers"},
		"version":           {Config{Version: 2, Triggers: []TriggerConfig{ok()}}, "version"},
		"bad name":          {Config{Triggers: []TriggerConfig{{Name: "Orders_1", Kind: "test", Sink: "http://fn"}}}, "name"},
		"empty name":        {Config{Triggers: []TriggerConfig{{Kind: "test", Sink: "http://fn"}}}, "name"},
		"duplicate":         {Config{Triggers: []TriggerConfig{ok(), ok()}}, "duplicate"},
		"unknown kind":      {Config{Triggers: []TriggerConfig{{Name: "a", Kind: "kafka", Sink: "http://fn"}}}, "unknown source kind"},
		"no sink":           {Config{Triggers: []TriggerConfig{{Name: "a", Kind: "test"}}}, "no sink"},
		"sink not http":     {Config{Triggers: []TriggerConfig{{Name: "a", Kind: "test", Sink: "ftp://fn"}}}, "not an http(s) URL"},
		"sink without host": {Config{Sink: "http://", Triggers: []TriggerConfig{{Name: "a", Kind: "test"}}}, "not an http(s) URL"},
		"dead letter kind":  {Config{Triggers: []TriggerConfig{{Name: "a", Kind: "test", Sink: "http://fn", DeadLetter: &DeadLetterConfig{Kind: "s3"}}}}, "dead_letter"},
	}
	for name, tc := range cases {
		err := tc.cfg.Validate()
		if err == nil || !strings.Contains(err.Error(), tc.want) {
			t.Errorf("%s: Validate = %v, want an error about %q", name, err, tc.want)
		}
	}
}

func TestLoadRejectsBrokenFiles(t *testing.T) {
	dir := t.TempDir()
	for name, body := range map[string]string{
		"syntax.json":   `{"triggers": [`,
		"duration.json": `{"sink": "http://fn", "triggers": [{"name": "a", "kind": "test", "retry_backoff": "-2s"}]}`,
		"empty.json":    `{}`,
	} {
		path := filepath.Join(dir, name)
		os.WriteFile(path, []byte(body), 0o600)
		if _, err := Load(path); err == nil {
			t.Errorf("Load(%s) accepted", name)
		}
	}
	if _, err := Load(filepath.Join(dir, "missing.json")); err == nil {
		t.Error("Load of a missing file accepted")
	}
}

func TestDurationJSON(t *testing.T) {
	cases := map[string]time.Duration{
		`"2s"`:    2 * time.Second,
		`"500ms"`: 500 * time.Millisecond,
		`"2"`:     2 * time.Second,
		`" 0.5 "`: 500 * time.Millisecond,
		`2`:       2 * time.Second,
		`0.25`:    250 * time.Millisecond,
		`null`:    0,
		`""`:      0,
	}
	for in, want := range cases {
		var d Duration
		if err := json.Unmarshal([]byte(in), &d); err != nil || d.D() != want {
			t.Errorf("Duration %s = %v, %v; want %v", in, d.D(), err, want)
		}
	}
	for _, bad := range []string{`"-1s"`, `-3`, `"soon"`, `true`, `{}`} {
		var d Duration
		if err := json.Unmarshal([]byte(bad), &d); err == nil {
			t.Errorf("Duration %s accepted", bad)
		}
	}
	b, _ := json.Marshal(Duration(90 * time.Second))
	if string(b) != `"1m30s"` {
		t.Errorf("Marshal = %s", b)
	}
}

// ── legacy env mode ───────────────────────────────────────────────────────────────

func TestFromLegacyEnvDefaults(t *testing.T) {
	cfg, err := FromLegacyEnv(env(map[string]string{"STREAM": "orders", "SINK_URL": "http://fn.example"}))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Legacy == nil || cfg.Legacy.DLQStream != "orders-dlq" || cfg.Legacy.Group != "default-group" || cfg.Legacy.Consumer == "" {
		t.Fatalf("legacy %+v", cfg.Legacy)
	}
	tc := cfg.Triggers[0]
	if tc.Name != "queue" || tc.Type != "queue" || tc.Kind != "redis-stream" || *tc.MaxRetries != 5 ||
		tc.RetryBackoff.D() != 2*time.Second || tc.RetryBackoffMax.D() != 5*time.Minute ||
		tc.DeliveryTimeout.D() != 30*time.Second || tc.Concurrency != 1 || cfg.Sink != "http://fn.example" {
		t.Fatalf("unexpected defaults: %+v", tc)
	}

	var src map[string]any
	json.Unmarshal(tc.Source, &src)
	wantSrc := map[string]any{"address": "localhost:6379", "password": "", "stream": "orders", "group": "default-group",
		"consumer": cfg.Legacy.Consumer, "start": "0", "format": "queue", "batch": float64(10)}
	for k, v := range wantSrc {
		if src[k] != v {
			t.Errorf("source %s = %v, want %v", k, src[k], v)
		}
	}

	var dlq struct {
		Kind   string            `json:"kind"`
		Stream string            `json:"stream"`
		MaxLen int64             `json:"max_len"`
		Meta   map[string]string `json:"meta"`
	}
	json.Unmarshal(tc.DeadLetter.Raw, &dlq)
	if tc.DeadLetter.Kind != "redis-stream" || dlq.Kind != "redis-stream" || dlq.Stream != "orders-dlq" || dlq.MaxLen != 10000 ||
		dlq.Meta["runr.dlq.stream"] != "orders" || dlq.Meta["runr.dlq.group"] != "default-group" {
		t.Errorf("dead letter %s", tc.DeadLetter.Raw)
	}
}

func TestFromLegacyEnvNormalisesInvalidValues(t *testing.T) {
	cfg, err := FromLegacyEnv(env(map[string]string{
		"SINK_URL":          "http://fn.example",
		"REDIS_ADDRESS":     "rediss://cache:6380",
		"REDIS_PASSWORD":    "pw",
		"STREAM":            "jobs",
		"GROUP":             "workers",
		"DLQ_STREAM":        "jobs-dead",
		"MAX_RETRIES":       "-3",
		"RETRY_BACKOFF":     "0",
		"RETRY_BACKOFF_MAX": "100ms",
		"BATCH_SIZE":        "0",
		"DELIVERY_TIMEOUT":  "1.5",
		"DLQ_MAXLEN":        "abc", // not an integer: default
		"CONSUMER":          "pod-7",
		"CONCURRENCY":       "-2",
	}))
	if err != nil {
		t.Fatal(err)
	}
	tc := cfg.Triggers[0]
	if cfg.Legacy.DLQStream != "jobs-dead" || cfg.Legacy.Consumer != "pod-7" || cfg.Legacy.Group != "workers" {
		t.Errorf("legacy %+v", cfg.Legacy)
	}
	if *tc.MaxRetries != 0 {
		t.Errorf("MaxRetries = %d, want 0 (negative clamped)", *tc.MaxRetries)
	}
	if tc.RetryBackoff.D() != time.Second {
		t.Errorf("RetryBackoff = %v, want 1s (non-positive replaced)", tc.RetryBackoff)
	}
	if tc.RetryBackoffMax.D() != time.Second {
		t.Errorf("RetryBackoffMax = %v, want raised to RetryBackoff", tc.RetryBackoffMax)
	}
	if tc.DeliveryTimeout.D() != 1500*time.Millisecond {
		t.Errorf("DeliveryTimeout = %v, want 1.5s", tc.DeliveryTimeout)
	}
	if tc.Concurrency != 1 {
		t.Errorf("Concurrency = %d, want 1", tc.Concurrency)
	}
	var src map[string]any
	json.Unmarshal(tc.Source, &src)
	if src["batch"] != float64(10) || src["address"] != "rediss://cache:6380" || src["password"] != "pw" || src["consumer"] != "pod-7" {
		t.Errorf("source %v", src)
	}
	var dlq map[string]any
	json.Unmarshal(tc.DeadLetter.Raw, &dlq)
	if dlq["max_len"] != float64(10000) || dlq["address"] != "rediss://cache:6380" || dlq["password"] != "pw" {
		t.Errorf("dead letter %v", dlq)
	}

	cfg, err = FromLegacyEnv(env(map[string]string{"SINK_URL": "http://fn.example", "DLQ_MAXLEN": "0"}))
	if err != nil {
		t.Fatal(err)
	}
	json.Unmarshal(cfg.Triggers[0].DeadLetter.Raw, &dlq)
	if dlq["max_len"] != float64(-1) {
		t.Errorf("DLQ_MAXLEN=0 → max_len %v, want -1 (no trimming, as before)", dlq["max_len"])
	}
}

// The queue consumer delivered to http://localhost:8080 without SINK_URL; legacy env mode does
// too, and claims another consumer's entries after CLAIM_MIN_IDLE (default 30s).
func TestFromLegacyEnvSinkAndClaimDefaults(t *testing.T) {
	cfg, err := FromLegacyEnv(env(map[string]string{"STREAM": "jobs"}))
	if err != nil {
		t.Fatalf("no SINK_URL: %v", err)
	}
	if cfg.Sink != "http://localhost:8080" {
		t.Errorf("sink = %q, want the queue consumer's default", cfg.Sink)
	}
	var src map[string]any
	json.Unmarshal(cfg.Triggers[0].Source, &src)
	if src["claim_min_idle"] != "30s" {
		t.Errorf("claim_min_idle = %v, want 30s", src["claim_min_idle"])
	}

	for in, want := range map[string]string{"5s": "5s", "90": "1m30s", "0": "30s", "soon": "30s"} {
		cfg, err := FromLegacyEnv(env(map[string]string{"SINK_URL": "http://fn", "CLAIM_MIN_IDLE": in}))
		if err != nil {
			t.Fatal(err)
		}
		json.Unmarshal(cfg.Triggers[0].Source, &src)
		if src["claim_min_idle"] != want {
			t.Errorf("CLAIM_MIN_IDLE=%s → %v, want %s", in, src["claim_min_idle"], want)
		}
	}
}

func TestFromLegacyEnvRejects(t *testing.T) {
	_, err := FromLegacyEnv(env(map[string]string{"SINK_URL": "http://fn", "STREAM": "jobs", "DLQ_STREAM": "jobs"}))
	if err == nil || !strings.Contains(err.Error(), "DLQ_STREAM must differ from STREAM") {
		t.Errorf("DLQ_STREAM == STREAM: %v", err)
	}
	if _, err := FromLegacyEnv(env(map[string]string{"SINK_URL": "not a url"})); err == nil {
		t.Error("SINK_URL that is not an http(s) URL accepted")
	}
}
