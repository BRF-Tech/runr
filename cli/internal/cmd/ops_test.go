package cmd

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/internal/dev"
	"github.com/runrsh/core/cli/pkg/runr"
)

func TestLogs(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.LogLines = []string{"line one\n", "line two\n"}
	r := h.mustRun("logs", "--dir", dir)
	if r.out != "line one\n" {
		t.Fatalf("logs = %q", r.out)
	}
	calls := h.srv.CallsTo("GET /projects/{project}/deployments/{deployment}/logs")
	if len(calls) != 1 || calls[0].Query != "" {
		t.Fatalf("log calls = %+v", calls)
	}
}

func TestLogsFollow(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.LogLines = []string{"first\n", "second\n", "third\n"}
	r := h.mustRun("logs", "-f", "--dir", dir)
	if r.out != "first\nsecond\nthird\n" {
		t.Fatalf("followed logs = %q", r.out)
	}
	calls := h.srv.CallsTo("GET /projects/{project}/deployments/{deployment}/logs")
	if len(calls) != 4 {
		t.Fatalf("%d log calls, want 4 (first + 3 polls)", len(calls))
	}
	if !strings.HasPrefix(calls[1].Query, "since=") {
		t.Fatalf("follow poll query = %q, want since=", calls[1].Query)
	}
}

func TestLogsJSONAndNoDeployment(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	var logs runr.Logs
	decodeJSON(t, h.mustRun("logs", "--dir", dir, "-o", "json").out, &logs)
	if len(logs.Pods) != 1 || !logs.Pods[0].Ready || logs.Source != "loki" {
		t.Fatalf("logs json = %+v", logs)
	}
	h.srv.SeedProject("idle", runr.TypeFaaS, "python", "eu-1", nil)
	h.expectCode(ExitNotFound, "logs", "--project", "idle")
}

func TestEnvSetRedeploysAndUnset(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	before := len(h.srv.Deployments("hello-api"))

	r := h.mustRun("env", "set", "LOG_LEVEL=debug", "COLOR=blue", "--dir", dir)
	contains(t, r.err, "Variables set: COLOR, LOG_LEVEL")
	p, _ := h.srv.Project("hello-api")
	if p.Config.EnvVars["LOG_LEVEL"] != "debug" || p.Config.EnvVars["GREETING"] != "Hello" {
		t.Fatalf("env = %v", p.Config.EnvVars)
	}
	deps := h.srv.Deployments("hello-api")
	if len(deps) != before+1 || deps[0].Status != runr.DeploymentActive || deps[0].VersionLabel != "v1" {
		t.Fatalf("env set did not redeploy the live version: %+v", deps)
	}
	if msg := h.srv.CallsTo("PUT /projects/{project}")[0].Body["config_message"]; msg != "runr env set: COLOR, LOG_LEVEL" {
		t.Fatalf("config message = %v", msg)
	}

	r = h.mustRun("env", "ls", "--dir", dir)
	contains(t, r.out, "LOG_LEVEL")
	notContains(t, r.out, "debug")
	r = h.mustRun("env", "ls", "--dir", dir, "--show-values")
	contains(t, r.out, "debug")

	h.mustRun("env", "unset", "LOG_LEVEL", "--no-deploy", "--dir", dir)
	p, _ = h.srv.Project("hello-api")
	if _, ok := p.Config.EnvVars["LOG_LEVEL"]; ok {
		t.Fatal("LOG_LEVEL still set")
	}
	if len(h.srv.Deployments("hello-api")) != before+1 {
		t.Fatal("--no-deploy redeployed")
	}

	h.srv.ResetCalls()
	r = h.mustRun("env", "unset", "NOT_SET", "--dir", dir)
	contains(t, r.err, "Nothing changed")
	if len(h.srv.CallsTo("PUT /projects/{project}")) != 0 {
		t.Fatal("a no-op change sent an update")
	}
}

func TestEnvSetValidatesNames(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.expectCode(ExitUsage, "env", "set", "1BAD=x", "--dir", dir)
	h.expectCode(ExitUsage, "env", "set", "PORT=9000", "--dir", dir)
	h.expectCode(ExitUsage, "env", "set", "NOEQUALS", "--dir", dir)
	h.expectCode(ExitUsage, "env", "set", "--dir", dir)
}

func TestEnvSetFromFile(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	envFile := filepath.Join(t.TempDir(), ".env")
	write(t, envFile, "# comment\nexport A=1\nB=\"two words\"\nC='x'\n")
	h.mustRun("env", "set", "--from-file", envFile, "--no-deploy", "--dir", dir)
	p, _ := h.srv.Project("hello-api")
	if p.Config.EnvVars["A"] != "1" || p.Config.EnvVars["B"] != "two words" || p.Config.EnvVars["C"] != "x" {
		t.Fatalf("env = %v", p.Config.EnvVars)
	}
}

func TestEnvSecretWriteOnly(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.mustRun("env", "set", "--secret", "API_KEY=s3cr3t", "--no-deploy", "--dir", dir)
	notContains(t, r.err, "Warning")
	if h.srv.Secrets("hello-api")["API_KEY"] != "s3cr3t" {
		t.Fatalf("secret not stored: %v", h.srv.Secrets("hello-api"))
	}
	p, _ := h.srv.Project("hello-api")
	if _, plain := p.Config.EnvVars["API_KEY"]; plain {
		t.Fatal("the secret was stored as a plain variable")
	}
	r = h.mustRun("env", "ls", "--dir", dir, "-o", "json", "--show-values")
	notContains(t, r.out, "s3cr3t")
	var vars []runr.EnvVar
	decodeJSON(t, r.out, &vars)
	found := false
	for _, v := range vars {
		if v.Name == "API_KEY" {
			found = v.Secret && v.Value == ""
		}
	}
	if !found {
		t.Fatalf("env ls = %+v", vars)
	}
	h.mustRun("env", "unset", "API_KEY", "--no-deploy", "--dir", dir)
	if _, ok := h.srv.Secrets("hello-api")["API_KEY"]; ok {
		t.Fatal("secret not removed")
	}
}

func TestEnvSecretFallsBackWithWarning(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SecretEnv = false // an API without secret variables
	dir := h.deployHello()
	r := h.mustRun("env", "set", "--secret", "API_KEY=s3cr3t", "--no-deploy", "--dir", dir)
	contains(t, r.err, "Warning: this RunR API keeps all variables in the project config")
	p, _ := h.srv.Project("hello-api")
	if p.Config.EnvVars["API_KEY"] != "s3cr3t" {
		t.Fatalf("env = %v", p.Config.EnvVars)
	}
	body := h.srv.CallsTo("PUT /projects/{project}")[0].Body["config"].(map[string]any)
	if _, sent := body["secret_env_vars"]; sent {
		t.Fatal("secret_env_vars sent to an API that does not support it")
	}
}

func TestScale(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.mustRun("scale", "--min", "1", "--max", "3", "--to-zero=false", "--dir", dir)
	contains(t, r.err, "1..3")
	cfg := h.srv.CallsTo("PUT /projects/{project}")[0].Body["config"].(map[string]any)
	if cfg["min_scale"] != float64(1) || cfg["max_scale"] != float64(3) || cfg["scale_to_zero"] != false {
		t.Fatalf("scale config = %v", cfg)
	}
	if len(h.srv.Deployments("hello-api")) != 2 {
		t.Fatal("scale did not redeploy")
	}
	h.expectCode(ExitUsage, "scale", "--dir", dir)
	h.expectCode(ExitUsage, "scale", "--min", "11", "--dir", dir)
	h.expectCode(ExitUsage, "scale", "--min", "5", "--max", "2", "--dir", dir)

	h.srv.SeedProject("idle", runr.TypeFaaS, "python", "eu-1", nil)
	r = h.mustRun("scale", "--max", "2", "--project", "idle")
	contains(t, r.err, "No live deployment")
}

func TestDomains(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	r := h.mustRun("domains", "add", "api.example.com", "--dir", dir)
	contains(t, r.out, "_runr-challenge.api.example.com")
	contains(t, r.out, "CNAME")
	contains(t, r.out, "runr domains verify api.example.com")

	r = h.mustRun("domains", "ls", "--dir", dir)
	contains(t, r.out, "api.example.com")
	contains(t, r.out, "pending")

	r = h.expectCode(ExitValidation, "domains", "verify", "api.example.com", "--dir", dir)
	contains(t, r.err, "not verified")
	h.srv.VerifyDomains = true
	r = h.mustRun("domains", "verify", "api.example.com", "--dir", dir)
	contains(t, r.out, "active")

	r = h.mustRun("domains", "tls", "api.example.com", "--mode", "cert-manager", "--dir", dir)
	contains(t, r.out, "cert-manager")
	h.expectCode(ExitUsage, "domains", "tls", "api.example.com", "--mode", "custom", "--dir", dir)
	h.expectCode(ExitUsage, "domains", "tls", "api.example.com", "--dir", dir)

	certDir := t.TempDir()
	write(t, filepath.Join(certDir, "cert.pem"), "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n")
	write(t, filepath.Join(certDir, "key.pem"), "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n")
	h.mustRun("domains", "tls", "api.example.com", "--mode", "custom", "--cert", filepath.Join(certDir, "cert.pem"),
		"--key", filepath.Join(certDir, "key.pem"), "--dir", dir)
	call := h.srv.CallsTo("PUT /projects/{project}/domains/{domain}/tls")[1]
	if !strings.Contains(call.Body["privateKey"].(string), "PRIVATE KEY") {
		t.Fatal("private key not sent")
	}

	h.expectCode(ExitValidation, "domains", "add", "api.example.com", "--dir", dir)
	h.expectCode(ExitNotFound, "domains", "rm", "other.example.com", "--yes", "--dir", dir)
	h.mustRun("domains", "rm", "api.example.com", "--yes", "--dir", dir)
	var out struct {
		Domains []runr.Domain `json:"domains"`
	}
	decodeJSON(t, h.mustRun("domains", "ls", "--dir", dir, "-o", "json").out, &out)
	if len(out.Domains) != 0 {
		t.Fatalf("domains after rm = %+v", out.Domains)
	}
}

func TestRegionsAndRuntimes(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("us-1", false)
	r := h.mustRun("regions", "ls")
	contains(t, r.out, "eu-1")
	contains(t, r.out, "active")
	contains(t, r.out, "us-1")
	contains(t, r.out, "inactive")

	var regions []runr.Region
	decodeJSON(t, h.mustRun("regions", "-o", "json").out, &regions)
	if len(regions) != 2 {
		t.Fatalf("regions = %+v", regions)
	}

	r = h.mustRun("runtimes", "ls")
	contains(t, r.out, "ghcr.io/runrsh/exec-python:3.13")
	contains(t, r.out, "2028-10-31")
}

func TestRuntimePlugins(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("runtimes", "ls")
	contains(t, r.out, "plugin")
	contains(t, r.out, "(built in the region)")

	// init takes the starter of a plugin from the catalog's template.
	dir := filepath.Join(t.TempDir(), "qfn")
	h.mustRun("init", dir, "--runtime", "q")
	if b, err := os.ReadFile(filepath.Join(dir, "main.q")); err != nil || !strings.Contains(string(b), "fn handler") {
		t.Fatalf("plugin starter: %q %v", b, err)
	}
	if len(h.srv.CallsTo("GET /runtimes/{runtime}/template")) != 1 {
		t.Fatal("the template was not fetched")
	}
	xdir := filepath.Join(t.TempDir(), "qscript")
	h.mustRun("init", xdir, "--type", "xaas", "--runtime", "q")
	b, _ := os.ReadFile(filepath.Join(xdir, "runr.yaml"))
	contains(t, string(b), "file: main.q")

	// deploy: the API checks the handler file of plugins.
	r = h.mustRun("deploy", "--dir", dir)
	contains(t, r.out, "https://qfn.eu-1.runr.test")
	if h.srv.Files("qfn")["main.q"] == "" {
		t.Fatal("plugin code not uploaded")
	}

	// dev runs plugins the way the cluster does: wrapper from its image, the runtime image
	// built from the uploaded Dockerfile, the wrapper as entrypoint.
	r = h.mustRun("dev", "--dir", dir, "--print")
	contains(t, r.out, "install /runr/runr-wrapper")
	contains(t, r.out, "docker build -t runr-dev-runtime-q:1.0-")
	contains(t, r.out, "--entrypoint /runr/runr-wrapper runr-dev-runtime-q:1.0-")
	contains(t, r.out, "RUNR_WRAPPER_MODE=persistent")

	h.expectCode(ExitUsage, "init", filepath.Join(t.TempDir(), "x"), "--runtime", "zz")
}

func TestRuntimeCatalogFallback(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Catalog = false
	r := h.mustRun("runtimes", "ls", "-o", "json")
	contains(t, r.out, `"id": "python"`)
	notContains(t, r.out, "built in the region")
	h.expectCode(ExitUsage, "init", filepath.Join(t.TempDir(), "x"), "--runtime", "q")
}

func TestRuntimesOffline(t *testing.T) {
	h := newHarness(t)
	r := h.mustRun("runtimes", "ls", "-o", "json")
	contains(t, r.out, `"id": "python"`)
}

func TestDeployPicksRegionWhenSeveral(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("us-1", true)
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	r := h.expectCode(ExitError, "deploy", "--dir", dir)
	contains(t, r.err, "several regions")
	h.mustRun("deploy", "--dir", dir, "--region", "us-1")
	p, _ := h.srv.Project("hello-api")
	if p.Region == nil || p.Region.Name != "us-1" {
		t.Fatalf("region = %+v", p.Region)
	}
	h.expectCode(ExitNotFound, "deploy", "--dir", dir, "--region", "mars-1")
}

func TestDevPrint(t *testing.T) {
	h := newHarness(t).login()
	dir := h.project(helloYAML+"dev:\n  port: 9090\n", map[string]string{"main.py": pythonHandler, "requirements.txt": "requests\n"})
	r := h.mustRun("dev", "--dir", dir, "--print", "-o", "json", "-e", "EXTRA=1")
	var plan struct {
		Image   string   `json:"image"`
		Port    int      `json:"port"`
		Run     []string `json:"docker_run"`
		Install []string `json:"docker_install"`
	}
	decodeJSON(t, r.out, &plan)
	if plan.Image != "ghcr.io/runrsh/exec-python:3.13" || plan.Port != 9090 {
		t.Fatalf("plan = %+v", plan)
	}
	joined := strings.Join(plan.Run, " ")
	for _, want := range []string{"RUNR_MODE=http", "RUNR_HANDLER=handler", "RUNR_FILENAME=main.py", "GREETING=Hello",
		"EXTRA=1", "PYTHONPATH=/app/vendor", "127.0.0.1:9090:8080", "target=/src,readonly"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("docker run lacks %q: %s", want, joined)
		}
	}
	if !strings.Contains(strings.Join(plan.Install, " "), "pip install") {
		t.Fatalf("install = %v", plan.Install)
	}
	r = h.mustRun("dev", "--dir", dir, "--print")
	contains(t, r.out, "docker run --rm --name runr-dev-hello-api-")
}

func TestDevRunsAndStopsContainer(t *testing.T) {
	h := newHarness(t)
	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	// The container stops right after it started (like `docker rm -f` from another shell);
	// with --no-watch the session then ends.
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
	contains(t, r.err, "Starting hello-api on http://localhost:8080")
	if h.docker.startCount() != 1 {
		t.Fatalf("%d containers started", h.docker.startCount())
	}
	// Offline (not logged in): the built-in default version is used.
	contains(t, strings.Join(h.docker.starts[0], " "), "ghcr.io/runrsh/exec-python:3.13")
}

func TestDevInvoke(t *testing.T) {
	h := newHarness(t)
	var got struct {
		method, path, body, ceType, trigger string
	}
	fn := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		got.method, got.path, got.body = r.Method, r.URL.Path, string(b)
		got.ceType, got.trigger = r.Header.Get("ce-type"), r.Header.Get("X-RunR-Trigger")
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"ok":true}`))
	}))
	defer fn.Close()

	dir := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	r := h.mustRun("dev", "invoke", "--dir", dir, "--url", fn.URL, "--event", `{"name": "Ada"}`, "--path", "/greet")
	contains(t, r.out, `{"ok":true}`)
	if got.method != "POST" || got.path != "/greet" || got.body != `{"name":"Ada"}` {
		t.Fatalf("http invoke = %+v", got)
	}

	h.mustRun("dev", "invoke", "--dir", dir, "--url", fn.URL, "--trigger", "queue", "--event", `{"order":1}`)
	if got.ceType != "dev.runr.queue.message" || got.body != `{"order":1}` {
		t.Fatalf("queue invoke = %+v", got)
	}

	h.mustRun("dev", "invoke", "--dir", dir, "--url", fn.URL, "--trigger", "schedule")
	if got.trigger != "schedule" || got.body != `{"trigger":"schedule"}` {
		t.Fatalf("schedule invoke = %+v", got)
	}

	eventFile := filepath.Join(t.TempDir(), "event.json")
	write(t, eventFile, `{"from":"file"}`)
	r = h.mustRun("dev", "invoke", "--dir", dir, "--url", fn.URL, "--event", "@"+eventFile, "-o", "json")
	var out struct {
		Status int            `json:"status"`
		Body   map[string]any `json:"body"`
	}
	decodeJSON(t, r.out, &out)
	if out.Status != 200 || out.Body["ok"] != true || got.body != `{"from":"file"}` {
		t.Fatalf("invoke json = %+v (sent %s)", out, got.body)
	}

	h.expectCode(ExitUsage, "dev", "invoke", "--dir", dir, "--url", fn.URL, "--event", "{not json")
}

func TestDevInvokeFindsSession(t *testing.T) {
	h := newHarness(t)
	var trigger string
	fn := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		trigger = r.Header.Get("ce-type")
		w.Write([]byte("done"))
	}))
	defer fn.Close()
	dir := h.project(strings.Replace(helloYAML, "env:", "trigger:\n  type: queue\n  queue: orders\nenv:", 1),
		map[string]string{"main.py": pythonHandler})
	root, err := dev.SessionDir(h.cache, dir)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(root, 0o755); err != nil {
		t.Fatal(err)
	}
	st, _ := json.Marshal(dev.State{URL: fn.URL, Trigger: runr.TriggerQueue, Queue: "orders", Dir: dir})
	write(t, filepath.Join(root, "state.json"), string(st))

	r := h.mustRun("dev", "invoke", "--dir", dir, "--event", `{"id":7}`)
	contains(t, r.out, "done")
	if trigger != "dev.runr.queue.message" {
		t.Fatalf("session trigger not used: %q", trigger)
	}

	other := h.project(helloYAML, map[string]string{"main.py": pythonHandler})
	r = h.expectCode(ExitError, "dev", "invoke", "--dir", other)
	contains(t, r.err, "no `runr dev` session")
}
