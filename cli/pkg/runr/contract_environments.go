package runr

// Environments of a project and promotion between them (docs/environments.md,
// routes/environments.php). Every endpoint is Optional: APIs before environments answer 404,
// which the client reports as ErrUnsupported. The answers are arrays built by hand: the fields
// are checked against the resource, the controllers and the models' toPublicArray.

var environmentFields = []string{
	"id", "name", "is_primary", "workload_name", "knative_region_id", "own_region", "position",
	"requires_approval", "git_branch", "auto_deploy", "inherit_secrets", "created_at", "updated_at",
	"region.id", "region.name", "overrides", "url", "default_url", "deploy_strategy",
	"scale_schedule", "scale_schedule[].cron", "scale_schedule[].min_scale", "scale_schedule_inherited", "warm_scale.applied", "warm_scale.applied_at",
	"deployment.id", "deployment.status", "deployment.config_source", "deployment.ready_at", "deployment.created_at",
	"deployment.version.id", "deployment.version.number", "deployment.version.label",
	"secrets[].name", "secrets[].scope", "secrets[].updated_at", "secrets[].version",
}

var environmentSources = []string{
	"app/Http/Resources/EnvironmentResource.php",
	"app/Models/ProjectEnvironment.php",
	"app/Models/EnvironmentSecret.php",
	"app/Http/Controllers/Api/EnvironmentController.php",
	// the rows of an environment's timed warmer (scale_schedule)
	"app/Support/Projects/ScaleSchedule.php",
}

// The body of PATCH; an environment is not renamed (UpdateEnvironment sends no name).
var environmentUpdateFields = []string{
	"knative_region_id", "position", "requires_approval", "git_branch", "auto_deploy", "inherit_secrets",
	"overrides.env_vars", "overrides.cpu", "overrides.memory_mb", "overrides.timeout_seconds",
	"overrides.min_scale", "overrides.max_scale", "overrides.scale_to_zero", "overrides.schedule", "overrides.queue_stream",
	"overrides.scale_schedule", "overrides.scale_schedule[].cron", "overrides.scale_schedule[].min_scale",
}

// The body of POST: the name and the fields of PATCH.
var environmentRequestFields = concat([]string{"name"}, environmentUpdateFields)

var promotionFields = []string{
	"id", "project_id", "project.id", "project.name", "project.slug", "source", "target",
	"version.id", "version.number", "version.label", "image", "status", "status_message", "diff",
	"message", "requested_by", "decided_by", "decided_at", "decision_comment", "deployment_id",
	"created_at", "can_approve", "can_cancel",
}

var promotionSources = []string{
	"app/Http/Controllers/Api/PromotionController.php",
	"app/Services/Environments/Promotions.php",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/environments", Status: 200, Permission: "projects.view", Optional: true,
			Response: concat(prefix("data[].", environmentFields), []string{"abilities.manage", "abilities.promote", "abilities.approve"}),
			Sources:  environmentSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/environments", Status: 201, Permission: "environments.manage", Optional: true,
			Request: environmentRequestFields, FreeForm: []string{"overrides.env_vars"},
			Response: prefix("data.", environmentFields), Sources: environmentSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/environments/{environment}", Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data.", environmentFields), Sources: environmentSources},
		Endpoint{Method: "PATCH", Path: "/projects/{project}/environments/{environment}", Status: 200, Permission: "environments.manage", Optional: true,
			Request: environmentUpdateFields, FreeForm: []string{"overrides.env_vars"},
			Response: prefix("data.", environmentFields), Sources: environmentSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/environments/{environment}", Status: 200, Permission: "environments.manage", Optional: true,
			Response: []string{"message", "incomplete"}, Sources: environmentSources},

		Endpoint{Method: "GET", Path: "/projects/{project}/environments/{environment}/secrets", Status: 200, Permission: "env.view", Optional: true,
			Response: []string{"data[].name", "data[].scope", "data[].updated_at", "data[].version", "inherit_secrets"}, Sources: environmentSources},
		// 201 for a new secret, 200 for a replaced one.
		Endpoint{Method: "PUT", Path: "/projects/{project}/environments/{environment}/secrets/{name}", Status: 200, Permission: "env.edit", Optional: true,
			Request: []string{"value"}, Response: []string{"data.name", "data.scope", "data.updated_at", "data.version", "requires_deploy"}, Sources: environmentSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/environments/{environment}/secrets/{name}", Status: 200, Permission: "env.edit", Optional: true,
			Response: []string{"requires_deploy"}, Sources: environmentSources},

		// What a promotion changes, and the promotion itself
		Endpoint{Method: "GET", Path: "/projects/{project}/environments/{environment}/promote", Query: []string{"to"}, Status: 200,
			Permission: "projects.view", Optional: true,
			Response: []string{
				"data.source.environment", "data.source.environment_id", "data.source.region_id", "data.source.deployment_id", "data.source.status",
				"data.target.environment", "data.target.environment_id", "data.target.region_id", "data.target.deployment_id", "data.target.status",
				"data.version.id", "data.version.number", "data.version.label", "data.version.commit_message", "data.version.image",
				"data.code.from", "data.code.to", "data.code.changes", "data.config", "data.environment_differences",
				"data.requires_approval", "data.can_promote", "data.can_approve", "data.problem",
			},
			Sources: promotionSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/environments/{environment}/promote", Status: 202, Permission: "environments.promote", Optional: true,
			Request: []string{"to", "message"}, Response: prefix("data.", promotionFields), Sources: promotionSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/promotions", Query: []string{"status", "limit"}, Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data[].", promotionFields), Sources: promotionSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/promotions/{promotion}/approve", Status: 200, Permission: "environments.approve", Optional: true,
			Request: []string{"comment"}, Response: prefix("data.", promotionFields), Sources: promotionSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/promotions/{promotion}/reject", Status: 200, Permission: "environments.approve", Optional: true,
			Request: []string{"comment"}, Response: prefix("data.", promotionFields), Sources: promotionSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/promotions/{promotion}/cancel", Status: 200, Permission: "environments.promote|environments.approve", Optional: true,
			Response: prefix("data.", promotionFields), Sources: promotionSources},
		// The approvals inbox: promotions of every project the caller may see
		Endpoint{Method: "GET", Path: "/environment-promotions", Query: []string{"status", "limit"}, Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data[].", promotionFields), Sources: promotionSources},

		// Rollback to a deployment: its version with the config it ran with (docs/versioning.md#rollback)
		Endpoint{Method: "POST", Path: "/projects/{project}/deployments/{deployment}/rollback", Status: 202, Permission: "projects.deploy", Optional: true,
			Request: []string{"keep_current_config"}, Response: deploymentFields,
			Capabilities: concat([]string{"message", "environment", "environment_id", "config_source"}, prefix("queued_deploy.", queuedDeployFields)),
			Sources:      concat([]string{"app/Http/Controllers/Api/DeploymentController.php", "app/Http/Resources/DeploymentResource.php"}, rolloutSources)},
	)
}
