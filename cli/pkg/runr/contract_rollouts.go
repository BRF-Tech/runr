package runr

// Deploy strategies and rollouts (rollouts.go): the strategy settings of a project, the rollout
// of a blue-green or canary deploy and its promote / abort / rollback. Deployments carry their
// rollout (deploymentFields), projects their strategy name (projectFields), and the deploy
// takes a one-off strategy (contract.go).

var deployStrategyOptionFields = []string{
	"blue_green.auto_promote", "blue_green.health_path", "blue_green.health_checks", "blue_green.keep_previous_minutes",
	"canary.steps", "canary.interval_minutes", "canary.error_rate_threshold", "canary.p95_threshold_ms",
}

var deployStrategyFields = concat([]string{"strategy", "eligible", "trigger_type", "tracing", "defaults.strategy",
	"environment", "own", "inherited", "project"},
	deployStrategyOptionFields, prefix("defaults.", deployStrategyOptionFields))

var rolloutFields = []string{
	"id", "project_id", "deployment_id", "stable_deployment_id", "environment_id", "environment", "strategy", "status", "open",
	"traffic.candidate", "traffic.stable", "revision", "stable_revision", "tag", "test_url", "previous_url", "options",
	"step.index", "step.count", "step.percent", "step.next_percent", "step.started_at", "step.ends_at",
	"health.path", "health.passes", "health.required", "health.last_status", "health.last_error", "health.checked_at",
	"health.deadline_at",
	"metrics.tracing", "metrics.source", "metrics.revision", "metrics.error_rate", "metrics.p95_ms", "metrics.volume", "metrics.window_minutes",
	"metrics.measured_at", "metrics.error_rate_threshold", "metrics.p95_threshold_ms",
	"keep_until", "promoted_at", "finished_at", "released_at", "next_check_at", "reason",
	"events[].at", "events[].type", "events[].message", "events[].traffic", "events[].actor",
	"can.promote", "can.abort", "can.rollback", "created_by", "created_at", "updated_at",
}

// rolloutSources build a rollout's JSON and its events (the generator types its nested arrays loosely).
var rolloutSources = []string{"app/Http/Resources/DeploymentRolloutResource.php", "app/Models/DeploymentRollout.php"}

// deployStrategySources build the strategy settings and validate their update.
var deployStrategySources = []string{"app/Services/Rollouts/DeployStrategies.php", "app/Services/Rollouts/DeployStrategy.php"}

func init() {
	Contract = append(Contract,
		// ?environment= one of the project's environments: its settings (the project's unless it
		// sets its own).
		Endpoint{Method: "GET", Path: "/projects/{project}/deploy-strategy", Query: []string{"environment"}, Status: 200,
			Permission: "projects.view", Response: prefix("data.", deployStrategyFields), Sources: deployStrategySources},
		// A partial update: what is sent is merged onto the stored settings (no new version); with
		// environment it becomes the environment's own (environments.manage too), reset drops it.
		Endpoint{Method: "PUT", Path: "/projects/{project}/deploy-strategy", Status: 200, Permission: "projects.update",
			Request:  concat([]string{"strategy", "environment", "reset"}, deployStrategyOptionFields),
			Response: prefix("data.", deployStrategyFields), Sources: deployStrategySources},
		// The newest rollout of the project's own workload (?environment=: of that environment);
		// data is null when it never had one.
		Endpoint{Method: "GET", Path: "/projects/{project}/rollout", Query: []string{"environment"}, Status: 200,
			Permission: "projects.view", Response: prefix("data.", rolloutFields), Sources: rolloutSources},
		// 404 when the deployment is not the new deployment of a rollout.
		Endpoint{Method: "GET", Path: "/projects/{project}/deployments/{deployment}/rollout", Status: 200,
			Permission: "projects.view", Response: prefix("data.", rolloutFields), Sources: rolloutSources},
		// 409 rollout_not_promotable / rollout_not_abortable / rollout_not_rollbackable; 502
		// rollout_traffic_failed when the cluster refused the traffic change.
		Endpoint{Method: "POST", Path: "/projects/{project}/deployments/{deployment}/rollout/promote", Status: 200,
			Permission: "projects.deploy", Response: prefix("data.", rolloutFields), Sources: rolloutSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/deployments/{deployment}/rollout/abort", Status: 200,
			Permission: "projects.deploy", Request: []string{"reason"}, Response: prefix("data.", rolloutFields), Sources: rolloutSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/deployments/{deployment}/rollout/rollback", Status: 200,
			Permission: "projects.deploy", Response: prefix("data.", rolloutFields), Sources: rolloutSources},
	)
}
