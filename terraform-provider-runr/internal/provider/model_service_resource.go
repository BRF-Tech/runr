package provider

import (
	"context"
	"errors"
	"regexp"
	"time"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/mapvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/objectplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewModelServiceResource) }

var (
	_ resource.Resource                   = &modelServiceResource{}
	_ resource.ResourceWithConfigure      = &modelServiceResource{}
	_ resource.ResourceWithImportState    = &modelServiceResource{}
	_ resource.ResourceWithValidateConfig = &modelServiceResource{}
)

// NewModelServiceResource returns the runr_model_service resource.
func NewModelServiceResource() resource.Resource { return &modelServiceResource{} }

type modelServiceResource struct{ client *Client }

type modelServiceModel struct {
	ID             types.String `tfsdk:"id"`
	Name           types.String `tfsdk:"name"`
	HubID          types.String `tfsdk:"hub_id"`
	Revision       types.String `tfsdk:"revision"`
	ModelID        types.String `tfsdk:"model_id"`
	RegionID       types.String `tfsdk:"region_id"`
	Mode           types.String `tfsdk:"mode"`
	Device         types.String `tfsdk:"device"`
	Engine         types.String `tfsdk:"engine"`
	GPU            types.Object `tfsdk:"gpu"`
	CPU            types.String `tfsdk:"cpu"`
	MemoryMB       types.Int64  `tfsdk:"memory_mb"`
	MinScale       types.Int64  `tfsdk:"min_scale"`
	MaxScale       types.Int64  `tfsdk:"max_scale"`
	ScaleToZero    types.Bool   `tfsdk:"scale_to_zero"`
	TimeoutSeconds types.Int64  `tfsdk:"timeout_seconds"`
	Image          types.String `tfsdk:"image"`
	Args           types.List   `tfsdk:"args"`
	EnvVars        types.Map    `tfsdk:"env_vars"`
	Port           types.Int64  `tfsdk:"port"`
	HealthPath     types.String `tfsdk:"health_path"`
	ServedName     types.String `tfsdk:"served_name"`
	Running        types.Bool   `tfsdk:"running"`
	Wait           types.Bool   `tfsdk:"wait"`
	Timeout        types.String `tfsdk:"timeout"`
	Status         types.String `tfsdk:"status"`
	StatusMessage  types.String `tfsdk:"status_message"`
	ServiceName    types.String `tfsdk:"service_name"`
	API            types.String `tfsdk:"api"`
	URL            types.String `tfsdk:"url"`
	OpenAIBase     types.String `tfsdk:"openai_base"`
}

type modelGPUModel struct {
	Count     types.Int64  `tfsdk:"count"`
	Type      types.String `tfsdk:"type"`
	MemoryMin types.Int64  `tfsdk:"memory_min"`
}

var modelGPUAttrTypes = map[string]attr.Type{"count": types.Int64Type, "type": types.StringType, "memory_min": types.Int64Type}

var modelServiceNamePattern = regexp.MustCompile(`^[a-z]([-a-z0-9]{0,28}[a-z0-9])?$`)

func (r *modelServiceResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_model_service"
}

func (r *modelServiceResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	keepInt := []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A model service: a Hugging Face model (or a model of the organization's library) served by an engine " +
			"(vLLM, TGI, TEI, RunR's transformers / diffusers server or a custom image) on CPU or GPU in a region. " +
			"`online` services download the model from the Hub into the region's model volume; `offline` services serve " +
			"the region's offline copy and have no egress. Settings left out are RunR's pick (engine, device, size, scaling); " +
			"a change of a running service redeploys it. Bind it to projects with `runr models bind` " +
			"(`RUNR_MODEL_<ALIAS>_*` variables).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Model service id."},
			"name": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: []planmodifier.String{
				stringplanmodifier.RequiresReplaceIfConfigured(), stringplanmodifier.UseStateForUnknown()},
				Validators:          []validator.String{stringvalidator.RegexMatches(modelServiceNamePattern, "must be lower case letters, digits and dashes, start with a letter, at most 30 characters")},
				MarkdownDescription: "Service name (default: from the model's name). Changing it creates a new service."},
			"hub_id": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Hugging Face Hub id (`organization/name`); the model is added to the library on first use. Exclusive with `model_id`."},
			"revision": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Hub branch, tag or commit of `hub_id` (default `main`); the model is pinned to the commit it points to."},
			"model_id": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: []planmodifier.String{
				stringplanmodifier.RequiresReplaceIfConfigured(), stringplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "Id of a model of the library (uploaded and imported models). Computed for `hub_id`."},
			"region_id": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Region id (`data.runr_region`). Changing it creates a new service."},
			"mode": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				Validators:          []validator.String{stringvalidator.OneOf("online", "offline")},
				MarkdownDescription: "`online` (default; from the Hub, cached in the region) or `offline` (the region's copy, no egress)."},
			"device": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				Validators:          []validator.String{stringvalidator.OneOf("cpu", "gpu")},
				MarkdownDescription: "`cpu` or `gpu` (default: RunR's pick for the model)."},
			"engine": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				Validators:          []validator.String{stringvalidator.OneOf("vllm", "tgi", "tei", "transformers", "diffusers", "custom")},
				MarkdownDescription: "`vllm`, `tgi`, `tei`, `transformers`, `diffusers` or `custom` (default: RunR's pick for the task and device)."},
			"gpu": schema.SingleNestedAttribute{Optional: true, Computed: true,
				PlanModifiers:       []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "GPUs of a `gpu` service (default `{count = 1}`).",
				Attributes: map[string]schema.Attribute{
					"count": schema.Int64Attribute{Required: true, Validators: []validator.Int64{int64validator.Between(1, 8)},
						MarkdownDescription: "Number of GPUs."},
					"type": schema.StringAttribute{Optional: true, MarkdownDescription: "GPU product (node label), e.g. `NVIDIA A100-SXM4-80GB`."},
					"memory_min": schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.AtLeast(1)},
						MarkdownDescription: "Least GPU memory per GPU in MiB."},
				}},
			"cpu": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Kubernetes CPU quantity (default: from the model size)."},
			"memory_mb": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators: []validator.Int64{int64validator.Between(256, 262144)}, MarkdownDescription: "Memory in MiB (default: from the model size)."},
			"min_scale": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators: []validator.Int64{int64validator.Between(0, 10)}, MarkdownDescription: "Minimum instances (default 0; 1 keeps one warm)."},
			"max_scale": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators: []validator.Int64{int64validator.Between(1, 100)}, MarkdownDescription: "Maximum instances (default 1)."},
			"scale_to_zero": schema.BoolAttribute{Optional: true, Computed: true,
				PlanModifiers:       []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "Scale to zero instances when idle (default true)."},
			"timeout_seconds": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
				Validators: []validator.Int64{int64validator.Between(1, 3600)}, MarkdownDescription: "Request timeout in seconds (default 300)."},
			"image": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Image instead of the engine's (a mirror, a newer release; the image of a `custom` engine)."},
			"args": schema.ListAttribute{Optional: true, ElementType: types.StringType,
				Validators:          []validator.List{listvalidator.SizeAtLeast(1)},
				MarkdownDescription: "Arguments appended to the engine's (the only arguments of a `custom` image)."},
			"env_vars": schema.MapAttribute{Optional: true, Sensitive: true, ElementType: types.StringType,
				Validators:          []validator.Map{mapvalidator.SizeAtLeast(1)},
				MarkdownDescription: "Extra environment variables (stored encrypted); they win over RunR's."},
			"port": schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.Between(1, 65535)},
				MarkdownDescription: "Port a custom image listens on (default 8080)."},
			"health_path": schema.StringAttribute{Optional: true, MarkdownDescription: "Readiness path of a custom image (default: TCP)."},
			"served_name": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Model name of the OpenAI API (default: the model's name)."},
			"running": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Deploy the service (`true`, default) or keep it stopped. A failed service is deployed again on the next apply."},
			"wait": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Wait until a deployed service is ready."},
			"timeout": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("30m"),
				MarkdownDescription: "How long `wait` waits (Go duration; large models take a while the first time)."},
			"status": schema.StringAttribute{Computed: true,
				MarkdownDescription: "`stopped`, `waiting_snapshot`, `deploying`, `ready` or `failed`."},
			"status_message": schema.StringAttribute{Computed: true, MarkdownDescription: "Reason of the status, if any."},
			"service_name":   schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Knative Service name."},
			"api": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "API of the engine: `openai`, `tei`, `runr` or `custom`."},
			"url": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Cluster-local URL (projects of the region reach it; bound projects get it as `RUNR_MODEL_<ALIAS>_URL`)."},
			"openai_base": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`<url>/v1` for engines with OpenAI-compatible routes."},
		},
	}
}

func (r *modelServiceResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *modelServiceResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m modelServiceModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if !m.HubID.IsUnknown() && !m.ModelID.IsUnknown() {
		if m.HubID.IsNull() == m.ModelID.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("hub_id"), "hub_id or model_id",
				"Set exactly one of hub_id (a Hugging Face model) and model_id (a model of the library).")
		}
	}
	if !m.Revision.IsNull() && m.HubID.IsNull() && !m.HubID.IsUnknown() {
		resp.Diagnostics.AddAttributeError(path.Root("revision"), "revision without hub_id", "revision applies to hub_id.")
	}
	if !m.GPU.IsNull() && !m.GPU.IsUnknown() && known(m.Device) && m.Device.ValueString() != "gpu" {
		resp.Diagnostics.AddAttributeError(path.Root("gpu"), "gpu needs device gpu", "Set device = \"gpu\" or remove gpu.")
	}
	if known(m.MinScale) && known(m.MaxScale) && m.MinScale.ValueInt64() > m.MaxScale.ValueInt64() {
		resp.Diagnostics.AddAttributeError(path.Root("min_scale"), "min_scale above max_scale", "min_scale must not be greater than max_scale.")
	}
	if known(m.Timeout) {
		if _, err := time.ParseDuration(m.Timeout.ValueString()); err != nil {
			resp.Diagnostics.AddAttributeError(path.Root("timeout"), "Invalid duration", err.Error())
		}
	}
	if known(m.EnvVars) {
		for k := range m.EnvVars.Elements() {
			if err := runr.ValidateEnvName(k); err != nil {
				resp.Diagnostics.AddAttributeError(path.Root("env_vars"), "Invalid variable name", err.Error())
			}
		}
	}
}

// settings returns the settings of the plan; with state, only those that differ from it, and
// the optional settings removed from the configuration (reset to the engine's default).
func (r *modelServiceResource) settings(ctx context.Context, plan modelServiceModel, state *modelServiceModel, diags *diag.Diagnostics) (runr.ModelServiceSettings, []string) {
	var s runr.ModelServiceSettings
	var clear []string
	changed := func(p, st attr.Value) bool { return state == nil || !p.Equal(st) }
	str := func(v types.String, st types.String) *string {
		if !known(v) || (state != nil && !changed(v, st)) {
			return nil
		}
		return strPtr(v)
	}
	num := func(v types.Int64, st types.Int64) *int {
		if !known(v) || (state != nil && !changed(v, st)) {
			return nil
		}
		return intPtr(v)
	}
	var st modelServiceModel
	if state != nil {
		st = *state
	}
	s.Mode = str(plan.Mode, st.Mode)
	s.Device = str(plan.Device, st.Device)
	s.Engine = str(plan.Engine, st.Engine)
	if known(plan.GPU) && changed(plan.GPU, st.GPU) {
		var g modelGPUModel
		diags.Append(plan.GPU.As(ctx, &g, basetypes.ObjectAsOptions{})...)
		s.GPU = &runr.GPURequest{Count: int(g.Count.ValueInt64()), Type: g.Type.ValueString(), MemoryMin: int(g.MemoryMin.ValueInt64())}
	}
	s.CPU = str(plan.CPU, st.CPU)
	s.MemoryMB = num(plan.MemoryMB, st.MemoryMB)
	s.MinScale = num(plan.MinScale, st.MinScale)
	s.MaxScale = num(plan.MaxScale, st.MaxScale)
	if known(plan.ScaleToZero) && changed(plan.ScaleToZero, st.ScaleToZero) {
		s.ScaleToZero = boolPtr(plan.ScaleToZero)
	}
	s.TimeoutSeconds = num(plan.TimeoutSeconds, st.TimeoutSeconds)
	s.ServedName = str(plan.ServedName, st.ServedName)

	// Optional settings without a default of RunR's: removing one resets it.
	reset := func(name string, p, stv attr.Value) bool {
		if state != nil && p.IsNull() && !stv.IsNull() {
			clear = append(clear, name)
			return true
		}
		return false
	}
	if !reset("image", plan.Image, st.Image) {
		s.Image = str(plan.Image, st.Image)
	}
	if !reset("args", plan.Args, st.Args) && known(plan.Args) && changed(plan.Args, st.Args) {
		s.Args = stringList(ctx, plan.Args, diags)
	}
	if !reset("env_vars", plan.EnvVars, st.EnvVars) && known(plan.EnvVars) && changed(plan.EnvVars, st.EnvVars) {
		env := map[string]string{}
		diags.Append(plan.EnvVars.ElementsAs(ctx, &env, false)...)
		s.EnvVars = &env
	}
	if !reset("port", plan.Port, st.Port) {
		s.Port = num(plan.Port, st.Port)
	}
	if !reset("health_path", plan.HealthPath, st.HealthPath) {
		s.HealthPath = str(plan.HealthPath, st.HealthPath)
	}
	return s, clear
}

// fill writes a service into the model (computed values and what the API resolved).
func (r *modelServiceResource) fill(ctx context.Context, m *modelServiceModel, s *runr.ModelService, diags *diag.Diagnostics) {
	m.ID = types.StringValue(s.ID)
	m.Name = types.StringValue(s.Name)
	m.ModelID = types.StringValue(s.AIModelID)
	m.RegionID = types.StringValue(s.RegionID)
	m.Mode = types.StringValue(s.Mode)
	m.Device = types.StringValue(s.Device)
	m.Engine = types.StringValue(s.Engine)
	if s.GPU != nil {
		g := modelGPUModel{Count: types.Int64Value(int64(s.GPU.Count)), Type: strOrNull(s.GPU.Type), MemoryMin: types.Int64Null()}
		if s.GPU.MemoryMin > 0 {
			g.MemoryMin = types.Int64Value(int64(s.GPU.MemoryMin))
		}
		obj, d := types.ObjectValueFrom(ctx, modelGPUAttrTypes, g)
		diags.Append(d...)
		m.GPU = obj
	} else {
		m.GPU = types.ObjectNull(modelGPUAttrTypes)
	}
	m.CPU = types.StringValue(s.CPU)
	m.MemoryMB = types.Int64Value(int64(s.MemoryMB))
	m.MinScale = types.Int64Value(int64(s.MinScale))
	m.MaxScale = types.Int64Value(int64(s.MaxScale))
	m.ScaleToZero = types.BoolValue(s.ScaleToZero)
	m.TimeoutSeconds = types.Int64Value(int64(s.TimeoutSeconds))
	m.Image = strOrNull(s.Image)
	m.Args = listOfStrings(ctx, s.Args, diags)
	if len(s.EnvVars) > 0 {
		env, d := types.MapValueFrom(ctx, types.StringType, map[string]string(s.EnvVars))
		diags.Append(d...)
		m.EnvVars = env
	} else {
		m.EnvVars = types.MapNull(types.StringType)
	}
	if s.Port > 0 {
		m.Port = types.Int64Value(int64(s.Port))
	} else {
		m.Port = types.Int64Null()
	}
	m.HealthPath = strOrNull(s.HealthPath)
	m.ServedName = types.StringValue(s.ServedName)
	m.Running = types.BoolValue(s.Running())
	m.Status = types.StringValue(s.Status)
	m.StatusMessage = types.StringValue(s.StatusMessage)
	m.ServiceName = types.StringValue(s.ServiceName)
	m.API = types.StringValue(s.API)
	m.URL = types.StringValue(s.URL)
	m.OpenAIBase = types.StringValue(s.OpenAIBase)
}

func (r *modelServiceResource) wait(ctx context.Context, m modelServiceModel, s *runr.ModelService) (*runr.ModelService, error) {
	if !m.Wait.ValueBool() || !s.Running() || s.Status == runr.ModelServiceReady {
		return s, nil
	}
	timeout, _ := time.ParseDuration(m.Timeout.ValueString())
	ready, err := r.client.API.WaitModelService(ctx, s.ID, r.client.wait(timeout))
	if ready == nil {
		ready = s
	}
	return ready, err
}

func (r *modelServiceResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan modelServiceModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	settings, _ := r.settings(ctx, plan, nil, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	in := runr.CreateModelServiceInput{
		HubID:                plan.HubID.ValueString(),
		Revision:             plan.Revision.ValueString(),
		RegionID:             plan.RegionID.ValueString(),
		ModelServiceSettings: settings,
	}
	if known(plan.ModelID) {
		in.AIModelID = plan.ModelID.ValueString()
	}
	if known(plan.Name) {
		in.Name = plan.Name.ValueString()
	}
	if !plan.Running.ValueBool() {
		f := false
		in.Deploy = &f
	}
	s, err := r.client.API.CreateModelService(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create model service failed", err)
		return
	}
	s, err = r.wait(ctx, plan, s)
	r.fill(ctx, &plan, s, &resp.Diagnostics)
	if err != nil {
		// The service exists: keep it in the state (tainted by the error) so it is not orphaned.
		resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
		apiError(&resp.Diagnostics, "Model service did not become ready", err)
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *modelServiceResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state modelServiceModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	s, err := r.client.API.GetModelService(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read model service failed", err)
		return
	}
	r.fill(ctx, &state, s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *modelServiceResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state modelServiceModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	api := r.client.API
	id := state.ID.ValueString()
	settings, clear := r.settings(ctx, plan, &state, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	var s *runr.ModelService
	var err error
	if !settings.Empty() || len(clear) > 0 {
		// A running service is redeployed with the new settings.
		if s, err = api.UpdateModelService(ctx, id, settings, clear...); err != nil {
			apiError(&resp.Diagnostics, "Update model service failed", err)
			return
		}
	} else if s, err = api.GetModelService(ctx, id); err != nil {
		apiError(&resp.Diagnostics, "Read model service failed", err)
		return
	}
	switch want := plan.Running.ValueBool(); {
	case want && !s.Running():
		s, err = api.DeployModelService(ctx, id)
	case !want && s.Status != runr.ModelServiceStopped:
		s, err = api.StopModelService(ctx, id)
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Start or stop model service failed", err)
		return
	}
	s, err = r.wait(ctx, plan, s)
	r.fill(ctx, &plan, s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	if err != nil {
		apiError(&resp.Diagnostics, "Model service did not become ready", err)
	}
}

func (r *modelServiceResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state modelServiceModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.DeleteModelService(ctx, state.ID.ValueString())
	var ae *runr.APIError
	switch {
	case err == nil, runr.IsNotFound(err):
	case errors.As(err, &ae) && ae.StatusCode == 409:
		resp.Diagnostics.AddError("Model service is bound to projects", err.Error()+"\nRemove the bindings (runr models unbind) first.")
	default:
		apiError(&resp.Diagnostics, "Delete model service failed", err)
	}
}

// ImportState accepts a model service id or name.
func (r *modelServiceResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	s, err := r.client.API.FindModelService(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import model service failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), s.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("wait"), true)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("timeout"), "30m")...)
}
