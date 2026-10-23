package runr

// Triggers (routes/triggers.php, docs/triggers.md): a project's trigger list, the stored events
// of webhook / platform_event triggers and the organization's topics. The answers are arrays
// built by hand (TriggerPresenter, TriggerEvent::toSummaryArray, TopicController::present);
// the fields are checked against those sources.

var triggerFields = []string{
	"id", "name", "type", "label", "enabled", "position", "primary", "runs_on", "config", "secrets",
	"delivery", "retention_days", "created_at", "updated_at",
}

// triggerTypeFields are what a type adds to its triggers (WebhookTrigger::present,
// ScheduleTrigger::present).
var triggerTypeFields = []string{"url", "has_signing_secret", "effective_timezone"}

var triggerPlanFields = []string{"workload", "public", "gateway", "runtime_trigger", "requires_redis"}

var triggerSources = []string{
	"app/Triggers/TriggerPresenter.php",
	"app/Triggers/Types/WebhookTrigger.php",
	"app/Http/Controllers/Api/Triggers/ProjectTriggerController.php",
}

var triggerEventFields = []string{
	"id", "trigger_id", "kind", "type", "subject", "delivery_id", "content_type", "size", "status",
	"attempts", "replays", "last_error", "received_at", "delivered_at", "expires_at",
}

var triggerEventSources = []string{
	"app/Models/TriggerEvent.php",
	"app/Http/Controllers/Api/Triggers/TriggerEventController.php",
}

var topicFields = []string{
	"id", "name", "description", "knative_region_id", "region_name", "stream", "created_by", "created_at",
}

var topicSources = []string{"app/Http/Controllers/Api/Triggers/TopicController.php"}

func init() {
	Contract = append(Contract,
		// The trigger list (a change reaches the cluster with the next deploy)
		Endpoint{Method: "GET", Path: "/projects/{project}/triggers", Status: 200, Permission: "projects.view", Optional: true,
			Response:     concat(prefix("data[].", triggerFields), prefix("plan.", triggerPlanFields)),
			Capabilities: prefix("data[].", triggerTypeFields), Sources: triggerSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/triggers", Status: 201, Permission: "projects.update", Optional: true,
			Request:      []string{"type", "name", "enabled", "position", "config", "secrets"},
			FreeForm:     []string{"config", "secrets"},
			Response:     concat(prefix("data.", triggerFields), []string{"requires_deploy"}),
			Capabilities: prefix("data.", triggerTypeFields), Sources: triggerSources},
		Endpoint{Method: "PUT", Path: "/projects/{project}/triggers", Status: 200, Permission: "projects.update", Optional: true,
			Request:  []string{"triggers[].type", "triggers[].name", "triggers[].enabled", "triggers[].config", "triggers[].secrets"},
			FreeForm: []string{"triggers[].config", "triggers[].secrets"},
			Response: concat(prefix("data[].", triggerFields), prefix("plan.", triggerPlanFields), []string{"requires_deploy"}),
			Sources:  triggerSources},
		Endpoint{Method: "PATCH", Path: "/projects/{project}/triggers/{trigger}", Status: 200, Permission: "projects.update", Optional: true,
			Request:      []string{"name", "enabled", "position", "config", "secrets"},
			FreeForm:     []string{"config", "secrets"},
			Response:     concat(prefix("data.", triggerFields), []string{"requires_deploy"}),
			Capabilities: prefix("data.", triggerTypeFields), Sources: triggerSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/triggers/{trigger}", Status: 200, Permission: "projects.update", Optional: true,
			Response: []string{"requires_deploy"}, Sources: triggerSources},

		// Stored events of webhook / platform_event triggers and their replay
		Endpoint{Method: "GET", Path: "/projects/{project}/triggers/{trigger}/events", Status: 200, Permission: "replay.manage", Optional: true,
			Query:    []string{"status", "per_page"},
			Response: concat(prefix("data[].", triggerEventFields), workflowPageFields), Sources: triggerEventSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/triggers/{trigger}/events/{event}", Status: 200, Permission: "replay.manage", Optional: true,
			Response: prefix("data.", concat(triggerEventFields, []string{"headers", "body_base64", "body_text"})),
			Sources:  triggerEventSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/triggers/{trigger}/events/{event}/replay", Status: 200, Permission: "replay.manage", Optional: true,
			Response: prefix("data.", triggerEventFields), Sources: triggerEventSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/triggers/{trigger}/events/replay", Status: 200, Permission: "replay.manage", Optional: true,
			Request: []string{"status", "ids"}, Response: []string{"replayed"}, Sources: triggerEventSources},

		// The organization's topics; publishing is sending an event (events.send)
		Endpoint{Method: "GET", Path: "/topics", Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data[].", concat(topicFields, []string{"subscriptions"})), Sources: topicSources},
		Endpoint{Method: "POST", Path: "/topics", Status: 201, Permission: "topics.manage", Optional: true,
			Request:  []string{"name", "knative_region_id", "description"},
			Response: prefix("data.", topicFields), Sources: topicSources},
		// One topic with its subscribing triggers (a list here, a count in GET /topics)
		Endpoint{Method: "GET", Path: "/topics/{topic}", Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data.", concat(topicFields, []string{"subscriptions"})), Sources: topicSources},
		Endpoint{Method: "PATCH", Path: "/topics/{topic}", Status: 200, Permission: "topics.manage", Optional: true,
			Request: []string{"description"}, Response: prefix("data.", topicFields), Sources: topicSources},
		Endpoint{Method: "DELETE", Path: "/topics/{topic}", Status: 204, Permission: "topics.manage", Optional: true},
		Endpoint{Method: "POST", Path: "/topics/{topic}/publish", Status: 202, Permission: "events.send", Optional: true,
			Request: []string{"data", "content_type", "type", "subject", "id"}, FreeForm: []string{"data"},
			// The answer is the topic publisher's ({id, stream_id}), passed through untyped.
			Capabilities: []string{"data.id", "data.stream_id"}, Sources: topicSources},
	)
}
