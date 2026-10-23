package runr_test

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func wfSetup(t *testing.T) (*runrtest.Server, *runr.Client, runr.Project) {
	t.Helper()
	srv := runrtest.New(t)
	p := srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	srv.SeedWorkflow("flows", "process-order", runr.WorkflowTrigger{Event: "order.created"})
	return srv, client(t, srv), p
}

func TestWorkflowTriggerKinds(t *testing.T) {
	cases := []struct {
		t          runr.WorkflowTrigger
		kind, text string
	}{
		{runr.WorkflowTrigger{Event: "order.created"}, "event", "event order.created"},
		{runr.WorkflowTrigger{Event: "order.created", If: "event.data.total > 0"}, "event", "event order.created if event.data.total > 0"},
		{runr.WorkflowTrigger{Cron: "0 9 * * *", Timezone: "Europe/Istanbul"}, "cron", "cron 0 9 * * * (Europe/Istanbul)"},
		{runr.WorkflowTrigger{Queue: "orders"}, "queue", "queue orders"},
		{runr.WorkflowTrigger{Event: "queue/orders"}, "queue", "queue orders"},
	}
	for _, c := range cases {
		if c.t.Kind() != c.kind || c.t.String() != c.text {
			t.Errorf("%+v: kind %s, text %q", c.t, c.t.Kind(), c.t.String())
		}
	}
	if !(runr.WorkflowTrigger{Queue: "orders"}).Same(runr.WorkflowTrigger{Event: "queue/orders"}) {
		t.Error("a queue trigger differs from its event form")
	}
	if (runr.WorkflowTrigger{Cron: "0 9 * * *"}).Same(runr.WorkflowTrigger{Cron: "0 9 * * *", Timezone: "UTC"}) {
		t.Error("cron triggers with different time zones are the same")
	}
	for _, s := range []string{runr.RunCompleted, runr.RunFailed, runr.RunCancelled, runr.RunTimedOut} {
		if !runr.RunTerminal(s) {
			t.Errorf("%s is not terminal", s)
		}
	}
	if runr.RunTerminal(runr.RunSleeping) {
		t.Error("sleeping is terminal")
	}
}

// Definition gives back what the API accepts: cancel_on expressions as "if", queue triggers
// as "queue", keyed concurrency limits.
func TestWorkflowDefinition(t *testing.T) {
	w := runr.Workflow{Name: "process-order", Description: "Orders", Retries: 4, Timeout: "1d",
		Triggers:    []runr.WorkflowTrigger{{Event: "order.created", If: "event.data.paid == true"}, {Event: "queue/orders"}, {Cron: "0 9 * * *"}},
		Concurrency: []runr.WorkflowConcurrency{{Limit: 5}, {Limit: 1, Key: "event.data.customer_id"}},
		Idempotency: "event.data.order_id",
		CancelOn:    []runr.WorkflowCancelOn{{Event: "order.cancelled", Expression: "event.data.order_id == async.data.order_id"}}}
	got, _ := json.Marshal(w.Definition())
	want := `{"cancel_on":[{"event":"order.cancelled","if":"event.data.order_id == async.data.order_id"}],` +
		`"concurrency":[{"limit":5},{"key":"event.data.customer_id","limit":1}],"description":"Orders",` +
		`"idempotency":"event.data.order_id","name":"process-order","retries":4,"timeout":"1d",` +
		`"triggers":[{"event":"order.created","if":"event.data.paid == true"},{"queue":"orders"},{"cron":"0 9 * * *"}]}`
	if string(got) != want {
		t.Fatalf("definition\n got %s\nwant %s", got, want)
	}
}

// The API keeps a cancel_on "if" in parentheses; declaring a definition again must not add a
// pair each time.
func TestWorkflowCancelOnRoundTrip(t *testing.T) {
	for in, want := range map[string]string{
		"(event.data.id == async.data.id)":             "event.data.id == async.data.id",
		"((a == 1))":                                   "(a == 1)",
		"(a == 1) && (b == 2)":                         "(a == 1) && (b == 2)",
		`(event.data.note == ")(")`:                    `event.data.note == ")("`,
		"event.data.id == async.data.id":               "event.data.id == async.data.id",
		"((event.x == async.x)) && (event.y == 'a)b')": "((event.x == async.x)) && (event.y == 'a)b')",
	} {
		if got := (runr.WorkflowCancelOn{Event: "e", Expression: in}).Condition(); got != want {
			t.Errorf("Condition(%q) = %q, want %q", in, got, want)
		}
	}

	srv, c, p := wfSetup(t)
	ctx := context.Background()
	def := map[string]any{"name": "orders", "cancel_on": []any{map[string]any{"event": "order.cancelled", "if": "event.data.id == async.data.id"}}}
	if _, err := c.DeclareWorkflows(ctx, p.ID, []map[string]any{def}); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 2; i++ {
		w, _ := srv.Workflow("flows", "orders")
		if w.CancelOn[0].Expression != "(event.data.id == async.data.id)" {
			t.Fatalf("round %d: stored %q", i, w.CancelOn[0].Expression)
		}
		if _, err := c.DeclareWorkflows(ctx, p.ID, []map[string]any{w.Definition()}); err != nil {
			t.Fatal(err)
		}
	}
}

// Workflows of an environment are declared, listed and paused apart from the project's own;
// its workflow app has an endpoint override of its own.
func TestWorkflowEnvironments(t *testing.T) {
	srv, c, p := wfSetup(t)
	srv.SeedEnvironment("flows", "staging", false)
	ctx := context.Background()
	staging := runr.WithWorkflowEnvironment(ctx, "staging")

	if _, err := c.DeclareWorkflows(staging, p.ID, []map[string]any{{"name": "nightly", "triggers": []any{map[string]any{"cron": "0 3 * * *"}}}}); err != nil {
		t.Fatal(err)
	}
	if _, ok := srv.Workflow("flows", "nightly"); ok {
		t.Fatal("a staging workflow is the project's own")
	}
	w, ok := srv.WorkflowIn("flows", "staging", "nightly")
	if !ok || w.Environment != "staging" || w.Source != "api" {
		t.Fatalf("staging workflow = %+v, %v", w, ok)
	}
	if own, ok := srv.Workflow("flows", "process-order"); !ok || own.Archived {
		t.Fatalf("declaring staging archived production's workflow: %+v", own)
	}
	list, app, err := c.ListWorkflows(staging, p.ID)
	if err != nil || len(list) != 1 || list[0].Name != "nightly" || list[0].Environment != "staging" || app.Environment != "staging" {
		t.Fatalf("staging list = %+v, %+v, %v", list, app, err)
	}
	if _, err := c.SetWorkflowActive(staging, p.ID, "nightly", false); err != nil {
		t.Fatal(err)
	}
	if _, err := c.GetWorkflow(ctx, p.ID, "nightly"); !errors.As(err, new(*runr.NotFoundError)) {
		t.Fatalf("production sees staging's workflow: %v", err)
	}
	if _, err := c.SetWorkflowEndpoint(staging, p.ID, "https://staging.example.com/workflows"); err != nil {
		t.Fatal(err)
	}
	if srv.WorkflowEndpointOverride("flows") != "" || srv.WorkflowEndpointOverrideIn("flows", "staging") != "https://staging.example.com/workflows" {
		t.Fatal("the endpoint override went to the wrong workflow app")
	}
	if _, _, err := c.ListWorkflows(runr.WithWorkflowEnvironment(ctx, "qa"), p.ID); !runr.IsValidation(err) {
		t.Fatalf("unknown environment: %v", err)
	}
}

func TestWorkflowRunLifecycle(t *testing.T) {
	srv, c, p := wfSetup(t)
	ctx := context.Background()
	srv.SetWorkflowRunOutcome(runr.RunCompleted, 1)

	run, created, err := c.StartWorkflowRun(ctx, p.ID, "process-order", runr.StartRunInput{Data: json.RawMessage(`{"a":{}}`), IdempotencyKey: "k1"})
	if err != nil || !created || run.Status != runr.RunQueued || run.Workflow.Name != "process-order" {
		t.Fatalf("start = %+v, %v, %v", run, created, err)
	}
	again, created, err := c.StartWorkflowRun(ctx, p.ID, "process-order", runr.StartRunInput{IdempotencyKey: "k1"})
	if err != nil || created || again.ID != run.ID {
		t.Fatalf("idempotent start = %+v, %v, %v", again, created, err)
	}

	var statuses []string
	done, err := c.WaitWorkflowRun(ctx, p.ID, run, runr.WaitOptions{Interval: time.Millisecond, Timeout: time.Second,
		Progress: func(s string) { statuses = append(statuses, s) }})
	if err != nil || done.Status != runr.RunCompleted {
		t.Fatalf("wait = %+v, %v", done, err)
	}
	if !reflect.DeepEqual(statuses, []string{"queued", "running", "completed"}) {
		t.Fatalf("progress = %v", statuses)
	}

	srv.SetWorkflowRunOutcome(runr.RunFailed, 0)
	run, _, _ = c.StartWorkflowRun(ctx, p.ID, "process-order", runr.StartRunInput{})
	_, err = c.WaitWorkflowRun(ctx, p.ID, run, runr.WaitOptions{Interval: time.Millisecond, Timeout: time.Second})
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.RunFailed || fe.Message != "StepError: step work failed: boom" {
		t.Fatalf("failed run error = %v", err)
	}

	runs, page, err := c.ListWorkflowRuns(ctx, p.ID, runr.RunFilter{Status: []string{runr.RunFailed}, PerPage: 5})
	if err != nil || len(runs) != 1 || page.Total != 1 || page.PerPage != 5 {
		t.Fatalf("list = %+v, %+v, %v", runs, page, err)
	}

	if _, err := c.GetWorkflowRun(ctx, p.ID, "01J00000000000000000000999"); !errors.As(err, new(*runr.NotFoundError)) {
		t.Fatalf("unknown run: %v", err)
	}
	if _, err := c.GetWorkflow(ctx, p.ID, "ghost"); !errors.As(err, new(*runr.NotFoundError)) {
		t.Fatalf("unknown workflow: %v", err)
	}
}

func TestSendEventsShapes(t *testing.T) {
	srv, c, _ := wfSetup(t)
	ctx := context.Background()

	one, err := c.SendEvents(ctx, []runr.EventInput{{Name: "order.created", Data: json.RawMessage(`{"order_id":"o-1"}`)}}, false)
	if err != nil || len(one.IDs) != 1 || len(one.Events[0].Runs) != 1 {
		t.Fatalf("one event = %+v, %v", one, err)
	}
	two, err := c.SendEvents(ctx, []runr.EventInput{{Name: "order.created"}, {Name: "order.paid", ID: "p-1"}}, true)
	if err != nil || len(two.Events) != 2 {
		t.Fatalf("two events = %+v, %v", two, err)
	}
	calls := srv.CallsTo("POST /events")
	if _, single := calls[0].Body["name"]; !single {
		t.Fatalf("one event is not sent as an object: %v", calls[0].Body)
	}
	if list, _ := calls[1].Body["events"].([]any); len(list) != 2 || calls[1].Query != "test=1" {
		t.Fatalf("two events body = %v, query %q", calls[1].Body, calls[1].Query)
	}

	events, page, err := c.ListEvents(ctx, runr.EventFilter{Name: "order."})
	if err != nil || len(events) != 3 || page.Total != 3 || events[0].Name != "order.paid" {
		t.Fatalf("events = %+v, %v", events, err)
	}
	ev, runs, err := c.GetEvent(ctx, one.Events[0].ID)
	if err != nil || ev.Name != "order.created" || len(runs) != 1 {
		t.Fatalf("event = %+v, %+v, %v", ev, runs, err)
	}
	if _, err := c.SendEvents(ctx, nil, false); err == nil {
		t.Fatal("sending no event succeeded")
	}
}

func TestDeclareWorkflowsKeepsDefinitions(t *testing.T) {
	srv, c, p := wfSetup(t)
	ctx := context.Background()
	list, _, err := c.ListWorkflows(ctx, p.ID)
	if err != nil || len(list) != 1 {
		t.Fatalf("list = %+v, %v", list, err)
	}
	defs := []map[string]any{list[0].Definition(), {"name": "nightly", "triggers": []any{map[string]any{"cron": "0 3 * * *"}}}}
	res, err := c.DeclareWorkflows(ctx, p.ID, defs)
	if err != nil || len(res.Data) != 2 || res.Message == "" {
		t.Fatalf("declare = %+v, %v", res, err)
	}
	// declared back unchanged: still the SDK's
	w, _ := srv.Workflow("flows", "process-order")
	if w.Archived || w.Source != "sdk" || len(w.Triggers) != 1 || w.Triggers[0].Event != "order.created" {
		t.Fatalf("process-order after declare = %+v", w)
	}
	if n, _ := srv.Workflow("flows", "nightly"); n.Source != "api" {
		t.Fatalf("nightly = %+v", n)
	}
	paused, err := c.SetWorkflowActive(ctx, p.ID, "nightly", false)
	if err != nil || paused.State() != "paused" {
		t.Fatalf("pause = %+v, %v", paused, err)
	}
	// Pausing survives a declaration.
	if _, err := c.DeclareWorkflows(ctx, p.ID, defs); err != nil {
		t.Fatal(err)
	}
	if w, _ := srv.Workflow("flows", "nightly"); w.IsActive {
		t.Fatal("declaring resumed a paused workflow")
	}
	if _, err := c.DeclareWorkflows(ctx, p.ID, nil); err != nil {
		t.Fatal(err)
	}
	if w, _ := srv.Workflow("flows", "nightly"); !w.Archived {
		t.Fatal("an empty declaration did not archive the workflows")
	}
}

// One definition is declared and archived on its own; the others stay as they are.
func TestDeclareAndArchiveOneWorkflow(t *testing.T) {
	srv, c, p := wfSetup(t)
	ctx := context.Background()

	w, err := c.DeclareWorkflow(ctx, p.ID, "nightly", map[string]any{"triggers": []any{map[string]any{"cron": "0 3 * * *"}}, "retries": 1})
	if err != nil || w.Name != "nightly" || w.Source != "api" || w.Retries != 1 || w.Triggers[0].Cron != "0 3 * * *" {
		t.Fatalf("declare = %+v, %v", w, err)
	}
	if body := srv.CallsTo("PUT /projects/{project}/workflows/{workflow}")[0].Body; body["retries"] != float64(1) || body["name"] != nil {
		t.Fatalf("body = %+v", body)
	}
	if sdk, _ := srv.Workflow("flows", "process-order"); sdk.Source != "sdk" || sdk.Archived {
		t.Fatalf("process-order = %+v", sdk)
	}
	if _, err := c.DeclareWorkflow(ctx, p.ID, "nightly", map[string]any{"name": "other"}); !runr.IsValidation(err) {
		t.Fatalf("a definition of another name: %v", err)
	}

	archived, err := c.ArchiveWorkflow(ctx, p.ID, "nightly")
	if err != nil || !archived.Archived {
		t.Fatalf("archive = %+v, %v", archived, err)
	}
	var nf *runr.NotFoundError
	if _, err := c.ArchiveWorkflow(ctx, p.ID, "missing"); !errors.As(err, &nf) {
		t.Fatalf("archive missing: %v", err)
	}

	// an environment's: the environment goes in the body of a PUT, the query of a DELETE
	srv.SeedEnvironment("flows", "staging", false)
	envCtx := runr.WithWorkflowEnvironment(ctx, "staging")
	if _, err := c.DeclareWorkflow(envCtx, p.ID, "nightly", map[string]any{}); err != nil {
		t.Fatal(err)
	}
	if w, ok := srv.WorkflowIn("flows", "staging", "nightly"); !ok || w.Archived {
		t.Fatalf("staging nightly = %+v", w)
	}
	if _, err := c.ArchiveWorkflow(envCtx, p.ID, "nightly"); err != nil {
		t.Fatal(err)
	}
	if calls := srv.CallsTo("DELETE /projects/{project}/workflows/{workflow}"); calls[len(calls)-1].Query != "environment=staging" {
		t.Fatalf("query = %q", calls[len(calls)-1].Query)
	}

	// an API before 0.6.0: ErrUnsupported, the caller declares the set
	srv.Without("PUT /projects/{project}/workflows/{workflow}", "DELETE /projects/{project}/workflows/{workflow}")
	if _, err := c.DeclareWorkflow(ctx, p.ID, "nightly", map[string]any{}); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("declare on an earlier API: %v", err)
	}
	if _, err := c.ArchiveWorkflow(ctx, p.ID, "nightly"); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("archive on an earlier API: %v", err)
	}
}
