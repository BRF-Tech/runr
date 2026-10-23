package provider

import (
	"context"
	"regexp"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/resourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                     = &envVarResource{}
	_ resource.ResourceWithConfigure        = &envVarResource{}
	_ resource.ResourceWithImportState      = &envVarResource{}
	_ resource.ResourceWithConfigValidators = &envVarResource{}
	_ resource.ResourceWithValidateConfig   = &envVarResource{}
)

// NewEnvVarResource returns the runr_env_var resource.
func NewEnvVarResource() resource.Resource { return &envVarResource{} }

type envVarResource struct{ client *Client }

type envVarModel struct {
	ID             types.String `tfsdk:"id"`
	ProjectID      types.String `tfsdk:"project_id"`
	Name           types.String `tfsdk:"name"`
	Value          types.String `tfsdk:"value"`
	ValueWO        types.String `tfsdk:"value_wo"`
	ValueWOVersion types.String `tfsdk:"value_wo_version"`
	Secret         types.Bool   `tfsdk:"secret"`
	Reference      types.Object `tfsdk:"reference"`
}

// envVarReferenceModel is a secret variable's reference to a value in a secret store.
type envVarReferenceModel struct {
	Store types.String `tfsdk:"store"`
	Path  types.String `tfsdk:"path"`
	Key   types.String `tfsdk:"key"`
}

var envVarReferenceAttrTypes = map[string]attr.Type{"store": types.StringType, "path": types.StringType, "key": types.StringType}

var noWhitespace = regexp.MustCompile(`^\S+$`)

// secretDefault plans secret when the configuration leaves it out: true for a reference (a
// reference is a secret variable), else false.
type secretDefault struct{}

func (secretDefault) Description(context.Context) string {
	return "false, or true for a reference to a secret store"
}
func (m secretDefault) MarkdownDescription(ctx context.Context) string { return m.Description(ctx) }
func (secretDefault) PlanModifyBool(ctx context.Context, req planmodifier.BoolRequest, resp *planmodifier.BoolResponse) {
	if !req.ConfigValue.IsNull() {
		return
	}
	var ref types.Object
	resp.Diagnostics.Append(req.Config.GetAttribute(ctx, path.Root("reference"), &ref)...)
	if ref.IsUnknown() {
		resp.PlanValue = types.BoolUnknown()
		return
	}
	resp.PlanValue = types.BoolValue(!ref.IsNull())
}

type envNameValidator struct{}

func (envNameValidator) Description(context.Context) string {
	return "a shell identifier that RunR does not set itself"
}
func (v envNameValidator) MarkdownDescription(ctx context.Context) string { return v.Description(ctx) }
func (envNameValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	if err := runr.ValidateEnvName(req.ConfigValue.ValueString()); err != nil {
		resp.Diagnostics.AddAttributeError(req.Path, "Invalid variable name", err.Error())
	}
}

func (r *envVarResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_env_var"
}

func (r *envVarResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		MarkdownDescription: "One environment variable of a project. Either kind reaches the running deployment with the next " +
			"deploy: redeploy (`runr_deployment.triggers`) to apply a change.\n\n" +
			"- **Plain** variables (`secret = false`) are part of the project config; each change saves a new project version.\n" +
			"- **Secret** variables (`secret = true`) are stored write-only through the project's secrets API: RunR lists their " +
			"names, never their values, and they never appear in a version. Terraform keeps the value it set (`value`, sensitive) " +
			"and notices when the variable is removed outside Terraform. With `value_wo` (Terraform 1.11 and later) the value is " +
			"not stored in the state either; change `value_wo_version` to write a new value.\n" +
			"- **References** (`reference`) are secret variables whose value lives in a secret store of the organization " +
			"(`runr_secret_store`): RunR never sees it, External Secrets Operator reads it in the project's region. A reference " +
			"changed into a value outside Terraform (or the other way round) shows as a change.\n\n" +
			"A name is plain or secret, never both; switching `secret` recreates the variable. Switching a secret variable " +
			"between a value and a reference changes it in place. Import with `<project name or id>/<NAME>`: a secret " +
			"variable without its value, a reference with it.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "`<project_id>/<name>`.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"name": schema.StringAttribute{Required: true, MarkdownDescription: "Variable name.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				Validators:    []validator.String{envNameValidator{}}},
			"value": schema.StringAttribute{Optional: true, Sensitive: true,
				MarkdownDescription: "Value (sensitive). Exactly one of `value`, `value_wo` and `reference` is required."},
			"value_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true,
				MarkdownDescription: "Value of a secret variable that Terraform never stores (write-only attribute, " +
					"Terraform 1.11 and later; needs `secret = true`). Terraform cannot compare it, so change " +
					"`value_wo_version` to write a new value."},
			"value_wo_version": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Any string; a change writes `value_wo` again (for example a rotation date)."},
			"secret": schema.BoolAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Store the value as a write-only secret variable (default `false`; a `reference` is always " +
					"a secret variable, so it defaults to `true` with one and cannot be `false`). Changing it recreates the variable.",
				PlanModifiers: []planmodifier.Bool{secretDefault{}, boolplanmodifier.RequiresReplace()}},
			"reference": schema.SingleNestedAttribute{Optional: true,
				MarkdownDescription: "The value lives in a secret store of the organization: a secret variable that refers to " +
					"it instead of a `value` ([secret stores](https://runr.sh/docs/secret-stores/)). The variable reaches the " +
					"workload with the next deploy, as External Secrets Operator syncs it in the project's region.",
				Attributes: map[string]schema.Attribute{
					"store": schema.StringAttribute{Required: true,
						MarkdownDescription: "The store: its id or name. Use `runr_secret_store.<name>.id`, so Terraform creates the " +
							"store before the variable and deletes it after (a store a variable refers to cannot be deleted)."},
					"path": schema.StringAttribute{Required: true,
						Validators: []validator.String{stringvalidator.LengthAtMost(512), stringvalidator.RegexMatches(noWhitespace, "must not contain whitespace")},
						MarkdownDescription: "Path of the secret in the store: a Vault / OpenBao KV path (`apps/orders`), an AWS secret " +
							"name or ARN, a GCP secret name, an Azure Key Vault secret name."},
					"key": schema.StringAttribute{Optional: true,
						Validators:          []validator.String{stringvalidator.LengthAtMost(255), stringvalidator.RegexMatches(noWhitespace, "must not contain whitespace")},
						MarkdownDescription: "Key inside the secret (a field of a JSON or KV secret); left out: the whole secret."},
				}},
		},
	}
}

func (r *envVarResource) ConfigValidators(context.Context) []resource.ConfigValidator {
	return []resource.ConfigValidator{
		resourcevalidator.ExactlyOneOf(path.MatchRoot("value"), path.MatchRoot("value_wo"), path.MatchRoot("reference")),
	}
}

func (r *envVarResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m envVarModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	// secret unset in the configuration is its default, false.
	if !m.ValueWO.IsNull() && !m.Secret.IsUnknown() && !m.Secret.ValueBool() {
		resp.Diagnostics.AddAttributeError(path.Root("value_wo"), "value_wo needs secret = true",
			"A write-only value is only meaningful for a secret variable; plain variables are readable through the API.")
	}
	if !m.ValueWOVersion.IsNull() && m.ValueWO.IsNull() {
		resp.Diagnostics.AddAttributeWarning(path.Root("value_wo_version"), "value_wo_version without value_wo",
			"value_wo_version only takes effect together with value_wo.")
	}
	if !m.Reference.IsNull() && known(m.Secret) && !m.Secret.ValueBool() {
		resp.Diagnostics.AddAttributeError(path.Root("secret"), "A reference is a secret variable",
			"A reference to a secret store is always a secret variable: leave secret out or set it to true.")
	}
}

// reference returns the reference of m (nil without one).
func (m *envVarModel) reference(ctx context.Context, diags *diag.Diagnostics) *envVarReferenceModel {
	if !known(m.Reference) {
		return nil
	}
	var ref envVarReferenceModel
	diags.Append(m.Reference.As(ctx, &ref, basetypes.ObjectAsOptions{})...)
	return &ref
}

// referenceObject is the API's reference in the state's terms: the store keeps the spelling of
// prior (its id or its name) while it names the same store.
func referenceObject(prior *envVarReferenceModel, ref *runr.SecretReference) types.Object {
	store := ref.StoreID
	if prior != nil && known(prior.Store) {
		if p := prior.Store.ValueString(); p == ref.StoreID || (ref.StoreName != "" && strings.EqualFold(p, ref.StoreName)) {
			store = p
		}
	}
	return types.ObjectValueMust(envVarReferenceAttrTypes, map[string]attr.Value{
		"store": types.StringValue(store), "path": types.StringValue(ref.Path), "key": strOrNull(ref.Key),
	})
}

// setReference makes the variable a reference to a secret store; a store given by name is
// looked up (secret_stores.view), an id is sent as it is.
func (r *envVarResource) setReference(ctx context.Context, m *envVarModel, ref *envVarReferenceModel, diags *diag.Diagnostics) {
	storeID := ref.Store.ValueString()
	if !runr.LooksLikeULID(storeID) {
		s, err := r.client.API.FindSecretStore(ctx, storeID)
		if err != nil {
			apiError(diags, "Set secret reference failed", err)
			return
		}
		storeID = s.ID
	}
	projectID := m.ProjectID.ValueString()
	unlock := r.client.lock(projectID)
	defer unlock()
	_, err := r.client.API.SetSecretReference(ctx, projectID, m.Name.ValueString(),
		runr.SecretReferenceInput{StoreID: storeID, Path: ref.Path.ValueString(), Key: ref.Key.ValueString()})
	if err != nil {
		apiError(diags, "Set secret reference failed", err)
		return
	}
	m.ID = types.StringValue(projectID + "/" + m.Name.ValueString())
}

// write sets the variable of the plan: its reference, else its value (the plan's value or, for
// value_wo, the config's).
func (r *envVarResource) write(ctx context.Context, plan, cfg *envVarModel, diags *diag.Diagnostics) {
	if ref := plan.reference(ctx, diags); ref != nil {
		r.setReference(ctx, plan, ref, diags)
		return
	}
	r.set(ctx, plan, writeValue(*plan, *cfg), diags)
}

func (r *envVarResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *envVarResource) apply(ctx context.Context, projectID string, change runr.EnvChange) (*runr.EnvResult, error) {
	unlock := r.client.lock(projectID)
	defer unlock()
	p, err := r.client.API.GetProject(ctx, projectID)
	if err != nil {
		return nil, err
	}
	return r.client.API.ApplyEnv(ctx, p, change, "terraform: env")
}

type diagSink interface {
	AddError(string, string)
	AddWarning(string, string)
}

// set writes the variable; value is the plan's value or, for value_wo, the config's.
func (r *envVarResource) set(ctx context.Context, m *envVarModel, value string, diags diagSink) {
	res, err := r.apply(ctx, m.ProjectID.ValueString(), runr.EnvChange{
		Set:    map[string]string{m.Name.ValueString(): value},
		Secret: m.Secret.ValueBool(),
	})
	if err != nil {
		diags.AddError("Set environment variable failed", err.Error())
		return
	}
	if res.SecretStoredPlain {
		diags.AddWarning("Secret stored as a regular variable",
			"This RunR API keeps all variables in the project config, so "+m.Name.ValueString()+" stays readable through the API.")
	}
	m.ID = types.StringValue(m.ProjectID.ValueString() + "/" + m.Name.ValueString())
}

func (r *envVarResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg envVarModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.write(ctx, &plan, &cfg, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.ValueWO = types.StringNull()
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// writeValue is value of the plan, or value_wo of the config (never in plan or state).
func writeValue(plan, cfg envVarModel) string {
	if !plan.Value.IsNull() && !plan.Value.IsUnknown() {
		return plan.Value.ValueString()
	}
	return cfg.ValueWO.ValueString()
}

func (r *envVarResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state envVarModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	p, err := r.client.API.GetProject(ctx, state.ProjectID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read project failed", err)
		return
	}
	name := state.Name.ValueString()
	if p.Config.EnvVarsHidden {
		resp.Diagnostics.AddWarning("Environment variables hidden",
			"The token cannot read the variables of "+p.Name+" (permission env.view); "+name+" is kept as configured.")
		return
	}
	for _, k := range p.Config.SecretEnvKeys {
		if k == name {
			// Write-only: the API names it but never returns the value; keep what Terraform set.
			state.Secret = types.BoolValue(true)
			state.ValueWO = types.StringNull()
			r.readReference(ctx, p.ID, &state, &resp.Diagnostics)
			if resp.Diagnostics.HasError() {
				return
			}
			resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
			return
		}
	}
	v, ok := p.Config.EnvVars[name]
	if !ok {
		// Removed outside Terraform (neither a plain nor a secret variable any more).
		resp.State.RemoveResource(ctx)
		return
	}
	// A plain variable. One that is a secret in the state and was moved outside Terraform
	// flips to secret = false, so the next plan recreates it as a secret; on an API without
	// secret variables a secret is stored plain and keeps its flag.
	if runr.SupportsSecretEnv(p) {
		state.Secret = types.BoolValue(false)
	}
	state.Value = types.StringValue(v)
	state.ValueWO = types.StringNull()
	state.Reference = types.ObjectNull(envVarReferenceAttrTypes)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

// readReference reads from the project's secret list whether the secret variable of m is a
// reference and to what: a reference made a value outside Terraform (or the other way round)
// then shows as a change. APIs without the secret list keep the state as it is.
func (r *envVarResource) readReference(ctx context.Context, projectID string, m *envVarModel, diags *diag.Diagnostics) {
	list, err := r.client.API.ListSecrets(ctx, projectID)
	if runr.IsNotFound(err) {
		return
	}
	if err != nil {
		apiError(diags, "Read secret variables failed", err)
		return
	}
	for _, s := range list.Secrets {
		if s.Name != m.Name.ValueString() {
			continue
		}
		if s.External() && s.Reference != nil {
			m.Reference = referenceObject(m.reference(ctx, diags), s.Reference)
			m.Value = types.StringNull()
		} else {
			m.Reference = types.ObjectNull(envVarReferenceAttrTypes)
		}
		return
	}
}

func (r *envVarResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg envVarModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.write(ctx, &plan, &cfg, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.ValueWO = types.StringNull()
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *envVarResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state envVarModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	_, err := r.apply(ctx, state.ProjectID.ValueString(), runr.EnvChange{Unset: []string{state.Name.ValueString()}})
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Remove environment variable failed", err)
	}
}

// ImportState accepts "<project id or name>/<NAME>". A secret variable is imported with
// secret = true and without its value (the API never returns it): the next apply writes the
// configured value again. A reference is imported with its store, path and key.
func (r *envVarResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	i := strings.LastIndex(req.ID, "/")
	if i <= 0 || i == len(req.ID)-1 {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<NAME>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, req.ID[:i])
	if err != nil {
		apiError(&resp.Diagnostics, "Import environment variable failed", err)
		return
	}
	name := req.ID[i+1:]
	secret := false
	for _, k := range p.Config.SecretEnvKeys {
		secret = secret || k == name
	}
	if secret {
		// A reference is imported with it (Read); a value is not.
		m := envVarModel{Name: types.StringValue(name), Reference: types.ObjectNull(envVarReferenceAttrTypes)}
		r.readReference(ctx, p.ID, &m, &resp.Diagnostics)
		if m.Reference.IsNull() {
			resp.Diagnostics.AddWarning("Secret value not imported",
				name+" is a secret variable: RunR never returns its value, so the state holds none. "+
					"The next apply writes the value of the configuration again.")
		}
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), p.ID+"/"+name)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("name"), name)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("secret"), secret)...)
}
