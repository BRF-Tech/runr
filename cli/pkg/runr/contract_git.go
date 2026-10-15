package runr

// Git deploy (docs/git-deploy.md; api/routes/git-deploy.php). The controllers build their
// answers by hand (GitProjectController::payload, the toPublicArray of the models), so the
// fields are checked against those files where the generator leaves a schema untyped.

var gitInfoFields = []string{
	"remote_url", "remote_ready", "remote_head", "read_only",
	"settings.deploy_branch", "settings.root_dir", "settings.auto_deploy", "settings.allow_force_push",
	"settings.previews_enabled", "settings.preview_limit", "settings.preview_ttl_hours", "settings.preview_env",
	"settings.fork_previews", "settings.fork_secrets",
	"connection.id", "connection.provider_id", "connection.provider_type", "connection.provider_name",
	"connection.repo_full_name", "connection.repo_url", "connection.default_branch", "connection.auth_mode",
	"connection.installation_id", "connection.webhook_registered", "connection.last_event_at",
	"providers[].id", "providers[].type", "providers[].name", "providers[].base_url", "providers[].connect_modes",
	"limits.preview_limit_max", "limits.preview_ttl_max", "limits.max_push_mb",
	"abilities.pull", "abilities.push", "abilities.manage", "abilities.manage_previews", "username_hint",
}

var gitDeployFields = []string{
	"id", "kind", "trigger", "provider", "sha", "short_sha", "branch", "author_name", "message", "url",
	"pr_number", "status", "status_message", "warnings", "skipped", "applied", "version.id", "version.number",
	"version.label", "deployment_id", "preview_id", "created_at",
}

var gitPreviewFields = []string{
	"id", "number", "provider", "title", "branch", "base_branch", "head_sha", "short_sha", "author", "pr_url",
	"is_fork", "service_name", "url", "status", "status_message", "last_deployed_at", "expires_at", "closed_at",
	"created_at",
}

var gitProviderFields = []string{
	"id", "type", "name", "base_url", "api_url", "web_url", "app_id", "app_slug", "client_id", "is_enabled",
	"configured", "connect_modes", "has_client_secret", "has_private_key", "has_webhook_secret", "links_count",
	"urls", "created_at",
}

var gitCredentialFields = []string{"provider_id", "installation_id", "access_token", "token_ref"}

var (
	gitProjectSources = []string{"app/Http/Controllers/Api/GitProjectController.php", "app/Models/Git/GitRepoLink.php"}
	gitDeploySources  = []string{"app/Http/Controllers/Api/GitProjectController.php", "app/Models/Git/GitDeploy.php"}
	gitPreviewSources = []string{"app/Http/Controllers/Api/GitPreviewController.php", "app/Models/Git/PreviewEnvironment.php"}
)

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/git", Status: 200, Permission: "git.pull",
			Response: prefix("data.", gitInfoFields), Sources: gitProjectSources},
		Endpoint{Method: "PUT", Path: "/projects/{project}/git", Status: 200, Permission: "git.manage",
			Request: []string{"deploy_branch", "root_dir", "auto_deploy", "allow_force_push", "previews_enabled",
				"preview_limit", "preview_ttl_hours", "preview_env", "fork_previews", "fork_secrets"},
			FreeForm: []string{"preview_env"}, Response: prefix("data.", gitInfoFields), Sources: gitProjectSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/git/deploys", Query: []string{"kind", "per_page"}, Status: 200,
			Permission: "git.pull",
			Response:   concat(prefix("data[].", gitDeployFields), []string{"meta.total", "meta.current_page", "meta.last_page"}),
			Sources:    gitDeploySources},
		Endpoint{Method: "POST", Path: "/projects/{project}/git/redeploy", Status: 202, Permission: "git.push",
			Response: prefix("data.", gitDeployFields), Sources: gitDeploySources},
		Endpoint{Method: "POST", Path: "/projects/{project}/git/repos", Status: 200, Permission: "git.manage",
			Request:  concat(gitCredentialFields, []string{"search"}),
			Response: prefix("data[].", []string{"id", "full_name", "url", "default_branch", "private"}),
			Sources: []string{"app/Http/Controllers/Api/GitProjectController.php",
				"app/Services/GitDeploy/Providers/GitHubClient.php"}},
		Endpoint{Method: "POST", Path: "/projects/{project}/git/connect", Status: 201, Permission: "git.manage",
			Request: concat(gitCredentialFields, []string{"repository"}), Response: prefix("data.", gitInfoFields),
			Sources: gitProjectSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/git/connect", Status: 200, Permission: "git.manage",
			Response: prefix("data.", gitInfoFields), Sources: gitProjectSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/git/installations", Query: []string{"provider_id"}, Status: 200,
			Permission: "git.pull",
			Response:   prefix("data[].", []string{"id", "installation_id", "account_login", "account_type"}),
			Sources:    []string{"app/Http/Controllers/Api/GitProjectController.php"}},
		Endpoint{Method: "POST", Path: "/projects/{project}/git/github/install", Status: 200, Permission: "git.manage",
			Request: []string{"provider_id"}, Response: []string{"url"},
			Sources: []string{"app/Http/Controllers/Api/GitProjectController.php"}},
		Endpoint{Method: "POST", Path: "/projects/{project}/git/gitlab/authorize", Status: 200, Permission: "git.manage",
			Request: []string{"provider_id"}, Response: []string{"url"},
			Sources: []string{"app/Http/Controllers/Api/GitProjectController.php"}},
		Endpoint{Method: "GET", Path: "/projects/{project}/previews", Query: []string{"all"}, Status: 200,
			Permission: "git.pull", Response: prefix("data[].", gitPreviewFields), Sources: gitPreviewSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/previews/{preview}/redeploy", Status: 202,
			Permission: "previews.manage", Response: prefix("data.", gitPreviewFields), Sources: gitPreviewSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/previews/{preview}", Status: 200,
			Permission: "previews.manage", Response: prefix("data.", gitPreviewFields), Sources: gitPreviewSources},
		Endpoint{Method: "GET", Path: "/git-providers", Status: 200, Permission: "git_providers.manage",
			Response: prefix("data[].", gitProviderFields),
			Sources:  []string{"app/Http/Controllers/Api/GitProviderController.php"}},
	)
}
