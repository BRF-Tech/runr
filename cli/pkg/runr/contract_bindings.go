package runr

// Bindings (docs/bindings.md; api/routes/bindings.php). BindingController and
// ProjectBindingController build their answers by hand, so the generator leaves "data" untyped:
// the fields are checked against BindingResource.php.

var bindingFields = []string{
	"id", "name", "resource_name", "type", "plan", "knative_region_id", "region.id", "region.name",
	"status", "status_message", "settings", "env_keys", "details", "usage_bytes", "usage_measured_at",
	"rotated_at", "delete_requested_at", "purge_at", "created_by", "created_at", "updated_at",
	"attachments[].id", "attachments[].binding_id", "attachments[].project_id", "attachments[].project.name",
	"attachments[].env_prefix", "attachments[].env_names",
}

var attachmentFields = []string{
	"id", "binding_id", "project_id", "project.id", "project.name", "project.slug", "env_prefix", "env_names",
	"created_at", "binding.id", "binding.name", "binding.type", "binding.status",
}

var bindingSources = []string{"app/Http/Resources/BindingResource.php"}

var bindingWriteSources = []string{
	"app/Http/Controllers/Api/BindingController.php", "app/Http/Resources/BindingResource.php",
}

var bindingRequest = []string{"name", "type", "plan", "knative_region_id", "settings", "values"}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/bindings", Query: []string{"type", "knative_region_id"}, Status: 200,
			Permission: "bindings.view", Response: concat(prefix("data[].", bindingFields), []string{"abilities.manage", "abilities.view_credentials"}),
			Sources: bindingSources},
		Endpoint{Method: "GET", Path: "/bindings/types", Status: 200, Permission: "bindings.view",
			Response: []string{"data.kv.env_keys", "data.kv.plans", "data.kv.defaults", "data.kv.max",
				"data.object.env_keys", "data.postgres.plans", "data.postgres.defaults", "data.external.plans",
				"retention_days"}},
		Endpoint{Method: "GET", Path: "/bindings/quota", Status: 200, Permission: "bindings.view",
			Response: []string{"data.limits", "data.used", "data.measured_bytes"}},
		// One organization's limits (Tenancy module, platform administrators). The request is
		// validated from BindingQuotas::KEYS, so the document has no body schema.
		Endpoint{Method: "GET", Path: "/tenants/{tenant}/binding-quotas", Status: 200, Permission: "@platform", Optional: true,
			Response: []string{"data.tenant_id", "data.overrides", "data.defaults", "data.limits", "data.used"}},
		Endpoint{Method: "PUT", Path: "/tenants/{tenant}/binding-quotas", Status: 200, Permission: "@platform", Optional: true,
			Request:  []string{"max_bindings", "kv_mb", "object_gb", "postgres_gb", "dedicated_postgres"},
			Response: []string{"data.tenant_id", "data.overrides", "data.defaults", "data.limits", "data.used"},
			Sources: []string{"Modules/Tenancy/app/Http/Controllers/TenantBindingQuotaController.php",
				"app/Services/Bindings/BindingQuotas.php"}},
		Endpoint{Method: "POST", Path: "/bindings", Status: 201, Permission: "bindings.manage",
			Request: bindingRequest, FreeForm: []string{"settings", "values"},
			Response: prefix("data.", bindingFields), Sources: bindingWriteSources},
		Endpoint{Method: "GET", Path: "/bindings/{binding}", Status: 200, Permission: "bindings.view",
			Response: concat(prefix("data.", bindingFields), []string{"abilities.manage", "abilities.view_credentials"}),
			Sources:  bindingSources},
		Endpoint{Method: "PATCH", Path: "/bindings/{binding}", Status: 200, Permission: "bindings.manage",
			Request: []string{"settings", "values"}, FreeForm: []string{"settings", "values"},
			Response: prefix("data.", bindingFields), Sources: bindingWriteSources},
		Endpoint{Method: "DELETE", Path: "/bindings/{binding}", Status: 202, Permission: "bindings.manage",
			Request: []string{"confirm"}, Response: concat([]string{"message"}, prefix("data.", []string{"id", "name", "status", "purge_at"})),
			Sources: bindingWriteSources},
		Endpoint{Method: "POST", Path: "/bindings/{binding}/restore", Status: 200, Permission: "bindings.manage",
			Response: concat([]string{"message"}, prefix("data.", bindingFields)), Sources: bindingSources},
		Endpoint{Method: "POST", Path: "/bindings/{binding}/rotate", Status: 202, Permission: "bindings.manage",
			Response: concat([]string{"message"}, prefix("data.", bindingFields)), Sources: bindingSources},
		Endpoint{Method: "GET", Path: "/bindings/{binding}/status", Status: 200, Permission: "bindings.view",
			Response: concat(prefix("data.", bindingFields), []string{"health.state", "health.message", "health.details"}),
			Sources:  bindingSources},
		Endpoint{Method: "GET", Path: "/bindings/{binding}/credentials", Status: 200, Permission: "bindings.view_credentials",
			Response: []string{"data.env"},
			Sources:  []string{"app/Http/Controllers/Api/BindingController.php", "app/Services/Bindings/BindingManager.php"}},
		Endpoint{Method: "GET", Path: "/bindings/{binding}/usage", Query: []string{"days"}, Status: 200, Permission: "bindings.view",
			Response: prefix("data[].", []string{"record_date", "storage_gb_hours", "instance_hours", "peak_bytes"}),
			Sources:  []string{"app/Http/Controllers/Api/BindingController.php"}},

		// Attachments of a project (route permission on the project; the controller also requires
		// bindings.manage organization-wide for changes)
		// ?environment=: one environment's attachments (docs/environments.md#bindings)
		Endpoint{Method: "GET", Path: "/projects/{project}/bindings", Query: []string{"environment"}, Status: 200, Permission: "projects.view",
			Response: concat(prefix("data[].", attachmentFields), []string{"available[].id", "available[].name",
				"available[].type", "available[].status", "available[].knative_region_id", "secret",
				"abilities.manage", "abilities.view_credentials"}),
			Capabilities: []string{"data[].credential_mode", "data[].environment_id", "data[].environment"},
			Sources:      concat(bindingSources, []string{"app/Http/Controllers/Api/ProjectBindingController.php"})},
		// credentials: static, or dynamic (a Postgres user of the attachment's own from the
		// region's OpenBao, docs/bindings.md "Dynamic credentials")
		Endpoint{Method: "POST", Path: "/projects/{project}/bindings", Status: 201, Permission: "env.edit",
			Request: []string{"binding_id", "env_prefix", "credentials", "environment", "binding", "binding.name", "binding.type", "binding.plan",
				"binding.settings", "binding.values"},
			FreeForm:     []string{"binding.settings", "binding.values"},
			Response:     prefix("data.", attachmentFields),
			Capabilities: []string{"data.credential_mode", "data.environment_id", "data.environment"},
			Sources: []string{"app/Http/Controllers/Api/ProjectBindingController.php",
				"app/Http/Controllers/Api/BindingController.php", "app/Http/Resources/BindingResource.php"}},
		Endpoint{Method: "PATCH", Path: "/projects/{project}/bindings/{attachment}", Status: 200, Permission: "env.edit",
			Request: []string{"env_prefix", "credentials"}, Response: prefix("data.", attachmentFields),
			Capabilities: []string{"data.credential_mode"}, Sources: bindingSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/bindings/{attachment}", Status: 200, Permission: "env.edit",
			Response: []string{"message"}},
	)
}
