package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
)

// AI agents (docs/mcp.md): the RunR MCP server at <console>/mcp, the tool catalog, the OAuth
// clients connected with the caller's account, and hosted MCP servers — projects served at
// <console>/mcp/servers/<handle> behind RunR's gateway.

// MCPInfo is GET /mcp: how a client connects to the RunR MCP server.
type MCPInfo struct {
	Endpoint string `json:"endpoint"`
	Server   struct {
		Name    string `json:"name"`
		Title   string `json:"title,omitempty"`
		Version string `json:"version,omitempty"`
	} `json:"server"`
	ProtocolVersions []string `json:"protocol_versions"`
	OAuth            MCPOAuth `json:"oauth"`
	// TokenLifetimes and DefaultLifetime are days (null: until revoked); see Lifetimes and
	// DefaultDays.
	TokenLifetimes  json.RawMessage `json:"token_lifetimes,omitempty"`
	DefaultLifetime json.RawMessage `json:"default_token_lifetime,omitempty"`
	Raw             RawObject       `json:"-"`
}

// flexDays reads a number of days sent as a number, a numeric string or null (0).
func flexDays(raw json.RawMessage) int {
	var n json.Number
	if json.Unmarshal(raw, &n) == nil {
		if i, err := n.Int64(); err == nil {
			return int(i)
		}
	}
	var s string
	if json.Unmarshal(raw, &s) == nil {
		i, _ := strconv.Atoi(strings.TrimSpace(s))
		return i
	}
	return 0
}

// Lifetimes returns the token lifetimes in days the OAuth consent offers; 0 means until revoked.
func (i *MCPInfo) Lifetimes() []int {
	var items []json.RawMessage
	if json.Unmarshal(i.TokenLifetimes, &items) != nil {
		return nil
	}
	out := make([]int, 0, len(items))
	for _, it := range items {
		out = append(out, flexDays(it))
	}
	return out
}

// DefaultDays returns the default token lifetime in days (30 when the API names none).
func (i *MCPInfo) DefaultDays() int {
	if len(i.DefaultLifetime) == 0 {
		return 30
	}
	return flexDays(i.DefaultLifetime)
}

// MCPOAuth describes RunR's OAuth 2.1 authorization server for MCP clients.
type MCPOAuth struct {
	Enabled               bool   `json:"enabled"`
	Issuer                string `json:"issuer,omitempty"`
	ResourceMetadata      string `json:"resource_metadata,omitempty"`
	AuthorizationEndpoint string `json:"authorization_endpoint,omitempty"`
	TokenEndpoint         string `json:"token_endpoint,omitempty"`
	RegistrationEndpoint  string `json:"registration_endpoint,omitempty"`
}

// MCPTool is one tool of the RunR MCP server's catalog (GET /mcp/tools).
type MCPTool struct {
	Name        string `json:"name"`
	Title       string `json:"title,omitempty"`
	Description string `json:"description,omitempty"`
	Group       string `json:"group"`
	// Permissions are what the tool needs: every entry, an entry "a|b" is satisfied by either.
	Permissions []string        `json:"permissions"`
	Allowed     bool            `json:"allowed"`
	Annotations map[string]any  `json:"annotations,omitempty"`
	Streams     bool            `json:"streams"`
	InputSchema json.RawMessage `json:"input_schema,omitempty"`
}

// ReadOnly reports the tool's readOnlyHint annotation.
func (t *MCPTool) ReadOnly() bool {
	b, _ := t.Annotations["readOnlyHint"].(bool)
	return b
}

// MCPToolCatalog is the tool catalog and its groups in catalog order.
type MCPToolCatalog struct {
	Tools  []MCPTool `json:"data"`
	Groups []string  `json:"groups"`
}

// ScopesFor returns the permissions a token needs to use tools: for each requirement the first
// of its alternatives, sorted and without duplicates.
func ScopesFor(tools []MCPTool) []string {
	set := map[string]bool{}
	for _, t := range tools {
		for _, req := range t.Permissions {
			first, _, _ := strings.Cut(req, "|")
			if first = strings.TrimSpace(first); first != "" {
				set[first] = true
			}
		}
	}
	out := make([]string, 0, len(set))
	for p := range set {
		out = append(out, p)
	}
	sort.Strings(out)
	return out
}

// InGroups returns the tools of the given groups.
func (c *MCPToolCatalog) InGroups(groups ...string) []MCPTool {
	want := map[string]bool{}
	for _, g := range groups {
		want[g] = true
	}
	var out []MCPTool
	for _, t := range c.Tools {
		if want[t.Group] {
			out = append(out, t)
		}
	}
	return out
}

// ReadOnlyTools returns the tools with the readOnlyHint annotation.
func (c *MCPToolCatalog) ReadOnlyTools() []MCPTool {
	var out []MCPTool
	for _, t := range c.Tools {
		if t.ReadOnly() {
			out = append(out, t)
		}
	}
	return out
}

// MCPClient is an OAuth client connected with the caller's account (GET /mcp/clients).
type MCPClient struct {
	ClientID      string           `json:"client_id"`
	Name          string           `json:"name"`
	ClientURI     string           `json:"client_uri,omitempty"`
	RedirectHosts []string         `json:"redirect_hosts"`
	Tokens        []MCPClientToken `json:"tokens"`
	ConnectedAt   string           `json:"connected_at,omitempty"`
	LastUsedAt    string           `json:"last_used_at,omitempty"`
}

// MCPClientToken is one token an OAuth client holds.
type MCPClientToken struct {
	ID         FlexID   `json:"id"`
	Name       string   `json:"name"`
	Abilities  []string `json:"abilities"`
	Resource   string   `json:"resource,omitempty"`
	CreatedAt  string   `json:"created_at,omitempty"`
	LastUsedAt string   `json:"last_used_at,omitempty"`
	ExpiresAt  string   `json:"expires_at,omitempty"`
}

// HostedMCPServer is a project served as an MCP server (HostedServerResource).
type HostedMCPServer struct {
	ID               string `json:"id"`
	ProjectID        string `json:"project_id"`
	ProjectName      string `json:"project_name,omitempty"`
	Handle           string `json:"handle"`
	URL              string `json:"url"`
	AccessMode       string `json:"access_mode"`
	Path             string `json:"path"`
	AuditCalls       bool   `json:"audit_calls"`
	FunctionURL      string `json:"function_url,omitempty"`
	ResourceMetadata string `json:"resource_metadata,omitempty"`
	KeyRotatedAt     string `json:"key_rotated_at,omitempty"`
	NeedsRedeploy    bool   `json:"needs_redeploy"`
	CreatedAt        string `json:"created_at,omitempty"`
	UpdatedAt        string `json:"updated_at,omitempty"`
}

// Access modes of a hosted MCP server.
const (
	MCPAccessPublic = "public"
	MCPAccessToken  = "token"
	MCPAccessOAuth  = "oauth"
)

// MCPServerTemplate is a starter of hosted MCP servers (GET /mcp-servers/templates).
type MCPServerTemplate struct {
	ID                 string            `json:"id"`
	Name               string            `json:"name"`
	Description        string            `json:"description,omitempty"`
	ServiceType        string            `json:"service_type"`
	Runtime            string            `json:"runtime,omitempty"`
	RuntimeRegistered  bool              `json:"runtime_registered"`
	RuntimeAvailable   bool              `json:"runtime_available"`
	CanRegisterRuntime bool              `json:"can_register_runtime"`
	RuntimeManifest    string            `json:"runtime_manifest,omitempty"`
	Files              map[string]string `json:"files,omitempty"`
	Path               string            `json:"path"`
	Port               int               `json:"port,omitempty"`
}

// UnmarshalJSON tolerates files encoded as {} / null / [].
func (t *MCPServerTemplate) UnmarshalJSON(b []byte) error {
	type plain MCPServerTemplate
	var p struct {
		plain
		Files json.RawMessage `json:"files"`
	}
	if err := json.Unmarshal(b, &p); err != nil {
		return err
	}
	*t = MCPServerTemplate(p.plain)
	files, err := decodeFileMap(p.Files)
	if err != nil {
		return err
	}
	if len(files) > 0 {
		t.Files = files
	} else {
		t.Files = nil
	}
	return nil
}

// MCPServerInput is the body of PUT /projects/{project}/mcp-server; nil fields are not sent.
type MCPServerInput struct {
	AccessMode *string `json:"access_mode,omitempty"`
	Path       *string `json:"path,omitempty"`
	AuditCalls *bool   `json:"audit_calls,omitempty"`
}

// MCPServerCall is one entry of a hosted server's call log.
type MCPServerCall struct {
	ID         string `json:"id"`
	OccurredAt string `json:"occurred_at"`
	Method     string `json:"method"`
	Name       string `json:"name,omitempty"`
	JSONRPCID  any    `json:"jsonrpc_id,omitempty"`
	StatusCode int    `json:"status_code"`
	Outcome    string `json:"outcome"`
	Error      string `json:"error,omitempty"`
	DurationMs int    `json:"duration_ms"`
	Actor      struct {
		Type string `json:"type,omitempty"`
		ID   any    `json:"id,omitempty"`
		Name string `json:"name,omitempty"`
	} `json:"actor"`
	Auth          string `json:"auth"`
	SessionID     string `json:"session_id,omitempty"`
	RequestBytes  int    `json:"request_bytes,omitempty"`
	ResponseBytes int    `json:"response_bytes,omitempty"`
}

// Outcomes of a hosted server call.
var MCPCallOutcomes = []string{"ok", "error", "denied", "unreachable"}

// MCPCallsFilter narrows the call log.
type MCPCallsFilter struct {
	Outcome string
	Method  string
	// PerPage is 1–100 (default 50).
	PerPage int
}

// MCPInspectorInput is one JSON-RPC message sent to a hosted server through the gateway.
type MCPInspectorInput struct {
	Message         map[string]any `json:"message"`
	SessionID       string         `json:"session_id,omitempty"`
	ProtocolVersion string         `json:"protocol_version,omitempty"`
}

// MCPInspectorResult is the server's answer to an inspector message.
type MCPInspectorResult struct {
	Status      int              `json:"status"`
	SessionID   string           `json:"session_id,omitempty"`
	ContentType string           `json:"content_type,omitempty"`
	Messages    []map[string]any `json:"messages"`
	Raw         string           `json:"raw,omitempty"`
	DurationMs  int              `json:"duration_ms"`
	Method      string           `json:"method,omitempty"`
	Name        string           `json:"name,omitempty"`
}

// Result returns the "result" of the answer to request id (the first message without an id
// match when id is nil), or an error built from a JSON-RPC error.
func (r *MCPInspectorResult) Result(id any) (map[string]any, error) {
	for _, m := range r.Messages {
		if id != nil && fmt.Sprint(m["id"]) != fmt.Sprint(id) {
			continue
		}
		if e, ok := m["error"].(map[string]any); ok {
			return nil, &FailedError{What: "MCP request", Ref: fmt.Sprint(m["id"]), Status: fmt.Sprint(e["code"]),
				Message: fmt.Sprint(e["message"])}
		}
		if res, ok := m["result"].(map[string]any); ok {
			return res, nil
		}
	}
	return nil, nil
}

// ---------------------------------------------------------------------------------------
// Client methods

// MCPInfo returns the connection settings of the RunR MCP server; ErrUnsupported on APIs
// without it.
func (c *Client) MCPInfo(ctx context.Context) (*MCPInfo, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/mcp"}, &raw)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	var info MCPInfo
	if err := json.Unmarshal(raw, &info); err != nil {
		return nil, fmt.Errorf("decode GET /mcp: %w", err)
	}
	_ = json.Unmarshal(raw, &info.Raw)
	return &info, nil
}

// MCPTools returns the tool catalog; Allowed says whether the caller's token may use a tool.
func (c *Client) MCPTools(ctx context.Context) (*MCPToolCatalog, error) {
	var out MCPToolCatalog
	err := c.do(ctx, request{method: http.MethodGet, path: "/mcp/tools"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// MCPClients returns the OAuth clients connected with the caller's account.
func (c *Client) MCPClients(ctx context.Context) ([]MCPClient, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/mcp/clients"}, &raw); err != nil {
		return nil, err
	}
	var list []MCPClient
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// FindMCPClient resolves a connected client by id or name.
func (c *Client) FindMCPClient(ctx context.Context, ref string) (*MCPClient, error) {
	list, err := c.MCPClients(ctx)
	if err != nil {
		return nil, err
	}
	var byName []*MCPClient
	for i := range list {
		if list[i].ClientID == ref {
			return &list[i], nil
		}
		if strings.EqualFold(list[i].Name, ref) {
			byName = append(byName, &list[i])
		}
	}
	if len(byName) == 1 {
		return byName[0], nil
	}
	if len(byName) > 1 {
		return nil, fmt.Errorf("%d connected clients are named %q; use the client id", len(byName), ref)
	}
	return nil, &NotFoundError{Kind: "MCP client", Ref: ref}
}

// RevokeMCPClient revokes every token of the caller the client holds; it returns how many.
func (c *Client) RevokeMCPClient(ctx context.Context, clientID string) (int, error) {
	var out struct {
		Revoked int `json:"revoked"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: "/mcp/clients/" + seg(clientID)}, &out)
	return out.Revoked, err
}

// HostedMCPServers returns the hosted MCP servers on projects the caller can see.
func (c *Client) HostedMCPServers(ctx context.Context) ([]HostedMCPServer, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/mcp-servers"}, &raw); err != nil {
		return nil, err
	}
	var list []HostedMCPServer
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// MCPServerTemplates returns the starters of hosted MCP servers.
func (c *Client) MCPServerTemplates(ctx context.Context) ([]MCPServerTemplate, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/mcp-servers/templates"}, &raw); err != nil {
		return nil, err
	}
	var list []MCPServerTemplate
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, err
	}
	return list, nil
}

// HostedMCPServer returns the hosted server of a project; nil, nil when the project is not
// served as one.
func (c *Client) HostedMCPServer(ctx context.Context, projectID string) (*HostedMCPServer, error) {
	var raw []byte
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/mcp-server"}, &raw)
	if IsNotFound(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var s HostedMCPServer
	if err := decodeData(raw, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

// SetHostedMCPServer serves a project as an MCP server or changes it; created reports a new
// server (access_mode is required then).
func (c *Client) SetHostedMCPServer(ctx context.Context, projectID string, in MCPServerInput) (*HostedMCPServer, bool, error) {
	var out struct {
		Data    HostedMCPServer `json:"data"`
		Created bool            `json:"created"`
	}
	if err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/mcp-server", body: in}, &out); err != nil {
		return nil, false, err
	}
	return &out.Data, out.Created, nil
}

// DeleteHostedMCPServer stops serving a project as an MCP server.
func (c *Client) DeleteHostedMCPServer(ctx context.Context, projectID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/mcp-server"}, nil)
}

// RotateMCPServerKey creates a new gateway key; the workload gets it with the next deploy, and
// for seven days the gateway signs with both keys. The key itself is never returned.
func (c *Client) RotateMCPServerKey(ctx context.Context, projectID string) (*HostedMCPServer, string, error) {
	var out struct {
		Data    HostedMCPServer `json:"data"`
		Message string          `json:"message"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/mcp-server/rotate-key"}, &out); err != nil {
		return nil, "", err
	}
	return &out.Data, out.Message, nil
}

// MCPServerCalls returns the newest entries of a hosted server's call log.
func (c *Client) MCPServerCalls(ctx context.Context, projectID string, f MCPCallsFilter) ([]MCPServerCall, *Page, error) {
	q := url.Values{}
	if f.Outcome != "" {
		q.Set("outcome", f.Outcome)
	}
	if f.Method != "" {
		q.Set("method", f.Method)
	}
	if f.PerPage > 0 {
		q.Set("per_page", strconv.Itoa(f.PerPage))
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/mcp-server/calls", query: q}, &raw); err != nil {
		return nil, nil, err
	}
	var list []MCPServerCall
	page := &Page{}
	if err := decodeList(raw, &list, page); err != nil {
		return nil, nil, err
	}
	return list, page, nil
}

// InspectMCPServer sends one JSON-RPC message to a project's hosted server through the
// gateway as the caller (auth "console") and returns the messages of the answer.
func (c *Client) InspectMCPServer(ctx context.Context, projectID string, in MCPInspectorInput) (*MCPInspectorResult, error) {
	var out MCPInspectorResult
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/mcp-server/inspector", body: in}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// ---------------------------------------------------------------------------------------
// Client configuration snippets

// MCP clients a configuration snippet exists for.
const (
	MCPClientClaudeCode    = "claude-code"
	MCPClientClaudeDesktop = "claude-desktop"
	MCPClientCursor        = "cursor"
	MCPClientVSCode        = "vscode"
)

// MCPClientNames are the clients of MCPClientConfig, in display order.
var MCPClientNames = []string{MCPClientClaudeCode, MCPClientClaudeDesktop, MCPClientCursor, MCPClientVSCode}

// MCPSnippet is the configuration of one MCP client.
type MCPSnippet struct {
	Client string `json:"client"`
	// Where is where the snippet goes (a command to run, or a file).
	Where string `json:"where"`
	// Format is "shell" or "json".
	Format string `json:"format"`
	Text   string `json:"text"`
}

// MCPClientConfig returns the configuration snippet of client for the MCP server name at
// serverURL. With oauth the client signs in through RunR's OAuth server and needs the URL only;
// otherwise it sends token as a bearer token ("" = a placeholder the client fills in: the
// RUNR_TOKEN environment variable, or VS Code's password prompt).
func MCPClientConfig(client, name, serverURL string, oauth bool, token string) (*MCPSnippet, error) {
	if name == "" {
		name = "runr"
	}
	header := func(placeholder string) string {
		if token != "" {
			return "Bearer " + token
		}
		return "Bearer " + placeholder
	}
	js := func(v any) string {
		b, _ := json.MarshalIndent(v, "", "  ")
		return string(b) + "\n"
	}
	type obj = map[string]any
	switch client {
	case MCPClientClaudeCode:
		text := "claude mcp add --transport http " + shellQuote(name) + " " + shellQuote(serverURL)
		if !oauth {
			h := "Authorization: " + header("${RUNR_TOKEN}")
			if token != "" {
				text += " \\\n  --header " + shellQuote(h)
			} else {
				text += " \\\n  --header \"" + h + "\""
			}
		}
		return &MCPSnippet{Client: client, Where: "run in a shell", Format: "shell", Text: text + "\n"}, nil
	case MCPClientCursor:
		srv := obj{"url": serverURL}
		if !oauth {
			srv["headers"] = obj{"Authorization": header("${env:RUNR_TOKEN}")}
		}
		return &MCPSnippet{Client: client, Where: "~/.cursor/mcp.json (or .cursor/mcp.json in the project)", Format: "json",
			Text: js(obj{"mcpServers": obj{name: srv}})}, nil
	case MCPClientVSCode:
		srv := obj{"type": "http", "url": serverURL}
		cfg := obj{"servers": obj{name: srv}}
		if !oauth {
			if token != "" {
				srv["headers"] = obj{"Authorization": "Bearer " + token}
			} else {
				id := name + "-token"
				srv["headers"] = obj{"Authorization": "Bearer ${input:" + id + "}"}
				cfg["inputs"] = []obj{{"type": "promptString", "id": id, "description": "RunR API token", "password": true}}
			}
		}
		return &MCPSnippet{Client: client, Where: ".vscode/mcp.json", Format: "json", Text: js(cfg)}, nil
	case MCPClientClaudeDesktop:
		args := []string{"-y", "mcp-remote", serverURL}
		srv := obj{"command": "npx"}
		if !oauth {
			args = append(args, "--header", "Authorization:${RUNR_AUTH}")
			srv["env"] = obj{"RUNR_AUTH": header("<RUNR_TOKEN>")}
		}
		srv["args"] = args
		return &MCPSnippet{Client: client, Where: "claude_desktop_config.json (through the mcp-remote bridge)", Format: "json",
			Text: js(obj{"mcpServers": obj{name: srv}})}, nil
	}
	return nil, fmt.Errorf("unknown MCP client %q (known: %s)", client, strings.Join(MCPClientNames, ", "))
}

// shellQuote quotes s for a POSIX shell when it needs it.
func shellQuote(s string) string {
	if s != "" && !strings.ContainsAny(s, " \t\n\"'$;&|<>*?(){}[]\\`!#~") {
		return s
	}
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}
