package cmd

import (
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

// A secret-only change goes through the secrets endpoints: no project update, no new version,
// and the live version is redeployed so the Kubernetes Secret is applied.
func TestEnvSecretUsesSecretsEndpoints(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	p, _ := h.srv.Project("hello-api")
	versionsBefore := p.LatestVersion.Number
	deploysBefore := len(h.srv.Deployments("hello-api"))
	h.srv.ResetCalls()

	r := h.mustRun("env", "set", "--secret", "API_KEY=s3cr3t", "--dir", dir)
	contains(t, r.err, "Secret variables set: API_KEY")
	notContains(t, r.err, "Variables set")
	notContains(t, r.out+r.err, "s3cr3t")
	if puts := h.srv.CallsTo("PUT /projects/{project}/secrets/{name}"); len(puts) != 1 || puts[0].Path != "/projects/"+p.ID+"/secrets/API_KEY" {
		t.Fatalf("secret calls = %+v", puts)
	}
	if n := len(h.srv.CallsTo("PUT /projects/{project}")); n != 0 {
		t.Fatalf("%d project updates for a secret-only change", n)
	}
	if h.srv.Secrets("hello-api")["API_KEY"] != "s3cr3t" {
		t.Fatalf("secrets = %v", h.srv.Secrets("hello-api"))
	}
	p, _ = h.srv.Project("hello-api")
	if p.LatestVersion.Number != versionsBefore {
		t.Fatalf("a secret change saved version %d", p.LatestVersion.Number)
	}
	if deps := h.srv.Deployments("hello-api"); len(deps) != deploysBefore+1 || deps[0].Status != runr.DeploymentActive {
		t.Fatalf("the live version was not redeployed: %+v", deps)
	}

	var vars []runr.EnvVar
	decodeJSON(t, h.mustRun("env", "ls", "--dir", dir, "-o", "json").out, &vars)
	var key *runr.EnvVar
	for i := range vars {
		if vars[i].Name == "API_KEY" {
			key = &vars[i]
		}
	}
	if key == nil || !key.Secret || key.Value != "" || key.Revealed || key.UpdatedAt == "" || key.UpdatedBy == nil || key.UpdatedBy.Name != "Ada Lovelace" {
		t.Fatalf("env ls = %+v", vars)
	}
	r = h.mustRun("env", "ls", "--dir", dir)
	contains(t, r.out, "(write-only)")
	contains(t, r.out, "by Ada Lovelace")
}

// A plain name set with --secret moves to the secrets in one project update, and back.
func TestEnvSecretMovesBetweenKinds(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello() // GREETING=Hello is plain
	h.srv.ResetCalls()

	h.mustRun("env", "set", "--secret", "GREETING=Hi", "--no-deploy", "--dir", dir)
	puts := h.srv.CallsTo("PUT /projects/{project}")
	if len(puts) != 1 || len(h.srv.CallsTo("PUT /projects/{project}/secrets/{name}")) != 0 {
		t.Fatalf("move plain → secret: %d project updates, secret calls %+v", len(puts), h.srv.CallsTo("PUT /projects/{project}/secrets/{name}"))
	}
	cfg := puts[0].Body["config"].(map[string]any)
	if _, plain := cfg["env_vars"].(map[string]any)["GREETING"]; plain {
		t.Fatalf("GREETING still sent as plain: %v", cfg)
	}
	if cfg["secret_env_vars"].(map[string]any)["GREETING"] != "Hi" {
		t.Fatalf("secret_env_vars = %v", cfg["secret_env_vars"])
	}
	p, _ := h.srv.Project("hello-api")
	if _, plain := p.Config.EnvVars["GREETING"]; plain || h.srv.Secrets("hello-api")["GREETING"] != "Hi" {
		t.Fatalf("env %v secrets %v", p.Config.EnvVars, h.srv.Secrets("hello-api"))
	}

	h.srv.ResetCalls()
	h.mustRun("env", "set", "GREETING=Hello again", "--no-deploy", "--dir", dir)
	cfg = h.srv.CallsTo("PUT /projects/{project}")[0].Body["config"].(map[string]any)
	if cfg["env_vars"].(map[string]any)["GREETING"] != "Hello again" || cfg["secret_env_vars"].(map[string]any)["GREETING"] != "" {
		t.Fatalf("move secret → plain sent %v", cfg)
	}
	if _, ok := h.srv.Secrets("hello-api")["GREETING"]; ok {
		t.Fatal("the secret was kept")
	}
}

func TestEnvUnsetSecretAndPlain(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecret("hello-api", "TOKEN", "t0k3n")
	h.srv.ResetCalls()

	r := h.mustRun("env", "unset", "TOKEN", "--no-deploy", "--dir", dir)
	contains(t, r.err, "Secret variables removed: TOKEN")
	if dels := h.srv.CallsTo("DELETE /projects/{project}/secrets/{name}"); len(dels) != 1 {
		t.Fatalf("delete calls = %+v", dels)
	}
	if len(h.srv.CallsTo("PUT /projects/{project}")) != 0 {
		t.Fatal("removing a secret updated the project")
	}

	h.srv.SeedSecret("hello-api", "TOKEN", "t0k3n")
	h.srv.ResetCalls()
	h.mustRun("env", "unset", "TOKEN", "GREETING", "--no-deploy", "--dir", dir)
	puts := h.srv.CallsTo("PUT /projects/{project}")
	if len(puts) != 1 {
		t.Fatalf("%d project updates", len(puts))
	}
	cfg := puts[0].Body["config"].(map[string]any)
	if cfg["secret_env_vars"].(map[string]any)["TOKEN"] != "" {
		t.Fatalf("mixed unset sent %v", cfg)
	}
	if len(h.srv.Secrets("hello-api")) != 0 {
		t.Fatalf("secrets left: %v", h.srv.Secrets("hello-api"))
	}
}

func TestEnvSecretFromStdin(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.runStdin("from-stdin\n", "env", "set", "--secret", "DB_PASSWORD", "--no-deploy", "--dir", dir)
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	if h.srv.Secrets("hello-api")["DB_PASSWORD"] != "from-stdin" {
		t.Fatalf("secrets = %v", h.srv.Secrets("hello-api"))
	}

	h.terminal = true
	h.secrets = []string{"from-prompt"}
	h.mustRun("env", "set", "--secret", "DB_PASSWORD", "--no-deploy", "--dir", dir)
	if h.srv.Secrets("hello-api")["DB_PASSWORD"] != "from-prompt" {
		t.Fatalf("secrets = %v", h.srv.Secrets("hello-api"))
	}

	h.terminal = false
	r = h.runStdin("", "env", "set", "--secret", "EMPTY", "--dir", dir)
	if r.code != ExitUsage {
		t.Fatalf("empty stdin: exit %d", r.code)
	}
}

func TestEnvLsReveal(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecret("hello-api", "TOKEN", "t0k3n")

	r := h.mustRun("env", "ls", "--dir", dir)
	notContains(t, r.out, "t0k3n")
	r = h.mustRun("env", "ls", "--reveal", "--dir", dir)
	contains(t, r.out, "t0k3n")
	contains(t, r.out, "Hello") // --reveal shows plain values too
	if h.srv.SecretReveals() != 1 {
		t.Fatalf("%d value reads", h.srv.SecretReveals())
	}
	var vars []runr.EnvVar
	decodeJSON(t, h.mustRun("env", "ls", "--reveal", "--dir", dir, "-o", "json").out, &vars)
	for _, v := range vars {
		if v.Name == "TOKEN" && (!v.Revealed || v.Value != "t0k3n") {
			t.Fatalf("revealed = %+v", v)
		}
	}

	h.srv.DenySecretValues()
	before := h.srv.SecretReveals()
	r = h.expectCode(ExitAuth, "env", "ls", "--reveal", "--dir", dir)
	contains(t, r.err, "secrets.view_values")
	if h.srv.SecretReveals() != before {
		t.Fatal("a value was read without secrets.view_values")
	}
	h.mustRun("env", "ls", "--dir", dir) // listing names still works
}

func TestEnvRevealNeedsScope(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecret("hello-api", "TOKEN", "t0k3n")
	h.loginWith(h.srv.ScopedToken("reader", "env.view"))
	r := h.expectCode(ExitAuth, "env", "ls", "--reveal", "--dir", dir)
	contains(t, r.err, "secrets.view_values")
	h.mustRun("env", "ls", "--dir", dir)
}

func TestEnvLsHiddenVariables(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	r := h.expectCode(ExitAuth, "env", "ls", "--dir", dir)
	contains(t, r.err, "env.view")
}

func TestEnvSecretTooLarge(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.ResetCalls()
	r := h.expectCode(ExitValidation, "env", "set", "--secret", "BIG="+strings.Repeat("x", runr.MaxSecretBytes+1), "--dir", dir)
	contains(t, r.err, "at most 32 KiB")
	if len(h.srv.CallsTo("PUT /projects/{project}/secrets/{name}")) != 0 {
		t.Fatal("an oversized secret was sent")
	}
}

func TestEnvSecretRejectedByAPI(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecret("hello-api", "TOKEN", "t0k3n")
	// A plain variable may not take a secret's name unless the same update removes the secret;
	// runr env set does that, a raw project update does not.
	p, _ := h.srv.Project("hello-api")
	env := map[string]string{"TOKEN": "plain"}
	_, err := envTestClient(t, h).UpdateProject(t.Context(), p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{EnvVars: &env}})
	if !runr.IsValidation(err) {
		t.Fatalf("plain over secret: %v", err)
	}
	h.mustRun("env", "set", "TOKEN=plain", "--no-deploy", "--dir", dir)
}

func TestEnvSecretDebugTraceRedacted(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	r := h.mustRun("--debug", "env", "set", "--secret", "API_KEY=v3ry-s3cr3t", "--no-deploy", "--dir", dir)
	notContains(t, r.err, "v3ry-s3cr3t")
	contains(t, r.err, "/secrets/API_KEY")
	contains(t, r.err, "[redacted")

	h.srv.SeedSecret("hello-api", "OTHER", "an0ther-s3cr3t")
	r = h.mustRun("--debug", "env", "ls", "--reveal", "--dir", dir)
	notContains(t, r.err, "an0ther-s3cr3t") // the trace (stderr) never shows the value
	contains(t, r.out, "an0ther-s3cr3t")    // the result (stdout) does

	r = h.mustRun("--debug", "env", "set", "--secret", "GREETING=m0ved-s3cr3t", "--no-deploy", "--dir", dir)
	notContains(t, r.err, "m0ved-s3cr3t") // secret_env_vars of a project update
}

// envTestClient returns an API client of the harness's fake.
func envTestClient(t *testing.T, h *harness) *runr.Client {
	t.Helper()
	c, err := runr.New(runr.Options{APIURL: h.srv.URL, Token: runrtest.DefaultToken, HTTPClient: h.srv.Client(), Retries: -1})
	if err != nil {
		t.Fatal(err)
	}
	return c
}
