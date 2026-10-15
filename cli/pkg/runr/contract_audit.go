package runr

// Audit log (docs/security.md). The filters are validated in a helper the generator does not
// follow, and the events are built by AuditEvent::toPublicArray; both are checked against the
// sources.
var auditSources = []string{
	"app/Http/Controllers/Api/AuditEventController.php",
	"app/Models/AuditEvent.php",
}

var auditFilterParams = []string{
	"actor", "action", "target_type", "target_id", "target", "result", "from", "to", "request_id", "q", "tenant_id",
}

var auditEventFields = []string{
	"id", "occurred_at", "tenant_id", "actor.type", "actor.id", "actor.name", "actor.email", "actor.auth_method",
	"actor.impersonator.id", "actor.impersonator.name", "action", "result", "target.type", "target.id", "target.name",
	"ip_address", "user_agent", "request_id", "http_method", "route", "status_code", "metadata",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/audit-events", Status: 200, Permission: "audit.view",
			Query: concat(auditFilterParams, []string{"per_page"}),
			Response: concat(prefix("data[].", auditEventFields), []string{"meta.current_page", "meta.last_page",
				"meta.per_page", "meta.total", "meta.can_export", "meta.scope"}),
			Sources: auditSources},
		Endpoint{Method: "GET", Path: "/audit-events/{id}", Status: 200, Permission: "audit.view",
			Response: prefix("data.", auditEventFields), Sources: auditSources},
		// CSV (default) or JSON file, streamed.
		Endpoint{Method: "GET", Path: "/audit-events/export", Status: 200, Permission: "audit.export",
			Query: concat(auditFilterParams, []string{"format"}), Sources: auditSources},
		// Retention (install-wide; changing it also needs a platform administrator outside any
		// organization on a multi-tenant installation, which the controller checks).
		Endpoint{Method: "GET", Path: "/audit-settings", Status: 200, Permission: "audit.view",
			Response: []string{"retention_days", "max_days", "forward_enabled", "can_update"}},
		Endpoint{Method: "PUT", Path: "/audit-settings", Status: 200, Permission: "settings.manage",
			Request: []string{"retention_days"}, Response: []string{"retention_days", "max_days", "forward_enabled", "can_update"},
			Sources: auditSources},
	)
}
