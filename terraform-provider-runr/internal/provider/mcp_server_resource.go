package provider

import (
	"context"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/runrsh/core/cli/pkg/runr"
)

func init() { registerResource(NewMCPServerResource) }

var (
	_ resource.Resource                = &mcpServerResource{}
	_ resource.ResourceWithConfigure   = &mcpServerResource{}
	_ resource.ResourceWithImportState = &mcpServerResource{}
)

// NewMCPServerResource returns the runr_mcp_server resource.
func NewMCPServerResource() resource.Resource { return &mcpServerResource{} }

type mcpServerResource struct{ client *Client }

type mcpServerModel struct {
	ID               types.String `tfsdk:"id"`
	ProjectID        types.String `tfsdk:"project_id"`
	AccessMode       types.String `tfsdk:"access_mode"`
	Path             types.String `tfsdk:"path"`
	AuditCalls       types.Bool   `tfsdk:"audit_calls"`
	Handle           types.String `tfsdk:"handle"`
	URL              types.String `tfsdk:"url"`
	ResourceMetadata types.String `tfsdk:"resource_metadata"`
}

func (r *mcpServerResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_mcp_server"
}

func (r *mcpServerResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "Serves a project as a hosted MCP server at `<console>/mcp/servers/<handle>` behind RunR's " +
			"gateway, which authenticates callers, forwards their identity with a signature and records every call " +
			"([hosted MCP servers](https://github.com/runrsh/core/blob/main/docs/mcp.md#hosting-mcp-servers)). The " +
			"project needs the http trigger.\n\n" +
			"The gateway key reaches the workload with a deploy: put `id` into `runr_deployment.triggers` so the " +
			"project is deployed once the server exists. Needs `mcp.servers.manage` on the project.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Server id.", PlanModifiers: keep},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"access_mode": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(runr.MCPAccessToken),
				MarkdownDescription: "Who gets in: `public` (anyone), `token` (a RunR API token of a user with " +
					"`mcp.servers.call` on the project; default) or `oauth` (as `token`, plus OAuth sign-in for clients " +
					"that only have the URL).",
				Validators: []validator.String{stringvalidator.OneOf(runr.MCPAccessPublic, runr.MCPAccessToken, runr.MCPAccessOAuth)}},
			"path": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("/mcp"),
				MarkdownDescription: "Path of the MCP endpoint in the function (default `/mcp`).",
				Validators: []validator.String{stringvalidator.LengthAtMost(200),
					stringvalidator.RegexMatches(regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/-]*$`), "must be a URL path starting with /")}},
			"audit_calls": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false),
				MarkdownDescription: "Also record every call in the audit log (`mcp.server.call`)."},
			"handle": schema.StringAttribute{Computed: true, MarkdownDescription: "Handle in the URL (the project's slug).",
				PlanModifiers: keep},
			"url": schema.StringAttribute{Computed: true, MarkdownDescription: "URL MCP clients connect to.", PlanModifiers: keep},
			"resource_metadata": schema.StringAttribute{Computed: true,
				MarkdownDescription: "OAuth protected resource metadata URL (empty for `public`)."},
		},
	}
}

func (r *mcpServerResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func mcpServerToState(m *mcpServerModel, s *runr.HostedMCPServer) {
	m.ID = types.StringValue(s.ID)
	m.ProjectID = types.StringValue(s.ProjectID)
	m.AccessMode = types.StringValue(s.AccessMode)
	m.Path = types.StringValue(s.Path)
	m.AuditCalls = types.BoolValue(s.AuditCalls)
	m.Handle = types.StringValue(s.Handle)
	m.URL = types.StringValue(s.URL)
	m.ResourceMetadata = types.StringValue(s.ResourceMetadata)
}

func (r *mcpServerResource) put(ctx context.Context, m *mcpServerModel, diags *diag.Diagnostics) {
	mode, p, audit := m.AccessMode.ValueString(), m.Path.ValueString(), m.AuditCalls.ValueBool()
	s, _, err := r.client.API.SetHostedMCPServer(ctx, m.ProjectID.ValueString(),
		runr.MCPServerInput{AccessMode: &mode, Path: &p, AuditCalls: &audit})
	if err != nil {
		apiError(diags, "Set MCP server failed", err)
		return
	}
	mcpServerToState(m, s)
}

func (r *mcpServerResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan mcpServerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	cur, err := r.client.API.HostedMCPServer(ctx, plan.ProjectID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read MCP server failed", err)
		return
	}
	if cur != nil {
		resp.Diagnostics.AddError("Project already served as an MCP server",
			"The project is an MCP server at "+cur.URL+"; import it with `terraform import <address> "+plan.ProjectID.ValueString()+"`.")
		return
	}
	r.put(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *mcpServerResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state mcpServerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	s, err := r.client.API.HostedMCPServer(ctx, state.ProjectID.ValueString())
	if s == nil && (err == nil || runr.IsNotFound(err)) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read MCP server failed", err)
		return
	}
	mcpServerToState(&state, s)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *mcpServerResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan mcpServerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.put(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *mcpServerResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state mcpServerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteHostedMCPServer(ctx, state.ProjectID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Remove MCP server failed", err)
	}
}

// ImportState accepts the project's name or id.
func (r *mcpServerResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	p, err := r.client.API.FindProject(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import MCP server failed", err)
		return
	}
	s, err := r.client.API.HostedMCPServer(ctx, p.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import MCP server failed", err)
		return
	}
	if s == nil {
		resp.Diagnostics.AddError("Import MCP server failed", "Project "+p.Name+" is not served as an MCP server.")
		return
	}
	var m mcpServerModel
	mcpServerToState(&m, s)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
