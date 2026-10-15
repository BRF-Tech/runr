package dev

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net/http"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/internal/runtimes"
	"github.com/runrsh/core/cli/pkg/runr"
)

// devPluginResolver answers every runtime with one plugin spec.
type devPluginResolver struct{ spec *runtimes.Spec }

func (r devPluginResolver) Resolve(context.Context, string, string) (*runtimes.Spec, error) {
	s := *r.spec
	return &s, nil
}

func (r devPluginResolver) List(context.Context) ([]runtimes.Info, error) { return nil, nil }

func devPluginSpec(m *runr.RuntimeManifest, version string, files map[string]string) *runtimes.Spec {
	mv := m.Version(version)
	return &runtimes.Spec{ID: m.ID, Version: mv.Version, Image: mv.Image, Extension: m.Extension(), CodeDir: "/app/code",
		Workdir: "/app", Port: 8080, Compiled: m.Build != nil,
		Plugin: &runtimes.Plugin{Manifest: m, Files: files, Version: mv}}
}

// devCManifest is examples/runtimes/c: compiled with gcc, exec mode with raw input.
func devCManifest() *runr.RuntimeManifest {
	f := false
	return &runr.RuntimeManifest{ID: "c", Name: "C", Extensions: []string{"c", "h"},
		Versions: []runr.ManifestVersion{{Version: "17", Image: "ghcr.io/runrsh/reqs/alpine:3.21", Default: true}},
		Handler:  runr.ManifestHandler{File: "main", Function: "handler"},
		Build: &runr.ManifestBuild{Image: "{image}", RunImage: "ghcr.io/runrsh/reqs/alpine:3.21", Source: &f,
			Command: "apk add --no-cache gcc musl-dev >/dev/null && gcc -O2 -static -o /build/out/handler {file}",
			Files:   map[string]runr.ManifestBuildFile{"helper.h": {Content: "/* {file} */"}}},
		Run:      runr.ManifestRun{Mode: "exec", Input: "raw", Command: []string{"/app/handler"}},
		Triggers: []string{"http", "schedule", "queue"}}
}

func TestPluginPlanCompiledExec(t *testing.T) {
	t.Setenv(EnvWrapperImage, "")
	t.Setenv(runtimes.EnvRegistry, "")
	pf, dir := project(t, "name: hello-c\ntype: xaas\nruntime: c\nfile: main.c\nresources:\n  timeout_seconds: 12\n",
		map[string]string{"main.c": "int main(void) { return 0; }\n"})
	o := opts(pf, dir, t.TempDir())
	o.Resolver = devPluginResolver{spec: devPluginSpec(devCManifest(), "", nil)}
	p, err := MakePlan(context.Background(), o)
	if err != nil {
		t.Fatal(err)
	}
	pp := p.Plugin
	if pp == nil || pp.Mode != "exec" || !pp.Wrapper || pp.FunctionBuild == nil || p.Image != p.Container+":dev" {
		t.Fatalf("plan = %+v / %+v", p, pp)
	}
	df := pp.Dockerfile
	for _, want := range []string{
		"FROM ghcr.io/runrsh/reqs/alpine:3.21 AS build\nWORKDIR /build/code\nCOPY code/ /build/code/\n",
		"RUN mkdir -p /build/out && apk add --no-cache gcc musl-dev >/dev/null && gcc -O2 -static -o /build/out/handler main.c\n",
		"\nFROM ghcr.io/runrsh/reqs/alpine:3.21\nWORKDIR /app\n",
		"COPY --from=build /build/out/ /app/\n", "COPY .runr/runr-wrapper /runr/runr-wrapper\n",
		"RUNR_MODE=http RUNR_HANDLER=handler RUNR_FILENAME=main.c", "RUNR_INPUT=raw", `RUNR_COMMAND="[\"/app/handler\"]"`,
		"RUNR_WRAPPER_MODE=exec", "EXPOSE 8080\n", `ENTRYPOINT ["/runr/runr-wrapper"]`,
	} {
		if !strings.Contains(df, want) {
			t.Fatalf("Dockerfile lacks %q:\n%s", want, df)
		}
	}
	if strings.Contains(df, "COPY code/ /app/code/") {
		t.Fatalf("source: false keeps the code out of the final image:\n%s", df)
	}
	if pp.buildFiles["helper.h"] != "/* {file} */" {
		t.Fatalf("build files = %v", pp.buildFiles)
	}
	args := strings.Join(p.RunArgs(), " ")
	for _, want := range []string{"-e RUNR_TIMEOUT=12", "-e RUNR_RUNTIME=c", "-e RUNR_RUNTIME_VERSION=17",
		"--entrypoint /runr/runr-wrapper " + p.Image} {
		if !strings.Contains(args, want) {
			t.Fatalf("docker run lacks %q: %s", want, args)
		}
	}
	if strings.Contains(args, "target=/app/code") {
		t.Fatalf("built functions carry their code: %s", args)
	}
	prep := p.PrepareArgs()
	if len(prep) != 2 || prep[0][len(prep[0])-2] != "install" || prep[1][0] != "build" {
		t.Fatalf("prepare = %v", prep)
	}
}

func TestPluginPlanHTTPRuntime(t *testing.T) {
	m := &runr.RuntimeManifest{ID: "deno", Extensions: []string{"ts"},
		Versions: []runr.ManifestVersion{{Version: "2.1", Image: "denoland/deno:2.1", Default: true}},
		Handler:  runr.ManifestHandler{File: "main", Function: "handler"},
		Run: runr.ManifestRun{Mode: "http", Schedule: "invoke",
			Command: []string{"deno", "run", "-A", "/app/code/{file}"}, Env: runr.EnvMap{"DENO_DIR": "/tmp/{version}"}}}
	pf, dir := project(t, "name: api\ntype: faas\nruntime: deno\nhandler: server.serve\ntrigger:\n  type: queue\n  queue: jobs\n",
		map[string]string{"server.ts": "Deno.serve(() => new Response('hi'))\n"})
	o := opts(pf, dir, t.TempDir())
	o.Resolver = devPluginResolver{spec: devPluginSpec(m, "", nil)}
	p, err := MakePlan(context.Background(), o)
	if err != nil {
		t.Fatal(err)
	}
	if p.MainFile != "server.ts" || p.HandlerName != "serve" || p.Plugin.Wrapper || !p.Plugin.CloudEventSchedule {
		t.Fatalf("plan = %+v %+v", p, p.Plugin)
	}
	args := strings.Join(p.RunArgs(), " ")
	for _, want := range []string{"-e RUNR_TRIGGER=queue", "-e DENO_DIR=/tmp/2.1",
		"--entrypoint deno denoland/deno:2.1 run -A /app/code/server.ts", "target=/app/code,readonly"} {
		if !strings.Contains(args, want) {
			t.Fatalf("docker run lacks %q: %s", want, args)
		}
	}
	if strings.Contains(args, "target=/runr") || strings.Contains(args, "RUNR_WRAPPER_MODE") || len(p.PrepareArgs()) != 0 {
		t.Fatalf("an http runtime serves without the wrapper: %s %v", args, p.PrepareArgs())
	}
}

func TestPluginRuntimeImageBuilds(t *testing.T) {
	cache := t.TempDir()
	v := &runr.ManifestVersion{Version: "1.0", Dockerfile: "Dockerfile", BuildArgs: runr.EnvMap{"Q": "1"}}
	b, err := runtimeImageBuild(cache, "q", v, map[string]string{"Dockerfile": "FROM alpine\n"})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(b.Tag, "runr-dev-runtime-q:1.0-") || b.Dockerfile != filepath.Join(b.Context, "Dockerfile") || !b.Cached {
		t.Fatalf("dockerfile build = %+v", b)
	}
	if got := strings.Join(b.Args(), " "); !strings.Contains(got, "--build-arg Q=1") || !strings.HasSuffix(got, b.Context) {
		t.Fatalf("args = %s", got)
	}
	// Another Dockerfile is another image.
	b2, _ := runtimeImageBuild(cache, "q", v, map[string]string{"Dockerfile": "FROM busybox\n"})
	if b2.Tag == b.Tag {
		t.Fatal("the tag does not follow the source")
	}
	if _, err := runtimeImageBuild(cache, "q", v, nil); err == nil {
		t.Fatal("a dockerfile version without its file built")
	}

	g := &runr.ManifestVersion{Version: "2.0", Git: &runr.ManifestGit{URL: "https://github.com/acme/q.git", Ref: "main", Path: "runtime/", Dockerfile: "build/Dockerfile"}}
	gb, err := runtimeImageBuild(cache, "q", g, nil)
	if err != nil {
		t.Fatal(err)
	}
	if gb.Context != "https://github.com/acme/q.git#main:runtime" || gb.Dockerfile != "build/Dockerfile" || gb.Files != nil {
		t.Fatalf("git build = %+v", gb)
	}
}

func TestPluginEntrypointAndPlaceholders(t *testing.T) {
	m := &runr.RuntimeManifest{Extensions: []string{"rs"}, Handler: runr.ManifestHandler{File: "lib", Function: "handle"}}
	cases := []struct{ yaml, file, fn string }{
		{"name: a\ntype: faas\nruntime: r\n", "lib.rs", "handle"},
		{"name: a\ntype: faas\nruntime: r\nhandler: app.run\n", "app.rs", "run"},
		{"name: a\ntype: xaas\nruntime: r\n", "lib.rs", "handle"},
		{"name: a\ntype: xaas\nruntime: r\nfile: x.rs\nhandler: go\n", "x.rs", "go"},
	}
	for _, c := range cases {
		pf, _ := project(t, c.yaml, map[string]string{})
		if file, fn := pluginEntrypoint(pf, m); file != c.file || fn != c.fn {
			t.Errorf("%q: %s %s, want %s %s", c.yaml, file, fn, c.file, c.fn)
		}
	}

	v := map[string]string{"version": "8.4", "file": "main.go", "handler": "handle", "mode": "http"}
	for in, want := range map[string]string{
		"{file} {handler} {Handler}":        "main.go handle Handle",
		"{if:semver}pin {semver}{end}ok":    "pin 8.4ok",
		"${HOME} {\"statusCode\": 200} {x}": "${HOME} {\"statusCode\": 200} {x}",
		"{image}|{trigger}":                 "|",
	} {
		if got := renderPlaceholders(in, v); got != want {
			t.Errorf("render(%q) = %q, want %q", in, got, want)
		}
	}
	if got := renderPlaceholders("{if:semver}x{end}y", map[string]string{"version": "latest"}); got != "y" {
		t.Errorf("semver of a non-numeric version: %q", got)
	}
}

// devExecDocker records Run calls and answers them with a canned result.
type devExecDocker struct {
	args   []string
	stdout string
	err    error
}

func (d *devExecDocker) Run(_ context.Context, args []string, stdout, stderr io.Writer) error {
	d.args = args
	_, _ = io.WriteString(stdout, d.stdout)
	_, _ = io.WriteString(stderr, "[runr-wrapper] tick\n")
	return d.err
}

func (d *devExecDocker) Start(context.Context, []string, io.Writer, io.Writer) (Process, error) {
	return nil, errors.New("not used")
}

func TestInvokeScheduleThroughTheWrapper(t *testing.T) {
	d := &devExecDocker{stdout: "tick done\n"}
	res, err := InvokeSchedule(context.Background(), d, "runr-dev-x")
	if err != nil || res.Status != 200 || res.Body != "tick done" || !strings.Contains(res.Logs, "[runr-wrapper]") {
		t.Fatalf("tick = %+v %v", res, err)
	}
	if strings.Join(d.args, " ") != "exec -e RUNR_TRIGGER=schedule runr-dev-x /runr/runr-wrapper" {
		t.Fatalf("args = %v", d.args)
	}
	d = &devExecDocker{stdout: "", err: errors.New("exit status 1")}
	if res, _ := InvokeSchedule(context.Background(), d, "runr-dev-x"); res.Status != 500 {
		t.Fatalf("failed tick = %+v", res)
	}
}

func TestBuildRequestScheduleCloudEvent(t *testing.T) {
	req, err := BuildRequest(context.Background(), InvokeOptions{URL: "http://localhost:8080", Trigger: runr.TriggerSchedule, ScheduleCloudEvent: true})
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(req.Body)
	if req.Method != http.MethodPost || req.Header.Get("ce-type") != "dev.runr.schedule.tick" ||
		req.Header.Get("ce-source") != "/runr/schedule" || req.Header.Get("ce-specversion") != "1.0" ||
		req.Header.Get("X-RunR-Trigger") != "" || !bytes.Equal(body, []byte(`{"trigger":"schedule"}`)) {
		t.Fatalf("tick request = %v %s", req.Header, body)
	}
}
