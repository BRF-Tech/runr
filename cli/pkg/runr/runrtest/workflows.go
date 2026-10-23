package runrtest

import (
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Durable workflows (routes/workflows.php): definitions per project, runs and the
// organization's events. A started run is queued, stays running for WorkflowPolls GETs of its
// detail and then ends with the outcome SetWorkflowRunOutcome chose (completed by default).

type wfState struct {
	defs   map[string][]*runr.Workflow // project id → definitions
	runs   []*wfRun
	events []*runr.WorkflowEvent
	final  string
	polls  int
	synced map[string]string // project id → last sync time
}

type wfRun struct {
	data      runr.WorkflowRun
	pollsLeft int
	final     string
}

func (s *Server) wf() *wfState {
	return featureState(s, "workflows", func() *wfState {
		return &wfState{defs: map[string][]*runr.Workflow{}, final: runr.RunCompleted, polls: 1, synced: map[string]string{}}
	})
}

var (
	wfNamePattern   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`)
	wfEventPattern  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:/-]{0,199}$`)
	wfStreamPattern = regexp.MustCompile(`^[a-zA-Z0-9._-]{1,255}$`)
	// Duration::milliseconds: "30s", "1h30m", a number of seconds or an ISO 8601 duration
	wfDurationPattern = regexp.MustCompile(`^((\d+(\.\d+)?(ms|s|m|h|d|w))+|\d+(\.\d+)?|p[0-9a-z.]+)$`)
)

func init() {
	handle("GET /projects/{project}/workflows", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, env, err := s.wfProject(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, s.wfListing(p, env), nil
	})
	handle("PUT /projects/{project}/workflows", (*Server).wfDeclare)
	handle("PUT /projects/{project}/workflows/{workflow}", (*Server).wfDeclareOne)
	handle("DELETE /projects/{project}/workflows/{workflow}", (*Server).wfArchive)
	handle("POST /projects/{project}/workflows/sync", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, env, err := s.wfProject(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.wf()
		key := wfKey(p.data.ID, env)
		st.synced[key] = nowRFC3339()
		for _, w := range st.defs[p.data.ID] {
			if !w.Archived && w.Environment == env {
				w.SyncedAt = st.synced[key]
			}
		}
		out := s.wfListing(p, env)
		out["message"] = "Workflow definitions synced."
		out["source"] = "sdk"
		return 200, out, nil
	})
	handle("GET /projects/{project}/workflows/{workflow}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, w, err := s.wfFind(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.wfJSON(p, w)}, nil
	})
	handle("PATCH /projects/{project}/workflows/{workflow}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, w, err := s.wfFind(q)
		if err != nil {
			return 0, nil, err
		}
		active, ok := q.Body["is_active"].(bool)
		if !ok {
			if _, present := q.Body["is_active"]; present {
				return 0, nil, errValidation("is_active", "The is active field must be true or false.")
			}
			return 0, nil, errValidation("is_active", "The is active field is required.")
		}
		w.IsActive = active
		return 200, map[string]any{"data": s.wfJSON(p, w)}, nil
	})
	handle("GET /projects/{project}/workflows/{workflow}/runs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return s.wfRunList(p, q, q.Segs[3])
	})
	handle("POST /projects/{project}/workflows/{workflow}/runs", (*Server).wfStart)
	handle("GET /projects/{project}/workflow-runs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		return s.wfRunList(p, q, q.query("workflow"))
	})
	handle("GET /projects/{project}/workflow-runs/{run}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.wfFindRun(q)
		if err != nil {
			return 0, nil, err
		}
		s.wfAdvance(r)
		return 200, map[string]any{"data": wfRunJSON(r, true)}, nil
	})
	handle("POST /projects/{project}/workflow-runs/{run}/cancel", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.wfFindRun(q)
		if err != nil {
			return 0, nil, err
		}
		if runr.RunTerminal(r.data.Status) {
			return 0, nil, &apiErr{status: 409, message: "The run has already finished."}
		}
		r.data.Status = runr.RunCancelled
		r.data.Error = json.RawMessage(`{"name":"Cancelled","message":"Cancelled by ` + s.User.Name + `"}`)
		r.data.EndedAt = wfTime(time.Now())
		return 200, map[string]any{"data": wfRunJSON(r, false)}, nil
	})
	handle("POST /projects/{project}/workflow-runs/{run}/retry", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, r, err := s.wfFindRun(q)
		if err != nil {
			return 0, nil, err
		}
		switch r.data.Status {
		case runr.RunFailed, runr.RunCancelled, runr.RunTimedOut:
		default:
			return 0, nil, &apiErr{status: 409, message: "Only failed, cancelled or timed-out runs can be retried (this run is " + r.data.Status + ")."}
		}
		st := s.wf()
		r.data.Status = runr.RunQueued
		r.data.RetryCount++
		r.data.Error = nil
		r.data.EndedAt = ""
		r.pollsLeft, r.final = st.polls, st.final
		return 200, map[string]any{"data": wfRunJSON(r, false)}, nil
	})
	handle("POST /projects/{project}/workflow-runs/{run}/replay", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, r, err := s.wfFindRun(q)
		if err != nil {
			return 0, nil, err
		}
		if !runr.RunTerminal(r.data.Status) {
			return 0, nil, &apiErr{status: 409, message: "A run can be replayed once it has finished."}
		}
		from := q.str("from_step")
		if from != "" {
			found := false
			for _, st := range r.data.Steps {
				found = found || st.StepKey == from
			}
			if !found {
				return 0, nil, &apiErr{status: 422, message: `Step "` + from + `" is not part of this run.`}
			}
		}
		n := s.wfNewRun(p.data.ID, r.data.Workflow, "replay", r.data.Event)
		n.data.ReplayOfRunID = r.data.ID
		n.data.ReplayFromStep = from
		return 201, map[string]any{"data": wfRunJSON(n, false)}, nil
	})
	handle("POST /events", (*Server).wfSendEvents)
	handle("GET /events", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.wf()
		var items []any
		for i := len(st.events) - 1; i >= 0; i-- {
			e := st.events[i]
			if n := q.query("name"); n != "" && !strings.HasPrefix(e.Name, n) {
				continue
			}
			if src := q.query("source"); src != "" && e.Source != src {
				continue
			}
			if pid := q.query("project_id"); pid != "" && e.ProjectID != pid {
				continue
			}
			items = append(items, wfEventJSON(e))
		}
		return wfPaginate(items, q)
	})
	handle("GET /events/{event}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.wf()
		for _, e := range st.events {
			if e.ID == q.Segs[1] {
				runs := []any{}
				for _, r := range st.runs {
					if r.data.EventID == e.ID {
						runs = append(runs, wfRunJSON(r, false))
					}
				}
				return 200, map[string]any{"data": wfEventJSON(e), "runs": runs}, nil
			}
		}
		return 0, nil, &apiErr{status: 404, message: "Event not found."}
	})
}

// ---------------------------------------------------------------------------------------
// Seed helpers (they lock s.mu)

// SeedWorkflow adds an active workflow definition reported by the project's SDK.
func (s *Server) SeedWorkflow(projectName, name string, triggers ...runr.WorkflowTrigger) runr.Workflow {
	return s.seedWorkflow(projectName, name, "sdk", triggers)
}

// SeedDeclaredWorkflow adds an active workflow declared through the API (source api).
func (s *Server) SeedDeclaredWorkflow(projectName, name string, triggers ...runr.WorkflowTrigger) runr.Workflow {
	return s.seedWorkflow(projectName, name, "api", triggers)
}

// SeedWorkflowIn adds an active workflow the SDK of an environment's deployment reported
// (docs/workflows.md#environments); the environment must exist (SeedEnvironment).
func (s *Server) SeedWorkflowIn(projectName, environment, name string, triggers ...runr.WorkflowTrigger) runr.Workflow {
	return s.seedWorkflowIn(projectName, environment, name, "sdk", triggers)
}

func (s *Server) seedWorkflow(projectName, name, source string, triggers []runr.WorkflowTrigger) runr.Workflow {
	return s.seedWorkflowIn(projectName, "", name, source, triggers)
}

func (s *Server) seedWorkflowIn(projectName, environment, name, source string, triggers []runr.WorkflowTrigger) runr.Workflow {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	if environment == runr.PrimaryEnvironment {
		environment = ""
	}
	if environment != "" {
		if _, err := s.environmentRef(p, environment); err != nil {
			s.t.Fatalf("runrtest: project %q has no environment %q", projectName, environment)
		}
	}
	if triggers == nil {
		triggers = []runr.WorkflowTrigger{}
	}
	w := &runr.Workflow{ID: s.newID(), ProjectID: p.data.ID, Environment: environment, Name: name, Triggers: triggers, Retries: 3,
		IsActive: true, Source: source, SyncedAt: nowRFC3339(), CreatedAt: nowRFC3339()}
	st := s.wf()
	st.defs[p.data.ID] = append(st.defs[p.data.ID], w)
	return *w
}

// SeedWorkflowRun adds a run of a workflow in the given status, with the steps "charge"
// (completed) and "ship" (completed, or failed for a failed run).
func (s *Server) SeedWorkflowRun(projectName, workflow, status string) runr.WorkflowRun {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	w := s.wfByName(p.data.ID, workflow)
	if w == nil {
		s.t.Fatalf("runrtest: workflow %q of %q does not exist", workflow, projectName)
	}
	r := s.wfNewRun(p.data.ID, runr.WorkflowRef{ID: w.ID, Name: w.Name}, "api",
		json.RawMessage(`{"name":"runr/api.start","data":{"order_id":"o-1"}}`))
	r.data.Status = status
	r.pollsLeft = 0
	now := time.Now()
	r.data.StartedAt = wfTime(now.Add(-2 * time.Second))
	ship := runr.WorkflowStep{ID: s.newID(), StepKey: "ship", Name: "ship", Type: "run", Status: "completed", Position: 1,
		Attempt: 1, MaxAttempts: 4, Output: json.RawMessage(`{"shipped":true}`)}
	switch status {
	case runr.RunFailed:
		ship.Status = "failed"
		ship.Output = nil
		ship.Error = json.RawMessage(`{"name":"Error","message":"carrier unavailable"}`)
		r.data.Error = json.RawMessage(`{"name":"StepError","message":"step ship failed: carrier unavailable"}`)
	case runr.RunCompleted:
		r.data.Output = json.RawMessage(`{"shipped":true}`)
	}
	if runr.RunTerminal(status) {
		r.data.EndedAt = wfTime(now)
		d := int64(2000)
		r.data.DurationMs = &d
	}
	r.data.Steps = []runr.WorkflowStep{
		{ID: s.newID(), StepKey: "charge", Name: "charge", Type: "run", Status: "completed", Position: 0, Attempt: 1,
			MaxAttempts: 4, Output: json.RawMessage(`{"charged":true}`)},
		ship,
	}
	r.data.StepsCount = len(r.data.Steps)
	return r.data
}

// SetWorkflowRunOutcome makes runs started from now on stay active for polls GETs of their
// detail and then end with final (completed, failed, cancelled or timed_out).
func (s *Server) SetWorkflowRunOutcome(final string, polls int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.wf()
	st.final, st.polls = final, polls
}

// Workflow returns a project's workflow definition by name (the project's own workload).
func (s *Server) Workflow(projectName, name string) (runr.Workflow, bool) {
	return s.WorkflowIn(projectName, "", name)
}

// WorkflowIn returns a workflow definition of one environment of a project by name ("" or
// production: the project's own workload).
func (s *Server) WorkflowIn(projectName, environment, name string) (runr.Workflow, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return runr.Workflow{}, false
	}
	if environment == runr.PrimaryEnvironment {
		environment = ""
	}
	w := s.wfByNameIn(p.data.ID, environment, name)
	if w == nil {
		return runr.Workflow{}, false
	}
	out := *w
	out.Environment = wfEnvName(w.Environment)
	return out, true
}

// WorkflowRuns returns a project's runs, newest first.
func (s *Server) WorkflowRuns(projectName string) []runr.WorkflowRun {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	var out []runr.WorkflowRun
	st := s.wf()
	for i := len(st.runs) - 1; i >= 0; i-- {
		if st.runs[i].data.ProjectID == p.data.ID {
			out = append(out, st.runs[i].data)
		}
	}
	return out
}

// WorkflowEvents returns the received events, oldest first.
func (s *Server) WorkflowEvents() []runr.WorkflowEvent {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []runr.WorkflowEvent
	for _, e := range s.wf().events {
		out = append(out, *e)
	}
	return out
}

// ---------------------------------------------------------------------------------------
// Behaviour

// Every environment of a project has workflows of its own (docs/workflows.md#environments): a
// definition's Environment is the environment's name, "" for the project's own workload
// (production); the workflow app and the sync state are kept per project and environment.

func wfKey(projectID, env string) string { return projectID + "/" + env }

// wfEnvName is the environment as the API names it ("production" for the project's own).
func wfEnvName(env string) string {
	if env == "" {
		return runr.PrimaryEnvironment
	}
	return env
}

// wfProject resolves the project and the environment a workflow request names (environment: in
// the query of a GET, in the body otherwise; a name or id; none or production: "").
func (s *Server) wfProject(q *featureReq) (*project, string, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return nil, "", err
	}
	var ref string
	if q.R.Method == "GET" || q.R.Method == "DELETE" {
		ref = q.query("environment")
	} else {
		ref, _ = q.Body["environment"].(string)
	}
	ref = strings.TrimSpace(ref)
	if ref == "" || strings.EqualFold(ref, runr.PrimaryEnvironment) {
		return p, "", nil
	}
	e, err := s.environmentRef(p, ref)
	if err != nil {
		return nil, "", err
	}
	if e.data.IsPrimary {
		return p, "", nil
	}
	return p, e.data.Name, nil
}

// wfByName returns a workflow of the project's own workload.
func (s *Server) wfByName(projectID, name string) *runr.Workflow {
	return s.wfByNameIn(projectID, "", name)
}

func (s *Server) wfByNameIn(projectID, env, name string) *runr.Workflow {
	for _, w := range s.wf().defs[projectID] {
		if w.Name == name && w.Environment == env {
			return w
		}
	}
	return nil
}

func (s *Server) wfFind(q *featureReq) (*project, *runr.Workflow, *apiErr) {
	p, env, err := s.wfProject(q)
	if err != nil {
		return nil, nil, err
	}
	w := s.wfByNameIn(p.data.ID, env, q.Segs[3])
	if w == nil {
		return nil, nil, &apiErr{status: 404, message: "Workflow not found."}
	}
	return p, w, nil
}

func (s *Server) wfFindRun(q *featureReq) (*project, *wfRun, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return nil, nil, err
	}
	for _, r := range s.wf().runs {
		if r.data.ID == q.Segs[3] && r.data.ProjectID == p.data.ID {
			return p, r, nil
		}
	}
	return nil, nil, &apiErr{status: 404, message: "Workflow run not found."}
}

func (s *Server) wfListing(p *project, env string) map[string]any {
	var defs []*runr.Workflow
	for _, w := range s.wf().defs[p.data.ID] {
		if w.Environment == env {
			defs = append(defs, w)
		}
	}
	sort.SliceStable(defs, func(i, j int) bool {
		if defs[i].Archived != defs[j].Archived {
			return !defs[i].Archived
		}
		return defs[i].Name < defs[j].Name
	})
	list := []any{}
	for _, w := range defs {
		list = append(list, s.wfJSON(p, w))
	}
	return map[string]any{"data": list, "app": s.wfAppDetail(p, env)}
}

func (s *Server) wfAppJSON(p *project, env string) map[string]any {
	trigger, _ := p.config["trigger_type"].(string)
	if trigger == "" {
		trigger = runr.TriggerHTTP
	}
	stream, _ := p.config["queue_stream"].(string)
	synced := s.wf().synced[wfKey(p.data.ID, env)]
	workload := p.data.Slug
	if env != "" {
		if e, err := s.environmentRef(p, env); err == nil {
			workload = e.data.WorkloadName
		}
	}
	var status, last any
	if synced != "" {
		status, last = "ok", synced
	}
	var event any
	if stream != "" {
		event = runr.QueuePrefix + stream
	}
	return map[string]any{
		"project_id": p.data.ID, "environment": wfEnvName(env), "trigger_type": trigger, "is_workflow_project": trigger == "workflow",
		"endpoint_url":      "https://" + workload + "." + s.regionName(p) + ".runr.test",
		"endpoint_override": nil, "key_fingerprint": "sha256:" + strings.ToLower(p.data.ID[len(p.data.ID)-8:]),
		"previous_key_expires_at": nil, "sync_status": status, "sync_error": nil, "sdk": nil, "last_synced_at": last,
		"queue_bridge": map[string]any{"enabled": trigger == "workflow" && stream != "", "stream": wfNil(stream),
			"event": event, "has_redis": true},
	}
}

func wfNil(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func (s *Server) wfJSON(p *project, w *runr.Workflow) map[string]any {
	triggers := []any{}
	for _, t := range w.Triggers {
		m := map[string]any{}
		switch {
		case t.Cron != "":
			m["cron"] = t.Cron
			if t.Timezone != "" {
				m["timezone"] = t.Timezone
			}
		default:
			ev := t.Event
			if t.Queue != "" {
				ev = runr.QueuePrefix + t.Queue
			}
			m["event"] = ev
			if t.If != "" {
				m["if"] = t.If
			}
		}
		triggers = append(triggers, m)
	}
	concurrency := []any{}
	for _, c := range w.Concurrency {
		concurrency = append(concurrency, map[string]any{"limit": c.Limit, "key": wfNil(c.Key)})
	}
	cancel := []any{}
	for _, c := range w.CancelOn {
		m := map[string]any{"event": c.Event}
		if c.Expression != "" {
			m["expression"] = c.Expression
		}
		cancel = append(cancel, m)
	}
	counts := map[string]int{}
	var last string
	for _, r := range s.wf().runs {
		if r.data.Workflow.ID == w.ID {
			counts[r.data.Status]++
			if r.data.CreatedAt > last {
				last = r.data.CreatedAt
			}
		}
	}
	var archivedAt any
	if w.ArchivedAt != "" {
		archivedAt = w.ArchivedAt
	}
	return map[string]any{
		"id": w.ID, "project_id": p.data.ID, "environment": wfEnvName(w.Environment), "name": w.Name, "description": wfNil(w.Description),
		"triggers": triggers, "retries": w.Retries, "timeout": wfNil(w.Timeout), "concurrency": concurrency,
		"idempotency": wfNil(w.Idempotency), "cancel_on": cancel, "is_active": w.IsActive,
		"archived": w.Archived, "archived_at": archivedAt, "source": w.Source, "synced_at": wfNil(w.SyncedAt),
		"run_counts": counts, "last_run_at": wfNil(last), "created_at": w.CreatedAt,
	}
}

// wfDeclare is PUT /projects/{project}/workflows (WorkflowRegistry::declare).
func (s *Server) wfDeclare(q *featureReq) (int, any, *apiErr) {
	p, env, err := s.wfProject(q)
	if err != nil {
		return 0, nil, err
	}
	raw, present := q.Body["workflows"]
	if !present {
		return 0, nil, errValidation("workflows", "The workflows field must be present.")
	}
	items, ok := raw.([]any)
	if !ok {
		return 0, nil, errValidation("workflows", "The workflows field must be an array.")
	}
	var defs []*runr.Workflow
	seen := map[string]bool{}
	for _, it := range items {
		w, derr := wfParseDefinition(it)
		if derr != nil {
			return 0, nil, derr
		}
		if seen[w.Name] {
			return 0, nil, &apiErr{status: 422, message: `Workflow "` + w.Name + `" is declared twice`}
		}
		seen[w.Name] = true
		defs = append(defs, w)
	}
	st := s.wf()
	for _, d := range defs {
		s.wfStore(p, env, d)
	}
	now := nowRFC3339()
	for _, w := range st.defs[p.data.ID] {
		if w.Environment == env && !seen[w.Name] && !w.Archived {
			w.Archived, w.ArchivedAt = true, now
		}
	}
	out := s.wfListing(p, env)
	out["message"] = "Workflow definitions saved."
	return 200, out, nil
}

// wfStore adds or replaces the definition d in the project's environment (WorkflowRegistry::
// apply): a live definition declared unchanged keeps its source and sync time; otherwise it is
// the API's now.
func (s *Server) wfStore(p *project, env string, d *runr.Workflow) *runr.Workflow {
	st := s.wf()
	now := nowRFC3339()
	w := s.wfByNameIn(p.data.ID, env, d.Name)
	if w == nil {
		w = &runr.Workflow{ID: s.newID(), ProjectID: p.data.ID, Name: d.Name, IsActive: true, CreatedAt: now}
		st.defs[p.data.ID] = append(st.defs[p.data.ID], w)
	}
	unchanged := w.Source != "" && !w.Archived && wfSameDefinition(w, d)
	source, synced := "api", now
	if unchanged {
		source, synced = w.Source, w.SyncedAt
	}
	active := w.IsActive
	id, created := w.ID, w.CreatedAt
	*w = *d
	w.ID, w.ProjectID, w.CreatedAt, w.IsActive, w.Environment = id, p.data.ID, created, active, env
	w.Source, w.SyncedAt, w.Archived, w.ArchivedAt = source, synced, false, ""
	return w
}

// wfSameDefinition reports whether a and b define the same workflow (Definition::hash()).
func wfSameDefinition(a, b *runr.Workflow) bool {
	content := func(w *runr.Workflow) string {
		triggers, limits, cancel := w.Triggers, w.Concurrency, w.CancelOn
		if triggers == nil {
			triggers = []runr.WorkflowTrigger{}
		}
		if limits == nil {
			limits = []runr.WorkflowConcurrency{}
		}
		if cancel == nil {
			cancel = []runr.WorkflowCancelOn{}
		}
		out, _ := json.Marshal([]any{w.Name, w.Description, triggers, w.Retries, w.Timeout, limits, w.Idempotency, cancel})
		return string(out)
	}
	return content(a) == content(b)
}

// wfDeclareOne is PUT /projects/{project}/workflows/{workflow} (WorkflowRegistry::declareOne): one
// definition added or replaced, the others as they are.
func (s *Server) wfDeclareOne(q *featureReq) (int, any, *apiErr) {
	p, env, err := s.wfProject(q)
	if err != nil {
		return 0, nil, err
	}
	name := q.Segs[3]
	raw := map[string]any{}
	for k, v := range q.Body {
		if k != "environment" {
			raw[k] = v
		}
	}
	if given, ok := raw["name"]; ok && given != name {
		return 0, nil, &apiErr{status: 422, code: "definition_invalid", message: fmt.Sprintf(`The definition is named "%v"; the address names workflow "%s".`, given, name)}
	}
	raw["name"] = name
	d, derr := wfParseDefinition(raw)
	if derr != nil {
		derr.code = "definition_invalid"
		return 0, nil, derr
	}
	w := s.wfStore(p, env, d)
	return 200, map[string]any{"message": `Workflow "` + name + `" saved.`, "data": s.wfJSON(p, w)}, nil
}

// wfArchive is DELETE /projects/{project}/workflows/{workflow}: one definition archived.
func (s *Server) wfArchive(q *featureReq) (int, any, *apiErr) {
	p, w, err := s.wfFind(q)
	if err != nil {
		err.code = "workflow_not_found"
		return 0, nil, err
	}
	if !w.Archived {
		w.Archived, w.ArchivedAt = true, nowRFC3339()
	}
	return 200, map[string]any{"message": `Workflow "` + w.Name + `" archived: it starts no new runs.`, "data": s.wfJSON(p, w)}, nil
}

// wfParseDefinition validates one definition like App\Workflows\Engine\Definition::fromArray.
func wfParseDefinition(raw any) (*runr.Workflow, *apiErr) {
	m, ok := raw.(map[string]any)
	if !ok {
		return nil, &apiErr{status: 422, message: "A workflow definition must be an object"}
	}
	name, _ := m["name"].(string)
	if name == "" {
		name, _ = m["id"].(string)
	}
	if !wfNamePattern.MatchString(name) {
		return nil, &apiErr{status: 422, message: `Workflow name must match /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/ (letters, digits, ".", "_", "-"; at most 100)`}
	}
	if l := strings.ToLower(name); l == "app" || l == "sync" {
		return nil, &apiErr{status: 422, message: `Workflow name "` + name + `" is reserved`}
	}
	w := &runr.Workflow{Name: name, Triggers: []runr.WorkflowTrigger{}, Retries: 3}
	w.Description, _ = m["description"].(string)
	if rawT, ok := m["triggers"]; ok && rawT != nil {
		list, ok := rawT.([]any)
		if !ok {
			return nil, &apiErr{status: 422, message: name + `: "triggers" must be a list`}
		}
		for i, it := range list {
			t, ok := it.(map[string]any)
			if !ok {
				return nil, &apiErr{status: 422, message: fmt.Sprintf("%s: triggers[%d] must be an object", name, i)}
			}
			cond, _ := t["if"].(string)
			switch {
			case t["cron"] != nil:
				cron, _ := t["cron"].(string)
				if len(strings.Fields(cron)) != 5 {
					return nil, &apiErr{status: 422, message: fmt.Sprintf(`%s: triggers[%d]: invalid cron expression "%s"`, name, i, cron)}
				}
				tz, _ := t["timezone"].(string)
				if tz != "" {
					if _, err := time.LoadLocation(tz); err != nil {
						return nil, &apiErr{status: 422, message: fmt.Sprintf("%s: triggers[%d]: unknown timezone", name, i)}
					}
				}
				w.Triggers = append(w.Triggers, runr.WorkflowTrigger{Cron: strings.TrimSpace(cron), Timezone: tz})
			case t["queue"] != nil || t["event"] != nil:
				ev, _ := t["event"].(string)
				if t["queue"] != nil {
					qs, _ := t["queue"].(string)
					if !wfStreamPattern.MatchString(qs) {
						return nil, &apiErr{status: 422, message: fmt.Sprintf("%s: triggers[%d]: invalid queue stream name", name, i)}
					}
					ev = runr.QueuePrefix + qs
				}
				if !wfEventPattern.MatchString(ev) {
					return nil, &apiErr{status: 422, message: fmt.Sprintf("%s: triggers[%d]: invalid event name", name, i)}
				}
				w.Triggers = append(w.Triggers, runr.WorkflowTrigger{Event: ev, If: strings.TrimSpace(cond)})
			default:
				return nil, &apiErr{status: 422, message: fmt.Sprintf(`%s: triggers[%d] needs "event", "cron" or "queue"`, name, i)}
			}
		}
	}
	if r, ok := m["retries"].(float64); ok {
		if r < 0 || r > 20 || r != float64(int(r)) {
			return nil, &apiErr{status: 422, message: name + `: "retries" must be an integer between 0 and 20`}
		}
		w.Retries = int(r)
	}
	switch t := m["timeout"].(type) {
	case string:
		if !wfDurationPattern.MatchString(strings.ToLower(strings.TrimSpace(t))) {
			return nil, &apiErr{status: 422, message: name + `: invalid "timeout"`}
		}
		w.Timeout = t
	case float64:
		w.Timeout = strconv.Itoa(int(t)) + "s"
	}
	switch c := m["concurrency"].(type) {
	case float64:
		w.Concurrency = []runr.WorkflowConcurrency{{Limit: int(c)}}
	case map[string]any:
		w.Concurrency = []runr.WorkflowConcurrency{wfLimit(c)}
	case []any:
		if len(c) > 2 {
			return nil, &apiErr{status: 422, message: name + `: "concurrency" takes a limit, an object or a list of at most 2`}
		}
		for _, it := range c {
			switch l := it.(type) {
			case float64:
				w.Concurrency = append(w.Concurrency, runr.WorkflowConcurrency{Limit: int(l)})
			case map[string]any:
				w.Concurrency = append(w.Concurrency, wfLimit(l))
			}
		}
	}
	keyed := 0
	for _, l := range w.Concurrency {
		if l.Limit < 1 || l.Limit > 1000 {
			return nil, &apiErr{status: 422, message: name + ": concurrency limit must be an integer between 1 and 1000"}
		}
		if l.Key != "" {
			if keyed++; keyed > 1 {
				return nil, &apiErr{status: 422, message: name + ": only one keyed concurrency limit"}
			}
		}
	}
	idem, _ := m["idempotency"].(string)
	w.Idempotency = strings.TrimSpace(idem)
	if list, ok := m["cancel_on"].([]any); ok {
		if len(list) > 10 {
			return nil, &apiErr{status: 422, message: name + `: "cancel_on" must be a list of at most 10`}
		}
		for i, it := range list {
			c, _ := it.(map[string]any)
			ev, _ := c["event"].(string)
			if !wfEventPattern.MatchString(ev) {
				return nil, &apiErr{status: 422, message: fmt.Sprintf("%s: cancel_on[%d]: invalid event name", name, i)}
			}
			// Expression::combine: each part in parentheses, joined with &&
			match, _ := c["match"].(string)
			cond, _ := c["if"].(string)
			var parts []string
			if match = strings.TrimSpace(match); match != "" {
				parts = append(parts, "(event."+match+" == async."+match+")")
			}
			if cond = strings.TrimSpace(cond); cond != "" {
				parts = append(parts, "("+cond+")")
			}
			w.CancelOn = append(w.CancelOn, runr.WorkflowCancelOn{Event: ev, Expression: strings.Join(parts, " && ")})
		}
	}
	return w, nil
}

func wfLimit(m map[string]any) runr.WorkflowConcurrency {
	l, _ := m["limit"].(float64)
	k, _ := m["key"].(string)
	return runr.WorkflowConcurrency{Limit: int(l), Key: strings.TrimSpace(k)}
}

func (s *Server) wfStart(q *featureReq) (int, any, *apiErr) {
	p, w, err := s.wfFind(q)
	if err != nil {
		return 0, nil, err
	}
	data := map[string]any{}
	switch d := q.Body["data"].(type) {
	case nil:
	case map[string]any:
		data = d
	default:
		return 0, nil, &apiErr{status: 422, message: `Event #0: "data" must be a JSON object.`}
	}
	if !w.IsActive || w.Archived {
		return 0, nil, &apiErr{status: 409, message: `Workflow "` + w.Name + `" is paused or no longer declared by the project.`}
	}
	key := q.str("idempotency_key")
	if key != "" {
		for _, r := range s.wf().runs {
			if r.data.Workflow.ID == w.ID && r.data.IdempotencyKey == key {
				return 200, map[string]any{"data": wfRunJSON(r, false), "created": false}, nil
			}
		}
	}
	source := "api"
	if test, _ := q.Body["test"].(bool); test {
		source = "test"
	}
	event, _ := json.Marshal(map[string]any{"name": "runr/api.start", "data": data})
	r := s.wfNewRun(p.data.ID, runr.WorkflowRef{ID: w.ID, Name: w.Name}, source, event)
	r.data.IdempotencyKey = key
	r.data.StartedBy = s.User.ID
	return 201, map[string]any{"data": wfRunJSON(r, false), "created": true}, nil
}

func (s *Server) wfNewRun(projectID string, w runr.WorkflowRef, source string, event json.RawMessage) *wfRun {
	st := s.wf()
	r := &wfRun{pollsLeft: st.polls, final: st.final}
	r.data = runr.WorkflowRun{ID: s.newID(), Workflow: w, ProjectID: projectID, Status: runr.RunQueued,
		TriggerSource: source, Event: event, CreatedAt: wfTime(time.Now())}
	var ev struct {
		Name string `json:"name"`
	}
	if json.Unmarshal(event, &ev) == nil {
		r.data.EventName = ev.Name
	}
	st.runs = append(st.runs, r)
	return r
}

// wfAdvance moves an active run on: running while pollsLeft lasts, then its final status.
func (s *Server) wfAdvance(r *wfRun) {
	if runr.RunTerminal(r.data.Status) {
		return
	}
	now := time.Now()
	if r.data.StartedAt == "" {
		r.data.StartedAt = wfTime(now)
	}
	if r.pollsLeft > 0 {
		r.pollsLeft--
		r.data.Status = runr.RunRunning
		r.data.Attempt = 1
		return
	}
	r.data.Status = r.final
	r.data.EndedAt = wfTime(now)
	d := int64(1500)
	r.data.DurationMs = &d
	step := runr.WorkflowStep{ID: s.newID(), StepKey: "work", Name: "work", Type: "run", Status: "completed",
		Attempt: 1, MaxAttempts: 4, StartedAt: r.data.StartedAt, EndedAt: r.data.EndedAt, DurationMs: &d}
	switch r.final {
	case runr.RunCompleted:
		r.data.Output = json.RawMessage(`{"ok":true}`)
		step.Output = json.RawMessage(`{"ok":true}`)
	case runr.RunFailed:
		step.Status = "failed"
		step.Error = json.RawMessage(`{"name":"Error","message":"boom"}`)
		r.data.Error = json.RawMessage(`{"name":"StepError","message":"step work failed: boom"}`)
	case runr.RunTimedOut:
		r.data.Error = json.RawMessage(`{"name":"RunTimeout","message":"The run did not finish within its timeout"}`)
	case runr.RunCancelled:
		r.data.Error = json.RawMessage(`{"name":"Cancelled","message":"Cancelled"}`)
	}
	r.data.Steps = append(r.data.Steps, step)
	r.data.StepsCount = len(r.data.Steps)
}

func (s *Server) wfRunList(p *project, q *featureReq, workflow string) (int, any, *apiErr) {
	if pp := q.query("per_page"); pp != "" {
		if n, err := strconv.Atoi(pp); err != nil || n < 1 || n > 100 {
			return 0, nil, errValidation("per_page", "The per page field must be between 1 and 100.")
		}
	}
	statuses := map[string]bool{}
	for _, x := range strings.Split(q.query("status"), ",") {
		if x != "" {
			statuses[x] = true
		}
	}
	var items []any
	st := s.wf()
	for i := len(st.runs) - 1; i >= 0; i-- {
		r := st.runs[i]
		if r.data.ProjectID != p.data.ID {
			continue
		}
		if workflow != "" && r.data.Workflow.Name != workflow {
			continue
		}
		if len(statuses) > 0 && !statuses[r.data.Status] {
			continue
		}
		if src := q.query("source"); src != "" && r.data.TriggerSource != src {
			continue
		}
		if needle := q.query("q"); needle != "" && r.data.ID != needle && r.data.IdempotencyKey != needle &&
			!strings.Contains(string(r.data.Event), needle) {
			continue
		}
		items = append(items, wfRunJSON(r, false))
	}
	return wfPaginate(items, q)
}

// wfPaginate answers like a Laravel resource collection of a paginator (first page).
func wfPaginate(items []any, q *featureReq) (int, any, *apiErr) {
	per := 25
	if n, err := strconv.Atoi(q.query("per_page")); err == nil && n > 0 {
		per = n
	}
	total := len(items)
	page := items
	if len(page) > per {
		page = page[:per]
	}
	if page == nil {
		page = []any{}
	}
	last := (total + per - 1) / per
	if last < 1 {
		last = 1
	}
	var from, to any
	if len(page) > 0 {
		from, to = 1, len(page)
	}
	return 200, map[string]any{
		"data":  page,
		"links": map[string]any{"first": "?page=1", "last": "?page=" + strconv.Itoa(last), "prev": nil, "next": nil},
		"meta": map[string]any{"current_page": 1, "from": from, "last_page": last, "links": []any{},
			"path": q.R.URL.Path, "per_page": per, "to": to, "total": total},
	}, nil
}

func wfRunJSON(r *wfRun, detail bool) map[string]any {
	d := r.data
	raw := func(m json.RawMessage) any {
		if len(m) == 0 {
			return nil
		}
		return m
	}
	var dur any
	if d.DurationMs != nil {
		dur = *d.DurationMs
	}
	var startedBy any
	if d.StartedBy != "" {
		startedBy = d.StartedBy.String()
	}
	out := map[string]any{
		"id": d.ID, "workflow": map[string]any{"id": d.Workflow.ID, "name": d.Workflow.Name}, "project_id": d.ProjectID,
		"status": d.Status, "trigger_source": d.TriggerSource, "event_id": wfNil(d.EventID),
		"event_name": wfNil(d.EventName), "error": raw(d.Error), "attempt": d.Attempt, "retry_count": d.RetryCount,
		"idempotency_key": wfNil(d.IdempotencyKey), "concurrency_key": wfNil(d.ConcurrencyKey),
		"parent_run_id": wfNil(d.ParentRunID), "replay_of_run_id": wfNil(d.ReplayOfRunID),
		"replay_from_step": wfNil(d.ReplayFromStep), "started_by": startedBy,
		"next_attempt_at": wfNil(d.NextAttemptAt), "timeout_at": wfNil(d.TimeoutAt),
		"started_at": wfNil(d.StartedAt), "ended_at": wfNil(d.EndedAt), "duration_ms": dur,
		"steps_count": d.StepsCount, "created_at": d.CreatedAt,
	}
	if detail {
		out["event"] = raw(d.Event)
		out["output"] = raw(d.Output)
		steps := []any{}
		for _, st := range d.Steps {
			var sd any
			if st.DurationMs != nil {
				sd = *st.DurationMs
			}
			steps = append(steps, map[string]any{
				"id": st.ID, "step_key": st.StepKey, "name": st.Name, "type": st.Type, "status": st.Status,
				"position": st.Position, "attempt": st.Attempt, "max_attempts": st.MaxAttempts, "input": raw(st.Input),
				"output": raw(st.Output), "error": raw(st.Error), "attempts": []any{}, "wake_at": wfNil(st.WakeAt),
				"timeout_at": wfNil(st.TimeoutAt), "wait_event": wfNil(st.WaitEvent),
				"wait_expression": wfNil(st.WaitExpression), "child_run_id": wfNil(st.ChildRunID),
				"started_at": wfNil(st.StartedAt), "ended_at": wfNil(st.EndedAt), "duration_ms": sd,
			})
		}
		out["steps"] = steps
		out["children"] = []any{}
	}
	return out
}

func wfEventJSON(e *runr.WorkflowEvent) map[string]any {
	data := any(map[string]any{})
	if len(e.Data) > 0 {
		data = e.Data
	}
	var sentBy any
	if e.SentBy != "" {
		sentBy = e.SentBy.String()
	}
	return map[string]any{
		"id": e.ID, "name": e.Name, "data": data, "external_id": wfNil(e.ExternalID), "source": e.Source,
		"project_id": wfNil(e.ProjectID), "sent_by": sentBy, "ts": e.TS, "runs_started": e.RunsStarted,
		"waits_resumed": e.WaitsResumed, "runs_cancelled": e.RunsCancelled, "created_at": e.CreatedAt,
	}
}

// wfSendEvents is POST /events: one event or {"events": […]} (EventIngestor).
func (s *Server) wfSendEvents(q *featureReq) (int, any, *apiErr) {
	var raws []any
	if list, ok := q.Body["events"].([]any); ok {
		raws = list
	} else if q.Body != nil {
		raws = []any{q.Body}
	}
	if len(raws) == 0 {
		return 0, nil, &apiErr{status: 422, message: `Event #0 must be an object with "name" and "data".`}
	}
	if len(raws) > 100 {
		return 0, nil, &apiErr{status: 422, message: "At most 100 events per request."}
	}
	source := "api"
	if t := q.query("test"); t == "1" || t == "true" {
		source = "test"
	}
	st := s.wf()
	ids := []string{}
	results := []any{}
	for i, raw := range raws {
		m, ok := raw.(map[string]any)
		if !ok {
			return 0, nil, &apiErr{status: 422, message: fmt.Sprintf(`Event #%d must be an object with "name" and "data".`, i)}
		}
		name, _ := m["name"].(string)
		if !wfEventPattern.MatchString(name) {
			return 0, nil, &apiErr{status: 422, message: fmt.Sprintf(`Event #%d: "name" must be 1-200 characters of letters, digits and . _ : / -`, i)}
		}
		if strings.HasPrefix(name, "runr/") {
			return 0, nil, &apiErr{status: 422, message: `Event names starting with "runr/" are reserved for the platform.`}
		}
		data := map[string]any{}
		switch d := m["data"].(type) {
		case nil:
		case map[string]any:
			data = d
		default:
			return 0, nil, &apiErr{status: 422, message: fmt.Sprintf(`Event #%d: "data" must be a JSON object.`, i)}
		}
		ext, _ := m["id"].(string)
		if ext != "" {
			var dup *runr.WorkflowEvent
			for _, e := range st.events {
				if e.ExternalID == ext {
					dup = e
				}
			}
			if dup != nil {
				ids = append(ids, dup.ID)
				results = append(results, map[string]any{"id": dup.ID, "name": dup.Name, "duplicate": true,
					"runs": []string{}, "resumed": 0, "cancelled": 0})
				continue
			}
		}
		ts := time.Now().UnixMilli()
		if t, ok := m["ts"].(float64); ok && t > 0 {
			ts = int64(t)
		}
		dataJSON, _ := json.Marshal(data)
		e := &runr.WorkflowEvent{ID: s.newID(), Name: name, Data: dataJSON, ExternalID: ext, Source: source,
			SentBy: s.User.ID, TS: ts, CreatedAt: wfTime(time.Now())}
		st.events = append(st.events, e)
		runs := []string{}
		eventJSON, _ := json.Marshal(map[string]any{"id": e.ID, "name": name, "data": data, "ts": ts})
		for _, pid := range wfSortedKeys(st.defs) {
			for _, w := range st.defs[pid] {
				// an event without an environment is production's
				if !w.IsActive || w.Archived || w.Environment != "" {
					continue
				}
				for _, t := range w.Triggers {
					if t.Cron == "" && (t.Event == name || (t.Queue != "" && runr.QueuePrefix+t.Queue == name)) {
						r := s.wfNewRun(pid, runr.WorkflowRef{ID: w.ID, Name: w.Name}, "event", eventJSON)
						r.data.EventID = e.ID
						runs = append(runs, r.data.ID)
						break
					}
				}
			}
		}
		e.RunsStarted = len(runs)
		ids = append(ids, e.ID)
		results = append(results, map[string]any{"id": e.ID, "name": name, "duplicate": false, "runs": runs,
			"resumed": 0, "cancelled": 0})
	}
	return 200, map[string]any{"ids": ids, "events": results}, nil
}

func wfSortedKeys(m map[string][]*runr.Workflow) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// wfTime formats like the API (ISO 8601, UTC, milliseconds).
func wfTime(t time.Time) string { return t.UTC().Format("2006-01-02T15:04:05.000Z") }
