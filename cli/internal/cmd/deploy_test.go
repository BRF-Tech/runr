package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestInitScaffoldsEachRuntime(t *testing.T) {
	h := newHarness(t)
	cases := map[string]string{"python": "main.py", "nodejs": "main.js", "go": "main.go", "php": "main.php"}
	for rt, main := range cases {
		dir := filepath.Join(t.TempDir(), "svc-"+rt)
		r := h.mustRun("init", dir, "--runtime", rt, "--region", "eu-1")
		contains(t, r.out, "created")
		pf, err := projectfile.Load(filepath.Join(dir, "runr.yaml"))
		if err != nil {
			t.Fatalf("%s: generated runr.yaml is invalid: %v", rt, err)
		}
		if pf.Name != "svc-"+rt || pf.Runtime != rt || pf.Type != runr.TypeFaaS || pf.Region != "eu-1" {
			t.Fatalf("%s: runr.yaml = %+v", rt, pf)
		}
		if _, err := os.Stat(filepath.Join(dir, main)); err != nil {
			t.Fatalf("%s: %s not written", rt, main)
		}
	}
}

func TestInitXaasAndCaas(t *testing.T) {
	h := newHarness(t)
	dir := filepath.Join(t.TempDir(), "script")
	h.mustRun("init", dir, "--type", "xaas", "--runtime", "python")
	pf, err := projectfile.Load(filepath.Join(dir, "runr.yaml"))
	if err != nil || pf.Type != runr.TypeXaaS || pf.MainFile() != "main.py" {
		t.Fatalf("xaas runr.yaml = %+v, %v", pf, err)
	}

	dir = filepath.Join(t.TempDir(), "web")
	h.mustRun("init", dir, "--type", "caas", "--image", "ghcr.io/acme/web:1")
	pf, err = projectfile.Load(filepath.Join(dir, "runr.yaml"))
	if err != nil || pf.Type != runr.TypeCaaS || pf.Image != "ghcr.io/acme/web:1" {
		t.Fatalf("caas runr.yaml = %+v, %v", pf, err)
	}
}

func TestInitRefusesToOverwriteAndRejectsUnknownRuntime(t *testing.T) {
	h := newHarness(t)
	dir := filepath.Join(t.TempDir(), "app")
	h.mustRun("init", dir)
	r := h.expectCode(ExitUsage, "init", dir)
	contains(t, r.err, "exists")
	h.mustRun("init", dir, "--force")
	h.expectCode(ExitUsage, "init", filepath.Join(t.TempDir(), "x"), "--runtime", "cobol")
	h.expectCode(ExitUsage, "init", filepath.Join(t.TempDir(), "y"), "--type", "paas")
}

func TestDeployCreatesProjectAndPrintsURL(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler, "util.py": "X = 1\n", "notes.md": "# notes\n"})
	write(t, filepath.Join(dir, ".runrignore"), "*.md\n")
	write(t, filepath.Join(dir, ".git", "HEAD"), "ref: refs/heads/main\n")
	write(t, filepath.Join(dir, "__pycache__", "main.cpython-313.pyc"), "x")

	r := h.mustRun("deploy", "--dir", dir)
	contains(t, r.out, "https://hello-api.eu-1.runr.test")
	contains(t, r.err, "Creating project hello-api (faas, python) in eu-1")

	p, ok := h.srv.Project("hello-api")
	if !ok {
		t.Fatal("project not created")
	}
	if p.Config.EnvVars["GREETING"] != "Hello" {
		t.Fatalf("env = %v", p.Config.EnvVars)
	}
	files := h.srv.Files("hello-api")
	if len(files) != 2 || files["main.py"] != pythonHandler || files["util.py"] == "" {
		t.Fatalf("uploaded files = %v", keys(files))
	}
	deps := h.srv.Deployments("hello-api")
	if len(deps) != 1 || deps[0].Status != runr.DeploymentActive {
		t.Fatalf("deployments = %+v", deps)
	}
}

func TestDeployAgainReusesVersionAndSyncsConfig(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	h.srv.ResetCalls()
	h.mustRun("deploy", "--dir", dir)
	if n := len(h.srv.CallsTo("PUT /projects/{project}")); n != 0 {
		t.Fatalf("unchanged runr.yaml sent %d updates", n)
	}
	p, _ := h.srv.Project("hello-api")
	if p.LatestVersion.Number != 1 {
		t.Fatalf("unchanged code made version %d", p.LatestVersion.Number)
	}

	write(t, filepath.Join(dir, "runr.yaml"), helloYAML+"resources:\n  memory_mb: 256\nscale:\n  max: 9\n")
	h.srv.ResetCalls()
	r := h.mustRun("deploy", "--dir", dir, "-o", "json")
	puts := h.srv.CallsTo("PUT /projects/{project}")
	if len(puts) != 1 {
		t.Fatalf("config change sent %d updates", len(puts))
	}
	cfg := puts[0].Body["config"].(map[string]any)
	if cfg["memory_mb"] != float64(256) || cfg["max_scale"] != float64(9) {
		t.Fatalf("update config = %v", cfg)
	}
	if _, sent := cfg["env_vars"]; sent {
		t.Fatal("unchanged env was sent")
	}
	if msg := puts[0].Body["config_message"]; msg != "runr deploy: max_scale, memory_mb" {
		t.Fatalf("config message = %v", msg)
	}
	var res DeployResult
	decodeJSON(t, r.out, &res)
	if res.Version.Number != 2 || res.Deployment.Status != runr.DeploymentActive || res.URL == "" || res.Created {
		t.Fatalf("deploy result = %+v", res)
	}
}

func TestDeployWaitsForBuild(t *testing.T) {
	h := newHarness(t).login()
	h.srv.BuildPolls = 2
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler, "requirements.txt": "requests==2.32.3\n"})
	r := h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "Building v1: pending")
	contains(t, r.err, "Building v1: building")
	if len(h.srv.CallsTo("POST /projects/{project}/deploy")) != 1 {
		t.Fatal("not deployed after the build")
	}
}

func TestDeployBuildFailure(t *testing.T) {
	h := newHarness(t).login()
	h.srv.FailBuild = true
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler, "requirements.txt": "nope==0\n"})
	r := h.expectCode(ExitFailed, "deploy", "--dir", dir)
	contains(t, r.err, "build of v1 failed")
	if len(h.srv.CallsTo("POST /projects/{project}/deploy")) != 0 {
		t.Fatal("a failed build was deployed")
	}
}

func TestDeployFailure(t *testing.T) {
	h := newHarness(t).login()
	h.srv.FailDeploy = true
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	r := h.expectCode(ExitFailed, "deploy", "--dir", dir)
	contains(t, r.err, "RevisionFailed")
}

func TestDeployNoWaitAndOutputEnv(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	envFile := filepath.Join(t.TempDir(), "github_output")
	write(t, envFile, "previous=1\n")
	r := h.mustRun("deploy", "--dir", dir, "--no-wait", "--output-env", envFile, "-o", "json")
	var res DeployResult
	decodeJSON(t, r.out, &res)
	if res.Waited || res.Deployment.Status != runr.DeploymentPending {
		t.Fatalf("--no-wait result = %+v", res.Deployment)
	}
	data, _ := os.ReadFile(envFile)
	got := string(data)
	for _, want := range []string{"previous=1\n", "project=hello-api\n", "version=v1\n", "deployment_id=" + res.Deployment.ID + "\n", "status=pending\n", "url=https://hello-api.eu-1.runr.test\n"} {
		if !strings.Contains(got, want) {
			t.Fatalf("output env lacks %q:\n%s", want, got)
		}
	}
}

func TestDeployExistingVersion(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	h.mustRun("deploy", "--dir", dir)
	h.srv.ResetCalls()
	h.mustRun("deploy", "--dir", dir, "--project", "hello-api", "--version", "v1")
	if len(h.srv.CallsTo("POST /projects/{project}/files")) != 0 {
		t.Fatal("--version uploaded files")
	}
	if len(h.srv.CallsTo("POST /projects/{project}/deploy")) != 1 {
		t.Fatal("--version did not deploy")
	}
	if d := h.srv.Deployments("hello-api")[0]; d.VersionLabel != "v1" {
		t.Fatalf("deployed %s, want v1", d.VersionLabel)
	}
	h.expectCode(ExitNotFound, "deploy", "--dir", dir, "--version", "v42")
}

func TestDeployRejectsNestedFilesAndMissingHandler(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler, "lib/helpers.py": "x = 1\n"})
	r := h.expectCode(ExitError, "deploy", "--dir", dir)
	contains(t, r.err, "lib/helpers.py")

	dir = h.project(helloYAML, map[string]string{"app.py": pythonHandler})
	r = h.expectCode(ExitError, "deploy", "--dir", dir)
	contains(t, r.err, "main.py")
}

func TestDeployInvalidProjectFile(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project("name: Bad Name!\ntype: lambda\n", nil)
	r := h.expectCode(ExitValidation, "deploy", "--dir", dir)
	contains(t, r.err, "unknown type")
}

func TestDeployWithoutProject(t *testing.T) {
	h := newHarness(t).login()
	h.expectCode(ExitUsage, "deploy", "--dir", t.TempDir())
	h.expectCode(ExitNotFound, "deploy", "--dir", t.TempDir(), "--project", "ghost")
}

func TestDeployTypeAndRuntimeCannotChange(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	write(t, filepath.Join(dir, "runr.yaml"), strings.Replace(helloYAML, "runtime: python", "runtime: nodejs", 1))
	write(t, filepath.Join(dir, "main.js"), "exports.handler = async () => ({})\n")
	r := h.expectCode(ExitError, "deploy", "--dir", dir)
	contains(t, r.err, "runtime cannot change")
}

func TestDeployXaas(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project("name: script\ntype: xaas\nruntime: python\nfile: job.py\n", map[string]string{"job.py": pythonHandler})
	h.mustRun("deploy", "--dir", dir)
	p, _ := h.srv.Project("script")
	if p.Config.Filename != "job.py" {
		t.Fatalf("filename = %q", p.Config.Filename)
	}
	if h.srv.Files("script")["job.py"] != pythonHandler {
		t.Fatal("script not saved")
	}
	if len(h.srv.CallsTo("POST /projects/{project}/code")) != 1 {
		t.Fatal("xaas did not use POST /code")
	}
}

func TestDeployCaasAndRollbackRestoresImage(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project("name: web\ntype: caas\nimage: nginx:1.26\nports: [80]\n", nil)
	h.mustRun("deploy", "--dir", dir)
	h.mustRun("deploy", "--dir", dir, "--image", "nginx:1.27")
	p, _ := h.srv.Project("web")
	if p.Config.Image != "nginx:1.27" {
		t.Fatalf("image = %q", p.Config.Image)
	}
	if p.LatestVersion.Number != 2 {
		t.Fatalf("latest version = %d", p.LatestVersion.Number)
	}

	r := h.mustRun("rollback", "v1", "--project", "web")
	contains(t, r.err, "Restoring the container config of v1")
	p, _ = h.srv.Project("web")
	if p.Config.Image != "nginx:1.26" || p.LatestVersion.Number != 3 {
		t.Fatalf("after rollback image=%q version=%d", p.Config.Image, p.LatestVersion.Number)
	}
	if d := h.srv.Deployments("web")[0]; d.VersionLabel != "v3" || d.Status != runr.DeploymentActive {
		t.Fatalf("rollback deployment = %+v", d)
	}

	h.expectCode(ExitUsage, "deploy", "--dir", h.project(helloYAML, map[string]string{"main.py": pythonHandler}), "--image", "x:1")
}

func TestRollbackCodeProject(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	h.mustRun("deploy", "--dir", dir)

	r := h.mustRun("rollback", "1", "--dir", dir, "-o", "json")
	var res DeployResult
	decodeJSON(t, r.out, &res)
	if res.Version.Label != "v1" || res.Deployment.Status != runr.DeploymentActive {
		t.Fatalf("rollback result = %+v", res)
	}
	if len(h.srv.CallsTo("PUT /projects/{project}")) != 0 {
		t.Fatal("a code rollback changed the config")
	}
	h.expectCode(ExitNotFound, "rollback", "v9", "--dir", dir)
}

func TestVersionsLs(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# two\n")
	h.mustRun("deploy", "--dir", dir, "-m", "second")
	r := h.mustRun("versions", "ls", "--dir", dir)
	contains(t, r.out, "v2")
	contains(t, r.out, "second")
	lines := strings.Split(strings.TrimSpace(r.out), "\n")
	if len(lines) != 3 || !strings.HasPrefix(strings.TrimSpace(lines[1]), "*") {
		t.Fatalf("versions table:\n%s", r.out)
	}
	var list []runr.Version
	decodeJSON(t, h.mustRun("versions", "--dir", dir, "-o", "json").out, &list)
	if len(list) != 2 || list[0].Number != 2 {
		t.Fatalf("versions json = %+v", list)
	}
}

func TestProjectsLsGetDelete(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	r := h.mustRun("projects", "ls")
	contains(t, r.out, "hello-api")
	contains(t, r.out, "python 3.13")
	contains(t, r.out, "active")

	r = h.mustRun("projects", "ls", "-o", "yaml")
	contains(t, r.out, "name: hello-api")

	r = h.mustRun("projects", "get", "hello-api")
	contains(t, r.out, "Handler:")
	contains(t, r.out, "main.handler")

	var p runr.Project
	decodeJSON(t, h.mustRun("projects", "get", "hello-api", "-o", "json").out, &p)
	if p.Name != "hello-api" || p.Config.EnvVars["GREETING"] != "Hello" {
		t.Fatalf("project json = %+v", p)
	}
	h.mustRun("projects", "get", p.ID)

	h.expectCode(ExitUsage, "projects", "delete", "hello-api")
	h.terminal = true
	r = h.runStdin("n\n", "projects", "delete", "hello-api")
	if r.code != ExitError {
		t.Fatalf("declined delete exit %d", r.code)
	}
	h.terminal = false
	h.mustRun("projects", "delete", "hello-api", "--yes")
	if _, ok := h.srv.Project("hello-api"); ok {
		t.Fatal("project not deleted")
	}
	h.expectCode(ExitNotFound, "projects", "get", "hello-api")
}

func TestStatusAndOpen(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.mustRun("status", "--dir", dir)
	contains(t, r.out, "active v1")
	contains(t, r.out, "https://hello-api.eu-1.runr.test")

	r = h.mustRun("open", "--dir", dir, "--print")
	if strings.TrimSpace(r.out) != "https://hello-api.eu-1.runr.test" {
		t.Fatalf("open --print = %q", r.out)
	}
	h.mustRun("open", "--dir", dir)
	if len(h.opened) != 1 || h.opened[0] != "https://hello-api.eu-1.runr.test" {
		t.Fatalf("opened %v", h.opened)
	}

	h.srv.SeedProject("cron", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "schedule", "schedule": "*/5 * * * *"})
	r = h.expectCode(ExitError, "open", "--project", "cron")
	contains(t, r.err, "no URL")
	r = h.mustRun("status", "--project", "cron")
	contains(t, r.out, "none (run `runr deploy`)")
	contains(t, r.out, "schedule */5 * * * *")
}

// A stopped project shows who stopped it and why; its deploy is refused with the API's code.
func TestStoppedProject(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	h.srv.SeedStop("hello-api", "Unpaid since August", runr.StopLevelOrganization, "Bob")
	r := h.mustRun("projects", "ls")
	contains(t, r.out, "stopped")
	r = h.mustRun("projects", "get", "hello-api")
	contains(t, r.out, "Stopped:")
	contains(t, r.out, "by Bob for the organization")
	contains(t, r.out, "ago: Unpaid since August")
	r = h.mustRun("status", "--dir", dir)
	contains(t, r.out, "by Bob for the organization")

	// A platform stop names the platform, not its administrator
	h.srv.SeedStop("hello-api", "Terms of service", runr.StopLevelPlatform, "")
	r = h.mustRun("status", "--dir", dir)
	contains(t, r.out, "by the platform")
	contains(t, r.out, "Terms of service")
	notContains(t, r.out, "Bob")
	var p runr.Project
	decodeJSON(t, h.mustRun("projects", "get", "hello-api", "-o", "json").out, &p)
	if !p.Stopped.ByPlatform() || p.Stopped.By != nil || p.Stopped.Reason != "Terms of service" {
		t.Fatalf("stopped = %+v", p.Stopped)
	}

	r = h.expectCode(ExitValidation, "deploy", "--dir", dir)
	contains(t, r.err, "409 This project is stopped and cannot be deployed until it is resumed. Reason: Terms of service (project_stopped)")

	// Resumed: no stop line, deploys again
	h.srv.SeedStop("hello-api", "", "", "")
	r = h.mustRun("status", "--dir", dir)
	notContains(t, r.out, "Stopped")
	h.mustRun("deploy", "--dir", dir)
}

func TestProjectFromEnvironment(t *testing.T) {
	h := newHarness(t).login()
	h.deployHello()
	t.Setenv(EnvProject, "hello-api")
	r := h.mustRun("status")
	contains(t, r.out, "hello-api")
}

func keys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
