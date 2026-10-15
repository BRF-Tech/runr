package runr

// Runtime catalog and runtime plugins (docs/runtimes.md): `runr runtimes`, `runr init` of a
// plugin, `runr dev` of plugin projects and the runr_runtime resource.

var runtimeFields = []string{
	"id", "name", "description", "language", "extensions", "category", "source", "run_mode", "compiled",
	"triggers", "sandbox", "runtime_class", "handler.file", "handler.function", "handler.filename",
	"handler.pattern", "dependency_file", "versions[].version", "versions[].is_default", "versions[].eol_at",
	"versions[].gpu_required", "versions[].image", "versions[].built", "regions", "is_enabled", "tenant_id",
	"created_by", "projects_count", "updated_at",
}

// manifestFields are the fields of the normalized manifest the CLI reads (runr dev of plugins,
// runtimes get).
var manifestFields = []string{
	"id", "name", "extensions", "versions[].version", "versions[].image", "versions[].dockerfile",
	"versions[].git.url", "versions[].git.ref", "versions[].git.path", "versions[].git.dockerfile",
	"versions[].buildArgs", "versions[].default", "handler.file", "handler.function",
	"dependencies.file", "dependencies.image", "dependencies.install", "dependencies.copy[].from",
	"dependencies.copy[].to", "dependencies.env", "build.image", "build.command", "build.runImage",
	"build.source", "build.files", "run.mode", "run.command", "run.schedule", "run.input", "run.workers",
	"run.env", "triggers",
}

// runtimeDetailFields are the catalog fields plus what GET /runtimes/{id} adds.
var runtimeDetailFields = concat(runtimeFields, []string{"manifest_source", "files"}, prefix("manifest.", manifestFields))

// runtimeSources define the runtime objects the generator types as "string": the resource,
// the normalized manifest (ManifestValidator::normalize) and its JSON Schema.
var runtimeSources = []string{
	"app/Http/Resources/RuntimeResource.php",
	"app/Services/Runtimes/ManifestValidator.php",
	"resources/runtimes/runtime.schema.json",
}

var runtimeBuildFields = []string{"version", "knative_region_id", "region_name", "status", "image", "error", "updated_at"}

const runtimeAdmin = "runtimes.manage|runtimes.use_custom"

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/runtimes", Query: []string{"all", "region"}, Status: 200, Optional: true,
			Response: concat(prefix("data[].", runtimeFields), []string{"abilities.manage", "abilities.use_custom", "custom_enabled"}),
			Sources:  append([]string{"app/Http/Controllers/Api/RuntimeController.php"}, runtimeSources...),
			// WebAssembly runtimes name their shim
			Capabilities: []string{"data[].wasm"}},
		Endpoint{Method: "GET", Path: "/runtimes/{runtime}", Status: 200,
			Response: prefix("data.", runtimeDetailFields), Sources: runtimeSources,
			Capabilities: []string{"data.wasm"}},
		Endpoint{Method: "GET", Path: "/runtimes/{runtime}/template", Query: []string{"service_type", "handler"}, Status: 200,
			Optional: true, Response: []string{"files"}},
		Endpoint{Method: "POST", Path: "/runtimes/validate", Status: 200, Permission: runtimeAdmin,
			Request: []string{"manifest", "files"}, FreeForm: []string{"files"},
			Response: concat([]string{"valid"}, prefix("manifest.", []string{"id", "name", "run.mode", "versions[].version"})),
			Sources:  runtimeSources},
		Endpoint{Method: "POST", Path: "/runtimes", Status: 201, Permission: runtimeAdmin,
			Request: []string{"manifest", "files", "regions"}, FreeForm: []string{"files"},
			Response: prefix("data.", runtimeDetailFields), Sources: runtimeSources},
		Endpoint{Method: "PUT", Path: "/runtimes/{runtime}", Status: 200, Permission: runtimeAdmin,
			Request: []string{"manifest", "files", "regions", "is_enabled"}, FreeForm: []string{"files"},
			Response: prefix("data.", runtimeDetailFields), Sources: runtimeSources},
		Endpoint{Method: "DELETE", Path: "/runtimes/{runtime}", Status: 204, Permission: runtimeAdmin},
		Endpoint{Method: "GET", Path: "/runtimes/{runtime}/builds", Status: 200, Permission: runtimeAdmin,
			Response: prefix("data[].", runtimeBuildFields),
			Sources:  []string{"app/Http/Controllers/Api/RuntimeController.php"}},
		Endpoint{Method: "POST", Path: "/runtimes/{runtime}/builds", Status: 202, Permission: runtimeAdmin,
			Request: []string{"knative_region_id", "version"}, Response: prefix("data[].", runtimeBuildFields),
			Sources: []string{"app/Http/Controllers/Api/RuntimeController.php"}},
		Endpoint{Method: "POST", Path: "/runtimes/{runtime}/test-deploy", Status: 201, Permission: runtimeAdmin,
			Request:  []string{"knative_region_id", "version"},
			Response: []string{"data.id", "data.name", "data.slug", "data.endpoint_url"}},
	)
}
