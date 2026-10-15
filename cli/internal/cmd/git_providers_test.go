package cmd

import (
	"path/filepath"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const gitpPEM = "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAKj34GkxFhD90vcNLYLInFEX6Ppy1tPf9Cnzj4p4WGeKLs1Pt8Qu\n-----END RSA PRIVATE KEY-----\n"

func gitpFiles(t *testing.T) (pem, webhook, client string) {
	t.Helper()
	dir := t.TempDir()
	pem = filepath.Join(dir, "app.pem")
	webhook = filepath.Join(dir, "webhook.txt")
	client = filepath.Join(dir, "client.txt")
	write(t, pem, gitpPEM)
	write(t, webhook, "webhook-secret-0123456789\n")
	write(t, client, "client-secret-value\n")
	return pem, webhook, client
}

func TestGitProvidersCreateGitHubApp(t *testing.T) {
	h := newHarness(t).login()
	pem, webhook, client := gitpFiles(t)

	// Every GitHub App setting is required.
	r := h.expectCode(ExitUsage, "git", "providers", "create", "--type", "github", "--name", "acme-github", "--app-id", "123456")
	contains(t, r.err, "--private-key-file")

	r = h.mustRun("git", "providers", "create", "--type", "github", "--name", "acme-github", "--app-id", "123456",
		"--app-slug", "acme-runr", "--client-id", "Iv1.abc", "--client-secret-file", client, "--private-key-file", pem,
		"--webhook-secret-file", webhook)
	contains(t, r.out, "Enter at the provider:")
	contains(t, r.out, "webhook URL")
	contains(t, r.out, "private key, webhook secret, client secret")
	for _, secret := range []string{"client-secret-value", "MIIBOgIBAAJBAKj34", "webhook-secret-0123456789"} {
		notContains(t, r.out, secret)
		notContains(t, r.err, secret)
	}
	body := h.srv.CallsTo("POST /git-providers")[0].Body
	if body["type"] != "github" || body["app_id"] != "123456" || body["webhook_secret"] != "webhook-secret-0123456789" ||
		body["client_secret"] != "client-secret-value" {
		t.Fatalf("create body = %v", body)
	}

	var list []runr.GitProviderInfo
	decodeJSON(t, h.mustRun("git", "providers", "-o", "json").out, &list)
	if len(list) != 1 || !list[0].Configured || !list[0].HasPrivateKey {
		t.Fatalf("providers = %+v", list)
	}
	_, key, _ := h.srv.GitProviderSecrets(list[0].ID)
	if key != gitpPEM {
		t.Fatalf("stored key = %q", key)
	}
}

func TestGitProvidersCreateForgejoAndGitLab(t *testing.T) {
	h := newHarness(t).login()
	h.expectCode(ExitUsage, "git", "providers", "create", "--type", "forgejo", "--name", "codeberg")
	h.expectCode(ExitUsage, "git", "providers", "create", "--type", "bitbucket", "--name", "bb")
	h.expectCode(ExitUsage, "git", "providers", "create", "--type", "gitlab")

	var p runr.GitProviderInfo
	decodeJSON(t, h.mustRun("git", "providers", "create", "--type", "gitea", "--name", "codeberg", "--base-url",
		"https://codeberg.org/", "-o", "json").out, &p)
	if p.Type != runr.GitProviderForgejo || p.BaseURL != "https://codeberg.org" {
		t.Fatalf("forgejo = %+v", p)
	}

	// A GitLab OAuth application's secret from stdin.
	r := h.runStdin("gitlab-oauth-secret\n", "git", "providers", "create", "--type", "gitlab", "--name", "gitlab",
		"--client-id", "gl-app", "--client-secret-file", "-", "-o", "json")
	if r.code != 0 {
		t.Fatalf("gitlab create: exit %d\n%s", r.code, r.err)
	}
	decodeJSON(t, r.out, &p)
	if !p.HasClientSecret || p.ClientID != "gl-app" {
		t.Fatalf("gitlab = %+v", p)
	}
	if cs, _, _ := h.srv.GitProviderSecrets(p.ID); cs != "gitlab-oauth-secret" {
		t.Fatalf("client secret = %q", cs)
	}

	// Two secrets cannot both come from stdin.
	h.expectCode(ExitUsage, "git", "providers", "update", "gitlab", "--client-secret-file", "-", "--webhook-secret-file", "-")
}

func TestGitProvidersUpdateGetRm(t *testing.T) {
	h := gitHarness(t)
	pr := h.srv.SeedGitProvider(runr.GitProviderGitHub, "acme-github", false)

	h.expectCode(ExitUsage, "git", "providers", "update", "acme-github")
	h.expectCode(ExitUsage, "git", "providers", "update", "acme-github", "--enable", "--disable")

	pem, _, _ := gitpFiles(t)
	r := h.mustRun("git", "providers", "update", "acme-github", "--private-key-file", pem, "--disable", "--name", "acme")
	contains(t, r.err, "Updated git provider acme.")
	put := h.srv.CallsTo("PUT /git-providers/{provider}")
	if len(put) != 1 || put[0].Body["is_enabled"] != false || put[0].Body["name"] != "acme" || put[0].Body["private_key"] != gitpPEM {
		t.Fatalf("update = %+v", put)
	}
	if _, sent := put[0].Body["webhook_secret"]; sent {
		t.Fatalf("an unchanged secret was sent: %v", put[0].Body)
	}

	r = h.mustRun("git", "providers", "get", pr.ID)
	contains(t, r.out, "acme")
	contains(t, r.out, "Enabled:")

	// A provider that projects are connected through is not removed. (Disabled providers
	// accept no new connections: enable it again first.)
	h.mustRun("git", "providers", "update", "acme", "--enable")
	h.srv.SeedGitInstallation(pr.ID, "4242", "acme")
	h.srv.SeedGitRepository(pr.ID, "acme/orders", "main", true)
	h.mustRun("git", "connect", "--provider", pr.ID, "--repo", "acme/orders", "-p", "orders-api")
	h.expectCode(ExitValidation, "git", "providers", "rm", "acme", "--yes")

	h.mustRun("git", "disconnect", "-p", "orders-api", "--yes")
	h.expectCode(ExitUsage, "git", "providers", "rm", "acme")
	h.mustRun("git", "providers", "rm", "acme", "--yes")
	if n := len(h.srv.CallsTo("DELETE /git-providers/{provider}")); n != 2 {
		t.Fatalf("delete calls = %d", n)
	}
	h.expectCode(ExitNotFound, "git", "providers", "get", "acme")
}

func TestGitProvidersNeedTheirPermission(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedGitProvider(runr.GitProviderForgejo, "codeberg", false)
	tok := h.srv.ScopedToken("git", "git.manage")
	h.loginWith(tok)
	r := h.expectCode(ExitAuth, "git", "providers", "rm", "codeberg", "--yes")
	contains(t, r.err, "git_providers.manage")
}
