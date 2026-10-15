package cmd

import (
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// mcpTokenCall returns the body of the last POST /tokens.
func mcpTokenCall(t *testing.T, h *harness) map[string]any {
	t.Helper()
	calls := h.srv.CallsTo("POST /tokens")
	if len(calls) == 0 {
		t.Fatal("no token was created")
	}
	return calls[len(calls)-1].Body
}

func mcpAbilities(body map[string]any) []string {
	raw, _ := body["abilities"].([]any)
	out := []string{}
	for _, a := range raw {
		out = append(out, a.(string))
	}
	return out
}

func TestMCPConfigPrintsTheClients(t *testing.T) {
	h := newHarness(t).login()
	endpoint := h.srv.URL + "/mcp"

	r := h.mustRun("mcp")
	contains(t, r.out, "Endpoint:")
	contains(t, r.out, endpoint)
	contains(t, r.out, "Streamable HTTP")
	// OAuth is offered: the clients need the URL only.
	contains(t, r.out, "claude mcp add --transport http runr "+endpoint+"\n")
	notContains(t, r.out, "Authorization")
	for _, title := range []string{"Claude Code", "Claude Desktop", "Cursor", "VS Code"} {
		contains(t, r.out, title)
	}

	r = h.mustRun("mcp", "--client", "cursor", "--auth", "token")
	contains(t, r.out, `"Authorization": "Bearer ${env:RUNR_TOKEN}"`)
	notContains(t, r.out, "Claude Code")

	var out struct {
		Endpoint string            `json:"endpoint"`
		Auth     string            `json:"auth"`
		Snippets []runr.MCPSnippet `json:"snippets"`
		MCP      map[string]any    `json:"mcp"`
	}
	decodeJSON(t, h.mustRun("mcp", "config", "-o", "json").out, &out)
	if out.Endpoint != endpoint || out.Auth != "oauth" || len(out.Snippets) != 4 || out.MCP["endpoint"] != endpoint {
		t.Fatalf("json = %+v", out)
	}

	r = h.runStdin("12|from-stdin\n", "mcp", "--client", "claude-code", "--token-stdin")
	if r.code != 0 {
		t.Fatalf("token-stdin: %d %s", r.code, r.err)
	}
	contains(t, r.out, "--header 'Authorization: Bearer 12|from-stdin'")

	h.expectCode(ExitUsage, "mcp", "--client", "emacs")
	h.expectCode(ExitUsage, "mcp", "--auth", "basic")
	h.expectCode(ExitUsage, "mcp", "--auth", "oauth", "--token", "x")
}

func TestMCPConfigWithoutOAuth(t *testing.T) {
	h := newHarness(t).login()
	h.srv.MCP().OAuth = false
	r := h.mustRun("mcp", "--client", "claude-code")
	contains(t, r.out, `--header "Authorization: Bearer ${RUNR_TOKEN}"`)
	contains(t, r.out, "runr mcp token create")
}

func TestMCPConfigOnAnAPIWithoutMCPSettings(t *testing.T) {
	h := newHarness(t).login()
	h.srv.MCP().Disabled = true
	r := h.mustRun("mcp", "--client", "vscode")
	contains(t, r.out, h.srv.URL+"/mcp")
	contains(t, r.out, "${input:runr-token}")
}

func TestMCPTokenCreatePresets(t *testing.T) {
	h := newHarness(t).login()

	var out struct {
		Token    string            `json:"token"`
		Record   runr.Token        `json:"record"`
		Snippets []runr.MCPSnippet `json:"snippets"`
	}
	decodeJSON(t, h.mustRun("mcp", "token", "create", "--preset", "read", "--client", "cursor", "-o", "json").out, &out)
	body := mcpTokenCall(t, h)
	if got := mcpAbilities(body); !reflect.DeepEqual(got, []string{"logs.view", "projects.view"}) {
		t.Fatalf("read abilities = %v", got)
	}
	if body["name"] != "mcp-cursor" {
		t.Fatalf("name = %v", body["name"])
	}
	exp, err := time.Parse(time.RFC3339, body["expires_at"].(string))
	if err != nil || exp.Before(time.Now().Add(29*24*time.Hour)) || exp.After(time.Now().Add(31*24*time.Hour)) {
		t.Fatalf("expires_at = %v (%v): the server's default is 30 days", body["expires_at"], err)
	}
	if out.Token == "" || len(out.Snippets) != 1 || out.Snippets[0].Client != "cursor" ||
		!strings.Contains(out.Snippets[0].Text, "Bearer "+out.Token) {
		t.Fatalf("output = %+v", out)
	}

	h.mustRun("mcp", "token", "create", "deployer", "--preset", "deploy", "--expires", "7d")
	body = mcpTokenCall(t, h)
	if got := mcpAbilities(body); !reflect.DeepEqual(got, mcpDeployScopes()) || body["name"] != "deployer" {
		t.Fatalf("deploy token = %v", body)
	}

	r := h.mustRun("mcp", "token", "create", "--preset", "full", "--expires", "never")
	body = mcpTokenCall(t, h)
	if _, ok := body["abilities"]; ok {
		t.Fatalf("a full token sends abilities: %v", body)
	}
	if _, ok := body["expires_at"]; ok {
		t.Fatalf("--expires never sends an expiry: %v", body)
	}
	contains(t, r.out, "every permission you hold")
	contains(t, r.out, "claude mcp add --transport http runr")
}

func TestMCPTokenCreateGroupsAndScopes(t *testing.T) {
	h := newHarness(t).login()
	h.mustRun("mcp", "token", "create", "--group", "sandboxes", "--scope", "logs.view,projects.view")
	if got := mcpAbilities(mcpTokenCall(t, h)); !reflect.DeepEqual(got, []string{"logs.view", "projects.view", "sandboxes.create", "sandboxes.exec"}) {
		t.Fatalf("abilities = %v", got)
	}
	h.expectCode(ExitUsage, "mcp", "token", "create", "--group", "games")
	h.expectCode(ExitUsage, "mcp", "token", "create", "--preset", "admin")
	h.expectCode(ExitUsage, "mcp", "token", "create", "--preset", "read", "--client", "emacs")
	h.expectCode(ExitUsage, "mcp", "token", "create", "--scope", "logs.view", "--expires", "soon")
}

func TestMCPTokenCreateAsksOnATerminal(t *testing.T) {
	h := newHarness(t).login()
	// Without a choice and without a terminal, runr does not guess.
	h.expectCode(ExitUsage, "mcp", "token", "create")
	if len(h.srv.CallsTo("POST /tokens")) != 0 {
		t.Fatal("a token was created without a choice")
	}

	h.terminal = true
	// The fake's groups in catalog order: projects, code, deployments, operations, configuration, sandboxes, mcp.
	r := h.runStdin("3,4\n", "mcp", "token", "create")
	if r.code != 0 {
		t.Fatalf("picker: exit %d\n%s", r.code, r.err)
	}
	contains(t, r.err, "What may the agent do?")
	contains(t, r.err, "deployments: 1 tool (projects.deploy)")
	if got := mcpAbilities(mcpTokenCall(t, h)); !reflect.DeepEqual(got, []string{"logs.view", "projects.deploy"}) {
		t.Fatalf("picked abilities = %v", got)
	}

	h.runStdin("r\n", "mcp", "token", "create")
	if got := mcpAbilities(mcpTokenCall(t, h)); !reflect.DeepEqual(got, []string{"logs.view", "projects.view"}) {
		t.Fatalf("read-only pick = %v", got)
	}
	if r := h.runStdin("9\n", "mcp", "token", "create"); r.code != ExitUsage {
		t.Fatalf("out-of-range pick: exit %d", r.code)
	}
}

func TestMCPClientsCommands(t *testing.T) {
	h := newHarness(t).login()
	claude := h.srv.SeedMCPClient("Claude", "projects.view")

	r := h.mustRun("mcp", "clients")
	contains(t, r.out, claude.ClientID)
	contains(t, r.out, "Claude")

	h.expectCode(ExitUsage, "mcp", "clients", "revoke", "Claude")
	r = h.mustRun("mcp", "clients", "revoke", "Claude", "--yes")
	contains(t, r.err, "Revoked 1 token of Claude.")
	if len(h.srv.MCPClients()) != 0 {
		t.Fatal("the client is still connected")
	}
	h.expectCode(ExitNotFound, "mcp", "clients", "revoke", "Claude", "--yes")
	contains(t, h.mustRun("mcp", "clients").out, "No OAuth client is connected")
}

func TestMCPToolsCommand(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("mcp", "tools", "--group", "sandboxes")
	contains(t, r.out, "sandbox_exec")
	contains(t, r.out, "sandboxes.create|sandboxes.manage")
	notContains(t, r.out, "projects_list")

	h.loginWith(h.srv.ScopedToken("reader", "logs.view"))
	var tools []runr.MCPTool
	decodeJSON(t, h.mustRun("mcp", "tools", "--allowed", "-o", "json").out, &tools)
	var names []string
	for _, tl := range tools {
		names = append(names, tl.Name)
	}
	if !reflect.DeepEqual(names, []string{"projects_list", "logs_read"}) {
		t.Fatalf("allowed tools = %v", names)
	}
	contains(t, h.mustRun("mcp", "tools", "--search", "deploy").out, "1 tool,")
}

func TestMCPServerLifecycle(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	before := len(h.srv.Deployments("hello-api"))

	h.expectCode(ExitNotFound, "mcp-server", "--dir", dir)
	h.expectCode(ExitUsage, "mcp-server", "set", "--access-mode", "bogus", "--dir", dir)
	h.expectCode(ExitUsage, "mcp-server", "set", "--path", "rpc", "--dir", dir)

	// A new server gets the access mode token, and the live version is deployed again so the
	// workload gets the gateway key.
	var set struct {
		Server     runr.HostedMCPServer `json:"server"`
		Redeployed bool                 `json:"redeployed"`
		Created    bool                 `json:"created"`
	}
	decodeJSON(t, h.mustRun("mcp-server", "set", "--dir", dir, "-o", "json").out, &set)
	if !set.Created || !set.Redeployed || set.Server.AccessMode != runr.MCPAccessToken || set.Server.NeedsRedeploy {
		t.Fatalf("set = %+v", set)
	}
	if got := len(h.srv.Deployments("hello-api")); got != before+1 {
		t.Fatalf("%d deployments, want %d", got, before+1)
	}
	if body := h.srv.CallsTo("PUT /projects/{project}/mcp-server")[0].Body; !reflect.DeepEqual(body, map[string]any{"access_mode": "token"}) {
		t.Fatalf("PUT body = %v", body)
	}

	r := h.mustRun("mcp-server", "--dir", dir)
	contains(t, r.out, "/mcp/servers/hello-api")
	contains(t, r.out, "claude mcp add --transport http hello-api")
	contains(t, r.out, "Authorization: Bearer ${RUNR_TOKEN}")

	h.expectCode(ExitUsage, "mcp-server", "set", "--dir", dir) // nothing to change
	h.mustRun("mcp-server", "set", "--access-mode", "oauth", "--audit-calls", "--dir", dir)
	s, _ := h.srv.MCPServerOf("hello-api")
	if s.AccessMode != runr.MCPAccessOAuth || !s.AuditCalls {
		t.Fatalf("server = %+v", s)
	}
	r = h.mustRun("mcp-server", "get", "--client", "cursor", "--dir", dir)
	notContains(t, r.out, "Authorization")

	// rotate-key without a redeploy leaves the server waiting for one.
	h.expectCode(ExitUsage, "mcp-server", "rotate-key", "--dir", dir)
	var rot struct {
		Server     runr.HostedMCPServer `json:"server"`
		Redeployed bool                 `json:"redeployed"`
		Message    string               `json:"message"`
	}
	decodeJSON(t, h.mustRun("mcp-server", "rotate-key", "--yes", "--no-deploy", "--dir", dir, "-o", "json").out, &rot)
	if rot.Redeployed || !rot.Server.NeedsRedeploy || rot.Server.KeyRotatedAt == "" || rot.Message == "" {
		t.Fatalf("rotate = %+v", rot)
	}
	r = h.mustRun("mcp-server", "rotate-key", "--yes", "--dir", dir)
	contains(t, r.err, "is live")
	if s, _ := h.srv.MCPServerOf("hello-api"); s.NeedsRedeploy {
		t.Fatal("the server still waits for a deploy")
	}

	r = h.mustRun("mcp-server", "ls")
	contains(t, r.out, "hello-api")
	contains(t, r.out, "oauth")

	h.expectCode(ExitUsage, "mcp-server", "rm", "--dir", dir)
	h.mustRun("mcp-server", "rm", "--yes", "--dir", dir)
	if _, ok := h.srv.MCPServerOf("hello-api"); ok {
		t.Fatal("the server still exists")
	}
}

func TestMCPServerSetWithoutDeploy(t *testing.T) {
	h := newHarness(t).login()
	dir := h.deployHello()
	before := len(h.srv.Deployments("hello-api"))
	h.mustRun("mcp-server", "set", "--access-mode", "public", "--no-deploy", "--dir", dir)
	if len(h.srv.Deployments("hello-api")) != before {
		t.Fatal("--no-deploy deployed")
	}
	s, _ := h.srv.MCPServerOf("hello-api")
	if !s.NeedsRedeploy || s.AccessMode != runr.MCPAccessPublic {
		t.Fatalf("server = %+v", s)
	}
}

func TestMCPServerSetRefusesOtherTriggers(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("nightly", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "schedule", "schedule": "0 3 * * *"})
	r := h.expectCode(ExitValidation, "mcp-server", "set", "-p", "nightly")
	contains(t, r.err, "http trigger")
}

func TestMCPServerCalls(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("weather", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedMCPServer("weather", runr.MCPAccessToken)
	h.srv.SeedMCPServerCall("weather", "initialize", "", "ok")
	h.srv.SeedMCPServerCall("weather", "tools/call", "forecast", "error")

	r := h.mustRun("mcp-server", "calls", "-p", "weather", "--outcome", "error")
	contains(t, r.out, "forecast")
	notContains(t, r.out, "initialize")
	if q := h.srv.CallsTo("GET /projects/{project}/mcp-server/calls")[0].Query; q != "outcome=error&per_page=50" {
		t.Fatalf("query = %q", q)
	}
	var out struct {
		Calls []runr.MCPServerCall `json:"calls"`
		Meta  runr.Page            `json:"meta"`
	}
	decodeJSON(t, h.mustRun("mcp-server", "calls", "-p", "weather", "--limit", "1", "-o", "json").out, &out)
	if len(out.Calls) != 1 || out.Meta.Total != 2 || out.Calls[0].Method != "tools/call" {
		t.Fatalf("calls = %+v", out)
	}
	h.expectCode(ExitUsage, "mcp-server", "calls", "-p", "weather", "--limit", "0")
	h.expectCode(ExitUsage, "mcp-server", "calls", "-p", "weather", "--outcome", "slow")
}

func TestMCPServerInspect(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedProject("weather", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.SeedMCPServer("weather", runr.MCPAccessToken)

	r := h.mustRun("mcp-server", "inspect", "-p", "weather")
	contains(t, r.out, "whoami")
	contains(t, r.out, "echo")
	contains(t, r.out, "Session:")
	var methods []string
	for _, m := range h.srv.MCPInspectorMessages("weather") {
		methods = append(methods, m["method"].(string))
	}
	if !reflect.DeepEqual(methods, []string{"initialize", "notifications/initialized", "tools/list"}) {
		t.Fatalf("messages = %v", methods)
	}
	if sess := h.srv.CallsTo("POST /projects/{project}/mcp-server/inspector")[2].Body["session_id"]; sess != "sess-1" {
		t.Fatalf("tools/list without the session: %v", sess)
	}

	r = h.mustRun("mcp-server", "inspect", "-p", "weather", "--tool", "echo", "--args", `{"text":"hello from runr"}`)
	contains(t, r.out, "hello from runr")

	h.expectCode(ExitFailed, "mcp-server", "inspect", "-p", "weather", "--tool", "nope")

	var out struct {
		Exchanges []struct {
			Request map[string]any `json:"request"`
		} `json:"exchanges"`
		Result map[string]any `json:"result"`
	}
	decodeJSON(t, h.mustRun("mcp-server", "inspect", "-p", "weather", "--method", "ping", "--no-init", "-o", "json").out, &out)
	if len(out.Exchanges) != 1 || out.Exchanges[0].Request["method"] != "ping" || out.Result == nil {
		t.Fatalf("ping = %+v", out)
	}

	r = h.mustRun("mcp-server", "inspect", "-p", "weather", "--message", `{"jsonrpc":"2.0","id":9,"method":"ping"}`)
	contains(t, r.out, `"id": 9`)

	h.expectCode(ExitUsage, "mcp-server", "inspect", "-p", "weather", "--tool", "echo", "--method", "ping")
	h.expectCode(ExitUsage, "mcp-server", "inspect", "-p", "weather", "--args", "{}")
	h.expectCode(ExitUsage, "mcp-server", "inspect", "-p", "weather", "--message", "not json")

	h.srv.MCP().Unreachable = true
	h.expectCode(ExitUnreachable, "mcp-server", "inspect", "-p", "weather")
}

func TestMCPServerTemplatesCommand(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("mcp-server", "templates")
	contains(t, r.out, "mcp-python")
	contains(t, r.out, "image")
}
