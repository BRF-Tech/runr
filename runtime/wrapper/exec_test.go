package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// call sends a request to a handler serving cfg and returns status, body and headers.
func call(t *testing.T, cfg config, inv invoker, method, target, body string, headers map[string]string) (int, string, http.Header) {
	t.Helper()
	srv := httptest.NewServer(newHandler(cfg, inv))
	defer srv.Close()

	req, err := http.NewRequest(method, srv.URL+target, strings.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(b), resp.Header
}

func TestExecRawOutput(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`echo "Hello, RunR! (from sh)"`)...)
	status, body, h := call(t, cfg, &execInvoker{cfg: cfg}, "GET", "/", "", nil)

	if status != 200 || body != "Hello, RunR! (from sh)\n" || h.Get("Content-Type") != contentTypeText {
		t.Errorf("got %d %q %v", status, body, h)
	}
	if h.Get("Access-Control-Allow-Origin") != "*" {
		t.Error("responses carry Access-Control-Allow-Origin: *")
	}
}

func TestExecEnvelopeOnStdin(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, "cat")
	status, body, h := call(t, cfg, &execInvoker{cfg: cfg}, "POST", "/items?name=RunR", `{"a":1}`, map[string]string{"X-Test": "yes"})

	if status != 200 || h.Get("Content-Type") != contentTypeJSON {
		t.Fatalf("got %d %v", status, h)
	}
	var env Envelope
	if err := json.Unmarshal([]byte(body), &env); err != nil {
		t.Fatalf("stdin was not the envelope: %v\n%s", err, body)
	}
	if env.Method != "POST" || env.Path != "/items" || env.Query["name"] != "RunR" || env.Body != `{"a":1}` || env.Headers["x-test"] != "yes" || env.Trigger != "http" {
		t.Errorf("envelope = %+v", env)
	}
}

func TestExecRawInputAndEnv(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`printf '%s|%s|%s|%s|' "$RUNR_METHOD" "$RUNR_PATH" "$RUNR_QUERY" "$RUNR_HEADER_X_TEST"; cat`)...)
	cfg.Input = inputRaw

	status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "PUT", "/p?q=1", "raw body", map[string]string{"X-Test": "v"})
	if status != 200 || body != "PUT|/p|q=1|v|raw body" {
		t.Errorf("got %d %q", status, body)
	}
}

func TestExecResponseEnvelope(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`printf '{"statusCode":201,"headers":{"X-A":"b"},"body":"made"}'`)...)
	status, body, h := call(t, cfg, &execInvoker{cfg: cfg}, "POST", "/", "", nil)

	if status != 201 || body != "made" || h.Get("X-A") != "b" {
		t.Errorf("got %d %q %v", status, body, h)
	}
}

func TestExecNonZeroExit(t *testing.T) {
	cfg, _, stderr := testConfig(t, modeExec, sh(`echo "something broke" >&2; exit 1`)...)
	status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "GET", "/", "", nil)

	if status != 500 || body != `{"error":"handler exited with status 1"}` {
		t.Errorf("got %d %q", status, body)
	}
	if !strings.Contains(stderr.String(), "something broke") {
		t.Errorf("stderr goes to the logs, got %q", stderr.String())
	}
}

func TestExecTimeoutKillsTheProcessGroup(t *testing.T) {
	// The shell's child (sleep) holds stdout: only a group kill ends the invocation quickly
	cfg, _, _ := testConfig(t, modeExec, sh(`sleep 30; echo late`)...)
	cfg.Timeout = 300 * time.Millisecond

	start := time.Now()
	status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "GET", "/", "", nil)

	if status != 504 || body != `{"error":"handler timed out after 0.3s"}` {
		t.Errorf("got %d %q", status, body)
	}
	if elapsed := time.Since(start); elapsed > 3*time.Second {
		t.Errorf("timeout took %s", elapsed)
	}
}

func TestExecCommandFromShellString(t *testing.T) {
	argv, err := parseCommand(`echo "method=$RUNR_METHOD"`)
	if err != nil {
		t.Fatal(err)
	}
	cfg, _, _ := testConfig(t, modeExec, argv...)

	status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "DELETE", "/", "", nil)
	if status != 200 || body != "method=DELETE\n" {
		t.Errorf("got %d %q", status, body)
	}
}

func TestExecRunsInTheCodeDir(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`cat greeting.txt`)...)
	if err := os.WriteFile(filepath.Join(cfg.Dir, "greeting.txt"), []byte("from the code dir"), 0o644); err != nil {
		t.Fatal(err)
	}

	if status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "GET", "/", "", nil); status != 200 || body != "from the code dir" {
		t.Errorf("got %d %q", status, body)
	}
}

func TestExecMissingCommand(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, "/nonexistent/handler")
	if status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "GET", "/", "", nil); status != 500 || body != `{"error":"handler failed to start"}` {
		t.Errorf("got %d %q", status, body)
	}
}

func TestExecOutputLimit(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`head -c 5000 /dev/zero`)...)
	cfg.MaxBody = 1000

	if status, _, _ := call(t, cfg, &execInvoker{cfg: cfg}, "GET", "/", "", nil); status != 502 {
		t.Errorf("output over the limit is a 502, got %d", status)
	}
}

func TestProbesDoNotInvoke(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`touch invoked; echo hi`)...)
	inv := &execInvoker{cfg: cfg}

	for _, h := range []string{"K-Network-Probe", "K-Kubelet-Probe"} {
		status, body, _ := call(t, cfg, inv, "GET", "/", "", map[string]string{h: "queue"})
		if status != 200 || body != "ok" {
			t.Errorf("%s: got %d %q", h, status, body)
		}
	}
	if _, err := os.Stat(filepath.Join(cfg.Dir, "invoked")); err == nil {
		t.Error("a probe invoked the handler")
	}
}

func TestOptionsPreflight(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`touch invoked`)...)
	status, _, h := call(t, cfg, &execInvoker{cfg: cfg}, "OPTIONS", "/", "", nil)

	if status != 204 || h.Get("Access-Control-Allow-Origin") != "*" || h.Get("Access-Control-Max-Age") != "86400" ||
		h.Get("Access-Control-Allow-Methods") != "GET, POST, PUT, DELETE, PATCH, OPTIONS" || h.Get("Access-Control-Allow-Headers") != "Content-Type, Authorization" {
		t.Errorf("got %d %v", status, h)
	}
	if _, err := os.Stat(filepath.Join(cfg.Dir, "invoked")); err == nil {
		t.Error("OPTIONS invoked the handler")
	}
}

func TestRequestBodyLimit(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, "cat")
	cfg.MaxBody = 10

	if status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "POST", "/", strings.Repeat("x", 100), nil); status != 413 || body != `{"error":"request body exceeds 10 bytes"}` {
		t.Errorf("got %d %q", status, body)
	}
}

func TestQueueTriggerCloudEvent(t *testing.T) {
	cfg, _, _ := testConfig(t, modeExec, sh(`printf '%s %s ' "$RUNR_TRIGGER" "$RUNR_EVENT_TYPE"; cat`)...)
	cfg.Trigger = triggerQueue

	status, body, _ := call(t, cfg, &execInvoker{cfg: cfg}, "POST", "/", `{"n":1}`, map[string]string{
		"Content-Type": "application/json", "Ce-Specversion": "1.0", "Ce-Id": "1-0", "Ce-Type": "dev.runr.queue.message", "Ce-Source": "/redisstream/s/g",
	})
	if status != 200 || !strings.HasPrefix(body, "queue dev.runr.queue.message {") || !strings.Contains(body, `"event":{"specversion":"1.0","id":"1-0","type":"dev.runr.queue.message","source":"/redisstream/s/g","datacontenttype":"application/json","data":{"n":1}}`) {
		t.Errorf("got %d %s", status, body)
	}
}
