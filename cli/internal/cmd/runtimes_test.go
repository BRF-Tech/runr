package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const rtBashManifest = `apiVersion: runr.sh/v1
kind: Runtime
id: bash
name: Bash
extensions: [sh]
versions:
  - version: "5"
    image: bash:5
    default: true
template:
  files:
    "{file}": "#!/usr/bin/env bash\necho \"Hello from Bash\"\n"
run:
  mode: exec
  input: raw
  command: ["bash", "/app/code/{file}"]
triggers: [http, schedule, queue]
`

const rtQ2Manifest = `apiVersion: runr.sh/v1
kind: Runtime
id: q2
name: Q2
extensions: [q]
versions:
  - version: "1.0"
    dockerfile: Dockerfile
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
  workers: 2
`

// rtManifestFile writes a manifest into a new directory and returns its path.
func rtManifestFile(t *testing.T, content string) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), "runtime.yaml")
	write(t, p, content)
	return p
}

func TestRuntimesRegisterGetUpdateRm(t *testing.T) {
	h := newHarness(t).login()
	file := rtManifestFile(t, rtBashManifest)

	r := h.mustRun("runtimes", "register", "-f", file)
	contains(t, r.err, "Registered runtime bash (platform).")
	contains(t, r.out, "exec")
	contains(t, r.out, "bash:5")
	if calls := h.srv.CallsTo("POST /runtimes"); len(calls) != 1 || calls[0].Body["manifest"] != rtBashManifest {
		t.Fatalf("register calls = %+v", calls)
	}

	r = h.mustRun("runtimes", "ls")
	contains(t, r.out, "bash")
	contains(t, r.out, "exec")
	contains(t, r.out, "plugin")

	var d runr.RuntimeDetail
	decodeJSON(t, h.mustRun("runtimes", "get", "bash", "-o", "json").out, &d)
	if d.Source != "platform" || d.Manifest == nil || d.Manifest.Run.Mode != "exec" || d.Manifest.Run.Input != "raw" ||
		len(d.Versions) != 1 || d.Versions[0].Image != "bash:5" || !d.IsEnabled {
		t.Fatalf("get = %+v", d)
	}
	if out := h.mustRun("runtimes", "get", "bash", "--manifest").out; out != rtBashManifest {
		t.Fatalf("manifest = %q", out)
	}
	h.expectCode(ExitNotFound, "runtimes", "get", "cobol")

	// Disabled runtimes leave the catalog; --all lists them.
	h.mustRun("runtimes", "update", "bash", "--disable")
	notContains(t, h.mustRun("runtimes", "ls").out, "bash:5")
	contains(t, h.mustRun("runtimes", "ls", "--all").out, "bash:5")
	if ls := h.srv.CallsTo("GET /runtimes"); ls[len(ls)-1].Query != "all=1" {
		t.Fatalf("ls --all query = %q", ls[len(ls)-1].Query)
	}
	put := h.srv.CallsTo("PUT /runtimes/{runtime}")
	if len(put) != 1 || put[0].Body["is_enabled"] != false || put[0].Body["manifest"] != nil {
		t.Fatalf("update calls = %+v", put)
	}
	h.mustRun("runtimes", "update", "bash", "--enable", "--region", "eu-1")
	put = h.srv.CallsTo("PUT /runtimes/{runtime}")
	if regions, _ := put[1].Body["regions"].([]any); len(regions) != 1 || put[1].Body["is_enabled"] != true {
		t.Fatalf("update regions = %+v", put[1].Body)
	}
	h.mustRun("runtimes", "update", "bash", "--all-regions")
	if v, has := h.srv.CallsTo("PUT /runtimes/{runtime}")[2].Body["regions"]; !has || v != nil {
		t.Fatalf("--all-regions sends regions: null, got %v (%v)", v, has)
	}
	h.expectCode(ExitUsage, "runtimes", "update", "bash")
	h.expectCode(ExitUsage, "runtimes", "update", "bash", "--enable", "--disable")

	// The id cannot change; a duplicate id is refused.
	renamed := rtManifestFile(t, strings.Replace(rtBashManifest, "id: bash", "id: zsh", 1))
	h.expectCode(ExitValidation, "runtimes", "update", "bash", "-f", renamed)
	h.expectCode(ExitValidation, "runtimes", "register", "-f", file)

	// rm asks; a runtime projects use is refused (409).
	h.expectCode(ExitUsage, "runtimes", "rm", "bash")
	h.srv.SeedProject("script", runr.TypeXaaS, "bash", "eu-1", map[string]any{"filename": "main.sh"})
	r = h.expectCode(ExitValidation, "runtimes", "rm", "bash", "--yes")
	contains(t, r.err, "1 projects use this runtime")
	h.srv.ResetCalls()
	h.mustRun("projects", "delete", "script", "--yes")
	h.mustRun("runtimes", "rm", "bash", "--yes")
	h.expectCode(ExitNotFound, "runtimes", "get", "bash")
	h.expectCode(ExitAuth, "runtimes", "rm", "python", "--yes")
}

func TestRuntimesRegisterFromStdinWithFiles(t *testing.T) {
	h := newHarness(t).login()
	files := t.TempDir()
	write(t, filepath.Join(files, "Dockerfile"), "FROM golang:1.23-alpine\nCOPY . /src\n")
	write(t, filepath.Join(files, "main.go"), "package main\n")
	if err := os.Mkdir(filepath.Join(files, "sub"), 0o755); err != nil {
		t.Fatal(err)
	}

	r := h.runStdin(rtQ2Manifest, "runtimes", "register", "-f", "-", "--files", files, "--wait")
	if r.code != 0 {
		t.Fatalf("register: exit %d\n%s", r.code, r.err)
	}
	stored, uploaded, ok := h.srv.RegisteredRuntime("q2")
	if !ok || stored != rtQ2Manifest || len(uploaded) != 2 || uploaded["Dockerfile"] == "" {
		t.Fatalf("stored manifest %q files %v", stored, uploaded)
	}
	if len(h.srv.CallsTo("GET /runtimes/{runtime}/builds")) == 0 {
		t.Fatal("--wait did not poll the builds")
	}

	var builds []runr.RuntimeBuild
	decodeJSON(t, h.mustRun("runtimes", "builds", "q2", "-o", "json").out, &builds)
	if len(builds) != 1 || builds[0].Status != runr.RuntimeBuildReady || builds[0].RegionName != "eu-1" || builds[0].Image == "" {
		t.Fatalf("builds = %+v", builds)
	}

	// A failed rebuild exits with code 6 and names the error.
	h.srv.FailRuntimeBuilds(true)
	r = h.expectCode(ExitFailed, "runtimes", "build", "q2", "--region", "eu-1", "--wait")
	contains(t, r.err, "runtime image build q2 1.0 in eu-1 failed")
	if b := h.srv.CallsTo("POST /runtimes/{runtime}/builds"); len(b) != 1 || b[0].Body["knative_region_id"] == "" {
		t.Fatalf("build calls = %+v", b)
	}
	// A runtime with image versions only has nothing to build.
	h.srv.SeedRuntime(rtBashManifest, nil)
	h.expectCode(ExitValidation, "runtimes", "build", "bash")

	// A dockerfile version needs its file.
	h.expectCode(ExitValidation, "runtimes", "register", "-f", rtManifestFile(t, strings.Replace(rtQ2Manifest, "id: q2", "id: q3", 1)))
}

func TestRuntimesValidate(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("runtimes", "validate", "-f", rtManifestFile(t, rtBashManifest))
	contains(t, r.out, "valid (bash, exec mode, 1 version(s))")
	if len(h.srv.CallsTo("POST /runtimes")) != 0 {
		t.Fatal("validate registered the runtime")
	}

	bad := rtManifestFile(t, strings.Replace(rtBashManifest, "  command: [\"bash\", \"/app/code/{file}\"]\n", "", 1))
	r = h.expectCode(ExitValidation, "runtimes", "validate", "-f", bad)
	contains(t, r.out, "/run/command")
	contains(t, r.out, "is required in exec mode")
	contains(t, r.err, "is not a valid runtime manifest (1 problem)")

	r = h.expectCode(ExitValidation, "runtimes", "validate", "-f", bad, "-o", "json")
	var out struct {
		Valid    bool                   `json:"valid"`
		Problems []runr.ManifestProblem `json:"problems"`
	}
	decodeJSON(t, r.out, &out)
	if out.Valid || len(out.Problems) != 1 || out.Problems[0].Path != "/run/command" {
		t.Fatalf("validate json = %+v", out)
	}
	h.expectCode(ExitUsage, "runtimes", "validate")
}

func TestRuntimesTemplate(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("runtimes", "template", "q")
	contains(t, r.out, "==> main.q <==")
	contains(t, r.out, "fn handler")
	if q := h.srv.CallsTo("GET /runtimes/{runtime}/template")[0].Query; q != "service_type=xaas" {
		t.Fatalf("template query = %q", q)
	}

	out := filepath.Join(t.TempDir(), "qfn")
	h.mustRun("runtimes", "template", "q", "--type", "faas", "--handler", "app.run", "--out", out)
	if b, err := os.ReadFile(filepath.Join(out, "app.q")); err != nil || !strings.Contains(string(b), "fn run") {
		t.Fatalf("written template: %q %v", b, err)
	}
	h.expectCode(ExitUsage, "runtimes", "template", "q", "--type", "faas", "--handler", "app.run", "--out", out)
	h.mustRun("runtimes", "template", "q", "--type", "faas", "--handler", "app.run", "--out", out, "--force")
	h.expectCode(ExitUsage, "runtimes", "template", "q", "--type", "caas")
	h.expectCode(ExitNotFound, "runtimes", "template", "cobol")
}

func TestRuntimesScopedTokens(t *testing.T) {
	h := newHarness(t)
	h.loginWith(h.srv.ScopedToken("custom", "runtimes.use_custom"))
	r := h.mustRun("runtimes", "register", "-f", rtManifestFile(t, rtBashManifest))
	contains(t, r.err, "Registered runtime bash (custom).")

	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	h.mustRun("runtimes", "ls")
	r = h.expectCode(ExitAuth, "runtimes", "rm", "bash", "--yes")
	contains(t, r.err, "runtimes.manage")
}

func TestRuntimesTestDeploy(t *testing.T) {
	h := newHarness(t).login()
	var p runr.Project
	decodeJSON(t, h.mustRun("runtimes", "test-deploy", "q", "-o", "json").out, &p)
	if !strings.HasPrefix(p.Name, "rt-q-") || p.Runtime != "q" || p.ServiceType != runr.TypeXaaS {
		t.Fatalf("test project = %+v", p)
	}
	if deps := h.srv.Deployments(p.Name); len(deps) != 1 {
		t.Fatalf("test deploy deployments = %+v", deps)
	}
	h.expectCode(ExitNotFound, "runtimes", "test-deploy", "q", "--region", "mars-1")
}
