package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"
)

// fakeIngest is RunR's replay ingest endpoint: it keeps every capture and the bearer token.
type fakeIngest struct {
	srv      *httptest.Server
	mu       sync.Mutex
	captures []capture
	tokens   []string
}

func newFakeIngest(t *testing.T) *fakeIngest {
	t.Helper()
	f := &fakeIngest{}
	f.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var c capture
		if err := json.NewDecoder(r.Body).Decode(&c); err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		f.mu.Lock()
		f.captures = append(f.captures, c)
		f.tokens = append(f.tokens, r.Header.Get("Authorization"))
		f.mu.Unlock()
		w.WriteHeader(http.StatusAccepted)
	}))
	t.Cleanup(f.srv.Close)
	return f
}

func (f *fakeIngest) all() []capture {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]capture(nil), f.captures...)
}

// upstream is an http-mode runtime server: /fail answers 500, /slow sleeps, other paths echo
// the traceparent it received.
func newUpstream(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		switch r.URL.Path {
		case "/fail":
			w.Header().Set("Set-Cookie", "session=secret")
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusInternalServerError)
			fmt.Fprintf(w, `{"error":"boom","echo":%q}`, body)
		case "/slow":
			time.Sleep(2 * time.Second)
			w.WriteHeader(http.StatusOK)
		default:
			w.Header().Set("X-Upstream", "1")
			fmt.Fprintf(w, "tp=%s q=%s", r.Header.Get("Traceparent"), r.URL.RawQuery)
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func proxyConfig(t *testing.T) config {
	cfg, _, _ := testConfig(t, modeHTTP, "unused")
	cfg.Timeout = 500 * time.Millisecond
	return cfg
}

func TestProxyPropagatesTheTrace(t *testing.T) {
	c := newFakeCollector(t)
	up := newUpstream(t)
	cfg := proxyConfig(t)
	o := setupObservability(cfg, tracingEnv(c, nil))
	front := httptest.NewServer(newProxyHandler(cfg, o, up.URL))
	defer front.Close()

	req, _ := http.NewRequest("GET", front.URL+"/hello?x=1", nil)
	req.Header.Set("Traceparent", "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	o.shutdown()

	spans := c.all()
	if len(spans) != 1 {
		t.Fatalf("spans %d", len(spans))
	}
	sp := spans[0]

	forwarded := strings.TrimPrefix(strings.Fields(string(body))[0], "tp=")
	sc, ok := parseTraceparent(forwarded)
	if !ok || sc.TraceID.String() != "4bf92f3577b34da6a3ce929d0e0e4736" || sc.SpanID.String() != sp.SpanID {
		t.Errorf("upstream got traceparent %q, wrapper span %s", forwarded, sp.SpanID)
	}
	if sp.ParentSpanID != "00f067aa0ba902b7" {
		t.Errorf("the wrapper span's parent is the incoming span: %q", sp.ParentSpanID)
	}
	if !strings.Contains(string(body), "q=x=1") || resp.Header.Get("X-Upstream") != "1" {
		t.Errorf("query and response headers pass through: %q %v", body, resp.Header)
	}
	if resp.Header.Get("Traceresponse") != forwarded {
		t.Errorf("traceresponse %q", resp.Header.Get("Traceresponse"))
	}
	if a := attrMap(sp.Attributes); a["url.path"] != "/hello" || a["http.response.status_code"] != "200" || a["runr.cold_start"] != "true" {
		t.Errorf("attributes %v", a)
	}
}

func TestProxyCapturesFailedRequests(t *testing.T) {
	c := newFakeCollector(t)
	ing := newFakeIngest(t)
	up := newUpstream(t)
	cfg := proxyConfig(t)
	o := setupObservability(cfg, tracingEnv(c, map[string]string{
		"RUNR_REPLAY_URL":            ing.srv.URL + "/api/internal/observability/replay/_.01JPROJECT",
		"RUNR_REPLAY_TOKEN":          "replay-token",
		"RUNR_REPLAY_MAX_BODY":       "8",
		"RUNR_REPLAY_REDACT_HEADERS": "X-Tenant-Secret",
	}))
	front := httptest.NewServer(newProxyHandler(cfg, o, up.URL))
	defer front.Close()

	req, _ := http.NewRequest("POST", front.URL+"/fail?token=abc&page=2", strings.NewReader("0123456789abcdef"))
	req.Header.Set("Authorization", "Bearer user-secret")
	req.Header.Set("Cookie", "sid=1")
	req.Header.Set("X-Tenant-Secret", "s3cr3t")
	req.Header.Set("X-Request-Id", "req-42")
	req.Header.Set("Content-Type", "text/plain")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	full, _ := io.ReadAll(resp.Body)
	resp.Body.Close()

	// healthy and replayed requests are not captured
	ok, _ := http.Get(front.URL + "/fine")
	ok.Body.Close()
	replayed, _ := http.NewRequest("GET", front.URL+"/fail", nil)
	replayed.Header.Set(replayHeader, "cap-1")
	r2, _ := http.DefaultClient.Do(replayed)
	r2.Body.Close()

	o.shutdown()

	if resp.StatusCode != 500 || !strings.Contains(string(full), "0123456789abcdef") {
		t.Fatalf("the client gets the full response: %d %s", resp.StatusCode, full)
	}

	caps := ing.all()
	if len(caps) != 1 {
		t.Fatalf("one capture expected, got %d: %+v", len(caps), caps)
	}
	cp := caps[0]
	if ing.tokens[0] != "Bearer replay-token" {
		t.Errorf("ingest auth %q", ing.tokens[0])
	}
	if cp.Method != "POST" || cp.Path != "/fail" || cp.Query != "token=REDACTED&page=2" || cp.Status != 500 || cp.RequestID != "req-42" {
		t.Errorf("capture %+v", cp)
	}
	for _, h := range []string{"authorization", "cookie", "x-tenant-secret"} {
		if _, kept := cp.Headers[h]; kept {
			t.Errorf("%s must not be captured", h)
		}
	}
	if strings.Join(cp.RedactedHeaders, ",") != "authorization,cookie,x-tenant-secret" {
		t.Errorf("redacted header names %v", cp.RedactedHeaders)
	}
	if cp.Headers["content-type"] != "text/plain" {
		t.Errorf("other headers are kept: %v", cp.Headers)
	}
	if cp.Body != "01234567" || !cp.BodyTruncated || cp.BodySize != 16 {
		t.Errorf("body limit: %q truncated=%t size=%d", cp.Body, cp.BodyTruncated, cp.BodySize)
	}
	if _, kept := cp.ResponseHeaders["set-cookie"]; kept || cp.ResponseHeaders["content-type"] != "application/json" {
		t.Errorf("response headers %v", cp.ResponseHeaders)
	}
	if cp.ResponseBody != `{"error"` || !cp.ResponseTruncated {
		t.Errorf("response body kept up to the limit: %q", cp.ResponseBody)
	}
	if spans := c.all(); len(spans) == 0 || cp.TraceID != spans[0].TraceID || cp.Revision != "orders-00002" {
		t.Errorf("the capture links to the trace: %q / %+v", cp.TraceID, spans)
	}
}

func TestProxyTimeout(t *testing.T) {
	ing := newFakeIngest(t)
	up := newUpstream(t)
	cfg := proxyConfig(t)
	o := setupObservability(cfg, envMap(map[string]string{"RUNR_REPLAY_URL": ing.srv.URL, "RUNR_REPLAY_TOKEN": "t"}))
	front := httptest.NewServer(newProxyHandler(cfg, o, up.URL))
	defer front.Close()

	resp, err := http.Get(front.URL + "/slow")
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	o.shutdown()

	if resp.StatusCode != http.StatusGatewayTimeout || !strings.Contains(string(body), "timed out") {
		t.Errorf("timeout → 504: %d %s", resp.StatusCode, body)
	}
	if caps := ing.all(); len(caps) != 1 || !caps[0].TimedOut || caps[0].Status != 504 || caps[0].TraceID != "" {
		t.Errorf("timeouts are captured (no trace id without tracing): %+v", caps)
	}
}

func TestProxyUnavailableUpstreamAndProbes(t *testing.T) {
	cfg := proxyConfig(t)
	o := setupObservability(cfg, envMap(map[string]string{"RUNR_REPLAY_URL": "http://127.0.0.1:1", "RUNR_REPLAY_TOKEN": "t"}))
	front := httptest.NewServer(newProxyHandler(cfg, o, "http://127.0.0.1:"+freePort(t)))
	defer front.Close()

	resp, _ := http.Get(front.URL + "/")
	resp.Body.Close()
	if resp.StatusCode != http.StatusBadGateway {
		t.Errorf("no upstream → 502, got %d", resp.StatusCode)
	}

	probe, _ := http.NewRequest("GET", front.URL+"/", nil)
	probe.Header.Set("K-Network-Probe", "queue")
	presp, _ := http.DefaultClient.Do(probe)
	pbody, _ := io.ReadAll(presp.Body)
	presp.Body.Close()
	if presp.StatusCode != 200 || string(pbody) != "ok" {
		t.Errorf("probes are answered by the wrapper: %d %q", presp.StatusCode, pbody)
	}
	o.shutdown()
}

func TestUpstreamPort(t *testing.T) {
	cfg := proxyConfig(t)
	cfg.Port = "8080"
	if p := upstreamPort(cfg, envMap(nil)); p != "8081" {
		t.Errorf("default upstream port %q", p)
	}
	if p := upstreamPort(cfg, envMap(map[string]string{"RUNR_UPSTREAM_PORT": "9000"})); p != "9000" {
		t.Errorf("RUNR_UPSTREAM_PORT %q", p)
	}
}

func TestObservedProxyRunsTheServer(t *testing.T) {
	c := newFakeCollector(t)
	cfg := proxyConfig(t)
	cfg.Command = []string{os.Args[0]}
	cfg.Port = freePort(t)
	cfg.Env = []string{helperEnv + "=http-server", "HELPER_STATUS=503"}
	t.Setenv("RUNR_UPSTREAM_PORT", freePort(t))
	o := setupObservability(cfg, tracingEnv(c, nil))

	done := make(chan int, 1)
	go func() { done <- runObservedProxy(cfg, o) }()

	var resp *http.Response
	var err error
	for i := 0; i < 100; i++ {
		resp, err = http.Post("http://127.0.0.1:"+cfg.Port+"/", "application/json", strings.NewReader(`{"a":1}`))
		if err == nil {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	if err != nil {
		t.Fatalf("proxy never answered: %v", err)
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != 503 || !strings.Contains(string(body), `data={"a":1}`) {
		t.Errorf("proxied response %d %q", resp.StatusCode, body)
	}

	// SIGTERM: drain, stop the server, exit 0
	_ = syscall.Kill(os.Getpid(), syscall.SIGTERM)
	select {
	case code := <-done:
		if code != 0 {
			t.Errorf("exit code %d", code)
		}
	case <-time.After(15 * time.Second):
		t.Fatal("proxy did not stop on SIGTERM")
	}
	o.shutdown()

	if spans := c.all(); len(spans) != 1 || attrMap(spans[0].Attributes)["runr.status"] != "error" {
		t.Errorf("one error span: %+v", spans)
	}
}

func TestObservedProxyServerFailsToListen(t *testing.T) {
	cfg := proxyConfig(t)
	cfg.Command = sh("exit 3")
	cfg.Port = freePort(t)
	o := setupObservability(cfg, envMap(map[string]string{"RUNR_REPLAY_URL": "http://127.0.0.1:1", "RUNR_REPLAY_TOKEN": "t"}))
	if code := runObservedProxy(cfg, o); code != 1 {
		t.Errorf("a server that exits before listening → exit 1, got %d", code)
	}
	o.shutdown()
}
