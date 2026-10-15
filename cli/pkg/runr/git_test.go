package runr_test

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func gitTestProject(t *testing.T, srv *runrtest.Server) runr.Project {
	t.Helper()
	p := srv.SeedProject("orders-api", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedFiles("orders-api", map[string]string{"main.py": "def handler(event, context):\n    return 1\n"})
	return p
}

func gitWait() runr.WaitOptions {
	return runr.WaitOptions{Timeout: 5 * time.Second, Interval: time.Millisecond}
}

func TestGitInfoAndSettings(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	p := gitTestProject(t, srv)
	c := client(t, srv)

	info, err := c.GetGit(ctx, p.ID)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasSuffix(info.RemoteURL, "/git/runr/orders-api.git") || info.ReadOnly || info.RemoteReady {
		t.Fatalf("info = %+v", info)
	}
	if info.Settings.DeployBranch != "main" || !info.Settings.AutoDeploy || info.Settings.PreviewLimit != 3 {
		t.Fatalf("default settings = %+v", info.Settings)
	}
	if !info.Abilities.Pull || !info.Abilities.ManagePreviews {
		t.Fatalf("abilities = %+v", info.Abilities)
	}

	branch, root, off, on, limit := "release", "services/api", false, true, 5
	env := map[string]string{"FEATURE_FLAGS": "beta"}
	info, err = c.UpdateGit(ctx, p.ID, runr.GitSettingsInput{DeployBranch: &branch, RootDir: &root, AutoDeploy: &off,
		PreviewsEnabled: &on, PreviewLimit: &limit, PreviewEnv: &env})
	if err != nil {
		t.Fatal(err)
	}
	s := info.Settings
	if s.DeployBranch != "release" || s.RootDir != "services/api" || s.AutoDeploy || !s.PreviewsEnabled || s.PreviewLimit != 5 ||
		s.PreviewEnv["FEATURE_FLAGS"] != "beta" {
		t.Fatalf("updated settings = %+v", s)
	}
	body := srv.CallsTo("PUT /projects/{project}/git")[0].Body
	if _, sent := body["allow_force_push"]; sent {
		t.Fatalf("unset fields were sent: %v", body)
	}

	bad := "../main"
	if _, err := c.UpdateGit(ctx, p.ID, runr.GitSettingsInput{DeployBranch: &bad}); !runr.IsValidation(err) {
		t.Fatalf("invalid branch: %v", err)
	}

	// Preview settings need previews.manage on top of git.manage.
	scoped := c.WithToken(srv.ScopedToken("git-admin", "git.manage"))
	if _, err := scoped.UpdateGit(ctx, p.ID, runr.GitSettingsInput{AutoDeploy: &on}); err != nil {
		t.Fatalf("git.manage update: %v", err)
	}
	if _, err := scoped.UpdateGit(ctx, p.ID, runr.GitSettingsInput{PreviewsEnabled: &off}); err == nil || !runr.IsUnauthorized(err) {
		t.Fatalf("preview change without previews.manage: %v", err)
	}
	reader := c.WithToken(srv.ScopedToken("viewer", "git.pull"))
	if _, err := reader.GetGit(ctx, p.ID); err != nil {
		t.Fatalf("git.pull read: %v", err)
	}
	if _, err := reader.RedeployGit(ctx, p.ID); len(runr.MissingPermissions(err)) == 0 {
		t.Fatalf("redeploy with git.pull only: %v", err)
	}
}

func TestGitRedeployAndWait(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	p := gitTestProject(t, srv)
	c := client(t, srv)

	if _, err := c.RedeployGit(ctx, p.ID); !runr.IsValidation(err) {
		t.Fatalf("redeploy of an empty remote: %v", err)
	}

	srv.SeedGitPush("orders-api", strings.Repeat("3f2c1aa", 5)+"12345", "feat: intake")
	d, err := c.RedeployGit(ctx, p.ID)
	if err != nil {
		t.Fatal(err)
	}
	if d.Status != runr.GitDeployQueued || d.Trigger != "redeploy" || d.ShortSHA != "3f2c1aa" {
		t.Fatalf("redeploy = %+v", d)
	}
	var seen []string
	opts := gitWait()
	opts.Progress = func(s string) { seen = append(seen, s) }
	d, err = c.WaitGitDeploy(ctx, p.ID, d, opts)
	if err != nil || d.Status != runr.GitDeployActive {
		t.Fatalf("wait = %+v, %v", d, err)
	}
	if len(seen) == 0 || seen[0] != runr.GitDeployQueued {
		t.Fatalf("progress = %v", seen)
	}

	list, page, err := c.GitDeploys(ctx, p.ID, "production", 1)
	if err != nil || len(list) != 1 || page.Total != 2 || list[0].ID != d.ID {
		t.Fatalf("deploys = %+v, %+v, %v", list, page, err)
	}
	if q := srv.CallsTo("GET /projects/{project}/git/deploys"); !strings.Contains(q[len(q)-1].Query, "per_page=1") {
		t.Fatalf("query = %q", q[len(q)-1].Query)
	}

	srv.Git().FailStatus = runr.GitDeploySkipped
	srv.Git().FailMessage = "The version is blocked by the policy gate."
	d, _ = c.RedeployGit(ctx, p.ID)
	_, err = c.WaitGitDeploy(ctx, p.ID, d, gitWait())
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.GitDeploySkipped || !strings.Contains(fe.Error(), "policy gate") {
		t.Fatalf("failed deploy: %v", err)
	}
}

func TestGitConnectAndDisconnect(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	p := gitTestProject(t, srv)
	c := client(t, srv)

	gh := srv.SeedGitProvider(runr.GitProviderGitHub, "GitHub", false)
	gl := srv.SeedGitProvider(runr.GitProviderGitLab, "GitLab", true)
	srv.SeedGitInstallation(gh.ID, "4711", "acme")
	srv.SeedGitRepository(gh.ID, "acme/orders", "main", true)
	srv.SeedGitRepository(gl.ID, "acme/billing", "trunk", false)

	info, _ := c.GetGit(ctx, p.ID)
	if len(info.Providers) != 2 {
		t.Fatalf("providers = %+v", info.Providers)
	}
	pr, err := runr.PickGitProvider(info.Providers, "github")
	if err != nil || pr.ID != gh.ID || !pr.HasMode("app") {
		t.Fatalf("pick github = %+v, %v", pr, err)
	}

	inst, err := c.GitInstallations(ctx, p.ID, gh.ID)
	if err != nil || len(inst) != 1 || inst[0].InstallationID.String() != "4711" || inst[0].AccountLogin != "acme" {
		t.Fatalf("installations = %+v, %v", inst, err)
	}
	if _, err := c.GitRepositories(ctx, p.ID, runr.GitCredentials{ProviderID: gh.ID, InstallationID: "999"}, ""); !runr.IsValidation(err) {
		t.Fatalf("unknown installation: %v", err)
	}
	repos, err := c.GitRepositories(ctx, p.ID, runr.GitCredentials{ProviderID: gh.ID, InstallationID: "4711"}, "ord")
	if err != nil || len(repos) != 1 || repos[0].FullName != "acme/orders" || !repos[0].Private {
		t.Fatalf("repos = %+v, %v", repos, err)
	}
	u, err := c.GitHubInstallURL(ctx, p.ID, gh.ID)
	if err != nil || !strings.Contains(u, "/apps/github/installations/new?state=") {
		t.Fatalf("install url = %q, %v", u, err)
	}
	u, err = c.GitLabAuthorizeURL(ctx, p.ID, gl.ID)
	if err != nil || !strings.Contains(u, "/oauth/authorize") {
		t.Fatalf("gitlab url = %q, %v", u, err)
	}

	info, err = c.ConnectGit(ctx, p.ID, runr.GitCredentials{ProviderID: gh.ID, InstallationID: "4711"}, "acme/orders")
	if err != nil {
		t.Fatal(err)
	}
	if !info.ReadOnly || info.Connection == nil || info.Connection.RepoFullName != "acme/orders" || info.Connection.AuthMode != "app" {
		t.Fatalf("connected = %+v", info)
	}
	if srv.CallsTo("POST /projects/{project}/git/connect")[0].Body["repository"] != "acme/orders" {
		t.Fatal("repository not sent")
	}
	if _, err := c.ConnectGit(ctx, p.ID, runr.GitCredentials{ProviderID: gh.ID, InstallationID: "4711"}, "acme/orders"); !runr.IsValidation(err) {
		t.Fatalf("second connect: %v", err)
	}

	srv.SeedPreview("orders-api", 7, "feature/x", runr.PreviewReady)
	info, err = c.DisconnectGit(ctx, p.ID)
	if err != nil || info.Connection != nil || info.ReadOnly {
		t.Fatalf("disconnected = %+v, %v", info, err)
	}
	if pv := srv.PreviewsOf("orders-api"); len(pv) != 1 || pv[0].Status != runr.PreviewDeleted {
		t.Fatalf("previews after disconnect = %+v", pv)
	}

	// A GitLab access token: sent once, never in the trace.
	var trace bytes.Buffer
	dc, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	if _, err := dc.ConnectGit(ctx, p.ID, runr.GitCredentials{ProviderID: gl.ID, AccessToken: "glpat-SECRET-123"}, "acme/billing"); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(trace.String(), "glpat-SECRET-123") {
		t.Fatalf("access token in the trace:\n%s", trace.String())
	}
	if got := srv.GitAccessTokens(); len(got) != 1 || got[0] != "glpat-SECRET-123" {
		t.Fatalf("tokens = %v", got)
	}
}

func TestGitPreviews(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	p := gitTestProject(t, srv)
	c := client(t, srv)

	srv.SeedPreview("orders-api", 12, "feature/cart", runr.PreviewReady)
	srv.SeedPreview("orders-api", 9, "fix/tax", runr.PreviewDeleted)
	srv.SeedPreview("orders-api", 14, "feature/pay", runr.PreviewBuilding)

	live, err := c.ListPreviews(ctx, p.ID, false)
	if err != nil || len(live) != 2 {
		t.Fatalf("live previews = %+v, %v", live, err)
	}
	all, _ := c.ListPreviews(ctx, p.ID, true)
	if len(all) != 3 {
		t.Fatalf("all previews = %+v", all)
	}
	if q := srv.CallsTo("GET /projects/{project}/previews"); q[len(q)-1].Query != "all=1" {
		t.Fatalf("query = %q", q[len(q)-1].Query)
	}

	for _, ref := range []string{"12", "#12", "pr-12", "feature/cart"} {
		pv, err := c.FindPreview(ctx, p.ID, ref)
		if err != nil || pv.Number != 12 {
			t.Fatalf("FindPreview(%q) = %+v, %v", ref, pv, err)
		}
	}
	var nf *runr.NotFoundError
	if _, err := c.FindPreview(ctx, p.ID, "99"); !errors.As(err, &nf) {
		t.Fatalf("unknown preview: %v", err)
	}

	pv, _ := c.FindPreview(ctx, p.ID, "12")
	pv, err = c.RedeployPreview(ctx, p.ID, pv.ID)
	if err != nil || pv.Status != runr.PreviewDeploying {
		t.Fatalf("redeploy = %+v, %v", pv, err)
	}
	pv, err = c.WaitPreview(ctx, p.ID, pv, gitWait())
	if err != nil || pv.Status != runr.PreviewReady {
		t.Fatalf("wait = %+v, %v", pv, err)
	}
	building, _ := c.FindPreview(ctx, p.ID, "14")
	if _, err := c.RedeployPreview(ctx, p.ID, building.ID); !runr.IsValidation(err) {
		t.Fatalf("redeploy of a building preview: %v", err)
	}

	pv, err = c.DeletePreview(ctx, p.ID, pv.ID)
	if err != nil || pv.Status != runr.PreviewDeleted || pv.ClosedAt == "" {
		t.Fatalf("delete = %+v, %v", pv, err)
	}
	if _, err := c.WaitPreview(ctx, p.ID, pv, gitWait()); err == nil {
		t.Fatal("waiting on a deleted preview succeeded")
	}
}

func TestGitPickHelpers(t *testing.T) {
	list := []runr.GitPreview{
		{ID: "a", Number: 3, Branch: "x", Status: runr.PreviewDeleted},
		{ID: "b", Number: 3, Branch: "x", Status: runr.PreviewReady},
	}
	if p := runr.PickPreview(list, "3"); p == nil || p.ID != "b" {
		t.Fatalf("live preview should win: %+v", p)
	}
	if p := runr.PickPreview(list, "a"); p == nil || p.ID != "a" {
		t.Fatalf("by id: %+v", p)
	}

	providers := []runr.GitProviderRef{
		{ID: "p1", Type: runr.GitProviderGitLab, Name: "GitLab.com"},
		{ID: "p2", Type: runr.GitProviderGitLab, Name: "Internal GitLab"},
		{ID: "p3", Type: runr.GitProviderForgejo, Name: "Codeberg"},
	}
	if _, err := runr.PickGitProvider(providers, "gitlab"); err == nil || !strings.Contains(err.Error(), "several gitlab providers") {
		t.Fatalf("ambiguous type: %v", err)
	}
	if p, err := runr.PickGitProvider(providers, "internal gitlab"); err != nil || p.ID != "p2" {
		t.Fatalf("by name: %+v, %v", p, err)
	}
	if p, err := runr.PickGitProvider(providers, "gitea"); err != nil || p.ID != "p3" {
		t.Fatalf("gitea alias: %+v, %v", p, err)
	}
	var nf *runr.NotFoundError
	if _, err := runr.PickGitProvider(providers, "github"); !errors.As(err, &nf) {
		t.Fatalf("unknown type: %v", err)
	}
}
