package runr

// Contract entries of models, model services, uploads and project model bindings
// (api/routes/ai.php, docs/huggingface.md).

var aiModelFields = []string{
	"id", "name", "source", "hub_id", "revision", "revision_sha", "task", "library", "license", "gated",
	"private", "size_bytes", "parameters", "card_summary", "tags", "source_ref", "created_at",
	"snapshots[].id", "snapshots[].knative_region_id", "snapshots[].region_name", "snapshots[].status",
	"snapshots[].message", "services_count",
}

var modelSnapshotFields = []string{
	"id", "ai_model_id", "knative_region_id", "region_name", "source", "revision", "status", "message",
	"size_bytes", "files_count", "started_at", "completed_at",
}

var modelServiceFields = []string{
	"id", "name", "service_name", "ai_model_id", "model.id", "model.name", "model.hub_id", "model.task",
	"model.library", "model.size_bytes", "model.source", "knative_region_id", "region_name", "engine",
	"device", "mode", "api", "gpu", "cpu", "memory_mb", "min_scale", "max_scale", "scale_to_zero",
	"timeout_seconds", "image", "args", "env_vars", "port", "health_path", "served_name", "status",
	"status_message", "url", "openai_base", "playground.kind", "playground.path", "bindings_count",
	"deployed_at", "created_at",
}

// modelServiceSettings are the settings of POST and PATCH /model-services (settingsRules).
var modelServiceSettings = []string{
	"mode", "device", "engine", "gpu.count", "gpu.type", "gpu.memory_min", "cpu", "memory_mb", "min_scale",
	"max_scale", "scale_to_zero", "timeout_seconds", "image", "args", "env_vars", "port", "health_path",
	"served_name",
}

var modelBindingFields = []string{
	"id", "project_id", "project.id", "project.name", "ai_model_service_id", "alias", "mount_weights", "env",
	"service.id", "service.name", "service.status", "applies_on_next_deploy",
}

var modelUploadFields = []string{
	"id", "ai_model_id", "knative_region_id", "status", "chunk_size", "files[].path", "files[].size",
	"files[].uploaded_bytes", "snapshot_id", "message", "expires_at",
}

const (
	aiModelResourceSrc    = "app/Http/Resources/Ai/AiModelResource.php"
	aiSnapshotResourceSrc = "app/Http/Resources/Ai/AiModelSnapshotResource.php"
	aiServiceResourceSrc  = "app/Http/Resources/Ai/AiModelServiceResource.php"
	aiBindingResourceSrc  = "app/Http/Resources/Ai/AiModelBindingResource.php"
	aiUploadResourceSrc   = "app/Http/Resources/Ai/AiModelUploadResource.php"
	aiEngineCatalogSrc    = "app/Services/Ai/Engines/EngineCatalog.php"
	aiServiceCtrlSrc      = "app/Http/Controllers/Api/Ai/ModelServiceController.php"
	aiGpuRequestSrc       = "app/Services/Ai/Gpu/GpuRequest.php"
)

var modelsContract = []Endpoint{
	// Hub browser, engines, token
	{Method: "GET", Path: "/models/hub/search", Query: []string{"search", "task", "library", "sort", "limit", "cursor"},
		Status: 200, Permission: "models.view",
		Response: concat(prefix("data[].", []string{"id", "author", "task", "library", "downloads", "likes", "gated",
			"private", "last_modified", "tags"}), []string{"next_cursor"}),
		Sources: []string{"app/Services/Ai/Hub/HubClient.php"}},
	{Method: "GET", Path: "/models/hub/info", Query: []string{"id", "revision", "knative_region_id"}, Status: 200,
		Permission: "models.view",
		Response: []string{"id", "sha", "task", "library", "license", "gated", "private", "downloads", "likes", "tags",
			"size_bytes", "parameters", "files[].name", "files[].size", "card_summary", "last_modified",
			"engines[].engine", "engines[].devices", "engines[].recommended", "engines[].api", "suggested", "warnings"}},
	{Method: "GET", Path: "/models/engines", Status: 200, Permission: "models.view",
		Response: []string{"engines", "tasks[].task", "tasks[].engines", "tasks[].default"},
		Sources:  []string{aiEngineCatalogSrc}},
	{Method: "GET", Path: "/models/hub-token", Status: 200, Permission: "models.view",
		Response: []string{"configured", "hint", "updated_at"}},
	{Method: "PUT", Path: "/models/hub-token", Status: 200, Permission: "models.manage",
		Request: []string{"token", "validate"}, Response: []string{"configured", "hint", "updated_at"}},
	{Method: "DELETE", Path: "/models/hub-token", Status: 200, Permission: "models.manage",
		Response: []string{"configured"}},

	// Library
	{Method: "GET", Path: "/models", Status: 200, Permission: "models.view",
		Response: prefix("data[].", aiModelFields), Sources: []string{aiModelResourceSrc, aiSnapshotResourceSrc}},
	{Method: "POST", Path: "/models", Status: 201, Permission: "models.deploy|models.manage",
		Request:  []string{"source", "hub_id", "revision", "name", "task", "library", "path", "knative_region_id", "reference"},
		Response: aiModelFields, Sources: []string{aiSnapshotResourceSrc}},
	{Method: "GET", Path: "/models/{model}", Status: 200, Permission: "models.view",
		Response: aiModelFields, Sources: []string{aiSnapshotResourceSrc}},
	{Method: "PATCH", Path: "/models/{model}", Status: 200, Permission: "models.deploy",
		Request: []string{"name", "task", "library"}, Response: aiModelFields, Sources: []string{aiSnapshotResourceSrc}},
	{Method: "DELETE", Path: "/models/{model}", Status: 204, Permission: "models.manage"},

	// Snapshots (offline copies)
	{Method: "GET", Path: "/models/{model}/snapshots", Status: 200, Permission: "models.view",
		Response: prefix("data[].", modelSnapshotFields), Sources: []string{aiSnapshotResourceSrc}},
	{Method: "POST", Path: "/models/{model}/snapshots", Status: 202, Permission: "models.deploy|models.manage",
		Request: []string{"knative_region_id"}, Response: modelSnapshotFields},
	{Method: "DELETE", Path: "/models/{model}/snapshots/{snapshot}", Status: 204, Permission: "models.manage"},
	{Method: "GET", Path: "/models/{model}/snapshots/{snapshot}/logs", Status: 200, Permission: "models.view",
		Response: []string{"logs"}},

	// Uploads (air-gapped regions)
	{Method: "POST", Path: "/models/{model}/uploads", Status: 201, Permission: "models.deploy",
		Request:  []string{"knative_region_id", "files[].path", "files[].size", "files[].sha256"},
		Response: modelUploadFields, Sources: []string{aiUploadResourceSrc}},
	{Method: "GET", Path: "/models/{model}/uploads/{upload}", Status: 200, Permission: "models.view",
		Response: modelUploadFields, Sources: []string{aiUploadResourceSrc}},
	{Method: "PUT", Path: "/models/{model}/uploads/{upload}/chunk", Query: []string{"path", "offset"}, RawBody: true,
		Status: 200, Permission: "models.deploy", Response: []string{"path", "uploaded_bytes"},
		Sources: []string{"app/Http/Controllers/Api/Ai/ModelUploadController.php"}},
	{Method: "POST", Path: "/models/{model}/uploads/{upload}/complete", Status: 200, Permission: "models.deploy",
		Response: concat(prefix("upload.", modelUploadFields), prefix("snapshot.", modelSnapshotFields)),
		Sources:  []string{aiUploadResourceSrc}},
	{Method: "DELETE", Path: "/models/{model}/uploads/{upload}", Status: 204, Permission: "models.deploy"},

	// Model services
	{Method: "GET", Path: "/model-services", Query: []string{"knative_region_id"}, Status: 200, Permission: "models.view",
		Response: prefix("data[].", modelServiceFields), Sources: []string{aiServiceResourceSrc, aiEngineCatalogSrc}},
	{Method: "POST", Path: "/model-services", Status: 201, Permission: "models.deploy",
		Request:  concat([]string{"ai_model_id", "hub_id", "revision", "name", "knative_region_id", "deploy"}, modelServiceSettings),
		Response: modelServiceFields, Sources: []string{aiServiceCtrlSrc, aiGpuRequestSrc}},
	{Method: "GET", Path: "/model-services/{service}", Status: 200, Permission: "models.view", Response: modelServiceFields},
	{Method: "PATCH", Path: "/model-services/{service}", Status: 200, Permission: "models.deploy",
		Request: modelServiceSettings, Response: modelServiceFields, Sources: []string{aiServiceCtrlSrc, aiGpuRequestSrc}},
	{Method: "DELETE", Path: "/model-services/{service}", Status: 204, Permission: "models.deploy"},
	{Method: "POST", Path: "/model-services/{service}/deploy", Status: 202, Permission: "models.deploy",
		Response: modelServiceFields},
	{Method: "POST", Path: "/model-services/{service}/stop", Status: 202, Permission: "models.deploy",
		Response: modelServiceFields},
	{Method: "GET", Path: "/model-services/{service}/logs", Query: []string{"tail"}, Status: 200, Permission: "models.view",
		Response: []string{"pods[].name", "pods[].phase", "pods[].ready", "pods[].logs"},
		Sources:  []string{aiServiceCtrlSrc, "app/Services/Ai/Models/ModelServiceDeployer.php"}},
	{Method: "POST", Path: "/model-services/{service}/invoke", Status: 200, Permission: "models.view",
		Request: []string{"path", "method", "body"}, FreeForm: []string{"body"},
		Response: []string{"status", "duration_ms", "content_type", "body"}},
	{Method: "GET", Path: "/model-services/{service}/bindings", Status: 200, Permission: "models.view",
		Response: prefix("data[].", modelBindingFields), Sources: []string{aiBindingResourceSrc, aiServiceResourceSrc}},

	// Models of a project
	{Method: "GET", Path: "/projects/{project}/models", Status: 200, Permission: "projects.view",
		Response: concat(prefix("data[].", modelBindingFields), []string{"available[].id", "available[].name",
			"available[].engine", "available[].status"}),
		Sources: []string{aiBindingResourceSrc, aiServiceResourceSrc, "app/Http/Controllers/Api/Ai/ProjectModelBindingController.php"}},
	{Method: "POST", Path: "/projects/{project}/models", Status: 201, Permission: "projects.update",
		Request: []string{"ai_model_service_id", "alias", "mount_weights"}, Response: modelBindingFields,
		Sources: []string{aiServiceResourceSrc}},
	{Method: "PATCH", Path: "/projects/{project}/models/{modelBinding}", Status: 200, Permission: "projects.update",
		Request: []string{"alias", "mount_weights"}, Response: modelBindingFields, Sources: []string{aiServiceResourceSrc}},
	{Method: "DELETE", Path: "/projects/{project}/models/{modelBinding}", Status: 204, Permission: "projects.update"},
}

func init() { Contract = append(Contract, modelsContract...) }
