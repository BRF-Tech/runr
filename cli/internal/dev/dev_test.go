package dev

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/internal/runtimes"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func project(t *testing.T, yaml string, files map[string]string) (*projectfile.File, string) {
	t.Helper()
	dir := t.TempDir()
	files["runr.yaml"] = yaml
	for name, content := range files {
		p := filepath.Join(dir, filepath.FromSlash(name))
		_ = os.MkdirAll(filepath.Dir(p), 0o755)
		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	pf, err := projectfile.Load(filepath.Join(dir, "runr.yaml"))
	if err != nil {
		t.Fatal(err)
	}
	return pf, dir
}

func opts(pf *projectfile.File, dir, cache string) Options {
	return Options{Project: pf, Dir: dir, Resolver: runtimes.Builtin{}, CacheDir: cache, Out: io.Discard, Err: io.Discard}
}

func TestPlanPython(t *testing.T) {
	pf, dir := project(t, "name: hello\ntype: faas\nruntime: python\nhandler: app.run\nenv:\n  A: '1'\ndev:\n  env:\n    B: '2'\n",
		map[string]string{"app.py": "def run(e, c):\n    return 1\n", "requirements.txt": "requests\n"})
	o := opts(pf, dir, t.TempDir())
	o.Env = map[string]string{"C": "3", "A": "override"}
	p, err := MakePlan(context.Background(), o)
	if err != nil {
		t.Fatal(err)
	}
	if p.Image != "ghcr.io/brf-tech/runr/exec-python:3.13" || p.MainFile != "app.py" || p.HandlerName != "run" || p.Port != 8080 {
		t.Fatalf("plan = %+v", p)
	}
	args := strings.Join(p.RunArgs(), " ")
	for _, want := range []string{
		"run --rm --name runr-dev-hello-", "-p 127.0.0.1:8080:8080", "type=bind,source=" + dir + ",target=/src,readonly",
		"target=/app/vendor,readonly", "-e A=override", "-e B=2", "-e C=3", "-e PYTHONPATH=/app/vendor",
		"-e RUNR_FILENAME=app.py", "-e RUNR_HANDLER=run", "-e RUNR_MODE=http", "-e PORT=8080",
		"--entrypoint sh ghcr.io/brf-tech/runr/exec-python:3.13 -c",
	} {
		if !strings.Contains(args, want) {
			t.Fatalf("run args lack %q:\n%s", want, args)
		}
	}
	script := p.RunArgs()[len(p.RunArgs())-1]
	if !strings.Contains(script, "cp -R \"$f\" /app/code/") || !strings.HasSuffix(script, "exec ./entrypoint.sh") {
		t.Fatalf("start script = %s", script)
	}
	inst := strings.Join(p.InstallArgs(), " ")
	if !strings.Contains(inst, "pip install --no-cache-dir --target=/deps -r /src/requirements.txt") || !strings.Contains(inst, "target=/deps") {
		t.Fatalf("install args = %s", inst)
	}
}

func TestPlanNodeGoPHPAndCaas(t *testing.T) {
	pf, dir := project(t, "name: n\ntype: faas\nruntime: nodejs\n", map[string]string{"main.js": "exports.handler=()=>1\n", "package.json": "{}"})
	p, err := MakePlan(context.Background(), opts(pf, dir, t.TempDir()))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(p.RunArgs(), " "), filepath.Join(p.DepsDir, "node_modules")+",target=/app/code/node_modules,readonly") {
		t.Fatalf("node deps mount: %v", p.RunArgs())
	}

	pf, dir = project(t, "name: g\ntype: faas\nruntime: go\n", map[string]string{"main.go": "package main\n"})
	p, err = MakePlan(context.Background(), opts(pf, dir, t.TempDir()))
	if err != nil {
		t.Fatal(err)
	}
	args := strings.Join(p.RunArgs(), " ")
	if !strings.Contains(args, "target=/go/pkg/mod") || !strings.Contains(args, "go mod tidy") || p.DepsFile != "" {
		t.Fatalf("go plan: %s", args)
	}

	pf, dir = project(t, "name: p\ntype: xaas\nruntime: php\nfile: job.php\n", map[string]string{"job.php": "<?php\n", "composer.json": "{}"})
	p, err = MakePlan(context.Background(), opts(pf, dir, t.TempDir()))
	if err != nil {
		t.Fatal(err)
	}
	if p.Spec.BuilderImage != "ghcr.io/brf-tech/runr/reqs/composer:latest" || !strings.Contains(p.Spec.InstallScript, "platform.php 8.4") {
		t.Fatalf("php spec = %+v", p.Spec)
	}
	if !strings.Contains(strings.Join(p.RunArgs(), " "), "RUNR_FILENAME=job.php") {
		t.Fatal("xaas file not used")
	}

	pf, dir = project(t, "name: web\ntype: caas\nimage: nginx:1.27\nports: [8081]\ncommand: [nginx, -g]\nargs: ['daemon off;']\n", map[string]string{})
	o := opts(pf, dir, t.TempDir())
	o.Port = 9999
	p, err = MakePlan(context.Background(), o)
	if err != nil {
		t.Fatal(err)
	}
	args = strings.Join(p.RunArgs(), " ")
	if !strings.Contains(args, "127.0.0.1:9999:8081") || !strings.Contains(args, "--entrypoint nginx nginx:1.27 -g daemon off;") {
		t.Fatalf("caas args: %s", args)
	}
	if strings.Contains(args, "RUNR_MODE") {
		t.Fatal("caas got runtime variables")
	}
}

func TestPlanNeedsHandlerFile(t *testing.T) {
	pf, dir := project(t, "name: n\ntype: faas\nruntime: python\n", map[string]string{"other.py": "x"})
	if _, err := MakePlan(context.Background(), opts(pf, dir, t.TempDir())); err == nil || !strings.Contains(err.Error(), "main.py") {
		t.Fatalf("missing handler file: %v", err)
	}
}

func TestSessionDirIsStable(t *testing.T) {
	a, _ := SessionDir("/cache", "/work/app")
	b, _ := SessionDir("/cache", "/work/app")
	c, _ := SessionDir("/cache", "/work/other")
	if a != b || a == c {
		t.Fatalf("session dirs %s %s %s", a, b, c)
	}
}

type recorder struct {
	mu      sync.Mutex
	runs    [][]string
	starts  [][]string
	running map[string]chan struct{}
	started chan string
	runErr  error
}

func newRecorder() *recorder {
	return &recorder{running: map[string]chan struct{}{}, started: make(chan string, 16)}
}

func (r *recorder) Run(ctx context.Context, args []string, stdout, stderr io.Writer) error {
	r.mu.Lock()
	r.runs = append(r.runs, args)
	var ch chan struct{}
	if len(args) == 3 && args[0] == "rm" {
		ch = r.running[args[2]]
		delete(r.running, args[2])
	}
	r.mu.Unlock()
	if ch != nil {
		close(ch)
	}
	return r.runErr
}

type proc struct{ done chan struct{} }

func (p *proc) Wait() error { <-p.done; return nil }

func (r *recorder) Start(ctx context.Context, args []string, stdout, stderr io.Writer) (Process, error) {
	name := args[3]
	ch := make(chan struct{})
	r.mu.Lock()
	r.running[name] = ch
	r.starts = append(r.starts, args)
	r.mu.Unlock()
	r.started <- name
	return &proc{done: ch}, nil
}

func (r *recorder) installs() int {
	r.mu.Lock()
	defer r.mu.Unlock()
	n := 0
	for _, a := range r.runs {
		if len(a) > 3 && strings.HasSuffix(a[3], "-deps") {
			n++
		}
	}
	return n
}

func TestEnsureDepsInstallsOnlyWhenChanged(t *testing.T) {
	pf, dir := project(t, "name: n\ntype: faas\nruntime: python\n", map[string]string{"main.py": "x", "requirements.txt": "a\n"})
	p, err := MakePlan(context.Background(), opts(pf, dir, t.TempDir()))
	if err != nil {
		t.Fatal(err)
	}
	r := newRecorder()
	for i := 0; i < 2; i++ {
		if err := EnsureDeps(context.Background(), r, p, io.Discard, io.Discard); err != nil {
			t.Fatal(err)
		}
	}
	if r.installs() != 1 {
		t.Fatalf("%d installs for an unchanged file", r.installs())
	}
	_ = os.WriteFile(filepath.Join(dir, "requirements.txt"), []byte("a\nb\n"), 0o644)
	_ = EnsureDeps(context.Background(), r, p, io.Discard, io.Discard)
	if r.installs() != 2 {
		t.Fatalf("%d installs after a change", r.installs())
	}
	r.runErr = io.ErrUnexpectedEOF
	_ = os.WriteFile(filepath.Join(dir, "requirements.txt"), []byte("c\n"), 0o644)
	if err := EnsureDeps(context.Background(), r, p, io.Discard, io.Discard); err == nil {
		t.Fatal("a failed install succeeded")
	}
}

func TestRunRestartsOnChangeAndStops(t *testing.T) {
	pf, dir := project(t, "name: hot\ntype: faas\nruntime: python\ntrigger:\n  type: schedule\n  schedule: '* * * * *'\n",
		map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	cache := t.TempDir()
	r := newRecorder()
	o := opts(pf, dir, cache)
	o.Docker = r
	o.Watch = true
	o.PollInterval = 10 * time.Millisecond
	var errOut syncBuffer
	o.Err = &errOut

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- Run(ctx, o) }()

	<-r.started
	st, err := ReadState(cache, dir)
	if err != nil || st.URL != "http://localhost:8080" || st.Trigger != runr.TriggerSchedule {
		t.Fatalf("state = %+v, %v", st, err)
	}

	time.Sleep(30 * time.Millisecond)
	future := time.Now().Add(2 * time.Second)
	_ = os.WriteFile(filepath.Join(dir, "main.py"), []byte("def handler(e, c):\n    return 2\n"), 0o644)
	_ = os.Chtimes(filepath.Join(dir, "main.py"), future, future)
	select {
	case <-r.started:
	case <-time.After(5 * time.Second):
		t.Fatal("no restart after a file change")
	}

	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("Run did not stop")
	}
	if !strings.Contains(errOut.String(), "Change detected, restarting") || !strings.Contains(errOut.String(), "runr dev invoke") {
		t.Fatalf("stderr = %s", errOut.String())
	}
	if _, err := ReadState(cache, dir); err == nil {
		t.Fatal("state file left behind")
	}
}

type syncBuffer struct {
	mu sync.Mutex
	b  strings.Builder
}

func (s *syncBuffer) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.Write(p)
}

func (s *syncBuffer) String() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.String()
}

func TestSnapshotIgnoresDependencyFolders(t *testing.T) {
	_, dir := project(t, "name: n\ntype: faas\nruntime: python\n", map[string]string{"main.py": "x"})
	a, _ := Snapshot(dir, watchIgnore)
	_ = os.MkdirAll(filepath.Join(dir, "__pycache__"), 0o755)
	_ = os.WriteFile(filepath.Join(dir, "__pycache__", "m.pyc"), []byte("x"), 0o644)
	b, _ := Snapshot(dir, watchIgnore)
	if a != b {
		t.Fatal("a __pycache__ change counts as a change")
	}
	_ = os.WriteFile(filepath.Join(dir, "new.py"), []byte("y"), 0o644)
	c, _ := Snapshot(dir, watchIgnore)
	if c == b {
		t.Fatal("a new file is not a change")
	}
}

func TestBuildRequest(t *testing.T) {
	ctx := context.Background()
	req, err := BuildRequest(ctx, InvokeOptions{URL: "http://localhost:8080/", Trigger: runr.TriggerHTTP})
	if err != nil || req.Method != http.MethodGet || req.URL.String() != "http://localhost:8080/" {
		t.Fatalf("http without event = %v %v", req, err)
	}
	req, _ = BuildRequest(ctx, InvokeOptions{URL: "http://x", Trigger: runr.TriggerHTTP, Event: `{"a": 1}`, Method: "put", Path: "items"})
	if req.Method != http.MethodPut || req.URL.Path != "/items" || req.Header.Get("Content-Type") != "application/json" {
		t.Fatalf("http with event = %v", req)
	}
	req, _ = BuildRequest(ctx, InvokeOptions{URL: "http://x", Trigger: runr.TriggerQueue, Queue: "orders"})
	if req.Method != http.MethodPost || req.Header.Get("ce-specversion") != "1.0" ||
		req.Header.Get("ce-source") != "/redisstream/orders/runr-dev" || req.Header.Get("X-RunR-Delivery-Attempt") != "1" {
		t.Fatalf("queue request headers = %v", req.Header)
	}
	body, _ := io.ReadAll(req.Body)
	if string(body) != "{}" {
		t.Fatalf("queue default body = %s", body)
	}
	req, _ = BuildRequest(ctx, InvokeOptions{URL: "http://x", Trigger: runr.TriggerSchedule, Header: map[string]string{"X-Test": "1"}})
	body, _ = io.ReadAll(req.Body)
	if string(body) != `{"trigger":"schedule"}` || req.Header.Get("X-Test") != "1" {
		t.Fatalf("schedule = %s %v", body, req.Header)
	}
	if _, err := BuildRequest(ctx, InvokeOptions{URL: "http://x", Event: "{"}); err == nil {
		t.Fatal("invalid JSON accepted")
	}
}

func TestInvoke(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Reply", "yes")
		w.WriteHeader(201)
		w.Write([]byte("created"))
	}))
	defer srv.Close()
	res, err := Invoke(context.Background(), InvokeOptions{URL: srv.URL, Trigger: runr.TriggerHTTP, Event: "{}"})
	if err != nil || res.Status != 201 || res.Body != "created" || res.Headers["X-Reply"] != "yes" {
		t.Fatalf("invoke = %+v, %v", res, err)
	}
	if _, err := Invoke(context.Background(), InvokeOptions{URL: "http://127.0.0.1:1"}); err == nil || !strings.Contains(err.Error(), "runr dev") {
		t.Fatalf("unreachable dev server: %v", err)
	}
}
