package runr

// Software supply chain and data residency (docs/supply-chain.md). The generator types the
// version rows, reports and decisions as plain strings; the fields are checked against the
// controller and the models that build them.
var securitySources = []string{
	"app/Http/Controllers/Api/ProjectSecurityController.php",
	"app/Models/ImageSecurityReport.php",
	"app/Services/SupplyChain/PolicyDecision.php",
	"app/Services/SupplyChain/TrivyReportParser.php",
}

var securityVersionFields = []string{
	"id", "number", "label", "build_status", "image_kind", "image", "applicable",
	"report.status", "report.counts.critical", "report.counts.high", "report.counts.medium",
	"report.counts.low", "report.signature_status", "report.scan_status", "report.sbom_status",
}

var securityReportFields = []string{
	"id", "status", "image_ref", "image_digest", "sbom_status", "scan_status", "signature_status",
	"counts.critical", "counts.high", "counts.medium", "counts.low", "counts.unknown",
	"fixable.critical", "fixable.high", "findings_total", "packages", "scanned_at", "signed_at", "error",
	"findings[].id", "findings[].severity", "findings[].package", "findings[].installed",
	"findings[].fixed", "findings[].title", "sbom_formats",
}

var tenantPolicyFields = []string{"tenant_id", "residency_enforced", "allowed_region_ids", "allowed_locations",
	"default_region_id", "restricted", "gate_mode", "gate_max_critical", "gate_max_high", "gate_require_scan",
	"updated_by", "updated_at", "regions[].id", "regions[].name", "regions[].data_location", "regions[].region_mode",
	"locations", "abilities.residency", "abilities.gate"}

var tenantPolicySources = []string{"app/Http/Controllers/Api/ResidencyController.php"}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/security", Status: 200, Permission: "security.view",
			Response: concat(
				[]string{"data.region.id", "data.region.name", "data.region.data_location", "data.policy.mode",
					"data.pipeline.sbom", "data.pipeline.scan", "data.pipeline.signing", "data.pipeline.admission",
					"data.deployments[].id", "data.deployments[].status", "data.deployments[].version_id",
					"data.deployments[].version_label"},
				prefix("data.versions[].", securityVersionFields)),
			Sources: securitySources},
		Endpoint{Method: "GET", Path: "/projects/{project}/versions/{version}/security", Status: 200, Permission: "security.view",
			Response: concat(prefix("data.version.", []string{"id", "number", "label", "applicable", "image"}),
				prefix("data.report.", securityReportFields),
				[]string{"data.decision.applicable", "data.decision.blocked", "data.decision.overridden", "data.decision.violations"}),
			Sources: securitySources},
		Endpoint{Method: "GET", Path: "/projects/{project}/deployments/{deployment}/security", Status: 200, Permission: "security.view",
			Response: concat([]string{"data.deployment_id"}, prefix("data.version.", []string{"id", "number", "label"}),
				prefix("data.report.", securityReportFields)),
			Sources: securitySources},
		Endpoint{Method: "POST", Path: "/projects/{project}/versions/{version}/security/scan", Status: 202, Permission: "security.view",
			Response: []string{"data.id", "data.status"}, Sources: securitySources},
		// The SBOM document itself (application/spdx+json or application/vnd.cyclonedx+json).
		Endpoint{Method: "GET", Path: "/projects/{project}/versions/{version}/sbom", Query: []string{"format"}, Status: 200,
			Permission: "security.view", Sources: securitySources},

		// The report of a model service's (external) image, and its SBOM.
		Endpoint{Method: "GET", Path: "/model-services/{service}/security", Status: 200, Permission: "models.view",
			Response: concat([]string{"data.image", "data.scanned", "data.current"}, prefix("data.report.", securityReportFields)),
			Sources:  concat(securitySources, []string{"app/Http/Controllers/Api/ModelServiceSecurityController.php"})},
		Endpoint{Method: "GET", Path: "/model-services/{service}/sbom", Query: []string{"format"}, Status: 200,
			Permission: "models.view", Sources: concat(securitySources, []string{"app/Http/Controllers/Api/ModelServiceSecurityController.php"})},

		// An organization's security policy: residency and its own policy gate (multi-tenant).
		Endpoint{Method: "GET", Path: "/tenants/{tenant}/security-policy", Status: 200,
			Permission: "tenants.residency.manage|regions.supply_chain.manage", Optional: true,
			Response: prefix("data.", tenantPolicyFields), Sources: tenantPolicySources},
		Endpoint{Method: "PUT", Path: "/tenants/{tenant}/security-policy", Status: 200,
			Permission: "tenants.residency.manage|regions.supply_chain.manage", Optional: true,
			Request: []string{"residency_enforced", "allowed_region_ids", "allowed_locations", "default_region_id",
				"gate_mode", "gate_max_critical", "gate_max_high", "gate_require_scan"},
			Response: prefix("data.", tenantPolicyFields), Sources: tenantPolicySources},

		// Data residency of the caller's organization (region pickers).
		Endpoint{Method: "GET", Path: "/residency", Status: 200,
			Response: []string{"data.tenant_id", "data.restricted", "data.residency_enforced", "data.allowed_region_ids",
				"data.allowed_locations", "data.default_region_id", "data.regions[].id", "data.regions[].name",
				"data.regions[].data_location", "data.regions[].allowed"},
			Sources: []string{"app/Http/Controllers/Api/ResidencyController.php"}},
	)
}
