package cmd

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

// gitFakeRunner replaces the git CLI of `runr git remote --add`.
type gitFakeRunner struct {
	calls [][]string
	dirs  []string
	// urls are the remotes the fake repository has (name → URL).
	urls map[string]string
}

func (g *gitFakeRunner) run(_ context.Context, dir string, args ...string) (string, error) {
	g.calls = append(g.calls, append([]string(nil), args...))
	g.dirs = append(g.dirs, dir)
	if len(args) == 3 && args[0] == "remote" && args[1] == "get-url" {
		if u, ok := g.urls[args[2]]; ok {
			return u + "\n", nil
		}
		return "", errors.New("git remote get-url " + args[2] + ": error: No such remote '" + args[2] + "'")
	}
	if len(args) == 4 && args[0] == "remote" && (args[1] == "add" || args[1] == "set-url") {
		if g.urls == nil {
			g.urls = map[string]string{}
		}
		g.urls[args[2]] = args[3]
	}
	return "", nil
}

func gitUseFakeRunner(t *testing.T) *gitFakeRunner {
	t.Helper()
	fake := &gitFakeRunner{}
	orig := gitRunner
	gitRunner = fake.run
	t.Cleanup(func() { gitRunner = orig })
	return fake
}

// gitHarness logs in and seeds the orders-api project with one push.
func gitHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarness(t).login()
	h.srv.SeedProject("orders-api", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedFiles("orders-api", map[string]string{"main.py": pythonHandler})
	h.srv.SeedGitPush("orders-api", "3f2c1aa0000000000000000000000000000000ab", "feat: intake")
	return h
}

func TestGitStatus(t *testing.T) {
	h := gitHarness(t)
	r := h.mustRun("git", "-p", "orders-api")
	contains(t, r.out, "/git/runr/orders-api.git")
	contains(t, r.out, "Deploy branch:")
	contains(t, r.out, "main (head 3f2c1aa)")
	contains(t, r.out, "feat: intake")
	contains(t, r.out, "Auto-deploy:")

	var out struct {
		Git     runr.GitInfo     `json:"git"`
		Deploys []runr.GitDeploy `json:"deploys"`
	}
	decodeJSON(t, h.mustRun("git", "status", "-p", "orders-api", "-o", "json").out, &out)
	if !strings.HasSuffix(out.Git.RemoteURL, "/git/runr/orders-api.git") || len(out.Deploys) != 1 || out.Deploys[0].Status != runr.GitDeployActive {
		t.Fatalf("status json = %+v", out)
	}
	h.expectCode(ExitNotFound, "git", "-p", "ghost")
}

func TestGitRemote(t *testing.T) {
	h := gitHarness(t)
	fake := gitUseFakeRunner(t)
	dir := h.project("name: orders-api\ntype: faas\nruntime: python\n", map[string]string{"main.py": pythonHandler})

	r := h.mustRun("git", "remote", "--dir", dir)
	contains(t, r.out, "git remote add runr ")
	contains(t, r.out, "git push runr main")
	contains(t, r.out, "git.push")
	contains(t, r.out, "credential."+h.srv.URL+".helper store")
	if len(fake.calls) != 0 {
		t.Fatalf("git ran without --add: %v", fake.calls)
	}

	r = h.mustRun("git", "remote", "--add", "--dir", dir)
	want := h.srv.URL + "/git/runr/orders-api.git"
	if len(fake.calls) != 2 || strings.Join(fake.calls[1], " ") != "remote add runr "+want || fake.dirs[1] != dir {
		t.Fatalf("git calls = %v in %v", fake.calls, fake.dirs)
	}

	// Adding again is a no-op; another URL needs --force.
	h.mustRun("git", "remote", "--add", "--dir", dir)
	if len(fake.calls) != 3 {
		t.Fatalf("an existing remote was added again: %v", fake.calls)
	}
	fake.urls["deploy"] = "https://elsewhere.example.com/x.git"
	h.expectCode(ExitUsage, "git", "remote", "deploy", "--add", "--dir", dir)
	h.mustRun("git", "remote", "deploy", "--add", "--force", "--dir", dir)
	last := fake.calls[len(fake.calls)-1]
	if strings.Join(last, " ") != "remote set-url deploy "+want {
		t.Fatalf("force = %v", last)
	}

	n := len(fake.calls)
	r = h.mustRun("git", "remote", "--add", "--print", "--dir", dir)
	contains(t, r.out, "git -C ")
	contains(t, r.out, "remote add runr "+want)
	if len(fake.calls) != n {
		t.Fatal("--print ran git")
	}
	h.expectCode(ExitUsage, "git", "remote", "--force", "--dir", dir)

	var res map[string]any
	decodeJSON(t, h.mustRun("git", "remote", "--dir", dir, "-o", "json").out, &res)
	if res["remote_url"] != want || res["deploy_branch"] != "main" {
		t.Fatalf("remote json = %v", res)
	}
}

func TestGitRemoteOfAConnectedProject(t *testing.T) {
	h := gitHarness(t)
	gh := h.srv.SeedGitProvider(runr.GitProviderGitHub, "GitHub", false)
	h.srv.SeedGitInstallation(gh.ID, "4711", "acme")
	h.srv.SeedGitRepository(gh.ID, "acme/orders", "main", true)
	h.mustRun("git", "connect", "--provider", "github", "--repo", "acme/orders", "-p", "orders-api")
	r := h.mustRun("git", "remote", "-p", "orders-api")
	contains(t, r.out, "Read-only")
	contains(t, r.out, "acme/orders")
}

func TestGitSetDeploysRedeploy(t *testing.T) {
	h := gitHarness(t)
	h.mustRun("git", "set", "--branch", "release", "--root-dir", "/services/api/", "--auto-deploy=false",
		"--previews", "--preview-limit", "5", "--preview-env", "FEATURE_FLAGS=beta", "-p", "orders-api")
	s := h.srv.GitSettingsOf("orders-api")
	if s.DeployBranch != "release" || s.RootDir != "services/api" || s.AutoDeploy || !s.PreviewsEnabled ||
		s.PreviewLimit != 5 || s.PreviewEnv["FEATURE_FLAGS"] != "beta" {
		t.Fatalf("settings = %+v", s)
	}
	body := h.srv.CallsTo("PUT /projects/{project}/git")[0].Body
	if _, sent := body["fork_previews"]; sent {
		t.Fatalf("unchanged flags were sent: %v", body)
	}

	// --preview-env merges, --unset-preview-env removes.
	h.mustRun("git", "set", "--preview-env", "DEBUG=1", "--unset-preview-env", "FEATURE_FLAGS", "-p", "orders-api")
	if env := h.srv.GitSettingsOf("orders-api").PreviewEnv; len(env) != 1 || env["DEBUG"] != "1" {
		t.Fatalf("preview env = %v", env)
	}
	h.expectCode(ExitUsage, "git", "set", "-p", "orders-api")
	h.expectCode(ExitUsage, "git", "set", "--preview-env", "1BAD=x", "-p", "orders-api")
	h.expectCode(ExitValidation, "git", "set", "--branch", "a..b", "-p", "orders-api")

	r := h.mustRun("git", "redeploy", "--wait", "-p", "orders-api")
	contains(t, r.out, "active")
	r = h.mustRun("git", "deploys", "-p", "orders-api")
	contains(t, r.out, "COMMIT")
	contains(t, r.out, "3f2c1aa")
	h.expectCode(ExitUsage, "git", "deploys", "--kind", "nightly", "-p", "orders-api")

	h.srv.Git().FailStatus = runr.GitDeployFailed
	r = h.expectCode(ExitFailed, "git", "redeploy", "--wait", "-p", "orders-api")
	contains(t, r.err, "failed")

	reader := h.srv.ScopedToken("reader", "git.pull")
	h.loginWith(reader)
	r = h.expectCode(ExitAuth, "git", "redeploy", "-p", "orders-api")
	contains(t, r.err, "git.push")
}

func TestGitConnectGitHub(t *testing.T) {
	h := gitHarness(t)
	gh := h.srv.SeedGitProvider(runr.GitProviderGitHub, "GitHub", false)
	h.srv.SeedGitRepository(gh.ID, "acme/orders", "main", true)

	// No installation yet: the installation page opens and the command fails.
	r := h.expectCode(ExitError, "git", "connect", "--provider", "github", "--repo", "acme/orders", "-p", "orders-api")
	contains(t, r.err, "GitHub App")
	if len(h.opened) != 1 || !strings.Contains(h.opened[0], "/apps/github/installations/new") {
		t.Fatalf("opened = %v", h.opened)
	}

	h.srv.SeedGitInstallation(gh.ID, "4711", "acme")
	r = h.mustRun("git", "installations", "-p", "orders-api")
	contains(t, r.out, "acme")
	r = h.mustRun("git", "repos", "--provider", "github", "-p", "orders-api")
	contains(t, r.out, "acme/orders")

	h.mustRun("git", "connect", "--provider", "github", "--repo", "acme/orders", "--branch", "main", "--previews", "-p", "orders-api")
	conn := h.srv.GitConnectionOf("orders-api")
	if conn == nil || conn.RepoFullName != "acme/orders" || conn.InstallationID.String() != "4711" {
		t.Fatalf("connection = %+v", conn)
	}
	if !h.srv.GitSettingsOf("orders-api").PreviewsEnabled {
		t.Fatal("--previews was not applied")
	}
	call := h.srv.CallsTo("POST /projects/{project}/git/connect")[0].Body
	if call["installation_id"] != "4711" || call["provider_id"] != gh.ID {
		t.Fatalf("connect body = %v", call)
	}

	// Connected already: exit 5; a second installation needs --installation.
	h.expectCode(ExitValidation, "git", "connect", "--provider", "github", "--repo", "acme/orders", "-p", "orders-api")
	h.mustRun("git", "disconnect", "--yes", "-p", "orders-api")
	h.srv.SeedGitInstallation(gh.ID, "4712", "acme-labs")
	r = h.expectCode(ExitUsage, "git", "connect", "--provider", "github", "--repo", "acme/orders", "-p", "orders-api")
	contains(t, r.err, "--installation")
	h.expectCode(ExitUsage, "git", "connect", "--provider", "github", "--repo", "acme/orders", "--token-stdin", "-p", "orders-api")
	h.expectCode(ExitNotFound, "git", "connect", "--provider", "forgejo", "--repo", "acme/orders", "-p", "orders-api")
	h.expectCode(ExitUsage, "git", "connect", "--provider", "github", "-p", "orders-api")
}

func TestGitConnectWithAccessToken(t *testing.T) {
	h := gitHarness(t)
	gl := h.srv.SeedGitProvider(runr.GitProviderGitLab, "GitLab", false)
	h.srv.SeedGitRepository(gl.ID, "acme/billing", "trunk", false)

	h.expectCode(ExitUsage, "git", "connect", "--provider", "gitlab", "--repo", "acme/billing", "-p", "orders-api")
	h.expectCode(ExitUsage, "git", "connect", "--provider", "gitlab", "--oauth", "-p", "orders-api")

	r := h.runStdin("glpat-SECRET\n", "git", "connect", "--provider", "gitlab", "--repo", "acme/billing", "--token-stdin",
		"-p", "orders-api", "--debug")
	if r.code != 0 {
		t.Fatalf("connect: exit %d\n%s", r.code, r.err)
	}
	if tokens := h.srv.GitAccessTokens(); len(tokens) != 1 || tokens[0] != "glpat-SECRET" {
		t.Fatalf("tokens = %v", tokens)
	}
	notContains(t, r.out+r.err, "glpat-SECRET")
	contains(t, r.out, "acme/billing on GitLab")

	// A prompt in a terminal.
	h.mustRun("git", "disconnect", "--yes", "-p", "orders-api")
	h.terminal = true
	h.secrets = []string{"glpat-PROMPTED"}
	h.mustRun("git", "connect", "--provider", "gitlab", "--repo", "acme/billing", "-p", "orders-api")
	if tokens := h.srv.GitAccessTokens(); len(tokens) != 2 || tokens[1] != "glpat-PROMPTED" {
		t.Fatalf("tokens = %v", tokens)
	}
}

func TestGitConnectGitLabSignIn(t *testing.T) {
	h := gitHarness(t)
	h.srv.SeedGitProvider(runr.GitProviderGitLab, "GitLab", true)
	r := h.mustRun("git", "connect", "--oauth", "-p", "orders-api")
	contains(t, r.out, "/oauth/authorize")
	if len(h.opened) != 1 || !strings.Contains(h.opened[0], "/oauth/authorize") {
		t.Fatalf("opened = %v", h.opened)
	}
	if h.srv.GitConnectionOf("orders-api") != nil {
		t.Fatal("--oauth connected by itself")
	}
}

func TestGitDisconnect(t *testing.T) {
	h := gitHarness(t)
	h.mustRun("git", "disconnect", "-p", "orders-api") // not connected: nothing to do
	gh := h.srv.SeedGitProvider(runr.GitProviderGitHub, "GitHub", false)
	h.srv.SeedGitInstallation(gh.ID, "4711", "acme")
	h.srv.SeedGitRepository(gh.ID, "acme/orders", "main", true)
	h.mustRun("git", "connect", "--provider", "github", "--repo", "acme/orders", "-p", "orders-api")
	h.expectCode(ExitUsage, "git", "disconnect", "-p", "orders-api")
	h.mustRun("git", "disconnect", "--yes", "-p", "orders-api")
	if h.srv.GitConnectionOf("orders-api") != nil {
		t.Fatal("still connected")
	}
	if len(h.srv.CallsTo("DELETE /projects/{project}/git/connect")) != 1 {
		t.Fatal("disconnect not sent")
	}
}

func TestPreviewsCommands(t *testing.T) {
	h := gitHarness(t)
	h.srv.SeedPreview("orders-api", 12, "feature/cart", runr.PreviewReady)
	h.srv.SeedPreview("orders-api", 9, "fix/tax", runr.PreviewDeleted)
	h.srv.SeedPreview("orders-api", 14, "feature/pay", runr.PreviewFailed)

	r := h.mustRun("previews", "-p", "orders-api")
	contains(t, r.out, "#12")
	contains(t, r.out, "#14")
	notContains(t, r.out, "#9 ")
	r = h.mustRun("git", "previews", "ls", "--all", "-p", "orders-api")
	contains(t, r.out, "#9")

	var list []runr.GitPreview
	decodeJSON(t, h.mustRun("previews", "-p", "orders-api", "-o", "json").out, &list)
	if len(list) != 2 || list[0].Number != 14 {
		t.Fatalf("previews json = %+v", list)
	}

	r = h.mustRun("previews", "get", "#12", "-p", "orders-api")
	contains(t, r.out, "orders-api-pr-12")
	h.expectCode(ExitNotFound, "previews", "get", "99", "-p", "orders-api")

	r = h.mustRun("previews", "redeploy", "14", "--wait", "-p", "orders-api")
	contains(t, r.out, "ready")

	h.expectCode(ExitUsage, "git", "previews", "rm", "12", "-p", "orders-api")
	h.mustRun("git", "previews", "rm", "pr-12", "--yes", "-p", "orders-api")
	for _, pv := range h.srv.PreviewsOf("orders-api") {
		if pv.Number == 12 && pv.Status != runr.PreviewDeleted {
			t.Fatalf("preview 12 = %+v", pv)
		}
	}
	h.expectCode(ExitValidation, "previews", "redeploy", "12", "-p", "orders-api")
}

func TestGitProviders(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedGitProvider(runr.GitProviderGitHub, "GitHub", false)
	h.srv.SeedGitProvider(runr.GitProviderForgejo, "Codeberg", false)
	r := h.mustRun("git", "providers")
	contains(t, r.out, "Codeberg")
	contains(t, r.out, "Forgejo/Gitea")
	var list []runr.GitProviderInfo
	decodeJSON(t, h.mustRun("git", "providers", "ls", "-o", "json").out, &list)
	if len(list) != 2 || list[0].ConnectModes[0] != "app" || !list[0].HasPrivateKey {
		t.Fatalf("providers json = %+v", list)
	}
}
