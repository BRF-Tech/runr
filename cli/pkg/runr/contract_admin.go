package runr

// Contract entries of the administration commands: a region's tracing backend and data
// services, the GPU quota, and the git providers of the installation.

var regionTracingFields = []string{
	"region_id", "mode", "status", "status_message", "tempo_url", "tempo_otlp_endpoint", "tempo_org_id",
	"export_endpoint", "export_header_names", "knative_sample_rate", "retention_hours", "installed_at",
	"queryable", "accepts_spans",
}

// regionTracingSources: GET and the 200 answer of PUT are {"data": toApi()}, which the
// generator types as a string.
var regionTracingSources = []string{
	"app/Models/Observability/RegionTracingConfig.php",
	"app/Http/Controllers/Api/Observability/RegionTracingController.php",
}

var dataServiceFields = []string{
	"service", "installed", "mode", "status", "status_message", "config", "bindings_count", "updated_at",
	"health.state", "health.message",
}

// dataServiceSources: the services map of the status answer is built by DataServiceManager,
// the install settings are validated by DataServiceController::rules.
var dataServiceSources = []string{
	"app/Http/Controllers/Api/DataServiceController.php",
	"app/Services/Bindings/DataServiceManager.php",
}

func dataServiceStatusFields() []string {
	out := []string{"data.region_id", "data.namespace", "data.services", "data.region_redis.mode",
		"data.region_redis.status", "data.region_redis.has_password", "abilities.manage"}
	for _, svc := range []string{"kv", "object", "postgres"} {
		out = append(out, prefix("data.services."+svc+".", dataServiceFields)...)
	}
	return out
}

// supplyChainRequest are the settings PUT …/supply-chain takes (UpdateRegionSupplyChainRequest).
var supplyChainRequest = []string{"sbom_enabled", "scan_enabled", "scan_external_images", "rescan_interval_hours",
	"scanner_db_mode", "scanner_db_source", "gate_mode", "gate_max_critical", "gate_max_high", "gate_only_fixable",
	"gate_require_scan", "signing_enabled", "verify_on_deploy", "attest_enabled", "admission_mode",
	"admission_require_sbom", "admission_exempt_images", "caas_policy", "caas_allowed_registries", "caas_trusted_keys",
	"data_location"}

var supplyChainFields = concat(supplyChainRequest, []string{"knative_region_id", "scanner_db_status",
	"scanner_db_updated_at", "scanner_db_refreshed_at", "scanner_db_message", "signing_status", "signing_public_key",
	"signing_key_fingerprint", "signing_key_created_at", "signing_retired_keys[].fingerprint",
	"signing_retired_keys[].public_key", "signing_retired_keys[].retired_at", "signing_message", "admission_status",
	"admission_message", "updated_by", "region.id", "region.name", "region.has_registry", "region.registry_mode",
	"region.project_namespace", "scanner_db_repository", "scanner_db_default_source", "supply_chain_namespace",
	"can_manage"})

var supplyChainSources = []string{
	"app/Http/Controllers/Api/RegionSupplyChainController.php",
	"app/Models/RegionSupplyChainSetting.php",
}

var regionGPUFields = concat([]string{"mode", "status", "status_message", "detected_at", "allocation.total",
	"allocation.by_node"},
	prefix("settings.", []string{"time_slicing_replicas", "runtime_class", "runtime_class_choice", "gvisor_nvproxy",
		"operator_driver", "operator_toolkit", "restrict_to_gpu_nodes", "gpu_nodes"}),
	prefix("summary.", []string{"gpu_nodes", "total", "allocatable", "allocated", "available", "products[].product",
		"products[].memory_mb", "products[].count", "products[].allocatable", "products[].nodes", "hardware_without_plugin"}),
	prefix("nodes[].", []string{"name", "ready", "product", "memory_mb", "count", "capacity", "allocatable", "allocated",
		"replicas", "compute_capability", "driver_version", "cuda_version", "has_device_plugin", "hardware_detected", "taints"}),
	[]string{"sandbox.faas.runtime_class", "sandbox.faas.gpu", "sandbox.faas.message"})

var regionGPUSources = []string{
	"app/Http/Controllers/Api/Ai/GpuController.php",
	"app/Services/Ai/Gpu/GpuInventory.php",
	"app/Services/Ai/Gpu/GpuPolicy.php",
}

var modelStorageFields = []string{"storage_class", "size_gi", "access_mode", "pvc_name", "status", "message", "capacity"}

var gitProviderRequestFields = []string{
	"name", "base_url", "api_url", "is_enabled", "client_id", "client_secret", "app_id", "app_slug",
	"private_key", "webhook_secret",
}

var gitProviderSources = []string{"app/Http/Controllers/Api/GitProviderController.php"}

func init() {
	Contract = append(Contract,
		// Region tracing backend (docs/observability.md).
		Endpoint{Method: "GET", Path: "/observability/regions/{knativeRegion}/tracing", Status: 200, Permission: "regions.view",
			Response: prefix("data.", regionTracingFields), Sources: regionTracingSources},
		Endpoint{Method: "PUT", Path: "/observability/regions/{knativeRegion}/tracing", Status: 200,
			Permission: "regions.observability.manage",
			Request: []string{"mode", "tempo_url", "tempo_otlp_endpoint", "tempo_org_id", "export_endpoint", "export_headers",
				"knative_sample_rate", "retention_hours", "install"},
			FreeForm: []string{"export_headers"},
			Response: concat(prefix("data.", regionTracingFields), []string{"202:message", "202:data.status"}),
			Sources:  regionTracingSources},
		Endpoint{Method: "POST", Path: "/observability/regions/{knativeRegion}/tracing/install", Status: 202,
			Permission: "regions.observability.manage",
			Response:   concat(prefix("data.", regionTracingFields), []string{"message"}), Sources: regionTracingSources},
		Endpoint{Method: "DELETE", Path: "/observability/regions/{knativeRegion}/tracing", Status: 202,
			Permission: "regions.observability.manage", Response: []string{"message"}},

		// Data services of a region (docs/bindings.md).
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/data-services", Query: []string{"live"}, Status: 200,
			Permission: "regions.view|bindings.view", Response: dataServiceStatusFields(), Sources: dataServiceSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/data-services/{service}", Status: 202,
			Permission: "regions.data_services.manage",
			Request: []string{"mode", "maxmemory_mb", "storage", "provider", "endpoint", "region", "path_style", "vendor",
				"bucket_prefix", "admin_endpoint", "admin_token", "access_key_id", "secret_access_key", "instances", "backups"},
			Response: []string{"message", "data.service", "data.status", "data.mode"}, Sources: dataServiceSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/data-services/{service}", Status: 202,
			Permission: "regions.data_services.manage",
			Response:   []string{"message", "data.service", "data.status"}, Sources: dataServiceSources},

		// Sandbox runtimes of a region (SandboxController; docs/sandbox-api.md). Install and
		// uninstall answer the region resource too, which the client does not read.
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/sandbox-status", Status: 200, Permission: "regions.view",
			Response: []string{"kata.status", "kata.message", "kata.hypervisor", "gvisor.status", "gvisor.message",
				"mapping.xaas", "mapping.faas", "mapping.caas", "mapping.build"}},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/kata-shims", Query: []string{"refresh"}, Status: 200,
			Permission: "regions.view",
			Response: []string{"shims", "kvm.state", "kvm.nodes", "kvm.kvm_nodes", "kvm.checked", "kvm.probing", "warning",
				"fc_warning", "devmapper.default_pool_gb", "devmapper.min_pool_gb", "devmapper.max_pool_gb"},
			Sources: []string{"app/Http/Controllers/Api/SandboxController.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/install-sandbox", Status: 202, Permission: "sandbox.manage",
			Request: []string{"runtime", "hypervisors", "devmapper_pool_gb"}, Response: []string{"message", "warning"},
			Sources: []string{"app/Http/Controllers/Api/SandboxController.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/uninstall-sandbox", Status: 200, Permission: "sandbox.manage",
			Request: []string{"runtime"}, Response: []string{"message"}},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/sandbox-mapping", Status: 200, Permission: "sandbox.manage",
			Request:  []string{"sandbox_xaas_runtime", "sandbox_faas_runtime", "sandbox_caas_runtime", "sandbox_build_runtime"},
			Response: []string{"message"}},

		// The supply chain of a region (RegionSupplyChainController; docs/supply-chain.md). The
		// settings answer is the model's toArray() plus the region block, untyped in the document.
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/supply-chain", Status: 200, Permission: "regions.view",
			Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/supply-chain", Status: 200,
			Permission: "regions.supply_chain.manage", Request: supplyChainRequest,
			Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/supply-chain/signing-key", Status: 202,
			Permission: "regions.supply_chain.manage", Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/supply-chain/signing-key/retired", Status: 200,
			Permission: "regions.supply_chain.manage", Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/supply-chain/scanner-db/refresh", Status: 202,
			Permission: "regions.supply_chain.manage", Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/supply-chain/admission", Status: 202,
			Permission: "regions.supply_chain.manage", Request: []string{"mode"},
			Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/supply-chain/admission", Status: 202,
			Permission: "regions.supply_chain.manage", Response: prefix("data.", supplyChainFields), Sources: supplyChainSources},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/supply-chain/admission/preview", Status: 200,
			Permission: "regions.supply_chain.manage",
			Response: prefix("data.", []string{"release", "chart", "chart_version", "namespace", "project_namespace",
				"namespace_label", "values", "no_match_policy", "policies"})},
		// The PEM of the signing key (application/x-pem-file).
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/supply-chain/public-key", Status: 200, Permission: "regions.view"},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/supply-chain/runtime-images", Status: 200, Permission: "regions.view",
			Response: concat(prefix("data[].", []string{"build_id", "runtime", "version", "status", "image"}),
				prefix("data[].report.", []string{"id", "status", "image_ref", "scan_status", "sbom_status", "signature_status",
					"counts.critical", "counts.high", "counts.medium", "counts.low"})),
			Sources: concat(supplyChainSources, []string{"app/Models/ImageSecurityReport.php"})},
		// The SBOM document of a runtime image report.
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/supply-chain/runtime-images/{report}/sbom", Query: []string{"format"},
			Status: 200, Permission: "regions.view", Sources: supplyChainSources},

		// GPU support and model storage of a region (GpuController; docs/ai.md).
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/gpu", Query: []string{"refresh"}, Status: 200,
			Permission: "regions.view", Response: regionGPUFields, Sources: regionGPUSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/gpu/install", Status: 202, Permission: "regions.gpu.manage",
			Request: []string{"mode", "time_slicing_replicas", "runtime_class", "operator_driver", "operator_toolkit",
				"restrict_to_gpu_nodes", "gpu_nodes"},
			Response: concat([]string{"message"}, prefix("gpu.", regionGPUFields)), Sources: regionGPUSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/gpu/uninstall", Status: 202, Permission: "regions.gpu.manage",
			Response: concat([]string{"message"}, prefix("gpu.", regionGPUFields)), Sources: regionGPUSources},
		// 200 when the settings are only stored, 202 when the chart is applied again.
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/gpu/settings", Status: 200, Permission: "regions.gpu.manage",
			Request:  []string{"time_slicing_replicas", "runtime_class", "gvisor_nvproxy", "restrict_to_gpu_nodes", "gpu_nodes"},
			Response: []string{"message", "gpu.mode", "gpu.status", "gpu.status_message", "gpu.settings"}, Sources: regionGPUSources},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/model-storage", Status: 200, Permission: "regions.view",
			Response: modelStorageFields, Sources: []string{"app/Services/Ai/Models/ModelStorage.php"}},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/model-storage", Status: 200, Permission: "regions.gpu.manage",
			Request: []string{"storage_class", "size_gi", "access_mode"}, Response: modelStorageFields,
			Sources: []string{"app/Services/Ai/Models/ModelStorage.php"}},

		// GPU quota (docs/ai.md).
		Endpoint{Method: "GET", Path: "/models/gpu-quota", Query: []string{"knative_region_id"}, Status: 200,
			Permission: "models.view", Response: []string{"quota", "used", "remaining"}},
		// Tenancy module route (multi-tenant installations; not in the exported document).
		Endpoint{Method: "PUT", Path: "/tenants/{tenant}/regions/{region}/gpu-quota", Status: 200, Permission: "@platform",
			Optional: true, Request: []string{"gpu_quota"}, Response: []string{"tenant_id", "region_id", "gpu_quota"},
			Sources: []string{"Modules/Tenancy/app/Http/Controllers/TenantGpuQuotaController.php"}},

		// Git providers of the installation (docs/git-deploy.md). The request is validated in
		// a helper of the controller, so the document has no body schema.
		Endpoint{Method: "POST", Path: "/git-providers", Status: 201, Permission: "git_providers.manage",
			Request:  concat([]string{"type"}, gitProviderRequestFields),
			Response: prefix("data.", gitProviderFields), Sources: gitProviderSources},
		Endpoint{Method: "PUT", Path: "/git-providers/{provider}", Status: 200, Permission: "git_providers.manage",
			Request: gitProviderRequestFields, Response: prefix("data.", gitProviderFields), Sources: gitProviderSources},
		Endpoint{Method: "DELETE", Path: "/git-providers/{provider}", Status: 204, Permission: "git_providers.manage"},
	)
}
