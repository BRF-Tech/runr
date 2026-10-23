package runrtest

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Deploy strategies and rollouts. A project starts direct with the API's default options; GET /
// PUT /projects/{project}/deploy-strategy read and change them. A deploy of a blue-green or
// canary project (http trigger) while a deployment is active opens a rollout: its new
// deployment is pending for DeployPolls reads, then "running", and the rollout waits for
// promotion (blue-green), runs its health checks (blue-green auto promotion with a health path)
// or starts its first step (canary). Every read of the rollout (GET …/rollout) counts: after
// RolloutConfig.StepPolls reads in a step, the next read moves the canary to its next step or
// passes a health check. The previous deployment stays active until the promotion.
//
// An environment of the project (environments.go, with Environments on) has the project's
// settings unless it sets its own (GET / PUT …/deploy-strategy with environment; reset drops
// them), and a deploy to it rolls out with them next to the deployment that serves it — the
// rollouts of each environment are its own (GET …/rollout?environment=).

// RolloutConfig controls the rollouts of the fake.
type RolloutConfig struct {
	// StepPolls is how many reads of a rollout a canary step or a health check lasts.
	StepPolls int
	// Tracing makes the projects report tracing: canary rollouts carry metrics and their
	// error rate threshold is checked against ErrorRate.
	Tracing bool
	// ErrorRate is the error rate in percent the canary metrics report; above the threshold the
	// canary rolls back at its next step.
	ErrorRate float64
	// FailTraffic answers promote, abort and rollback with 502 rollout_traffic_failed.
	FailTraffic bool
}

type rolloutState struct {
	cfg RolloutConfig
	// strategies are the stored settings by project id (DefaultDeployStrategy until changed).
	strategies map[string]runr.DeployStrategySettings
	// envOwn is what an environment sets of its own over its project's settings, by environment id.
	envOwn map[string]map[string]any
	items  []*fakeRollout
}

type fakeRollout struct {
	data    runr.Rollout
	project *project
	// env is the environment whose workload it rolls out (nil: the project's own)
	env       *fakeEnvironment
	candidate *deployment
	stable    *deployment
	bg        runr.BlueGreenOptions
	canary    runr.CanaryOptions
	// reads counts the reads in the current step or health check.
	reads int
}

func (s *Server) rolloutSt() *rolloutState {
	return featureState(s, "rollouts", func() *rolloutState {
		return &rolloutState{cfg: RolloutConfig{StepPolls: 1, ErrorRate: 0.4}, strategies: map[string]runr.DeployStrategySettings{},
			envOwn: map[string]map[string]any{}}
	})
}

// DefaultDeployStrategy returns the settings a project starts with: direct, with the API's
// default options of both strategies.
func DefaultDeployStrategy() runr.DeployStrategySettings {
	rate := 5.0
	return runr.DeployStrategySettings{
		Strategy:  runr.StrategyDirect,
		BlueGreen: runr.BlueGreenOptions{HealthChecks: 3, KeepPreviousMinutes: 15},
		Canary:    runr.CanaryOptions{Steps: []int{10, 50, 100}, IntervalMinutes: 5, ErrorRateThreshold: &rate},
	}
}

// Rollouts returns the settings of the fake's rollouts; change them before the deploy.
func (s *Server) Rollouts() *RolloutConfig {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.rolloutSt().cfg
}

// SeedDeployStrategy stores the deploy strategy of a project (start from DefaultDeployStrategy).
func (s *Server) SeedDeployStrategy(projectName string, settings runr.DeployStrategySettings) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	s.rolloutSt().strategies[p.data.ID] = copySettings(settings)
}

// DeployStrategy returns the stored deploy strategy of a project.
func (s *Server) DeployStrategy(projectName string) runr.DeployStrategySettings {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	return s.strategyOf(p)
}

// Rollout returns the newest rollout of a project.
func (s *Server) Rollout(projectName string) (runr.Rollout, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return runr.Rollout{}, false
	}
	r := s.newestRollout(p)
	if r == nil {
		return runr.Rollout{}, false
	}
	return r.resource(), true
}

func copySettings(in runr.DeployStrategySettings) runr.DeployStrategySettings {
	out := in
	if in.BlueGreen.HealthPath != nil {
		v := *in.BlueGreen.HealthPath
		out.BlueGreen.HealthPath = &v
	}
	out.Canary.Steps = append([]int{}, in.Canary.Steps...)
	if in.Canary.ErrorRateThreshold != nil {
		v := *in.Canary.ErrorRateThreshold
		out.Canary.ErrorRateThreshold = &v
	}
	if in.Canary.P95ThresholdMs != nil {
		v := *in.Canary.P95ThresholdMs
		out.Canary.P95ThresholdMs = &v
	}
	return out
}

// strategyOf returns a copy of the project's settings.
func (s *Server) strategyOf(p *project) runr.DeployStrategySettings {
	if set, ok := s.rolloutSt().strategies[p.data.ID]; ok {
		return copySettings(set)
	}
	return DefaultDeployStrategy()
}

func projectTrigger(p *project) string {
	if t, _ := p.config["trigger_type"].(string); t != "" {
		return t
	}
	return runr.TriggerHTTP
}

// strategyEligible reports whether a trigger type can use a deploy strategy.
func strategyEligible(trigger string) bool {
	return trigger == runr.TriggerHTTP || trigger == "workflow"
}

func (s *Server) deployStrategyResource(p *project, env *fakeEnvironment) runr.DeployStrategy {
	defaults := DefaultDeployStrategy()
	trigger := projectTrigger(p)
	out := runr.DeployStrategy{DeployStrategySettings: s.strategyIn(p, env), Eligible: strategyEligible(trigger),
		TriggerType: trigger, Tracing: s.rolloutSt().cfg.Tracing, Defaults: &defaults}
	if !s.Environments {
		return out
	}
	out.Environment = runr.PrimaryEnvironment
	if envKey(env) != "" {
		own := s.rolloutSt().envOwn[env.data.ID]
		out.Environment, out.Inherited, out.Project = env.data.Name, len(own) == 0, s.strategyOf(p).Strategy
		out.Own = map[string]any{}
		for k, v := range own {
			out.Own[k] = v
		}
	}
	return out
}

// strategyIn returns the settings a deploy to env rolls out with (nil: the project's own
// workload): the project's, with what the environment sets of its own over them.
func (s *Server) strategyIn(p *project, env *fakeEnvironment) runr.DeployStrategySettings {
	cur := s.strategyOf(p)
	if envKey(env) == "" {
		return cur
	}
	if own := s.rolloutSt().envOwn[env.data.ID]; len(own) > 0 {
		if out, err := applyStrategy(p, cur, own); err == nil {
			return out
		}
	}
	return cur
}

// strategyEnvironment resolves the environment a deploy-strategy or rollout request names (nil:
// the project's own workload); an API before environments ignores it.
func (s *Server) strategyEnvironment(p *project, ref string) (*fakeEnvironment, *apiErr) {
	if ref == "" || !s.Environments {
		return nil, nil
	}
	e, err := s.environmentRef(p, ref)
	if err != nil || e.data.IsPrimary {
		return nil, err
	}
	return e, nil
}

// updateEnvironmentStrategy validates a partial update like the project's and merges it into what
// the environment sets of its own; reset drops that.
func (s *Server) updateEnvironmentStrategy(p *project, env *fakeEnvironment, body map[string]any) *apiErr {
	st := s.rolloutSt()
	if reset, _ := body["reset"].(bool); reset {
		delete(st.envOwn, env.data.ID)
		return nil
	}
	if _, err := applyStrategy(p, s.strategyIn(p, env), body); err != nil {
		return err
	}
	own := st.envOwn[env.data.ID]
	if own == nil {
		own = map[string]any{}
	}
	if raw, ok := body["strategy"].(string); ok {
		if name, valid := runr.NormalizeStrategy(raw); valid {
			own["strategy"] = name
		}
	}
	for _, group := range []string{"blue_green", "canary"} {
		patch, ok := body[group].(map[string]any)
		if !ok {
			continue
		}
		set, _ := own[group].(map[string]any)
		if set == nil {
			set = map[string]any{}
		}
		for k, v := range patch {
			set[k] = v
		}
		own[group] = set
	}
	if len(own) > 0 {
		st.envOwn[env.data.ID] = own
	}
	return nil
}

// intIn reads a whole JSON number between min and max.
func intIn(v any, min, max int) (int, bool) {
	f, ok := v.(float64)
	if !ok || f != float64(int(f)) || int(f) < min || int(f) > max {
		return 0, false
	}
	return int(f), true
}

// canarySteps reads canary steps: strictly increasing percentages of 1-100, or a message.
func canarySteps(raw []any) ([]int, string) {
	steps := make([]int, 0, len(raw))
	for i, x := range raw {
		n, valid := intIn(x, 1, 100)
		if !valid {
			return nil, "Each step must be a percentage between 1 and 100."
		}
		if i > 0 && n <= steps[i-1] {
			return nil, "The steps must increase strictly."
		}
		steps = append(steps, n)
	}
	return steps, ""
}

// updateDeployStrategy validates a partial update like DeployStrategies::validate and merges it.
func (s *Server) updateDeployStrategy(p *project, body map[string]any) *apiErr {
	cur, err := applyStrategy(p, s.strategyOf(p), body)
	if err != nil {
		return err
	}
	s.rolloutSt().strategies[p.data.ID] = cur
	return nil
}

// applyStrategy validates a partial update and returns cur with it applied.
func applyStrategy(p *project, cur runr.DeployStrategySettings, body map[string]any) (runr.DeployStrategySettings, *apiErr) {
	errs := map[string][]string{}
	add := func(field, msg string) { errs[field] = append(errs[field], msg) }
	if raw, ok := body["strategy"]; ok && raw != nil {
		str, _ := raw.(string)
		name, valid := runr.NormalizeStrategy(str)
		trigger := projectTrigger(p)
		switch {
		case !valid:
			add("strategy", "The selected strategy is invalid.")
		case name != runr.StrategyDirect && !strategyEligible(trigger):
			add("strategy", "A deploy strategy needs an http or workflow trigger; this project has a "+trigger+" trigger.")
		default:
			cur.Strategy = name
		}
	}
	if bg, ok := body["blue_green"].(map[string]any); ok {
		if v, ok := bg["auto_promote"]; ok {
			if b, isBool := v.(bool); isBool {
				cur.BlueGreen.AutoPromote = b
			} else {
				add("blue_green.auto_promote", "The blue green.auto promote field must be true or false.")
			}
		}
		if v, ok := bg["health_path"]; ok {
			switch t := v.(type) {
			case nil:
				cur.BlueGreen.HealthPath = nil
			case string:
				if !strings.HasPrefix(t, "/") || strings.ContainsAny(t, " \t\r\n") || len(t) > 255 {
					add("blue_green.health_path", "The health path must start with / and contain no spaces.")
				} else {
					cur.BlueGreen.HealthPath = &t
				}
			default:
				add("blue_green.health_path", "The blue green.health path field must be a string.")
			}
		}
		if v, ok := bg["health_checks"]; ok {
			if n, valid := intIn(v, 1, 20); valid {
				cur.BlueGreen.HealthChecks = n
			} else {
				add("blue_green.health_checks", "The blue green.health checks field must be between 1 and 20.")
			}
		}
		if v, ok := bg["keep_previous_minutes"]; ok {
			if n, valid := intIn(v, 0, 1440); valid {
				cur.BlueGreen.KeepPreviousMinutes = n
			} else {
				add("blue_green.keep_previous_minutes", "The blue green.keep previous minutes field must be between 0 and 1440.")
			}
		}
	}
	if c, ok := body["canary"].(map[string]any); ok {
		if v, ok := c["steps"]; ok {
			raw, isList := v.([]any)
			if !isList || len(raw) < 1 || len(raw) > 10 {
				add("canary.steps", "The canary.steps field must have between 1 and 10 items.")
			} else if steps, msg := canarySteps(raw); msg != "" {
				add("canary.steps", msg)
			} else {
				cur.Canary.Steps = steps
			}
		}
		if v, ok := c["interval_minutes"]; ok {
			if n, valid := intIn(v, 1, 1440); valid {
				cur.Canary.IntervalMinutes = n
			} else {
				add("canary.interval_minutes", "The canary.interval minutes field must be between 1 and 1440.")
			}
		}
		if v, ok := c["error_rate_threshold"]; ok {
			switch t := v.(type) {
			case nil:
				cur.Canary.ErrorRateThreshold = nil
			case float64:
				if t < 0 || t > 100 {
					add("canary.error_rate_threshold", "The canary.error rate threshold field must be between 0 and 100.")
				} else {
					cur.Canary.ErrorRateThreshold = &t
				}
			default:
				add("canary.error_rate_threshold", "The canary.error rate threshold field must be a number.")
			}
		}
		if v, ok := c["p95_threshold_ms"]; ok {
			if v == nil {
				cur.Canary.P95ThresholdMs = nil
			} else if n, valid := intIn(v, 1, 600000); valid {
				cur.Canary.P95ThresholdMs = &n
			} else {
				add("canary.p95_threshold_ms", "The canary.p95 threshold ms field must be between 1 and 600000.")
			}
		}
	}
	if len(errs) > 0 {
		keys := make([]string, 0, len(errs))
		for k := range errs {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		return cur, &apiErr{status: 422, message: errs[keys[0]][0], errors: errs}
	}
	return cur, nil
}

// ---------------------------------------------------------------------------------------
// Rollouts

// rolloutDeploy opens a rollout for a deploy (POST /deploy) to env (nil: the project's own
// workload) when its strategy (or the deploy's strategy field) is blue-green or canary and a
// deployment of env is active; nil deploys directly. Any deploy ends the rollout of env that is
// open: it is replaced.
func (s *Server) rolloutDeploy(p *project, v *version, body map[string]any, env *fakeEnvironment) (*deployment, *apiErr) {
	set := s.strategyIn(p, env)
	name := set.Strategy
	if raw, ok := body["strategy"]; ok && raw != nil {
		str, _ := raw.(string)
		n, valid := runr.NormalizeStrategy(str)
		if !valid {
			return nil, errValidation("strategy", "The selected strategy is invalid.")
		}
		name = n
	}
	for _, r := range s.rolloutSt().items {
		if r.project != p || envKey(r.env) != envKey(env) {
			continue
		}
		if r.data.Open {
			s.abortRollout(r, "system", "Replaced by a newer deploy.")
		} else if r.keepsPrevious() {
			s.releasePrevious(r)
		}
	}
	if name == runr.StrategyDirect || !strategyEligible(projectTrigger(p)) {
		return nil, nil
	}
	var stable *deployment
	for i := len(p.deployments) - 1; i >= 0 && stable == nil; i-- {
		if d := p.deployments[i]; d.data.Status == runr.DeploymentActive && d.data.EnvironmentID == envKey(env) {
			stable = d
		}
	}
	if stable == nil {
		return nil, nil // first deploy, nothing served: direct
	}
	cand := s.newDeployment(p, v, env)
	r := &fakeRollout{project: p, env: env, candidate: cand, stable: stable, bg: set.BlueGreen, canary: set.Canary}
	tag := "green"
	opts, _ := json.Marshal(set.BlueGreen)
	if name == runr.StrategyCanary {
		tag = "canary"
		opts, _ = json.Marshal(set.Canary)
	}
	now := nowRFC3339()
	r.data = runr.Rollout{ID: s.newID(), ProjectID: p.data.ID, DeploymentID: cand.data.ID, StableDeploymentID: stable.data.ID,
		Strategy: name, Status: runr.RolloutPending, Open: true, Traffic: runr.RolloutTraffic{Candidate: 0, Stable: 100},
		Revision: revisionName(workloadSlug(p, env), cand), StableRevision: revisionName(workloadSlug(p, env), stable), Tag: tag,
		TestURL: "https://" + tag + "-" + s.rolloutHost(r), Options: opts, CreatedBy: s.User.Username,
		CreatedAt: now, UpdatedAt: now}
	if s.Environments {
		r.data.Environment, r.data.EnvironmentID = runr.PrimaryEnvironment, envKey(env)
		if envKey(env) != "" {
			r.data.Environment = env.data.Name
		}
	}
	r.event("created", fmt.Sprintf("%s rollout of %s started", runr.StrategyLabel(name), v.data.Label), s.User.Username)
	s.rolloutSt().items = append(s.rolloutSt().items, r)
	return cand, nil
}

func revisionName(slug string, d *deployment) string {
	label := "v0"
	if d.data.Version != nil {
		label = d.data.Version.Name
	}
	return slug + "-" + label + "-" + strings.ToLower(d.data.ID[len(d.data.ID)-6:])
}

// workloadSlug is the name of the workload of env (nil: the project's own).
func workloadSlug(p *project, env *fakeEnvironment) string {
	if envKey(env) != "" {
		return env.data.WorkloadName
	}
	return p.data.Slug
}

func (s *Server) functionHost(p *project) string {
	return p.data.Slug + "." + s.regionName(p) + ".runr.test"
}

// rolloutHost is the host of the workload a rollout moves the traffic of.
func (s *Server) rolloutHost(r *fakeRollout) string {
	return workloadSlug(r.project, r.env) + "." + s.regionName(r.project) + ".runr.test"
}

func (r *fakeRollout) event(typ, msg, actor string) {
	now := nowRFC3339()
	r.data.Events = append(r.data.Events, runr.RolloutEvent{At: now, Type: typ, Message: msg, Traffic: r.data.Traffic.Candidate, Actor: actor})
	r.data.UpdatedAt = now
}

// keepsPrevious reports whether a promoted rollout still keeps the previous revision warm.
func (r *fakeRollout) keepsPrevious() bool {
	return r.data.Status == runr.RolloutPromoted && r.data.ReleasedAt == "" && r.stable.data.Status == runr.DeploymentRunning
}

// resource is the RolloutResource of the rollout (a copy).
func (r *fakeRollout) resource() runr.Rollout {
	out := r.data
	out.Can = runr.RolloutActions{
		Promote:  r.data.Status == runr.RolloutProgressing || r.data.Status == runr.RolloutWaitingPromotion,
		Abort:    r.data.Open,
		Rollback: r.keepsPrevious(),
	}
	out.Events = append([]runr.RolloutEvent{}, r.data.Events...)
	if r.data.Step != nil {
		st := *r.data.Step
		out.Step = &st
	}
	if r.data.Health != nil {
		h := *r.data.Health
		out.Health = &h
	}
	if r.data.Metrics != nil {
		m := *r.data.Metrics
		out.Metrics = &m
	}
	return out
}

// newestRollout returns the newest rollout of the project's own workload.
func (s *Server) newestRollout(p *project) *fakeRollout {
	return s.newestRolloutIn(p, nil)
}

// newestRolloutIn returns the newest rollout of env (nil: the project's own workload).
func (s *Server) newestRolloutIn(p *project, env *fakeEnvironment) *fakeRollout {
	items := s.rolloutSt().items
	for i := len(items) - 1; i >= 0; i-- {
		if items[i].project == p && envKey(items[i].env) == envKey(env) {
			return items[i]
		}
	}
	return nil
}

// deploymentResource is the DeploymentResource of d: its rollout when it is a rollout's new
// deployment, and its part in the project's current rollout.
func (s *Server) deploymentResource(p *project, d *deployment) runr.Deployment {
	out := d.data
	out.Rollout, out.RolloutRole = nil, ""
	var current *fakeRollout
	for _, r := range s.rolloutSt().items {
		// the rollouts of the deployment's own environment
		if r.project != p || envKey(r.env) != d.data.EnvironmentID {
			continue
		}
		if r.candidate == d {
			res := r.resource()
			out.Rollout = &res
		}
		if r.data.Open || r.keepsPrevious() {
			current = r
		}
	}
	if current != nil {
		switch d {
		case current.candidate:
			out.RolloutRole = runr.RolloutRoleCandidate
		case current.stable:
			out.RolloutRole = runr.RolloutRoleStable
			if !current.data.Open {
				out.RolloutRole = runr.RolloutRolePrevious
			}
		}
	}
	return out
}

// finishCandidate ends the pending phase of a rollout's new deployment (finishDeployment):
// failed, it fails the rollout; ready, it runs next to the previous deployment.
func (s *Server) finishCandidate(p *project, d *deployment) bool {
	var r *fakeRollout
	for _, x := range s.rolloutSt().items {
		if x.candidate == d && x.data.Status == runr.RolloutPending {
			r = x
		}
	}
	if r == nil {
		return false
	}
	now := time.Now().UTC()
	if d.fails {
		f := false
		d.data.Status = runr.DeploymentFailed
		d.data.KnativeReady = &f
		d.data.KnativeMessage = d.failMsg
		s.endRollout(r, runr.RolloutFailed, "The new revision did not become ready: "+d.failMsg)
		r.event("failed", r.data.Reason, "system")
		return true
	}
	t := true
	d.data.Status = runr.DeploymentRunning
	d.data.KnativeReady = &t
	d.data.ReadyAt = &now
	r.event("ready", "The new revision is ready", "system")
	switch {
	case r.data.Strategy == runr.StrategyCanary:
		r.data.Status = runr.RolloutProgressing
		s.canaryStep(r, 0)
	case r.bg.AutoPromote && r.bg.HealthPath != nil:
		r.data.Status = runr.RolloutProgressing
		r.data.Health = &runr.RolloutHealth{Path: *r.bg.HealthPath, Required: r.bg.HealthChecks,
			DeadlineAt: now.Add(5 * time.Minute).Format(time.RFC3339)}
		r.data.NextCheckAt = now.Add(10 * time.Second).Format(time.RFC3339)
	case r.bg.AutoPromote:
		s.promoteRollout(r, "system")
	default:
		r.data.Status = runr.RolloutWaitingPromotion
	}
	return true
}

// canaryStep moves a canary to step i; a step of 100 % promotes it.
func (s *Server) canaryStep(r *fakeRollout, i int) {
	steps := r.canary.Steps
	if i >= len(steps) || steps[i] >= 100 {
		s.promoteRollout(r, "system")
		return
	}
	now := time.Now().UTC()
	pct := steps[i]
	r.data.Traffic = runr.RolloutTraffic{Candidate: pct, Stable: 100 - pct}
	step := &runr.RolloutStep{Index: i, Count: len(steps), Percent: pct, StartedAt: now.Format(time.RFC3339),
		EndsAt: now.Add(time.Duration(r.canary.IntervalMinutes) * time.Minute).Format(time.RFC3339)}
	if i+1 < len(steps) {
		next := steps[i+1]
		step.NextPercent = &next
	}
	r.data.Step = step
	r.data.NextCheckAt = step.EndsAt
	// With tracing the new revision's span metrics; without it no source answers (the region's
	// Prometheus has no Knative request metrics here): the canary moves on time only.
	cfg := s.rolloutSt().cfg
	m := &runr.RolloutMetrics{Tracing: cfg.Tracing, Revision: r.data.Revision, MeasuredAt: now.Format(time.RFC3339)}
	if cfg.Tracing {
		rate, p95 := runr.FlexFloat(cfg.ErrorRate), runr.FlexFloat(120.5)
		m.Source, m.ErrorRate, m.P95Ms, m.Volume, m.WindowMinutes = runr.MetricsFromSpans, &rate, &p95, 312, 3
	}
	if t := r.canary.ErrorRateThreshold; t != nil {
		v := runr.FlexFloat(*t)
		m.ErrorRateThreshold = &v
	}
	if t := r.canary.P95ThresholdMs; t != nil {
		v := runr.FlexFloat(*t)
		m.P95ThresholdMs = &v
	}
	r.data.Metrics = m
	r.event("step", fmt.Sprintf("%d %% of the traffic on the new revision", pct), "system")
}

// advanceRollout takes a rollout one read further (GET …/rollout).
func (s *Server) advanceRollout(r *fakeRollout) {
	switch r.data.Status {
	case runr.RolloutPending:
		s.pollDeployment(r.project, r.candidate)
		return
	case runr.RolloutProgressing:
	default:
		return
	}
	cfg := s.rolloutSt().cfg
	if r.reads < max(cfg.StepPolls, 1) {
		r.reads++
		return
	}
	r.reads = 1
	if r.data.Strategy == runr.StrategyCanary {
		if t := r.canary.ErrorRateThreshold; cfg.Tracing && t != nil && cfg.ErrorRate > *t {
			s.rollBackRollout(r, "system", fmt.Sprintf("Error rate %.1f %% is over the %.1f %% threshold", cfg.ErrorRate, *t))
			return
		}
		s.canaryStep(r, r.data.Step.Index+1)
		return
	}
	h := r.data.Health
	if h == nil {
		return
	}
	ok := 200
	h.Passes++
	h.LastStatus = &ok
	h.CheckedAt = nowRFC3339()
	r.event("health", fmt.Sprintf("Health check %d of %d passed (%s answered 200)", h.Passes, h.Required, h.Path), "system")
	if h.Passes >= h.Required {
		s.promoteRollout(r, "system")
	}
}

// endRollout closes a rollout with a final status other than promoted: the traffic is on the
// previous revision.
func (s *Server) endRollout(r *fakeRollout, status, reason string) {
	r.data.Status = status
	r.data.Open = false
	r.data.Traffic = runr.RolloutTraffic{Candidate: 0, Stable: 100}
	r.data.TestURL = ""
	r.data.PreviousURL = ""
	r.data.NextCheckAt = ""
	r.data.Reason = reason
	r.data.FinishedAt = nowRFC3339()
}

func (s *Server) promoteRollout(r *fakeRollout, actor string) {
	now := time.Now().UTC()
	r.candidate.data.Status = runr.DeploymentActive
	if r.data.Strategy == runr.StrategyBlueGreen && r.bg.KeepPreviousMinutes > 0 {
		r.stable.data.Status = runr.DeploymentRunning // kept warm for an instant rollback
		r.data.KeepUntil = now.Add(time.Duration(r.bg.KeepPreviousMinutes) * time.Minute).Format(time.RFC3339)
		r.data.PreviousURL = "https://previous-" + s.rolloutHost(r)
	} else {
		r.stable.data.Status = runr.DeploymentShutDown
		r.data.FinishedAt = now.Format(time.RFC3339)
	}
	r.data.Status = runr.RolloutPromoted
	r.data.Open = false
	r.data.Traffic = runr.RolloutTraffic{Candidate: 100, Stable: 0}
	r.data.TestURL = ""
	r.data.NextCheckAt = ""
	r.data.PromotedAt = now.Format(time.RFC3339)
	r.event("promoted", "The new revision has all the traffic", actor)
}

func (s *Server) abortRollout(r *fakeRollout, actor, reason string) {
	if reason == "" {
		reason = "Aborted by " + actor
	}
	r.candidate.data.Status = runr.DeploymentShuttingDown
	s.endRollout(r, runr.RolloutAborted, reason)
	r.event("aborted", reason, actor)
}

func (s *Server) rollBackRollout(r *fakeRollout, actor, reason string) {
	r.stable.data.Status = runr.DeploymentActive
	r.candidate.data.Status = runr.DeploymentShuttingDown
	r.data.KeepUntil = ""
	s.endRollout(r, runr.RolloutRolledBack, reason)
	r.event("rolled_back", reason, actor)
}

// releasePrevious shuts the previous revision of a promoted rollout down (keep_until passed or
// a newer deploy).
func (s *Server) releasePrevious(r *fakeRollout) {
	r.stable.data.Status = runr.DeploymentShutDown
	r.data.ReleasedAt = nowRFC3339()
	r.data.FinishedAt = r.data.ReleasedAt
	r.data.PreviousURL = ""
	r.event("released", "The previous revision was released", "system")
}

// rolloutOfRequest resolves the {deployment} rollout of a …/deployments/{deployment}/rollout request.
func (s *Server) rolloutOfRequest(q *featureReq) (*fakeRollout, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return nil, err
	}
	for _, r := range s.rolloutSt().items {
		if r.project == p && r.candidate.data.ID == q.Segs[3] {
			return r, nil
		}
	}
	if s.deploymentByID(p, q.Segs[3]) == nil {
		return nil, errNotFound()
	}
	return nil, &apiErr{status: 404, message: "This deployment is not the new deployment of a rollout."}
}

func errTrafficFailed() *apiErr {
	return &apiErr{status: 502, code: "rollout_traffic_failed",
		message: "The cluster refused the traffic change: the service could not be updated."}
}

func init() {
	handle("GET /projects/{project}/deploy-strategy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		env, err := s.strategyEnvironment(p, q.query("environment"))
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.deployStrategyResource(p, env)}, nil
	})
	handle("PUT /projects/{project}/deploy-strategy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		env, err := s.strategyEnvironment(p, q.str("environment"))
		if err != nil {
			return 0, nil, err
		}
		if env != nil {
			err = s.updateEnvironmentStrategy(p, env, q.Body)
		} else {
			err = s.updateDeployStrategy(p, q.Body)
		}
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.deployStrategyResource(p, env)}, nil
	})
	handle("GET /projects/{project}/rollout", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		env, err := s.strategyEnvironment(p, q.query("environment"))
		if err != nil {
			return 0, nil, err
		}
		r := s.newestRolloutIn(p, env)
		if r == nil {
			return 200, map[string]any{"data": nil}, nil
		}
		s.advanceRollout(r)
		return 200, map[string]any{"data": r.resource()}, nil
	})
	handle("GET /projects/{project}/deployments/{deployment}/rollout", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := s.rolloutOfRequest(q)
		if err != nil {
			return 0, nil, err
		}
		s.advanceRollout(r)
		return 200, map[string]any{"data": r.resource()}, nil
	})
	handle("POST /projects/{project}/deployments/{deployment}/rollout/promote", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := s.rolloutOfRequest(q)
		if err != nil {
			return 0, nil, err
		}
		if r.data.Status != runr.RolloutProgressing && r.data.Status != runr.RolloutWaitingPromotion {
			return 0, nil, &apiErr{status: 409, code: "rollout_not_promotable",
				message: "This rollout cannot be promoted: it is " + r.data.Status + "."}
		}
		if s.rolloutSt().cfg.FailTraffic {
			return 0, nil, errTrafficFailed()
		}
		s.promoteRollout(r, s.User.Username)
		return 200, map[string]any{"data": r.resource()}, nil
	})
	handle("POST /projects/{project}/deployments/{deployment}/rollout/abort", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := s.rolloutOfRequest(q)
		if err != nil {
			return 0, nil, err
		}
		reason := q.str("reason")
		if len([]rune(reason)) > 500 {
			return 0, nil, errValidation("reason", "The reason field must not be greater than 500 characters.")
		}
		if !r.data.Open {
			return 0, nil, &apiErr{status: 409, code: "rollout_not_abortable",
				message: "This rollout cannot be aborted: it is " + r.data.Status + "."}
		}
		if s.rolloutSt().cfg.FailTraffic {
			return 0, nil, errTrafficFailed()
		}
		s.abortRollout(r, s.User.Username, reason)
		return 200, map[string]any{"data": r.resource()}, nil
	})
	handle("POST /projects/{project}/deployments/{deployment}/rollout/rollback", func(s *Server, q *featureReq) (int, any, *apiErr) {
		r, err := s.rolloutOfRequest(q)
		if err != nil {
			return 0, nil, err
		}
		if !r.keepsPrevious() {
			return 0, nil, &apiErr{status: 409, code: "rollout_not_rollbackable",
				message: "This rollout cannot be rolled back: the previous revision is no longer kept."}
		}
		if s.rolloutSt().cfg.FailTraffic {
			return 0, nil, errTrafficFailed()
		}
		s.rollBackRollout(r, s.User.Username, "Rolled back by "+s.User.Username)
		return 200, map[string]any{"data": r.resource()}, nil
	})
}
