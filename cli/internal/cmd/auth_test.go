package cmd

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/config"
	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
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

// loginWithPassword runs `runr login --with-password` against the fake with extra flags.
func (h *harness) loginWithPassword(extra ...string) result {
	h.t.Helper()
	args := append([]string{"login", h.srv.URL, "--with-password", "--username", "ada", "--password-stdin"}, extra...)
	return h.runStdin("secret-password\n", args...)
}

// requestedExpiry is the expires_at of the last POST /tokens (nil without one).
func (h *harness) requestedExpiry() *time.Time {
	h.t.Helper()
	calls := h.srv.CallsTo("POST /tokens")
	if len(calls) == 0 {
		h.t.Fatal("no POST /tokens")
	}
	raw, ok := calls[len(calls)-1].Body["expires_at"]
	if !ok {
		return nil
	}
	exp, err := time.Parse(time.RFC3339, raw.(string))
	if err != nil {
		h.t.Fatalf("expires_at %v: %v", raw, err)
	}
	return &exp
}

func near(t *testing.T, got *time.Time, want time.Time) {
	t.Helper()
	if got == nil || got.Sub(want).Abs() > time.Minute {
		t.Fatalf("expiry = %v, want about %v", got, want)
	}
}

func TestLoginWithPasswordTokenExpiresAfter90Days(t *testing.T) {
	h := newHarness(t)
	r := h.loginWithPassword("--token-name", "laptop")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	want := time.Now().Add(90 * 24 * time.Hour)
	near(t, h.requestedExpiry(), want)
	contains(t, r.out, `The API token "laptop" expires `)
	contains(t, r.out, "(in 90d); log in again then (--token-ttl sets its lifetime).")

	f, _ := config.LoadFrom(h.cfgPath)
	c := f.Contexts[f.CurrentContext]
	near(t, c.TokenExpiresAt, want)
	if !c.TokenExpiresAt.Equal(*h.requestedExpiry()) {
		t.Fatalf("stored %v, the API answered %v", c.TokenExpiresAt, h.requestedExpiry())
	}

	r = h.mustRun("whoami")
	contains(t, r.out, "Token expires:")
	contains(t, r.out, c.TokenExpiresAt.Local().Format(time.RFC3339)+" (in 90d)")
	var out struct {
		TokenExpiresAt *time.Time `json:"token_expires_at"`
	}
	decodeJSON(t, h.mustRun("whoami", "-o", "json").out, &out)
	if out.TokenExpiresAt == nil || !out.TokenExpiresAt.Equal(*c.TokenExpiresAt) {
		t.Fatalf("whoami json token_expires_at = %v", out.TokenExpiresAt)
	}

	// The login's JSON names the token and its expiry
	r = h.runStdin("secret-password\n", "login", h.srv.URL, "--with-password", "--username", "ada", "--password-stdin", "-o", "json")
	var login struct {
		TokenName      string     `json:"token_name"`
		TokenExpiresAt *time.Time `json:"token_expires_at"`
	}
	decodeJSON(t, r.out, &login)
	if !strings.HasPrefix(login.TokenName, "runr-cli@") || login.TokenExpiresAt == nil {
		t.Fatalf("login json = %+v", login)
	}
}

func TestLoginTokenTTL(t *testing.T) {
	h := newHarness(t)
	if r := h.loginWithPassword("--token-ttl", "30d"); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	near(t, h.requestedExpiry(), time.Now().Add(30*24*time.Hour))

	if r := h.loginWithPassword("--token-ttl", "36h"); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	near(t, h.requestedExpiry(), time.Now().Add(36*time.Hour))

	// 0: no expiry
	r := h.loginWithPassword("--token-ttl", "0")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	if exp := h.requestedExpiry(); exp != nil {
		t.Fatalf("--token-ttl 0 sent expires_at %v", exp)
	}
	contains(t, r.out, "does not expire (--token-ttl 0)")
	f, _ := config.LoadFrom(h.cfgPath)
	if c := f.Contexts[f.CurrentContext]; c.TokenExpiresAt != nil {
		t.Fatalf("stored expiry %v for a token without one", c.TokenExpiresAt)
	}
	contains(t, h.mustRun("whoami").out, "Token expires:")
	contains(t, h.mustRun("whoami").out, "never")
	notContains(t, h.mustRun("whoami", "-o", "json").out, "token_expires_at")

	// never: the same, as for `runr mcp token create --expires`
	if r := h.loginWithPassword("--token-ttl", "never"); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	if exp := h.requestedExpiry(); exp != nil {
		t.Fatalf("--token-ttl never sent expires_at %v", exp)
	}
}

func TestLoginTokenTTLIsValidatedFirst(t *testing.T) {
	h := newHarness(t)
	for _, v := range []string{"abc", "-5d", "0d", "-1h", "1.5d"} {
		r := h.loginWithPassword("--token-ttl", v)
		if r.code != ExitUsage {
			t.Fatalf("--token-ttl %s: exit %d, want %d: %s", v, r.code, ExitUsage, r.err)
		}
		contains(t, r.err, "--token-ttl")
	}
	// A pasted token was not created by the CLI: its lifetime is the console's
	r := h.runStdin(runrtest.DefaultToken+"\n", "login", h.srv.URL, "--token-stdin", "--token-ttl", "30d")
	if r.code != ExitUsage {
		t.Fatalf("exit %d, want %d: %s", r.code, ExitUsage, r.err)
	}
	contains(t, r.err, "--with-password")
	if n := len(h.srv.CallsTo("POST /auth/login")) + len(h.srv.CallsTo("GET /auth/me")); n != 0 {
		t.Fatalf("%d API calls before the flags were checked", n)
	}
}

func TestExpiredLoginTokenSaysToLogInAgain(t *testing.T) {
	h := newHarness(t)
	if r := h.loginWithPassword("--token-ttl", "1h"); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	// An hour later: the API refuses the token, and the stored expiry has passed
	f, _ := config.LoadFrom(h.cfgPath)
	name := f.CurrentContext
	c := f.Contexts[name]
	h.srv.ExpireToken(c.Token)
	past := time.Now().Add(-time.Minute).UTC().Truncate(time.Second)
	c.TokenExpiresAt = &past
	if err := f.Save(); err != nil {
		t.Fatal(err)
	}

	r := h.expectCode(ExitAuth, "whoami")
	contains(t, r.err, "The API token of context \""+name+"\" expired on "+past.Local().Format(time.RFC3339))
	contains(t, r.err, "log in again (runr login "+h.srv.URL+" --with-password)")
	notContains(t, r.err, "The token was rejected")
	h.expectCode(ExitAuth, "projects", "ls")

	// RUNR_TOKEN overrides the context's token: the generic hint
	t.Setenv(config.EnvToken, "wrong")
	r = h.expectCode(ExitAuth, "whoami")
	contains(t, r.err, "The token was rejected")
	t.Setenv(config.EnvToken, "")

	// logout does not try to revoke an expired token (the API would refuse the call)
	h.mustRun("logout")
	if n := len(h.srv.CallsTo("DELETE /tokens/{token}")); n != 0 {
		t.Fatalf("logout revoked an expired token (%d calls)", n)
	}
}

func TestLoginAgainRevokesThePreviousLoginToken(t *testing.T) {
	h := newHarness(t)
	if r := h.loginWithPassword(); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	f, _ := config.LoadFrom(h.cfgPath)
	name := f.CurrentContext
	first := *f.Contexts[name]

	if r := h.loginWithPassword(); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	revoked := h.srv.CallsTo("DELETE /tokens/{token}")
	if len(revoked) != 1 || !strings.HasSuffix(revoked[0].Path, "/tokens/"+first.TokenID) {
		t.Fatalf("revoked = %+v, want the first login's token %s", revoked, first.TokenID)
	}
	f, _ = config.LoadFrom(h.cfgPath)
	second := *f.Contexts[name]
	if second.TokenID == first.TokenID || second.Token == first.Token {
		t.Fatal("the second login kept the first token")
	}
	h.mustRun("whoami")
	t.Setenv(config.EnvToken, first.Token)
	h.expectCode(ExitAuth, "whoami")
	t.Setenv(config.EnvToken, "")

	// A login into another context leaves this context's token alone
	if r := h.loginWithPassword("--name", "other"); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	if n := len(h.srv.CallsTo("DELETE /tokens/{token}")); n != 1 {
		t.Fatalf("%d revocations after a login into another context", n)
	}

	// A pasted token replacing the CLI's own token: the CLI's token is revoked as well
	if r := h.runStdin(runrtest.DefaultToken+"\n", "login", h.srv.URL, "--token-stdin", "--name", name); r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	revoked = h.srv.CallsTo("DELETE /tokens/{token}")
	if len(revoked) != 2 || !strings.HasSuffix(revoked[1].Path, "/tokens/"+second.TokenID) {
		t.Fatalf("revoked = %+v, want the second login's token %s", revoked, second.TokenID)
	}
}

func TestRejectedTokenWithoutExpiryKeepsTheGenericHint(t *testing.T) {
	h := newHarness(t).loginWith("revoked")
	r := h.expectCode(ExitAuth, "whoami")
	contains(t, r.err, "The token was rejected: run `runr login` again or check RUNR_TOKEN.")
	notContains(t, r.out+r.err, "Token expires")
}

func TestTokenExpiryLabel(t *testing.T) {
	now := time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
	in90 := now.Add(90*24*time.Hour - 3*time.Second)
	if got := tokenExpiryLabel(&in90, now); !strings.HasSuffix(got, "(in 90d)") {
		t.Fatalf("90 days: %q", got)
	}
	in5h := now.Add(5 * time.Hour)
	if got := tokenExpiryLabel(&in5h, now); !strings.HasSuffix(got, "(in 5h)") {
		t.Fatalf("5 hours: %q", got)
	}
	if got := tokenExpiryLabel(&now, now); got != "expired "+now.Local().Format(time.RFC3339) {
		t.Fatalf("expired: %q", got)
	}
	if got := tokenExpiryLabel(nil, now); got != "never" {
		t.Fatalf("no expiry: %q", got)
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

	var latest atomic.Value
	latest.Store("v9.9.9\n")
	dl := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/dl/cli/latest.txt" {
			http.NotFound(w, r)
			return
		}
		w.Write([]byte(latest.Load().(string)))
	}))
	defer dl.Close()
	h.release = dl.URL + "/dl/cli/latest.txt"
	r = h.mustRun("version", "--check-update")
	contains(t, r.out, "Latest release")
	contains(t, r.out, "v9.9.9")
	contains(t, r.out, "A newer runr is available: v9.9.9")

	r = h.mustRun("version", "--check-update", "-o", "json")
	contains(t, r.out, `"latest_release": "v9.9.9"`)

	// Anything but a release tag is ignored.
	for _, bad := range []string{"<html>not found</html>", "latest", "v1.2", ""} {
		latest.Store(bad)
		r = h.mustRun("version", "--check-update")
		notContains(t, r.out, "Latest release")
	}

	// A missing file is no error either.
	h.release = dl.URL + "/missing"
	r = h.mustRun("version", "--check-update")
	notContains(t, r.out, "Latest release")

	// Without --check-update runr.sh is not contacted.
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
