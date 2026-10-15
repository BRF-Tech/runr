package cmd

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/config"
	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func TestLoginWithTokenFromStdin(t *testing.T) {
	h := newHarness(t)
	r := h.runStdin(runrtest.DefaultToken+"\n", "login", h.srv.URL, "--token-stdin", "--name", "local")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	contains(t, r.out, "Logged in to "+h.srv.URL+" as ada")

	f, err := config.LoadFrom(h.cfgPath)
	if err != nil {
		t.Fatal(err)
	}
	c := f.Contexts["local"]
	if c == nil || c.Token != runrtest.DefaultToken || c.APIURL != h.srv.URL || c.User != "ada" {
		t.Fatalf("stored context = %+v", c)
	}
	if f.CurrentContext != "local" {
		t.Fatalf("current context = %q", f.CurrentContext)
	}
	if runtime.GOOS != "windows" {
		st, err := os.Stat(h.cfgPath)
		if err != nil {
			t.Fatal(err)
		}
		if st.Mode().Perm() != 0o600 {
			t.Fatalf("config permissions = %v, want 0600", st.Mode().Perm())
		}
	}
}

func TestLoginRejectsBadToken(t *testing.T) {
	h := newHarness(t)
	r := h.runStdin("wrong\n", "login", h.srv.URL, "--token-stdin")
	if r.code != ExitAuth {
		t.Fatalf("exit %d, want %d: %s", r.code, ExitAuth, r.err)
	}
	contains(t, r.err, "rejected the token")
	if _, err := os.Stat(h.cfgPath); err == nil {
		f, _ := config.LoadFrom(h.cfgPath)
		if len(f.Contexts) != 0 {
			t.Fatal("a rejected token was saved")
		}
	}
}

func TestLoginPromptsForTokenOnTerminal(t *testing.T) {
	h := newHarness(t)
	h.terminal = true
	h.secrets = []string{runrtest.DefaultToken}
	h.mustRun("login", h.srv.URL)
	h.mustRun("whoami")
}

func TestLoginNeedsTokenWhenNotInteractive(t *testing.T) {
	h := newHarness(t)
	h.expectCode(ExitUsage, "login", h.srv.URL)
}

func TestLoginWithPasswordCreatesTokenAndLogoutRevokesIt(t *testing.T) {
	h := newHarness(t)
	h.srv.TwoFactorCode = "123456"
	r := h.runStdin("secret-password\n", "login", h.srv.URL, "--with-password", "--username", "ada",
		"--password-stdin", "--otp", "123456", "--token-name", "laptop")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	f, _ := config.LoadFrom(h.cfgPath)
	c := f.Contexts[f.CurrentContext]
	if c == nil || c.TokenID == "" || !strings.Contains(c.Token, "plain_token") {
		t.Fatalf("context = %+v, want the created API token", c)
	}
	if len(h.srv.CallsTo("POST /tokens")) != 1 || len(h.srv.CallsTo("POST /auth/logout")) != 1 {
		t.Fatal("login did not create a token and end the session")
	}
	if name := h.srv.CallsTo("POST /tokens")[0].Body["name"]; name != "laptop" {
		t.Fatalf("token name = %v", name)
	}
	h.mustRun("whoami")

	h.mustRun("logout")
	if len(h.srv.CallsTo("DELETE /tokens/{token}")) != 1 {
		t.Fatal("logout did not revoke the token")
	}
	h.expectCode(ExitAuth, "whoami")
}

func TestLoginWithPasswordNeedsOTPWhenNotInteractive(t *testing.T) {
	h := newHarness(t)
	h.srv.TwoFactorCode = "123456"
	r := h.runStdin("secret-password\n", "login", h.srv.URL, "--with-password", "--username", "ada", "--password-stdin")
	if r.code != ExitUsage {
		t.Fatalf("exit %d, want %d: %s", r.code, ExitUsage, r.err)
	}
	contains(t, r.err, "--otp")
}

func TestLoginWithWrongPassword(t *testing.T) {
	h := newHarness(t)
	r := h.runStdin("nope\n", "login", h.srv.URL, "--with-password", "--username", "ada", "--password-stdin")
	if r.code != ExitAuth {
		t.Fatalf("exit %d, want %d: %s", r.code, ExitAuth, r.err)
	}
}

func TestWhoami(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("whoami")
	contains(t, r.out, "ada")
	contains(t, r.out, "ada@example.com")
	contains(t, r.out, h.srv.URL)

	r = h.mustRun("whoami", "-o", "json")
	var out struct {
		User struct {
			Username string `json:"username"`
		} `json:"user"`
		Context string `json:"context"`
	}
	decodeJSON(t, r.out, &out)
	if out.User.Username != "ada" || out.Context != "test" {
		t.Fatalf("whoami json = %+v", out)
	}
}

func TestEnvironmentOverridesContext(t *testing.T) {
	h := newHarness(t)
	t.Setenv(config.EnvAPIURL, h.srv.URL)
	t.Setenv(config.EnvToken, runrtest.DefaultToken)
	r := h.mustRun("whoami", "-o", "json")
	contains(t, r.out, "(environment)")
}

func TestNotLoggedIn(t *testing.T) {
	h := newHarness(t)
	r := h.expectCode(ExitAuth, "projects", "ls")
	contains(t, r.err, "runr login")
}

func TestContextCommands(t *testing.T) {
	h := newHarness(t)
	h.runStdin(runrtest.DefaultToken+"\n", "login", h.srv.URL, "--token-stdin", "--name", "a")
	h.runStdin(runrtest.DefaultToken+"\n", "login", h.srv.URL, "--token-stdin", "--name", "b")

	r := h.mustRun("context", "ls")
	contains(t, r.out, "a")
	contains(t, r.out, "b")

	r = h.mustRun("context", "current")
	contains(t, r.out, "b")

	h.mustRun("context", "use", "a")
	r = h.mustRun("context", "current", "-o", "json")
	contains(t, r.out, `"name": "a"`)

	h.expectCode(ExitNotFound, "context", "use", "nope")
	h.mustRun("context", "rm", "a")
	r = h.mustRun("context", "ls", "-o", "json")
	notContains(t, r.out, `"name": "a"`)

	// --context selects a context for one command.
	h.mustRun("--context", "b", "whoami")
	h.expectCode(ExitError, "--context", "missing", "whoami")
}

func TestWhoamiShowsPermissions(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("whoami")
	contains(t, r.out, "Role:")
	contains(t, r.out, "all permissions of the user")
	contains(t, r.out, "projects.deploy")

	h.loginWith(h.srv.ScopedToken("ci", "logs.view"))
	r = h.mustRun("whoami")
	contains(t, r.out, "scoped")
	contains(t, r.out, "logs.view, projects.view")
	notContains(t, r.out, "projects.deploy")

	var out struct {
		Permissions runr.Permissions `json:"permissions"`
	}
	decodeJSON(t, h.mustRun("whoami", "-o", "json").out, &out)
	if !out.Permissions.TokenScoped || !out.Permissions.Has("logs.view", "") || out.Permissions.Has("projects.deploy", "") {
		t.Fatalf("permissions json = %+v", out.Permissions)
	}
}

func TestWhoamiWithoutRBAC(t *testing.T) {
	h := newHarness(t).login()
	h.srv.RBAC = false
	r := h.mustRun("whoami")
	notContains(t, r.out, "Role:")
	notContains(t, r.err, "Warning")
}

func TestLoginShowsScopes(t *testing.T) {
	h := newHarness(t)
	token := h.srv.ScopedToken("ci", runr.ScopesDeploy...)
	r := h.runStdin(token+"\n", "login", h.srv.URL, "--token-stdin")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	contains(t, r.out, "Token scopes: code.edit, env.edit, env.view, projects.deploy, projects.update, projects.view, regions.view.")
}

func TestForbiddenNamesTheMissingPermission(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	r := h.expectCode(ExitAuth, "logs", "--dir", dir)
	contains(t, r.err, "requires logs.view")
	contains(t, r.err, "The token lacks permission logs.view")
	contains(t, r.err, "runr tokens create NAME --ability logs.view")

	r = h.expectCode(ExitAuth, "scale", "--max", "2", "--dir", dir)
	contains(t, r.err, "projects.update or env.edit")

	r = h.expectCode(ExitAuth, "env", "set", "A=1", "--dir", dir)
	contains(t, r.err, "The token lacks permission env.view")
}

// The documented CI scopes are enough for runr deploy (the fake enforces the contract's
// permissions, which mirror api/config/route_permissions.php).
func TestDeployScopesAreSufficient(t *testing.T) {
	h := newHarness(t)
	dir := h.project(helloYAML+"region: eu-1\n", map[string]string{"main.py": pythonHandler})

	h.loginWith(h.srv.ScopedToken("ci", runr.ScopesDeploy...))
	r := h.expectCode(ExitAuth, "deploy", "--dir", dir)
	contains(t, r.err, "projects.create")

	h.loginWith(h.srv.ScopedToken("ci-create", runr.ScopesDeployCreate...))
	h.mustRun("deploy", "--dir", dir)

	h.loginWith(h.srv.ScopedToken("ci", runr.ScopesDeploy...))
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# next\n")
	write(t, filepath.Join(dir, "runr.yaml"), helloYAML+"region: eu-1\nresources:\n  memory_mb: 256\n")
	r = h.mustRun("deploy", "--dir", dir, "-o", "json")
	contains(t, r.out, `"status": "active"`)
}

// A token that cannot read the variables must not overwrite them with a partial map.
func TestHiddenVariablesAreNotOverwritten(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.mustRun("env", "set", "KEEP=me", "--no-deploy", "--dir", dir)

	h.loginWith(h.srv.ScopedToken("ci", "projects.view", "regions.view", "projects.update", "code.edit", "projects.deploy"))
	r := h.expectCode(ExitAuth, "env", "set", "OTHER=1", "--dir", dir)
	contains(t, r.err, "hidden from this token")
	contains(t, r.err, "--ability env.view")

	write(t, filepath.Join(dir, "runr.yaml"), helloYAML+"resources:\n  memory_mb: 256\n")
	r = h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "env of runr.yaml is not applied")
	p, _ := h.srv.Project("hello-api")
	if p.Config.EnvVars["KEEP"] != "me" || p.Config.EnvVars["GREETING"] != "Hello" || *p.Config.MemoryMB != 256 {
		t.Fatalf("env = %v memory = %v", p.Config.EnvVars, *p.Config.MemoryMB)
	}
	for _, c := range h.srv.CallsTo("PUT /projects/{project}") {
		if cfg, ok := c.Body["config"].(map[string]any); ok {
			if _, sent := cfg["env_vars"]; sent && cfg["env_vars"].(map[string]any)["KEEP"] == nil {
				t.Fatal("a partial variable map was sent")
			}
		}
	}
}

func TestTokenPresets(t *testing.T) {
	h := newHarness(t).login()
	h.mustRun("tokens", "create", "gha", "--preset", "deploy", "--ability", "logs.view")
	body := h.srv.CallsTo("POST /tokens")[0].Body
	got := []string{}
	for _, a := range body["abilities"].([]any) {
		got = append(got, a.(string))
	}
	if strings.Join(got, ",") != "code.edit,env.edit,logs.view,projects.deploy,projects.update,projects.view,regions.view" {
		t.Fatalf("abilities = %v", got)
	}
	h.expectCode(ExitUsage, "tokens", "create", "x", "--preset", "everything")
}

func TestTokens(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("tokens", "create", "ci", "--expires", "30d", "--ability", "logs.view")
	contains(t, r.out, "plain_token")
	call := h.srv.CallsTo("POST /tokens")[0]
	if call.Body["expires_at"] == nil {
		t.Fatal("expires_at not sent")
	}
	if ab, _ := call.Body["abilities"].([]any); len(ab) != 1 || ab[0] != "logs.view" {
		t.Fatalf("abilities = %v", call.Body["abilities"])
	}

	r = h.mustRun("tokens", "ls")
	contains(t, r.out, "ci")
	contains(t, r.out, "logs.view")

	var list struct {
		Tokens []struct {
			ID string `json:"id"`
		} `json:"tokens"`
	}
	decodeJSON(t, h.mustRun("tokens", "ls", "-o", "json").out, &list)
	if len(list.Tokens) != 1 {
		t.Fatalf("tokens = %+v", list)
	}
	h.mustRun("tokens", "rm", list.Tokens[0].ID)
	h.expectCode(ExitNotFound, "tokens", "rm", "999")
	h.expectCode(ExitUsage, "tokens", "create", "x", "--expires", "soon")
}

func TestVersionCommand(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("version")
	contains(t, r.out, "Client")
	contains(t, r.out, "v0.5.0")

	r = h.mustRun("version", "--client", "-o", "json")
	notContains(t, r.out, "v0.5.0")

	gh := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"tag_name":"v9.9.9"}`))
	}))
	defer gh.Close()
	h.release = gh.URL
	r = h.mustRun("version", "--check-update")
	contains(t, r.out, "v9.9.9")

	// Without --check-update GitHub is not contacted.
	h.release = "http://127.0.0.1:1/never"
	r = h.mustRun("version")
	notContains(t, r.out, "Latest release")
}

func TestCompletion(t *testing.T) {
	h := newHarness(t)
	for _, shell := range []string{"bash", "zsh", "fish", "powershell"} {
		r := h.mustRun("completion", shell)
		if len(r.out) < 100 {
			t.Fatalf("completion %s printed %d bytes", shell, len(r.out))
		}
	}
}

func TestUsageErrors(t *testing.T) {
	h := newHarness(t).login()
	h.expectCode(ExitUsage, "projects", "ls", "--bogus")
	h.expectCode(ExitUsage, "projects", "ls", "-o", "xml")
	h.expectCode(ExitUsage, "rollback")
}

func TestDebugTraceRedactsToken(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("--debug", "projects", "ls")
	contains(t, r.err, "> GET "+h.srv.URL+"/api/projects")
	contains(t, r.err, "Authorization: Bearer [redacted]")
	notContains(t, r.err, runrtest.DefaultToken)
	contains(t, r.err, "< 200 OK")
}

func TestDebugTraceRedactsPassword(t *testing.T) {
	h := newHarness(t)
	r := h.runStdin("secret-password\n", "--debug", "login", h.srv.URL, "--with-password", "--username", "ada", "--password-stdin")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	notContains(t, r.err, "secret-password")
	notContains(t, r.err, "plain_token_2")
	contains(t, r.err, `"password":"[redacted]"`)
}
