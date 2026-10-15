package runr

// AI agents (docs/mcp.md; api/routes/mcp-api.php): the RunR MCP server's settings, tool catalog
// and connected clients, and hosted MCP servers. The controllers build most answers by hand
// (HostedServerResource::make, HostedServerCall::toPublicArray), so the fields are checked
// against those files where the generator leaves a schema untyped.

var mcpServerFields = []string{
	"id", "project_id", "project_name", "handle", "url", "access_mode", "path", "audit_calls",
	"function_url", "resource_metadata", "key_rotated_at", "needs_redeploy", "created_at", "updated_at",
}

var mcpCallFields = []string{
	"id", "occurred_at", "method", "name", "jsonrpc_id", "status_code", "outcome", "error", "duration_ms",
	"actor.type", "actor.id", "actor.name", "auth", "session_id", "request_bytes", "response_bytes",
}

var (
	mcpSettingsSources = []string{"app/Mcp/Http/Controllers/McpSettingsController.php"}
	mcpHostedSources   = []string{"app/Mcp/Http/Controllers/HostedServerController.php", "app/Mcp/Http/Resources/HostedServerResource.php"}
	mcpCallSources     = []string{"app/Mcp/Models/HostedServerCall.php"}
)

func init() {
	Contract = append(Contract,
		// The RunR MCP server (the MCP endpoint itself is outside /api).
		Endpoint{Method: "GET", Path: "/mcp", Status: 200, Optional: true,
			Response: []string{"endpoint", "server.name", "server.title", "server.version", "protocol_versions",
				"oauth.enabled", "oauth.issuer", "oauth.resource_metadata", "oauth.authorization_endpoint",
				"oauth.token_endpoint", "oauth.registration_endpoint", "token_lifetimes", "default_token_lifetime"}},
		Endpoint{Method: "GET", Path: "/mcp/tools", Status: 200, Optional: true,
			Response: concat(prefix("data[].", []string{"name", "title", "description", "group", "permissions", "allowed",
				"annotations", "streams", "input_schema"}), []string{"groups"}),
			Sources: mcpSettingsSources},
		Endpoint{Method: "GET", Path: "/mcp/clients", Status: 200, Permission: "tokens.manage",
			Response: prefix("data[].", []string{"client_id", "name", "client_uri", "redirect_hosts", "tokens[].id",
				"tokens[].name", "tokens[].abilities", "tokens[].resource", "tokens[].created_at", "tokens[].last_used_at",
				"tokens[].expires_at", "connected_at", "last_used_at"}),
			Sources: mcpSettingsSources},
		Endpoint{Method: "DELETE", Path: "/mcp/clients/{client}", Status: 200, Permission: "tokens.manage",
			Response: []string{"revoked", "client.id", "client.name"}},

		// Hosted MCP servers.
		// Only the container-image template has a port.
		Endpoint{Method: "GET", Path: "/mcp-servers/templates", Status: 200,
			Response: prefix("data[].", []string{"id", "name", "description", "service_type", "runtime", "runtime_registered",
				"runtime_available", "can_register_runtime", "runtime_manifest", "files", "path"}),
			Capabilities: []string{"data[].port"}, Sources: mcpHostedSources},
		Endpoint{Method: "GET", Path: "/mcp-servers", Status: 200, Permission: "projects.view",
			Response: prefix("data[].", mcpServerFields), Sources: mcpHostedSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/mcp-server", Status: 200, Permission: "projects.view",
			Response: prefix("data.", mcpServerFields), Sources: mcpHostedSources},
		// 201 when the project becomes a server, 200 for a change (the document types 200).
		Endpoint{Method: "PUT", Path: "/projects/{project}/mcp-server", Status: 200, Permission: "mcp.servers.manage",
			Request:  []string{"access_mode", "path", "audit_calls"},
			Response: concat(prefix("data.", mcpServerFields), []string{"created"}), Sources: mcpHostedSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/mcp-server", Status: 204, Permission: "mcp.servers.manage"},
		Endpoint{Method: "POST", Path: "/projects/{project}/mcp-server/rotate-key", Status: 200, Permission: "mcp.servers.manage",
			Response: concat(prefix("data.", mcpServerFields), []string{"message"}), Sources: mcpHostedSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/mcp-server/calls", Status: 200, Permission: "logs.view",
			Query:    []string{"per_page", "outcome", "method"},
			Response: concat(prefix("data[].", mcpCallFields), []string{"meta.current_page", "meta.last_page", "meta.total", "meta.per_page"}),
			Sources:  concat(mcpHostedSources, mcpCallSources)},
		Endpoint{Method: "POST", Path: "/projects/{project}/mcp-server/inspector", Status: 200, Permission: "mcp.servers.call",
			Request: []string{"message", "session_id", "protocol_version"}, FreeForm: []string{"message"},
			Response: []string{"status", "session_id", "content_type", "messages", "raw", "duration_ms", "method", "name"},
			Sources:  mcpHostedSources},
	)
}
