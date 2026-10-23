package runr

// Runtime patches (docs/runtimes.md#runtime-patches; api/routes/runtime-patches.php). The
// controllers build the answers by hand: the fields are checked against them and the models.
// Optional: APIs before runtime patches answer 404 (ErrUnsupported).
var runtimePatchSources = []string{
	"app/Http/Controllers/Api/RuntimePatchController.php",
	"app/Http/Controllers/Api/ProjectRuntimePatchController.php",
	"app/Models/RuntimePatch.php",
	"app/Models/RuntimePatchMigration.php",
	"app/Services/Runtimes/Patches/RuntimePatches.php",
}

var runtimePatchFields = []string{
	"id", "runtime", "version", "tag", "image", "digest", "level", "reason", "reasons", "cves", "url",
	"upstream", "published_at", "deadline_at", "deadline_extended", "in_feed",
}

// runtimePatchStateFields: ProjectResource.runtime_patch (RuntimePatches::state()).
var runtimePatchStateFields = []string{
	"state", "level", "deadline_at", "deployed", "current.id", "current.tag", "current.level", "current.digest",
	"current.published_at", "latest.id", "latest.tag", "latest.level", "latest.digest", "latest.published_at",
	"pending", "pin.id", "pin.tag", "pin.digest", "pin.available", "pin.by",
}

var runtimePatchMigrationFields = []string{
	"id", "patch_id", "trigger", "status", "deployment_id", "queued_deploy_id", "previous_patch_id",
	"message", "created_at", "finished_at",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/runtime-patches", Query: []string{"runtime", "version"}, Status: 200, Optional: true,
			Response: concat(prefix("data[].", runtimePatchFields), []string{"feed.enabled", "feed.status", "feed.fetched_at", "keep"}),
			Sources:  runtimePatchSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/runtime-patches", Status: 200, Permission: "projects.view", Optional: true,
			Response: concat(prefix("data.state.", runtimePatchStateFields), prefix("data.patches[].", runtimePatchFields),
				[]string{"data.keep", "data.auto_migrate"}, prefix("data.migrations[].", runtimePatchMigrationFields)),
			Sources: runtimePatchSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/runtime-patches/apply", Status: 202, Permission: "projects.deploy", Optional: true,
			Response: concat(prefix("data.", runtimePatchMigrationFields), []string{"message"}), Sources: runtimePatchSources},
		// 200 with the state; 202 with the state and the migration when it redeploys onto the pin
		Endpoint{Method: "PUT", Path: "/projects/{project}/runtime-patches/pin", Status: 200, Permission: "projects.deploy", Optional: true,
			Request:  []string{"patch_id", "deploy"},
			Response: concat(prefix("data.", runtimePatchStateFields), prefix("202:migration.", runtimePatchMigrationFields)),
			Sources:  runtimePatchSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/runtime-patches/pin", Status: 200, Permission: "projects.deploy", Optional: true,
			Response: prefix("data.", runtimePatchStateFields), Sources: runtimePatchSources},
	)
}
