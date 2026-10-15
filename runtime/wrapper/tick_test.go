package main

import (
	"bytes"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestLoadTickConfigDefaults(t *testing.T) {
	cfg, err := loadTickConfig(envMap(map[string]string{"PORT": "9091", "RUNR_TIMEOUT": "7"}))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.URL != "http://127.0.0.1:9091/" || cfg.Addr != "127.0.0.1:9091" || cfg.Timeout != 7*time.Second {
		t.Errorf("cfg = %+v", cfg)
	}

	cfg, err = loadTickConfig(envMap(map[string]string{"RUNR_TICK_URL": "http://fn.example.com/tick"}))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Addr != "fn.example.com:80" || cfg.Timeout != defaultTimeout {
		t.Errorf("cfg = %+v", cfg)
	}
}

func TestTickPostsTheScheduleCloudEvent(t *testing.T) {
	var got *http.Request
	var body []byte
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r
		body, _ = io.ReadAll(r.Body)
		_, _ = w.Write([]byte("tick from Rust (Wasm)"))
	}))
	defer srv.Close()

	var out bytes.Buffer
	cfg := tickConfig{URL: srv.URL + "/", Addr: strings.TrimPrefix(srv.URL, "http://"), Timeout: 5 * time.Second, MaxBody: defaultMaxBody, Stdout: &out}

	if code := runTick(cfg); code != 0 {
		t.Fatalf("exit code %d", code)
	}
	if got.Method != http.MethodPost || got.URL.Path != "/" {
		t.Errorf("request %s %s", got.Method, got.URL.Path)
	}
	if got.Header.Get("ce-type") != scheduleEventType || got.Header.Get("ce-source") != scheduleEventSource || got.Header.Get("ce-specversion") != "1.0" {
		t.Errorf("CloudEvent headers %v", got.Header)
	}
	if got.Header.Get("X-Request-Id") == "" || got.Header.Get("X-Request-Id") != got.Header.Get("ce-id") {
		t.Errorf("request id %q, ce-id %q", got.Header.Get("X-Request-Id"), got.Header.Get("ce-id"))
	}
	if string(body) != `{"trigger":"schedule"}` {
		t.Errorf("body %q", body)
	}
	if out.String() != "tick from Rust (Wasm)\n" {
		t.Errorf("stdout %q", out.String())
	}
}

func TestTickFailsOnAnErrorStatus(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "boom", http.StatusInternalServerError)
	}))
	defer srv.Close()

	var out bytes.Buffer
	cfg := tickConfig{URL: srv.URL + "/", Addr: strings.TrimPrefix(srv.URL, "http://"), Timeout: 5 * time.Second, MaxBody: defaultMaxBody, Stdout: &out}

	if code := runTick(cfg); code != 1 {
		t.Errorf("status 500 -> exit 1, got %d", code)
	}
	if out.String() != "boom\n" {
		t.Errorf("stdout %q", out.String())
	}
}

func TestTickWaitsForTheServer(t *testing.T) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := l.Addr().String()
	_ = l.Close() // nothing listens yet

	go func() {
		time.Sleep(300 * time.Millisecond)
		l2, err := net.Listen("tcp", addr)
		if err != nil {
			return
		}
		_ = http.Serve(l2, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("late")) }))
	}()

	var out bytes.Buffer
	cfg := tickConfig{URL: "http://" + addr + "/", Addr: addr, Timeout: 5 * time.Second, MaxBody: defaultMaxBody, Stdout: &out}
	if code := runTick(cfg); code != 0 || out.String() != "late\n" {
		t.Errorf("code %d stdout %q", code, out.String())
	}
}

func TestTickGivesUpWhenNothingListens(t *testing.T) {
	old := startupTimeout
	startupTimeout = 300 * time.Millisecond
	defer func() { startupTimeout = old }()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := l.Addr().String()
	_ = l.Close()

	cfg := tickConfig{URL: "http://" + addr + "/", Addr: addr, Timeout: time.Second, MaxBody: defaultMaxBody, Stdout: io.Discard}
	if code := runTick(cfg); code != 1 {
		t.Errorf("no server -> exit 1, got %d", code)
	}
}

func envMap(m map[string]string) func(string) string {
	return func(k string) string { return m[k] }
}
