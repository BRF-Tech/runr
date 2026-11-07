package runr

// Runtime sources (docs/runtimes.md#runtime-sources): `runr runtimes sources`, `runr runtimes
// catalog`, the runr_runtime_source resource and data source. Optional: APIs before runtime
// sources answer 404 (ErrUnsupported).

var runtimeSourceFields = []string{
	"id", "name", "kind", "scope", "tenant_id", "is_default", "url", "ref", "path", "public_key", "patch_feed",
	"registry_host", "credentials.git", "credentials.git_username", "credentials.git_token_hint",
	"credentials.ssh_key_set", "credentials.known_hosts_set", "credentials.registry_username",
	"credentials.registry_password_set", "is_enabled", "interval_minutes", "status", "error", "problems[].file",
	"problems[].runtime", "problems[].path", "problems[].message", "revision", "signature_verified",
	"runtimes_count", "checked_at", "synced_at", "next_check_at", "created_by", "created_at", "updated_at",
}

// runtimeSourceRequest: the fields of POST / PUT /runtime-sources (and /test). The credentials
// are write-only.
var runtimeSourceRequest = []string{
	"name", "kind", "url", "ref", "path", "public_key", "patch_feed", "registry_host", "interval_minutes",
	"is_enabled", "git_token", "git_username", "ssh_private_key", "ssh_known_hosts", "registry_username",
	"registry_password",
}

// runtimeLifecycleFields: RuntimeResource's lifecycle and source fields (APIs with runtime
// sources), read only when the API sends them.
var runtimeLifecycleFields = []string{
	"source_id", "source_name", "source_path", "managed", "lifecycle.state", "lifecycle.deprecated_at",
	"lifecycle.sunset_at", "lifecycle.orphaned_at", "lifecycle.replacement", "lifecycle.notice",
	"versions[].deprecated_at", "versions[].sunset_at", "versions[].ends_at", "versions[].lifecycle",
}

// The controllers validate in a helper (no request schema) and build the runtimes of a source by
// hand (RuntimeResource resolved): the fields are checked against them.
var runtimeSourceSources = []string{
	"app/Http/Controllers/Api/RuntimeSourceController.php",
	"app/Models/RuntimeSource.php",
	"app/Http/Resources/RuntimeResource.php",
	"app/Services/Runtimes/RuntimeLifecycle.php",
	"app/Models/RuntimePatch.php",
	"app/Models/RuntimePatchFeed.php",
}

var builtinCatalogFields = []string{
	"mode", "bundle", "builtin_runtimes", "saved", "runtimes[].id", "runtimes[].name", "runtimes[].offered",
	"runtimes[].in_bundle",
}

const runtimeSourceAdmin = "runtimes.manage|runtimes.use_custom"

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/runtime-sources", Status: 200, Permission: runtimeSourceAdmin, Optional: true,
			Response: concat(prefix("data[].", runtimeSourceFields), []string{"catalog.mode", "catalog.runtimes",
				"abilities.manage", "abilities.organization", "custom_enabled"}),
			Sources: runtimeSourceSources},
		Endpoint{Method: "POST", Path: "/runtime-sources", Status: 201, Permission: runtimeSourceAdmin, Optional: true,
			Request: runtimeSourceRequest, Response: prefix("data.", runtimeSourceFields), Sources: runtimeSourceSources},
		// `source`: test changes of a connected source with its stored credentials
		Endpoint{Method: "POST", Path: "/runtime-sources/test", Status: 200, Permission: runtimeSourceAdmin, Optional: true,
			Request: append([]string{"source"}, runtimeSourceRequest...),
			Response: concat(prefix("data.", []string{"ok", "status", "error", "revision", "signature_verified"}),
				prefix("data.runtimes[].", []string{"id", "path", "valid"}),
				prefix("data.problems[].", []string{"file", "runtime", "path", "message"})),
			Sources: runtimeSourceSources},
		Endpoint{Method: "GET", Path: "/runtime-sources/{source}", Status: 200, Permission: runtimeSourceAdmin, Optional: true,
			Response: concat(prefix("data.source.", runtimeSourceFields),
				prefix("data.runtimes[].", []string{"id", "name", "source", "run_mode", "versions[].version",
					"versions[].is_default", "versions[].eol_at", "versions[].image", "is_enabled", "projects_count"}),
				prefix("data.runtimes[].", runtimeLifecycleFields),
				prefix("data.patches[].", []string{"id", "runtime", "version", "tag", "level", "reason", "cves",
					"published_at", "deadline_at"}),
				prefix("data.patch_feed.", []string{"reference", "enabled", "status", "error", "signature_verified",
					"generated_at", "checked_at", "fetched_at"})),
			Sources: runtimeSourceSources},
		// RunR's catalog (is_default) takes is_enabled and interval_minutes only
		Endpoint{Method: "PUT", Path: "/runtime-sources/{source}", Status: 200, Permission: runtimeSourceAdmin, Optional: true,
			Request: runtimeSourceRequest, Response: prefix("data.", runtimeSourceFields), Sources: runtimeSourceSources},
		// 422 for RunR's catalog
		Endpoint{Method: "DELETE", Path: "/runtime-sources/{source}", Status: 204, Permission: runtimeSourceAdmin, Optional: true},
		// 502 with the source when the read failed or did not verify; 409 while a read runs
		Endpoint{Method: "POST", Path: "/runtime-sources/{source}/sync", Status: 200, Permission: runtimeSourceAdmin, Optional: true,
			Response: concat(prefix("data.", runtimeSourceFields), prefix("502:data.", []string{"id", "name", "status", "error", "problems[].path"})),
			Sources:  runtimeSourceSources},

		Endpoint{Method: "GET", Path: "/runtime-catalog", Status: 200, Permission: "runtimes.manage", Optional: true,
			Response: concat(prefix("data.", builtinCatalogFields), prefix("data.source.", []string{"id", "name", "url", "status", "revision", "synced_at"})),
			Sources:  []string{"app/Http/Controllers/Api/RuntimeCatalogController.php", "app/Models/RuntimeSource.php"}},
		Endpoint{Method: "PUT", Path: "/runtime-catalog", Status: 200, Permission: "runtimes.manage", Optional: true,
			Request:  []string{"builtin_runtimes"},
			Response: concat(prefix("data.", builtinCatalogFields), prefix("data.source.", []string{"id", "name", "url", "status", "revision", "synced_at"})),
			Sources:  []string{"app/Http/Controllers/Api/RuntimeCatalogController.php", "app/Models/RuntimeSource.php"}},
	)
}
