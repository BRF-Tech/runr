package runrtest

import (
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Environments of a project and promotion (EnvironmentController, PromotionController,
// Promotions). The primary environment "production" stands for the project's own workload: it
// is created on first use, last in the pipeline, and promotions into it require approval. A
// deployment of an environment carries its id; a deploy to one replaces only that
// environment's live deployment. Server.Environments off: the endpoints answer 404 and the
// deploy ignores environment / restore_config, like an API before environments.

type fakeEnvironment struct {
	data      runr.Environment
	overrides map[string]any
	secrets   map[string]string
	// versions counts the writes of each own secret value (EnvironmentSecret::version)
	versions map[string]int
	// ownRegion: the environment has a region of its own (knative_region_id); without one it
	// follows the project's region, which data.RegionID mirrors
	ownRegion bool
	// warm is what the timed warmer last applied to its workload (SetEnvironmentWarmScale)
	warm *runr.WarmScale
}

type fakePromotion struct {
	data        runr.Promotion
	requestedBy string // user id of the requester
	sourceEnv   string // environment id ("" the primary)
	targetEnv   string
	versionID   string
}

type environmentsState struct {
	byProject  map[string][]*fakeEnvironment
	promotions map[string][]*fakePromotion
}

func (s *Server) environmentsState() *environmentsState {
	return featureState(s, "environments", func() *environmentsState {
		return &environmentsState{byProject: map[string][]*fakeEnvironment{}, promotions: map[string][]*fakePromotion{}}
	})
}

var environmentName = regexp.MustCompile(`^[a-z]([-a-z0-9]{0,18}[a-z0-9])?$`)

// pipeline returns the project's environments in pipeline order, the primary created on first use.
func (s *Server) pipeline(p *project) []*fakeEnvironment {
	st := s.environmentsState()
	list := st.byProject[p.data.ID]
	hasPrimary := false
	for _, e := range list {
		hasPrimary = hasPrimary || e.data.IsPrimary
	}
	if !hasPrimary {
		now := nowRFC3339()
		list = append(list, &fakeEnvironment{data: runr.Environment{
			ID: s.newID(), Name: runr.PrimaryEnvironment, IsPrimary: true, WorkloadName: p.data.Slug,
			RegionID: p.data.RegionID, Position: 1000, RequiresApproval: true, AutoDeploy: true, InheritSecrets: true,
			CreatedAt: now, UpdatedAt: now,
		}, overrides: map[string]any{}, secrets: map[string]string{}, versions: map[string]int{}})
		st.byProject[p.data.ID] = list
	}
	// an environment without a region of its own runs in the project's (also after a move)
	for _, e := range list {
		if !e.ownRegion {
			e.data.RegionID = p.data.RegionID
		}
	}
	sort.SliceStable(list, func(i, j int) bool { return list[i].data.Position < list[j].data.Position })
	return list
}

// regionNameOf is the name of a region by id ("region" when it is unknown).
func (s *Server) regionNameOf(id string) string {
	if r := s.regionByID(id); r != nil {
		return r.Name
	}
	return "region"
}

// environmentRef resolves a name or id; the primary for "production".
func (s *Server) environmentRef(p *project, ref string) (*fakeEnvironment, *apiErr) {
	for _, e := range s.pipeline(p) {
		if e.data.Name == strings.ToLower(ref) || e.data.ID == ref {
			return e, nil
		}
	}
	msg := fmt.Sprintf("The project has no environment %q.", ref)
	return nil, &apiErr{status: 422, code: "environment_not_found", message: msg, errors: map[string][]string{"environment": {msg}}}
}

// envKey is the environment id a deployment carries ("" for the primary).
func envKey(e *fakeEnvironment) string {
	if e == nil || e.data.IsPrimary {
		return ""
	}
	return e.data.ID
}

func (s *Server) environmentJSON(p *project, e *fakeEnvironment) runr.Environment {
	out := e.data
	if reg := s.regionByID(out.RegionID); reg != nil {
		out.Region = &runr.Ref{ID: reg.ID, Name: reg.Name}
	}
	// its default URL is on the function domain of the region it runs in
	out.URL = "https://" + out.WorkloadName + "." + s.regionNameOf(out.RegionID) + ".runr.test"
	out.DefaultURL = out.URL
	out.OwnRegion = e.ownRegion && !e.data.IsPrimary
	// how its deploys roll out: the project's strategy unless it sets its own (rollouts.go)
	out.DeployStrategy = runr.StrategyDirect
	if strategyEligible(projectTrigger(p)) {
		out.DeployStrategy = s.strategyIn(p, e).Strategy
	}
	out.Overrides = runr.EnvironmentOverrides{}
	if !e.data.IsPrimary {
		raw := map[string]any{}
		for k, v := range e.overrides {
			raw[k] = v
		}
		// what it sets of its own deploy strategy is kept with its overrides
		if own := s.rolloutSt().envOwn[e.data.ID]; len(own) > 0 {
			raw["deploy_strategy"] = own
		}
		if !s.holds("env.view") {
			if _, ok := raw["env_vars"]; ok {
				raw["env_vars"] = nil
				raw["env_vars_hidden"] = true
			}
		}
		decodeInto(raw, &out.Overrides)
	}
	// its timed warmer: its own schedule (an empty one: none), else the project's
	out.ScaleSchedule, out.ScaleScheduleInherited, out.WarmScale = nil, true, e.warm
	if e.data.IsPrimary {
		out.WarmScale = p.data.WarmScale
	}
	schedule, own := e.overrides["scale_schedule"]
	if own && !e.data.IsPrimary {
		out.ScaleScheduleInherited = false
	} else {
		schedule = p.config["scale_schedule"]
	}
	if rows, _ := schedule.([]any); len(rows) > 0 {
		decodeInto(map[string]any{"rows": rows}, &struct {
			Rows *[]runr.ScaleScheduleRow `json:"rows"`
		}{&out.ScaleSchedule})
	}
	out.Secrets = []runr.EnvironmentSecret{}
	names := make([]string, 0, len(e.secrets))
	for k := range e.secrets {
		names = append(names, k)
	}
	sort.Strings(names)
	for _, n := range names {
		out.Secrets = append(out.Secrets, runr.EnvironmentSecret{Name: n, Scope: "environment", Version: e.versions[n]})
	}
	if d := s.liveDeploymentOf(p, envKey(e)); d != nil {
		ed := &runr.EnvironmentDeployment{ID: d.data.ID, Status: d.data.Status, ConfigSource: d.data.ConfigSource}
		if d.data.Version != nil {
			number := 0
			_, _ = fmt.Sscanf(d.data.VersionLabel, "v%d", &number)
			ed.Version = &struct {
				ID     string `json:"id"`
				Number int    `json:"number"`
				Label  string `json:"label"`
			}{ID: d.data.Version.ID, Number: number, Label: d.data.VersionLabel}
		}
		out.Deployment = ed
	}
	return out
}

// liveDeploymentOf returns an environment's active deployment, else its newest live one.
func (s *Server) liveDeploymentOf(p *project, envID string) *deployment {
	var live *deployment
	for i := len(p.deployments) - 1; i >= 0; i-- {
		d := p.deployments[i]
		if d.data.EnvironmentID != envID || !d.data.Live() {
			continue
		}
		if d.data.Status == runr.DeploymentActive {
			return d
		}
		if live == nil {
			live = d
		}
	}
	return live
}

func (s *Server) environmentsProject(q *featureReq) (*project, *apiErr) {
	if !s.Environments {
		return nil, errNotFound()
	}
	return s.projectOf(q)
}

var (
	environmentBranch = regexp.MustCompile(`^[A-Za-z0-9._/-]{1,255}$`)
	environmentCPU    = regexp.MustCompile(`^(\d+m|\d+(\.\d{1,3})?)$`)
	environmentStream = regexp.MustCompile(`^[a-zA-Z0-9._-]+$`)
)

// environmentOverridable are the fields an environment may override (ProjectEnvironment::OVERRIDABLE);
// deploy_strategy is kept apart (rollouts.go, envOwn).
var environmentOverridable = map[string]bool{
	"env_vars": true, "cpu": true, "memory_mb": true, "timeout_seconds": true, "min_scale": true, "max_scale": true,
	"scale_to_zero": true, "schedule": true, "queue_stream": true, "scale_schedule": true,
}

// applyEnvironmentInput validates and applies a create / update body like EnvironmentController
// and EnvironmentManager: another region only while nothing runs in the environment and it has no
// private database connection; a branch without refs/heads/, deploying one environment; the flags
// and the position (null keeps them); the overrides as a whole, of the known fields only. The
// primary (the project's own workload) takes the flags and the position only.
func (s *Server) applyEnvironmentInput(p *project, e *fakeEnvironment, body map[string]any) *apiErr {
	primary := e.data.IsPrimary
	if raw, ok := body["knative_region_id"]; ok && !primary {
		target := p.data.RegionID
		if v, _ := raw.(string); v != "" && v != p.data.RegionID {
			reg := s.regionByID(v)
			if reg == nil || reg.IsDemo {
				return errValidation("knative_region_id", "That region cannot run environments.")
			}
			target = v
		}
		if target != e.data.RegionID {
			if s.liveDeploymentOf(p, envKey(e)) != nil {
				return errValidation("knative_region_id", "The environment runs a deployment: delete it (or the environment) before moving it to another region.")
			}
			if s.environmentHasConnections(p, e) {
				return errValidation("knative_region_id", "The environment has a gateway route or private database connections in its region: remove them before moving it to another region.")
			}
		}
		e.data.RegionID, e.ownRegion = target, target != p.data.RegionID
	}
	if raw, ok := body["git_branch"]; ok && !primary {
		branch, _ := raw.(string)
		branch = strings.TrimPrefix(strings.TrimSpace(branch), "refs/heads/")
		if branch != "" {
			if !environmentBranch.MatchString(branch) || strings.Contains(branch, "..") {
				return errValidation("git_branch", "That is not a valid branch name.")
			}
			for _, other := range s.pipeline(p) {
				if other != e && other.data.GitBranch == branch {
					return errValidation("git_branch", fmt.Sprintf("Branch %s already deploys environment %s.", branch, other.data.Name))
				}
			}
		}
		e.data.GitBranch = branch
	}
	if raw, ok := body["position"]; ok && raw != nil {
		n, valid := intIn(raw, 0, 2000)
		if !valid {
			return errValidation("position", "The position field must be between 0 and 2000.")
		}
		e.data.Position = n
	}
	for key, dst := range map[string]*bool{"requires_approval": &e.data.RequiresApproval, "auto_deploy": &e.data.AutoDeploy, "inherit_secrets": &e.data.InheritSecrets} {
		if v, ok := body[key].(bool); ok {
			*dst = v
		}
	}
	if raw, ok := body["overrides"]; ok && !primary {
		m, _ := raw.(map[string]any)
		clean := map[string]any{}
		for k, v := range m {
			if environmentOverridable[k] && v != nil {
				clean[k] = v
			}
		}
		if err := environmentOverrides(p, clean); err != nil {
			return err
		}
		vars, sent := clean["env_vars"]
		stored, has := e.overrides["env_vars"]
		switch {
		case !sent && has && !s.holds("env.view"):
			// a caller who cannot see the variables (masked in its answers) and sends none keeps them
			clean["env_vars"] = stored
		case sent && !sameEnvOverrides(vars, stored) && !s.holds("env.edit"):
			// variables sent back unchanged with other overrides need no env.edit
			return &apiErr{status: 403, message: "You do not have permission to do this (requires env.edit).", permissions: []string{"env.edit"}}
		}
		if err := environmentWarmer(p, clean); err != nil {
			return err
		}
		e.overrides = clean
	}
	e.data.UpdatedAt = nowRFC3339()
	return nil
}

// environmentOverrides checks the override fields like EnvironmentController::rules and keeps env
// var values as strings (null: the project's variable is removed there).
func environmentOverrides(p *project, m map[string]any) *apiErr {
	if raw, ok := m["env_vars"]; ok {
		vars, isMap := raw.(map[string]any)
		if !isMap {
			if list, isList := raw.([]any); !isList || len(list) > 0 {
				return errValidation("overrides.env_vars", "The environment variables must be a map of names to values.")
			}
			vars = map[string]any{}
		}
		out := map[string]any{}
		for k, v := range vars {
			if err := runr.ValidateEnvName(k); err != nil {
				return errValidation("overrides.env_vars", err.Error())
			}
			switch x := v.(type) {
			case nil:
				out[k] = nil
			case string:
				out[k] = x
			case float64, bool:
				out[k] = fmt.Sprint(x)
			default:
				return errValidation("overrides.env_vars", "The value of "+k+" must be a string.")
			}
		}
		m["env_vars"] = out
	}
	if v, ok := m["cpu"]; ok {
		if str, _ := v.(string); !environmentCPU.MatchString(str) || strings.Trim(str, "0.m") == "" {
			return errValidation("overrides.cpu", `CPU must be a Kubernetes quantity greater than zero, e.g. "250m" or "0.5".`)
		}
	}
	ints := map[string][2]int{"memory_mb": {64, 1 << 30}, "timeout_seconds": {1, 1 << 30}, "min_scale": {0, 10}, "max_scale": {1, 1000}}
	for key, bounds := range ints {
		if v, ok := m[key]; ok {
			if _, valid := intIn(v, bounds[0], bounds[1]); !valid {
				return errValidation("overrides."+key, fmt.Sprintf("The overrides.%s field must be between %d and %d.", key, bounds[0], bounds[1]))
			}
		}
	}
	if v, ok := m["scale_to_zero"]; ok {
		if _, isBool := v.(bool); !isBool {
			return errValidation("overrides.scale_to_zero", "The overrides.scale to zero field must be true or false.")
		}
	}
	if v, ok := m["schedule"]; ok {
		str, _ := v.(string)
		macro := map[string]bool{"@hourly": true, "@daily": true, "@weekly": true, "@monthly": true, "@yearly": true}[strings.TrimSpace(str)]
		if !macro && len(strings.Fields(str)) != 5 {
			return errValidation("overrides.schedule", "The schedule must be a 5-field cron expression (minute hour day month weekday) or @hourly/@daily/@weekly/@monthly/@yearly; seconds, time zones and L/W/# are not supported.")
		}
	}
	if v, ok := m["queue_stream"]; ok {
		if str, _ := v.(string); !environmentStream.MatchString(str) || len(str) > 255 {
			return errValidation("overrides.queue_stream", "The overrides.queue stream field format is invalid.")
		}
	}
	return nil
}

// sameEnvOverrides reports whether two env var override maps (name → value, nil: removed) are the same.
func sameEnvOverrides(a, b any) bool {
	am, _ := a.(map[string]any)
	bm, _ := b.(map[string]any)
	if len(am) != len(bm) {
		return false
	}
	for k, v := range am {
		w, ok := bm[k]
		if !ok || (v == nil) != (w == nil) || (v != nil && fmt.Sprint(v) != fmt.Sprint(w)) {
			return false
		}
	}
	return true
}

// environmentHasConnections reports whether an environment has private database connections
// (they live in its region).
func (s *Server) environmentHasConnections(p *project, e *fakeEnvironment) bool {
	for _, c := range s.privateConnectionsState().list {
		if c.ProjectID == p.data.ID && c.EnvironmentID == envKey(e) && envKey(e) != "" {
			return true
		}
	}
	return false
}

// environmentNameError is the refusal of a new environment's name (EnvironmentManager::assertName,
// assertWorkloadName), "" when it may be used.
func (s *Server) environmentNameError(p *project, name string) string {
	if !environmentName.MatchString(name) {
		return "An environment name is a lowercase DNS label: letters, digits and hyphens, starting with a letter, at most 20 characters."
	}
	if runr.ReservedEnvironmentName(name) {
		return fmt.Sprintf("%q is reserved: the project's own workload, its previews and its resources use it.", name)
	}
	for _, e := range s.pipeline(p) {
		if e.data.Name == name {
			return fmt.Sprintf("The project already has an environment named %q.", name)
		}
	}
	workload := p.data.Slug + "-" + name
	for _, x := range s.projects {
		if x.data.Slug == workload {
			return "The workload name " + workload + " is already used by another project or environment; choose another name."
		}
		for _, e := range s.environmentsState().byProject[x.data.ID] {
			if e.data.WorkloadName == workload {
				return "The workload name " + workload + " is already used by another project or environment; choose another name."
			}
		}
	}
	return ""
}

// removeEnvironmentEndpoints removes what a deleted environment had in its region: its custom
// domains, its private database connections and its binding attachments.
func (s *Server) removeEnvironmentEndpoints(p *project, e *fakeEnvironment) {
	id := envKey(e)
	if id == "" {
		return
	}
	domains := []*runr.Domain{}
	for _, d := range p.domains {
		if p.domainEnv[d.ID] == id {
			delete(p.domainEnv, d.ID)
			continue
		}
		domains = append(domains, d)
	}
	p.domains = domains
	pc := s.privateConnectionsState()
	conns := []*runr.PrivateConnection{}
	for _, c := range pc.list {
		if c.ProjectID != p.data.ID || c.EnvironmentID != id {
			conns = append(conns, c)
		}
	}
	pc.list = conns
	bs := s.bindSt()
	attachments := []*fakeAttachment{}
	for _, a := range bs.attachments {
		if a.projectID != p.data.ID || a.environmentID != id {
			attachments = append(attachments, a)
		}
	}
	bs.attachments = attachments
}

// Environment returns an environment of a project as the API shows it (with every permission).
func (s *Server) Environment(projectName, environment string) (runr.Environment, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.bearer = "" // helpers see everything
	p := s.projectByName(projectName)
	if p == nil {
		return runr.Environment{}, false
	}
	e, err := s.environmentRef(p, environment)
	if err != nil {
		return runr.Environment{}, false
	}
	return s.environmentJSON(p, e), true
}

// EnvironmentSecrets returns the own secret values of an environment.
func (s *Server) EnvironmentSecrets(projectName, environment string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	e, err := s.environmentRef(p, environment)
	if err != nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range e.secrets {
		out[k] = v
	}
	return out
}

// SeedEnvironment adds an environment to a project as POST …/environments would.
func (s *Server) SeedEnvironment(projectName, name string, requiresApproval bool) runr.Environment {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	e := s.addEnvironment(p, name)
	e.data.RequiresApproval = requiresApproval
	return s.environmentJSON(p, e)
}

func (s *Server) addEnvironment(p *project, name string) *fakeEnvironment {
	st := s.environmentsState()
	maxPos := 0
	for _, e := range s.pipeline(p) {
		if !e.data.IsPrimary && e.data.Position > maxPos {
			maxPos = e.data.Position
		}
	}
	now := nowRFC3339()
	// after the environments added before it, before production (position 1000)
	e := &fakeEnvironment{data: runr.Environment{
		ID: s.newID(), Name: name, WorkloadName: p.data.Slug + "-" + name, RegionID: p.data.RegionID,
		Position: min(maxPos+1, 999), AutoDeploy: true, InheritSecrets: true, CreatedAt: now, UpdatedAt: now,
	}, overrides: map[string]any{}, secrets: map[string]string{}, versions: map[string]int{}}
	st.byProject[p.data.ID] = append(st.byProject[p.data.ID], e)
	return e
}

// SeedEnvironmentDeployment makes a version run (active) in an environment.
func (s *Server) SeedEnvironmentDeployment(projectName, environment string, versionNumber int) runr.Deployment {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	e, err := s.environmentRef(p, environment)
	if err != nil {
		s.t.Fatalf("runrtest: %s", err.message)
	}
	var v *version
	for _, x := range p.versions {
		if x.data.Number == versionNumber {
			v = x
		}
	}
	if v == nil {
		s.t.Fatalf("runrtest: version %d of %q does not exist", versionNumber, projectName)
	}
	d := s.startDeploymentIn(p, v, e)
	d.pollsLeft = 0
	d.fails = false
	s.finishDeployment(p, d)
	return d.data
}

// SeedPromotion records a promotion that waits for approval, requested by another user than
// Server.User (so that User may approve it).
func (s *Server) SeedPromotion(projectName, from, to string) runr.Promotion {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	source, err := s.environmentRef(p, from)
	if err != nil {
		s.t.Fatalf("runrtest: %s", err.message)
	}
	target, err := s.environmentRef(p, to)
	if err != nil {
		s.t.Fatalf("runrtest: %s", err.message)
	}
	live := s.liveDeploymentOf(p, envKey(source))
	if live == nil {
		s.t.Fatalf("runrtest: nothing runs in %s", from)
	}
	pr := s.newPromotion(p, source, target, live, "Grace Hopper", "99")
	pr.data.Status = runr.PromotionPendingApproval
	return s.promotionJSON(pr)
}

// Promotions returns the promotions of a project, oldest first.
func (s *Server) Promotions(projectName string) []runr.Promotion {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	out := []runr.Promotion{}
	for _, pr := range s.environmentsState().promotions[p.data.ID] {
		out = append(out, s.promotionJSON(pr))
	}
	return out
}

func (s *Server) newPromotion(p *project, source, target *fakeEnvironment, live *deployment, byName, byID string) *fakePromotion {
	number := 0
	_, _ = fmt.Sscanf(live.data.VersionLabel, "v%d", &number)
	pr := &fakePromotion{
		data: runr.Promotion{
			ID: s.newID(), ProjectID: p.data.ID, Project: &runr.Ref{ID: p.data.ID, Name: p.data.Name},
			Source: source.data.Name, Target: target.data.Name,
			Version:     &runr.PromotionVersion{ID: live.data.Version.ID, Number: number, Label: live.data.VersionLabel},
			RequestedBy: byName, CreatedAt: nowRFC3339(),
		},
		requestedBy: byID, sourceEnv: envKey(source), targetEnv: envKey(target), versionID: live.data.Version.ID,
	}
	st := s.environmentsState()
	st.promotions[p.data.ID] = append(st.promotions[p.data.ID], pr)
	return pr
}

func (s *Server) promotionJSON(pr *fakePromotion) runr.Promotion {
	out := pr.data
	out.CanApprove = out.Status == runr.PromotionPendingApproval && pr.requestedBy != s.User.ID.String() && s.holds("environments.approve")
	out.CanCancel = out.Status == runr.PromotionPendingApproval && pr.requestedBy == s.User.ID.String()
	return out
}

// deployPromotion starts the promotion's deployment in its target.
func (s *Server) deployPromotion(p *project, pr *fakePromotion) {
	var v *version
	for _, x := range p.versions {
		if x.data.ID == pr.versionID {
			v = x
		}
	}
	if v == nil {
		pr.data.Status = runr.PromotionFailed
		pr.data.StatusMessage = "The version was deleted."
		return
	}
	var target *fakeEnvironment
	for _, e := range s.pipeline(p) {
		if envKey(e) == pr.targetEnv {
			target = e
		}
	}
	// with the target's deploy strategy: a blue-green or canary one opens a rollout (rollouts.go)
	d, _ := s.rolloutDeploy(p, v, map[string]any{}, target)
	if d == nil {
		d = s.startDeploymentIn(p, v, target)
	}
	pr.data.Status = runr.PromotionDeploying
	pr.data.DeploymentID = d.data.ID
}

func (s *Server) findPromotion(p *project, id string) *fakePromotion {
	for _, pr := range s.environmentsState().promotions[p.data.ID] {
		if pr.data.ID == id {
			return pr
		}
	}
	return nil
}

func init() {
	handle("GET /projects/{project}/environments", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		list := []runr.Environment{}
		for _, e := range s.pipeline(p) {
			list = append(list, s.environmentJSON(p, e))
		}
		return 200, map[string]any{"data": list, "abilities": map[string]bool{
			"manage": s.holds("environments.manage"), "promote": s.holds("environments.promote"), "approve": s.holds("environments.approve"),
		}}, nil
	})
	handle("POST /projects/{project}/environments", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		name := strings.ToLower(strings.TrimSpace(q.str("name")))
		if msg := s.environmentNameError(p, name); msg != "" {
			return 0, nil, errValidation("name", msg)
		}
		e := s.addEnvironment(p, name)
		if err := s.applyEnvironmentInput(p, e, q.Body); err != nil {
			st := s.environmentsState()
			st.byProject[p.data.ID] = st.byProject[p.data.ID][:len(st.byProject[p.data.ID])-1]
			return 0, nil, err
		}
		return 201, map[string]any{"data": s.environmentJSON(p, e)}, nil
	})
	handle("GET /projects/{project}/environments/{environment}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		e, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.environmentJSON(p, e)}, nil
	})
	handle("PATCH /projects/{project}/environments/{environment}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		e, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return 0, nil, err
		}
		if err := s.applyEnvironmentInput(p, e, q.Body); err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.environmentJSON(p, e)}, nil
	})
	handle("DELETE /projects/{project}/environments/{environment}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		e, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return 0, nil, err
		}
		if e.data.IsPrimary {
			return 0, nil, errValidation("environment", "The production environment is the project itself: delete the project instead.")
		}
		st := s.environmentsState()
		kept := []*fakeEnvironment{}
		for _, x := range st.byProject[p.data.ID] {
			if x != e {
				kept = append(kept, x)
			}
		}
		st.byProject[p.data.ID] = kept
		deps := []*deployment{}
		for _, d := range p.deployments {
			if d.data.EnvironmentID != e.data.ID {
				deps = append(deps, d)
			}
		}
		p.deployments = deps
		// its rollouts and its deploy strategy go with it
		rs := s.rolloutSt()
		rollouts := []*fakeRollout{}
		for _, r := range rs.items {
			if r.env != e {
				rollouts = append(rollouts, r)
			}
		}
		rs.items = rollouts
		delete(rs.envOwn, e.data.ID)
		// its custom domains and private connections are removed, its bindings detached (the
		// bindings stay); its secrets go with it
		s.removeEnvironmentEndpoints(p, e)
		return 200, map[string]any{"message": "Environment " + e.data.Name + " was deleted.", "incomplete": false}, nil
	})

	handle("GET /projects/{project}/environments/{environment}/secrets", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		e, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return 0, nil, err
		}
		list := []map[string]any{}
		seen := map[string]bool{}
		for name := range e.secrets {
			list = append(list, map[string]any{"name": name, "scope": "environment", "source": "region", "stored_in": "region",
				"version": e.versions[name], "fingerprint": fmt.Sprintf("%012x", len(e.secrets[name])), "updated_at": nowRFC3339()})
			seen[name] = true
		}
		if e.data.IsPrimary || e.data.InheritSecrets {
			for name := range p.secrets {
				if !seen[name] {
					list = append(list, map[string]any{"name": name, "scope": "project", "updated_at": nil})
				}
			}
		}
		sort.Slice(list, func(i, j int) bool { return list[i]["name"].(string) < list[j]["name"].(string) })
		return 200, map[string]any{"data": list, "inherit_secrets": e.data.IsPrimary || e.data.InheritSecrets}, nil
	})
	handle("PUT /projects/{project}/environments/{environment}/secrets/{name}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		e, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return 0, nil, err
		}
		if e.data.IsPrimary {
			return 0, nil, errValidation("environment", "Secrets of the production environment are the project's secret variables.")
		}
		name := q.Segs[5]
		if msg := secretNameError(name, map[string]any{}); msg != "" {
			return 0, nil, errValidation("name", msg)
		}
		value := q.str("value")
		if value == "" {
			return 0, nil, errValidation("value", "The value field is required.")
		}
		// pass-through: the value goes into the cluster of the environment's region
		if reg := s.regionByID(e.data.RegionID); reg == nil || reg.IsDemo {
			return 0, nil, errValidation("value", "Region "+s.regionNameOf(e.data.RegionID)+" has no cluster to keep the value in.")
		}
		_, existed := e.secrets[name]
		e.secrets[name] = value
		e.versions[name]++
		status := 201
		if existed {
			status = 200
		}
		return status, map[string]any{"data": map[string]any{"name": name, "scope": "environment", "source": "region", "stored_in": "region",
			"version": e.versions[name], "fingerprint": fmt.Sprintf("%012x", len(value)), "updated_at": nowRFC3339()}, "requires_deploy": true}, nil
	})
	handle("DELETE /projects/{project}/environments/{environment}/secrets/{name}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		e, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return 0, nil, err
		}
		if _, ok := e.secrets[q.Segs[5]]; !ok {
			return 0, nil, errNotFound()
		}
		delete(e.secrets, q.Segs[5])
		delete(e.versions, q.Segs[5])
		return 200, map[string]any{"requires_deploy": true}, nil
	})

	// Promotion
	pair := func(s *Server, q *featureReq, p *project) (*fakeEnvironment, *fakeEnvironment, *apiErr) {
		source, err := s.environmentRef(p, q.Segs[3])
		if err != nil {
			return nil, nil, err
		}
		to := q.str("to")
		if to == "" {
			to = q.query("to")
		}
		if to != "" {
			target, err := s.environmentRef(p, to)
			return source, target, err
		}
		list := s.pipeline(p)
		for i, e := range list {
			if e == source && i+1 < len(list) {
				return source, list[i+1], nil
			}
		}
		msg := source.data.Name + " is the last environment of the pipeline: name the environment to promote to."
		return nil, nil, &apiErr{status: 422, code: "promotion_not_possible", message: msg, errors: map[string][]string{"to": {msg}}}
	}
	handle("GET /projects/{project}/environments/{environment}/promote", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		source, target, err := pair(s, q, p)
		if err != nil {
			return 0, nil, err
		}
		src := s.liveDeploymentOf(p, envKey(source))
		dst := s.liveDeploymentOf(p, envKey(target))
		side := func(e *fakeEnvironment, d *deployment) map[string]any {
			out := map[string]any{"environment": e.data.Name, "environment_id": e.data.ID, "region_id": e.data.RegionID, "deployment_id": nil, "status": nil}
			if d != nil {
				out["deployment_id"], out["status"] = d.data.ID, d.data.Status
			}
			return out
		}
		ver := func(d *deployment) any {
			if d == nil || d.data.Version == nil {
				return nil
			}
			number := 0
			_, _ = fmt.Sscanf(d.data.VersionLabel, "v%d", &number)
			return map[string]any{"id": d.data.Version.ID, "number": number, "label": d.data.VersionLabel}
		}
		problem := any(nil)
		if src == nil {
			problem = "Nothing runs in " + source.data.Name + ": deploy a version there first."
		}
		config := []map[string]any{}
		if src != nil && dst != nil {
			// The target keeps its own config: what its overrides change against the source's
			if fmt.Sprint(source.overrides["min_scale"]) != fmt.Sprint(target.overrides["min_scale"]) {
				config = append(config, map[string]any{"field": "min_scale", "from": source.overrides["min_scale"], "to": target.overrides["min_scale"]})
			}
		}
		return 200, map[string]any{"data": map[string]any{
			"source": side(source, src), "target": side(target, dst), "version": ver(src),
			"code":                    map[string]any{"from": ver(dst), "to": ver(src), "changes": src != nil && (dst == nil || dst.data.Version.ID != src.data.Version.ID)},
			"config":                  config,
			"environment_differences": []any{},
			"requires_approval":       target.data.RequiresApproval,
			"can_promote":             problem == nil,
			"can_approve":             s.holds("environments.approve"),
			"problem":                 problem,
		}}, nil
	})
	handle("POST /projects/{project}/environments/{environment}/promote", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		source, target, err := pair(s, q, p)
		if err != nil {
			return 0, nil, err
		}
		live := s.liveDeploymentOf(p, envKey(source))
		if live == nil {
			msg := "Nothing runs in " + source.data.Name + ": deploy a version there first."
			return 0, nil, &apiErr{status: 422, code: "promotion_not_possible", message: msg, errors: map[string][]string{"source": {msg}}}
		}
		for _, old := range s.environmentsState().promotions[p.data.ID] {
			if old.targetEnv == envKey(target) && old.data.Status == runr.PromotionPendingApproval {
				old.data.Status = runr.PromotionSuperseded
			}
		}
		pr := s.newPromotion(p, source, target, live, s.User.Name, s.User.ID.String())
		pr.data.Message = q.str("message")
		if target.data.RequiresApproval {
			pr.data.Status = runr.PromotionPendingApproval
		} else {
			pr.data.Status = runr.PromotionApproved
			s.deployPromotion(p, pr)
		}
		return 202, map[string]any{"data": s.promotionJSON(pr)}, nil
	})
	handle("GET /projects/{project}/promotions", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		list := []runr.Promotion{}
		all := s.environmentsState().promotions[p.data.ID]
		for i := len(all) - 1; i >= 0; i-- {
			if st := q.query("status"); st == "" || all[i].data.Status == st {
				list = append(list, s.promotionJSON(all[i]))
			}
		}
		return 200, map[string]any{"data": list}, nil
	})
	decide := func(decision string) featureHandler {
		return func(s *Server, q *featureReq) (int, any, *apiErr) {
			p, err := s.environmentsProject(q)
			if err != nil {
				return 0, nil, err
			}
			pr := s.findPromotion(p, q.Segs[3])
			if pr == nil {
				return 0, nil, errNotFound()
			}
			if decision == "approve" && pr.requestedBy == s.User.ID.String() {
				return 0, nil, &apiErr{status: 403, code: "self_approval", message: "A promotion is approved by someone other than the one who requested it."}
			}
			if pr.data.Status != runr.PromotionPendingApproval {
				return 0, nil, &apiErr{status: 409, code: "promotion_not_open", message: "The promotion is " + pr.data.Status + ": it cannot be changed any more."}
			}
			pr.data.DecidedBy, pr.data.DecidedAt, pr.data.DecisionComment = s.User.Name, nowRFC3339(), q.str("comment")
			switch decision {
			case "approve":
				pr.data.Status = runr.PromotionApproved
				s.deployPromotion(p, pr)
			case "reject":
				pr.data.Status = runr.PromotionRejected
			case "cancel":
				pr.data.Status = runr.PromotionCancelled
				pr.data.DecidedBy, pr.data.DecidedAt = "", ""
			}
			return 200, map[string]any{"data": s.promotionJSON(pr)}, nil
		}
	}
	handle("POST /projects/{project}/promotions/{promotion}/approve", decide("approve"))
	handle("POST /projects/{project}/promotions/{promotion}/reject", decide("reject"))
	handle("POST /projects/{project}/promotions/{promotion}/cancel", decide("cancel"))
	handle("GET /environment-promotions", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if !s.Environments {
			return 0, nil, errNotFound()
		}
		status := q.query("status")
		if status == "" {
			status = runr.PromotionPendingApproval
		}
		list := []runr.Promotion{}
		for _, p := range s.projects {
			for _, pr := range s.environmentsState().promotions[p.data.ID] {
				if status == "all" || pr.data.Status == status {
					list = append(list, s.promotionJSON(pr))
				}
			}
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("POST /projects/{project}/deployments/{deployment}/rollback", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.environmentsProject(q)
		if err != nil {
			return 0, nil, err
		}
		from := s.deploymentByID(p, q.Segs[3])
		if from == nil || from.data.Version == nil {
			return 0, nil, errNotFound()
		}
		var v *version
		for _, x := range p.versions {
			if x.data.ID == from.data.Version.ID {
				v = x
			}
		}
		if v == nil {
			return 0, nil, &apiErr{status: 422, message: "The version of that deployment no longer exists."}
		}
		var env *fakeEnvironment
		for _, e := range s.pipeline(p) {
			if envKey(e) == from.data.EnvironmentID {
				env = e
			}
		}
		// with the environment's deploy strategy (or the strategy of the request)
		d, aerr := s.rolloutDeploy(p, v, q.Body, env)
		if aerr != nil {
			return 0, nil, aerr
		}
		if d == nil {
			d = s.startDeploymentIn(p, v, env)
		}
		if keep, _ := q.Body["keep_current_config"].(bool); !keep {
			d.data.ConfigSource = runr.ConfigRestored
		}
		return 202, d.data, nil
	})
}

// decodeInto copies a JSON-shaped map into a typed value.
// environmentWarmer checks and normalizes overrides.scale_schedule like the API: rows as on a
// project (never above max_scale: the environment's, else the project's), [] for none; null
// follows the project's schedule.
func environmentWarmer(p *project, overrides map[string]any) *apiErr {
	raw, ok := overrides["scale_schedule"]
	if !ok {
		return nil
	}
	if raw == nil {
		delete(overrides, "scale_schedule")
		return nil
	}
	rows, isList := raw.([]any)
	if !isList {
		return errValidation("overrides.scale_schedule", "The overrides.scale schedule field must be an array.")
	}
	if len(rows) > runr.MaxScaleScheduleRows {
		return errValidation("overrides.scale_schedule", fmt.Sprintf("The overrides.scale schedule field must not have more than %d items.", runr.MaxScaleScheduleRows))
	}
	if len(rows) > 0 && projectTrigger(p) == runr.TriggerSchedule {
		return errValidation("overrides.scale_schedule", "The timed warmer keeps containers of a Knative Service warm: a schedule (CronJob) trigger has none.")
	}
	maxScale, hasMax := overrides["max_scale"].(float64)
	if !hasMax {
		maxScale, hasMax = p.config["max_scale"].(float64)
	}
	out := make([]any, 0, len(rows))
	for i, r := range rows {
		row, _ := r.(map[string]any)
		cron, _ := row["cron"].(string)
		if len(strings.Fields(cron)) != 5 {
			return errValidation(fmt.Sprintf("overrides.scale_schedule.%d.cron", i), "The cron expression is not valid.")
		}
		n, isNum := row["min_scale"].(float64)
		if !isNum || n < 0 || n > runr.MaxWarmScale || n != float64(int(n)) {
			return errValidation(fmt.Sprintf("overrides.scale_schedule.%d.min_scale", i), "The container count must be between 0 and 10.")
		}
		if hasMax && n > maxScale {
			return errValidation(fmt.Sprintf("overrides.scale_schedule.%d.min_scale", i), fmt.Sprintf("The container count must not be above the maximum scale (%d).", int(maxScale)))
		}
		out = append(out, map[string]any{"cron": runr.NormalizeCron(cron), "min_scale": n})
	}
	overrides["scale_schedule"] = out
	return nil
}

// SetEnvironmentWarmScale records what the timed warmer last applied to an environment.
func (s *Server) SetEnvironmentWarmScale(projectName, environment string, applied int, at time.Time) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	e, err := s.environmentRef(p, environment)
	if err != nil {
		s.t.Fatalf("runrtest: %s", err.message)
	}
	e.warm = &runr.WarmScale{Applied: &applied, AppliedAt: &at}
}

func decodeInto(in map[string]any, out any) {
	data, _ := json.Marshal(in)
	_ = json.Unmarshal(data, out)
}
