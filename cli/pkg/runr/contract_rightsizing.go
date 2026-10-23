package runr

// Rightsizing recommendations (RightsizingController, docs/monitoring.md#rightsizing). The
// resource builds its nested objects by hand (evidence, freed, savings), which the generator may
// leave untyped; the fields are checked against the files that build them. Optional: APIs before
// rightsizing answer 404, the client reports ErrUnsupported.

var rightsizingSources = []string{
	"app/Http/Controllers/Api/RightsizingController.php",
	"app/Http/Resources/RightsizingRecommendationResource.php",
	"app/Services/Rightsizing/SizeRecommender.php",
}

var rightsizingFields = []string{
	"id", "project_id", "project.id", "project.name", "project.slug", "project.service_type", "region_id",
	"status", "direction", "current.cpu_m", "current.memory_mb", "recommended.cpu_m", "recommended.memory_mb",
	"changes.cpu", "changes.memory", "reasons",
	"evidence.window_days", "evidence.from", "evidence.to", "evidence.days", "evidence.sessions", "evidence.always_on",
	"evidence.memory_max_mb", "evidence.memory_p95_mb", "evidence.memory_basis", "evidence.memory_margin",
	"evidence.memory_near_limit", "evidence.cpu_max_m", "evidence.cpu_p95_m", "evidence.cpu_basis", "evidence.cpu_margin",
	"evidence.oom_kills", "evidence.throttled_ratio",
	"freed.cpu_m", "freed.memory_mb", "freed.pods", "freed.always_on", "freed.peak_cpu_m", "freed.peak_memory_mb",
	"savings.basis", "savings.monthly", "savings.currency", "savings.allocation", "savings.freed_allocation",
	"blocked.code", "blocked.message", "computed_at", "snoozed_until", "decided_at", "applied_version_id",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/rightsizing", Status: 200, Permission: "metrics.view", Optional: true,
			Response: concat(prefix("data[].", rightsizingFields),
				prefix("summary.", []string{"count", "monthly_savings", "currency", "freed_memory_mb", "freed_cpu_m"})),
			Sources: rightsizingSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/rightsizing", Status: 200, Permission: "metrics.view", Optional: true,
			Response: concat([]string{"status", "reason", "pending_deploy"}, prefix("data.", rightsizingFields),
				prefix("collecting.", []string{"days", "required_days", "sessions", "required_sessions", "window_days"})),
			Sources: rightsizingSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/rightsizing/apply", Status: 200, Permission: "projects.update", Optional: true,
			Response: concat(prefix("data.", rightsizingFields), []string{"version.id", "version.number", "version.label", "deploy_required", "message"}),
			Sources:  rightsizingSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/rightsizing/dismiss", Status: 200, Permission: "projects.update", Optional: true,
			Request:  []string{"snooze_days"},
			Response: concat(prefix("data.", rightsizingFields), []string{"message"}),
			Sources:  rightsizingSources},
	)
}
