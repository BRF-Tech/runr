package cmd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/internal/version"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() {
	registerCommand(newMCPCmd)
	registerCommand(newMCPServerCmd)
}

// mcpProtocolVersion is the MCP revision `runr mcp-server inspect` asks for in initialize.
const mcpProtocolVersion = "2025-11-25"

// mcpTokenPresets are the scope sets of `runr mcp token create --preset`.
var mcpTokenPresets = []string{"read", "deploy", "full"}

func newMCPCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "mcp",
		Short: "Connect AI agents to the RunR MCP server: client configuration, tokens, clients, tools",
		Long: "RunR serves the Model Context Protocol at <console>/mcp: AI agents (Claude Code, Claude\n" +
			"Desktop, Cursor, VS Code, …) operate RunR with exactly the permissions of their token.\n" +
			"Without a subcommand, `runr mcp` prints the connection details and the configuration of\n" +
			"the common clients.",
		Args: cobra.NoArgs,
	}
	config := newMCPConfigCmd(app)
	cmd.RunE = config.RunE
	copyFlags(cmd, config)
	cmd.AddCommand(config, newMCPTokenCmd(app), newMCPClientsCmd(app), newMCPToolsCmd(app))
	return cmd
}

// copyFlags gives parent the flags of child (a command that runs its default subcommand).
func copyFlags(parent, child *cobra.Command) {
	parent.Flags().AddFlagSet(child.Flags())
}

// mcpEndpoint returns the MCP endpoint and settings; an API without the MCP settings endpoint
// gives the conventional URL and no settings.
func mcpEndpoint(ctx context.Context, c *runr.Client) (string, *runr.MCPInfo, error) {
	info, err := c.MCPInfo(ctx)
	if errors.Is(err, runr.ErrUnsupported) {
		return strings.TrimRight(c.ConsoleURL(), "/") + "/mcp", nil, nil
	}
	if err != nil {
		return "", nil, err
	}
	return info.Endpoint, info, nil
}

func mcpClientTitle(client string) string {
	return map[string]string{
		runr.MCPClientClaudeCode: "Claude Code", runr.MCPClientClaudeDesktop: "Claude Desktop",
		runr.MCPClientCursor: "Cursor", runr.MCPClientVSCode: "VS Code",
	}[client]
}

// mcpSnippets builds the snippets of the clients (all when client is "").
func mcpSnippets(client, name, url string, oauth bool, token string) ([]runr.MCPSnippet, error) {
	clients := runr.MCPClientNames
	if client != "" {
		clients = []string{client}
	}
	var out []runr.MCPSnippet
	for _, cl := range clients {
		s, err := runr.MCPClientConfig(cl, name, url, oauth, token)
		if err != nil {
			return nil, usageErrorf("--client: %v", err)
		}
		out = append(out, *s)
	}
	return out, nil
}

func noteSnippets(t *output.TableWriter, snippets []runr.MCPSnippet) {
	for _, s := range snippets {
		t.Note("")
		t.Note("%s (%s):", mcpClientTitle(s.Client), s.Where)
		t.Note("%s", strings.TrimRight(s.Text, "\n"))
	}
}

func daysLabel(d int) string {
	if d == 0 {
		return "until revoked"
	}
	return countLabel(d, "day")
}

func newMCPConfigCmd(app *App) *cobra.Command {
	var client, token, auth string
	var tokenStdin bool
	cmd := &cobra.Command{
		Use:     "config",
		Aliases: []string{"connect"},
		Short:   "Print the MCP endpoint and the configuration of Claude Code, Claude Desktop, Cursor and VS Code",
		Long: "Print how to connect a client to the RunR MCP server. With OAuth (the default where the\n" +
			"server offers it) a client needs the URL only and signs in through the browser; with\n" +
			"--auth token it sends an API token (--token or --token-stdin embeds one, otherwise the\n" +
			"snippets read it from RUNR_TOKEN or ask for it). `runr mcp token create` makes a token.",
		Example: "  runr mcp\n" +
			"  runr mcp config --client cursor --auth token\n" +
			"  runr mcp token create --preset read --client claude-code",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if tokenStdin {
				if token, err = app.readLine(""); err != nil {
					return err
				}
				token = strings.TrimSpace(token)
			}
			endpoint, info, err := mcpEndpoint(cmd.Context(), c)
			if err != nil {
				return err
			}
			oauthAvailable := info != nil && info.OAuth.Enabled
			mode := auth
			switch mode {
			case "":
				mode = "token"
				if oauthAvailable && token == "" {
					mode = "oauth"
				}
			case "oauth":
				if token != "" {
					return usageErrorf("--auth oauth sends no token; leave out --token")
				}
				if !oauthAvailable {
					app.Warn("this RunR API does not offer OAuth for MCP clients; use --auth token")
				}
			case "token":
			default:
				return usageErrorf("--auth must be oauth or token")
			}
			snippets, err := mcpSnippets(client, "runr", endpoint, mode == "oauth", token)
			if err != nil {
				return err
			}
			out := map[string]any{"endpoint": endpoint, "auth": mode, "snippets": snippets}
			if info != nil {
				out["mcp"] = info.Raw
			}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.KV("Endpoint", endpoint)
				t.KV("Transport", "Streamable HTTP")
				if info != nil {
					server := info.Server.Title
					if server == "" {
						server = info.Server.Name
					}
					if info.Server.Version != "" {
						server += " " + info.Server.Version
					}
					t.KV("Server", server)
					t.KV("Protocol", joinOrDash(info.ProtocolVersions))
					if info.OAuth.Enabled {
						t.KV("Auth", "OAuth 2.1 (the client signs in through the browser) or an API token")
						t.KV("OAuth metadata", output.Dash(info.OAuth.ResourceMetadata))
						var lifetimes []string
						for _, d := range info.Lifetimes() {
							lifetimes = append(lifetimes, daysLabel(d))
						}
						if len(lifetimes) > 0 {
							t.KV("Token lifetimes", strings.Join(lifetimes, ", ")+" (default "+daysLabel(info.DefaultDays())+")")
						}
					} else {
						t.KV("Auth", "API token (Authorization: Bearer)")
					}
				}
				t.KV("Client auth", map[string]string{"oauth": "OAuth: the URL is enough", "token": "API token"}[mode])
				noteSnippets(t, snippets)
				if mode == "token" && token == "" {
					t.Note("")
					t.Note("Create a token for the agent with `runr mcp token create` (it prints these snippets with the token).")
				}
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&client, "client", "", "only this client: claude-code, claude-desktop, cursor or vscode")
	f.StringVar(&auth, "auth", "", "oauth or token (default: oauth where the server offers it, else token)")
	f.StringVar(&token, "token", "", "API token to embed in the snippets (it ends up in the client's configuration)")
	f.BoolVar(&tokenStdin, "token-stdin", false, "read the token to embed from stdin")
	return cmd
}

// ---------------------------------------------------------------------------------------
// runr mcp token create

func newMCPTokenCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "token",
		Short: "API tokens for MCP clients",
	}
	var preset, expires, client string
	var groups, scopes []string
	create := &cobra.Command{
		Use:   "create [NAME]",
		Short: "Create an API token for an AI agent and print it with the client configuration",
		Long: "Create an API token an MCP client sends as a bearer token. The token decides what the agent\n" +
			"may do: pick tool groups (--group), permissions (--scope) or a preset:\n" +
			"  read    the permissions of every read-only tool\n" +
			"  deploy  " + strings.Join(mcpDeployScopes(), ", ") + "\n" +
			"  full    every permission you hold\n" +
			"On a terminal without any of them, runr asks. The token never exceeds your own permissions\n" +
			"and expires after the server's default lifetime unless --expires says otherwise\n" +
			"(--expires never: until revoked). Its value is printed once.",
		Example: "  runr mcp token create --preset read\n" +
			"  runr mcp token create ci-agent --group deployments --group operations --client cursor\n" +
			"  runr mcp token create --scope projects.view,logs.view --expires 7d",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			if client != "" {
				if _, err := runr.MCPClientConfig(client, "runr", "https://x", false, "t"); err != nil {
					return usageErrorf("--client: %v", err)
				}
			}
			endpoint, info, err := mcpEndpoint(ctx, c)
			if err != nil {
				return err
			}
			abilities, full, err := mcpTokenAbilities(ctx, app, c, preset, groups, scopes)
			if err != nil {
				return err
			}
			if !full && len(abilities) == 0 {
				// Without abilities the API would grant every permission.
				return usageErrorf("the chosen tools need no permission; pick other groups or --scope")
			}
			name := "mcp-agent"
			if client != "" {
				name = "mcp-" + client
			}
			if len(args) == 1 {
				name = args[0]
			}
			in := runr.CreateTokenInput{Name: name}
			if !full {
				in.Abilities = abilities
			}
			days := 30
			if info != nil {
				days = info.DefaultDays()
			}
			switch strings.ToLower(strings.TrimSpace(expires)) {
			case "":
			case "never", "0":
				days = 0
			default:
				d, err := parseDays(expires)
				if err != nil {
					return usageErrorf("--expires: %v", err)
				}
				days = -1
				t := time.Now().Add(d).UTC().Truncate(time.Second)
				in.ExpiresAt = &t
			}
			if days > 0 {
				t := time.Now().Add(time.Duration(days) * 24 * time.Hour).UTC().Truncate(time.Second)
				in.ExpiresAt = &t
			}
			plain, tok, err := c.CreateToken(ctx, in)
			if err != nil {
				return err
			}
			snippetClient := client
			if snippetClient == "" {
				snippetClient = runr.MCPClientClaudeCode
			}
			snippets, err := mcpSnippets(snippetClient, "runr", endpoint, false, plain)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"token": plain, "record": tok, "endpoint": endpoint, "snippets": snippets},
				func(t *output.TableWriter) {
					t.Note("%s", plain)
					t.Note("")
					scope := "every permission you hold"
					if !full {
						scope = strings.Join(tok.Abilities, ", ")
					}
					expiry := "until revoked"
					if tok.ExpiresAt != "" {
						expiry = "until " + tok.ExpiresAt
					}
					t.Note("Token %q created (id %s) with %s, valid %s. Store it now: it is not shown again.",
						tok.Name, tok.ID, scope, expiry)
					noteSnippets(t, snippets)
				})
		},
	}
	f := create.Flags()
	f.StringVar(&preset, "preset", "", "scope set: read, deploy or full")
	f.StringArrayVar(&groups, "group", nil, "tool group the agent may use (repeatable): projects, deployments, operations, …")
	f.StringSliceVar(&scopes, "scope", nil, "permission the token gets (repeatable or comma-separated)")
	f.StringVar(&expires, "expires", "", "lifetime, e.g. 7d or 720h, or never (default: the server's default, 30 days)")
	f.StringVar(&client, "client", "", "print the configuration of this client: claude-code (default), claude-desktop, cursor or vscode")
	cmd.AddCommand(create)
	return cmd
}

// mcpDeployScopes is the deploy preset: what `runr deploy` needs, plus reading logs and metrics.
func mcpDeployScopes() []string {
	return uniqSorted(append(append([]string{}, runr.ScopesDeploy...), "logs.view", "metrics.view"))
}

// mcpTokenAbilities resolves the scope flags (or asks on a terminal); full means every
// permission of the user (no abilities sent).
func mcpTokenAbilities(ctx context.Context, app *App, c *runr.Client, preset string, groups, scopes []string) ([]string, bool, error) {
	if preset != "" {
		ok := false
		for _, p := range mcpTokenPresets {
			ok = ok || p == preset
		}
		if !ok {
			return nil, false, usageErrorf("--preset must be %s", strings.Join(mcpTokenPresets, ", "))
		}
	}
	if preset == "" && len(groups) == 0 && len(scopes) == 0 {
		if !app.interactive() {
			return nil, false, usageErrorf("choose what the agent may do: --preset %s, --group or --scope", strings.Join(mcpTokenPresets, "|"))
		}
		return mcpPickScopes(ctx, app, c)
	}
	if preset == "full" {
		return nil, true, nil
	}
	var out []string
	needCatalog := preset == "read" || len(groups) > 0
	var catalog *runr.MCPToolCatalog
	if needCatalog {
		var err error
		if catalog, err = c.MCPTools(ctx); err != nil {
			if errors.Is(err, runr.ErrUnsupported) {
				return nil, false, usageErrorf("this RunR API has no MCP tool catalog: use --scope")
			}
			return nil, false, err
		}
	}
	switch preset {
	case "read":
		out = append(out, runr.ScopesFor(catalog.ReadOnlyTools())...)
	case "deploy":
		out = append(out, mcpDeployScopes()...)
	}
	if len(groups) > 0 {
		known := map[string]bool{}
		for _, g := range catalog.Groups {
			known[g] = true
		}
		for _, g := range groups {
			if !known[g] {
				return nil, false, usageErrorf("unknown tool group %q (groups: %s)", g, strings.Join(catalog.Groups, ", "))
			}
		}
		out = append(out, runr.ScopesFor(catalog.InGroups(groups...))...)
	}
	out = append(out, scopes...)
	out = uniqSorted(out)
	if len(out) == 0 {
		return nil, false, usageErrorf("the chosen tools need no permission; pick other groups or --scope")
	}
	return out, false, nil
}

// mcpPickScopes asks which tool groups the agent may use.
func mcpPickScopes(ctx context.Context, app *App, c *runr.Client) ([]string, bool, error) {
	catalog, err := c.MCPTools(ctx)
	if err != nil {
		if errors.Is(err, runr.ErrUnsupported) {
			return nil, false, usageErrorf("this RunR API has no MCP tool catalog: use --scope")
		}
		return nil, false, err
	}
	var groups []string
	count := map[string]int{}
	for _, t := range catalog.Tools {
		if count[t.Group] == 0 {
			groups = append(groups, t.Group)
		}
		count[t.Group]++
	}
	order := map[string]int{}
	for i, g := range catalog.Groups {
		order[g] = i + 1
	}
	sort.SliceStable(groups, func(i, j int) bool { return mcpOrder(order, groups[i]) < mcpOrder(order, groups[j]) })

	fmt.Fprintln(app.Err, "What may the agent do?")
	fmt.Fprintf(app.Err, "  r   read only: every read-only tool (%s)\n", strings.Join(runr.ScopesFor(catalog.ReadOnlyTools()), ", "))
	fmt.Fprintln(app.Err, "  a   full access: every permission you hold")
	for i, g := range groups {
		fmt.Fprintf(app.Err, "  %-3d %s: %s (%s)\n", i+1, g, countLabel(count[g], "tool"),
			strings.Join(runr.ScopesFor(catalog.InGroups(g)), ", "))
	}
	ans, err := app.readLine("Numbers separated by commas, r or a: ")
	if err != nil {
		return nil, false, err
	}
	ans = strings.ToLower(strings.TrimSpace(ans))
	switch ans {
	case "":
		return nil, false, errAborted
	case "a":
		return nil, true, nil
	case "r":
		return runr.ScopesFor(catalog.ReadOnlyTools()), false, nil
	}
	var picked []string
	for _, part := range strings.Split(ans, ",") {
		n, err := strconv.Atoi(strings.TrimSpace(part))
		if err != nil || n < 1 || n > len(groups) {
			return nil, false, usageErrorf("%q is not one of the numbers 1-%d", strings.TrimSpace(part), len(groups))
		}
		picked = append(picked, groups[n-1])
	}
	return runr.ScopesFor(catalog.InGroups(picked...)), false, nil
}

func mcpOrder(order map[string]int, g string) int {
	if o, ok := order[g]; ok {
		return o
	}
	return 1 << 20
}

// ---------------------------------------------------------------------------------------
// runr mcp clients, runr mcp tools

func newMCPClientsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "clients",
		Short: "OAuth clients connected with your account (Claude, Cursor, …) and their tokens",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the connected clients",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.MCPClients(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.MCPClient{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("CLIENT", "NAME", "REDIRECT HOSTS", "TOKENS", "CONNECTED", "LAST USED")
				for _, cl := range list {
					t.Row(cl.ClientID, cl.Name, joinOrDash(cl.RedirectHosts), strconv.Itoa(len(cl.Tokens)),
						gitAge(cl.ConnectedAt), gitAge(cl.LastUsedAt))
				}
				if len(list) == 0 {
					t.Note("No OAuth client is connected with your account.")
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	var yes bool
	revoke := &cobra.Command{
		Use:     "revoke CLIENT",
		Aliases: []string{"rm", "disconnect"},
		Short:   "Revoke every token a client holds of yours (by client id or name)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			cl, err := c.FindMCPClient(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Revoke the access of "+cl.Name+" ("+countLabel(len(cl.Tokens), "token")+")", yes); err != nil {
				return err
			}
			n, err := c.RevokeMCPClient(cmd.Context(), cl.ClientID)
			if err != nil {
				return err
			}
			app.Progress("Revoked %s of %s.", countLabel(n, "token"), cl.Name)
			return app.Printer().Print(map[string]any{"client_id": cl.ClientID, "name": cl.Name, "revoked": n}, func(*output.TableWriter) {})
		},
	}
	revoke.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(revoke)
	return cmd
}

func newMCPToolsCmd(app *App) *cobra.Command {
	var search, group string
	var allowed bool
	cmd := &cobra.Command{
		Use:   "tools",
		Short: "The tools of the RunR MCP server, the permissions they need and whether your token may use them",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			catalog, err := c.MCPTools(cmd.Context())
			if err != nil {
				return err
			}
			needle := strings.ToLower(search)
			list := []runr.MCPTool{}
			for _, t := range catalog.Tools {
				if group != "" && t.Group != group {
					continue
				}
				if allowed && !t.Allowed {
					continue
				}
				if needle != "" && !strings.Contains(strings.ToLower(t.Name+" "+t.Title+" "+t.Description), needle) {
					continue
				}
				list = append(list, t)
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("TOOL", "GROUP", "PERMISSIONS", "ALLOWED", "DESCRIPTION")
				n := 0
				for _, tool := range list {
					if tool.Allowed {
						n++
					}
					t.Row(tool.Name, tool.Group, joinOrDash(tool.Permissions), yesNo(tool.Allowed), output.Truncate(tool.Description, 60))
				}
				t.Note("%s, %d of them allowed for this token.", countLabel(len(list), "tool"), n)
			})
		},
	}
	cmd.Flags().StringVar(&search, "search", "", "only tools whose name or description contains this")
	cmd.Flags().StringVar(&group, "group", "", "only tools of this group")
	cmd.Flags().BoolVar(&allowed, "allowed", false, "only tools this token may use")
	return cmd
}

// ---------------------------------------------------------------------------------------
// runr mcp-server

func newMCPServerCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "mcp-server",
		Short: "Serve a project as an MCP server behind RunR's gateway (access, key, call log, inspector)",
		Long: "A project with the http trigger can be served as an MCP server at\n" +
			"<console>/mcp/servers/<handle>: the gateway authenticates callers (public, a RunR token or\n" +
			"OAuth), signs every request and records the calls. Without a subcommand, `runr mcp-server`\n" +
			"shows the project's server (-p or runr.yaml).",
		Args: cobra.NoArgs,
	}
	get := newMCPServerGetCmd(app)
	cmd.RunE = get.RunE
	copyFlags(cmd, get)
	cmd.AddCommand(get, newMCPServerLsCmd(app), newMCPServerTemplatesCmd(app), newMCPServerSetCmd(app),
		newMCPServerRmCmd(app), newMCPServerRotateCmd(app), newMCPServerCallsCmd(app), newMCPServerInspectCmd(app))
	return cmd
}

// mcpServerOf resolves the project and its hosted server (exit code 4 when it is none).
func mcpServerOf(ctx context.Context, app *App) (*runr.Client, *runr.Project, *runr.HostedMCPServer, error) {
	c, p, err := app.Project(ctx)
	if err != nil {
		return nil, nil, nil, err
	}
	s, err := c.HostedMCPServer(ctx, p.ID)
	if err != nil {
		return nil, nil, nil, err
	}
	if s == nil {
		return nil, nil, nil, &runr.NotFoundError{Kind: "MCP server of project", Ref: p.Name}
	}
	return c, p, s, nil
}

func mcpServerDetail(t *output.TableWriter, s *runr.HostedMCPServer) {
	t.KV("Project", output.Dash(s.ProjectName))
	t.KV("Handle", s.Handle)
	t.KV("URL", s.URL)
	t.KV("Access", s.AccessMode)
	t.KV("Path", s.Path)
	t.KV("Audit calls", map[bool]string{true: "yes", false: "no"}[s.AuditCalls])
	t.KV("Function URL", output.Dash(s.FunctionURL))
	if s.ResourceMetadata != "" {
		t.KV("Resource metadata", s.ResourceMetadata)
	}
	t.KV("Key rotated", gitAge(s.KeyRotatedAt))
	if s.NeedsRedeploy {
		t.Note("The running deployment has an older gateway key: deploy the project to give it the new one.")
	}
}

// mcpHostedSnippet is the client configuration of a hosted server.
func mcpHostedSnippet(client string, s *runr.HostedMCPServer) ([]runr.MCPSnippet, error) {
	return mcpSnippets(client, s.Handle, s.URL, s.AccessMode != runr.MCPAccessToken, "")
}

func newMCPServerGetCmd(app *App) *cobra.Command {
	var client string
	cmd := &cobra.Command{
		Use:   "get",
		Short: "Show the project's MCP server and how a client connects to it",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			_, _, s, err := mcpServerOf(cmd.Context(), app)
			if err != nil {
				return err
			}
			if client == "" {
				client = runr.MCPClientClaudeCode
			}
			snippets, err := mcpHostedSnippet(client, s)
			if err != nil {
				return err
			}
			return app.Printer().Print(s, func(t *output.TableWriter) {
				mcpServerDetail(t, s)
				noteSnippets(t, snippets)
			})
		},
	}
	cmd.Flags().StringVar(&client, "client", "", "client configuration to print: claude-code (default), claude-desktop, cursor or vscode")
	return cmd
}

func newMCPServerLsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the hosted MCP servers of the organization",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.HostedMCPServers(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.HostedMCPServer{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("HANDLE", "PROJECT", "ACCESS", "URL", "REDEPLOY")
				for _, s := range list {
					t.Row(s.Handle, output.Dash(s.ProjectName), s.AccessMode, s.URL, yesNo(s.NeedsRedeploy))
				}
			})
		},
	}
}

func newMCPServerTemplatesCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "templates",
		Short: "Starters of hosted MCP servers (Python, TypeScript, a container image)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.MCPServerTemplates(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.MCPServerTemplate{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("TEMPLATE", "NAME", "TYPE", "RUNTIME", "REGISTERED", "AVAILABLE", "PATH")
				for _, tp := range list {
					t.Row(tp.ID, tp.Name, tp.ServiceType, output.Dash(tp.Runtime), yesNo(tp.RuntimeRegistered),
						yesNo(tp.RuntimeAvailable), tp.Path)
				}
				t.Note("Start from a template with `runr init --runtime mcp-python` (or mcp-node) once its runtime is registered.")
			})
		},
	}
}

// mcpRedeploy deploys the live version again so the workload gets the gateway key; nil when
// the project has no live deployment.
func mcpRedeploy(ctx context.Context, app *App, c *runr.Client, p *runr.Project, timeout time.Duration) (*runr.Deployment, error) {
	return redeployLive(ctx, app, c, p, timeout, "the next `runr deploy` gives the server its gateway key")
}

// mcpServerResult prints a server after a change and an optional redeploy.
func mcpServerResult(ctx context.Context, app *App, c *runr.Client, p *runr.Project, s *runr.HostedMCPServer, noDeploy bool,
	timeout time.Duration, extra map[string]any) error {
	var dep *runr.Deployment
	if s.NeedsRedeploy && !noDeploy {
		var err error
		if dep, err = mcpRedeploy(ctx, app, c, p, timeout); err != nil {
			return err
		}
		if dep != nil {
			if fresh, err := c.HostedMCPServer(ctx, p.ID); err == nil && fresh != nil {
				s = fresh
			}
		}
	}
	out := map[string]any{"server": s, "redeployed": dep != nil}
	if dep != nil {
		out["deployment"] = dep
	}
	for k, v := range extra {
		out[k] = v
	}
	return app.Printer().Print(out, func(t *output.TableWriter) { mcpServerDetail(t, s) })
}

func newMCPServerSetCmd(app *App) *cobra.Command {
	var access, path string
	var audit, noDeploy bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "set",
		Short: "Serve the project as an MCP server, or change its access mode, path and call auditing",
		Long: "Access modes: public (anyone), token (a RunR API token of a user with mcp.servers.call on\n" +
			"the project; the default) and oauth (token, plus OAuth sign-in for clients that only have\n" +
			"the URL). The gateway forwards to the function's URL plus --path (default /mcp). A new\n" +
			"server's gateway key reaches the workload with a deploy: runr redeploys the live version\n" +
			"unless --no-deploy is given.",
		Example: "  runr mcp-server set -p weather\n" +
			"  runr mcp-server set --access-mode oauth --audit-calls",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			if fl.Changed("access-mode") && access != runr.MCPAccessPublic && access != runr.MCPAccessToken && access != runr.MCPAccessOAuth {
				return usageErrorf("--access-mode must be public, token or oauth")
			}
			if fl.Changed("path") && !strings.HasPrefix(path, "/") {
				return usageErrorf("--path must start with /")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			cur, err := c.HostedMCPServer(ctx, p.ID)
			if err != nil {
				return err
			}
			in := runr.MCPServerInput{}
			if fl.Changed("access-mode") {
				in.AccessMode = &access
			} else if cur == nil {
				mode := runr.MCPAccessToken
				in.AccessMode = &mode
			}
			if fl.Changed("path") {
				in.Path = &path
			}
			if fl.Changed("audit-calls") {
				in.AuditCalls = &audit
			}
			if cur != nil && in == (runr.MCPServerInput{}) {
				return usageErrorf("give --access-mode, --path or --audit-calls")
			}
			s, created, err := c.SetHostedMCPServer(ctx, p.ID, in)
			if err != nil {
				return err
			}
			if created {
				app.Progress("%s is an MCP server at %s.", p.Name, s.URL)
			} else {
				app.Progress("MCP server %s updated.", s.Handle)
			}
			return mcpServerResult(ctx, app, c, p, s, noDeploy, timeout, map[string]any{"created": created})
		},
	}
	f := cmd.Flags()
	f.StringVar(&access, "access-mode", "", "public, token or oauth (a new server: token)")
	f.StringVar(&path, "path", "", "path of the MCP endpoint in the function (default /mcp)")
	f.BoolVar(&audit, "audit-calls", false, "record every call in the audit log as well")
	f.BoolVar(&noDeploy, "no-deploy", false, "do not redeploy the live version for the gateway key")
	f.DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

func newMCPServerRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm",
		Aliases: []string{"remove", "delete"},
		Short:   "Stop serving the project as an MCP server (the project stays)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, s, err := mcpServerOf(cmd.Context(), app)
			if err != nil {
				return err
			}
			if err := app.confirm("Stop serving "+p.Name+" at "+s.URL, yes); err != nil {
				return err
			}
			if err := c.DeleteHostedMCPServer(cmd.Context(), p.ID); err != nil {
				return err
			}
			app.Progress("%s is no longer an MCP server; its call log is removed.", p.Name)
			return nil
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newMCPServerRotateCmd(app *App) *cobra.Command {
	var yes, noDeploy bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "rotate-key",
		Short: "Create a new gateway key and redeploy so the server gets it",
		Long: "The gateway signs every request to the server with its key. A new key reaches the workload\n" +
			"with a deploy. For seven days after the rotation the gateway signs with both keys, so a\n" +
			"deployment that still has the old key keeps working; deploy within that time. runr redeploys\n" +
			"the live version unless --no-deploy is given. The key itself is never shown: the server\n" +
			"reads it from RUNR_MCP_GATEWAY_KEY.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, s, err := mcpServerOf(ctx, app)
			if err != nil {
				return err
			}
			if err := app.confirm("Rotate the gateway key of "+s.Handle, yes); err != nil {
				return err
			}
			s, msg, err := c.RotateMCPServerKey(ctx, p.ID)
			if err != nil {
				return err
			}
			if msg != "" {
				app.Progress("%s", msg)
			}
			return mcpServerResult(ctx, app, c, p, s, noDeploy, timeout, map[string]any{"message": msg})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.Flags().BoolVar(&noDeploy, "no-deploy", false, "only rotate; the next deploy gives the server the key")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	return cmd
}

func newMCPServerCallsCmd(app *App) *cobra.Command {
	var outcome, method string
	var limit int
	cmd := &cobra.Command{
		Use:   "calls",
		Short: "The newest calls through the gateway: method, tool, caller, outcome, duration",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if outcome != "" {
				ok := false
				for _, o := range runr.MCPCallOutcomes {
					ok = ok || o == outcome
				}
				if !ok {
					return usageErrorf("--outcome must be %s", strings.Join(runr.MCPCallOutcomes, ", "))
				}
			}
			if limit < 1 || limit > 100 {
				return usageErrorf("--limit must be between 1 and 100")
			}
			c, p, _, err := mcpServerOf(cmd.Context(), app)
			if err != nil {
				return err
			}
			list, page, err := c.MCPServerCalls(cmd.Context(), p.ID, runr.MCPCallsFilter{Outcome: outcome, Method: method, PerPage: limit})
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.MCPServerCall{}
			}
			return app.Printer().Print(map[string]any{"calls": list, "meta": page}, func(t *output.TableWriter) {
				t.Header("TIME", "METHOD", "NAME", "CALLER", "AUTH", "STATUS", "OUTCOME", "DURATION")
				for _, cl := range list {
					status := strconv.Itoa(cl.StatusCode)
					if cl.Error != "" {
						status += " " + output.Truncate(cl.Error, 40)
					}
					t.Row(gitAge(cl.OccurredAt), cl.Method, output.Dash(cl.Name), output.Dash(cl.Actor.Name), cl.Auth, status,
						cl.Outcome, strconv.Itoa(cl.DurationMs)+"ms")
				}
				if page != nil && page.Total > len(list) {
					t.Note("%d of %d calls; --limit shows up to 100.", len(list), page.Total)
				}
			})
		},
	}
	cmd.Flags().StringVar(&outcome, "outcome", "", "only calls with this outcome: ok, error, denied or unreachable")
	cmd.Flags().StringVar(&method, "method", "", "only calls of this JSON-RPC method (tools/call, initialize, …)")
	cmd.Flags().IntVar(&limit, "limit", 50, "how many calls (1-100)")
	return cmd
}

// mcpExchange is one message of an inspector session and the server's answer.
type mcpExchange struct {
	Request map[string]any           `json:"request"`
	Answer  *runr.MCPInspectorResult `json:"answer"`
}

func newMCPServerInspectCmd(app *App) *cobra.Command {
	var method, params, tool, toolArgs, message, session, protocol string
	var noInit bool
	cmd := &cobra.Command{
		Use:   "inspect",
		Short: "Talk to the project's MCP server through the gateway: list tools, call one, send any message",
		Long: "Without flags, inspect initializes a session and lists the server's tools. --tool calls a\n" +
			"tool with --args (a JSON object, @file or -), --method sends another request with --params,\n" +
			"--message sends one JSON-RPC message exactly as given. Requests go through the gateway as\n" +
			"you (auth \"console\") and appear in the call log.",
		Example: "  runr mcp-server inspect -p weather\n" +
			"  runr mcp-server inspect --tool forecast --args '{\"city\":\"Ankara\"}'\n" +
			"  runr mcp-server inspect --method resources/list\n" +
			"  runr mcp-server inspect --message @ping.json --session 3f…",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			modes := 0
			for _, v := range []string{method, tool, message} {
				if v != "" {
					modes++
				}
			}
			if modes > 1 {
				return usageErrorf("give one of --method, --tool and --message")
			}
			if toolArgs != "" && tool == "" {
				return usageErrorf("--args goes with --tool")
			}
			if params != "" && method == "" {
				return usageErrorf("--params goes with --method")
			}
			ctx := cmd.Context()
			c, p, s, err := mcpServerOf(ctx, app)
			if err != nil {
				return err
			}
			insp := &mcpInspector{c: c, projectID: p.ID, session: session, protocol: protocol}

			if message != "" {
				raw, err := readJSONArg(app, "--message", message)
				if err != nil {
					return err
				}
				var msg map[string]any
				if err := json.Unmarshal(raw, &msg); err != nil {
					return usageErrorf("--message must be a JSON object: %v", err)
				}
				if _, err := insp.send(ctx, msg); err != nil {
					return err
				}
				return mcpPrintExchanges(app, s, insp, nil)
			}
			if !noInit {
				if err := insp.initialize(ctx); err != nil {
					return err
				}
			}
			var req map[string]any
			switch {
			case tool != "":
				arguments := map[string]any{}
				if toolArgs != "" {
					raw, err := readJSONArg(app, "--args", toolArgs)
					if err != nil {
						return err
					}
					if err := json.Unmarshal(raw, &arguments); err != nil {
						return usageErrorf("--args must be a JSON object: %v", err)
					}
				}
				req = map[string]any{"name": tool, "arguments": arguments}
				method = "tools/call"
			case method != "":
				if params != "" {
					raw, err := readJSONArg(app, "--params", params)
					if err != nil {
						return err
					}
					if err := json.Unmarshal(raw, &req); err != nil {
						return usageErrorf("--params must be a JSON object: %v", err)
					}
				}
			default:
				method = "tools/list"
			}
			result, err := insp.request(ctx, method, req)
			if err != nil {
				return err
			}
			if method == "tools/call" {
				if isErr, _ := result["isError"].(bool); isErr {
					_ = mcpPrintExchanges(app, s, insp, result)
					return &runr.FailedError{What: "tool", Ref: tool, Message: mcpToolText(result)}
				}
			}
			return mcpPrintExchanges(app, s, insp, result)
		},
	}
	f := cmd.Flags()
	f.StringVar(&tool, "tool", "", "call this tool (tools/call)")
	f.StringVar(&toolArgs, "args", "", "the tool's arguments: a JSON object, @file or -")
	f.StringVar(&method, "method", "", "send this JSON-RPC request (resources/list, prompts/list, …)")
	f.StringVar(&params, "params", "", "the request's params: a JSON object, @file or -")
	f.StringVar(&message, "message", "", "send this JSON-RPC message as it is (JSON, @file or -)")
	f.StringVar(&session, "session", "", "Mcp-Session-Id of an existing session")
	f.StringVar(&protocol, "protocol-version", "", "MCP-Protocol-Version to send (default: the one initialize agrees on)")
	f.BoolVar(&noInit, "no-init", false, "do not initialize a session first")
	return cmd
}

// mcpInspector keeps the session of an inspector conversation.
type mcpInspector struct {
	c         *runr.Client
	projectID string
	session   string
	protocol  string
	nextID    int
	exchanges []mcpExchange
	server    map[string]any
}

func (m *mcpInspector) send(ctx context.Context, msg map[string]any) (*runr.MCPInspectorResult, error) {
	res, err := m.c.InspectMCPServer(ctx, m.projectID, runr.MCPInspectorInput{Message: msg, SessionID: m.session, ProtocolVersion: m.protocol})
	if err != nil {
		return nil, err
	}
	m.exchanges = append(m.exchanges, mcpExchange{Request: msg, Answer: res})
	if res.SessionID != "" {
		m.session = res.SessionID
	}
	if res.Status >= 400 {
		return res, &runr.FailedError{What: "MCP server request", Ref: fmt.Sprint(msg["method"]), Status: strconv.Itoa(res.Status),
			Message: fmt.Sprintf("the server answered %d: %s", res.Status, output.Truncate(res.Raw, 200))}
	}
	return res, nil
}

func (m *mcpInspector) request(ctx context.Context, method string, params map[string]any) (map[string]any, error) {
	m.nextID++
	msg := map[string]any{"jsonrpc": "2.0", "id": m.nextID, "method": method}
	if params != nil {
		msg["params"] = params
	}
	res, err := m.send(ctx, msg)
	if err != nil {
		return nil, err
	}
	return res.Result(m.nextID)
}

func (m *mcpInspector) initialize(ctx context.Context) error {
	result, err := m.request(ctx, "initialize", map[string]any{
		"protocolVersion": mcpProtocolVersion,
		"capabilities":    map[string]any{},
		"clientInfo":      map[string]any{"name": "runr-cli", "version": version.Version},
	})
	if err != nil {
		return err
	}
	m.server = result
	if v, ok := result["protocolVersion"].(string); ok && m.protocol == "" {
		m.protocol = v
	}
	_, err = m.send(ctx, map[string]any{"jsonrpc": "2.0", "method": "notifications/initialized"})
	return err
}

// mcpToolText joins the text content of a tool result.
func mcpToolText(result map[string]any) string {
	var parts []string
	items, _ := result["content"].([]any)
	for _, it := range items {
		m, _ := it.(map[string]any)
		switch m["type"] {
		case "text":
			parts = append(parts, fmt.Sprint(m["text"]))
		default:
			parts = append(parts, fmt.Sprintf("[%v content]", m["type"]))
		}
	}
	return strings.Join(parts, "\n")
}

func mcpPrintExchanges(app *App, s *runr.HostedMCPServer, m *mcpInspector, result map[string]any) error {
	out := map[string]any{"server": s.Handle, "session_id": m.session, "protocol_version": m.protocol,
		"exchanges": m.exchanges, "result": result}
	return app.Printer().Print(out, func(t *output.TableWriter) {
		if m.server != nil {
			info, _ := m.server["serverInfo"].(map[string]any)
			t.KV("Server", strings.TrimSpace(fmt.Sprint(info["name"], " ", info["version"])))
			t.KV("Protocol", output.Dash(m.protocol))
		}
		if m.session != "" {
			t.KV("Session", m.session)
		}
		if result == nil {
			// --message: the answer's messages as they came.
			last := m.exchanges[len(m.exchanges)-1].Answer
			t.KV("Status", strconv.Itoa(last.Status))
			b, _ := json.MarshalIndent(last.Messages, "", "  ")
			t.Note("%s", b)
			return
		}
		if tools, ok := result["tools"].([]any); ok {
			t.Header("TOOL", "DESCRIPTION")
			for _, it := range tools {
				tm, _ := it.(map[string]any)
				t.Row(fmt.Sprint(tm["name"]), output.Truncate(fmt.Sprint(tm["description"]), 80))
			}
			return
		}
		if _, ok := result["content"]; ok {
			t.Note("%s", mcpToolText(result))
			return
		}
		b, _ := json.MarshalIndent(result, "", "  ")
		t.Note("%s", b)
	})
}
