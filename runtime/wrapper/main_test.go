package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"strconv"
	"sync"
	"testing"
	"time"
)

// helperEnv makes the test binary act as a runtime process instead of running the tests
// (the helper-process pattern): "worker" speaks the persistent line protocol, "http-server"
// is an http-mode runtime server.
const helperEnv = "RUNR_WRAPPER_TEST_HELPER"

func TestMain(m *testing.M) {
	switch os.Getenv(helperEnv) {
	case "":
		os.Exit(m.Run())
	case "worker":
		helperWorker()
	case "http-server":
		helperHTTPServer()
	default:
		fmt.Fprintln(os.Stderr, "unknown helper")
		os.Exit(2)
	}
	os.Exit(0)
}

// helperWorker answers each envelope line by its path:
//
//	/crash   exits without answering
//	/hang    never answers
//	/log     prints a non-JSON log line first
//	/string  answers a JSON string
//	/object  answers a JSON object without statusCode
//	other    answers an envelope: body "<path>?<rawQuery>", header X-Pid
func helperWorker() {
	sc := bufio.NewScanner(os.Stdin)
	sc.Buffer(make([]byte, 1<<20), 1<<20)
	out := bufio.NewWriter(os.Stdout)

	for sc.Scan() {
		var env Envelope
		if err := json.Unmarshal(sc.Bytes(), &env); err != nil {
			fmt.Fprintln(os.Stderr, "bad envelope:", err)
			os.Exit(4)
		}

		switch env.Path {
		case "/crash":
			os.Exit(3)
		case "/hang":
			time.Sleep(time.Hour)
		case "/log":
			fmt.Fprintln(out, "this is a log line, not JSON")
		case "/string":
			fmt.Fprintln(out, `"plain string"`)
			out.Flush()
			continue
		case "/object":
			fmt.Fprintln(out, `{"hello":"world"}`)
			out.Flush()
			continue
		}

		resp, _ := json.Marshal(map[string]any{
			"statusCode": 200,
			"headers":    map[string]string{"X-Pid": strconv.Itoa(os.Getpid()), "X-Trigger": env.Trigger, "X-Worker": os.Getenv("RUNR_WORKER")},
			"body":       env.Path + "?" + env.RawQuery,
		})
		out.Write(resp)
		out.WriteByte('\n')
		out.Flush()
	}
}

// helperHTTPServer listens on $PORT (after HELPER_DELAY) and answers POST / with
// "type=<ce-type> data=<body>" and status HELPER_STATUS (default 200).
func helperHTTPServer() {
	if d, err := time.ParseDuration(os.Getenv("HELPER_DELAY")); err == nil {
		time.Sleep(d)
	}
	status := 200
	if s, err := strconv.Atoi(os.Getenv("HELPER_STATUS")); err == nil {
		status = s
	}

	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		w.WriteHeader(status)
		fmt.Fprintf(w, "type=%s source=%s data=%s", r.Header.Get("Ce-Type"), r.Header.Get("Ce-Source"), body)
	})
	if err := http.ListenAndServe("127.0.0.1:"+os.Getenv("PORT"), nil); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(5)
	}
}

// syncBuffer is a goroutine-safe bytes.Buffer (runtime stderr is written concurrently).
type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.String()
}

// testConfig is a config as loadConfig builds it, with output captured.
func testConfig(t *testing.T, mode string, command ...string) (config, *syncBuffer, *syncBuffer) {
	t.Helper()
	stdout, stderr := &syncBuffer{}, &syncBuffer{}
	return config{
		Mode:    mode,
		Command: command,
		Trigger: triggerHTTP,
		Input:   inputJSON,
		Workers: 1,
		Timeout: 5 * time.Second,
		MaxBody: defaultMaxBody,
		Port:    defaultPort,
		Dir:     t.TempDir(),
		Stdout:  stdout,
		Stderr:  stderr,
	}, stdout, stderr
}

// sh is a /bin/sh -c command.
func sh(script string) []string {
	return []string{"/bin/sh", "-c", script}
}

// freePort returns a TCP port nothing listens on.
func freePort(t *testing.T) string {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer l.Close()
	_, port, _ := net.SplitHostPort(l.Addr().String())
	return port
}
