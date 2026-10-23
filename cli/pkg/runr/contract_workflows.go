package runr

// Durable workflows (routes/workflows.php, docs/workflows.md): definitions, runs and events.

var workflowFields = []string{
	"id", "project_id", "name", "description", "triggers", "retries", "timeout", "concurrency",
	"idempotency", "cancel_on", "is_active", "archived", "archived_at", "source", "synced_at",
	"run_counts", "last_run_at", "created_at",
}

var workflowAppFields = []string{
	"project_id", "trigger_type", "is_workflow_project", "endpoint_url", "endpoint_override",
	"key_fingerprint", "sync_status", "sync_error", "last_synced_at",
}

// workflowAppDetailFields are those of GET /projects/{project}/workflows/app.
var workflowAppDetailFields = concat(workflowAppFields, []string{"previous_key_expires_at", "sdk",
	"queue_bridge.enabled", "queue_bridge.stream", "queue_bridge.event", "queue_bridge.has_redis"})

var workflowUsageFields = []string{"from", "to", "totals.step_executions", "totals.runs_started",
	"daily[].date", "daily[].step_executions", "daily[].runs_started", "by_workflow[].workflow_id",
	"by_workflow[].workflow", "by_workflow[].project_id", "by_workflow[].step_executions", "by_workflow[].runs_started"}

var workflowUsageSources = []string{"app/Workflows/Http/Controllers/WorkflowUsageController.php"}

var workflowRunFields = []string{
	"id", "workflow", "project_id", "status", "trigger_source", "event_id", "event_name", "error",
	"attempt", "retry_count", "idempotency_key", "concurrency_key", "parent_run_id",
	"replay_of_run_id", "replay_from_step", "started_by", "next_attempt_at", "timeout_at",
	"started_at", "ended_at", "duration_ms", "steps_count", "created_at",
}

// workflowRunDetailFields are sent by the detail view (GET …/workflow-runs/{run}).
var workflowRunDetailFields = concat(workflowRunFields, []string{"event", "output", "children",
	"steps[].id", "steps[].step_key", "steps[].name", "steps[].type", "steps[].status", "steps[].position",
	"steps[].attempt", "steps[].max_attempts", "steps[].input", "steps[].output", "steps[].error",
	"steps[].attempts", "steps[].wake_at", "steps[].timeout_at", "steps[].wait_event",
	"steps[].wait_expression", "steps[].child_run_id", "steps[].started_at", "steps[].ended_at",
	"steps[].duration_ms"})

var workflowEventFields = []string{
	"id", "name", "data", "external_id", "source", "project_id", "sent_by", "ts", "runs_started",
	"waits_resumed", "runs_cancelled", "created_at",
}

var workflowPageFields = []string{"meta.current_page", "meta.last_page", "meta.per_page", "meta.total"}

var workflowRunFilterQuery = []string{"status", "source", "q", "from", "to", "per_page", "environment"}

// workflowEnvironment is the environment a workflow call addresses (docs/workflows.md#environments):
// a query parameter of a GET, a body field otherwise.
var workflowEnvironment = []string{"environment"}

// workflowListingSources define the listing answered by sync and declare (an array the
// generator types as a string).
var workflowListingSources = []string{
	"app/Workflows/Http/Controllers/WorkflowController.php",
	"app/Workflows/Http/Resources/WorkflowResource.php",
	"app/Workflows/Http/Resources/WorkflowAppResource.php",
}

func init() {
	Contract = append(Contract,
		// Definitions
		Endpoint{Method: "GET", Path: "/projects/{project}/workflows", Status: 200, Permission: "workflows.view", Optional: true,
			Query: workflowEnvironment, Response: concat(prefix("data[].", workflowFields), prefix("app.", workflowAppFields))},
		Endpoint{Method: "PUT", Path: "/projects/{project}/workflows", Status: 200, Permission: "workflows.manage", Optional: true,
			Request: []string{"workflows", "environment"}, FreeForm: []string{"workflows"},
			Response: concat([]string{"message"}, prefix("data[].", workflowFields), prefix("app.", workflowAppFields)),
			Sources:  workflowListingSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/workflows/sync", Status: 200, Permission: "workflows.manage", Optional: true,
			Request:  workflowEnvironment,
			Response: concat([]string{"message", "source"}, prefix("data[].", workflowFields), prefix("app.", workflowAppFields)),
			Sources:  workflowListingSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/workflows/{workflow}", Status: 200, Permission: "workflows.view", Optional: true,
			Query: workflowEnvironment, Response: prefix("data.", workflowFields)},
		Endpoint{Method: "PATCH", Path: "/projects/{project}/workflows/{workflow}", Status: 200, Permission: "workflows.manage", Optional: true,
			Request: []string{"is_active", "environment"}, Response: prefix("data.", workflowFields)},
		// One definition, declared (added or replaced) or archived; the others stay as they are
		// (runr_workflow, runr_workflow_trigger). Newer than the set: an earlier API answers 405.
		Endpoint{Method: "PUT", Path: "/projects/{project}/workflows/{workflow}", Status: 200, Permission: "workflows.manage", Optional: true,
			Request:  []string{"name", "description", "triggers", "retries", "timeout", "concurrency", "idempotency", "cancel_on", "environment"},
			FreeForm: []string{"triggers", "concurrency", "cancel_on"},
			Response: concat([]string{"message"}, prefix("data.", workflowFields)), Sources: workflowListingSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/workflows/{workflow}", Status: 200, Permission: "workflows.manage", Optional: true,
			Query: workflowEnvironment, Response: concat([]string{"message"}, prefix("data.", workflowFields)), Sources: workflowListingSources},

		// The workflow app: endpoint override and signing key (the key itself only from
		// signing-key, whose bodies stay out of --debug traces).
		Endpoint{Method: "GET", Path: "/projects/{project}/workflows/app", Status: 200, Permission: "workflows.view", Optional: true,
			Query: workflowEnvironment, Response: prefix("data.", workflowAppDetailFields)},
		Endpoint{Method: "PATCH", Path: "/projects/{project}/workflows/app", Status: 200, Permission: "workflows.manage", Optional: true,
			Request: []string{"endpoint_url", "environment"}, Response: prefix("data.", workflowAppDetailFields)},
		Endpoint{Method: "POST", Path: "/projects/{project}/workflows/app/rotate-key", Status: 200, Permission: "workflows.manage",
			Optional: true, Request: workflowEnvironment, Response: concat([]string{"message"}, prefix("data.", workflowAppDetailFields))},
		Endpoint{Method: "GET", Path: "/projects/{project}/workflows/app/signing-key", Status: 200, Permission: "workflows.manage",
			Optional: true, Query: workflowEnvironment, Response: []string{"signing_key", "env", "fingerprint"}},

		// Metering: step executions and started runs per day and per workflow.
		Endpoint{Method: "GET", Path: "/workflow-usage", Status: 200, Permission: "workflows.view", Optional: true,
			Query: []string{"from", "to"}, Response: workflowUsageFields, Sources: workflowUsageSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/workflow-usage", Status: 200, Permission: "workflows.view", Optional: true,
			Query: []string{"from", "to", "environment"}, Response: workflowUsageFields, Sources: workflowUsageSources},

		// Runs
		Endpoint{Method: "GET", Path: "/projects/{project}/workflows/{workflow}/runs", Status: 200, Permission: "workflows.view", Optional: true,
			Query: workflowRunFilterQuery, Response: concat(prefix("data[].", workflowRunFields), workflowPageFields)},
		Endpoint{Method: "POST", Path: "/projects/{project}/workflows/{workflow}/runs", Status: 200, Permission: "workflows.run", Optional: true,
			Request: []string{"data", "idempotency_key", "test", "environment"}, FreeForm: []string{"data"},
			// 201 for a new run, 200 for the run an idempotency key matched; the generator
			// documents the answer as untyped.
			Response: concat([]string{"created"}, prefix("data.", workflowRunFields)),
			Sources: []string{"app/Workflows/Http/Controllers/WorkflowRunController.php",
				"app/Workflows/Http/Resources/WorkflowRunResource.php"}},
		Endpoint{Method: "GET", Path: "/projects/{project}/workflow-runs", Status: 200, Permission: "workflows.view", Optional: true,
			Query: workflowRunFilterQuery, Response: concat(prefix("data[].", workflowRunFields), workflowPageFields)},
		Endpoint{Method: "GET", Path: "/projects/{project}/workflow-runs/{run}", Status: 200, Permission: "workflows.view", Optional: true,
			Response: prefix("data.", workflowRunDetailFields)},
		Endpoint{Method: "POST", Path: "/projects/{project}/workflow-runs/{run}/cancel", Status: 200, Permission: "workflows.run", Optional: true,
			Response: prefix("data.", workflowRunFields)},
		Endpoint{Method: "POST", Path: "/projects/{project}/workflow-runs/{run}/retry", Status: 200, Permission: "workflows.run", Optional: true,
			Response: prefix("data.", workflowRunFields)},
		Endpoint{Method: "POST", Path: "/projects/{project}/workflow-runs/{run}/replay", Status: 201, Permission: "workflows.run", Optional: true,
			Request: []string{"from_step"}, Response: prefix("data.", workflowRunFields)},

		// Events (organization level). POST reads the raw body: one event, or {"events": […]}.
		Endpoint{Method: "POST", Path: "/events", Status: 200, Permission: "events.send", Optional: true,
			Query: []string{"test"},
			Request: []string{"name", "data", "id", "ts", "environment", "events[].name", "events[].data", "events[].id", "events[].ts",
				"events[].environment"},
			FreeForm: []string{"data", "events[].data"},
			Response: []string{"ids", "events[].id", "events[].name", "events[].duplicate", "events[].runs",
				"events[].resumed", "events[].cancelled"},
			Sources: []string{"app/Workflows/Http/Controllers/WorkflowEventController.php",
				"app/Workflows/Engine/EventIngestor.php"}},
		Endpoint{Method: "GET", Path: "/events", Status: 200, Permission: "workflows.view", Optional: true,
			Query:    []string{"name", "source", "project_id", "environment", "from", "to", "per_page"},
			Response: concat(prefix("data[].", workflowEventFields), workflowPageFields)},
		Endpoint{Method: "GET", Path: "/events/{event}", Status: 200, Permission: "workflows.view", Optional: true,
			Response: concat(prefix("data.", workflowEventFields), prefix("runs[].", workflowRunFields))},
	)
}
