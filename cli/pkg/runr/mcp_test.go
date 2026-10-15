package runr_test

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestMCPClientConfigSnippets(t *testing.T) {
	url := "https://runr.example.com/mcp"

	s, err := runr.MCPClientConfig(runr.MCPClientClaudeCode, "runr", url, true, "")
	if err != nil || s.Text != "claude mcp add --transport http runr https://runr.example.com/mcp\n" || s.Format != "shell" {
		t.Fatalf("claude-code oauth = %+v, %v", s, err)
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientClaudeCode, "runr", url, false, "")
	if !strings.Contains(s.Text, `--header "Authorization: Bearer ${RUNR_TOKEN}"`) {
		t.Fatalf("claude-code placeholder = %q", s.Text)
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientClaudeCode, "runr", url, false, "12|abc")
	if !strings.Contains(s.Text, `--header 'Authorization: Bearer 12|abc'`) {
		t.Fatalf("claude-code token = %q", s.Text)
	}

	var cursor struct {
		MCPServers map[string]struct {
			URL     string            `json:"url"`
			Headers map[string]string `json:"headers"`
		} `json:"mcpServers"`
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientCursor, "runr", url, false, "")
	if err := json.Unmarshal([]byte(s.Text), &cursor); err != nil {
		t.Fatal(err)
	}
	if cursor.MCPServers["runr"].URL != url || cursor.MCPServers["runr"].Headers["Authorization"] != "Bearer ${env:RUNR_TOKEN}" {
		t.Fatalf("cursor = %s", s.Text)
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientCursor, "runr", url, true, "")
	if strings.Contains(s.Text, "headers") {
		t.Fatalf("cursor with oauth sends a header: %s", s.Text)
	}

	var vscode struct {
		Servers map[string]struct {
			Type    string            `json:"type"`
			URL     string            `json:"url"`
			Headers map[string]string `json:"headers"`
		} `json:"servers"`
		Inputs []struct {
			ID       string `json:"id"`
			Password bool   `json:"password"`
		} `json:"inputs"`
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientVSCode, "weather", "https://runr.example.com/mcp/servers/weather", false, "")
	if err := json.Unmarshal([]byte(s.Text), &vscode); err != nil {
		t.Fatal(err)
	}
	if vscode.Servers["weather"].Type != "http" || vscode.Servers["weather"].Headers["Authorization"] != "Bearer ${input:weather-token}" ||
		len(vscode.Inputs) != 1 || vscode.Inputs[0].ID != "weather-token" || !vscode.Inputs[0].Password {
		t.Fatalf("vscode = %s", s.Text)
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientVSCode, "runr", url, false, "12|abc")
	if strings.Contains(s.Text, "inputs") || !strings.Contains(s.Text, "Bearer 12|abc") {
		t.Fatalf("vscode with a token = %s", s.Text)
	}

	var desktop struct {
		MCPServers map[string]struct {
			Command string            `json:"command"`
			Args    []string          `json:"args"`
			Env     map[string]string `json:"env"`
		} `json:"mcpServers"`
	}
	s, _ = runr.MCPClientConfig(runr.MCPClientClaudeDesktop, "runr", url, false, "12|abc")
	if err := json.Unmarshal([]byte(s.Text), &desktop); err != nil {
		t.Fatal(err)
	}
	d := desktop.MCPServers["runr"]
	if d.Command != "npx" || !reflect.DeepEqual(d.Args, []string{"-y", "mcp-remote", url, "--header", "Authorization:${RUNR_AUTH}"}) ||
		d.Env["RUNR_AUTH"] != "Bearer 12|abc" {
		t.Fatalf("claude-desktop = %s", s.Text)
	}

	if _, err := runr.MCPClientConfig("emacs", "runr", url, true, ""); err == nil {
		t.Fatal("an unknown client was accepted")
	}
}

func TestMCPInfoAndTools(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)

	info, err := c.MCPInfo(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if info.Endpoint != srv.URL+"/mcp" || !info.OAuth.Enabled || info.Server.Name != "runr" || len(info.ProtocolVersions) == 0 {
		t.Fatalf("info = %+v", info)
	}
	if !reflect.DeepEqual(info.Lifetimes(), []int{7, 30, 90, 180, 365, 0}) || info.DefaultDays() != 30 {
		t.Fatalf("lifetimes = %v, default %d", info.Lifetimes(), info.DefaultDays())
	}
	if !info.Raw.Has("oauth") {
		t.Fatal("the raw answer is not kept")
	}

	cat, err := c.MCPTools(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(cat.Tools) == 0 || cat.Groups[0] != "account" {
		t.Fatalf("catalog = %+v", cat)
	}
	if got := runr.ScopesFor(cat.ReadOnlyTools()); !reflect.DeepEqual(got, []string{"logs.view", "projects.view"}) {
		t.Fatalf("read-only scopes = %v", got)
	}
	// "a|b" needs one of them: the first is taken.
	if got := runr.ScopesFor(cat.InGroups("sandboxes")); !reflect.DeepEqual(got, []string{"sandboxes.create", "sandboxes.exec"}) {
		t.Fatalf("sandbox scopes = %v", got)
	}

	srv.MCP().Disabled = true
	if _, err := c.MCPInfo(ctx); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("MCPInfo of an API without MCP: %v", err)
	}
	if _, err := c.MCPTools(ctx); !errors.Is(err, runr.ErrUnsupported) {
		t.Fatalf("MCPTools of an API without MCP: %v", err)
	}
}

func TestMCPToolsAllowedFollowsTheToken(t *testing.T) {
	srv := runrtest.New(t)
	tok := srv.ScopedToken("agent", "logs.view")
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: tok, HTTPClient: srv.Client(), Retries: -1})
	if err != nil {
		t.Fatal(err)
	}
	cat, err := c.MCPTools(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	allowed := map[string]bool{}
	for _, tl := range cat.Tools {
		allowed[tl.Name] = tl.Allowed
	}
	if !allowed["logs_read"] || !allowed["projects_list"] || allowed["deploy"] || allowed["sandbox_exec"] {
		t.Fatalf("allowed = %v", allowed)
	}
}

func TestMCPClientsAndRevoke(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	claude := srv.SeedMCPClient("Claude", "projects.view", "logs.view")
	srv.SeedMCPClient("Cursor", "projects.view")

	list, err := c.MCPClients(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(list) != 2 || list[0].Name != "Claude" || len(list[0].Tokens) != 1 || list[0].Tokens[0].Abilities[1] != "logs.view" {
		t.Fatalf("clients = %+v", list)
	}
	found, err := c.FindMCPClient(ctx, "claude")
	if err != nil || found.ClientID != claude.ClientID {
		t.Fatalf("find by name = %+v, %v", found, err)
	}
	var nf *runr.NotFoundError
	if _, err := c.FindMCPClient(ctx, "Windsurf"); !errors.As(err, &nf) {
		t.Fatalf("find unknown: %v", err)
	}
	n, err := c.RevokeMCPClient(ctx, claude.ClientID)
	if err != nil || n != 1 {
		t.Fatalf("revoke = %d, %v", n, err)
	}
	if len(srv.MCPClients()) != 1 {
		t.Fatalf("clients after revoke = %+v", srv.MCPClients())
	}
	if _, err := c.RevokeMCPClient(ctx, claude.ClientID); !runr.IsNotFound(err) {
		t.Fatalf("second revoke: %v", err)
	}
}

func TestHostedMCPServerLifecycle(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	p := srv.SeedProject("weather", runr.TypeFaaS, "python", "eu-1", nil)

	s, err := c.HostedMCPServer(ctx, p.ID)
	if err != nil || s != nil {
		t.Fatalf("server of a plain project = %+v, %v", s, err)
	}
	if _, _, err := c.SetHostedMCPServer(ctx, p.ID, runr.MCPServerInput{}); !runr.IsValidation(err) {
		t.Fatalf("a new server without an access mode: %v", err)
	}
	mode := runr.MCPAccessToken
	s, created, err := c.SetHostedMCPServer(ctx, p.ID, runr.MCPServerInput{AccessMode: &mode})
	if err != nil || !created {
		t.Fatalf("set = %+v, %v, %v", s, created, err)
	}
	if s.Handle != "weather" || s.URL != srv.URL+"/mcp/servers/weather" || s.Path != "/mcp" || s.ResourceMetadata == "" {
		t.Fatalf("server = %+v", s)
	}
	path, audit := "/rpc", true
	s, created, err = c.SetHostedMCPServer(ctx, p.ID, runr.MCPServerInput{Path: &path, AuditCalls: &audit})
	if err != nil || created || s.Path != "/rpc" || !s.AuditCalls || s.AccessMode != runr.MCPAccessToken {
		t.Fatalf("change = %+v, %v, %v", s, created, err)
	}
	bad := "/no spaces"
	if _, _, err := c.SetHostedMCPServer(ctx, p.ID, runr.MCPServerInput{Path: &bad}); !runr.IsValidation(err) {
		t.Fatalf("invalid path: %v", err)
	}

	list, err := c.HostedMCPServers(ctx)
	if err != nil || len(list) != 1 || list[0].ProjectName != "weather" {
		t.Fatalf("servers = %+v, %v", list, err)
	}

	s, msg, err := c.RotateMCPServerKey(ctx, p.ID)
	if err != nil || s.KeyRotatedAt == "" || !strings.Contains(msg, "gateway key") {
		t.Fatalf("rotate = %+v, %q, %v", s, msg, err)
	}

	if err := c.DeleteHostedMCPServer(ctx, p.ID); err != nil {
		t.Fatal(err)
	}
	if s, err := c.HostedMCPServer(ctx, p.ID); err != nil || s != nil {
		t.Fatalf("after delete = %+v, %v", s, err)
	}
	if err := c.DeleteHostedMCPServer(ctx, p.ID); !runr.IsNotFound(err) {
		t.Fatalf("second delete: %v", err)
	}
}

func TestHostedMCPServerNeedsHTTPTrigger(t *testing.T) {
	srv := runrtest.New(t)
	p := srv.SeedProject("nightly", runr.TypeFaaS, "python", "eu-1", map[string]any{"trigger_type": "schedule", "schedule": "0 3 * * *"})
	mode := runr.MCPAccessPublic
	_, _, err := client(t, srv).SetHostedMCPServer(context.Background(), p.ID, runr.MCPServerInput{AccessMode: &mode})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 422 || len(ae.Errors["trigger_type"]) == 0 {
		t.Fatalf("schedule project: %v", err)
	}
}

func TestMCPServerCallsAndInspector(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	c := client(t, srv)
	p := srv.SeedProject("weather", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedMCPServer("weather", runr.MCPAccessOAuth)
	srv.SeedMCPServerCall("weather", "tools/call", "forecast", "ok")
	srv.SeedMCPServerCall("weather", "tools/call", "forecast", "error")

	res, err := c.InspectMCPServer(ctx, p.ID, runr.MCPInspectorInput{Message: map[string]any{
		"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": map[string]any{"protocolVersion": "2025-11-25"}}})
	if err != nil {
		t.Fatal(err)
	}
	result, err := res.Result(1)
	if err != nil || result["protocolVersion"] != "2025-11-25" || res.SessionID == "" {
		t.Fatalf("initialize = %+v, %v", res, err)
	}
	res, err = c.InspectMCPServer(ctx, p.ID, runr.MCPInspectorInput{SessionID: res.SessionID,
		Message: map[string]any{"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": map[string]any{"name": "nope"}}})
	if err != nil {
		t.Fatal(err)
	}
	var fe *runr.FailedError
	if _, err := res.Result(2); !errors.As(err, &fe) || !strings.Contains(fe.Message, "Unknown tool") {
		t.Fatalf("JSON-RPC error: %v", err)
	}
	if _, err := c.InspectMCPServer(ctx, p.ID, runr.MCPInspectorInput{Message: map[string]any{"id": 3}}); !runr.IsValidation(err) {
		t.Fatalf("invalid message: %v", err)
	}
	if got := srv.MCPInspectorMessages("weather"); len(got) != 2 {
		t.Fatalf("messages the server got = %v", got)
	}

	calls, page, err := c.MCPServerCalls(ctx, p.ID, runr.MCPCallsFilter{Outcome: "error"})
	if err != nil {
		t.Fatal(err)
	}
	// The seeded error and the inspector's unknown tool.
	if len(calls) != 2 || page.Total != 2 || calls[0].Name != "nope" || calls[1].Name != "forecast" {
		t.Fatalf("error calls = %+v, %+v", calls, page)
	}
	calls, page, err = c.MCPServerCalls(ctx, p.ID, runr.MCPCallsFilter{PerPage: 1})
	if err != nil || len(calls) != 1 || page.Total != 4 || page.LastPage != 4 {
		t.Fatalf("one per page = %+v, %+v, %v", calls, page, err)
	}

	srv.MCP().Unreachable = true
	_, err = c.InspectMCPServer(ctx, p.ID, runr.MCPInspectorInput{Message: map[string]any{"jsonrpc": "2.0", "id": 4, "method": "ping"}})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 502 {
		t.Fatalf("unreachable server: %v", err)
	}
}

func TestMCPServerTemplates(t *testing.T) {
	srv := runrtest.New(t)
	list, err := client(t, srv).MCPServerTemplates(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if len(list) != 3 || list[0].ID != "python" || list[0].Files["main.py"] == "" || list[2].ID != "image" ||
		list[2].Files != nil || list[2].Port != 8080 {
		t.Fatalf("templates = %+v", list)
	}
}
