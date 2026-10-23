package runr

// API product of the gateways (docs/api-product.md). The resources are arrays built by hand
// (resolve()) and the request rules partly composed in helpers, which the generator types as
// "any": their fields are checked against these sources. The endpoints newer than the gateways
// are Optional (an older API answers 404 → ErrUnsupported).
var apiProductSources = []string{
	"app/Http/Controllers/Api/GatewayController.php",
	"app/Http/Resources/GatewayResource.php",
	"app/Http/Resources/GatewayRouteResource.php",
	"app/Services/ApiProduct/GatewayAuthConfig.php",
	"app/Http/Controllers/Api/ApiProduct/ApiConsumerController.php",
	"app/Http/Controllers/Api/ApiProduct/ApiKeyController.php",
	"app/Http/Controllers/Api/ApiProduct/ApiPlanController.php",
	"app/Http/Controllers/Api/ApiProduct/ApiUsageController.php",
	"app/Http/Resources/ApiProduct/ApiConsumerResource.php",
	"app/Http/Resources/ApiProduct/ApiKeyResource.php",
	"app/Http/Resources/ApiProduct/ApiPlanResource.php",
	"app/Services/ApiProduct/ApiKeys.php",
	"app/Services/ApiProduct/ApiUsageReport.php",
	"app/Services/ApiProduct/ApiResponseMetrics.php",
	"app/Models/ApiProduct/ApiUsageHour.php",
}

// usageAnswerFields: what the functions answered (the metering proxy), in every usage row and
// the totals.
var usageAnswerFields = []string{
	"responses", "status_2xx", "status_3xx", "status_4xx", "status_5xx", "upstream_errors", "errors",
	"latency_avg_ms", "latency_p50_ms", "latency_p95_ms", "latency_p99_ms", "bytes_in", "bytes_out",
}

var gatewayFields = []string{
	"id", "subdomain", "custom_domain", "status", "knative_region_id", "region.id", "region.name", "host",
	"rate_limit.average", "rate_limit.burst",
	"routes[].id", "routes[].path", "routes[].methods", "routes[].project_id", "routes[].project.id",
	"routes[].project.name", "routes[].project.slug", "routes[].url",
}

// gatewayProductFields came with the API product.
var gatewayProductFields = []string{
	"custom_domain_pending.domain", "custom_domain_pending.verification.name", "custom_domain_pending.verification.value",
	"custom_domain_pending.dns_targets[].type", "custom_domain_pending.dns_targets[].value",
	"developer_page", "developer_page_key", "description", "status_message",
	"routes[].auth_mode", "routes[].jwt.jwks_url", "routes[].jwt.issuer", "routes[].jwt.audience",
	"routes[].jwt.subject_claim", "routes[].jwt.require_consumer", "routes[].gateway_only",
	// the Terraform provider (runr_gateway, runr_gateway_route)
	"custom_domain_pending.verification.type", "custom_domain_pending.dns_targets[].name", "custom_domain_verified_at",
	"developer_page_metrics", "routes[].environment", "routes[].environment_id",
}

// routeSettingsRequest are the API product settings of a route, on creation and change.
var routeSettingsRequest = []string{"auth_mode", "jwt.jwks_url", "jwt.issuer", "jwt.audience", "jwt.subject_claim",
	"jwt.require_consumer", "gateway_only"}

// routeViewFields: the answers of adding and changing a route are the route as the gateway shows
// it (GatewayRouteResource), with its URL and JWT settings.
var routeViewFields = []string{"data.methods", "data.project.id", "data.project.name", "data.project.slug", "data.url",
	"data.auth_mode", "data.jwt.jwks_url", "data.jwt.issuer", "data.jwt.audience", "data.jwt.subject_claim",
	"data.jwt.require_consumer", "data.gateway_only"}

var apiConsumerFields = []string{
	"id", "gateway_id", "scope", "name", "email", "external_id", "jwt_subject", "plan_id", "plan.name",
	"status", "metadata", "keys.total", "keys.active", "can_manage",
}

var apiKeyFields = []string{
	"id", "consumer_id", "name", "prefix", "scopes", "plan_id", "status", "expires_at", "revoked_at",
	"last_used_at", "rotated_from_id",
}

var apiPlanFields = []string{
	"id", "gateway_id", "scope", "name", "description", "rate_per_second", "burst", "rate_per_minute",
	"quota_per_day", "quota_per_month",
}

var apiConsumerRequest = []string{"name", "email", "external_id", "jwt_subject", "plan_id", "status", "metadata"}

var apiKeyRequest = []string{"name", "scopes[].route_id", "scopes[].methods", "plan_id", "expires_at"}

var apiPlanRequest = []string{
	"name", "description", "rate_per_second", "burst", "rate_per_minute", "quota_per_day", "quota_per_month",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/gateways", Status: 200,
			Response: prefix("data[].", gatewayFields), Capabilities: prefix("data[].", gatewayProductFields), Sources: apiProductSources},
		Endpoint{Method: "GET", Path: "/gateways/{gateway}", Status: 200,
			Response: prefix("data.", gatewayFields), Capabilities: prefix("data.", gatewayProductFields), Sources: apiProductSources},
		// The gateway's IP rate limit (rate_limit null: none) and its developer page
		Endpoint{Method: "PATCH", Path: "/gateways/{gateway}", Status: 200,
			Request:  []string{"rate_limit.average", "rate_limit.burst", "developer_page", "description", "developer_page_metrics"},
			Response: []string{"data.id", "data.rate_limit.average", "data.rate_limit.burst"},
			Capabilities: []string{"data.developer_page", "data.developer_page_key", "data.developer_page_metrics", "data.description",
				"data.custom_domain_pending.domain"}, Sources: apiProductSources},
		// The caller's gateway in a region, created when there is none (201; 200 when it exists —
		// the document has the 200 answer)
		Endpoint{Method: "POST", Path: "/gateways", Status: 200, Optional: true,
			Request: []string{"knative_region_id"}, Response: []string{"data.id", "data.host", "data.knative_region_id"}, Sources: apiProductSources},
		// A route to a project's own workload, or (environment) to one of its environments, with
		// its authentication in the same call; the answer is the route as the gateway shows it.
		// An earlier API ignores the settings and answers the stored route (no auth_mode, url, jwt):
		// AddGatewayRoute sets them right after.
		Endpoint{Method: "POST", Path: "/gateways/{gateway}/routes", Status: 201,
			Request:      concat([]string{"project_id", "environment", "path", "methods"}, routeSettingsRequest),
			Response:     []string{"data.id", "data.path", "data.project_id"},
			Capabilities: concat([]string{"data.environment", "data.environment_id"}, routeViewFields), Sources: apiProductSources},
		Endpoint{Method: "DELETE", Path: "/gateways/{gateway}/routes/{route}", Status: 204, Sources: apiProductSources},
		Endpoint{Method: "PATCH", Path: "/gateways/{gateway}/routes/{route}", Status: 200,
			Request:  concat([]string{"path", "methods"}, routeSettingsRequest),
			Response: []string{"data.id", "data.path"}, Capabilities: routeViewFields,
			Sources: apiProductSources},
		Endpoint{Method: "PUT", Path: "/gateways/{gateway}/custom-domain", Status: 200, Optional: true,
			Request: []string{"domain"}, Response: []string{"data.id", "data.custom_domain_pending.domain"}, Sources: apiProductSources},
		Endpoint{Method: "POST", Path: "/gateways/{gateway}/custom-domain/verify", Status: 200, Optional: true,
			Response: []string{"data.custom_domain"}, Sources: apiProductSources},
		Endpoint{Method: "DELETE", Path: "/gateways/{gateway}/custom-domain", Status: 200, Optional: true,
			Response: []string{"data.id"}, Sources: apiProductSources},

		Endpoint{Method: "GET", Path: "/api-consumers", Status: 200, Optional: true, Query: []string{"gateway_id"},
			Response: prefix("data[].", apiConsumerFields), Sources: apiProductSources},
		Endpoint{Method: "POST", Path: "/api-consumers", Status: 201, Optional: true,
			Request: concat([]string{"gateway_id"}, apiConsumerRequest), FreeForm: []string{"metadata"},
			Response: prefix("data.", apiConsumerFields), Sources: apiProductSources},
		Endpoint{Method: "GET", Path: "/api-consumers/{apiConsumer}", Status: 200, Optional: true,
			Response: prefix("data.", apiConsumerFields), Sources: apiProductSources},
		Endpoint{Method: "PATCH", Path: "/api-consumers/{apiConsumer}", Status: 200, Optional: true,
			Request: apiConsumerRequest, FreeForm: []string{"metadata"}, Response: prefix("data.", apiConsumerFields), Sources: apiProductSources},
		Endpoint{Method: "DELETE", Path: "/api-consumers/{apiConsumer}", Status: 204, Optional: true, Sources: apiProductSources},
		Endpoint{Method: "GET", Path: "/api-consumers/{apiConsumer}/keys", Status: 200, Optional: true,
			Response: prefix("data[].", apiKeyFields), Sources: apiProductSources},
		Endpoint{Method: "POST", Path: "/api-consumers/{apiConsumer}/keys", Status: 201, Optional: true,
			Request: apiKeyRequest, Response: concat(prefix("data.", apiKeyFields), []string{"data.key"}), Sources: apiProductSources},
		// Name, scopes, plan and expiry of a key (the key itself stays)
		Endpoint{Method: "PATCH", Path: "/api-keys/{apiKey}", Status: 200, Optional: true,
			Request: apiKeyRequest, Response: prefix("data.", apiKeyFields), Sources: apiProductSources},
		Endpoint{Method: "POST", Path: "/api-keys/{apiKey}/rotate", Status: 201, Optional: true,
			Request: []string{"overlap_minutes"}, Response: concat(prefix("data.", apiKeyFields), []string{"data.key"}), Sources: apiProductSources},
		Endpoint{Method: "POST", Path: "/api-keys/{apiKey}/revoke", Status: 200, Optional: true,
			Response: prefix("data.", apiKeyFields), Sources: apiProductSources},

		Endpoint{Method: "GET", Path: "/api-plans", Status: 200, Optional: true, Query: []string{"gateway_id"},
			Response: prefix("data[].", apiPlanFields), Sources: apiProductSources},
		Endpoint{Method: "POST", Path: "/api-plans", Status: 201, Optional: true,
			Request: concat([]string{"gateway_id"}, apiPlanRequest), Response: prefix("data.", apiPlanFields), Sources: apiProductSources},
		Endpoint{Method: "PATCH", Path: "/api-plans/{apiPlan}", Status: 200, Optional: true,
			Request: apiPlanRequest, Response: prefix("data.", apiPlanFields), Sources: apiProductSources},
		Endpoint{Method: "DELETE", Path: "/api-plans/{apiPlan}", Status: 204, Optional: true, Sources: apiProductSources},

		Endpoint{Method: "GET", Path: "/api-usage", Status: 200, Optional: true,
			Query: []string{"gateway_id", "consumer_id", "key_id", "route_id", "from", "to", "group_by"},
			Response: concat([]string{"from", "to", "group_by", "totals.requests", "totals.unauthorized", "totals.forbidden",
				"totals.rate_limited", "totals.quota_exceeded", "data[].period", "data[].consumer_id", "data[].subject",
				"data[].key_id", "data[].route_id", "data[].path", "data[].requests"},
				prefix("totals.", usageAnswerFields), prefix("data[].", usageAnswerFields)),
			Sources: apiProductSources},
	)
}
