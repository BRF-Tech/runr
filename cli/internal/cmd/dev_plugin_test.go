package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/internal/dev"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

const devPluginScript = "#!/usr/bin/env bash\necho \"hello from bash\"\n"

// devPluginProject registers the bash exec runtime and writes an xaas project on it.
func devPluginProject(t *testing.T, h *harness, trigger string) string {
	t.Helper()
	h.srv.SeedRuntime(rtBashManifest, nil)
	yaml := "name: hello-sh\ntype: xaas\nruntime: bash\nfile: main.sh\nenv:\n  GREETING: Hello\n"
	if trigger != "" {
		yaml += "trigger:\n  type: " + trigger + "\n"
		if trigger == runr.TriggerSchedule {
			yaml += "  schedule: \"*/5 * * * *\"\n"
		}
		if trigger == runr.TriggerQueue {
			yaml += "  queue: jobs\n"
		}
	}
	return h.project(yaml, map[string]string{"main.sh": devPluginScript})
}

func TestDevPluginExecPrint(t *testing.T) {
	h := newHarness(t).login()
	dir := devPluginProject(t, h, "")
	r := h.mustRun("dev", "--dir", dir, "--print", "-o", "json", "-e", "EXTRA=1")
	var plan struct {
		Image      string     `json:"image"`
		Runtime    string     `json:"runtime"`
		Mode       string     `json:"run_mode"`
		Wrapper    bool       `json:"wrapper"`
		Run        []string   `json:"docker_run"`
		Prepare    [][]string `json:"docker_prepare"`
		Dockerfile string     `json:"dockerfile"`
	}
	decodeJSON(t, r.out, &plan)
	if plan.Image != "bash:5" || plan.Runtime != "bash" || plan.Mode != "exec" || !plan.Wrapper || plan.Dockerfile != "" {
		t.Fatalf("plan = %+v", plan)
	}
	run := strings.Join(plan.Run, " ")
	for _, want := range []string{
		"target=/app/code,readonly", "target=/runr,readonly", "-p 127.0.0.1:8080:8080",
		"RUNR_WRAPPER_MODE=exec", "RUNR_INPUT=raw", `RUNR_COMMAND=["bash","/app/code/main.sh"]`,
		"RUNR_TRIGGER=http", "RUNR_HANDLER=handler", "RUNR_FILENAME=main.sh", "RUNR_CODE_DIR=/app/code",
		"RUNR_RUNTIME=bash", "RUNR_RUNTIME_VERSION=5", "RUNR_TIMEOUT=30", "RUNR_MODE=http", "PORT=8080",
		"GREETING=Hello", "EXTRA=1", "--entrypoint /runr/runr-wrapper bash:5",
	} {
		if !strings.Contains(run, want) {
			t.Fatalf("docker run lacks %q:\n%s", want, run)
		}
	}
	if strings.HasSuffix(run, "bash:5 bash") {
		t.Fatalf("the wrapper takes the command from RUNR_COMMAND: %s", run)
	}
	// The init container's command: runr-wrapper install into the directory mounted at /runr.
	if len(plan.Prepare) != 1 || len(plan.Prepare[0]) != 7 ||
		strings.Join(plan.Prepare[0][:3], " ") != "run --rm --mount" ||
		!strings.HasPrefix(plan.Prepare[0][3], "type=bind,source=") || !strings.HasSuffix(plan.Prepare[0][3], ",target=/runr") ||
		strings.Join(plan.Prepare[0][4:], " ") != "ghcr.io/brf-tech/runr/wrapper:"+dev.WrapperVersion+" install /runr/runr-wrapper" {
		t.Fatalf("prepare = %v", plan.Prepare)
	}

	// The project's variables win over the contract environment.
	r = h.mustRun("dev", "--dir", dir, "--print", "-o", "json", "-e", "RUNR_TIMEOUT=5")
	decodeJSON(t, r.out, &plan)
	if run := strings.Join(plan.Run, " "); !strings.Contains(run, "RUNR_TIMEOUT=5") || strings.Contains(run, "RUNR_TIMEOUT=30") {
		t.Fatalf("user env does not win: %s", run)
	}

	// A mirror registry and RUNR_WRAPPER_IMAGE choose the wrapper image.
	t.Setenv(dev.EnvWrapperImage, "registry.example.com/runr-wrapper:9")
	r = h.mustRun("dev", "--dir", dir, "--print")
	contains(t, r.out, "registry.example.com/runr-wrapper:9 install /runr/runr-wrapper")
}

func TestDevPluginRunsWrapperAndSyncsCode(t *testing.T) {
	h := newHarness(t).login()
	dir := devPluginProject(t, h, "")
	write(t, filepath.Join(dir, "README.md"), "not uploaded for xaas\n")
	h.docker.onStart = func(args []string) {
		for i, a := range args {
			if a == "--name" {
				_ = h.docker.Run(context.Background(), []string{"rm", "-f", args[i+1]}, io.Discard, io.Discard)
			}
		}
	}
	r := h.run("dev", "--dir", dir, "--no-watch")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	contains(t, r.err, "Installing the universal wrapper from ghcr.io/brf-tech/runr/wrapper:"+dev.WrapperVersion)
	contains(t, r.err, "Starting hello-sh on http://localhost:8080 (bash:5)")
	install := false
	for _, a := range h.docker.runs {
		if len(a) > 2 && a[len(a)-2] == "install" && a[len(a)-1] == "/runr/runr-wrapper" {
			install = true
		}
	}
	if !install {
		t.Fatalf("the wrapper was not installed: %v", h.docker.runs)
	}
	// The code the cluster gets (the xaas script) is what /app/code holds.
	root, _ := dev.SessionDir(h.cache, dir)
	entries, err := os.ReadDir(filepath.Join(root, "code"))
	if err != nil || len(entries) != 1 || entries[0].Name() != "main.sh" {
		t.Fatalf("code dir = %v %v", entries, err)
	}
	start := strings.Join(h.docker.starts[0], " ")
	contains(t, start, "source="+filepath.Join(root, "code")+",target=/app/code,readonly")
}

func TestDevPluginDockerfileVersionAndBuild(t *testing.T) {
	h := newHarness(t).login()
	files := map[string]string{"Dockerfile": "FROM golang:1.23-alpine\n"}
	h.srv.SeedRuntime(strings.Replace(rtQ2Manifest, "extensions: [q]\n", "extensions: [q]\ndependencies:\n  file: deps.txt\n  install: \"q-install /build/code/deps.txt /build/lib\"\n  copy:\n    - {from: /build/lib, to: /app/lib}\n  env:\n    Q_PATH: /app/lib\n", 1), files)
	dir := h.project("name: qfn\ntype: faas\nruntime: q2\nhandler: main.handler\n",
		map[string]string{"main.q": "fn handler event => 1\n", "deps.txt": "json\n"})

	// The runtime image is built locally from the uploaded Dockerfile (once: the tag carries
	// the source digest), the function with the cluster's two-stage Dockerfile.
	h.docker.onRun = func(args []string, _ io.Writer) error {
		if len(args) > 1 && args[0] == "image" && args[1] == "inspect" {
			return errors.New("No such image")
		}
		return nil
	}
	r := h.mustRun("dev", "--dir", dir, "--print", "-o", "json")
	var plan struct {
		Image      string     `json:"image"`
		Run        []string   `json:"docker_run"`
		Prepare    [][]string `json:"docker_prepare"`
		Dockerfile string     `json:"dockerfile"`
	}
	decodeJSON(t, r.out, &plan)
	if !strings.HasPrefix(plan.Image, "runr-dev-qfn-") || !strings.HasSuffix(plan.Image, ":dev") {
		t.Fatalf("image = %s", plan.Image)
	}
	if len(plan.Prepare) != 3 || plan.Prepare[1][0] != "build" || !strings.HasPrefix(plan.Prepare[1][2], "runr-dev-runtime-q2:1.0-") ||
		plan.Prepare[2][2] != plan.Image {
		t.Fatalf("prepare = %v", plan.Prepare)
	}
	for _, want := range []string{
		"FROM runr-dev-runtime-q2:1.0-", "AS build", "COPY code/ /build/code/",
		"RUN q-install /build/code/deps.txt /build/lib", "COPY code/ /app/code/", "COPY --from=build /build/lib /app/lib",
		"COPY .runr/runr-wrapper /runr/runr-wrapper", "Q_PATH=/app/lib", "RUNR_WRAPPER_MODE=persistent", "RUNR_WORKERS=2",
		`ENTRYPOINT ["/runr/runr-wrapper"]`, "EXPOSE 8080",
	} {
		if !strings.Contains(plan.Dockerfile, want) {
			t.Fatalf("Dockerfile lacks %q:\n%s", want, plan.Dockerfile)
		}
	}
	run := strings.Join(plan.Run, " ")
	if strings.Contains(run, "target=/app/code") || strings.Contains(run, "target=/runr") {
		t.Fatalf("a built function carries code and wrapper in its image: %s", run)
	}
	contains(t, run, "--entrypoint /runr/runr-wrapper "+plan.Image)

	// Running it: wrapper install, runtime build, function build (context written), start.
	root, _ := dev.SessionDir(h.cache, dir)
	h.docker.onRun = func(args []string, _ io.Writer) error {
		if len(args) > 1 && args[0] == "image" && args[1] == "inspect" {
			return errors.New("No such image")
		}
		if len(args) > 3 && args[len(args)-2] == "install" {
			// What the init container does: the binary lands in the mounted directory.
			src := strings.TrimSuffix(strings.TrimPrefix(args[3], "type=bind,source="), ",target=/runr")
			return os.WriteFile(filepath.Join(src, "runr-wrapper"), []byte("\x7fELF"), 0o755)
		}
		return nil
	}
	h.docker.onStart = func(args []string) {
		for i, a := range args {
			if a == "--name" {
				_ = h.docker.Run(context.Background(), []string{"rm", "-f", args[i+1]}, io.Discard, io.Discard)
			}
		}
	}
	r = h.run("dev", "--dir", dir, "--no-watch")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	contains(t, r.err, "Building runtime image q2 1.0")
	contains(t, r.err, "Building the function")
	builds := 0
	for _, a := range h.docker.runs {
		if a[0] == "build" {
			builds++
		}
	}
	if builds != 2 {
		t.Fatalf("%d docker builds, want 2: %v", builds, h.docker.runs)
	}
	for _, f := range []string{"Dockerfile", filepath.Join("code", "main.q"), filepath.Join("code", "deps.txt"), filepath.Join(".runr", "runr-wrapper")} {
		if _, err := os.Stat(filepath.Join(root, "build", f)); err != nil {
			t.Fatalf("build context lacks %s: %v", f, err)
		}
	}
}

func TestDevPluginBuildFailureStopsWithoutWatch(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedRuntime(rtQ2Manifest, map[string]string{"Dockerfile": "FROM alpine\n"})
	dir := h.project("name: qfn\ntype: xaas\nruntime: q2\nfile: main.q\n", map[string]string{"main.q": "fn handler event => 1\n"})
	h.docker.onRun = func(args []string, _ io.Writer) error {
		if args[0] == "image" || args[0] == "build" {
			return errors.New("exit status 1")
		}
		return nil
	}
	r := h.expectCode(ExitError, "dev", "--dir", dir, "--no-watch")
	contains(t, r.err, "the build of runtime image q2 1.0 failed")
	if h.docker.startCount() != 0 {
		t.Fatal("a container started without its image")
	}
}

func TestDevPluginInvokeSchedule(t *testing.T) {
	h := newHarness(t).login()
	dir := devPluginProject(t, h, runr.TriggerSchedule)

	// The session state says how a tick runs.
	r := h.mustRun("dev", "--dir", dir, "--print")
	contains(t, r.out, "RUNR_TRIGGER=http")
	root, err := dev.SessionDir(h.cache, dir)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(root, 0o755); err != nil {
		t.Fatal(err)
	}
	st, _ := json.Marshal(dev.State{URL: "http://127.0.0.1:1", Trigger: runr.TriggerSchedule, Container: "runr-dev-hello-sh-abc123",
		Dir: dir, Runtime: "bash", ScheduleExec: true})
	write(t, filepath.Join(root, "state.json"), string(st))

	h.docker.onRun = func(args []string, stdout io.Writer) error {
		if args[0] == "exec" {
			_, _ = io.WriteString(stdout, "hello from bash\n")
		}
		return nil
	}
	r = h.mustRun("dev", "invoke", "--dir", dir)
	contains(t, r.out, "hello from bash")
	last := h.docker.runs[len(h.docker.runs)-1]
	if strings.Join(last, " ") != "exec -e RUNR_TRIGGER=schedule runr-dev-hello-sh-abc123 /runr/runr-wrapper" {
		t.Fatalf("schedule tick = %v", last)
	}

	// A failing tick (exit 1 of the wrapper) answers 500.
	h.docker.onRun = func(args []string, stdout io.Writer) error {
		if args[0] == "exec" {
			_, _ = io.WriteString(stdout, "boom\n")
			return errors.New("exit status 1")
		}
		return nil
	}
	r = h.expectCode(ExitError, "dev", "invoke", "--dir", dir)
	contains(t, r.out, "boom")
	contains(t, r.err, "the function answered 500")
	r = h.mustRun("dev", "invoke", "--dir", dir, "-o", "json")
	var out struct {
		Status int `json:"status"`
	}
	decodeJSON(t, r.out, &out)
	if out.Status != 500 {
		t.Fatalf("failed tick = %+v", out)
	}
}

func TestDevPluginInvokeHTTPRuntimeSchedule(t *testing.T) {
	h := newHarness(t).login()
	var ceType, ceSource, legacy string
	fn := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ceType, ceSource, legacy = r.Header.Get("ce-type"), r.Header.Get("ce-source"), r.Header.Get("X-RunR-Trigger")
		w.Write([]byte("tick"))
	}))
	defer fn.Close()
	dir := h.project("name: web\ntype: xaas\nruntime: q\nfile: main.q\n", map[string]string{"main.q": "fn handler event => 1\n"})
	root, _ := dev.SessionDir(h.cache, dir)
	if err := os.MkdirAll(root, 0o755); err != nil {
		t.Fatal(err)
	}
	st, _ := json.Marshal(dev.State{URL: fn.URL, Trigger: runr.TriggerSchedule, Dir: dir, Runtime: "web", CloudEventSchedule: true})
	write(t, filepath.Join(root, "state.json"), string(st))
	h.mustRun("dev", "invoke", "--dir", dir)
	if ceType != "dev.runr.schedule.tick" || ceSource != "/runr/schedule" || legacy != "" {
		t.Fatalf("tick headers: ce-type %q ce-source %q X-RunR-Trigger %q", ceType, ceSource, legacy)
	}
}

func TestDevPluginTriggerAndWasm(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedRuntime(strings.Replace(rtBashManifest, "triggers: [http, schedule, queue]", "triggers: [http]", 1), nil)
	dir := h.project("name: job\ntype: xaas\nruntime: bash\nfile: main.sh\ntrigger:\n  type: queue\n  queue: jobs\n",
		map[string]string{"main.sh": devPluginScript})
	r := h.expectCode(ExitError, "dev", "--dir", dir, "--print")
	contains(t, r.err, "does not support the queue trigger")

	// The handler file comes from the manifest (extension, default file).
	dir = h.project("name: nofile\ntype: faas\nruntime: bash\n", map[string]string{"other.sh": devPluginScript})
	r = h.expectCode(ExitError, "dev", "--dir", dir, "--print")
	contains(t, r.err, "the handler file main.sh does not exist")

	// WebAssembly runtimes run on the region's shim: runr dev says so and points to deploy.
	h.srv.SeedRuntime(`apiVersion: runr.sh/v1
kind: Runtime
id: wasm-demo
name: Demo (Wasm)
extensions: [rs]
runtimeClass: wasmtime-spin-v2
handler:
  file: lib
  function: handle
versions:
  - version: "4.1"
    image: ghcr.io/brf-tech/runr/exec-wasm-rust:4.1
run:
  mode: http
triggers: [http, schedule, queue]
`, nil)
	var infos []struct {
		ID   string `json:"id"`
		Kind string `json:"kind"`
	}
	decodeJSON(t, h.mustRun("runtimes", "ls", "-o", "json").out, &infos)
	kinds := map[string]string{}
	for _, i := range infos {
		kinds[i.ID] = i.Kind
	}
	if kinds["wasm-demo"] != "wasm" || kinds["bash"] != "plugin" || kinds["python"] != "built-in" {
		t.Fatalf("kinds = %v", kinds)
	}
	dir = h.project("name: wasmfn\ntype: faas\nruntime: wasm-demo\nhandler: lib.handle\n", map[string]string{"lib.rs": "fn handle() {}\n"})
	r = h.expectCode(ExitError, "dev", "--dir", dir, "--print")
	contains(t, r.err, "wasm-demo is a WebAssembly runtime")
	contains(t, r.err, "runr deploy")
}
