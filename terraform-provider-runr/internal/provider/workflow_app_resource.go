package provider

import (
	"context"
	"regexp"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewWorkflowAppResource) }

var (
	_ resource.Resource                = &workflowAppResource{}
	_ resource.ResourceWithConfigure   = &workflowAppResource{}
	_ resource.ResourceWithImportState = &workflowAppResource{}
)

// NewWorkflowAppResource returns the runr_workflow_app resource.
func NewWorkflowAppResource() resource.Resource { return &workflowAppResource{} }

type workflowAppResource struct{ client *Client }

type workflowAppModel struct {
	ID             types.String `tfsdk:"id"`
	ProjectID      types.String `tfsdk:"project_id"`
	Environment    types.String `tfsdk:"environment"`
	EndpointURL    types.String `tfsdk:"endpoint_url"`
	URL            types.String `tfsdk:"url"`
	KeyFingerprint types.String `tfsdk:"key_fingerprint"`
}

func (r *workflowAppResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_workflow_app"
}

func (r *workflowAppResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "The workflow app of a project (or of one of its environments): where the workflow engine calls the " +
			"workflows' code ([workflows](https://runr.sh/docs/workflows/)). By default that is the project's function; " +
			"`endpoint_url` points the engine at a function served outside RunR instead, which verifies the engine's calls " +
			"with the app's signing key (`RUNR_WORKFLOW_SIGNING_KEY`, from `runr workflows app signing-key`). Rotating the " +
			"key is an action (`runr workflows app rotate-key`), not configuration.\n\n" +
			"Destroying the resource goes back to the project's function. Needs `workflows.manage`. Import with " +
			"`<project name or id>` or `<project name or id>/<environment>`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "`<project_id>` or `<project_id>/<environment>`.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace, MarkdownDescription: "Project id."},
			"environment": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				Validators:          []validator.String{stringvalidator.LengthAtLeast(1)},
				MarkdownDescription: "The project environment (name or id). Left out: the project's own workload (production)."},
			"endpoint_url": schema.StringAttribute{Optional: true,
				Validators: []validator.String{stringvalidator.LengthAtMost(2048),
					stringvalidator.RegexMatches(regexp.MustCompile(`^https?://\S+$`), "must be an http or https URL")},
				MarkdownDescription: "URL the engine calls instead of the project's function (a public http or https address). " +
					"Left out: the project's function; an override set outside Terraform is then removed."},
			"url": schema.StringAttribute{Computed: true,
				MarkdownDescription: "The URL the engine calls: `endpoint_url`, else the function's URL."},
			"key_fingerprint": schema.StringAttribute{Computed: true,
				MarkdownDescription: "Fingerprint of the current signing key (changes when the key is rotated)."},
		},
	}
}

func (r *workflowAppResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (m *workflowAppModel) context(ctx context.Context) context.Context {
	return runr.WithWorkflowEnvironment(ctx, m.Environment.ValueString())
}

func workflowAppToState(m *workflowAppModel, app *runr.WorkflowApp) {
	m.ID = types.StringValue(m.ProjectID.ValueString())
	if known(m.Environment) {
		m.ID = types.StringValue(m.ProjectID.ValueString() + "/" + m.Environment.ValueString())
	}
	m.EndpointURL = spelling(m.EndpointURL, app.EndpointOverride)
	m.URL = strOrNull(app.EndpointURL)
	m.KeyFingerprint = strOrNull(app.KeyFingerprint)
}

func (r *workflowAppResource) set(ctx context.Context, m *workflowAppModel, diags *diag.Diagnostics) {
	app, err := r.client.API.SetWorkflowEndpoint(m.context(ctx), m.ProjectID.ValueString(), m.EndpointURL.ValueString())
	if err != nil {
		apiError(diags, "Set workflow endpoint failed", err)
		return
	}
	workflowAppToState(m, app)
}

func (r *workflowAppResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan workflowAppModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.set(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *workflowAppResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state workflowAppModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	app, err := r.client.API.WorkflowApp(state.context(ctx), state.ProjectID.ValueString())
	if runr.IsNotFound(err) || workflowEnvironmentGone(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read workflow app failed", err)
		return
	}
	workflowAppToState(&state, app)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *workflowAppResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan workflowAppModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.set(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *workflowAppResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state workflowAppModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	_, err := r.client.API.SetWorkflowEndpoint(state.context(ctx), state.ProjectID.ValueString(), "")
	if err != nil && !runr.IsNotFound(err) && !workflowEnvironmentGone(err) {
		apiError(&resp.Diagnostics, "Reset workflow endpoint failed", err)
	}
}

// ImportState accepts "<project id or name>" and "<project id or name>/<environment>".
func (r *workflowAppResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	ref, env, _ := strings.Cut(req.ID, "/")
	if ref == "" || strings.Contains(env, "/") {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name> or <project id or name>/<environment>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, ref)
	if err != nil {
		apiError(&resp.Diagnostics, "Import workflow app failed", err)
		return
	}
	m := workflowAppModel{ProjectID: types.StringValue(p.ID), Environment: strOrNull(env), EndpointURL: types.StringNull()}
	app, err := r.client.API.WorkflowApp(m.context(ctx), p.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import workflow app failed", err)
		return
	}
	workflowAppToState(&m, app)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
