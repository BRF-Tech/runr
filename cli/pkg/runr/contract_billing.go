package runr

// The Billing module (api/Modules/Billing, docs/billing.md): the monthly cost limit of a project.
// The route rule is projects.view; ProjectBillingController additionally requires the project's
// owner, projects.billing on the project or billing.view (changes: billing.manage). Optional:
// without the Billing module the routes do not exist (404, ErrUnsupported).

var costLimitFields = []string{
	"available", "currency", "period", "limit.amount", "limit.action", "limit.updated_by.id", "limit.updated_by.name",
	"limit.updated_at", "month_to_date", "forecast", "days_elapsed", "days_in_month", "percent", "warning_percent",
	"state", "warned_at", "reached_at", "stopped", "can_manage", "can_resume", "checked_at",
}

var costLimitSources = []string{
	"Modules/Billing/app/Http/Controllers/ProjectBillingController.php",
	"Modules/Billing/app/Services/CostLimits.php",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/billing/cost-limit", Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data.", costLimitFields), Sources: costLimitSources},
		// 422 cost_limit_not_available for a fixed-price project; currency, when sent, must be
		// the billing currency
		Endpoint{Method: "PUT", Path: "/projects/{project}/billing/cost-limit", Status: 200, Permission: "projects.view", Optional: true,
			Request: []string{"amount", "action", "currency"}, Response: prefix("data.", costLimitFields), Sources: costLimitSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/billing/cost-limit", Status: 200, Permission: "projects.view", Optional: true,
			Response: prefix("data.", costLimitFields), Sources: costLimitSources},
	)
}
