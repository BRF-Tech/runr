package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Durable workflows (docs/workflows.md): the definitions of a project, their runs and the
// organization's events. Workflow definitions are addressed by name.
//
// Every environment of a project has workflows of its own (docs/workflows.md#environments): a
// context made by WithWorkflowEnvironment addresses one of them — definitions, the workflow app,
// runs, usage — and the events sent or listed with it belong to that environment.

type workflowEnvironmentKey struct{}

// WithWorkflowEnvironment returns a context whose workflow calls address one environment of the
// project: its name or id ("" or "production": the project's own workload). Events sent with it
// belong to the environment of that name.
func WithWorkflowEnvironment(ctx context.Context, environment string) context.Context {
	return context.WithValue(ctx, workflowEnvironmentKey{}, strings.TrimSpace(environment))
}

// WorkflowEnvironment is the environment WithWorkflowEnvironment put into ctx ("" for none).
func WorkflowEnvironment(ctx context.Context) string {
	env, _ := ctx.Value(workflowEnvironmentKey{}).(string)
	return env
}

// wfBody is the body of a workflow request that changes something (POST, PUT, PATCH): fields
// with the context's workflow environment ("environment"); nil when there is nothing to send.
func wfBody(ctx context.Context, fields map[string]any) any {
	if env := WorkflowEnvironment(ctx); env != "" {
		if fields == nil {
			fields = map[string]any{}
		}
		fields["environment"] = env
	}
	if fields == nil {
		return nil
	}
	return fields
}

// wfQuery is q with the context's workflow environment (environment=…) for a GET, q itself
// when there is none.
func wfQuery(ctx context.Context, q url.Values) url.Values {
	env := WorkflowEnvironment(ctx)
	if env == "" {
		return q
	}
	if q == nil {
		q = url.Values{}
	}
	q.Set("environment", env)
	return q
}

// Statuses of a workflow run.
const (
	RunQueued    = "queued"
	RunRunning   = "running"
	RunSleeping  = "sleeping"
	RunWaiting   = "waiting"
	RunCompleted = "completed"
	RunFailed    = "failed"
	RunCancelled = "cancelled"
	RunTimedOut  = "timed_out"
)

// RunTerminal reports whether a run status is final.
func RunTerminal(status string) bool {
	switch status {
	case RunCompleted, RunFailed, RunCancelled, RunTimedOut:
		return true
	}
	return false
}

// Workflow is WorkflowResource: one definition of a project.
type Workflow struct {
	ID        string `json:"id"`
	ProjectID string `json:"project_id"`
	// Environment is the environment the workflow belongs to ("production": the project's own
	// workload).
	Environment string                `json:"environment,omitempty"`
	Name        string                `json:"name"`
	Description string                `json:"description,omitempty"`
	Triggers    []WorkflowTrigger     `json:"triggers"`
	Retries     int                   `json:"retries"`
	Timeout     string                `json:"timeout,omitempty"`
	Concurrency []WorkflowConcurrency `json:"concurrency,omitempty"`
	Idempotency string                `json:"idempotency,omitempty"`
	CancelOn    []WorkflowCancelOn    `json:"cancel_on,omitempty"`
	IsActive    bool                  `json:"is_active"`
	Archived    bool                  `json:"archived"`
	ArchivedAt  string                `json:"archived_at,omitempty"`
	// Source is where the definition came from: sdk (introspection), manifest
	// (runr.workflows.json) or api (declared through the API).
	Source    string    `json:"source,omitempty"`
	SyncedAt  string    `json:"synced_at,omitempty"`
	RunCounts RunCounts `json:"run_counts,omitempty"`
	LastRunAt string    `json:"last_run_at,omitempty"`
	CreatedAt string    `json:"created_at,omitempty"`
}

// State is "active", "paused" or "archived".
func (w *Workflow) State() string {
	switch {
	case w.Archived:
		return "archived"
	case !w.IsActive:
		return "paused"
	}
	return "active"
}

// WorkflowTrigger is one trigger of a definition: an event (with an optional condition), a
// cron schedule or a queue stream. The API stores a queue trigger as the event
// "queue/<stream>".
type WorkflowTrigger struct {
	Event    string `json:"event,omitempty"`
	If       string `json:"if,omitempty"`
	Cron     string `json:"cron,omitempty"`
	Timezone string `json:"timezone,omitempty"`
	Queue    string `json:"queue,omitempty"`
}

// QueuePrefix is the event name prefix of queue triggers.
const QueuePrefix = "queue/"

// Kind returns event, cron or queue.
func (t WorkflowTrigger) Kind() string {
	switch {
	case t.Cron != "":
		return "cron"
	case t.Queue != "" || strings.HasPrefix(t.Event, QueuePrefix):
		return "queue"
	}
	return "event"
}

// Stream returns the queue stream of a queue trigger.
func (t WorkflowTrigger) Stream() string {
	if t.Queue != "" {
		return t.Queue
	}
	return strings.TrimPrefix(t.Event, QueuePrefix)
}

// String renders the trigger for humans: "event order.created", "cron 0 9 * * * (Europe/Istanbul)".
func (t WorkflowTrigger) String() string {
	switch t.Kind() {
	case "cron":
		if t.Timezone != "" {
			return "cron " + t.Cron + " (" + t.Timezone + ")"
		}
		return "cron " + t.Cron
	case "queue":
		return "queue " + t.Stream()
	}
	s := "event " + t.Event
	if t.If != "" {
		s += " if " + t.If
	}
	return s
}

// Same reports whether two triggers are the same trigger (a queue trigger equals its
// "queue/<stream>" event form).
func (t WorkflowTrigger) Same(o WorkflowTrigger) bool {
	if t.Kind() != o.Kind() {
		return false
	}
	switch t.Kind() {
	case "cron":
		return t.Cron == o.Cron && t.Timezone == o.Timezone
	case "queue":
		return t.Stream() == o.Stream() && t.If == o.If
	}
	return t.Event == o.Event && t.If == o.If
}

// WorkflowConcurrency is one concurrency limit (keyed when Key is set).
type WorkflowConcurrency struct {
	Limit int    `json:"limit"`
	Key   string `json:"key,omitempty"`
}

// WorkflowCancelOn is an event that cancels matching active runs.
type WorkflowCancelOn struct {
	Event      string `json:"event"`
	Expression string `json:"expression,omitempty"`
}

// Condition is Expression as a definition's "if" gives it. The API keeps each part of a
// cancel_on (match, if) in parentheses (Expression::combine), so an "if" comes back as
// "(<if>)": Condition takes one pair of parentheses around the whole expression off again, and
// declaring Condition() keeps Expression as it is.
func (c WorkflowCancelOn) Condition() string { return unwrapParens(c.Expression) }

// unwrapParens removes one pair of parentheses that encloses the whole expression ("(a == 1)"
// → "a == 1"; "(a) && (b)" stays). Parentheses inside string literals do not count.
func unwrapParens(expr string) string {
	s := strings.TrimSpace(expr)
	if len(s) < 2 || s[0] != '(' || s[len(s)-1] != ')' {
		return s
	}
	depth := 0
	var quote byte
	for i := 0; i < len(s); i++ {
		ch := s[i]
		switch {
		case quote != 0:
			if ch == '\\' {
				i++
			} else if ch == quote {
				quote = 0
			}
		case ch == '\'' || ch == '"':
			quote = ch
		case ch == '(':
			depth++
		case ch == ')':
			depth--
			if depth == 0 && i != len(s)-1 {
				return s // the first parenthesis closes before the end
			}
		}
	}
	if depth != 0 {
		return s
	}
	return strings.TrimSpace(s[1 : len(s)-1])
}

// RunCounts are the runs of a workflow per status. PHP sends {} or [] when there are none.
type RunCounts map[string]int

// UnmarshalJSON accepts an object, an empty array or null.
func (r *RunCounts) UnmarshalJSON(b []byte) error {
	t := bytes.TrimSpace(b)
	if len(t) == 0 || t[0] != '{' {
		*r = RunCounts{}
		return nil
	}
	var m map[string]int
	if err := json.Unmarshal(t, &m); err != nil {
		return err
	}
	*r = m
	return nil
}

// WorkflowApp is WorkflowAppResource: the function the engine drives and its sync state
// (never the signing key).
type WorkflowApp struct {
	ProjectID         string `json:"project_id"`
	Environment       string `json:"environment,omitempty"`
	TriggerType       string `json:"trigger_type,omitempty"`
	IsWorkflowProject bool   `json:"is_workflow_project"`
	EndpointURL       string `json:"endpoint_url,omitempty"`
	EndpointOverride  string `json:"endpoint_override,omitempty"`
	KeyFingerprint    string `json:"key_fingerprint,omitempty"`
	// PreviousKeyExpiresAt is set while the key before a rotation still signs requests.
	PreviousKeyExpiresAt string `json:"previous_key_expires_at,omitempty"`
	SyncStatus           string `json:"sync_status,omitempty"`
	SyncError            string `json:"sync_error,omitempty"`
	// SDK is what the function's workflow SDK reported at the last sync.
	SDK          any                  `json:"sdk,omitempty"`
	LastSyncedAt string               `json:"last_synced_at,omitempty"`
	QueueBridge  *WorkflowQueueBridge `json:"queue_bridge,omitempty"`
}

// WorkflowQueueBridge is the queue stream whose messages become events of a workflow project.
type WorkflowQueueBridge struct {
	Enabled  bool   `json:"enabled"`
	Stream   string `json:"stream,omitempty"`
	Event    string `json:"event,omitempty"`
	HasRedis bool   `json:"has_redis"`
}

// WorkflowRef names the workflow of a run.
type WorkflowRef struct {
	ID   string `json:"id"`
	Name string `json:"name,omitempty"`
}

// WorkflowRun is WorkflowRunResource. Event, Output and Steps are filled in the detail view
// (GetWorkflowRun).
type WorkflowRun struct {
	ID             string          `json:"id"`
	Workflow       WorkflowRef     `json:"workflow"`
	ProjectID      string          `json:"project_id"`
	Environment    string          `json:"environment,omitempty"`
	Status         string          `json:"status"`
	TriggerSource  string          `json:"trigger_source,omitempty"`
	EventID        string          `json:"event_id,omitempty"`
	EventName      string          `json:"event_name,omitempty"`
	Event          json.RawMessage `json:"event,omitempty"`
	Output         json.RawMessage `json:"output,omitempty"`
	Error          json.RawMessage `json:"error,omitempty"`
	Attempt        int             `json:"attempt"`
	RetryCount     int             `json:"retry_count"`
	IdempotencyKey string          `json:"idempotency_key,omitempty"`
	ConcurrencyKey string          `json:"concurrency_key,omitempty"`
	ParentRunID    string          `json:"parent_run_id,omitempty"`
	ReplayOfRunID  string          `json:"replay_of_run_id,omitempty"`
	ReplayFromStep string          `json:"replay_from_step,omitempty"`
	StartedBy      FlexID          `json:"started_by,omitempty"`
	NextAttemptAt  string          `json:"next_attempt_at,omitempty"`
	TimeoutAt      string          `json:"timeout_at,omitempty"`
	StartedAt      string          `json:"started_at,omitempty"`
	EndedAt        string          `json:"ended_at,omitempty"`
	DurationMs     *int64          `json:"duration_ms,omitempty"`
	StepsCount     int             `json:"steps_count"`
	CreatedAt      string          `json:"created_at,omitempty"`
	Steps          []WorkflowStep  `json:"steps,omitempty"`
	Children       []RunChild      `json:"children,omitempty"`
}

// ErrorMessage returns "Name: message" of the run's error ("" without one).
func (r *WorkflowRun) ErrorMessage() string { return errorText(r.Error) }

// RunChild is a run started by step.invoke.
type RunChild struct {
	ID     string `json:"id"`
	Status string `json:"status"`
}

// WorkflowStep is WorkflowStepResource: one step of a run with its attempt history.
type WorkflowStep struct {
	ID             string          `json:"id"`
	StepKey        string          `json:"step_key"`
	Name           string          `json:"name,omitempty"`
	Type           string          `json:"type"`
	Status         string          `json:"status"`
	Position       int             `json:"position"`
	Attempt        int             `json:"attempt"`
	MaxAttempts    int             `json:"max_attempts"`
	Input          json.RawMessage `json:"input,omitempty"`
	Output         json.RawMessage `json:"output,omitempty"`
	Error          json.RawMessage `json:"error,omitempty"`
	Attempts       json.RawMessage `json:"attempts,omitempty"`
	WakeAt         string          `json:"wake_at,omitempty"`
	TimeoutAt      string          `json:"timeout_at,omitempty"`
	WaitEvent      string          `json:"wait_event,omitempty"`
	WaitExpression string          `json:"wait_expression,omitempty"`
	ChildRunID     string          `json:"child_run_id,omitempty"`
	StartedAt      string          `json:"started_at,omitempty"`
	EndedAt        string          `json:"ended_at,omitempty"`
	DurationMs     *int64          `json:"duration_ms,omitempty"`
}

// ErrorMessage returns "Name: message" of the step's error ("" without one).
func (s *WorkflowStep) ErrorMessage() string { return errorText(s.Error) }

// errorText renders a workflow error object {"name", "message"}.
func errorText(raw json.RawMessage) string {
	t := bytes.TrimSpace(raw)
	if len(t) == 0 || bytes.Equal(t, []byte("null")) || bytes.Equal(t, []byte("[]")) {
		return ""
	}
	var e struct {
		Name    string `json:"name"`
		Message string `json:"message"`
	}
	if t[0] != '{' || json.Unmarshal(t, &e) != nil {
		return string(t)
	}
	switch {
	case e.Name != "" && e.Message != "":
		return e.Name + ": " + e.Message
	case e.Message != "":
		return e.Message
	}
	return e.Name
}

// WorkflowEvent is WorkflowEventResource: one received event.
type WorkflowEvent struct {
	ID            string          `json:"id"`
	Name          string          `json:"name"`
	Data          json.RawMessage `json:"data,omitempty"`
	ExternalID    string          `json:"external_id,omitempty"`
	Source        string          `json:"source,omitempty"`
	ProjectID     string          `json:"project_id,omitempty"`
	Environment   string          `json:"environment,omitempty"`
	SentBy        FlexID          `json:"sent_by,omitempty"`
	TS            int64           `json:"ts,omitempty"`
	RunsStarted   int             `json:"runs_started"`
	WaitsResumed  int             `json:"waits_resumed"`
	RunsCancelled int             `json:"runs_cancelled"`
	CreatedAt     string          `json:"created_at,omitempty"`
}

// ---------------------------------------------------------------------------------------
// Definitions

func workflowsPath(projectID string) string { return "/projects/" + projectID + "/workflows" }

// ListWorkflows returns a project's workflow definitions (archived ones last) and its
// workflow app.
func (c *Client) ListWorkflows(ctx context.Context, projectID string) ([]Workflow, *WorkflowApp, error) {
	var out struct {
		Data []Workflow  `json:"data"`
		App  WorkflowApp `json:"app"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: workflowsPath(projectID), query: wfQuery(ctx, nil)}, &out); err != nil {
		return nil, nil, err
	}
	return out.Data, &out.App, nil
}

// GetWorkflow returns one definition by name.
func (c *Client) GetWorkflow(ctx context.Context, projectID, name string) (*Workflow, error) {
	var out struct {
		Data Workflow `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: workflowsPath(projectID) + "/" + seg(name), query: wfQuery(ctx, nil)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "workflow", Ref: name}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// FindWorkflow resolves a definition by name or id (the API addresses workflows by name).
func (c *Client) FindWorkflow(ctx context.Context, projectID, ref string) (*Workflow, error) {
	ref = strings.TrimSpace(ref)
	if ref == "" {
		return nil, fmt.Errorf("no workflow given")
	}
	if LooksLikeULID(ref) {
		list, _, err := c.ListWorkflows(ctx, projectID)
		if err != nil {
			return nil, err
		}
		for i := range list {
			if list[i].ID == ref {
				return &list[i], nil
			}
		}
	}
	return c.GetWorkflow(ctx, projectID, ref)
}

// SetWorkflowActive pauses (false) or resumes (true) a workflow: a paused workflow starts no
// runs from triggers or the API; runs in progress continue. Pausing survives syncs.
func (c *Client) SetWorkflowActive(ctx context.Context, projectID, name string, active bool) (*Workflow, error) {
	body := wfBody(ctx, map[string]any{"is_active": active})
	var out struct {
		Data Workflow `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: workflowsPath(projectID) + "/" + seg(name), body: body}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "workflow", Ref: name}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// WorkflowsResult is the answer of a sync or a declaration: the definitions after it.
type WorkflowsResult struct {
	Message string      `json:"message,omitempty"`
	Source  string      `json:"source,omitempty"`
	Data    []Workflow  `json:"data"`
	App     WorkflowApp `json:"app"`
}

// SyncWorkflows asks the deployed function for its definitions (runr.workflows.json of the
// latest version when the function cannot answer).
func (c *Client) SyncWorkflows(ctx context.Context, projectID string) (*WorkflowsResult, error) {
	var out WorkflowsResult
	if err := c.do(ctx, request{method: http.MethodPost, path: workflowsPath(projectID) + "/sync", body: wfBody(ctx, nil)}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeclareWorkflows sets a project's definitions through the API. defs is the list of
// definitions (docs/workflows.md § Options); workflows not in it are archived. A definition
// declared unchanged keeps its source (e.g. sdk: what the function's SDK reported).
func (c *Client) DeclareWorkflows(ctx context.Context, projectID string, defs []map[string]any) (*WorkflowsResult, error) {
	if defs == nil {
		defs = []map[string]any{}
	}
	body := wfBody(ctx, map[string]any{"workflows": defs})
	var out WorkflowsResult
	if err := c.do(ctx, request{method: http.MethodPut, path: workflowsPath(projectID), body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DeclareWorkflow declares one definition through the API (def: its fields, as
// Workflow.Definition() gives them; its name, if any, must be name): added or replaced, the
// project's other definitions stay as they are — their sources too. An API before 0.6.0 has no
// such endpoint: ErrUnsupported (DeclareWorkflows declares the whole set there).
func (c *Client) DeclareWorkflow(ctx context.Context, projectID, name string, def map[string]any) (*Workflow, error) {
	fields := make(map[string]any, len(def)+1)
	for k, v := range def {
		fields[k] = v
	}
	var out struct {
		Data Workflow `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPut, path: workflowsPath(projectID) + "/" + seg(name), body: wfBody(ctx, fields)}, &out)
	if err != nil {
		// an earlier API: GET and PATCH of the path only (405), or none (404)
		if IsNotFound(err) || statusIs(err, http.StatusMethodNotAllowed) {
			return nil, &unsupportedError{err: err}
		}
		return nil, err
	}
	return &out.Data, nil
}

// ArchiveWorkflow archives one definition (its runs stay, it starts no new runs); the others
// stay as they are. A definition that does not exist is a *NotFoundError; an API before 0.6.0
// has no such endpoint: ErrUnsupported (DeclareWorkflows without it archives it there).
func (c *Client) ArchiveWorkflow(ctx context.Context, projectID, name string) (*Workflow, error) {
	var out struct {
		Data Workflow `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: workflowsPath(projectID) + "/" + seg(name), query: wfQuery(ctx, nil)}, &out)
	if err != nil {
		var ae *APIError
		switch {
		case errors.As(err, &ae) && ae.StatusCode == http.StatusNotFound && ae.Code == "workflow_not_found":
			return nil, &NotFoundError{Kind: "workflow", Ref: name}
		case IsNotFound(err) || statusIs(err, http.StatusMethodNotAllowed):
			// an earlier API (405: the path has GET and PATCH only), or the project is gone
			return nil, &unsupportedError{err: err}
		}
		return nil, err
	}
	return &out.Data, nil
}

// Definition returns the definition of w in the form DeclareWorkflows sends: declaring it
// again keeps the workflow as it is.
func (w *Workflow) Definition() map[string]any {
	def := map[string]any{"name": w.Name}
	if w.Description != "" {
		def["description"] = w.Description
	}
	triggers := make([]map[string]any, 0, len(w.Triggers))
	for _, t := range w.Triggers {
		triggers = append(triggers, t.definition())
	}
	def["triggers"] = triggers
	def["retries"] = w.Retries
	if w.Timeout != "" {
		def["timeout"] = w.Timeout
	}
	if len(w.Concurrency) > 0 {
		limits := make([]map[string]any, 0, len(w.Concurrency))
		for _, l := range w.Concurrency {
			m := map[string]any{"limit": l.Limit}
			if l.Key != "" {
				m["key"] = l.Key
			}
			limits = append(limits, m)
		}
		def["concurrency"] = limits
	}
	if w.Idempotency != "" {
		def["idempotency"] = w.Idempotency
	}
	if len(w.CancelOn) > 0 {
		cancel := make([]map[string]any, 0, len(w.CancelOn))
		for _, co := range w.CancelOn {
			m := map[string]any{"event": co.Event}
			if cond := co.Condition(); cond != "" {
				// The API stores match / if as one expression; it accepts it back as "if".
				m["if"] = cond
			}
			cancel = append(cancel, m)
		}
		def["cancel_on"] = cancel
	}
	return def
}

func (t WorkflowTrigger) definition() map[string]any {
	switch t.Kind() {
	case "cron":
		m := map[string]any{"cron": t.Cron}
		if t.Timezone != "" {
			m["timezone"] = t.Timezone
		}
		return m
	case "queue":
		m := map[string]any{"queue": t.Stream()}
		if t.If != "" {
			m["if"] = t.If
		}
		return m
	}
	m := map[string]any{"event": t.Event}
	if t.If != "" {
		m["if"] = t.If
	}
	return m
}

// ---------------------------------------------------------------------------------------
// Runs

// RunFilter selects runs of a project (all filters optional).
type RunFilter struct {
	// Workflow limits the list to one workflow (by name).
	Workflow string
	// Status is one or more run statuses.
	Status []string
	// Source is api, event, cron, queue, invoke, replay or test.
	Source string
	// Query matches a run id, event id, idempotency key or text of the event.
	Query string
	// From and To bound the creation time (ISO 8601 dates or times).
	From, To string
	// PerPage is the page size (1-100, the API's default 25).
	PerPage int
}

func (f RunFilter) values() url.Values {
	q := url.Values{}
	if len(f.Status) > 0 {
		q.Set("status", strings.Join(f.Status, ","))
	}
	if f.Source != "" {
		q.Set("source", f.Source)
	}
	if f.Query != "" {
		q.Set("q", f.Query)
	}
	if f.From != "" {
		q.Set("from", f.From)
	}
	if f.To != "" {
		q.Set("to", f.To)
	}
	if f.PerPage > 0 {
		q.Set("per_page", strconv.Itoa(f.PerPage))
	}
	return q
}

// ListWorkflowRuns returns the newest runs of a project or of one workflow (first page).
func (c *Client) ListWorkflowRuns(ctx context.Context, projectID string, f RunFilter) ([]WorkflowRun, *Page, error) {
	path := "/projects/" + projectID + "/workflow-runs"
	if f.Workflow != "" {
		path = workflowsPath(projectID) + "/" + seg(f.Workflow) + "/runs"
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: path, query: wfQuery(ctx, f.values())}, &raw); err != nil {
		return nil, nil, err
	}
	var list []WorkflowRun
	var page Page
	if err := decodeList(raw, &list, &page); err != nil {
		return nil, nil, fmt.Errorf("decode workflow runs: %w", err)
	}
	if list == nil {
		list = []WorkflowRun{}
	}
	return list, &page, nil
}

func runPath(projectID, runID string) string {
	return "/projects/" + projectID + "/workflow-runs/" + seg(runID)
}

// GetWorkflowRun returns a run with its event, output and steps.
func (c *Client) GetWorkflowRun(ctx context.Context, projectID, runID string) (*WorkflowRun, error) {
	var out struct {
		Data WorkflowRun `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: runPath(projectID, runID)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "workflow run", Ref: runID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// StartRunInput is the body of POST /projects/{project}/workflows/{workflow}/runs.
type StartRunInput struct {
	// Data is the trigger event's data, a JSON object (nil = {}).
	Data json.RawMessage `json:"data,omitempty"`
	// IdempotencyKey deduplicates starts: a second start with the key answers the first run.
	IdempotencyKey string `json:"idempotency_key,omitempty"`
	// Test marks the run as a test run.
	Test bool `json:"test,omitempty"`
	// Environment is the environment whose workflow runs (name or id; "" = the context's,
	// WithWorkflowEnvironment, else production).
	Environment string `json:"environment,omitempty"`
}

// StartWorkflowRun starts a run of a workflow. created is false when the idempotency key
// matched an existing run, which is returned instead.
func (c *Client) StartWorkflowRun(ctx context.Context, projectID, name string, in StartRunInput) (*WorkflowRun, bool, error) {
	var out struct {
		Data    WorkflowRun `json:"data"`
		Created bool        `json:"created"`
	}
	if in.Environment == "" {
		in.Environment = WorkflowEnvironment(ctx)
	}
	err := c.do(ctx, request{method: http.MethodPost, path: workflowsPath(projectID) + "/" + seg(name) + "/runs", body: in}, &out)
	if IsNotFound(err) {
		return nil, false, &NotFoundError{Kind: "workflow", Ref: name}
	}
	if err != nil {
		return nil, false, err
	}
	return &out.Data, out.Created, nil
}

func (c *Client) runAction(ctx context.Context, projectID, runID, action string, body any) (*WorkflowRun, error) {
	var out struct {
		Data WorkflowRun `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: runPath(projectID, runID) + "/" + action, body: body}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "workflow run", Ref: runID}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// CancelWorkflowRun cancels an active run (409 when it has finished).
func (c *Client) CancelWorkflowRun(ctx context.Context, projectID, runID string) (*WorkflowRun, error) {
	return c.runAction(ctx, projectID, runID, "cancel", nil)
}

// RetryWorkflowRun runs a failed, cancelled or timed-out run again in place: completed steps
// stay, failed and unfinished steps run again.
func (c *Client) RetryWorkflowRun(ctx context.Context, projectID, runID string) (*WorkflowRun, error) {
	return c.runAction(ctx, projectID, runID, "retry", nil)
}

// ReplayWorkflowRun starts a new run with the event of a finished run; fromStep ("" = from the
// start) keeps the memoized steps before that step.
func (c *Client) ReplayWorkflowRun(ctx context.Context, projectID, runID, fromStep string) (*WorkflowRun, error) {
	var body any
	if fromStep != "" {
		body = struct {
			FromStep string `json:"from_step"`
		}{FromStep: fromStep}
	}
	return c.runAction(ctx, projectID, runID, "replay", body)
}

// WaitWorkflowRun polls a run until it ends. A run that does not complete returns a
// *FailedError with the final run, a run still active at the timeout a *TimeoutError.
func (c *Client) WaitWorkflowRun(ctx context.Context, projectID string, run *WorkflowRun, opts WaitOptions) (*WorkflowRun, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "workflow run " + run.ID
	last := ""
	cur := run
	for {
		if cur.Status != last {
			last = cur.Status
			if opts.Progress != nil {
				opts.Progress(last)
			}
		}
		if RunTerminal(cur.Status) {
			if cur.Status == RunCompleted {
				return cur, nil
			}
			return cur, &FailedError{What: "workflow run", Ref: cur.ID, Status: cur.Status, Message: cur.ErrorMessage()}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
		next, err := c.GetWorkflowRun(ctx, projectID, cur.ID)
		if err != nil {
			if ctx.Err() != nil {
				return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return cur, err
		}
		cur = next
	}
}

// ---------------------------------------------------------------------------------------
// Events

// EventInput is one event for SendEvents.
type EventInput struct {
	// Name is 1-200 letters, digits and . _ : / - (names under runr/ are reserved).
	Name string `json:"name"`
	// Data is a JSON object (nil = {}).
	Data json.RawMessage `json:"data,omitempty"`
	// ID deduplicates: an id seen before answers the original event.
	ID string `json:"id,omitempty"`
	// TS is the event time in Unix milliseconds (0 = now).
	TS int64 `json:"ts,omitempty"`
	// Environment is the name of the environment the event belongs to ("" = the context's,
	// WithWorkflowEnvironment, else production).
	Environment string `json:"environment,omitempty"`
}

// SentEvent is the effect of one sent event.
type SentEvent struct {
	ID        string   `json:"id"`
	Name      string   `json:"name"`
	Duplicate bool     `json:"duplicate"`
	Runs      []string `json:"runs"`
	Resumed   int      `json:"resumed"`
	Cancelled int      `json:"cancelled"`
}

// SendEventsResult is the answer of POST /events.
type SendEventsResult struct {
	IDs    []string    `json:"ids"`
	Events []SentEvent `json:"events"`
}

// SendEvents sends events of the organization (permission events.send): every workflow with
// a matching event trigger starts a run, waiting steps resume, cancel_on cancels runs. test
// marks them as test events.
func (c *Client) SendEvents(ctx context.Context, events []EventInput, test bool) (*SendEventsResult, error) {
	if len(events) == 0 {
		return nil, fmt.Errorf("no event to send")
	}
	if env := WorkflowEnvironment(ctx); env != "" {
		withEnv := make([]EventInput, len(events))
		for i, e := range events {
			if e.Environment == "" {
				e.Environment = env
			}
			withEnv[i] = e
		}
		events = withEnv
	}
	var body any = events[0]
	if len(events) > 1 {
		body = struct {
			Events []EventInput `json:"events"`
		}{Events: events}
	}
	var q url.Values
	if test {
		q = url.Values{"test": {"1"}}
	}
	var out SendEventsResult
	if err := c.do(ctx, request{method: http.MethodPost, path: "/events", query: q, body: body}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// EventFilter selects received events (all filters optional).
type EventFilter struct {
	// Name is a name prefix.
	Name string
	// Source is api, workflow, queue, cron or test.
	Source    string
	ProjectID string
	From, To  string
	PerPage   int
}

// ListEvents returns the newest events of the organization (first page).
func (c *Client) ListEvents(ctx context.Context, f EventFilter) ([]WorkflowEvent, *Page, error) {
	q := url.Values{}
	for k, v := range map[string]string{"name": f.Name, "source": f.Source, "project_id": f.ProjectID, "from": f.From, "to": f.To} {
		if v != "" {
			q.Set(k, v)
		}
	}
	if f.PerPage > 0 {
		q.Set("per_page", strconv.Itoa(f.PerPage))
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/events", query: wfQuery(ctx, q)}, &raw); err != nil {
		return nil, nil, err
	}
	var list []WorkflowEvent
	var page Page
	if err := decodeList(raw, &list, &page); err != nil {
		return nil, nil, fmt.Errorf("decode events: %w", err)
	}
	if list == nil {
		list = []WorkflowEvent{}
	}
	return list, &page, nil
}

// GetEvent returns an event and the runs it started.
func (c *Client) GetEvent(ctx context.Context, id string) (*WorkflowEvent, []WorkflowRun, error) {
	var out struct {
		Data WorkflowEvent `json:"data"`
		Runs []WorkflowRun `json:"runs"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/events/" + seg(id)}, &out)
	if IsNotFound(err) {
		return nil, nil, &NotFoundError{Kind: "event", Ref: id}
	}
	if err != nil {
		return nil, nil, err
	}
	if out.Runs == nil {
		out.Runs = []WorkflowRun{}
	}
	return &out.Data, out.Runs, nil
}
