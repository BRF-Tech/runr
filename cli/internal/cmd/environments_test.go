package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestEnvironmentsCreateListUpdateDelete(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	r := h.mustRun("environments", "create", "staging", "--dir", dir, "--branch", "develop", "--min-scale", "1",
		"--env", "GREETING=Hi from staging", "--env", "SENTRY_DSN=", "-o", "json")
	var created runr.Environment
	decodeJSON(t, r.out, &created)
	if created.Name != "staging" || created.GitBranch != "develop" || created.WorkloadName != "hello-api-staging" {
		t.Fatalf("created = %+v", created)
	}
	body := h.srv.CallsTo("POST /projects/{project}/environments")[0].Body
	overrides, _ := body["overrides"].(map[string]any)
	vars, _ := overrides["env_vars"].(map[string]any)
	if vars["GREETING"] != "Hi from staging" || vars["SENTRY_DSN"] != nil || overrides["min_scale"] != float64(1) {
		t.Fatalf("create body = %v", body)
	}
	if _, sent := vars["SENTRY_DSN"]; !sent {
		t.Fatal("KEY= must send null (remove the project's variable)")
	}
	if _, sent := body["requires_approval"]; sent {
		t.Fatal("an unset flag was sent")
	}

	r = h.mustRun("envs", "--dir", dir)
	contains(t, r.out, "staging")
	contains(t, r.out, "Pipeline: staging → production")
	contains(t, r.out, "required") // production needs approval

	// update merges the overrides: min_scale kept, a variable added
	h.mustRun("environments", "update", "staging", "--dir", dir, "--env", "FEATURE_X=on", "--requires-approval")
	body = h.srv.CallsTo("PATCH /projects/{project}/environments/{environment}")[0].Body
	overrides, _ = body["overrides"].(map[string]any)
	vars, _ = overrides["env_vars"].(map[string]any)
	if vars["FEATURE_X"] != "on" || vars["GREETING"] != "Hi from staging" || overrides["min_scale"] != float64(1) || body["requires_approval"] != true {
		t.Fatalf("update body = %v", body)
	}

	r = h.mustRun("environments", "show", "staging", "--dir", dir)
	contains(t, r.out, "need approval")
	contains(t, r.out, "min_scale=1")
	contains(t, r.out, "env SENTRY_DSN (removed)")
	notContains(t, r.out, "Hi from staging") // values are masked

	h.expectCode(ExitUsage, "environments", "delete", "staging", "--dir", dir)
	h.mustRun("environments", "delete", "staging", "--dir", dir, "--yes")
	h.expectCode(ExitNotFound, "environments", "show", "staging", "--dir", dir)
	h.expectCode(ExitValidation, "environments", "delete", "production", "--dir", dir, "--yes")
}

func TestEnvironmentSecrets(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedSecret("hello-api", "API_KEY", "production-key")
	h.mustRun("environments", "create", "staging", "--dir", dir)

	h.runStdin("staging-key\n", "environments", "secrets", "set", "staging", "API_KEY", "--dir", dir)
	call := h.srv.CallsTo("PUT /projects/{project}/environments/{environment}/secrets/{name}")[0]
	if call.Body["value"] != "staging-key" {
		t.Fatalf("secret body = %v", call.Body)
	}
	h.mustRun("environments", "secrets", "set", "staging", "DB_URL=postgres://staging", "--dir", dir)

	r := h.mustRun("environments", "secrets", "ls", "staging", "--dir", dir)
	contains(t, r.out, "API_KEY")
	contains(t, r.out, "DB_URL")
	notContains(t, r.out, "staging-key")

	h.mustRun("environments", "secrets", "unset", "staging", "DB_URL", "--dir", dir)
	r = h.mustRun("environments", "secrets", "ls", "staging", "--dir", dir, "-o", "json")
	notContains(t, r.out, "DB_URL")
}

func TestDeployToAnEnvironment(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.mustRun("environments", "create", "staging", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	outFile := filepath.Join(t.TempDir(), "out.env")

	r := h.mustRun("deploy", "--dir", dir, "--env", "staging", "--output-env", outFile, "-o", "json")
	var res DeployResult
	decodeJSON(t, r.out, &res)
	if res.Environment != "staging" || res.Version.Label != "v2" || res.Deployment.Environment != "staging" {
		t.Fatalf("result = %+v (deployment %+v)", res, res.Deployment)
	}
	if res.URL != "https://hello-api-staging.eu-1.runr.test" {
		t.Fatalf("url = %q, want the environment's", res.URL)
	}
	calls := h.srv.CallsTo("POST /projects/{project}/deploy")
	if body := calls[len(calls)-1].Body; body["environment"] != "staging" {
		t.Fatalf("deploy body = %v", body)
	}
	// Production keeps running v1
	var prod *runr.Deployment
	for _, d := range h.srv.Deployments("hello-api") {
		if d.EnvironmentID == "" && d.Status == runr.DeploymentActive {
			dd := d
			prod = &dd
		}
	}
	if prod == nil || prod.VersionLabel != "v1" {
		t.Fatalf("production = %+v", prod)
	}
	data, err := os.ReadFile(outFile)
	if err != nil {
		t.Fatal(err)
	}
	contains(t, string(data), "environment=staging")

	// The runr.yaml config is not applied to an environment deploy
	write(t, filepath.Join(dir, "runr.yaml"), strings.Replace(helloYAML, "GREETING: Hello", "GREETING: Changed", 1))
	r = h.mustRun("deploy", "--dir", dir, "--env", "staging", "--version", "v2")
	contains(t, r.err, "are not applied to a deploy to staging")
	if n := len(h.srv.CallsTo("PUT /projects/{project}")); n != 0 {
		t.Fatalf("an environment deploy updated the project's config (%d calls)", n)
	}

	h.expectCode(ExitUsage, "deploy", "--dir", dir, "--env", "staging", "--image", "x:1")
	h.expectCode(ExitNotFound, "deploy", "--dir", dir, "--env", "nope")
}

func TestDeployToAnEnvironmentOfAnOlderAPI(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.Environments = false
	before := len(h.srv.CallsTo("POST /projects/{project}/deploy"))

	r := h.expectCode(ExitError, "deploy", "--dir", dir, "--env", "staging")
	contains(t, r.err, "no environments")
	if len(h.srv.CallsTo("POST /projects/{project}/deploy")) != before {
		t.Fatal("a deploy meant for an environment reached the project's own workload")
	}
	h.expectCode(ExitError, "environments", "--dir", dir)
}

func TestPromoteAndApprove(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	h.mustRun("deploy", "--dir", dir)
	h.srv.SeedEnvironment("hello-api", "dev", false)
	h.srv.SeedEnvironment("hello-api", "staging", false)
	h.srv.SeedEnvironmentDeployment("hello-api", "dev", 1)

	// dev → staging needs no approval: deployed and waited for
	r := h.mustRun("promote", "dev", "--dir", dir)
	contains(t, r.out, "v1 is live in staging")
	if d := h.srv.Deployments("hello-api")[0]; d.Environment != "staging" || d.VersionLabel != "v1" || d.Status != runr.DeploymentActive {
		t.Fatalf("staging deployment = %+v", d)
	}

	// staging → production: what changes, then a request that waits for an approver
	r = h.mustRun("promote", "staging", "--dir", dir, "--dry-run")
	contains(t, r.out, "To:")
	contains(t, r.out, "production")
	contains(t, r.out, "v2 → v1")
	contains(t, r.out, "required")
	if len(h.srv.Promotions("hello-api")) != 1 {
		t.Fatal("a dry run requested a promotion")
	}
	r = h.mustRun("promote", "staging", "--dir", dir, "-m", "release")
	contains(t, r.out, "waits for approval")
	pending := h.srv.Promotions("hello-api")[1]
	if pending.Status != runr.PromotionPendingApproval || pending.Message != "release" {
		t.Fatalf("pending = %+v", pending)
	}
	// The requester cannot approve their own
	h.expectCode(ExitAuth, "approvals", "approve", pending.ID, "--dir", dir)

	// One requested by someone else: listed in the inbox and approved
	seeded := h.srv.SeedPromotion("hello-api", "staging", "production")
	r = h.mustRun("approvals")
	contains(t, r.out, seeded.ID)
	contains(t, r.out, "staging → production")
	r = h.mustRun("approvals", "approve", seeded.ID, "--comment", "ship it", "--wait")
	contains(t, r.out, "v1 is live in production")
	approved := h.srv.Promotions("hello-api")[2]
	if approved.Status != runr.PromotionDeployed || approved.DecisionComment != "ship it" {
		t.Fatalf("approved = %+v", approved)
	}
	var prod runr.Deployment
	for _, d := range h.srv.Deployments("hello-api") {
		if d.EnvironmentID == "" && d.Status == runr.DeploymentActive {
			prod = d
		}
	}
	if prod.VersionLabel != "v1" {
		t.Fatalf("production after the approval = %+v", prod)
	}
	h.expectCode(ExitValidation, "approvals", "reject", seeded.ID)
	h.expectCode(ExitValidation, "promote", "production", "--dir", dir)
}

func TestRollbackAnEnvironment(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	h.mustRun("deploy", "--dir", dir)
	h.srv.SeedEnvironment("hello-api", "staging", false)
	h.srv.SeedEnvironmentDeployment("hello-api", "staging", 2)

	h.mustRun("rollback", "v1", "--dir", dir, "--env", "staging")
	calls := h.srv.CallsTo("POST /projects/{project}/deploy")
	body := calls[len(calls)-1].Body
	if body["environment"] != "staging" || body["restore_config"] != true {
		t.Fatalf("rollback body = %v", body)
	}
	if d := h.srv.Deployments("hello-api")[0]; d.Environment != "staging" || d.VersionLabel != "v1" || d.ConfigSource != runr.ConfigRestored {
		t.Fatalf("staging after the rollback = %+v", d)
	}
}

// An environment's timed warmer (docs/scaling.md#timed-warmer): the project's schedule unless it
// has its own; --no-warm keeps none warm there, --project-warm follows the project's again.
func TestEnvironmentTimedWarmer(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedEnvironment("hello-api", "staging", false)
	h.mustRun("environments", "update", "staging", "--dir", dir, "--env", "GREETING=hi")

	r := h.mustRun("environments", "show", "staging", "--dir", dir)
	contains(t, r.out, "Timed warmer:")
	contains(t, r.out, "off (as the project)")

	h.srv.ResetCalls()
	h.mustRun("environments", "update", "staging", "--dir", dir, "--warm", "0 9 * * 1-5=2", "--warm", "0  18 * * 1-5=0")
	body := h.srv.CallsTo("PATCH /projects/{project}/environments/{environment}")[0].Body
	overrides, _ := body["overrides"].(map[string]any)
	rows, _ := overrides["scale_schedule"].([]any)
	if len(rows) != 2 || rows[0].(map[string]any)["cron"] != "0 9 * * 1-5" || rows[0].(map[string]any)["min_scale"] != float64(2) {
		t.Fatalf("update body = %v", body)
	}
	if vars, _ := overrides["env_vars"].(map[string]any); vars["GREETING"] != "hi" {
		t.Fatalf("the other overrides were dropped: %v", overrides)
	}
	h.srv.SetEnvironmentWarmScale("hello-api", "staging", 2, time.Now().Add(-2*time.Minute))
	r = h.mustRun("environments", "show", "staging", "--dir", dir)
	contains(t, r.out, "0 9 * * 1-5 → 2, 0 18 * * 1-5 → 0 (organization time zone), its own schedule; 2 kept warm now")
	contains(t, r.out, "scale_schedule=0 9 * * 1-5 → 2")

	// None there: an empty schedule is sent
	h.srv.ResetCalls()
	h.mustRun("environments", "update", "staging", "--dir", dir, "--no-warm")
	overrides, _ = h.srv.CallsTo("PATCH /projects/{project}/environments/{environment}")[0].Body["overrides"].(map[string]any)
	if rows, isList := overrides["scale_schedule"].([]any); !isList || len(rows) != 0 {
		t.Fatalf("--no-warm overrides = %v", overrides)
	}
	contains(t, h.mustRun("environments", "show", "staging", "--dir", dir).out, "off in this environment")

	// The project's again: no scale_schedule is sent
	h.srv.ResetCalls()
	h.mustRun("environments", "update", "staging", "--dir", dir, "--project-warm")
	overrides, _ = h.srv.CallsTo("PATCH /projects/{project}/environments/{environment}")[0].Body["overrides"].(map[string]any)
	if _, sent := overrides["scale_schedule"]; sent {
		t.Fatalf("--project-warm overrides = %v", overrides)
	}
	contains(t, h.mustRun("environments", "show", "staging", "--dir", dir).out, "off (as the project)")

	// Checked before anything is sent
	h.srv.ResetCalls()
	h.expectCode(ExitUsage, "environments", "update", "staging", "--dir", dir, "--warm", "* * * * *=1", "--no-warm")
	h.expectCode(ExitUsage, "environments", "update", "staging", "--dir", dir, "--warm", "every day=1")
	h.expectCode(ExitUsage, "environments", "update", "staging", "--dir", dir, "--max-scale", "2", "--warm", "* * * * *=3")
	if len(h.srv.CallsTo("PATCH /projects/{project}/environments/{environment}")) != 0 {
		t.Fatal("an invalid warmer was sent")
	}
	// the API checks it against the project's max_scale too
	r = h.expectCode(ExitValidation, "environments", "update", "staging", "--dir", dir, "--warm", "* * * * *=9")
	contains(t, r.err, "maximum scale")
}
