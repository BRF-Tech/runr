package runr_test

import (
	"context"
	"encoding/json"
	"errors"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestEnvironmentOverridesJSON(t *testing.T) {
	var o runr.EnvironmentOverrides
	if err := json.Unmarshal([]byte(`{"env_vars":{"A":"1","B":null,"N":3},"min_scale":2}`), &o); err != nil {
		t.Fatal(err)
	}
	if *o.EnvVars["A"] != "1" || o.EnvVars["B"] != nil || *o.EnvVars["N"] != "3" || *o.MinScale != 2 {
		t.Fatalf("overrides = %+v", o)
	}
	for _, empty := range []string{`[]`, `null`, `{}`} {
		var e runr.EnvironmentOverrides
		if err := json.Unmarshal([]byte(empty), &e); err != nil || !e.Empty() {
			t.Fatalf("%s: %+v %v", empty, e, err)
		}
	}

	// A removed variable is sent as null; env_vars_hidden (an answer field) is never sent
	removed := (*string)(nil)
	v := "on"
	in := runr.EnvironmentInput{Name: "dev", Overrides: &runr.EnvironmentOverrides{EnvVars: map[string]*string{"X": &v, "Y": removed}, EnvVarsHidden: true}}
	data, err := json.Marshal(in)
	if err != nil {
		t.Fatal(err)
	}
	if string(data) != `{"name":"dev","overrides":{"env_vars":{"X":"on","Y":null}}}` {
		t.Fatalf("input JSON = %s", data)
	}
}

func TestEnvironmentsEndpoints(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c): pass\n"})

	e, err := c.CreateEnvironment(ctx, p.ID, runr.EnvironmentInput{Name: "staging"})
	if err != nil || e.Name != "staging" || e.IsPrimary {
		t.Fatalf("create = %+v %v", e, err)
	}
	list, err := c.ListEnvironments(ctx, p.ID)
	if err != nil || len(list.Environments) != 2 || list.Environments[1].Name != runr.PrimaryEnvironment || !list.Environments[1].RequiresApproval {
		t.Fatalf("list = %+v %v", list, err)
	}
	var nf *runr.NotFoundError
	if _, err := c.GetEnvironment(ctx, p.ID, "nope"); !errors.As(err, &nf) {
		t.Fatalf("unknown environment = %v, want a NotFoundError", err)
	}

	versions, _ := c.ListVersions(ctx, p.ID)
	start, err := c.StartDeployWith(ctx, p.ID, versions[0].ID, runr.DeployOptions{Environment: "staging"})
	if err != nil || start.Deployment.Environment != "staging" || start.Deployment.EnvironmentID != e.ID {
		t.Fatalf("deploy to staging = %+v %v", start, err)
	}
	if own, _ := c.ListDeployments(ctx, p.ID); len(own) != 0 {
		t.Fatalf("the project's own deployments = %+v", own)
	}
	if env, _ := c.ListEnvironmentDeployments(ctx, p.ID, "staging"); len(env) != 1 {
		t.Fatalf("staging's deployments = %+v", env)
	}

	srv.Environments = false
	if _, err := c.ListEnvironments(ctx, p.ID); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("older API: %v", err)
	}
	if _, err := c.GetEnvironment(ctx, p.ID, "staging"); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("older API get: %v", err)
	}
}

func TestReservedEnvironmentNames(t *testing.T) {
	for name, reserved := range map[string]bool{
		"production": true, "trigger": true, "qc": true, "bindings": true, "secret-env": true, "pr-12": true, "eu-pr-3": true,
		"v7": true, "staging": false, "pr": true, "preview": true, "v1beta": false, "qa-eu": false,
	} {
		if got := runr.ReservedEnvironmentName(name); got != reserved {
			t.Errorf("ReservedEnvironmentName(%q) = %v", name, got)
		}
	}
	for name, valid := range map[string]bool{"staging": true, "qa-eu": true, "Staging": false, "-dev": false, "dev-": false, "a23456789012345678901": false, "a2345678901234567890": true} {
		if got := runr.EnvironmentNamePattern.MatchString(name); got != valid {
			t.Errorf("EnvironmentNamePattern(%q) = %v", name, got)
		}
	}
}

// The fake refuses and normalizes what EnvironmentController / EnvironmentManager do.
func TestEnvironmentRulesOfTheFake(t *testing.T) {
	srv := runrtest.New(t)
	us := srv.AddRegion("us-1", true)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c): pass\n"})

	for _, name := range []string{"production", "pr-3", "v2", "-bad"} {
		if _, err := c.CreateEnvironment(ctx, p.ID, runr.EnvironmentInput{Name: name}); !runr.IsValidation(err) {
			t.Fatalf("create %q = %v, want a 422", name, err)
		}
	}
	branch := "refs/heads/develop"
	e, err := c.CreateEnvironment(ctx, p.ID, runr.EnvironmentInput{Name: "staging", GitBranch: &branch})
	if err != nil || e.GitBranch != "develop" || e.Position != 1 || e.OwnRegion || e.DefaultURL != "https://svc-staging.eu-1.runr.test" {
		t.Fatalf("create = %+v %v", e, err)
	}
	if _, err := c.CreateEnvironment(ctx, p.ID, runr.EnvironmentInput{Name: "qa", GitBranch: &branch}); !runr.IsValidation(err) {
		t.Fatalf("a second environment of branch develop = %v", err)
	}

	// Another region while nothing runs; refused once a deployment runs there
	region := us.ID
	if e, err = c.UpdateEnvironment(ctx, p.ID, "staging", runr.EnvironmentInput{RegionID: &region}); err != nil || !e.OwnRegion || e.RegionID != us.ID ||
		e.DefaultURL != "https://svc-staging.us-1.runr.test" {
		t.Fatalf("move = %+v %v", e, err)
	}
	srv.SeedEnvironmentDeployment("svc", "staging", 1)
	none := ""
	if _, err := c.UpdateEnvironment(ctx, p.ID, "staging", runr.EnvironmentInput{RegionID: &none}); !runr.IsValidation(err) {
		t.Fatalf("move while running = %v, want a 422", err)
	}

	// Overrides are saved as a whole, of the known fields only; a bad value is refused
	one, cpu := 1, "250m"
	e, err = c.UpdateEnvironment(ctx, p.ID, "staging", runr.EnvironmentInput{Overrides: &runr.EnvironmentOverrides{MinScale: &one, CPU: &cpu}})
	if err != nil || *e.Overrides.MinScale != 1 || *e.Overrides.CPU != "250m" {
		t.Fatalf("overrides = %+v %v", e, err)
	}
	e, err = c.UpdateEnvironment(ctx, p.ID, "staging", runr.EnvironmentInput{Overrides: &runr.EnvironmentOverrides{MinScale: &one}})
	if err != nil || e.Overrides.CPU != nil {
		t.Fatalf("overrides as a whole = %+v %v", e.Overrides, err)
	}
	bad := "0m"
	if _, err := c.UpdateEnvironment(ctx, p.ID, "staging", runr.EnvironmentInput{Overrides: &runr.EnvironmentOverrides{CPU: &bad}}); !runr.IsValidation(err) {
		t.Fatalf("cpu 0m = %v", err)
	}

	// Secrets: each write counts a version
	if s, err := c.SetEnvironmentSecret(ctx, p.ID, "staging", "API_KEY", "a"); err != nil || s.Version != 1 {
		t.Fatalf("set = %+v %v", s, err)
	}
	if s, err := c.SetEnvironmentSecret(ctx, p.ID, "staging", "API_KEY", "b"); err != nil || s.Version != 2 {
		t.Fatalf("set again = %+v %v", s, err)
	}
	list, err := c.ListEnvironmentSecrets(ctx, p.ID, "staging")
	if err != nil || len(list.Secrets) != 1 || list.Secrets[0].Version != 2 || !list.Secrets[0].Own() {
		t.Fatalf("secrets = %+v %v", list, err)
	}
	if _, err := c.SetEnvironmentSecret(ctx, p.ID, runr.PrimaryEnvironment, "API_KEY", "a"); !runr.IsValidation(err) {
		t.Fatalf("a production secret = %v, want a 422", err)
	}

	// Deleting the environment removes its domains and detaches its bindings
	if _, err := c.AddEnvironmentDomain(ctx, p.ID, "staging", "staging.example.com"); err != nil {
		t.Fatal(err)
	}
	srv.SeedBinding("cache", runr.BindingKV, "us-1", nil, nil)
	b, _ := c.FindBinding(ctx, "cache")
	if _, err := c.AttachBinding(ctx, p.ID, runr.AttachBindingInput{BindingID: b.ID, Environment: "staging"}); err != nil {
		t.Fatal(err)
	}
	if _, err := c.DeleteEnvironment(ctx, p.ID, "staging"); err != nil {
		t.Fatal(err)
	}
	if pb, _ := c.ProjectBindings(ctx, p.ID); len(pb.Attachments) != 0 {
		t.Fatalf("attachments left = %+v", pb.Attachments)
	}
	if _, err := c.CreateEnvironment(ctx, p.ID, runr.EnvironmentInput{Name: "staging"}); err != nil {
		t.Fatal(err)
	}
	if list, _, err := c.ListEnvironmentDomains(ctx, p.ID, "staging"); err != nil || len(list) != 0 {
		t.Fatalf("domains of the new staging = %+v %v", list, err)
	}
}

// Private connections: the fake refuses a global project, a region without Skupper and a link
// that is no Skupper v2 AccessToken or Link, and reports the region's status.
func TestPrivateConnectionRulesOfTheFake(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	in := runr.PrivateConnectionInput{Name: "orders", RoutingKey: "pg-orders", Database: "orders", Username: "app", Password: "pw",
		LinkToken: "apiVersion: skupper.io/v2alpha1\nkind: AccessToken\nspec: {}\n"}

	bad := in
	bad.LinkToken = "apiVersion: v1\nkind: Secret\n"
	if _, _, err := c.CreatePrivateConnection(ctx, p.ID, bad); !runr.IsValidation(err) {
		t.Fatalf("a v1 token = %v", err)
	}
	srv.SetRegionSkupper("eu-1", false)
	if _, _, err := c.CreatePrivateConnection(ctx, p.ID, in); !runr.IsValidation(err) {
		t.Fatalf("no Skupper = %v", err)
	}
	srv.SetRegionSkupper("eu-1", true)
	pc, _, err := c.CreatePrivateConnection(ctx, p.ID, in)
	if err != nil || pc.LinkKind != "access_token" || pc.Port != 5432 || pc.SSLMode != "require" {
		t.Fatalf("create = %+v %v", pc, err)
	}
	if _, _, err := c.CreatePrivateConnection(ctx, p.ID, in); !runr.IsValidation(err) {
		t.Fatalf("the same name again = %v", err)
	}
	srv.SetPrivateConnectionState("svc", "orders", "pending")
	if _, st, err := c.GetPrivateConnectionStatus(ctx, p.ID, pc.ID); err != nil || st.State != "pending" {
		t.Fatalf("status = %+v %v", st, err)
	}
}
