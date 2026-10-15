package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/internal/dev"
	"github.com/brf-tech/runr/cli/pkg/config"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// harness runs runr commands against the fake API (runrtest), with an isolated config file,
// cache directory and environment.
type harness struct {
	t       *testing.T
	srv     *runrtest.Server
	cfgPath string
	cache   string
	opened  []string
	docker  *fakeDocker
	release string
	// terminal makes the App behave as if stdin were a terminal.
	terminal bool
	secrets  []string
}

type result struct {
	out  string
	err  string
	code int
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	h := &harness{t: t, srv: runrtest.New(t), docker: &fakeDocker{}}
	dir := t.TempDir()
	h.cfgPath = filepath.Join(dir, "config.yaml")
	h.cache = filepath.Join(dir, "cache")
	t.Setenv(config.EnvConfig, h.cfgPath)
	for _, k := range []string{config.EnvAPIURL, config.EnvToken, config.EnvContext, EnvProject, "RUNR_RUNTIME_REGISTRY"} {
		t.Setenv(k, "")
		os.Unsetenv(k)
	}
	return h
}

// login stores a context for the fake API without running `runr login`.
func (h *harness) login() *harness { return h.loginWith(runrtest.DefaultToken) }

// loginWith stores a context that authenticates with token.
func (h *harness) loginWith(token string) *harness {
	h.t.Helper()
	f, err := config.LoadFrom(h.cfgPath)
	if err != nil {
		h.t.Fatal(err)
	}
	f.Set("test", &config.Context{APIURL: h.srv.URL, Token: token, User: "ada"})
	if err := f.Save(); err != nil {
		h.t.Fatal(err)
	}
	return h
}

func (h *harness) app(stdin string, out, errOut io.Writer) *App {
	return &App{
		In: strings.NewReader(stdin), Out: out, Err: errOut,
		PollInterval: time.Millisecond,
		HTTPClient:   h.srv.Client(),
		IsTerminal:   func() bool { return h.terminal },
		ReadSecret: func(string) (string, error) {
			if len(h.secrets) == 0 {
				return "", io.EOF
			}
			s := h.secrets[0]
			h.secrets = h.secrets[1:]
			return s, nil
		},
		OpenURL:        func(u string) error { h.opened = append(h.opened, u); return nil },
		Docker:         h.docker,
		DevCacheDir:    h.cache,
		MaxFollowPolls: 3,
		ReleaseURL:     h.release,
	}
}

func (h *harness) run(args ...string) result { return h.runStdin("", args...) }

func (h *harness) runStdin(stdin string, args ...string) result {
	h.t.Helper()
	var out, errOut bytes.Buffer
	code := Execute(h.app(stdin, &out, &errOut), args)
	return result{out: out.String(), err: errOut.String(), code: code}
}

// mustRun fails the test unless the command exits 0.
func (h *harness) mustRun(args ...string) result {
	h.t.Helper()
	r := h.run(args...)
	if r.code != 0 {
		h.t.Fatalf("runr %s: exit %d\nstdout: %s\nstderr: %s", strings.Join(args, " "), r.code, r.out, r.err)
	}
	return r
}

// expectCode fails the test unless the command exits with code.
func (h *harness) expectCode(code int, args ...string) result {
	h.t.Helper()
	r := h.run(args...)
	if r.code != code {
		h.t.Fatalf("runr %s: exit %d, want %d\nstdout: %s\nstderr: %s", strings.Join(args, " "), r.code, code, r.out, r.err)
	}
	return r
}

// project writes runr.yaml and files into a new directory and returns it.
func (h *harness) project(yaml string, files map[string]string) string {
	h.t.Helper()
	dir := h.t.TempDir()
	write(h.t, filepath.Join(dir, "runr.yaml"), yaml)
	for name, content := range files {
		write(h.t, filepath.Join(dir, name), content)
	}
	return dir
}

func write(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func decodeJSON(t *testing.T, s string, v any) {
	t.Helper()
	if err := json.Unmarshal([]byte(s), v); err != nil {
		t.Fatalf("decode JSON output: %v\n%s", err, s)
	}
}

func contains(t *testing.T, haystack, needle string) {
	t.Helper()
	if !strings.Contains(haystack, needle) {
		t.Fatalf("output does not contain %q:\n%s", needle, haystack)
	}
}

func notContains(t *testing.T, haystack, needle string) {
	t.Helper()
	if strings.Contains(haystack, needle) {
		t.Fatalf("output contains %q:\n%s", needle, haystack)
	}
}

const pythonHandler = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"hi\"}\n"

const helloYAML = `name: hello-api
type: faas
runtime: python
env:
  GREETING: Hello
`

// deployHello creates and deploys the hello-api project and returns its directory.
func (h *harness) deployHello() string {
	h.t.Helper()
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	h.mustRun("deploy", "--dir", dir)
	return dir
}

// fakeDocker records docker invocations; started containers run until stopped.
type fakeDocker struct {
	mu      sync.Mutex
	runs    [][]string
	starts  [][]string
	running map[string]chan struct{}
	// onStart is called after a container started.
	onStart func(args []string)
	// onRun, when set, answers `docker args…` of Run (output and error); nil answers success.
	onRun func(args []string, stdout io.Writer) error
}

func (d *fakeDocker) Run(ctx context.Context, args []string, stdout, stderr io.Writer) error {
	d.mu.Lock()
	d.runs = append(d.runs, args)
	if d.onRun != nil {
		hook := d.onRun
		d.mu.Unlock()
		if err := hook(args, stdout); err != nil {
			return err
		}
		d.mu.Lock()
	}
	var ch chan struct{}
	if len(args) >= 3 && args[0] == "rm" && args[1] == "-f" {
		ch = d.running[args[2]]
		delete(d.running, args[2])
	}
	d.mu.Unlock()
	if ch != nil {
		close(ch)
	}
	return nil
}

type fakeProc struct{ done chan struct{} }

func (p *fakeProc) Wait() error { <-p.done; return nil }

func (d *fakeDocker) Start(ctx context.Context, args []string, stdout, stderr io.Writer) (dev.Process, error) {
	d.mu.Lock()
	if d.running == nil {
		d.running = map[string]chan struct{}{}
	}
	name := ""
	for i, a := range args {
		if a == "--name" && i+1 < len(args) {
			name = args[i+1]
		}
	}
	ch := make(chan struct{})
	d.running[name] = ch
	d.starts = append(d.starts, args)
	cb := d.onStart
	d.mu.Unlock()
	if cb != nil {
		go cb(args)
	}
	return &fakeProc{done: ch}, nil
}

func (d *fakeDocker) startCount() int {
	d.mu.Lock()
	defer d.mu.Unlock()
	return len(d.starts)
}
