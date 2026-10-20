package runr

import (
	"regexp"
	"strings"
)

// Endpoint declares one API endpoint the client calls and the fields it relies on. The
// contract test (contract_test.go) checks every entry against the OpenAPI document exported
// from the API (testdata/openapi.json, `make openapi`), and the fake API (runrtest) rejects
// any request that no entry declares, so a client method cannot use an undeclared endpoint.
type Endpoint struct {
	Method string
	// Path is relative to the API root, path parameters in braces: /projects/{project}.
	Path string
	// Query lists the query parameters the client sends.
	Query []string
	// Request lists request body fields. "a.b" is a nested field, "a[].b" a field of the
	// items of array a.
	Request []string
	// Response lists response body fields of the success answer, same syntax. A "202:"
	// prefix names another status than Status.
	Response []string
	// Status is the success status whose response schema Response is checked against.
	Status int
	// Sources are files under api/ that define fields the OpenAPI generator could not infer
	// (Scramble emits an untyped schema for arrays built by hand, e.g. resource collections
	// passed through resolve()). A field whose schema is untyped must appear there as a
	// quoted PHP key.
	Sources []string
	// NotInSpec marks a route the generator does not document (closure routes); its path must
	// then appear in Sources (routes/api.php).
	NotInSpec bool
	// Capabilities are fields the client uses only when the API sends them (feature
	// detection); they are reported, not required.
	Capabilities []string
	// Optional marks an endpoint of newer APIs the client detects (a 404 means "not
	// supported"); the contract test checks it when the OpenAPI document has it.
	Optional bool
	// Permission is what the route requires under role-based access control
	// (api/config/route_permissions.php): "" for any signed-in user, "a|b" for any of them,
	// "a+b" for all of them, "@platform" for platform administrators. Token scopes must cover
	// it; runrtest enforces it for scoped tokens.
	Permission string
	// FreeForm lists request fields whose value is an object with caller-chosen keys (an
	// event payload, a binding's settings, labels): the fake API does not check the keys
	// inside them.
	FreeForm []string
	// RawBody marks a request whose body is not JSON (a binary upload chunk); Request is then
	// empty.
	RawBody bool
}

var projectFields = []string{
	"id", "name", "slug", "service_type", "runtime", "runtime_version", "description",
	"knative_region_id", "region.id", "region.name", "endpoint_type", "endpoint_url",
	"latest_version.id", "latest_version.label", "latest_deployment.status", "runtime_eol",
	"created_at", "stopped.at", "stopped.by.id", "stopped.by.name", "stopped.reason", "stopped.level",
	"config.filename", "config.handler", "config.image", "config.command", "config.args",
	"config.ports", "config.env_vars", "config.memory_mb", "config.cpu", "config.timeout_seconds",
	"config.image_pull_policy", "config.trigger_type", "config.schedule", "config.queue_stream",
	"config.scale_to_zero", "config.min_scale", "config.max_scale",
}

// configSources define the config object of ProjectResource (a match expression the
// generator types as "any").
var configSources = []string{
	"app/Http/Resources/FaasConfigResource.php",
	"app/Http/Resources/XaasConfigResource.php",
	"app/Http/Resources/ContainerConfigResource.php",
	"app/Http/Resources/ProjectResource.php", // secret_env_keys
}

var configRequestFields = []string{
	"config.filename", "config.handler", "config.image", "config.command", "config.args",
	"config.ports", "config.env_vars", "config.memory_mb", "config.cpu", "config.timeout_seconds",
	"config.image_pull_policy", "config.image_pull_secret", "config.trigger_type",
	"config.schedule", "config.queue_stream", "config.scale_to_zero", "config.min_scale",
	"config.max_scale",
}

var versionFields = []string{
	"id", "number", "label", "commit_id", "commit_message", "changed_files", "config_snapshot",
	"build_status", "build_error", "created_by.id", "created_by.name", "created_at",
}

var deploymentFields = []string{
	"id", "status", "k8s_job_name", "deployed_by", "deploy_config", "knative_ready",
	"knative_message", "ready_at", "version.id", "version.label", "created_at",
}

var queuedDeployFields = []string{
	"id", "project_id", "version_id", "version", "status", "reason", "message", "deployment_id",
	"created_at", "updated_at",
}

var domainFields = []string{
	"id", "project_id", "domain", "status", "status_message", "tls_mode", "tls_expires_at",
	"https_certificate", "tls_note", "url", "verification.type", "verification.name",
	"verification.value", "dns_targets[].type", "dns_targets[].name", "dns_targets[].value",
	"created_at", "updated_at",
}

var regionFields = []string{
	"id", "name", "is_active", "is_demo", "region_status", "region_mode", "knative_status",
	"has_redis", "project_count", "total_cpu_m", "total_memory_mb", "used_cpu_m",
	"used_memory_mb", "project_namespace",
}

func prefix(p string, fields []string) []string {
	out := make([]string, len(fields))
	for i, f := range fields {
		out[i] = p + f
	}
	return out
}

func concat(lists ...[]string) []string {
	var out []string
	for _, l := range lists {
		out = append(out, l...)
	}
	return out
}

// Contract is every endpoint the runr CLI and the Terraform provider call: the core entries
// below and those of the feature files (contract_*.go), which append theirs in init.
var Contract = []Endpoint{
	// Meta and authentication
	{Method: "GET", Path: "/version", Response: []string{"version"}, Status: 200,
		NotInSpec: true, Sources: []string{"routes/api.php"}},
	{Method: "POST", Path: "/auth/login", Request: []string{"login", "password", "two_factor_code"},
		Response: []string{"token", "user", "user.name", "user.username", "user.email", "202:requires_2fa"}, Status: 200,
		Sources: []string{"app/Models/User.php"}},
	{Method: "POST", Path: "/auth/logout", Status: 200},
	{Method: "GET", Path: "/auth/me", Response: []string{"name", "username", "email"}, Status: 200,
		Sources: []string{"app/Models/User.php"}},
	{Method: "GET", Path: "/auth/permissions", Status: 200, Optional: true,
		Response: []string{"edition", "platform_admin", "organization", "role", "roles", "permissions", "projects", "token_scoped"}},
	{Method: "GET", Path: "/tokens", Status: 200, Permission: "tokens.manage",
		Response: []string{"tokens[].id", "tokens[].name", "tokens[].abilities", "tokens[].last_used_at",
			"tokens[].expires_at", "tokens[].created_at", "available_abilities"},
		Sources: []string{"app/Http/Controllers/Api/ApiTokenController.php"}},
	// The request is validated in a helper of the controller, so the document has no body schema.
	{Method: "POST", Path: "/tokens", Request: []string{"name", "abilities", "expires_at"}, Status: 201, Permission: "tokens.manage",
		Response: []string{"plain_token", "token.id", "token.name", "token.abilities", "token.expires_at", "token.created_at"},
		Sources:  []string{"app/Http/Controllers/Api/ApiTokenController.php"}},
	{Method: "DELETE", Path: "/tokens/{token}", Status: 200, Permission: "tokens.manage"},
	{Method: "GET", Path: "/settings", Status: 200,
		Response: []string{"executor_images[].id", "executor_images[].runtime", "executor_images[].version",
			"executor_images[].is_default", "executor_images[].sort_order", "executor_images[].eol_at"},
		Sources: []string{"app/Http/Controllers/Api/Concerns/SettingsQueryTrait.php"}},

	// Runtime catalog: contract_runtimes.go

	// Projects
	{Method: "GET", Path: "/projects", Status: 200, Permission: "projects.view", Response: prefix("data[].", projectFields),
		Sources: configSources, Capabilities: []string{"data[].config.secret_env_keys", "data[].config.env_vars_hidden"}},
	{Method: "POST", Path: "/projects", Status: 201, Permission: "projects.create",
		Request: concat([]string{"name", "service_type", "runtime", "runtime_version", "description",
			"knative_region_id", "endpoint_type"}, configRequestFields),
		Response: projectFields, Sources: configSources,
		Capabilities: []string{"config.secret_env_vars"}},
	{Method: "GET", Path: "/projects/{project}", Status: 200, Permission: "projects.view", Response: prefix("data.", projectFields),
		Sources: configSources, Capabilities: []string{"data.config.secret_env_keys", "data.config.env_vars_hidden"}},
	{Method: "PUT", Path: "/projects/{project}", Status: 200, Permission: "projects.update|env.edit",
		Request:  concat([]string{"description", "knative_region_id", "config_message"}, configRequestFields),
		Response: prefix("data.", projectFields), Sources: configSources,
		Capabilities: []string{"config.secret_env_vars", "data.config.secret_env_keys"}},
	{Method: "DELETE", Path: "/projects/{project}", Status: 204, Permission: "projects.delete"},

	// Code and versions
	{Method: "GET", Path: "/projects/{project}/files", Query: []string{"version_id"}, Status: 200, Permission: "projects.view",
		Response: []string{"files", "is_default"}},
	{Method: "POST", Path: "/projects/{project}/files", Status: 201, Permission: "code.edit",
		Request:  []string{"files[].name", "files[].content", "message"},
		Response: prefix("version.", versionFields)},
	{Method: "GET", Path: "/projects/{project}/code", Query: []string{"version_id"}, Status: 200, Permission: "projects.view",
		Response: []string{"code", "is_default"}},
	{Method: "POST", Path: "/projects/{project}/code", Status: 201, Permission: "code.edit",
		Request:  []string{"code", "message"},
		Response: prefix("version.", versionFields)},
	{Method: "GET", Path: "/projects/{project}/versions", Status: 200, Permission: "projects.view",
		Response: prefix("data[].", versionFields)},
	{Method: "GET", Path: "/projects/{project}/versions/{version}/files", Status: 200, Permission: "projects.view",
		Response: []string{"files"}},

	// Deployments
	{Method: "GET", Path: "/projects/{project}/deployments", Status: 200, Permission: "projects.view",
		Response: prefix("data[].", deploymentFields)},
	// A version whose image is not in the region's registry is built again first: the answer is
	// then a queued deploy instead of a deployment (StartDeploy).
	{Method: "POST", Path: "/projects/{project}/deploy", Status: 202, Permission: "projects.deploy",
		Request: []string{"version_id", "deploy_config.cpu", "deploy_config.memory_mb", "deploy_config.timeout_seconds",
			"deploy_config.scale_to_zero", "deploy_config.min_scale", "deploy_config.max_scale"},
		Response:     deploymentFields,
		Capabilities: concat([]string{"message"}, prefix("queued_deploy.", queuedDeployFields))},
	{Method: "GET", Path: "/projects/{project}/deployments/{deployment}", Status: 200, Permission: "projects.view",
		Response: prefix("data.", deploymentFields)},
	{Method: "GET", Path: "/projects/{project}/queued-deploys/{queuedDeploy}", Status: 200, Permission: "projects.view",
		Response: prefix("data.", queuedDeployFields), Sources: []string{"app/Http/Resources/QueuedDeployResource.php"}},
	{Method: "DELETE", Path: "/projects/{project}/deployments/{deployment}", Status: 204, Permission: "projects.deploy"},
	// A web shell session in a pod of the deployment (runr shell; /ws/shell?token=…).
	{Method: "POST", Path: "/projects/{project}/deployments/{deployment}/exec", Status: 200, Permission: "shell.exec",
		Request: []string{"cols", "rows"}, Response: []string{"session_id"},
		Sources: []string{"app/Http/Controllers/Api/ExecController.php"}},
	{Method: "GET", Path: "/projects/{project}/deployments/{deployment}/logs", Query: []string{"since"}, Status: 200,
		Permission: "logs.view",
		Response:   []string{"pods[].name", "pods[].status", "pods[].ready", "logs", "last_ts_ms", "source", "error", "suggest_status"},
		Sources:    []string{"app/Services/KnativeLogService.php", "app/Http/Controllers/Api/DeploymentController.php"}},

	// Custom domains
	{Method: "GET", Path: "/projects/{project}/domains", Status: 200, Permission: "projects.view",
		Response: concat(prefix("data[].", domainFields), []string{"meta.eligible", "meta.reason",
			"meta.cert_manager_issuer", "meta.default_host", "meta.dns_targets"}),
		Sources: []string{"app/Http/Resources/ProjectDomainResource.php"}},
	{Method: "POST", Path: "/projects/{project}/domains", Status: 201, Request: []string{"domain"}, Permission: "domains.manage",
		Response: prefix("data.", domainFields), Sources: []string{"app/Http/Resources/ProjectDomainResource.php"}},
	{Method: "POST", Path: "/projects/{project}/domains/{domain}/verify", Status: 200, Permission: "domains.manage",
		Response: concat([]string{"message"}, prefix("data.", domainFields)),
		Sources:  []string{"app/Http/Resources/ProjectDomainResource.php"}},
	{Method: "PUT", Path: "/projects/{project}/domains/{domain}/tls", Status: 200, Permission: "domains.manage",
		Request:  []string{"mode", "certificate", "privateKey"},
		Response: prefix("data.", domainFields), Sources: []string{"app/Http/Resources/ProjectDomainResource.php"}},
	{Method: "DELETE", Path: "/projects/{project}/domains/{domain}", Status: 204, Permission: "domains.manage"},

	// Regions
	{Method: "GET", Path: "/knative-regions", Status: 200, Permission: "regions.view", Response: prefix("data[].", regionFields),
		Sources: []string{"app/Http/Resources/KnativeRegionResource.php"}},
	{Method: "GET", Path: "/knative-regions/{region}", Status: 200, Permission: "regions.view", Response: prefix("data.", regionFields),
		Sources: []string{"app/Http/Resources/KnativeRegionResource.php"}},
}

var pathParam = regexp.MustCompile(`\{[^}]+\}`)

// NormalizePath replaces path parameter names with {} so /projects/{projectId} and
// /projects/{project} compare equal.
func NormalizePath(p string) string {
	return pathParam.ReplaceAllString(strings.TrimRight(p, "/"), "{}")
}

// MatchEndpoint returns the contract entry for a concrete request path (/projects/01H…/deploy).
// The template with the most fixed segments wins: PUT /roles/default matches /roles/default,
// not /roles/{role}.
func MatchEndpoint(method, path string) *Endpoint {
	segs := strings.Split(strings.Trim(path, "/"), "/")
	var best *Endpoint
	bestFixed := -1
	for i := range Contract {
		e := &Contract[i]
		if e.Method != method {
			continue
		}
		tpl := strings.Split(strings.Trim(e.Path, "/"), "/")
		if len(tpl) != len(segs) {
			continue
		}
		ok, fixed := true, 0
		for j := range tpl {
			if strings.HasPrefix(tpl[j], "{") {
				if segs[j] == "" {
					ok = false
					break
				}
				continue
			}
			if tpl[j] != segs[j] {
				ok = false
				break
			}
			fixed++
		}
		if ok && fixed > bestFixed {
			best, bestFixed = e, fixed
		}
	}
	return best
}
