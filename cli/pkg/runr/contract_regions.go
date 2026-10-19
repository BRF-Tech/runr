package runr

// Contract entries of a region's lifecycle (runr regions create|get|set|rm|status|kubeconfig|
// nodes|knative|redis|registry|migration|function-tls|skupper|capacity|demo|wasm).

// regionDetailFields are the fields of KnativeRegionResource that RegionDetail reads.
var regionDetailFields = concat(regionFields, []string{
	"server_url", "has_kubeconfig", "is_synced", "cluster_status", "cluster_status_message",
	"knative_status_message", "knative_status_checked_at", "faas_cpu_overcommit", "faas_memory_overcommit",
	"selected_workers", "worker_count", "knative_settings", "redis_mode", "knative_redis_status", "redis_address",
	"knative_redis_status_message", "external_ip", "dns_instruction.wildcard", "dns_instruction.type",
	"dns_instruction.target", "function_tls_mode", "function_tls_expires_at", "knative_operator_status",
	"knative_operator_status_message", "knative_operator_namespace", "knative_serving_status",
	"knative_serving_status_message", "knative_serving_namespace", "has_serving", "registry_mode",
	"registry_address", "registry_storage_type", "registry_status", "registry_status_message", "registry_namespace",
	"has_registry", "region_demo_status", "sandbox_wasm_status", "migration_status", "migration_started_at",
	"migration_old_ip", "is_migrating",
})

// regionDetailSources: the region resource and the controllers that answer {message, region}
// (the generator types the resource as a string).
var regionDetailSources = []string{
	"app/Http/Resources/KnativeRegionResource.php",
	"app/Http/Controllers/Api/KnativeRegionController.php",
	"app/Http/Controllers/Api/KnativeInstallController.php",
	"app/Http/Controllers/Api/KnativeUninstallController.php",
	"app/Http/Controllers/Api/KnativeNodeController.php",
	"app/Http/Controllers/Api/KnativeRedisController.php",
	"app/Http/Controllers/Api/KnativeFunctionTlsController.php",
	"app/Http/Controllers/Api/WasmController.php",
}

// regionOperationFields: {message, region} of a queued region operation.
var regionOperationFields = concat([]string{"message"}, prefix("region.", regionDetailFields))

var regionNodeFields = []string{"name", "status", "cpu_m", "memory_mb", "available_cpu_m", "available_memory_mb",
	"is_selected", "labels"}

var regionCreateRequest = []string{"name", "kubeconfig", "nodes", "is_active", "redis_mode", "redis_address",
	"redis_password", "prometheus_url", "loki_url", "loki_org_id"}

// registryConfigRequest: the registry fields of a region (POST …/registry; part of registrySetupRequest).
var registryConfigRequest = []string{"registry_mode", "storage_type", "pvc_config.storageClass", "pvc_config.size",
	"s3_config.endpoint", "s3_config.bucket", "s3_config.region", "s3_config.access_key", "s3_config.secret_key",
	"external_address", "external_username", "external_password"}

var registrySetupRequest = []string{"name", "kubeconfig", "nodes", "registry_mode", "storage_type",
	"pvc_config.storageClass", "pvc_config.size", "s3_config.endpoint", "s3_config.bucket", "s3_config.region",
	"s3_config.access_key", "s3_config.secret_key", "external_address", "external_username", "external_password",
	"redis_mode", "redis_address", "redis_password", "prometheus_url", "loki_url", "loki_org_id"}

var capacityBudgetFields = []string{"capacity", "always_on_used", "always_on_available", "faas_overcommit",
	"faas_budget", "faas_used", "faas_available", "utilization"}

var regionCapacityFields = concat([]string{"region_id", "faas_cpu_overcommit", "faas_memory_overcommit"},
	prefix("cpu_m.", capacityBudgetFields), prefix("memory_mb.", capacityBudgetFields))

var regionCapacitySources = []string{
	"app/Http/Controllers/Api/RegionCapacityController.php",
	"app/Services/Helpers/RegionCapacity.php",
}

var regionWasmFields = concat([]string{"status", "message", "shims", "nodes", "spin_runtime", "warning",
	"can_manage", "cluster_error", "cluster.runtime_classes", "cluster.knative_features_to_enable",
	"cluster.installer.desired", "cluster.installer.ready", "cluster.installer.shims"},
	prefix("available_shims[].", []string{"id", "name", "version", "runtime_class", "handler", "description"}),
	prefix("cluster.nodes[].", []string{"name", "arch", "flavor", "runtime_version", "eligible", "reason", "target", "shims"}))

var regionWasmSources = []string{
	"app/Http/Controllers/Api/WasmController.php",
	"app/Services/Wasm/WasmShim.php",
	"app/Services/Wasm/WasmInstallService.php",
	"app/Services/Wasm/WasmNodes.php",
}

func init() {
	Contract = append(Contract,
		// Create, change, delete; the kubeconfig and the settings
		Endpoint{Method: "POST", Path: "/knative-regions", Status: 201, Permission: "regions.manage",
			Request: regionCreateRequest, Response: regionDetailFields, Sources: regionDetailSources},
		Endpoint{Method: "PUT", Path: "/knative-regions/{region}", Status: 200, Permission: "regions.manage",
			Request: []string{"kubeconfig", "region_status"}, Response: regionDetailFields, Sources: regionDetailSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{region}", Status: 204, Permission: "regions.manage"},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/kubeconfig", Status: 200, Permission: "regions.manage"},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/monitoring", Status: 200, Permission: "regions.manage",
			Request: []string{"prometheus_url", "loki_url", "loki_org_id"}, Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/settings", Status: 200, Permission: "regions.manage",
			Request:  []string{"request_timeout", "agent_ws_url", "cert_manager_cluster_issuer", "ingress_trusted_ips"},
			Response: concat([]string{"message", "settings"}, prefix("region.", regionDetailFields)), Sources: regionDetailSources},

		// Checks of a cluster before it becomes a region
		Endpoint{Method: "POST", Path: "/knative-regions/validate-kubeconfig", Status: 200, Permission: "regions.manage",
			Request: []string{"kubeconfig"}, Response: []string{"valid", "version", "kubeconfig"}},
		Endpoint{Method: "POST", Path: "/knative-regions/preview-nodes", Status: 200, Permission: "regions.manage",
			Request: []string{"kubeconfig"}, Response: prefix("nodes[].", regionNodeFields),
			Sources: []string{"app/Services/KnativeNodeService.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/preview-storage-classes", Status: 200, Permission: "regions.manage",
			Request: []string{"kubeconfig"}, Response: []string{"storageClasses[].name", "storageClasses[].is_default"},
			Sources: []string{"app/Http/Controllers/Api/KnativeRegionController.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/check-namespace-preview", Status: 200, Permission: "regions.manage",
			Request: []string{"kubeconfig", "name"}, Response: []string{"exists"}},

		// Cluster status and the Knative components
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/knative-status", Query: []string{"force"}, Status: 200,
			Permission: "regions.view", Response: concat([]string{"status", "message", "details"}, prefix("region.", regionDetailFields)),
			Sources: regionDetailSources},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/component-status", Status: 200, Permission: "regions.view",
			Response: concat(prefix("operator.", []string{"status", "message", "namespace"}),
				prefix("serving.", []string{"status", "message", "namespace", "ingress_class", "version"}),
				prefix("region.", regionDetailFields)),
			Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/install-knative", Status: 200, Permission: "regions.manage",
			Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/install-operator", Status: 200, Permission: "regions.manage",
			Request: []string{"namespace"}, Response: concat([]string{"adopted"}, regionOperationFields), Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/install-serving", Status: 200, Permission: "regions.manage",
			Request: []string{"namespace", "version"}, Response: concat([]string{"adopted"}, regionOperationFields),
			Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/upgrade-serving", Status: 200, Permission: "regions.manage",
			Request: []string{"version"}, Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/uninstall-knative", Status: 200, Permission: "regions.manage",
			Request: []string{"remove_traefik"}, Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/uninstall-operator", Status: 200, Permission: "regions.manage",
			Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/uninstall-serving", Status: 200, Permission: "regions.manage",
			Request: []string{"remove_traefik"}, Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/detect-components", Status: 200, Permission: "regions.manage",
			Response: concat([]string{"operator.adopted", "operator.namespace", "serving.adopted", "serving.namespace"},
				prefix("region.", regionDetailFields)),
			Sources: append([]string{"app/Services/Concerns/KnativeAdoptTrait.php"}, regionDetailSources...)},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/check-namespace", Query: []string{"name"}, Status: 200,
			Permission: "regions.view", Response: []string{"exists"}},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/compatible-versions", Status: 200, Permission: "regions.view",
			Response: []string{"versions"}},

		// Worker nodes
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/nodes", Status: 200, Permission: "regions.view",
			Response: prefix("nodes[].", regionNodeFields), Sources: []string{"app/Services/KnativeNodeService.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/workers", Status: 200, Permission: "regions.manage",
			Request: []string{"nodes"}, Response: concat([]string{"total_cpu_m", "total_memory_mb"}, regionOperationFields),
			Sources: regionDetailSources},

		// Queue Redis
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/redis-status", Status: 200, Permission: "regions.view",
			Response: []string{"redis_mode", "knative_redis_status", "redis_address", "knative_redis_status_message", "has_redis"},
			Sources:  []string{"app/Http/Controllers/Api/KnativeRedisController.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/test-redis", Status: 200, Permission: "regions.manage",
			Request: []string{"address", "password"}, Response: []string{"reachable", "message"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/redis", Status: 202, Permission: "regions.manage",
			Request: []string{"mode", "address", "password"}, Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/redis", Status: 200, Permission: "regions.manage",
			Response: regionOperationFields, Sources: regionDetailSources},

		// Image registry and the setup of a region with a registry
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/registry-status", Status: 200, Permission: "regions.view",
			Response: []string{"registry_mode", "registry_address", "registry_status", "registry_status_message", "has_registry"},
			Sources:  []string{"app/Http/Controllers/Api/RegistryController.php"}},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/registry", Status: 200, Permission: "registry.manage",
			Response: regionDetailFields, Sources: regionDetailSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/registry", Status: 202, Permission: "registry.manage",
			Request: registryConfigRequest, Response: regionOperationFields,
			Sources: append([]string{"app/Http/Controllers/Api/RegistryController.php"}, regionDetailSources...)},
		Endpoint{Method: "POST", Path: "/registry-setup/start", Status: 202, Permission: "regions.manage+registry.manage",
			Request: registrySetupRequest, Response: []string{"setup_token", "setup_id"}},
		Endpoint{Method: "POST", Path: "/registry-setup/{token}/retry", Status: 202, Permission: "regions.manage+registry.manage",
			Request: registrySetupRequest, Response: []string{"setup_token", "setup_id"}},
		Endpoint{Method: "GET", Path: "/registry-setup/{token}/status", Status: 200, Permission: "regions.view",
			Response: []string{"status", "phase", "message", "region_id", "address"},
			Sources:  []string{"app/Http/Controllers/Api/RegistrySetupController.php", "app/Jobs/InstallRegistryAndCreateRegionJob.php"}},
		Endpoint{Method: "POST", Path: "/registry-setup/test-pvc", Status: 200, Permission: "registry.manage",
			Request: []string{"kubeconfig", "storageClass", "size", "namespace"}, Response: []string{"success", "message"}},
		Endpoint{Method: "POST", Path: "/registry-setup/test-s3", Status: 200, Permission: "registry.manage",
			Request: []string{"kubeconfig", "s3_config.endpoint", "s3_config.bucket", "s3_config.region", "s3_config.access_key",
				"s3_config.secret_key", "namespace"},
			Response: []string{"success", "message"}},
		Endpoint{Method: "POST", Path: "/registry-setup/test-external", Status: 200, Permission: "registry.manage",
			Request: []string{"kubeconfig", "url", "username", "password", "namespace"}, Response: []string{"success", "message"}},

		// Migration to another cluster
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/migrate", Status: 200, Permission: "regions.manage",
			Request: []string{"kubeconfig", "data_location"}, Response: []string{"message", "status"}},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/migration-status", Status: 200, Permission: "regions.view",
			Response: []string{"migration_status", "migration_started_at", "migration_old_ip", "current_ip", "is_migrating"},
			Sources:  []string{"app/Http/Controllers/Api/RegionMigrateController.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/complete-migration", Status: 200, Permission: "regions.manage",
			Response: []string{"message"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/complete-domain-migration", Status: 200, Permission: "regions.manage",
			Response: regionOperationFields,
			Sources:  append([]string{"app/Http/Controllers/Api/RegionMigrateController.php"}, regionDetailSources...)},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/rollback-migration", Status: 200, Permission: "regions.manage",
			Response: []string{"message"}},

		// Function-domain TLS, Skupper, capacity, demo projects
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/function-tls", Status: 200, Permission: "regions.manage",
			Request: []string{"mode", "certificate", "privateKey", "replace_existing"}, Response: regionOperationFields,
			Sources: regionDetailSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/function-tls", Status: 200, Permission: "regions.manage",
			Response: regionOperationFields, Sources: regionDetailSources},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/skupper", Status: 200, Permission: "regions.view",
			Response: []string{"data.available", "data.api_version", "data.namespace", "data.site.name", "data.site.status",
				"data.site.message"},
			Sources: []string{"app/Services/Skupper/SkupperService.php"}},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/skupper/site", Status: 200, Permission: "regions.manage",
			Response: []string{"data.name", "data.status", "data.message"}, Sources: []string{"app/Services/Skupper/SkupperService.php"}},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/capacity", Status: 200, Permission: "regions.view",
			Response: prefix("data.", regionCapacityFields), Sources: regionCapacitySources},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/capacity", Status: 200, Permission: "regions.manage",
			Request:  []string{"faas_cpu_overcommit", "faas_memory_overcommit"},
			Response: concat([]string{"message"}, prefix("data.", regionCapacityFields)), Sources: regionCapacitySources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/demo-projects", Status: 200, Permission: "regions.manage",
			Response: []string{"region_demo_status"}},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/demo-projects", Status: 200, Permission: "regions.manage",
			Response: []string{"region_demo_status"}},

		// WebAssembly support (docs/wasm.md)
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/wasm", Query: []string{"cluster"}, Status: 200,
			Permission: "regions.view", Response: regionWasmFields, Sources: regionWasmSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/wasm", Status: 202, Permission: "sandbox.manage",
			Request: []string{"shims", "nodes"}, Response: concat([]string{"warning"}, regionOperationFields),
			Sources: regionDetailSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/wasm", Status: 202, Permission: "sandbox.manage",
			Response: regionOperationFields, Sources: regionDetailSources},
	)
}
