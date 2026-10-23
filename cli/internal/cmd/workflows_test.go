package cmd

import (
	"encoding/json"
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// wfHarness logs in and seeds the project "flows" (trigger workflow) with the workflow
// process-order, triggered by the event order.created.
func wfHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarness(t).login()
	h.srv.SeedProject("flows", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "workflow"})
	h.srv.SeedWorkflow("flows", "process-order", runr.WorkflowTrigger{Event: "order.created"})
	return h
}

func TestWorkflowsList(t *testing.T) {
	h := wfHarness(t)
	h.srv.SeedWorkflow("flows", "nightly-report", runr.WorkflowTrigger{Cron: "0 3 * * *", Timezone: "Europe/Istanbul"})
	h.srv.SeedWorkflowRun("flows", "process-order", runr.RunCompleted)

	r := h.mustRun("workflows", "ls", "-p", "flows")
	contains(t, r.out, "process-order")
	contains(t, r.out, "event order.created")
	contains(t, r.out, "cron 0 3 * * * (Europe/Istanbul)")
	contains(t, r.out, "1 completed")

	var out struct {
		Workflows []runr.Workflow  `json:"workflows"`
		App       runr.WorkflowApp `json:"app"`
	}
	decodeJSON(t, h.mustRun("workflows", "-p", "flows", "-o", "json").out, &out)
	if len(out.Workflows) != 2 || out.Workflows[0].Name != "nightly-report" || !out.App.IsWorkflowProject {
		t.Fatalf("workflows json = %+v", out)
	}
	if out.Workflows[1].RunCounts[runr.RunCompleted] != 1 || out.Workflows[1].State() != "active" {
		t.Fatalf("process-order = %+v", out.Workflows[1])
	}
}

// --env addresses the workflows of one environment of the project (docs/workflows.md#environments):
// a query parameter of every read, a body field of every change, the environment of the events sent.
func TestWorkflowsEnvironment(t *testing.T) {
	h := wfHarness(t)
	h.srv.SeedEnvironment("flows", "staging", false)
	h.srv.SeedWorkflowIn("flows", "staging", "process-order", runr.WorkflowTrigger{Event: "order.created"})

	h.mustRun("workflows", "ls", "-p", "flows", "--env", "staging")
	if q := h.srv.CallsTo("GET /projects/{project}/workflows")[0].Query; !strings.Contains(q, "environment=staging") {
		t.Fatalf("ls query = %q", q)
	}
	h.mustRun("workflows", "get", "process-order", "-p", "flows", "--env", "staging")
	if q := h.srv.CallsTo("GET /projects/{project}/workflows/{workflow}")[0].Query; !strings.Contains(q, "environment=staging") {
		t.Fatalf("get query = %q", q)
	}
	h.mustRun("workflows", "runs", "-p", "flows", "--env", "staging")
	if q := h.srv.CallsTo("GET /projects/{project}/workflow-runs")[0].Query; !strings.Contains(q, "environment=staging") {
		t.Fatalf("runs query = %q", q)
	}

	h.mustRun("workflows", "sync", "-p", "flows", "--env", "staging")
	if body := h.srv.CallsTo("POST /projects/{project}/workflows/sync")[0].Body; body["environment"] != "staging" {
		t.Fatalf("sync body = %v", body)
	}
	h.mustRun("workflows", "run", "process-order", "-p", "flows", "--env", "staging")
	if body := h.srv.CallsTo("POST /projects/{project}/workflows/{workflow}/runs")[0].Body; body["environment"] != "staging" {
		t.Fatalf("run body = %v", body)
	}
	h.mustRun("workflows", "disable", "process-order", "-p", "flows", "--env", "staging")
	if body := h.srv.CallsTo("PATCH /projects/{project}/workflows/{workflow}")[0].Body; body["environment"] != "staging" || body["is_active"] != false {
		t.Fatalf("disable body = %v", body)
	}
	if staging, _ := h.srv.WorkflowIn("flows", "staging", "process-order"); staging.IsActive {
		t.Fatal("disable --env staging did not pause staging's workflow")
	}
	if own, _ := h.srv.Workflow("flows", "process-order"); !own.IsActive {
		t.Fatal("disable --env staging paused production's workflow")
	}
	h.mustRun("workflows", "events", "send", "order.created", "--env", "staging")
	if body := h.srv.CallsTo("POST /events")[0].Body; body["environment"] != "staging" || body["name"] != "order.created" {
		t.Fatalf("event body = %v", body)
	}
	h.mustRun("workflows", "app", "-p", "flows", "--env", "staging")
	if q := h.srv.CallsTo("GET /projects/{project}/workflows/app")[0].Query; !strings.Contains(q, "environment=staging") {
		t.Fatalf("app query = %q", q)
	}

	// Without --env: production, nothing sent
	h.srv.ResetCalls()
	h.mustRun("workflows", "sync", "-p", "flows")
	if body := h.srv.CallsTo("POST /projects/{project}/workflows/sync")[0].Body; body["environment"] != nil {
		t.Fatalf("production sync body = %v", body)
	}
	h.mustRun("workflows", "ls", "-p", "flows")
	if q := h.srv.CallsTo("GET /projects/{project}/workflows")[0].Query; strings.Contains(q, "environment") {
		t.Fatalf("production ls query = %q", q)
	}
}

func TestWorkflowsGetPauseResume(t *testing.T) {
	h := wfHarness(t)
	r := h.mustRun("workflows", "get", "process-order", "-p", "flows")
	contains(t, r.out, "Triggers:")
	contains(t, r.out, "event order.created")
	contains(t, r.out, "3 per step")

	h.mustRun("workflows", "disable", "process-order", "-p", "flows")
	if w, _ := h.srv.Workflow("flows", "process-order"); w.IsActive {
		t.Fatal("disable did not pause the workflow")
	}
	if body := h.srv.CallsTo("PATCH /projects/{project}/workflows/{workflow}")[0].Body; body["is_active"] != false {
		t.Fatalf("patch body = %v", body)
	}
	// A paused workflow starts no runs: 409, exit code 5.
	r = h.expectCode(ExitValidation, "workflows", "run", "process-order", "-p", "flows")
	contains(t, r.err, "paused")

	h.mustRun("workflows", "pause", "process-order", "-p", "flows")
	h.mustRun("workflows", "resume", "process-order", "-p", "flows")
	if w, _ := h.srv.Workflow("flows", "process-order"); !w.IsActive {
		t.Fatal("resume did not activate the workflow")
	}

	// By id as well as by name.
	w, _ := h.srv.Workflow("flows", "process-order")
	contains(t, h.mustRun("workflows", "get", w.ID, "-p", "flows").out, "process-order")

	h.expectCode(ExitNotFound, "workflows", "get", "ghost", "-p", "flows")
}

func TestWorkflowsRunAndWait(t *testing.T) {
	h := wfHarness(t)
	h.srv.SetWorkflowRunOutcome(runr.RunCompleted, 2)

	var run runr.WorkflowRun
	r := h.mustRun("workflows", "run", "process-order", "-p", "flows", "--event", `{"order_id":"o-1"}`, "--wait", "-o", "json")
	decodeJSON(t, r.out, &run)
	if run.Status != runr.RunCompleted || wfCompact(run.Output) != `{"ok":true}` || len(run.Steps) != 1 {
		t.Fatalf("run = %+v", run)
	}
	start := h.srv.CallsTo("POST /projects/{project}/workflows/{workflow}/runs")
	if len(start) != 1 {
		t.Fatalf("%d starts", len(start))
	}
	data, _ := json.Marshal(start[0].Body["data"])
	if string(data) != `{"order_id":"o-1"}` {
		t.Fatalf("start data = %s", data)
	}
	if polls := h.srv.CallsTo("GET /projects/{project}/workflow-runs/{run}"); len(polls) != 3 {
		t.Fatalf("%d polls, want 3 (2 running + the final one)", len(polls))
	}
	contains(t, r.err, "Started run "+run.ID)

	// Without --wait the run is printed as started.
	r = h.mustRun("workflows", "run", "process-order", "-p", "flows")
	contains(t, r.out, "queued")
}

func TestWorkflowsRunWaitFailsAndTimesOut(t *testing.T) {
	h := wfHarness(t)
	h.srv.SetWorkflowRunOutcome(runr.RunFailed, 0)
	r := h.expectCode(ExitFailed, "workflows", "run", "process-order", "-p", "flows", "--wait")
	contains(t, r.out, "Status:")
	contains(t, r.out, "failed")
	contains(t, r.err, "step work failed: boom")

	h.srv.SetWorkflowRunOutcome(runr.RunCompleted, 100000)
	r = h.expectCode(ExitTimeout, "workflows", "run", "process-order", "-p", "flows", "--wait", "--timeout", "30ms")
	contains(t, r.err, "timed out")
}

func TestWorkflowsRunEventInput(t *testing.T) {
	h := wfHarness(t)
	dir := t.TempDir()
	path := filepath.Join(dir, "order.json")
	write(t, path, `{"order_id": "o-9", "items": [{"sku": "a"}]}`)
	h.mustRun("workflows", "run", "process-order", "-p", "flows", "--event", "@"+path)
	body := h.srv.CallsTo("POST /projects/{project}/workflows/{workflow}/runs")[0].Body
	if d, _ := body["data"].(map[string]any); d["order_id"] != "o-9" {
		t.Fatalf("data from file = %v", body["data"])
	}

	h.runStdin(`{"from":"stdin"}`, "workflows", "run", "process-order", "-p", "flows", "--event", "-")
	body = h.srv.CallsTo("POST /projects/{project}/workflows/{workflow}/runs")[1].Body
	if d, _ := body["data"].(map[string]any); d["from"] != "stdin" {
		t.Fatalf("data from stdin = %v", body["data"])
	}

	h.expectCode(ExitUsage, "workflows", "run", "process-order", "-p", "flows", "--event", `[1, 2]`)
	h.expectCode(ExitUsage, "workflows", "run", "process-order", "-p", "flows", "--event", `{not json`)
	h.expectCode(ExitNotFound, "workflows", "run", "ghost", "-p", "flows")
}

func TestWorkflowsRunIdempotencyAndTest(t *testing.T) {
	h := wfHarness(t)
	h.mustRun("workflows", "run", "process-order", "-p", "flows", "--idempotency-key", "order-o-1", "--test")
	r := h.mustRun("workflows", "run", "process-order", "-p", "flows", "--idempotency-key", "order-o-1")
	contains(t, r.err, "already exists")
	runs := h.srv.WorkflowRuns("flows")
	if len(runs) != 1 || runs[0].TriggerSource != "test" || runs[0].IdempotencyKey != "order-o-1" {
		t.Fatalf("runs = %+v", runs)
	}
	if body := h.srv.CallsTo("POST /projects/{project}/workflows/{workflow}/runs")[0].Body; body["test"] != true {
		t.Fatalf("start body = %v", body)
	}
}

func TestWorkflowsRunsList(t *testing.T) {
	h := wfHarness(t)
	h.srv.SeedWorkflow("flows", "nightly-report")
	failed := h.srv.SeedWorkflowRun("flows", "process-order", runr.RunFailed)
	h.srv.SeedWorkflowRun("flows", "process-order", runr.RunCompleted)
	h.srv.SeedWorkflowRun("flows", "nightly-report", runr.RunCompleted)

	r := h.mustRun("workflows", "runs", "-p", "flows")
	contains(t, r.out, failed.ID)
	contains(t, r.out, "nightly-report")
	if calls := h.srv.CallsTo("GET /projects/{project}/workflow-runs"); len(calls) != 1 || calls[0].Query != "per_page=25" {
		t.Fatalf("list calls = %+v", calls)
	}

	var runs []runr.WorkflowRun
	decodeJSON(t, h.mustRun("workflows", "runs", "process-order", "--status", "failed,timed_out", "--limit", "10", "-p", "flows", "-o", "json").out, &runs)
	if len(runs) != 1 || runs[0].ID != failed.ID || runs[0].ErrorMessage() != "StepError: step ship failed: carrier unavailable" {
		t.Fatalf("filtered runs = %+v", runs)
	}
	calls := h.srv.CallsTo("GET /projects/{project}/workflows/{workflow}/runs")
	if len(calls) != 1 || calls[0].Path != "/projects/"+runs[0].ProjectID+"/workflows/process-order/runs" ||
		!strings.Contains(calls[0].Query, "status=failed%2Ctimed_out") || !strings.Contains(calls[0].Query, "per_page=10") {
		t.Fatalf("workflow runs calls = %+v", calls)
	}

	h.mustRun("workflows", "runs", "ls", "--source", "api", "--since", "1h", "-p", "flows")
	q := h.srv.CallsTo("GET /projects/{project}/workflow-runs")[1].Query
	if !strings.Contains(q, "source=api") || !strings.Contains(q, "from=") {
		t.Fatalf("query = %s", q)
	}
	h.expectCode(ExitUsage, "workflows", "runs", "--limit", "500", "-p", "flows")
	h.expectCode(ExitUsage, "workflows", "runs", "--since", "yesterday", "-p", "flows")
}

func TestWorkflowsRunControl(t *testing.T) {
	h := wfHarness(t)
	failed := h.srv.SeedWorkflowRun("flows", "process-order", runr.RunFailed)

	// get: steps and the error; both spellings.
	r := h.mustRun("workflows", "run", "get", failed.ID, "-p", "flows")
	contains(t, r.out, "carrier unavailable")
	contains(t, r.out, "ship")
	var detail runr.WorkflowRun
	decodeJSON(t, h.mustRun("workflows", "runs", "get", failed.ID, "-p", "flows", "-o", "json").out, &detail)
	if len(detail.Steps) != 2 || detail.Steps[1].StepKey != "ship" || len(detail.Event) == 0 {
		t.Fatalf("detail = %+v", detail)
	}

	// retry: in place, back to queued.
	var retried runr.WorkflowRun
	decodeJSON(t, h.mustRun("workflows", "run", "retry", failed.ID, "-p", "flows", "-o", "json").out, &retried)
	if retried.ID != failed.ID || retried.Status != runr.RunQueued || retried.RetryCount != 1 {
		t.Fatalf("retried = %+v", retried)
	}

	// cancel: needs --yes without a terminal; a finished run answers 409 (exit 5).
	h.expectCode(ExitUsage, "workflows", "run", "cancel", failed.ID, "-p", "flows")
	h.mustRun("workflows", "runs", "cancel", failed.ID, "--yes", "-p", "flows")
	if runs := h.srv.WorkflowRuns("flows"); runs[0].Status != runr.RunCancelled {
		t.Fatalf("status after cancel = %s", runs[0].Status)
	}
	r = h.expectCode(ExitValidation, "workflows", "run", "cancel", failed.ID, "--yes", "-p", "flows")
	contains(t, r.err, "already finished")

	// replay: a new run, from a step; an unknown step is refused.
	done := h.srv.SeedWorkflowRun("flows", "process-order", runr.RunCompleted)
	var replay runr.WorkflowRun
	decodeJSON(t, h.mustRun("workflows", "run", "replay", done.ID, "--from-step", "ship", "-p", "flows", "-o", "json").out, &replay)
	if replay.ID == done.ID || replay.ReplayOfRunID != done.ID || replay.ReplayFromStep != "ship" || replay.TriggerSource != "replay" {
		t.Fatalf("replay = %+v", replay)
	}
	if body := h.srv.CallsTo("POST /projects/{project}/workflow-runs/{run}/replay")[0].Body; body["from_step"] != "ship" {
		t.Fatalf("replay body = %v", body)
	}
	h.expectCode(ExitValidation, "workflows", "runs", "replay", done.ID, "--from-step", "nope", "-p", "flows")
	h.mustRun("workflows", "runs", "replay", done.ID, "--wait", "-p", "flows")

	h.expectCode(ExitNotFound, "workflows", "run", "get", "01J00000000000000000000999", "-p", "flows")
}

func TestWorkflowEvents(t *testing.T) {
	h := wfHarness(t)
	var res runr.SendEventsResult
	decodeJSON(t, h.mustRun("workflows", "events", "send", "order.created", "--data", `{"order_id":"o-2"}`, "--id", "order-o-2", "-o", "json").out, &res)
	if len(res.Events) != 1 || len(res.Events[0].Runs) != 1 || res.Events[0].Duplicate {
		t.Fatalf("send = %+v", res)
	}
	runs := h.srv.WorkflowRuns("flows")
	if len(runs) != 1 || runs[0].TriggerSource != "event" || runs[0].EventID != res.Events[0].ID {
		t.Fatalf("runs started by the event = %+v", runs)
	}
	body := h.srv.CallsTo("POST /events")[0].Body
	if body["name"] != "order.created" || body["id"] != "order-o-2" {
		t.Fatalf("event body = %v", body)
	}

	// The same id again: the original event, no new run.
	r := h.mustRun("workflows", "events", "send", "order.created", "--id", "order-o-2")
	contains(t, r.out, "yes")
	if len(h.srv.WorkflowRuns("flows")) != 1 {
		t.Fatal("a duplicate event started a run")
	}

	h.mustRun("workflows", "events", "send", "order.shipped", "--test")
	if q := h.srv.CallsTo("POST /events")[2].Query; q != "test=1" {
		t.Fatalf("test query = %q", q)
	}

	var events []runr.WorkflowEvent
	decodeJSON(t, h.mustRun("workflows", "events", "ls", "--name", "order.", "-o", "json").out, &events)
	if len(events) != 2 || events[0].Name != "order.shipped" || events[0].Source != "test" {
		t.Fatalf("events = %+v", events)
	}
	if q := h.srv.CallsTo("GET /events")[0].Query; !strings.Contains(q, "name=order.") {
		t.Fatalf("events query = %s", q)
	}
	h.mustRun("workflows", "events", "ls", "-p", "flows")
	p, _ := h.srv.Project("flows")
	if q := h.srv.CallsTo("GET /events")[1].Query; !strings.Contains(q, "project_id="+p.ID) {
		t.Fatalf("project filter query = %s", q)
	}

	r = h.mustRun("workflows", "events", "get", res.Events[0].ID)
	contains(t, r.out, runs[0].ID)
	contains(t, r.out, `{"order_id":"o-2"}`)

	h.expectCode(ExitValidation, "workflows", "events", "send", "runr/internal")
	h.expectCode(ExitUsage, "workflows", "events", "send", "order.created", "--data", `"text"`)
	h.expectCode(ExitNotFound, "workflows", "events", "get", "01J00000000000000000000999")
}

// Declaring the set again keeps the source of a definition whose content does not change: what
// the function's SDK reported stays sdk; a changed or new one is the API's.
func TestWorkflowsDeclareKeepsTheSourceOfUnchangedDefinitions(t *testing.T) {
	h := wfHarness(t) // process-order: reported by the SDK, triggered by order.created
	file := filepath.Join(t.TempDir(), "runr.workflows.json")
	write(t, file, `{"workflows": [
  {"name": "process-order", "triggers": [{"event": "order.created"}], "retries": 3},
  {"name": "nightly", "triggers": [{"cron": "0 3 * * *"}]}
]}`)
	h.mustRun("workflows", "declare", "-f", file, "-p", "flows")
	if w, _ := h.srv.Workflow("flows", "process-order"); w.Source != "sdk" || w.Archived {
		t.Fatalf("process-order = %+v, want it still the SDK's", w)
	}
	if w, _ := h.srv.Workflow("flows", "nightly"); w.Source != "api" {
		t.Fatalf("nightly = %+v", w)
	}

	// changed in the file: the API's from now on (until the next sync)
	write(t, file, `{"workflows": [
  {"name": "process-order", "triggers": [{"event": "order.created"}, {"event": "order.updated"}]},
  {"name": "nightly", "triggers": [{"cron": "0 3 * * *"}]}
]}`)
	h.mustRun("workflows", "declare", "-f", file, "-p", "flows")
	if w, _ := h.srv.Workflow("flows", "process-order"); w.Source != "api" {
		t.Fatalf("process-order = %+v", w)
	}
	if n := len(h.srv.CallsTo("PUT /projects/{project}/workflows")); n != 2 {
		t.Fatalf("PUT calls = %d", n)
	}
}

func TestWorkflowsDeclareAndSync(t *testing.T) {
	h := wfHarness(t)
	dir := t.TempDir()
	jsonFile := filepath.Join(dir, "runr.workflows.json")
	write(t, jsonFile, `{"workflows": [
  {"name": "nightly", "triggers": [{"cron": "0 3 * * *", "timezone": "Europe/Istanbul"}], "retries": 5},
  {"name": "orders", "triggers": [{"queue": "orders"}], "cancel_on": [{"event": "order.cancelled", "match": "data.order_id"}]}
]}`)
	// process-order is not listed: archiving it needs --yes without a terminal.
	h.expectCode(ExitUsage, "workflows", "declare", "-f", jsonFile, "-p", "flows")
	h.mustRun("workflows", "declare", "-f", jsonFile, "--yes", "-p", "flows")
	if w, _ := h.srv.Workflow("flows", "process-order"); !w.Archived {
		t.Fatal("process-order was not archived")
	}
	nightly, ok := h.srv.Workflow("flows", "nightly")
	if !ok || nightly.Source != "api" || nightly.Retries != 5 || nightly.Triggers[0].Cron != "0 3 * * *" {
		t.Fatalf("nightly = %+v", nightly)
	}
	orders, _ := h.srv.Workflow("flows", "orders")
	if orders.Triggers[0].Event != "queue/orders" || orders.CancelOn[0].Expression == "" {
		t.Fatalf("orders = %+v", orders)
	}

	yamlFile := filepath.Join(dir, "workflows.yaml")
	write(t, yamlFile, "- name: nightly\n  triggers:\n    - cron: \"0 4 * * *\"\n- name: orders\n  triggers:\n    - queue: orders\n")
	h.mustRun("workflows", "declare", "-f", yamlFile, "-p", "flows")
	if nightly, _ = h.srv.Workflow("flows", "nightly"); nightly.Triggers[0].Cron != "0 4 * * *" {
		t.Fatalf("nightly after the YAML = %+v", nightly)
	}

	bad := filepath.Join(dir, "bad.json")
	write(t, bad, `{"workflows": [{"name": "app"}]}`)
	h.expectCode(ExitValidation, "workflows", "declare", "-f", bad, "--yes", "-p", "flows")
	write(t, bad, `{"workflows": [{"triggers": []}]}`)
	h.expectCode(ExitUsage, "workflows", "declare", "-f", bad, "-p", "flows")

	r := h.mustRun("workflows", "sync", "-p", "flows")
	contains(t, r.out, "Synced from the function (SDK).")
	var res runr.WorkflowsResult
	decodeJSON(t, h.mustRun("workflows", "sync", "-p", "flows", "-o", "json").out, &res)
	if res.Source != "sdk" || res.App.SyncStatus != "ok" || len(res.Data) != 3 {
		t.Fatalf("sync = %+v", res)
	}
}

func TestWorkflowsNeedPermissions(t *testing.T) {
	h := wfHarness(t)
	h.loginWith(h.srv.ScopedToken("viewer", "workflows.view"))
	h.mustRun("workflows", "ls", "-p", "flows")
	r := h.expectCode(ExitAuth, "workflows", "run", "process-order", "-p", "flows")
	contains(t, r.err, "workflows.run")
	r = h.expectCode(ExitAuth, "workflows", "events", "send", "order.created")
	contains(t, r.err, "events.send")
	r = h.expectCode(ExitAuth, "workflows", "disable", "process-order", "-p", "flows")
	contains(t, r.err, "workflows.manage")
}
