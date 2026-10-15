package runrtest

import (
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
)

// AI agents of the fake (api McpSettingsController, HostedServerController): the MCP server's
// settings, a small tool catalog, OAuth clients that tests seed, and hosted MCP servers whose
// inspector answers like a minimal stateless MCP server (initialize, tools/list, tools/call).

// MCPConfig changes the fake's MCP answers; get it with Server.MCP.
type MCPConfig struct {
	// OAuth is reported as enabled (default true).
	OAuth bool
	// Disabled makes GET /mcp and GET /mcp/tools answer 404, like an API without MCP.
	Disabled bool
	// Unreachable makes the inspector answer 502 (the server's function does not answer).
	Unreachable bool
}

type mcpState struct {
	cfg     MCPConfig
	clients []*mcpClientRow
	servers map[string]*mcpServerRow // by project id
	seq     int
}

type mcpClientRow struct {
	data runr.MCPClient
}

type mcpServerRow struct {
	data     runr.HostedMCPServer
	keyAt    time.Time
	calls    []runr.MCPServerCall
	messages []map[string]any
}

// mcpTools is the fake's tool catalog: name, group, permissions, read-only.
var mcpTools = []struct {
	name, group string
	perms       []string
	readOnly    bool
}{
	{"projects_list", "projects", []string{"projects.view"}, true},
	{"project_create", "projects", []string{"projects.create"}, false},
	{"code_write", "code", []string{"code.edit"}, false},
	{"deploy", "deployments", []string{"projects.deploy"}, false},
	{"logs_read", "operations", []string{"logs.view"}, true},
	{"env_set", "configuration", []string{"env.edit"}, false},
	{"sandbox_exec", "sandboxes", []string{"sandboxes.exec", "sandboxes.create|sandboxes.manage"}, false},
	{"mcp_server_configure", "mcp", []string{"mcp.servers.manage"}, false},
}

var mcpToolGroups = []string{
	"account", "projects", "code", "git", "deployments", "operations", "observability",
	"configuration", "domains", "regions", "runtimes", "sandboxes", "workflows", "bindings",
	"models", "security", "audit", "mcp",
}

var mcpPathRule = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/-]*$`)

func (s *Server) mcpSt() *mcpState {
	return featureState(s, "mcp", func() *mcpState {
		return &mcpState{cfg: MCPConfig{OAuth: true}, servers: map[string]*mcpServerRow{}}
	})
}

// MCP returns the fake's MCP settings; change them before the requests.
func (s *Server) MCP() *MCPConfig {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.mcpSt().cfg
}

// SeedMCPClient connects an OAuth client with the user's account, holding one token with the
// given abilities, and returns it.
func (s *Server) SeedMCPClient(name string, abilities ...string) runr.MCPClient {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.mcpSt()
	st.seq++
	now := nowRFC3339()
	c := &mcpClientRow{data: runr.MCPClient{
		ClientID: fmt.Sprintf("mcp_client_%d", st.seq), Name: name, RedirectHosts: []string{"127.0.0.1"},
		Tokens: []runr.MCPClientToken{{ID: runr.FlexID(strconv.Itoa(100 + st.seq)), Name: name + " (MCP)",
			Abilities: abilities, Resource: s.URL + "/mcp", CreatedAt: now, ExpiresAt: time.Now().Add(30 * 24 * time.Hour).UTC().Format(time.RFC3339)}},
		ConnectedAt: now,
	}}
	st.clients = append(st.clients, c)
	return c.data
}

// MCPClients returns the connected OAuth clients.
func (s *Server) MCPClients() []runr.MCPClient {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []runr.MCPClient
	for _, c := range s.mcpSt().clients {
		out = append(out, c.data)
	}
	return out
}

// SeedMCPServer serves a project as a hosted MCP server.
func (s *Server) SeedMCPServer(projectName, accessMode string) runr.HostedMCPServer {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	row := s.newMCPServer(p, accessMode)
	return s.mcpServerData(p, row)
}

// SeedMCPServerCall adds an entry to a hosted server's call log.
func (s *Server) SeedMCPServerCall(projectName, method, name, outcome string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	row := s.mcpSt().servers[p.data.ID]
	if row == nil {
		s.t.Fatalf("runrtest: project %q is not an MCP server", projectName)
	}
	s.recordMCPCall(row, method, name, outcome, 200)
}

// MCPServerOf returns the hosted server of a project.
func (s *Server) MCPServerOf(projectName string) (runr.HostedMCPServer, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return runr.HostedMCPServer{}, false
	}
	row := s.mcpSt().servers[p.data.ID]
	if row == nil {
		return runr.HostedMCPServer{}, false
	}
	return s.mcpServerData(p, row), true
}

// MCPInspectorMessages returns the JSON-RPC messages the inspector sent to a project's server.
func (s *Server) MCPInspectorMessages(projectName string) []map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	if row := s.mcpSt().servers[p.data.ID]; row != nil {
		return append([]map[string]any(nil), row.messages...)
	}
	return nil
}

func (s *Server) newMCPServer(p *project, accessMode string) *mcpServerRow {
	now := nowRFC3339()
	row := &mcpServerRow{keyAt: time.Now(), data: runr.HostedMCPServer{ID: s.newID(), ProjectID: p.data.ID,
		Handle: p.data.Slug, URL: s.URL + "/mcp/servers/" + p.data.Slug, AccessMode: accessMode, Path: "/mcp",
		CreatedAt: now, UpdatedAt: now}}
	s.mcpSt().servers[p.data.ID] = row
	return row
}

// mcpServerData is HostedServerResource::make.
func (s *Server) mcpServerData(p *project, row *mcpServerRow) runr.HostedMCPServer {
	out := row.data
	out.ProjectName = p.data.Name
	out.FunctionURL = "https://" + p.data.Slug + "." + s.regionName(p) + ".runr.test" + row.data.Path
	out.ResourceMetadata = ""
	if out.AccessMode != runr.MCPAccessPublic {
		out.ResourceMetadata = s.URL + "/.well-known/oauth-protected-resource/mcp/servers/" + out.Handle
	}
	out.NeedsRedeploy = false
	for _, d := range p.deployments {
		if d.data.Status == runr.DeploymentActive && d.data.CreatedAt != nil && d.data.CreatedAt.Before(row.keyAt) {
			out.NeedsRedeploy = true
		}
	}
	return out
}

func (s *Server) recordMCPCall(row *mcpServerRow, method, name, outcome string, status int) {
	st := s.mcpSt()
	st.seq++
	call := runr.MCPServerCall{ID: s.newID(), OccurredAt: time.Now().UTC().Format("2006-01-02T15:04:05.000Z"), Method: method,
		Name: name, StatusCode: status, Outcome: outcome, DurationMs: 12, Auth: "console", SessionID: "sess-1",
		RequestBytes: 64, ResponseBytes: 128}
	call.Actor.Type, call.Actor.ID, call.Actor.Name = "user", s.User.ID.String(), s.User.Name
	if outcome == "error" {
		call.Error = "tool failed"
	}
	row.calls = append(row.calls, call)
}

func (s *Server) mcpServerOfReq(q *featureReq) (*project, *mcpServerRow, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return nil, nil, err
	}
	row := s.mcpSt().servers[p.data.ID]
	if row == nil {
		return p, nil, &apiErr{status: 404, message: "This project is not served as an MCP server."}
	}
	return p, row, nil
}

func init() {
	handle("GET /mcp", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.mcpSt()
		if st.cfg.Disabled {
			return 0, nil, errNotFound()
		}
		var reg any
		if st.cfg.OAuth {
			reg = s.URL + "/oauth2/register"
		}
		return 200, map[string]any{
			"endpoint":          s.URL + "/mcp",
			"server":            map[string]any{"name": "runr", "title": "RunR", "version": s.Version},
			"protocol_versions": []string{"2025-11-25", "2025-06-18", "2025-03-26"},
			"oauth": map[string]any{
				"enabled": st.cfg.OAuth, "issuer": s.URL,
				"resource_metadata":      s.URL + "/.well-known/oauth-protected-resource/mcp",
				"authorization_endpoint": s.URL + "/oauth2/authorize", "token_endpoint": s.URL + "/oauth2/token",
				"registration_endpoint": reg,
			},
			"token_lifetimes":        []any{7, 30, 90, 180, 365, nil},
			"default_token_lifetime": 30,
		}, nil
	})
	handle("GET /mcp/tools", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if s.mcpSt().cfg.Disabled {
			return 0, nil, errNotFound()
		}
		list := []map[string]any{}
		for _, t := range mcpTools {
			allowed := true
			for _, req := range t.perms {
				held := false
				for _, alt := range strings.Split(req, "|") {
					held = held || s.holds(alt)
				}
				allowed = allowed && held
			}
			list = append(list, map[string]any{
				"name": t.name, "title": strings.ReplaceAll(t.name, "_", " "), "description": "Calls the RunR API: " + t.name + ".",
				"group": t.group, "permissions": t.perms, "allowed": allowed,
				"annotations": map[string]any{"readOnlyHint": t.readOnly, "destructiveHint": false, "idempotentHint": t.readOnly,
					"openWorldHint": false},
				"streams": t.name == "deploy", "input_schema": map[string]any{"type": "object", "properties": map[string]any{}},
			})
		}
		return 200, map[string]any{"data": list, "groups": mcpToolGroups}, nil
	})
	handle("GET /mcp/clients", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.MCPClient{}
		for _, c := range s.mcpSt().clients {
			list = append(list, c.data)
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("DELETE /mcp/clients/{client}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.mcpSt()
		for i, c := range st.clients {
			if c.data.ClientID == q.Segs[2] {
				st.clients = append(st.clients[:i], st.clients[i+1:]...)
				return 200, map[string]any{"revoked": len(c.data.Tokens), "client": map[string]any{"id": c.data.ClientID, "name": c.data.Name}}, nil
			}
		}
		return 0, nil, &apiErr{status: 404, message: "This application holds no token of yours here."}
	})
	handle("GET /mcp-servers/templates", func(s *Server, q *featureReq) (int, any, *apiErr) {
		tpl := func(id, name, runtime string, files map[string]string) map[string]any {
			return map[string]any{"id": id, "name": name, "description": name + " MCP server (Streamable HTTP at /mcp).",
				"service_type": "faas", "runtime": runtime, "runtime_registered": false, "runtime_available": true,
				"can_register_runtime": true, "runtime_manifest": "id: " + runtime + "\nname: " + name + "\n",
				"files": files, "path": "/mcp"}
		}
		return 200, map[string]any{"data": []any{
			tpl("python", "Python", "mcp-python", map[string]string{"main.py": "from mcp.server.fastmcp import FastMCP\n"}),
			tpl("node", "TypeScript", "mcp-node", map[string]string{"server.ts": "import { McpServer } from \"@modelcontextprotocol/sdk/server/mcp.js\";\n"}),
			map[string]any{"id": "image", "name": "Container image", "description": "An MCP server you already have as a container image that serves Streamable HTTP.",
				"service_type": "caas", "runtime": nil, "runtime_registered": true, "runtime_available": true,
				"can_register_runtime": false, "runtime_manifest": nil, "files": nil, "path": "/mcp", "port": 8080},
		}}, nil
	})
	handle("GET /mcp-servers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.mcpSt()
		list := []runr.HostedMCPServer{}
		for _, p := range s.projects {
			if row := st.servers[p.data.ID]; row != nil {
				list = append(list, s.mcpServerData(p, row))
			}
		}
		sort.Slice(list, func(i, j int) bool { return list[i].Handle < list[j].Handle })
		return 200, map[string]any{"data": list}, nil
	})
	handle("GET /projects/{project}/mcp-server", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, row, err := s.mcpServerOfReq(q)
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"data": s.mcpServerData(p, row)}, nil
	})
	handle("PUT /projects/{project}/mcp-server", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		row := s.mcpSt().servers[p.data.ID]
		mode, hasMode := q.Body["access_mode"].(string)
		if row == nil && !hasMode {
			return 0, nil, errValidation("access_mode", "The access mode field is required.")
		}
		if hasMode && mode != runr.MCPAccessPublic && mode != runr.MCPAccessToken && mode != runr.MCPAccessOAuth {
			return 0, nil, errValidation("access_mode", "The selected access mode is invalid.")
		}
		path, hasPath := q.Body["path"].(string)
		if hasPath && (len(path) > 200 || !mcpPathRule.MatchString(path)) {
			return 0, nil, errValidation("path", "The path field format is invalid.")
		}
		if tt, _ := p.config["trigger_type"].(string); tt != "" && tt != runr.TriggerHTTP {
			return 0, nil, errValidation("trigger_type", "An MCP server needs the http trigger.")
		}
		created := row == nil
		if created {
			row = s.newMCPServer(p, mode)
		}
		if hasMode {
			row.data.AccessMode = mode
		}
		if hasPath {
			row.data.Path = path
		}
		if v, ok := q.Body["audit_calls"].(bool); ok {
			row.data.AuditCalls = v
		}
		row.data.UpdatedAt = nowRFC3339()
		status := 200
		if created {
			status = 201
		}
		return status, map[string]any{"data": s.mcpServerData(p, row), "created": created}, nil
	})
	handle("DELETE /projects/{project}/mcp-server", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, _, err := s.mcpServerOfReq(q)
		if err != nil {
			return 0, nil, err
		}
		delete(s.mcpSt().servers, p.data.ID)
		return 204, nil, nil
	})
	handle("POST /projects/{project}/mcp-server/rotate-key", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, row, err := s.mcpServerOfReq(q)
		if err != nil {
			return 0, nil, err
		}
		row.keyAt = time.Now()
		row.data.KeyRotatedAt = nowRFC3339()
		return 200, map[string]any{"data": s.mcpServerData(p, row),
			"message": "New gateway key. Deploy the project to give it to the server; the previous key keeps working for 7 days."}, nil
	})
	handle("GET /projects/{project}/mcp-server/calls", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, row, err := s.mcpServerOfReq(q)
		if err != nil {
			return 0, nil, err
		}
		outcome, method := q.query("outcome"), q.query("method")
		if outcome != "" {
			ok := false
			for _, o := range runr.MCPCallOutcomes {
				ok = ok || o == outcome
			}
			if !ok {
				return 0, nil, errValidation("outcome", "The selected outcome is invalid.")
			}
		}
		perPage := 50
		if v := q.query("per_page"); v != "" {
			n, convErr := strconv.Atoi(v)
			if convErr != nil || n < 1 || n > 100 {
				return 0, nil, errValidation("per_page", "The per page field must be between 1 and 100.")
			}
			perPage = n
		}
		list := []runr.MCPServerCall{}
		for i := len(row.calls) - 1; i >= 0; i-- {
			c := row.calls[i]
			if (outcome == "" || c.Outcome == outcome) && (method == "" || c.Method == method) {
				list = append(list, c)
			}
		}
		total := len(list)
		if len(list) > perPage {
			list = list[:perPage]
		}
		last := (total + perPage - 1) / perPage
		if last == 0 {
			last = 1
		}
		return 200, map[string]any{"data": list, "meta": map[string]any{"current_page": 1, "last_page": last, "total": total, "per_page": perPage}}, nil
	})
	handle("POST /projects/{project}/mcp-server/inspector", func(s *Server, q *featureReq) (int, any, *apiErr) {
		_, row, err := s.mcpServerOfReq(q)
		if err != nil {
			return 0, nil, err
		}
		msg, ok := q.Body["message"].(map[string]any)
		if !ok {
			return 0, nil, errValidation("message", "The message field is required.")
		}
		method, _ := msg["method"].(string)
		if msg["jsonrpc"] != "2.0" || method == "" {
			return 0, nil, errValidation("message", "The message must be a JSON-RPC 2.0 request or notification.")
		}
		row.messages = append(row.messages, msg)
		if s.mcpSt().cfg.Unreachable {
			s.recordMCPCall(row, method, "", "unreachable", 502)
			return 0, nil, &apiErr{status: 502, message: "The MCP server did not answer."}
		}
		answer := mcpFakeAnswer(row, msg)
		name := ""
		if params, ok := msg["params"].(map[string]any); ok {
			name, _ = params["name"].(string)
		}
		status, messages, outcome := 200, []any{}, "ok"
		if answer == nil {
			status = 202
		} else {
			messages = append(messages, answer)
			if _, isErr := answer["error"]; isErr {
				outcome = "error"
			}
		}
		s.recordMCPCall(row, method, name, outcome, status)
		raw := ""
		if answer != nil {
			b, _ := json.Marshal(answer)
			raw = string(b)
		}
		return 200, map[string]any{"status": status, "session_id": "sess-1", "content_type": "application/json",
			"messages": messages, "raw": raw, "duration_ms": 12, "method": method, "name": name}, nil
	})
}

// mcpFakeAnswer answers a JSON-RPC message like a minimal stateless MCP server with the tools
// whoami and echo; nil for a notification.
func mcpFakeAnswer(row *mcpServerRow, msg map[string]any) map[string]any {
	id, hasID := msg["id"]
	if !hasID {
		return nil
	}
	method, _ := msg["method"].(string)
	params, _ := msg["params"].(map[string]any)
	result := func(v map[string]any) map[string]any { return map[string]any{"jsonrpc": "2.0", "id": id, "result": v} }
	switch method {
	case "initialize":
		return result(map[string]any{"protocolVersion": "2025-11-25", "capabilities": map[string]any{"tools": map[string]any{}},
			"serverInfo": map[string]any{"name": row.data.Handle, "version": "1.0.0"}})
	case "ping":
		return result(map[string]any{})
	case "tools/list":
		return result(map[string]any{"tools": []any{
			map[string]any{"name": "whoami", "description": "The caller the gateway forwarded.", "inputSchema": map[string]any{"type": "object"}},
			map[string]any{"name": "echo", "description": "Echoes its text.", "inputSchema": map[string]any{"type": "object",
				"properties": map[string]any{"text": map[string]any{"type": "string"}}, "required": []any{"text"}}},
		}})
	case "tools/call":
		name, _ := params["name"].(string)
		args, _ := params["arguments"].(map[string]any)
		switch name {
		case "whoami":
			return result(map[string]any{"content": []any{map[string]any{"type": "text", "text": "console: Ada Lovelace"}}})
		case "echo":
			text, _ := args["text"].(string)
			return result(map[string]any{"content": []any{map[string]any{"type": "text", "text": text}}})
		}
		return map[string]any{"jsonrpc": "2.0", "id": id, "error": map[string]any{"code": -32602, "message": "Unknown tool: " + name}}
	}
	return map[string]any{"jsonrpc": "2.0", "id": id, "error": map[string]any{"code": -32601, "message": "Method not found: " + method}}
}
