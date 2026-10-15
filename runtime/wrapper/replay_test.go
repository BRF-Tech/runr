package main

import (
	"context"
	"net/http"
	"strings"
	"testing"
	"time"
)

func TestReplayConfig(t *testing.T) {
	if _, ok := loadReplayConfig(envMap(map[string]string{"RUNR_REPLAY_URL": "http://runr/ingest"})); ok {
		t.Error("capture needs both the URL and the token")
	}

	cfg, ok := loadReplayConfig(envMap(map[string]string{
		"RUNR_REPLAY_URL":            "http://runr/ingest",
		"RUNR_REPLAY_TOKEN":          "tok",
		"RUNR_REPLAY_MAX_BODY":       "1024",
		"RUNR_REPLAY_PER_MINUTE":     "5",
		"RUNR_REPLAY_REDACT_HEADERS": " X-Signature , x-tenant-key",
		"RUNR_REPLAY_REDACT_QUERY":   "session",
	}))
	if !ok || cfg.MaxBody != 1024 || cfg.PerMinute != 5 {
		t.Fatalf("config %+v", cfg)
	}
	for _, h := range []string{"authorization", "cookie", "set-cookie", "proxy-authorization", "x-api-key", "x-signature", "x-tenant-key", "connection"} {
		if !cfg.Redact[h] {
			t.Errorf("%s must be redacted", h)
		}
	}
	if !cfg.RedactQuery["session"] || !cfg.RedactQuery["access_token"] {
		t.Errorf("query redaction %v", cfg.RedactQuery)
	}

	def, _ := loadReplayConfig(envMap(map[string]string{"RUNR_REPLAY_URL": "u", "RUNR_REPLAY_TOKEN": "t"}))
	if def.MaxBody != defaultReplayMaxBody || def.PerMinute != defaultReplayPerMinute {
		t.Errorf("defaults %+v", def)
	}
}

func TestRedactQuery(t *testing.T) {
	redact := map[string]bool{"token": true, "api_key": true}
	cases := map[string]string{
		"":                           "",
		"page=2":                     "page=2",
		"token=abc&page=2":           "token=REDACTED&page=2",
		"TOKEN=abc":                  "TOKEN=REDACTED",
		"api%5Fkey=xyz&flag":         "api%5Fkey=REDACTED&flag",
		"token&x=1":                  "token&x=1", // no value, nothing to hide
		"a=1&token=2&token=3&b=%20c": "a=1&token=REDACTED&token=REDACTED&b=%20c",
	}
	for in, want := range cases {
		if got := redactQuery(in, redact); got != want {
			t.Errorf("redactQuery(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRedactHeaders(t *testing.T) {
	h := http.Header{}
	h.Set("Authorization", "Bearer x")
	h.Add("Accept", "text/html")
	h.Add("Accept", "application/json")
	h.Set("X-Api-Key", "k")
	out, removed := redactHeaders(h, map[string]bool{"authorization": true, "x-api-key": true})

	if out["accept"] != "text/html, application/json" || len(out) != 1 {
		t.Errorf("kept headers %v", out)
	}
	if strings.Join(removed, ",") != "authorization,x-api-key" {
		t.Errorf("removed %v", removed)
	}
}

func TestSecretNamesAreNeverCaptured(t *testing.T) {
	h := http.Header{}
	h.Set("X-Auth-Token", "t")
	h.Set("X-Client-Secret", "s")
	h.Set("Private-Key", "k")
	h.Set("Accept", "*/*")
	out, removed := redactHeaders(h, map[string]bool{})
	if len(out) != 1 || out["accept"] != "*/*" {
		t.Errorf("only accept is kept: %v", out)
	}
	if strings.Join(removed, ",") != "private-key,x-auth-token,x-client-secret" {
		t.Errorf("removed %v", removed)
	}
	if got := redactQuery("client_secret=x&q=1&refresh-token=y", map[string]bool{}); got != "client_secret=REDACTED&q=1&refresh-token=REDACTED" {
		t.Errorf("secret query values: %q", got)
	}
}

func TestLimitedBody(t *testing.T) {
	if s, b64, cut := limitedBody([]byte("hello"), 10); s != "hello" || b64 || cut {
		t.Errorf("small UTF-8 body: %q %t %t", s, b64, cut)
	}
	if s, _, cut := limitedBody([]byte("hello world"), 5); s != "hello" || !cut {
		t.Errorf("cut body: %q %t", s, cut)
	}
	if s, b64, _ := limitedBody([]byte{0xff, 0xfe, 0x00}, 10); !b64 || s != "//4A" {
		t.Errorf("binary body is base64: %q %t", s, b64)
	}
	if s, _, cut := limitedBody([]byte("abc"), 0); s != "" || !cut {
		t.Errorf("limit 0 keeps no body: %q %t", s, cut)
	}
}

func TestReplayBudget(t *testing.T) {
	r := &replayer{cfg: replayConfig{PerMinute: 2}, tokens: 2, last: time.Unix(0, 0)}
	now := time.Unix(0, 0)
	if !r.allow(now) || !r.allow(now) || r.allow(now) {
		t.Fatal("two captures a minute, the third is refused")
	}
	if !r.allow(now.Add(31 * time.Second)) {
		t.Error("half a minute later one more is allowed")
	}
	if r.allow(now.Add(32 * time.Second)) {
		t.Error("but not two")
	}
}

func TestExecModeCapture(t *testing.T) {
	ing := newFakeIngest(t)
	cfg, _, _ := testConfig(t, modeExec, "cat")
	o := setupObservability(cfg, envMap(map[string]string{"RUNR_REPLAY_URL": ing.srv.URL, "RUNR_REPLAY_TOKEN": "tok"}))
	inv := o.wrap(&stubInvoker{resp: errorResponse(500, "handler exited with status 1")})

	env := httpEnvelope("PUT", "/items/7", map[string]string{"authorization": "Bearer secret", "content-type": "application/json"})
	env.RawQuery = "api_key=zzz"
	inv.Invoke(context.Background(), env, []byte(`{"name":"x"}`))
	o.shutdown()

	caps := ing.all()
	if len(caps) != 1 {
		t.Fatalf("captures %d", len(caps))
	}
	c := caps[0]
	if c.Method != "PUT" || c.Path != "/items/7" || c.Query != "api_key=REDACTED" || c.Body != `{"name":"x"}` || c.Trigger != "http" {
		t.Errorf("capture %+v", c)
	}
	if _, kept := c.Headers["authorization"]; kept {
		t.Error("authorization must not be captured")
	}
	if !strings.Contains(c.ResponseBody, "handler exited") || c.Status != 500 || c.TimedOut {
		t.Errorf("response part %+v", c)
	}
	if c.TraceID != "" {
		t.Error("no trace id without tracing")
	}
}
