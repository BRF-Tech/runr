package provider

import (
	"context"
	"strconv"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/resourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewEnvironmentSecretResource) }

var (
	_ resource.Resource                     = &environmentSecretResource{}
	_ resource.ResourceWithConfigure        = &environmentSecretResource{}
	_ resource.ResourceWithImportState      = &environmentSecretResource{}
	_ resource.ResourceWithConfigValidators = &environmentSecretResource{}
	_ resource.ResourceWithValidateConfig   = &environmentSecretResource{}
	_ resource.ResourceWithModifyPlan       = &environmentSecretResource{}
)

// NewEnvironmentSecretResource returns the runr_environment_secret resource.
func NewEnvironmentSecretResource() resource.Resource { return &environmentSecretResource{} }

// A secret variable of one environment (docs/environments.md#secrets): write-only and
// pass-through like the project's, its value goes straight into the environment's region.
type environmentSecretResource struct{ client *Client }

type environmentSecretModel struct {
	ID             types.String `tfsdk:"id"`
	ProjectID      types.String `tfsdk:"project_id"`
	Environment    types.String `tfsdk:"environment"`
	Name           types.String `tfsdk:"name"`
	Value          types.String `tfsdk:"value"`
	ValueWO        types.String `tfsdk:"value_wo"`
	ValueWOVersion types.String `tfsdk:"value_wo_version"`
	Version        types.Int64  `tfsdk:"version"`
}

// writtenKey is the private state key of the version Terraform wrote: a newer version on the
// server means the value was changed outside Terraform.
const writtenKey = "written_version"

func (r *environmentSecretResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_environment_secret"
}

func (r *environmentSecretResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A secret variable of one environment, over the project's of the same name " +
			"([environment secrets](https://runr.sh/docs/environments/#secrets)). The value is write-only and pass-through: " +
			"RunR writes it straight into the environment's Secret `<workload>-secret-env` in the region it runs in and keeps only " +
			"its name, a version and a fingerprint; it reaches the workload with the environment's next deploy. Terraform keeps " +
			"the value it set (`value`, sensitive); with `value_wo` (Terraform 1.11 and later) the state holds no value either — " +
			"change `value_wo_version` to write a new one. A value changed (a new version) or removed outside Terraform is " +
			"written again.\n\n" +
			"production's secret variables are the project's: use `runr_env_var` with `secret = true`. Needs `env.edit` on the " +
			"project. Import with `<project name or id>/<environment>/<NAME>` (the value is not imported).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "`<project_id>/<environment>/<name>`.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Project id. Changing it creates a new variable."},
			"environment": schema.StringAttribute{Required: true, PlanModifiers: replace,
				Validators:          []validator.String{notProductionValidator{hint: "production's secret variables are the project's: use runr_env_var with secret = true."}},
				MarkdownDescription: "Environment name (`runr_environment.<name>.name`) or id. Changing it creates a new variable."},
			"name": schema.StringAttribute{Required: true, PlanModifiers: replace,
				Validators:          []validator.String{envNameValidator{}},
				MarkdownDescription: "Variable name. Changing it creates a new variable."},
			"value": schema.StringAttribute{Optional: true, Sensitive: true,
				MarkdownDescription: "Value (sensitive). One of `value` and `value_wo` is required."},
			"value_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true,
				MarkdownDescription: "Value that Terraform never stores (write-only attribute, Terraform 1.11 and later). Terraform " +
					"cannot compare it, so change `value_wo_version` to write a new value."},
			"value_wo_version": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Any string; a change writes `value_wo` again (for example a rotation date)."},
			"version": schema.Int64Attribute{Computed: true,
				MarkdownDescription: "The value's version in RunR (each write adds one)."},
		},
	}
}

func (r *environmentSecretResource) ConfigValidators(context.Context) []resource.ConfigValidator {
	return []resource.ConfigValidator{
		resourcevalidator.ExactlyOneOf(path.MatchRoot("value"), path.MatchRoot("value_wo")),
	}
}

func (r *environmentSecretResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m environmentSecretModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if !m.ValueWOVersion.IsNull() && m.ValueWO.IsNull() {
		resp.Diagnostics.AddAttributeWarning(path.Root("value_wo_version"), "value_wo_version without value_wo",
			"value_wo_version only takes effect together with value_wo.")
	}
}

// notProductionValidator refuses the production environment where it has a resource of its own.
type notProductionValidator struct{ hint string }

func (notProductionValidator) Description(context.Context) string {
	return "an environment other than production"
}
func (v notProductionValidator) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}
func (v notProductionValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if known(req.ConfigValue) && req.ConfigValue.ValueString() == runr.PrimaryEnvironment {
		resp.Diagnostics.AddAttributeError(req.Path, "Not for production", v.hint)
	}
}

func (r *environmentSecretResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// write sets the value and returns the version RunR gave it.
func (r *environmentSecretResource) write(ctx context.Context, m *environmentSecretModel, value string) (int, error) {
	s, err := r.client.API.SetEnvironmentSecret(ctx, m.ProjectID.ValueString(), m.Environment.ValueString(), m.Name.ValueString(), value)
	if err != nil {
		return 0, err
	}
	m.ID = types.StringValue(m.ProjectID.ValueString() + "/" + m.Environment.ValueString() + "/" + m.Name.ValueString())
	m.Version = types.Int64Value(int64(s.Version))
	m.ValueWO = types.StringNull()
	return s.Version, nil
}

// secretValue is value of the plan, or value_wo of the configuration (never in plan or state).
func secretValue(plan, cfg environmentSecretModel) string {
	if known(plan.Value) {
		return plan.Value.ValueString()
	}
	return cfg.ValueWO.ValueString()
}

func (r *environmentSecretResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg environmentSecretModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	v, err := r.write(ctx, &plan, secretValue(plan, cfg))
	if err != nil {
		environmentError(&resp.Diagnostics, "Set environment secret failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	resp.Diagnostics.Append(resp.Private.SetKey(ctx, writtenKey, []byte(strconv.Itoa(v)))...)
}

func (r *environmentSecretResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state environmentSecretModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := state.ProjectID.ValueString()
	list, err := r.client.API.ListEnvironmentSecrets(ctx, pid, state.Environment.ValueString())
	if err != nil && environmentGone(ctx, r.client, pid, err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		environmentError(&resp.Diagnostics, "Read environment secrets failed", err)
		return
	}
	for i := range list.Secrets {
		s := &list.Secrets[i]
		if s.Name == state.Name.ValueString() && s.Own() {
			// Write-only: RunR names it but never returns the value; keep what Terraform set.
			state.Version = types.Int64Value(int64(s.Version))
			state.ValueWO = types.StringNull()
			resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
			return
		}
	}
	// Removed outside Terraform (an inherited variable of the same name is the project's).
	resp.State.RemoveResource(ctx)
}

// ModifyPlan plans the value again when its version on the server is not the one Terraform wrote
// (it was changed outside Terraform).
func (r *environmentSecretResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.State.Raw.IsNull() || req.Plan.Raw.IsNull() {
		return
	}
	written, diags := req.Private.GetKey(ctx, writtenKey)
	resp.Diagnostics.Append(diags...)
	if len(written) == 0 {
		return
	}
	var state environmentSecretModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() || !known(state.Version) {
		return
	}
	if n, err := strconv.Atoi(string(written)); err == nil && int64(n) != state.Version.ValueInt64() {
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("version"), types.Int64Unknown())...)
	}
}

func (r *environmentSecretResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg environmentSecretModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	v, err := r.write(ctx, &plan, secretValue(plan, cfg))
	if err != nil {
		environmentError(&resp.Diagnostics, "Set environment secret failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	resp.Diagnostics.Append(resp.Private.SetKey(ctx, writtenKey, []byte(strconv.Itoa(v)))...)
}

func (r *environmentSecretResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state environmentSecretModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := state.ProjectID.ValueString()
	err := r.client.API.DeleteEnvironmentSecret(ctx, pid, state.Environment.ValueString(), state.Name.ValueString())
	if err != nil && !environmentGone(ctx, r.client, pid, err) {
		environmentError(&resp.Diagnostics, "Remove environment secret failed", err)
	}
}

// ImportState accepts "<project id or name>/<environment>/<NAME>". The value is not imported
// (RunR never returns it): the next apply writes the configured value.
func (r *environmentSecretResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	parts := strings.Split(req.ID, "/")
	if len(parts) != 3 || parts[0] == "" || parts[1] == "" || parts[2] == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<environment>/<NAME>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, parts[0])
	if err != nil {
		apiError(&resp.Diagnostics, "Import environment secret failed", err)
		return
	}
	resp.Diagnostics.AddWarning("Secret value not imported",
		parts[2]+" is a secret variable: RunR never returns its value, so the state holds none. "+
			"The next apply writes the value of the configuration again.")
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), p.ID+"/"+parts[1]+"/"+parts[2])...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("environment"), parts[1])...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("name"), parts[2])...)
}
