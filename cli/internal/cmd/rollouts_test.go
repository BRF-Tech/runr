package cmd

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestStrategyShowAndSet(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	r := h.mustRun("strategy", "--dir", dir)
	contains(t, r.out, "Strategy:")
	contains(t, r.out, "direct")
	contains(t, r.out, "manual (runr rollout promote)")
	contains(t, r.out, "10 %, 50 %, 100 %, 5m each")
	contains(t, r.out, "error rate 5 %, p95 not checked")

	// Flags change only what they name
	h.srv.ResetCalls()
	r = h.mustRun("strategy", "set", "blue-green", "--auto-promote", "--health-path", "/healthz", "--keep-previous", "30m", "--dir", dir)
	contains(t, r.err, "Deploy strategy of hello-api saved: blue-green.")
	contains(t, r.out, "automatic after 3 health checks in a row")
	contains(t, r.out, "/healthz, 3 checks in a row")
	contains(t, r.out, "kept warm 30m after promotion")
	puts := h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")
	if len(puts) != 1 {
		t.Fatalf("%d PUTs", len(puts))
	}
	body := puts[0].Body
	bg, _ := body["blue_green"].(map[string]any)
	if body["strategy"] != "blue_green" || body["canary"] != nil || len(bg) != 3 || bg["auto_promote"] != true ||
		bg["health_path"] != "/healthz" || bg["keep_previous_minutes"] != float64(30) {
		t.Fatalf("PUT body = %v", body)
	}
	if s := h.srv.DeployStrategy("hello-api"); s.Strategy != runr.StrategyBlueGreen || s.BlueGreen.KeepPreviousMinutes != 30 || !s.BlueGreen.AutoPromote {
		t.Fatalf("stored = %+v", s)
	}

	r = h.mustRun("strategy", "set", "canary", "--steps", "5,25,100", "--interval", "2", "--max-error-rate", "1.5%",
		"--max-p95", "1.2s", "--dir", dir, "-o", "json")
	var s runr.DeployStrategy
	decodeJSON(t, r.out, &s)
	if s.Strategy != runr.StrategyCanary || !slices.Equal(s.Canary.Steps, []int{5, 25, 100}) || s.Canary.IntervalMinutes != 2 ||
		s.Canary.ErrorRateThreshold == nil || *s.Canary.ErrorRateThreshold != 1.5 || s.Canary.P95ThresholdMs == nil ||
		*s.Canary.P95ThresholdMs != 1200 || !s.BlueGreen.AutoPromote {
		t.Fatalf("canary settings = %+v", s)
	}

	// off clears a nullable option; the strategy stays
	h.srv.ResetCalls()
	r = h.mustRun("strategy", "set", "--max-p95", "off", "--health-path", "none", "--dir", dir, "-o", "json")
	var cleared runr.DeployStrategy
	decodeJSON(t, r.out, &cleared)
	if cleared.Strategy != runr.StrategyCanary || cleared.Canary.P95ThresholdMs != nil || cleared.BlueGreen.HealthPath != nil ||
		cleared.Canary.ErrorRateThreshold == nil {
		t.Fatalf("cleared = %+v", cleared)
	}
	body = h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")[0].Body
	if _, sent := body["strategy"]; sent {
		t.Fatalf("an option change sent the strategy: %v", body)
	}
	if v, ok := body["canary"].(map[string]any)["p95_threshold_ms"]; !ok || v != nil {
		t.Fatalf("--max-p95 off body = %v", body)
	}
	if v, ok := body["blue_green"].(map[string]any)["health_path"]; !ok || v != nil {
		t.Fatalf("--health-path none body = %v", body)
	}

	// Wrong invocations are refused before the API is called
	h.srv.ResetCalls()
	for _, args := range [][]string{
		{"strategy", "set"},
		{"strategy", "set", "rolling"},
		{"strategy", "set", "a", "b"},
		{"strategy", "set", "--steps", "50,10"},
		{"strategy", "set", "--steps", "ten"},
		{"strategy", "set", "--keep-previous", "90s"},
		{"strategy", "set", "--interval", "0"},
		{"strategy", "set", "--health-checks", "0"},
		{"strategy", "set", "--health-path", "healthz"},
		{"strategy", "set", "--max-error-rate", "120"},
		{"strategy", "set", "--max-p95", "fast"},
	} {
		h.expectCode(ExitUsage, append(args, "--dir", dir)...)
	}
	if n := len(h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")); n != 0 {
		t.Fatalf("refused invocations sent %d PUTs", n)
	}

	// A queue project deploys directly: the API refuses a strategy
	h.srv.SeedProject("worker", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "queue", "queue_stream": "jobs"})
	r = h.expectCode(ExitValidation, "strategy", "set", "canary", "-p", "worker")
	contains(t, r.err, "needs an http or workflow trigger")
	r = h.mustRun("strategy", "-p", "worker")
	contains(t, r.out, "Deploys of worker stay direct")

	// Changing the strategy needs projects.update
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	h.mustRun("strategy", "show", "--dir", dir)
	r = h.expectCode(ExitAuth, "strategy", "set", "direct", "--dir", dir)
	contains(t, r.err, "projects.update")
}

func TestDeployOpensBlueGreenRolloutAndPromote(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.mustRun("strategy", "set", "blue-green", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	envFile := filepath.Join(t.TempDir(), "github_output")

	// The deploy waits for the new revision, not for the promotion
	r := h.mustRun("deploy", "--dir", dir, "--output-env", envFile)
	contains(t, r.err, "Rolling out v2 (blue-green): the live revision keeps the traffic until the promotion")
	contains(t, r.err, "v2 of hello-api is ready")
	notContains(t, r.err, "is live")
	contains(t, r.out, "Rollout:")
	contains(t, r.out, "blue-green, waiting promotion")
	contains(t, r.out, "0 % on the new revision, 100 % on the previous one")
	contains(t, r.out, "https://green-hello-api.eu-1.runr.test")
	contains(t, r.out, "`runr rollout promote -p hello-api`")
	data, _ := os.ReadFile(envFile)
	for _, want := range []string{"status=running\n", "rollout_status=waiting_promotion\n", "test_url=https://green-hello-api.eu-1.runr.test\n", "rollout_id=01J"} {
		contains(t, string(data), want)
	}
	deps := h.srv.Deployments("hello-api")
	if deps[0].Status != runr.DeploymentRunning || deps[0].RolloutRole != runr.RolloutRoleCandidate ||
		deps[1].Status != runr.DeploymentActive || deps[1].RolloutRole != runr.RolloutRoleStable {
		t.Fatalf("deployments = %s/%s, %s/%s", deps[0].Status, deps[0].RolloutRole, deps[1].Status, deps[1].RolloutRole)
	}

	r = h.mustRun("status", "--dir", dir)
	contains(t, r.out, "active v1")
	contains(t, r.out, "Deploy strategy:")
	contains(t, r.out, "blue-green, waiting promotion: 0 % on the new revision")

	r = h.mustRun("rollout", "status", "--dir", dir)
	contains(t, r.out, "waiting promotion")
	contains(t, r.out, "created")
	contains(t, r.out, "ready")
	contains(t, r.out, "Check the new revision at its test URL")
	var ro runr.Rollout
	decodeJSON(t, h.mustRun("rollout", "--dir", dir, "-o", "json").out, &ro)
	if ro.Status != runr.RolloutWaitingPromotion || ro.Strategy != runr.StrategyBlueGreen || ro.DeploymentID != deps[0].ID || !ro.Can.Promote {
		t.Fatalf("rollout json = %+v", ro)
	}

	r = h.mustRun("rollout", "promote", "--dir", dir)
	contains(t, r.err, "Promoted: the new revision of hello-api has all the traffic.")
	contains(t, r.out, "promoted")
	contains(t, r.out, "Previous URL:")
	contains(t, r.out, "https://previous-hello-api.eu-1.runr.test")
	contains(t, r.out, "`runr rollout rollback -p hello-api`")
	deps = h.srv.Deployments("hello-api")
	if deps[0].Status != runr.DeploymentActive || deps[1].Status != runr.DeploymentRunning || deps[1].RolloutRole != runr.RolloutRolePrevious {
		t.Fatalf("after promotion = %s, %s/%s", deps[0].Status, deps[1].Status, deps[1].RolloutRole)
	}
	contains(t, h.mustRun("status", "--dir", dir).out, "active v2")

	r = h.mustRun("rollout", "rollback", "--dir", dir)
	contains(t, r.err, "Rolled back: the previous revision of hello-api has the traffic again.")
	contains(t, r.out, "rolled back")
	contains(t, r.out, "Rolled back by ada")
	deps = h.srv.Deployments("hello-api")
	if deps[0].Status != runr.DeploymentShuttingDown || deps[1].Status != runr.DeploymentActive {
		t.Fatalf("after rollback = %s, %s", deps[0].Status, deps[1].Status)
	}
	r = h.expectCode(ExitValidation, "rollout", "rollback", "--dir", dir)
	contains(t, r.err, "rollout_not_rollbackable")
}

func TestDeployStrategyOverride(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.mustRun("strategy", "set", "canary", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")

	// direct for this deploy: no rollout
	h.srv.ResetCalls()
	r := h.mustRun("deploy", "--dir", dir, "--strategy", "direct")
	contains(t, r.err, "Deploying v2 of hello-api (strategy direct for this deploy)")
	contains(t, r.out, "https://hello-api.eu-1.runr.test")
	notContains(t, r.out, "Rollout")
	if body := h.srv.CallsTo("POST /projects/{project}/deploy")[0].Body; body["strategy"] != "direct" {
		t.Fatalf("deploy body = %v", body)
	}
	if _, ok := h.srv.Rollout("hello-api"); ok {
		t.Fatal("--strategy direct opened a rollout")
	}

	h.expectCode(ExitUsage, "deploy", "--dir", dir, "--strategy", "rolling")
	h.expectCode(ExitUsage, "deploy", "--dir", dir, "--wait-rollout", "--no-wait")

	// Any spelling of blue-green
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v3\n")
	h.srv.ResetCalls()
	r = h.mustRun("deploy", "--dir", dir, "--strategy", "BlueGreen", "-o", "json")
	var res DeployResult
	decodeJSON(t, r.out, &res)
	if res.Deployment.Rollout == nil || res.Deployment.Rollout.Strategy != runr.StrategyBlueGreen || res.Deployment.Status != runr.DeploymentRunning {
		t.Fatalf("blue-green override = %+v", res.Deployment)
	}
	if body := h.srv.CallsTo("POST /projects/{project}/deploy")[0].Body; body["strategy"] != "blue_green" {
		t.Fatalf("deploy body = %v", body)
	}

	// rollback VERSION --strategy direct replaces the open rollout at once
	h.mustRun("rollback", "v1", "--dir", dir, "--strategy", "direct")
	if ro, _ := h.srv.Rollout("hello-api"); ro.Status != runr.RolloutAborted || ro.Reason != "Replaced by a newer deploy." {
		t.Fatalf("replaced rollout = %+v", ro)
	}
	if d := h.srv.Deployments("hello-api")[0]; d.VersionLabel != "v1" || d.Status != runr.DeploymentActive {
		t.Fatalf("rollback deployment = %+v", d)
	}
	h.expectCode(ExitUsage, "rollback", "v1", "--dir", dir, "--strategy", "rolling")
}

func TestDeployWaitRollout(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.mustRun("strategy", "set", "canary", "--interval", "1", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")

	// The canary is followed through its steps until the promotion
	r := h.mustRun("deploy", "--dir", dir, "--wait-rollout")
	contains(t, r.err, "v2 of hello-api is ready")
	contains(t, r.err, "Rollout: step: 50 % of the traffic on the new revision")
	contains(t, r.err, "Rollout: promoted: The new revision has all the traffic")
	contains(t, r.err, "v2 of hello-api is live")
	contains(t, r.out, "https://hello-api.eu-1.runr.test")
	deps := h.srv.Deployments("hello-api")
	if deps[0].Status != runr.DeploymentActive || deps[0].VersionLabel != "v2" || deps[1].Status != runr.DeploymentShutDown {
		t.Fatalf("after the canary = %+v", deps[:2])
	}

	// Over its error rate threshold, the canary rolls back: exit code 6
	h.srv.Rollouts().Tracing = true
	h.srv.Rollouts().ErrorRate = 9
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v3\n")
	envFile := filepath.Join(t.TempDir(), "github_output")
	r = h.expectCode(ExitFailed, "deploy", "--dir", dir, "--wait-rollout", "--output-env", envFile)
	contains(t, r.err, "rolled back: Error rate 9.0 % is over the 5.0 % threshold")
	contains(t, r.out, "canary, rolled back")
	contains(t, r.out, "Metrics:")
	data, _ := os.ReadFile(envFile)
	contains(t, string(data), "rollout_status=rolled_back\n")
	deps = h.srv.Deployments("hello-api")
	if deps[0].VersionLabel != "v3" || deps[0].Status != runr.DeploymentShuttingDown || deps[1].VersionLabel != "v2" || deps[1].Status != runr.DeploymentActive {
		t.Fatalf("after the rollback = %+v", deps[:2])
	}
}

func TestRolloutStatusAbortAndErrors(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()

	// No rollout yet
	r := h.expectCode(ExitNotFound, "rollout", "status", "--dir", dir)
	contains(t, r.err, "runr strategy set")
	h.expectCode(ExitNotFound, "rollout", "promote", "--dir", dir)

	h.srv.Rollouts().StepPolls = 100 // the canary stays at its first step
	h.mustRun("strategy", "set", "canary", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	var res DeployResult
	decodeJSON(t, h.mustRun("deploy", "--dir", dir, "-o", "json").out, &res)
	id := res.Deployment.ID
	if res.Deployment.Rollout == nil || res.Deployment.Rollout.Status != runr.RolloutProgressing || res.Deployment.Rollout.Traffic.Candidate != 10 {
		t.Fatalf("deploy result = %+v", res.Deployment.Rollout)
	}

	r = h.mustRun("rollout", "status", "--deployment", id, "--dir", dir)
	contains(t, r.out, "Step:")
	contains(t, r.out, "1 of 3 at 10 %, 50 % in ")
	contains(t, r.out, "no requests of the new revision measured: the canary moves on time only")
	contains(t, r.out, "The canary moves on by itself")

	stable := h.srv.Deployments("hello-api")[1].ID
	r = h.expectCode(ExitNotFound, "rollout", "status", "--deployment", stable, "--dir", dir)
	contains(t, r.err, "not the new deployment of a rollout")
	h.expectCode(ExitNotFound, "rollout", "promote", "--deployment", stable, "--dir", dir)

	// The cluster refuses the traffic change
	h.srv.Rollouts().FailTraffic = true
	r = h.expectCode(ExitUnreachable, "rollout", "promote", "--dir", dir)
	contains(t, r.err, "rollout_traffic_failed")
	h.srv.Rollouts().FailTraffic = false

	h.expectCode(ExitUsage, "rollout", "abort", "--reason", strings.Repeat("x", 501), "--dir", dir)
	h.srv.ResetCalls()
	r = h.mustRun("rollout", "abort", "--reason", "5xx on /checkout", "--dir", dir)
	contains(t, r.err, "Rollout of hello-api aborted: the traffic stays on the previous revision.")
	contains(t, r.out, "5xx on /checkout")
	if body := h.srv.CallsTo("POST /projects/{project}/deployments/{deployment}/rollout/abort")[0].Body; body["reason"] != "5xx on /checkout" {
		t.Fatalf("abort body = %v", body)
	}
	deps := h.srv.Deployments("hello-api")
	if deps[0].Status != runr.DeploymentShuttingDown || deps[1].Status != runr.DeploymentActive {
		t.Fatalf("after abort = %s, %s", deps[0].Status, deps[1].Status)
	}
	r = h.expectCode(ExitValidation, "rollout", "abort", "--deployment", id, "--dir", dir)
	contains(t, r.err, "rollout_not_abortable")

	// Promoting and aborting need projects.deploy
	h.loginWith(h.srv.ScopedToken("viewer", "projects.view"))
	h.mustRun("rollout", "status", "--dir", dir)
	r = h.expectCode(ExitAuth, "rollout", "promote", "--dir", dir)
	contains(t, r.err, "projects.deploy")
}

func TestRolloutStatusWatch(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.mustRun("strategy", "set", "canary", "--steps", "20,100", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	h.mustRun("deploy", "--dir", dir)

	r := h.mustRun("rollout", "status", "--watch", "--dir", dir)
	contains(t, r.err, "Rollout: promoted, 100 % of the traffic on the new revision")
	contains(t, r.out, "promoted")

	// A rollout that ended is shown at once
	var ro runr.Rollout
	decodeJSON(t, h.mustRun("rollout", "status", "--watch", "--dir", dir, "-o", "json").out, &ro)
	if ro.Status != runr.RolloutPromoted || ro.Open {
		t.Fatalf("watched rollout = %+v", ro)
	}
}

// runr deploy applies the deploy: section of runr.yaml before deploying, only what differs.
func TestDeployAppliesDeploySectionOfRunrYaml(t *testing.T) {
	h := newHarness(t).login()
	yaml := helloYAML + "deploy:\n  strategy: blue-green\n  blue-green:\n    health_path: /healthz\n    keep_previous_minutes: 30\n"
	dir := h.project(yaml, map[string]string{"main.py": pythonHandler})

	r := h.mustRun("deploy", "--dir", dir)
	contains(t, r.err, "Updating the deploy strategy of hello-api: strategy, blue_green.health_path, blue_green.keep_previous_minutes")
	puts := h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")
	if len(puts) != 1 {
		t.Fatalf("%d PUTs", len(puts))
	}
	bg, _ := puts[0].Body["blue_green"].(map[string]any)
	if puts[0].Body["strategy"] != "blue_green" || puts[0].Body["canary"] != nil || len(bg) != 2 ||
		bg["health_path"] != "/healthz" || bg["keep_previous_minutes"] != float64(30) {
		t.Fatalf("PUT body = %v", puts[0].Body)
	}
	if s := h.srv.DeployStrategy("hello-api"); s.Strategy != runr.StrategyBlueGreen || s.BlueGreen.HealthPath == nil || *s.BlueGreen.HealthPath != "/healthz" {
		t.Fatalf("stored = %+v", s)
	}
	if _, ok := h.srv.Rollout("hello-api"); ok {
		t.Fatal("the first deploy opened a rollout")
	}

	// Unchanged settings are not sent again; the next deploy rolls out
	h.srv.ResetCalls()
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	r = h.mustRun("deploy", "--dir", dir)
	if n := len(h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")); n != 0 {
		t.Fatalf("unchanged deploy: sent %d PUTs", n)
	}
	if n := len(h.srv.CallsTo("GET /projects/{project}/deploy-strategy")); n != 1 {
		t.Fatalf("%d GETs of the strategy", n)
	}
	notContains(t, r.err, "Updating the deploy strategy")
	contains(t, r.out, "Test URL:")

	// A changed option is sent alone
	write(t, filepath.Join(dir, "runr.yaml"), helloYAML+"deploy:\n  strategy: blue-green\n  blue_green:\n    health_path: /healthz\n"+
		"    keep_previous_minutes: 0\n  canary:\n    p95_threshold_ms: 900\n")
	h.srv.ResetCalls()
	h.mustRun("deploy", "--dir", dir, "--strategy", "direct")
	puts = h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")
	if len(puts) != 1 {
		t.Fatalf("%d PUTs", len(puts))
	}
	body := puts[0].Body
	if _, sent := body["strategy"]; sent {
		t.Fatalf("unchanged strategy sent: %v", body)
	}
	if bg := body["blue_green"].(map[string]any); len(bg) != 1 || bg["keep_previous_minutes"] != float64(0) {
		t.Fatalf("blue_green = %v", bg)
	}
	if c := body["canary"].(map[string]any); len(c) != 1 || c["p95_threshold_ms"] != float64(900) {
		t.Fatalf("canary = %v", c)
	}

	// An invalid section stops the deploy before the API is called
	write(t, filepath.Join(dir, "runr.yaml"), helloYAML+"deploy:\n  canary:\n    steps: [50, 10]\n")
	h.srv.ResetCalls()
	r = h.expectCode(ExitValidation, "deploy", "--dir", dir)
	contains(t, r.err, "deploy.canary.steps must increase strictly")
	if len(h.srv.Calls()) != 0 {
		t.Fatalf("an invalid runr.yaml called the API: %v", h.srv.Calls())
	}
}

// Each environment rolls out with its own strategy: the project's unless it sets its own
// (docs/deploy-strategies.md#environments); its rollouts are its own.
func TestEnvironmentStrategyAndRollout(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	h.srv.SeedEnvironment("hello-api", "staging", false)

	r := h.mustRun("strategy", "show", "--env", "staging", "--dir", dir)
	contains(t, r.out, "staging: the project's")
	contains(t, r.out, "direct")

	// staging's own: canary there, the project stays direct
	h.srv.ResetCalls()
	r = h.mustRun("strategy", "set", "canary", "--steps", "25,100", "--env", "staging", "--dir", dir)
	contains(t, r.err, "Deploy strategy of hello-api (staging) saved: canary.")
	contains(t, r.out, "staging: its own over the project's (direct)")
	contains(t, r.out, "25 %, 100 %, 5m each")
	body := h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")[0].Body
	if body["environment"] != "staging" || body["strategy"] != "canary" || body["reset"] != nil {
		t.Fatalf("PUT body = %v", body)
	}
	if s := h.srv.DeployStrategy("hello-api"); s.Strategy != runr.StrategyDirect {
		t.Fatalf("the project's strategy changed: %+v", s)
	}
	contains(t, h.mustRun("strategy", "--dir", dir).out, "direct")
	contains(t, h.mustRun("environments", "show", "staging", "--dir", dir).out, "Deploy strategy:")

	// The first deploy to staging goes directly; the next one rolls out next to it
	h.srv.Rollouts().StepPolls = 100 // the canary stays at its first step
	h.mustRun("deploy", "--env", "staging", "--dir", dir)
	write(t, filepath.Join(dir, "main.py"), pythonHandler+"# v2\n")
	r = h.mustRun("deploy", "--env", "staging", "--dir", dir)
	contains(t, r.err, "Rolling out v2 (canary)")
	contains(t, r.out, "--env staging")

	// production has no rollout; staging's is shown with --env
	r = h.expectCode(ExitNotFound, "rollout", "status", "--dir", dir)
	contains(t, r.err, "runr strategy set")
	h.srv.ResetCalls()
	r = h.mustRun("rollout", "status", "--env", "staging", "--dir", dir)
	contains(t, r.out, "Environment:")
	contains(t, r.out, "1 of 2 at 25 %")
	contains(t, r.out, "`runr rollout promote -p hello-api --env staging`")
	if calls := h.srv.CallsTo("GET /projects/{project}/rollout"); len(calls) != 1 || !strings.Contains(calls[0].Query, "environment=staging") {
		t.Fatalf("rollout reads = %+v", calls)
	}
	var ro runr.Rollout
	decodeJSON(t, h.mustRun("rollout", "--env", "staging", "--dir", dir, "-o", "json").out, &ro)
	if ro.Environment != "staging" || ro.Strategy != runr.StrategyCanary || !strings.HasPrefix(ro.Revision, "hello-api-staging-v2-") ||
		!strings.Contains(ro.TestURL, "hello-api-staging.") {
		t.Fatalf("staging rollout = %+v", ro)
	}

	r = h.mustRun("rollout", "promote", "--env", "staging", "--dir", dir)
	contains(t, r.err, "Promoted: the new revision of hello-api (staging) has all the traffic.")
	for _, d := range h.srv.Deployments("hello-api") {
		if d.Environment == runr.PrimaryEnvironment && d.Status != runr.DeploymentActive {
			t.Fatalf("production's deployment changed: %+v", d)
		}
	}

	// Back to the project's settings
	h.srv.ResetCalls()
	r = h.mustRun("strategy", "reset", "--env", "staging", "--dir", dir)
	contains(t, r.err, "hello-api (staging) deploys with the project's settings again: direct.")
	contains(t, r.out, "staging: the project's")
	if body := h.srv.CallsTo("PUT /projects/{project}/deploy-strategy")[0].Body; body["reset"] != true || body["environment"] != "staging" {
		t.Fatalf("reset body = %v", body)
	}
	h.expectCode(ExitUsage, "strategy", "reset", "--dir", dir)
	h.expectCode(ExitUsage, "strategy", "reset", "--env", "production", "--dir", dir)
}
