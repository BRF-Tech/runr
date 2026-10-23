package runr_test

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestNormalizeStrategy(t *testing.T) {
	for in, want := range map[string]string{
		"direct": runr.StrategyDirect, "blue-green": runr.StrategyBlueGreen, "blue_green": runr.StrategyBlueGreen,
		"bluegreen": runr.StrategyBlueGreen, " Blue-Green ": runr.StrategyBlueGreen, "CANARY": runr.StrategyCanary,
	} {
		if got, ok := runr.NormalizeStrategy(in); !ok || got != want {
			t.Errorf("NormalizeStrategy(%q) = %q, %v; want %q", in, got, ok, want)
		}
	}
	for _, bad := range []string{"", "rolling", "blue green"} {
		if _, ok := runr.NormalizeStrategy(bad); ok {
			t.Errorf("NormalizeStrategy(%q) accepted", bad)
		}
	}
	if runr.StrategyLabel(runr.StrategyBlueGreen) != "blue-green" || runr.StrategyLabel("") != "direct" {
		t.Fatal("strategy labels")
	}
}

// A partial update sends only what is set; Nullable sends null to clear a setting.
func TestDeployStrategyInputJSON(t *testing.T) {
	if !(runr.DeployStrategyInput{}).Empty() || !(runr.DeployStrategyInput{BlueGreen: &runr.BlueGreenInput{}, Canary: &runr.CanaryInput{}}).Empty() {
		t.Fatal("an input without settings is not empty")
	}
	checks := 5
	in := runr.DeployStrategyInput{
		Strategy:  runr.StrategyCanary,
		BlueGreen: &runr.BlueGreenInput{HealthPath: runr.NullOf[string](), HealthChecks: &checks},
		Canary:    &runr.CanaryInput{Steps: []int{5, 100}, ErrorRateThreshold: runr.NullableOf(2.5), P95ThresholdMs: runr.NullOf[int]()},
	}
	if in.Empty() {
		t.Fatal("input reported empty")
	}
	data, err := json.Marshal(in)
	if err != nil {
		t.Fatal(err)
	}
	want := `{"strategy":"canary","blue_green":{"health_path":null,"health_checks":5},"canary":{"steps":[5,100],"error_rate_threshold":2.5,"p95_threshold_ms":null}}`
	if string(data) != want {
		t.Fatalf("body = %s\nwant   %s", data, want)
	}
	var back runr.Nullable[string]
	if err := json.Unmarshal([]byte(`null`), &back); err != nil || !back.Null {
		t.Fatalf("null = %+v, %v", back, err)
	}
	if err := json.Unmarshal([]byte(`"/healthz"`), &back); err != nil || back.Null || back.Value != "/healthz" {
		t.Fatalf("value = %+v, %v", back, err)
	}
}

func TestDeployStrategyGetAndUpdate(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("shop", runr.TypeFaaS, "python", "eu-1", nil)

	s, err := c.GetDeployStrategy(ctx, p.ID)
	if err != nil {
		t.Fatal(err)
	}
	if s.Strategy != runr.StrategyDirect || !s.Eligible || s.TriggerType != runr.TriggerHTTP || s.Tracing {
		t.Fatalf("defaults = %+v", s)
	}
	if s.BlueGreen.HealthChecks != 3 || s.BlueGreen.KeepPreviousMinutes != 15 || s.BlueGreen.HealthPath != nil ||
		len(s.Canary.Steps) != 3 || s.Canary.IntervalMinutes != 5 || s.Canary.ErrorRateThreshold == nil || s.Canary.P95ThresholdMs != nil {
		t.Fatalf("default options = %+v / %+v", s.BlueGreen, s.Canary)
	}
	if s.Defaults == nil || s.Defaults.Strategy != runr.StrategyDirect {
		t.Fatalf("defaults block = %+v", s.Defaults)
	}

	auto := true
	s, err = c.UpdateDeployStrategy(ctx, p.ID, runr.DeployStrategyInput{Strategy: runr.StrategyBlueGreen,
		BlueGreen: &runr.BlueGreenInput{AutoPromote: &auto, HealthPath: runr.NullableOf("/healthz")}})
	if err != nil {
		t.Fatal(err)
	}
	if s.Strategy != runr.StrategyBlueGreen || !s.BlueGreen.AutoPromote || s.BlueGreen.HealthPath == nil || *s.BlueGreen.HealthPath != "/healthz" ||
		s.BlueGreen.HealthChecks != 3 {
		t.Fatalf("after update = %+v", s)
	}
	puts := srv.CallsTo("PUT /projects/{project}/deploy-strategy")
	if len(puts) != 1 {
		t.Fatalf("%d PUTs", len(puts))
	}
	bg := puts[0].Body["blue_green"].(map[string]any)
	if len(bg) != 2 || bg["health_path"] != "/healthz" || puts[0].Body["canary"] != nil {
		t.Fatalf("partial body = %v", puts[0].Body)
	}
	if got, _ := srv.Project("shop"); got.DeployStrategy != runr.StrategyBlueGreen {
		t.Fatalf("project deploy_strategy = %q", got.DeployStrategy)
	}

	// null clears the health path, other settings stay
	s, err = c.UpdateDeployStrategy(ctx, p.ID, runr.DeployStrategyInput{BlueGreen: &runr.BlueGreenInput{HealthPath: runr.NullOf[string]()}})
	if err != nil || s.BlueGreen.HealthPath != nil || !s.BlueGreen.AutoPromote || s.Strategy != runr.StrategyBlueGreen {
		t.Fatalf("cleared = %+v, %v", s, err)
	}

	// Validation: the API's 422 with the field
	_, err = c.UpdateDeployStrategy(ctx, p.ID, runr.DeployStrategyInput{Canary: &runr.CanaryInput{Steps: []int{50, 10}}})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 422 || len(ae.Errors["canary.steps"]) == 0 {
		t.Fatalf("decreasing steps: %v", err)
	}

	// A queue project cannot use a strategy
	q := srv.SeedProject("worker", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "queue", "queue_stream": "jobs"})
	if s, err := c.GetDeployStrategy(ctx, q.ID); err != nil || s.Eligible || s.TriggerType != runr.TriggerQueue {
		t.Fatalf("queue project = %+v, %v", s, err)
	}
	_, err = c.UpdateDeployStrategy(ctx, q.ID, runr.DeployStrategyInput{Strategy: runr.StrategyCanary})
	if !errors.As(err, &ae) || ae.StatusCode != 422 || len(ae.Errors["strategy"]) == 0 {
		t.Fatalf("canary on a queue project: %v", err)
	}
}

// An API without deploy strategies answers 404: ErrUnsupported.
func TestDeployStrategyUnsupported(t *testing.T) {
	old := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(404)
		_, _ = w.Write([]byte(`{"message":"Not found."}`))
	}))
	defer old.Close()
	c, err := runr.New(runr.Options{APIURL: old.URL, Token: "t", HTTPClient: old.Client(), Retries: -1})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	if _, err := c.GetDeployStrategy(ctx, "p"); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("GetDeployStrategy: %v", err)
	}
	if _, err := c.UpdateDeployStrategy(ctx, "p", runr.DeployStrategyInput{Strategy: runr.StrategyCanary}); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("UpdateDeployStrategy: %v", err)
	}
	if _, err := c.ProjectRollout(ctx, "p"); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("ProjectRollout: %v", err)
	}
}

// seedServed creates a served project (v1 active) and saves v2.
func seedServed(t *testing.T, srv *runrtest.Server, name string) (runr.Project, runr.Deployment, runr.Version) {
	t.Helper()
	p := srv.SeedProject(name, runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedFiles(name, map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	d := srv.SeedDeployment(name, 1)
	v := srv.SeedFiles(name, map[string]string{"main.py": "def handler(e, c):\n    return 2\n"})
	return p, d, v
}

func deploymentByID(t *testing.T, srv *runrtest.Server, project, id string) runr.Deployment {
	t.Helper()
	for _, d := range srv.Deployments(project) {
		if d.ID == id {
			return d
		}
	}
	t.Fatalf("deployment %s of %s not found", id, project)
	return runr.Deployment{}
}

func TestBlueGreenRolloutPromoteAndRollback(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p, stable, v := seedServed(t, srv, "shop")
	set := runrtest.DefaultDeployStrategy()
	set.Strategy = runr.StrategyBlueGreen
	srv.SeedDeployStrategy("shop", set)

	if r, err := c.ProjectRollout(ctx, p.ID); err != nil || r != nil {
		t.Fatalf("rollout before any = %+v, %v", r, err)
	}

	start, err := c.StartDeploy(ctx, p.ID, v.ID, nil)
	if err != nil {
		t.Fatal(err)
	}
	d := start.Deployment
	if d.Status != runr.DeploymentPending || d.Rollout == nil || d.Rollout.Status != runr.RolloutPending || !d.Rollout.Open ||
		d.Rollout.Strategy != runr.StrategyBlueGreen || d.RolloutRole != runr.RolloutRoleCandidate {
		t.Fatalf("deploy answer = %+v / %+v", d, d.Rollout)
	}
	if _, sent := srv.CallsTo("POST /projects/{project}/deploy")[0].Body["strategy"]; sent {
		t.Fatal("a deploy without override sent strategy")
	}

	// Ready: running next to the stable deployment, not waiting for the promotion
	d, err = c.WaitDeployment(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	r := d.Rollout
	if d.Status != runr.DeploymentRunning || r.Status != runr.RolloutWaitingPromotion || r.Traffic.Candidate != 0 || r.Traffic.Stable != 100 {
		t.Fatalf("ready = %s / %+v", d.Status, r)
	}
	if r.TestURL != "https://green-shop.eu-1.runr.test" || r.Tag != "green" || r.StableDeploymentID != stable.ID || !r.Can.Promote || !r.Can.Abort || r.Can.Rollback {
		t.Fatalf("rollout = %+v", r)
	}
	if got := deploymentByID(t, srv, "shop", stable.ID); got.Status != runr.DeploymentActive || got.RolloutRole != runr.RolloutRoleStable {
		t.Fatalf("stable = %s / %s", got.Status, got.RolloutRole)
	}
	if len(r.Events) != 2 || r.Events[0].Type != "created" || r.Events[0].Actor != "ada" || r.Events[1].Type != "ready" {
		t.Fatalf("events = %+v", r.Events)
	}

	got, err := c.ProjectRollout(ctx, p.ID)
	if err != nil || got == nil || got.ID != r.ID {
		t.Fatalf("project rollout = %+v, %v", got, err)
	}
	if got, err := c.DeploymentRollout(ctx, p.ID, d.ID); err != nil || got.ID != r.ID {
		t.Fatalf("deployment rollout = %+v, %v", got, err)
	}
	if _, err := c.DeploymentRollout(ctx, p.ID, stable.ID); !runr.IsNotFound(err) {
		t.Fatalf("rollout of the stable deployment: %v", err)
	}

	// Rollback before promotion is refused with the API's code
	_, err = c.RollbackRollout(ctx, p.ID, d.ID)
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 409 || ae.Code != "rollout_not_rollbackable" {
		t.Fatalf("early rollback: %v", err)
	}

	r, err = c.PromoteRollout(ctx, p.ID, d.ID)
	if err != nil {
		t.Fatal(err)
	}
	if r.Status != runr.RolloutPromoted || r.Open || r.Traffic.Candidate != 100 || r.TestURL != "" ||
		r.PreviousURL != "https://previous-shop.eu-1.runr.test" || r.KeepUntil == "" || !r.Can.Rollback || r.Can.Promote {
		t.Fatalf("promoted = %+v", r)
	}
	if got := deploymentByID(t, srv, "shop", d.ID); got.Status != runr.DeploymentActive {
		t.Fatalf("new deployment after promotion = %s", got.Status)
	}
	if got := deploymentByID(t, srv, "shop", stable.ID); got.Status != runr.DeploymentRunning || got.RolloutRole != runr.RolloutRolePrevious {
		t.Fatalf("previous after promotion = %s / %s", got.Status, got.RolloutRole)
	}
	_, err = c.PromoteRollout(ctx, p.ID, d.ID)
	if !errors.As(err, &ae) || ae.StatusCode != 409 || ae.Code != "rollout_not_promotable" {
		t.Fatalf("second promote: %v", err)
	}

	r, err = c.RollbackRollout(ctx, p.ID, d.ID)
	if err != nil || r.Status != runr.RolloutRolledBack || r.Traffic.Stable != 100 || r.Reason == "" {
		t.Fatalf("rolled back = %+v, %v", r, err)
	}
	if got := deploymentByID(t, srv, "shop", stable.ID); got.Status != runr.DeploymentActive {
		t.Fatalf("previous after rollback = %s", got.Status)
	}
	if got := deploymentByID(t, srv, "shop", d.ID); got.Status != runr.DeploymentShuttingDown {
		t.Fatalf("new deployment after rollback = %s", got.Status)
	}
}

func TestCanaryRolloutOverrideWaitAndAbort(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p, _, v := seedServed(t, srv, "api")

	// A direct project deploys as a canary through the deploy's strategy field
	start, err := c.StartDeploy(ctx, p.ID, v.ID, &runr.DeployConfig{Strategy: runr.StrategyCanary})
	if err != nil || start.Deployment.Rollout == nil || start.Deployment.Rollout.Strategy != runr.StrategyCanary {
		t.Fatalf("canary override = %+v, %v", start, err)
	}
	body := srv.CallsTo("POST /projects/{project}/deploy")[0].Body
	if body["strategy"] != "canary" {
		t.Fatalf("deploy body = %v", body)
	}
	if _, sent := body["deploy_config"]; sent {
		t.Fatal("a strategy-only override sent deploy_config")
	}
	d, err := c.WaitDeployment(ctx, p.ID, start.Deployment.ID, runr.WaitOptions{Interval: time.Millisecond})
	if err != nil || d.Rollout.Status != runr.RolloutProgressing || d.Rollout.Traffic.Candidate != 10 {
		t.Fatalf("canary ready = %+v, %v", d.Rollout, err)
	}
	if s := d.Rollout.Step; s == nil || s.Index != 0 || s.Count != 3 || s.Percent != 10 || s.NextPercent == nil || *s.NextPercent != 50 {
		t.Fatalf("step = %+v", d.Rollout.Step)
	}

	// WaitRollout follows the steps until the promotion and reports the new events
	var seen []string
	r, err := c.WaitRollout(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond, Progress: func(s string) { seen = append(seen, s) }})
	if err != nil || r.Status != runr.RolloutPromoted || r.Traffic.Candidate != 100 {
		t.Fatalf("wait = %+v, %v", r, err)
	}
	joined := strings.Join(seen, "\n")
	for _, want := range []string{"progressing, 10 % of the traffic on the new revision", "step: 50 % of the traffic on the new revision",
		"promoted: The new revision has all the traffic", "promoted, 100 % of the traffic on the new revision"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("progress lacks %q:\n%s", want, joined)
		}
	}
	if strings.Contains(joined, "created:") {
		t.Fatalf("progress repeated the history before the wait:\n%s", joined)
	}

	// The next canary is aborted with a reason; a second abort is refused
	v3 := srv.SeedFiles("api", map[string]string{"main.py": "def handler(e, c):\n    return 3\n"})
	start, err = c.StartDeploy(ctx, p.ID, v3.ID, &runr.DeployConfig{Strategy: "canary", MinScale: intp(1)})
	if err != nil {
		t.Fatal(err)
	}
	if body := srv.CallsTo("POST /projects/{project}/deploy")[1].Body; body["deploy_config"] == nil || body["strategy"] != "canary" {
		t.Fatalf("override with config = %v", body)
	}
	r, err = c.AbortRollout(ctx, p.ID, start.Deployment.ID, "5xx on /checkout")
	if err != nil || r.Status != runr.RolloutAborted || r.Reason != "5xx on /checkout" || r.Open {
		t.Fatalf("aborted = %+v, %v", r, err)
	}
	calls := srv.CallsTo("POST /projects/{project}/deployments/{deployment}/rollout/abort")
	if calls[0].Body["reason"] != "5xx on /checkout" {
		t.Fatalf("abort body = %v", calls[0].Body)
	}
	_, err = c.AbortRollout(ctx, p.ID, start.Deployment.ID, "")
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 409 || ae.Code != "rollout_not_abortable" {
		t.Fatalf("second abort: %v", err)
	}
	if calls := srv.CallsTo("POST /projects/{project}/deployments/{deployment}/rollout/abort"); calls[1].Body != nil {
		t.Fatalf("abort without a reason sent %v", calls[1].Body)
	}
}

// A canary over its error rate threshold rolls back: WaitRollout and WaitDeployment report it.
func TestCanaryRollsBackOverThreshold(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p, stable, v := seedServed(t, srv, "api")
	set := runrtest.DefaultDeployStrategy()
	set.Strategy = runr.StrategyCanary
	srv.SeedDeployStrategy("api", set)
	srv.Rollouts().Tracing = true
	srv.Rollouts().ErrorRate = 12

	start, err := c.StartDeploy(ctx, p.ID, v.ID, nil)
	if err != nil {
		t.Fatal(err)
	}
	d, err := c.WaitDeployment(ctx, p.ID, start.Deployment.ID, runr.WaitOptions{Interval: time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}
	if m := d.Rollout.Metrics; m == nil || !m.Tracing || m.ErrorRate == nil || float64(*m.ErrorRate) != 12 || m.ErrorRateThreshold == nil {
		t.Fatalf("metrics = %+v", d.Rollout.Metrics)
	}
	r, err := c.WaitRollout(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond})
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.RolloutRolledBack || !strings.Contains(err.Error(), "rolled back: Error rate 12.0 %") {
		t.Fatalf("wait = %v", err)
	}
	if r == nil || r.Status != runr.RolloutRolledBack || r.Events[len(r.Events)-1].Actor != "system" {
		t.Fatalf("rolled back = %+v", r)
	}
	if got := deploymentByID(t, srv, "api", stable.ID); got.Status != runr.DeploymentActive {
		t.Fatalf("stable after the rollback = %s", got.Status)
	}
}

// A project with a strategy deploys directly with the direct override, and when nothing is
// served (first deploy).
func TestDirectDeploysOfAStrategyProject(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	set := runrtest.DefaultDeployStrategy()
	set.Strategy = runr.StrategyBlueGreen
	srv.SeedDeployStrategy("svc", set)

	d, err := c.Deploy(ctx, p.ID, v.ID, nil)
	if err != nil || d.Rollout != nil {
		t.Fatalf("first deploy = %+v, %v", d, err)
	}
	if d, err = c.WaitDeployment(ctx, p.ID, d.ID, runr.WaitOptions{Interval: time.Millisecond}); err != nil || d.Status != runr.DeploymentActive {
		t.Fatalf("first deploy ready = %+v, %v", d, err)
	}
	v2 := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 2\n"})
	d, err = c.Deploy(ctx, p.ID, v2.ID, &runr.DeployConfig{Strategy: runr.StrategyDirect})
	if err != nil || d.Rollout != nil {
		t.Fatalf("direct override = %+v, %v", d, err)
	}
	if body := srv.CallsTo("POST /projects/{project}/deploy")[1].Body; body["strategy"] != "direct" {
		t.Fatalf("deploy body = %v", body)
	}
	if _, err := c.StartDeploy(ctx, p.ID, v2.ID, &runr.DeployConfig{Strategy: "rolling"}); !runr.IsValidation(err) {
		t.Fatalf("unknown strategy: %v", err)
	}
}
