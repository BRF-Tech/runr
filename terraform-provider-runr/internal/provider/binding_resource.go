package provider

import (
	"context"
	"errors"
	"regexp"
	"time"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewBindingResource) }

var (
	_ resource.Resource                   = &bindingResource{}
	_ resource.ResourceWithConfigure      = &bindingResource{}
	_ resource.ResourceWithImportState    = &bindingResource{}
	_ resource.ResourceWithValidateConfig = &bindingResource{}
)

// NewBindingResource returns the runr_binding resource.
func NewBindingResource() resource.Resource { return &bindingResource{} }

type bindingResource struct{ client *Client }

type bindingModel struct {
	ID               types.String `tfsdk:"id"`
	Name             types.String `tfsdk:"name"`
	Type             types.String `tfsdk:"type"`
	Plan             types.String `tfsdk:"plan"`
	RegionID         types.String `tfsdk:"region_id"`
	MemoryMB         types.Int64  `tfsdk:"memory_mb"`
	QuotaGB          types.Int64  `tfsdk:"quota_gb"`
	StorageGB        types.Int64  `tfsdk:"storage_gb"`
	ConnectionLimit  types.Int64  `tfsdk:"connection_limit"`
	StatementTimeout types.String `tfsdk:"statement_timeout"`
	Instances        types.Int64  `tfsdk:"instances"`
	Backups          types.Bool   `tfsdk:"backups"`
	Values           types.Map    `tfsdk:"values"`
	Wait             types.Bool   `tfsdk:"wait"`
	Timeout          types.String `tfsdk:"timeout"`
	Status           types.String `tfsdk:"status"`
	StatusMessage    types.String `tfsdk:"status_message"`
	ResourceName     types.String `tfsdk:"resource_name"`
	EnvKeys          types.List   `tfsdk:"env_keys"`
	OverQuota        types.Bool   `tfsdk:"over_quota"`
}

// bindingIntSettings are the integer settings and the binding types (and plan) they apply to.
var bindingIntSettings = []struct {
	attr  string
	types []string
	// dedicated: only postgres bindings of the dedicated plan.
	dedicated bool
}{
	{"memory_mb", []string{runr.BindingKV}, false},
	{"quota_gb", []string{runr.BindingObject}, false},
	{"storage_gb", []string{runr.BindingPostgres}, false},
	{"connection_limit", []string{runr.BindingPostgres}, false},
	{"instances", []string{runr.BindingPostgres}, true},
}

func (r *bindingResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_binding"
}

func (r *bindingResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keepInt := []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}
	keepStr := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A binding of the organization: a managed `kv` store (Redis), `object` storage (S3), a " +
			"`postgres` database, or `external` credentials. Attach it to projects with `runr_binding_attachment`; " +
			"its connection details reach the functions as environment variables.\n\n" +
			"Credentials are never part of the state: the API returns them only from `runr bindings credentials` " +
			"(permission `bindings.view_credentials`, audited). The `values` of an external binding are write-only: " +
			"Terraform keeps what it set.\n\n" +
			"Destroying the resource deletes the binding with retention: it is detached at once and its data is kept " +
			"for the retention period (`runr bindings restore` brings it back). Its name stays taken until the data " +
			"is purged, so a replacement needs a new `name`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keepStr, MarkdownDescription: "Binding id."},
			"name": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Name (lower case letters, digits and `-`, at most 30 characters; unique in the organization).",
				Validators: []validator.String{stringvalidator.LengthBetween(1, 30), stringvalidator.RegexMatches(
					regexp.MustCompile(`^[a-z]([-a-z0-9]*[a-z0-9])?$`), "must be lower case letters, digits and -, starting with a letter")}},
			"type": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "`kv`, `object`, `postgres` or `external`.",
				Validators:          []validator.String{stringvalidator.OneOf(runr.BindingTypes...)}},
			"plan": schema.StringAttribute{Optional: true, Computed: true,
				PlanModifiers:       []planmodifier.String{stringplanmodifier.UseStateForUnknown(), stringplanmodifier.RequiresReplace()},
				MarkdownDescription: "`postgres`: `shared` (default; a database on the region's shared cluster) or `dedicated` (a cluster of its own). Other types: computed.",
				Validators:          []validator.String{stringvalidator.OneOf(runr.BindingPlanShared, runr.BindingPlanDedicated)}},
			"region_id": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Region of a `kv`, `object` or `postgres` binding (required for those; external bindings have none). " +
					"It can be attached to projects of that region."},
			"memory_mb": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators:          []validator.Int64{int64validator.AtLeast(8)},
				MarkdownDescription: "`kv`: memory the binding's keys may use (default 64); above it the user can only read and delete."},
			"quota_gb": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators:          []validator.Int64{int64validator.AtLeast(1)},
				MarkdownDescription: "`object`: bucket quota in GB (default 5)."},
			"storage_gb": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators: []validator.Int64{int64validator.AtLeast(1)},
				MarkdownDescription: "`postgres`: size limit (shared, default 1) or volume size per instance (dedicated, default 5; " +
					"it can only grow)."},
			"connection_limit": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators:          []validator.Int64{int64validator.AtLeast(1)},
				MarkdownDescription: "`postgres`: connections of the role and the database (default 20)."},
			"statement_timeout": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keepStr,
				Validators: []validator.String{stringvalidator.RegexMatches(regexp.MustCompile(`^\d+(ms|s|min)$`),
					"must be a number with ms, s or min, e.g. 30s")},
				MarkdownDescription: "`postgres`: `statement_timeout` of the role (default `30s`)."},
			"instances": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators:          []validator.Int64{int64validator.Between(1, 3)},
				MarkdownDescription: "`postgres` dedicated: 1 primary plus replicas, up to 3 (default 1)."},
			"backups": schema.BoolAttribute{Optional: true, Computed: true,
				PlanModifiers:       []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "`postgres` dedicated: daily base backup and WAL archive to the region object store (default off)."},
			"values": schema.MapAttribute{Optional: true, Sensitive: true, ElementType: types.StringType,
				MarkdownDescription: "`external`: the variables to inject (names upper case, digits and `_`). Write-only: " +
					"Terraform keeps what it set; changing them updates the attached projects."},
			"wait": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Wait at create until the binding is provisioned (`ready`; default `true`)."},
			"timeout": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("10m"),
				MarkdownDescription: "How long `wait` waits (Go duration, default `10m`); a dedicated Postgres cluster takes a few minutes."},
			"status":         schema.StringAttribute{Computed: true, MarkdownDescription: "`provisioning`, `ready`, `rotating` or `error`."},
			"status_message": schema.StringAttribute{Computed: true, MarkdownDescription: "Reason of an `error` status."},
			"resource_name": schema.StringAttribute{Computed: true, PlanModifiers: keepStr,
				MarkdownDescription: "Name of the binding's resources on the data service (bucket, Redis user, database)."},
			"env_keys": schema.ListAttribute{Computed: true, ElementType: types.StringType,
				MarkdownDescription: "Variables an attachment injects (without its prefix)."},
			"over_quota": schema.BoolAttribute{Computed: true,
				MarkdownDescription: "The binding is over its size limit: writes are refused until data is deleted or the limit is raised."},
		},
	}
}

func (r *bindingResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (m *bindingModel) intAttr(name string) types.Int64 {
	switch name {
	case "memory_mb":
		return m.MemoryMB
	case "quota_gb":
		return m.QuotaGB
	case "storage_gb":
		return m.StorageGB
	case "connection_limit":
		return m.ConnectionLimit
	case "instances":
		return m.Instances
	}
	return types.Int64Null()
}

func (r *bindingResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m bindingModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || !known(m.Type) {
		return
	}
	typ := m.Type.ValueString()
	plan := m.Plan.ValueString()
	external := typ == runr.BindingExternal
	if external {
		if !m.RegionID.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("region_id"), "External bindings have no region", "Remove region_id.")
		}
		if m.Values.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("values"), "values required", "An external binding needs its variables in values.")
		}
	} else {
		if m.RegionID.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("region_id"), "region_id required", "A "+typ+" binding lives in a region.")
		}
		if !m.Values.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("values"), "values are for external bindings", "Use the settings attributes for a "+typ+" binding.")
		}
	}
	if !m.Plan.IsNull() && !m.Plan.IsUnknown() && typ != runr.BindingPostgres {
		resp.Diagnostics.AddAttributeError(path.Root("plan"), "plan applies to postgres bindings", "Remove plan from this "+typ+" binding.")
	}
	for _, s := range bindingIntSettings {
		v := m.intAttr(s.attr)
		if v.IsNull() {
			continue
		}
		applies := false
		for _, t := range s.types {
			applies = applies || t == typ
		}
		if s.dedicated && plan != runr.BindingPlanDedicated {
			applies = false
		}
		if !applies {
			resp.Diagnostics.AddAttributeError(path.Root(s.attr), s.attr+" does not apply", s.attr+" is not a setting of this "+typ+" binding.")
		}
	}
	if !m.StatementTimeout.IsNull() && typ != runr.BindingPostgres {
		resp.Diagnostics.AddAttributeError(path.Root("statement_timeout"), "statement_timeout does not apply", "statement_timeout is a postgres setting.")
	}
	if !m.Backups.IsNull() && (typ != runr.BindingPostgres || plan != runr.BindingPlanDedicated) {
		resp.Diagnostics.AddAttributeError(path.Root("backups"), "backups does not apply", "backups is a setting of dedicated postgres bindings.")
	}
	if known(m.Timeout) {
		if _, err := time.ParseDuration(m.Timeout.ValueString()); err != nil {
			resp.Diagnostics.AddAttributeError(path.Root("timeout"), "Invalid duration", err.Error())
		}
	}
}

// settings returns the settings set in the configuration (plan).
func (m *bindingModel) settings() map[string]any {
	out := map[string]any{}
	for _, s := range bindingIntSettings {
		if v := m.intAttr(s.attr); known(v) {
			out[s.attr] = v.ValueInt64()
		}
	}
	if known(m.StatementTimeout) {
		out["statement_timeout"] = m.StatementTimeout.ValueString()
	}
	if known(m.Backups) {
		out["backups"] = m.Backups.ValueBool()
	}
	return out
}

func (m *bindingModel) values(ctx context.Context, diags *diag.Diagnostics) map[string]string {
	if !known(m.Values) {
		return nil
	}
	out := map[string]string{}
	diags.Append(m.Values.ElementsAs(ctx, &out, false)...)
	return out
}

func (r *bindingResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan bindingModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := runr.CreateBindingInput{Name: plan.Name.ValueString(), Type: plan.Type.ValueString(),
		RegionID: plan.RegionID.ValueString(), Settings: plan.settings(), Values: plan.values(ctx, &resp.Diagnostics)}
	if known(plan.Plan) {
		in.Plan = plan.Plan.ValueString()
	}
	if len(in.Settings) == 0 {
		in.Settings = nil
	}
	if resp.Diagnostics.HasError() {
		return
	}
	b, err := r.client.API.CreateBinding(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create binding failed", err)
		return
	}
	// Keep the binding in the state even if waiting fails, so it is not orphaned.
	plan.fromAPI(ctx, b, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	if plan.Wait.ValueBool() && b.Status != runr.BindingReady {
		timeout, _ := time.ParseDuration(plan.Timeout.ValueString())
		b, err = r.client.API.WaitBinding(ctx, b, r.client.wait(timeout))
		if b != nil {
			plan.fromAPI(ctx, b, &resp.Diagnostics)
			resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
		}
		if err != nil {
			apiError(&resp.Diagnostics, "Binding provisioning failed", err)
			return
		}
	}
}

func (r *bindingResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state bindingModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	b, err := r.client.API.GetBinding(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) || (err == nil && b.Deleted()) {
		// Deleted (or scheduled for deletion) outside Terraform.
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read binding failed", err)
		return
	}
	state.fromAPI(ctx, b, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *bindingResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state bindingModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.ID = state.ID
	in := runr.UpdateBindingInput{}
	want, have := plan.settings(), state.settings()
	for k, v := range want {
		if have[k] != v {
			if in.Settings == nil {
				in.Settings = map[string]any{}
			}
			in.Settings[k] = v
		}
	}
	if !plan.Values.Equal(state.Values) && known(plan.Values) {
		in.Values = plan.values(ctx, &resp.Diagnostics)
	}
	if resp.Diagnostics.HasError() {
		return
	}
	var b *runr.Binding
	var err error
	if in.Settings != nil || in.Values != nil {
		b, err = r.client.API.UpdateBinding(ctx, state.ID.ValueString(), in)
	} else {
		b, err = r.client.API.GetBinding(ctx, state.ID.ValueString())
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Update binding failed", err)
		return
	}
	plan.fromAPI(ctx, b, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *bindingResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state bindingModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	b, err := r.client.API.GetBinding(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) || (err == nil && b.Deleted()) {
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read binding failed", err)
		return
	}
	if _, _, err := r.client.API.DeleteBinding(ctx, b); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete binding failed", err)
	}
}

// ImportState accepts the binding's id or name.
func (r *bindingResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	b, err := r.client.API.FindBinding(ctx, req.ID)
	var nf *runr.NotFoundError
	if errors.As(err, &nf) {
		resp.Diagnostics.AddError("Import binding failed", err.Error())
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Import binding failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), b.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("wait"), true)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("timeout"), "10m")...)
	if b.Type == runr.BindingExternal {
		resp.Diagnostics.AddWarning("Values are write-only",
			"The values of external binding "+b.Name+" cannot be read; Terraform sets the values of the configuration on the next apply.")
	}
}

func (m *bindingModel) fromAPI(ctx context.Context, b *runr.Binding, diags *diag.Diagnostics) {
	m.ID = types.StringValue(b.ID)
	m.Name = types.StringValue(b.Name)
	m.Type = types.StringValue(b.Type)
	m.Plan = strOrNull(b.Plan)
	m.RegionID = strOrNull(b.RegionID)
	m.Status = types.StringValue(b.Status)
	m.StatusMessage = types.StringValue(b.StatusMessage)
	m.ResourceName = types.StringValue(b.ResourceName)
	m.EnvKeys = listOfStrings(ctx, b.EnvKeys, diags)
	if m.EnvKeys.IsNull() {
		m.EnvKeys = types.ListValueMust(types.StringType, nil)
	}
	set := func(key string) types.Int64 {
		v, ok := b.Settings[key]
		if !ok {
			return types.Int64Null()
		}
		switch n := v.(type) {
		case float64:
			return types.Int64Value(int64(n))
		case int64:
			return types.Int64Value(n)
		case int:
			return types.Int64Value(int64(n))
		}
		return types.Int64Null()
	}
	m.MemoryMB = set("memory_mb")
	m.QuotaGB = set("quota_gb")
	m.StorageGB = set("storage_gb")
	m.ConnectionLimit = set("connection_limit")
	m.Instances = set("instances")
	m.StatementTimeout = types.StringNull()
	if s, ok := b.Settings["statement_timeout"].(string); ok {
		m.StatementTimeout = types.StringValue(s)
	}
	m.Backups = types.BoolNull()
	if v, ok := b.Settings["backups"].(bool); ok {
		m.Backups = types.BoolValue(v)
	}
	over, _ := b.Settings["over_quota"].(bool)
	m.OverQuota = types.BoolValue(over)
	if m.Wait.IsNull() || m.Wait.IsUnknown() {
		m.Wait = types.BoolValue(true)
	}
	if m.Timeout.IsNull() || m.Timeout.IsUnknown() {
		m.Timeout = types.StringValue("10m")
	}
	if m.Values.IsUnknown() {
		m.Values = types.MapNull(types.StringType)
	}
}
