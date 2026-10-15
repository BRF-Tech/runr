package runr_test

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func TestSecretsEndpoints(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", map[string]any{"env_vars": map[string]any{"PLAIN": "1"}})

	s, err := c.SetSecret(ctx, p.ID, "TOKEN", "t0k3n")
	if err != nil || s.Name != "TOKEN" || s.UpdatedAt == "" {
		t.Fatalf("set = %+v, %v", s, err)
	}
	if _, err := c.SetSecret(ctx, p.ID, "TOKEN", "t0k3n-2"); err != nil {
		t.Fatalf("replace: %v", err)
	}
	if _, err := c.SetSecret(ctx, p.ID, "PLAIN", "x"); !runr.IsValidation(err) {
		t.Fatalf("a plain name as secret: %v", err)
	}
	list, err := c.ListSecrets(ctx, p.ID)
	if err != nil || len(list.Secrets) != 1 || list.Secrets[0].Name != "TOKEN" || !list.CanViewValues ||
		list.Secrets[0].UpdatedBy == nil || list.Secrets[0].UpdatedBy.ID != "1" {
		t.Fatalf("list = %+v, %v", list, err)
	}
	v, err := c.SecretValue(ctx, p.ID, "TOKEN")
	if err != nil || v != "t0k3n-2" {
		t.Fatalf("value = %q, %v", v, err)
	}
	if err := c.DeleteSecret(ctx, p.ID, "TOKEN"); err != nil {
		t.Fatal(err)
	}
	if err := c.DeleteSecret(ctx, p.ID, "TOKEN"); !runr.IsNotFound(err) {
		t.Fatalf("second delete: %v", err)
	}
}

func TestApplyEnvSecretOnlyUsesEndpoints(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", map[string]any{"env_vars": map[string]any{"A": "1"}})
	p, _ := c.FindProject(ctx, "svc")

	res, err := c.ApplyEnv(ctx, p, runr.EnvChange{Set: map[string]string{"S1": "a", "S2": "b"}, Secret: true}, "m")
	if err != nil || !res.Changed || res.NewVersion || strings.Join(res.SecretsSet, ",") != "S1,S2" {
		t.Fatalf("apply = %+v, %v", res, err)
	}
	if n := len(srv.CallsTo("PUT /projects/{project}/secrets/{name}")); n != 2 {
		t.Fatalf("%d secret calls", n)
	}
	if len(srv.CallsTo("PUT /projects/{project}")) != 0 {
		t.Fatal("a secret-only change updated the project")
	}
	if got := res.Project.Config.SecretEnvKeys; len(got) != 2 {
		t.Fatalf("secret keys after apply = %v", got)
	}

	// Unset of a secret only: DELETE.
	res, err = c.ApplyEnv(ctx, res.Project, runr.EnvChange{Unset: []string{"S1"}}, "m")
	if err != nil || strings.Join(res.SecretsRemoved, ",") != "S1" || len(srv.CallsTo("DELETE /projects/{project}/secrets/{name}")) != 1 {
		t.Fatalf("unset = %+v, %v", res, err)
	}

	// Unset of a name that is neither: nothing sent.
	srv.ResetCalls()
	res, _ = c.ApplyEnv(ctx, res.Project, runr.EnvChange{Unset: []string{"NOPE"}}, "m")
	if res.Changed || len(srv.Calls()) != 0 {
		t.Fatalf("no-op unset = %+v, calls %v", res, srv.Calls())
	}
}

func TestApplyEnvSecretLimits(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	p, _ := c.FindProject(ctx, "svc")

	_, err := c.ApplyEnv(ctx, p, runr.EnvChange{Set: map[string]string{"BIG": strings.Repeat("x", runr.MaxSecretBytes+1)}, Secret: true}, "")
	if !runr.IsValidation(err) {
		t.Fatalf("oversized value: %v", err)
	}
	set := map[string]string{}
	for i := 0; i <= runr.MaxSecretsPerProject; i++ {
		set[fmt.Sprintf("S%03d", i)] = "v"
	}
	_, err = c.ApplyEnv(ctx, p, runr.EnvChange{Set: set, Secret: true}, "")
	if !runr.IsValidation(err) {
		t.Fatalf("%d secrets: %v", len(set), err)
	}
	if len(srv.CallsTo("PUT /projects/{project}/secrets/{name}")) != 0 {
		t.Fatal("a change over the limits was sent in part")
	}
}

func TestEnvDetails(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", map[string]any{"env_vars": map[string]any{"A": "1"}})
	srv.SeedSecret("svc", "TOKEN", "t0k3n")
	p, _ := c.FindProject(ctx, "svc")

	vars, err := c.EnvDetails(ctx, p, false)
	if err != nil || len(vars) != 2 || vars[0].Name != "A" || vars[1].Name != "TOKEN" || vars[1].Value != "" || vars[1].UpdatedAt == "" {
		t.Fatalf("details = %+v, %v", vars, err)
	}
	vars, err = c.EnvDetails(ctx, p, true)
	if err != nil || vars[1].Value != "t0k3n" || !vars[1].Revealed {
		t.Fatalf("revealed = %+v, %v", vars, err)
	}

	srv.DenySecretValues()
	_, err = c.EnvDetails(ctx, p, true)
	if perms := runr.MissingPermissions(err); len(perms) != 1 || perms[0] != "secrets.view_values" {
		t.Fatalf("reveal without permission: %v", err)
	}

	srv.SecretEnv = false
	p, _ = c.FindProject(ctx, "svc")
	vars, err = c.EnvDetails(ctx, p, false)
	if err != nil || len(vars) != 1 || vars[0].Secret {
		t.Fatalf("API without secrets: %+v, %v", vars, err)
	}
}

func TestSecretValuesNeverInTrace(t *testing.T) {
	srv := runrtest.New(t)
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	var trace bytes.Buffer
	c, _ := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	ctx := context.Background()
	p, _ := c.FindProject(ctx, "svc")
	if _, err := c.SetSecret(ctx, p.ID, "TOKEN", "first-s3cr3t"); err != nil {
		t.Fatal(err)
	}
	if _, err := c.SecretValue(ctx, p.ID, "TOKEN"); err != nil {
		t.Fatal(err)
	}
	m := map[string]string{"OTHER": "second-s3cr3t"}
	if _, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{SecretEnvVars: &m}}); err != nil {
		t.Fatal(err)
	}
	out := trace.String()
	for _, secret := range []string{"first-s3cr3t", "second-s3cr3t"} {
		if strings.Contains(out, secret) {
			t.Fatalf("trace shows %s:\n%s", secret, out)
		}
	}
	if !strings.Contains(out, "[redacted: ") || !strings.Contains(out, `"secret_env_vars":"[redacted]"`) {
		t.Fatalf("trace does not show the redaction:\n%s", out)
	}
	// The request lines stay in the trace; only the bodies are withheld.
	if !strings.Contains(out, "/secrets/TOKEN") {
		t.Fatalf("trace lost the request line:\n%s", out)
	}
}

func TestSecretOnlyUpdateSavesNoVersion(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	p, _ := c.FindProject(ctx, "svc")
	m := map[string]string{"S": "v"}
	up, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &runr.ConfigInput{SecretEnvVars: &m}})
	if err != nil || up.LatestVersion == nil || up.LatestVersion.Number != p.LatestVersion.Number {
		t.Fatalf("update = %+v, %v", up, err)
	}
	var ae *runr.APIError
	_, err = c.SetSecret(ctx, p.ID, "PORT", "1")
	if !errors.As(err, &ae) || ae.StatusCode != 422 {
		t.Fatalf("reserved name: %v", err)
	}
}
