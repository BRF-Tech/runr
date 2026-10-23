package provider

import (
	"context"
	"fmt"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/listplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/objectplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &projectResource{}
	_ resource.ResourceWithConfigure      = &projectResource{}
	_ resource.ResourceWithImportState    = &projectResource{}
	_ resource.ResourceWithValidateConfig = &projectResource{}
	_ resource.ResourceWithModifyPlan     = &projectResource{}
)

// NewProjectResource returns the runr_project resource.
func NewProjectResource() resource.Resource { return &projectResource{} }

type projectResource struct{ client *Client }

type projectModel struct {
	ID              types.String `tfsdk:"id"`
	Name            types.String `tfsdk:"name"`
	Type            types.String `tfsdk:"type"`
	Runtime         types.String `tfsdk:"runtime"`
	RuntimeVersion  types.String `tfsdk:"runtime_version"`
	RegionID        types.String `tfsdk:"region_id"`
	Description     types.String `tfsdk:"description"`
	Handler         types.String `tfsdk:"handler"`
	Filename        types.String `tfsdk:"filename"`
	Image           types.String `tfsdk:"image"`
	Command         types.List   `tfsdk:"command"`
	Args            types.List   `tfsdk:"args"`
	Ports           types.List   `tfsdk:"ports"`
	Resources       types.Object `tfsdk:"resources"`
	Scale           types.Object `tfsdk:"scale"`
	ScaleSchedule   types.List   `tfsdk:"scale_schedule"`
	Trigger         types.Object `tfsdk:"trigger"`
	Triggers        types.List   `tfsdk:"triggers"`
	Network         types.Object `tfsdk:"network"`
	DeployStrategy  types.Object `tfsdk:"deploy_strategy"`
	Global          types.Object `tfsdk:"global"`
	Slug            types.String `tfsdk:"slug"`
	EndpointURL     types.String `tfsdk:"endpoint_url"`
	LatestVersionID types.String `tfsdk:"latest_version_id"`
}

type resourcesModel struct {
	CPU            types.String `tfsdk:"cpu"`
	MemoryMB       types.Int64  `tfsdk:"memory_mb"`
	TimeoutSeconds types.Int64  `tfsdk:"timeout_seconds"`
}

type scaleModel struct {
	Min    types.Int64 `tfsdk:"min"`
	Max    types.Int64 `tfsdk:"max"`
	ToZero types.Bool  `tfsdk:"to_zero"`
}

type triggerModel struct {
	Type              types.String `tfsdk:"type"`
	Schedule          types.String `tfsdk:"schedule"`
	Timezone          types.String `tfsdk:"timezone"`
	EffectiveTimezone types.String `tfsdk:"effective_timezone"`
	Queue             types.String `tfsdk:"queue"`
}

// scheduleRowModel is one row of scale_schedule, the timed warmer.
type scheduleRowModel struct {
	Cron     types.String `tfsdk:"cron"`
	MinScale types.Int64  `tfsdk:"min_scale"`
}

var (
	resourcesAttrTypes   = map[string]attr.Type{"cpu": types.StringType, "memory_mb": types.Int64Type, "timeout_seconds": types.Int64Type}
	scaleAttrTypes       = map[string]attr.Type{"min": types.Int64Type, "max": types.Int64Type, "to_zero": types.BoolType}
	triggerAttrTypes     = map[string]attr.Type{"type": types.StringType, "schedule": types.StringType, "timezone": types.StringType, "effective_timezone": types.StringType, "queue": types.StringType}
	scheduleRowAttrTypes = map[string]attr.Type{"cron": types.StringType, "min_scale": types.Int64Type}
	scheduleRowType      = types.ObjectType{AttrTypes: scheduleRowAttrTypes}
)

var projectNamePattern = regexp.MustCompile(`^[A-Za-z][-_ A-Za-z0-9]*$`)

// cronPattern: five fields separated by single spaces, the form the API stores the expression in
// (a differently spaced value would read back as a change).
var cronPattern = regexp.MustCompile(`^[^@\s]\S*( \S+){4}$`)

// timedWarmerDescription documents scale_schedule (resource and data source).
const timedWarmerDescription = "Timed warmer: each row keeps at least `min_scale` containers warm from the moment its five-field " +
	"`cron` expression fires until the next row fires. The expressions run in the organization's time zone " +
	"(its Time zone setting, default Europe/Istanbul). While rows are set they replace `scale.min`; `scale.max` still applies. " +
	"RunR applies a changed schedule to the running workload within a minute, without a redeploy."

func (r *projectResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_project"
}

func (r *projectResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A RunR project: a function (`faas`), a single-file script (`xaas`) or a container (`caas`) " +
			"in a region. Code is uploaded with `runr deploy`; container images and deployments can be managed with `runr_deployment`.\n\n" +
			"A config change saves a new project version; running deployments pick it up when they are redeployed (see `runr_deployment.triggers`).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Project id.", PlanModifiers: keep},
			"name": schema.StringAttribute{
				Required: true, MarkdownDescription: "Project name; its slug names the service and the default URL. Changing it creates a new project.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				Validators:    []validator.String{stringvalidator.RegexMatches(projectNamePattern, "must start with a letter and contain letters, digits, dashes, underscores or spaces"), stringvalidator.LengthAtMost(255)},
			},
			"type": schema.StringAttribute{
				Required: true, MarkdownDescription: "`faas`, `xaas` or `caas`. Changing it creates a new project.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				Validators:    []validator.String{stringvalidator.OneOf(runr.TypeFaaS, runr.TypeXaaS, runr.TypeCaaS)},
			},
			"runtime": schema.StringAttribute{
				Optional: true, MarkdownDescription: "Runtime of `faas`/`xaas` projects: `python`, `nodejs`, `go`, `php`, … Changing it creates a new project.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
			},
			"runtime_version": schema.StringAttribute{
				Optional: true, Computed: true, MarkdownDescription: "Runtime version; default the platform's default version. Changing it creates a new project.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplaceIfConfigured(), stringplanmodifier.UseStateForUnknown()},
			},
			"region_id":   schema.StringAttribute{Required: true, MarkdownDescription: "Region id (`data.runr_region`). Changing it moves the project."},
			"description": schema.StringAttribute{Optional: true, MarkdownDescription: "Free text."},
			"handler": schema.StringAttribute{
				Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`faas`: `file.function` (default `main.handler` → `main.py`, `def handler`). `xaas`: the function name (default `handler`).",
			},
			"filename": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep, MarkdownDescription: "`xaas`: the script file (default `main.<ext>`)."},
			"image": schema.StringAttribute{
				Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`caas`: container image. Required when the project is created.",
			},
			"command": schema.ListAttribute{ElementType: types.StringType, Optional: true, MarkdownDescription: "`caas`: entrypoint override."},
			"args":    schema.ListAttribute{ElementType: types.StringType, Optional: true, MarkdownDescription: "`caas`: arguments."},
			"ports":   schema.ListAttribute{ElementType: types.Int64Type, Optional: true, MarkdownDescription: "`caas`: container ports; the first serves HTTP (default 80)."},
			"resources": schema.SingleNestedAttribute{
				Optional: true, Computed: true, MarkdownDescription: "Container limits.",
				PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				Attributes: map[string]schema.Attribute{
					"cpu": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "Kubernetes CPU quantity (`100m`, `0.5`)."},
					"memory_mb": schema.Int64Attribute{Optional: true, Computed: true, MarkdownDescription: "Memory in MB (at least 64).",
						PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}, Validators: []validator.Int64{int64validator.AtLeast(64)}},
					"timeout_seconds": schema.Int64Attribute{Optional: true, Computed: true, MarkdownDescription: "Request timeout in seconds.",
						PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}, Validators: []validator.Int64{int64validator.AtLeast(1)}},
				},
			},
			"scale": schema.SingleNestedAttribute{
				Optional: true, Computed: true, MarkdownDescription: "Autoscaling range.",
				PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				Attributes: map[string]schema.Attribute{
					"min": schema.Int64Attribute{Optional: true, Computed: true, MarkdownDescription: "Minimum instances (0-10).",
						PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}, Validators: []validator.Int64{int64validator.Between(0, 10)}},
					"max": schema.Int64Attribute{Optional: true, Computed: true, MarkdownDescription: "Maximum instances (1-1000).",
						PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}, Validators: []validator.Int64{int64validator.Between(1, 1000)}},
					"to_zero": schema.BoolAttribute{Optional: true, Computed: true, MarkdownDescription: "Scale to zero instances when idle.",
						PlanModifiers: []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()}},
				},
			},
			"scale_schedule": schema.ListNestedAttribute{
				Optional: true, Computed: true,
				MarkdownDescription: timedWarmerDescription + " At most 10 rows; `[]` turns the warmer off, leaving the attribute out " +
					"keeps the project's schedule as it is.",
				PlanModifiers: []planmodifier.List{listplanmodifier.UseStateForUnknown()},
				Validators:    []validator.List{listvalidator.SizeAtMost(runr.MaxScaleScheduleRows)},
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"cron": schema.StringAttribute{Required: true,
						MarkdownDescription: "Five-field cron expression (minute hour day-of-month month day-of-week), fields separated by one space, e.g. `0 9 * * 1-5`.",
						Validators:          []validator.String{stringvalidator.RegexMatches(cronPattern, "must be five cron fields separated by single spaces, e.g. \"0 9 * * 1-5\"")}},
					"min_scale": schema.Int64Attribute{Required: true,
						MarkdownDescription: "Containers kept warm from this moment on (0-10, at most `scale.max`).",
						Validators:          []validator.Int64{int64validator.Between(0, runr.MaxWarmScale)}},
				}},
			},
			"trigger": schema.SingleNestedAttribute{
				Optional: true, Computed: true,
				MarkdownDescription: "What starts the code: the project's primary trigger, the first enabled one of its trigger list. " +
					"Setting it changes the primary trigger's type and settings and leaves the project's other triggers as they are; " +
					"`triggers` sets the whole list instead (set either `trigger` or `triggers`). Read back, it is the primary " +
					"trigger, which can be of a type only `triggers` sets (e.g. `webhook` or `kafka`).",
				PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				Attributes: map[string]schema.Attribute{
					"type": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "`http` (default), `schedule`, `queue` or `workflow` (a durable workflow project; its only " +
							"trigger). Reads back the primary trigger's type, which can be one `triggers` sets (e.g. `webhook` or `kafka`).",
						Validators: []validator.String{stringvalidator.OneOf(runr.ProjectTriggerTypes...)}},
					"schedule": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "Cron expression of a `schedule` trigger, e.g. `*/5 * * * *`."},
					"timezone": schema.StringAttribute{Optional: true, Computed: true,
						PlanModifiers: []planmodifier.String{timezonePlan{}},
						Validators:    []validator.String{timezoneValidator{}},
						MarkdownDescription: "IANA time zone the `schedule` runs in, e.g. `Europe/Berlin` or `UTC`. `\"\"` runs it in the " +
							"organization's time zone (its Time zone setting, default Europe/Istanbul), which it then follows. Leaving it out keeps the " +
							"schedule's zone: a new schedule runs in the organization's, one created before RunR 0.6.0 in UTC."},
					"effective_timezone": schema.StringAttribute{Computed: true,
						PlanModifiers:       []planmodifier.String{effectiveTimezonePlan{}},
						MarkdownDescription: "The time zone the `schedule` runs in: `timezone`, else the organization's; null without a schedule."},
					"queue": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "Redis stream of a `queue` trigger (the region needs Redis)."},
				},
			},
			"triggers": triggersSchema(),
			"network":  networkSchema(),
			"deploy_strategy": deployStrategySchema("How a deploy rolls out ([deploy strategies](https://runr.sh/docs/deploy-strategies/)): " +
				"`direct`, `blue_green` or `canary` with the options of both. Values left out stay as they are; the project's " +
				"environments use these settings unless they set their own. Promotion, abort and rollback of a rollout stay with " +
				"`runr rollout`."),
			"global":            globalSchema(),
			"slug":              schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Slug derived from the name."},
			"endpoint_url":      schema.StringAttribute{Computed: true, MarkdownDescription: "Public URL (HTTP trigger)."},
			"latest_version_id": schema.StringAttribute{Computed: true, MarkdownDescription: "Id of the newest version."},
		},
	}
}

func (r *projectResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *projectResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m projectModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	// trigger sets the primary trigger, triggers the whole list
	if !m.Trigger.IsNull() && !m.Triggers.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("triggers"), "trigger and triggers both set",
			"Set either trigger (the primary trigger) or triggers (the whole list); with triggers, trigger reads back the primary one.")
	}
	validateTriggerList(ctx, m.Triggers, &resp.Diagnostics)
	validateNetwork(ctx, m.Network, &resp.Diagnostics)
	validateGlobal(ctx, m.Global, m.RegionID, &resp.Diagnostics)
	validateDeployStrategy(ctx, m.DeployStrategy, configuredPrimaryType(ctx, m, &resp.Diagnostics), path.Root("deploy_strategy"), &resp.Diagnostics)
	if m.Type.IsUnknown() {
		return
	}
	switch m.Type.ValueString() {
	case runr.TypeFaaS, runr.TypeXaaS:
		if m.Runtime.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("runtime"), "runtime required", "faas and xaas projects need a runtime.")
		}
		for _, a := range []struct {
			name string
			v    interface{ IsNull() bool }
		}{{"image", m.Image}, {"command", m.Command}, {"args", m.Args}, {"ports", m.Ports}} {
			if !a.v.IsNull() {
				resp.Diagnostics.AddAttributeError(path.Root(a.name), a.name+" applies to caas only", "Remove "+a.name+" or use type caas.")
			}
		}
	case runr.TypeCaaS:
		if !m.Runtime.IsNull() {
			resp.Diagnostics.AddAttributeError(path.Root("runtime"), "runtime does not apply to caas", "caas projects run an image.")
		}
	}
	// A time zone belongs to a schedule trigger
	if known(m.Trigger) {
		var tm triggerModel
		resp.Diagnostics.Append(m.Trigger.As(ctx, &tm, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		if known(tm.Timezone) && tm.Timezone.ValueString() != "" && known(tm.Type) && tm.Type.ValueString() != runr.TriggerSchedule {
			resp.Diagnostics.AddAttributeError(path.Root("trigger").AtName("timezone"), "timezone applies to a schedule trigger",
				"Remove trigger.timezone or use trigger.type \"schedule\".")
		}
	}
	// A warm row may not keep more containers than scale.max allows (when both are known here)
	if known(m.ScaleSchedule) && known(m.Scale) {
		var sm scaleModel
		resp.Diagnostics.Append(m.Scale.As(ctx, &sm, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		if known(sm.Max) {
			for i, row := range scheduleRows(ctx, m.ScaleSchedule, &resp.Diagnostics) {
				if int64(row.MinScale) > sm.Max.ValueInt64() {
					resp.Diagnostics.AddAttributeError(path.Root("scale_schedule").AtListIndex(i).AtName("min_scale"), "above scale.max",
						fmt.Sprintf("Row %d keeps %d containers warm; scale.max is %d.", i+1, row.MinScale, sm.Max.ValueInt64()))
				}
			}
		}
	}
}

// configuredPrimaryType is the primary trigger's type the configuration sets ("" when it sets
// none or it is not known yet): trigger.type, or the first enabled entry of triggers.
func configuredPrimaryType(ctx context.Context, m projectModel, diags *diag.Diagnostics) string {
	if known(m.Trigger) {
		var tm triggerModel
		diags.Append(m.Trigger.As(ctx, &tm, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		if known(tm.Type) {
			return tm.Type.ValueString()
		}
		return ""
	}
	for _, t := range triggerModels(ctx, m.Triggers, diags) {
		if t.Enabled.IsUnknown() || t.Type.IsUnknown() {
			return ""
		}
		if t.Enabled.IsNull() || t.Enabled.ValueBool() {
			return t.Type.ValueString()
		}
	}
	return ""
}

// validateTriggerList checks the rules of the list the API checks as a whole: unique names, at
// most one http trigger, a workflow trigger alone.
func validateTriggerList(ctx context.Context, l types.List, diags *diag.Diagnostics) {
	list := triggerModels(ctx, l, diags)
	names := map[string]bool{}
	http := 0
	for i, t := range list {
		at := path.Root("triggers").AtListIndex(i)
		if known(t.Name) {
			if names[t.Name.ValueString()] {
				diags.AddAttributeError(at.AtName("name"), "duplicate trigger name", "Trigger names are unique in a project: "+t.Name.ValueString()+".")
			}
			names[t.Name.ValueString()] = true
		}
		if !known(t.Type) {
			continue
		}
		switch t.Type.ValueString() {
		case runr.TriggerHTTP:
			if http++; http > 1 {
				diags.AddAttributeError(at.AtName("type"), "more than one http trigger", "A project has at most one http trigger.")
			}
		case runr.TriggerWorkflow:
			if len(list) > 1 {
				diags.AddAttributeError(at.AtName("type"), "workflow trigger with others", "A workflow trigger must be the project's only trigger.")
			}
		}
		if known(t.Secrets) {
			allowed := map[string]bool{}
			for _, k := range runr.TriggerSecretKeys[t.Type.ValueString()] {
				allowed[k] = true
			}
			for k := range t.Secrets.Elements() {
				if !allowed[k] {
					diags.AddAttributeError(at.AtName("secrets"), "unknown trigger secret",
						fmt.Sprintf("A %s trigger has no secret %q.", t.Type.ValueString(), k))
				}
			}
		}
	}
}

func (r *projectResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan projectModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	cfg := plan.configInput(ctx, nil, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	if plan.Type.ValueString() == runr.TypeCaaS && cfg.Image == nil {
		resp.Diagnostics.AddAttributeError(path.Root("image"), "image required", "A caas project needs an image when it is created.")
		return
	}
	in := runr.CreateProjectInput{
		Name:           plan.Name.ValueString(),
		ServiceType:    plan.Type.ValueString(),
		Runtime:        plan.Runtime.ValueString(),
		RuntimeVersion: plan.RuntimeVersion.ValueString(),
		Description:    plan.Description.ValueString(),
		RegionID:       plan.RegionID.ValueString(),
		Config:         cfg,
	}
	if !known(plan.RuntimeVersion) {
		in.RuntimeVersion = ""
	}
	if g, _ := globalConfigured(ctx, plan.Global, &resp.Diagnostics); g != nil {
		in.Placement, in.GlobalMode, in.RegionIDs = runr.PlacementGlobal, g.mode, g.regions
	}
	p, err := r.client.API.CreateProject(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create project failed", err)
		return
	}
	// The create answer may lack relations; read the project back.
	if full, err := r.client.API.GetProject(ctx, p.ID); err == nil {
		p = full
	}
	created := plan
	created.fromAPI(ctx, p, &resp.Diagnostics)
	extras, err := readProjectExtras(ctx, r.client.API, p)
	if err != nil {
		// Keep the project in the state so it is not orphaned (tainted by the error).
		apiError(&resp.Diagnostics, "Read the new project failed", err)
		created.setExtras(ctx, &projectExtras{}, types.ListNull(projectTriggerType), false, &resp.Diagnostics)
		resp.Diagnostics.Append(resp.State.Set(ctx, &created)...)
		return
	}
	r.applyProjectExtras(ctx, &plan, nil, p, extras, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		created.setExtras(ctx, extras, plan.Triggers, false, &resp.Diagnostics)
		resp.Diagnostics.Append(resp.State.Set(ctx, &created)...)
		return
	}
	if full, err := r.client.API.GetProject(ctx, p.ID); err == nil {
		p = full
	}
	planned := plan
	plan.fromAPI(ctx, p, &resp.Diagnostics)
	plan.setExtras(ctx, extras, planned.Triggers, true, &resp.Diagnostics)
	plan.keepPlannedComputed(ctx, &planned, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *projectResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state projectModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	p, err := r.client.API.GetProject(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read project failed", err)
		return
	}
	extras, err := readProjectExtras(ctx, r.client.API, p)
	if err != nil {
		apiError(&resp.Diagnostics, "Read project failed", err)
		return
	}
	prior := state.Triggers
	state.fromAPI(ctx, p, &resp.Diagnostics)
	state.setExtras(ctx, extras, prior, false, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

// ModifyPlan keeps a plan empty when nothing changes and marks what a change of another
// attribute changes as known after apply.
//
// Values left out keep the server's (Optional+Computed with UseStateForUnknown), but Terraform
// proposes null for a left-out nested attribute whose prior value holds values only a
// configuration sets (the rows of scale_schedule, the triggers' types), and the framework then
// marks every computed attribute without a plan modifier unknown — an update that would change
// nothing. When the plan with its unknown values taken from the prior state is the prior state,
// the plan is the prior state.
//
// A configured trigger list that sets what the project has keeps the computed values of its
// triggers; a changed list makes the primary trigger (trigger) unknown, a changed primary
// trigger the list.
func (r *projectResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.Plan.Raw.IsNull() {
		return
	}
	// Region ids from data sources are known now, not when the configuration is validated
	var regions struct {
		RegionID types.String `tfsdk:"region_id"`
		Global   types.Object `tfsdk:"global"`
	}
	resp.Diagnostics.Append(req.Plan.GetAttribute(ctx, path.Root("region_id"), &regions.RegionID)...)
	resp.Diagnostics.Append(req.Plan.GetAttribute(ctx, path.Root("global"), &regions.Global)...)
	validateGlobal(ctx, regions.Global, regions.RegionID, &resp.Diagnostics)
	if req.State.Raw.IsNull() || resp.Diagnostics.HasError() {
		return
	}
	if filledFromState(req.Plan.Raw, req.State.Raw).Equal(req.State.Raw) {
		resp.Plan.Raw = req.State.Raw.Copy()
		return
	}
	var plan, state, config projectModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &config)...)
	if resp.Diagnostics.HasError() {
		return
	}

	// The trigger list and the primary trigger describe the same triggers
	if !config.Triggers.IsNull() {
		planned := triggersModelOf(ctx, plan.Triggers, &resp.Diagnostics)
		prior := triggersModelOf(ctx, state.Triggers, &resp.Diagnostics)
		if !plan.Triggers.IsUnknown() && prior != nil && sameConfiguredTriggers(planned, prior) {
			plan.Triggers = state.Triggers
		} else if config.Trigger.IsNull() {
			plan.Trigger = types.ObjectUnknown(triggerAttrTypes)
		}
	} else if !config.Trigger.IsNull() && changedFromState(ctx, plan.Trigger, state.Trigger) {
		plan.Triggers = types.ListUnknown(projectTriggerType)
	}

	// The API keeps IP ranges for the allowlist mode only
	if known(plan.Network) && known(config.Network) {
		var pn, cn networkModel
		resp.Diagnostics.Append(plan.Network.As(ctx, &pn, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		resp.Diagnostics.Append(config.Network.As(ctx, &cn, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		if known(pn.AccessMode) && pn.AccessMode.ValueString() != runr.AccessAllowlist && cn.AllowedCIDRs.IsNull() &&
			(!known(pn.AllowedCIDRs) || len(pn.AllowedCIDRs.Elements()) > 0) {
			pn.AllowedCIDRs, _ = types.SetValueFrom(ctx, types.StringType, []string{})
			v, d := types.ObjectValueFrom(ctx, networkAttrTypes, pn)
			resp.Diagnostics.Append(d...)
			plan.Network = v
		}
	}

	// A global project's bindings are known after apply: attachments are resources of their own
	// and can change in the same apply
	if known(plan.Global) {
		var g globalModel
		resp.Diagnostics.Append(plan.Global.As(ctx, &g, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		if !g.Bindings.IsUnknown() {
			g.Bindings = types.ListUnknown(globalBindingType)
			v, d := types.ObjectValueFrom(ctx, globalAttrTypes, g)
			resp.Diagnostics.Append(d...)
			plan.Global = v
		}
	}
	resp.Diagnostics.Append(resp.Plan.Set(ctx, &plan)...)
}

func (r *projectResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state projectModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	cur, err := r.client.API.GetProject(ctx, state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read project failed", err)
		return
	}
	cfg := plan.configInput(ctx, &cur.Config, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	var in runr.UpdateProjectInput
	if !cfg.Empty() {
		in.Config = &cfg
		in.ConfigMessage = "terraform"
	}
	if d := plan.Description.ValueString(); d != cur.Description {
		in.Description = &d
	}
	// A global project's primary region changes with a switchover (global), a regional one moves
	if rid := plan.RegionID.ValueString(); rid != cur.RegionID && !cur.IsGlobal() {
		in.RegionID = &rid
	}
	p := cur
	if in.Config != nil || in.Description != nil || in.RegionID != nil {
		unlock := r.client.lock(cur.ID)
		p, err = r.client.API.UpdateProject(ctx, cur.ID, in)
		unlock()
		if err != nil {
			apiError(&resp.Diagnostics, "Update project failed", err)
			return
		}
	}
	extras, err := readProjectExtras(ctx, r.client.API, p)
	if err != nil {
		apiError(&resp.Diagnostics, "Read project failed", err)
		return
	}
	r.applyProjectExtras(ctx, &plan, &state, p, extras, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	if p.IsGlobal() || !plan.Triggers.Equal(state.Triggers) {
		// the primary region, endpoint and primary trigger follow these changes
		if full, err := r.client.API.GetProject(ctx, p.ID); err == nil {
			p = full
		}
	}
	planned := plan
	plan.ID = state.ID
	plan.fromAPI(ctx, p, &resp.Diagnostics)
	plan.setExtras(ctx, extras, planned.Triggers, true, &resp.Diagnostics)
	plan.keepPlannedComputed(ctx, &planned, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *projectResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state projectModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteProject(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete project failed", err)
	}
}

// ImportState accepts a project id or name.
func (r *projectResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	p, err := r.client.API.FindProject(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import project failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), p.ID)...)
}

// configInput returns the config fields of the plan. With cur set (update) only fields that
// differ from the server are returned; lists the plan drops are cleared.
func (m *projectModel) configInput(ctx context.Context, cur *runr.ProjectConfig, diags *diag.Diagnostics) runr.ConfigInput {
	var c runr.ConfigInput
	setStr := func(v types.String, have string, dst **string) {
		if p := strPtr(v); p != nil && (cur == nil || *p != have) {
			*dst = p
		}
	}
	setInt := func(v types.Int64, have *int, dst **int) {
		if p := intPtr(v); p != nil && (cur == nil || have == nil || *p != *have) {
			*dst = p
		}
	}
	var have runr.ProjectConfig
	if cur != nil {
		have = *cur
	}
	setStr(m.Handler, have.Handler, &c.Handler)
	setStr(m.Filename, have.Filename, &c.Filename)
	setStr(m.Image, have.Image, &c.Image)

	if l := stringList(ctx, m.Command, diags); l != nil && (cur == nil || !equalStringPtrs(l, have.Command)) {
		c.Command = l
	} else if l == nil && cur != nil && len(have.Command) > 0 {
		c.Command = &[]string{}
	}
	if l := stringList(ctx, m.Args, diags); l != nil && (cur == nil || !equalStringPtrs(l, have.Args)) {
		c.Args = l
	} else if l == nil && cur != nil && len(have.Args) > 0 {
		c.Args = &[]string{}
	}
	if l := intList(ctx, m.Ports, diags); l != nil {
		if cur == nil || !equalInts(*l, have.Ports) {
			c.Ports = l
		}
	} else if cur != nil && len(have.Ports) > 0 {
		c.Ports = &[]int{}
	}

	if known(m.Resources) {
		var rm resourcesModel
		diags.Append(m.Resources.As(ctx, &rm, basetypes.ObjectAsOptions{})...)
		setStr(rm.CPU, have.CPU, &c.CPU)
		setInt(rm.MemoryMB, have.MemoryMB, &c.MemoryMB)
		setInt(rm.TimeoutSeconds, have.TimeoutSeconds, &c.TimeoutSeconds)
	}
	if known(m.Scale) {
		var sm scaleModel
		diags.Append(m.Scale.As(ctx, &sm, basetypes.ObjectAsOptions{})...)
		setInt(sm.Min, have.MinScale, &c.MinScale)
		setInt(sm.Max, have.MaxScale, &c.MaxScale)
		if b := boolPtr(sm.ToZero); b != nil && (cur == nil || have.ScaleToZero == nil || *b != *have.ScaleToZero) {
			c.ScaleToZero = b
		}
	}
	// The rows replace the schedule; [] turns it off (a new project without rows has none)
	if known(m.ScaleSchedule) {
		rows := scheduleRows(ctx, m.ScaleSchedule, diags)
		if (cur == nil && len(rows) > 0) || (cur != nil && !runr.EqualScaleSchedules(rows, have.ScaleSchedule)) {
			c.ScaleSchedule = &rows
		}
	}
	if known(m.Trigger) {
		var tm triggerModel
		diags.Append(m.Trigger.As(ctx, &tm, basetypes.ObjectAsOptions{})...)
		setStr(tm.Type, have.Trigger(), &c.TriggerType)
		setStr(tm.Schedule, have.Schedule, &c.Schedule)
		setStr(tm.Queue, have.QueueStream, &c.QueueStream)
		// The schedule's zone ("" = the organization's); a trigger that is no schedule has none
		if known(tm.Timezone) && (!known(tm.Type) || tm.Type.ValueString() == runr.TriggerSchedule) {
			setStr(tm.Timezone, have.ScheduleTimezone, &c.ScheduleTimezone)
			if c.ScheduleTimezone != nil && cur == nil && *c.ScheduleTimezone == "" {
				c.ScheduleTimezone = nil // a new schedule follows the organization anyway
			}
		}
		// A schedule or queue the server needs along with a changed type.
		if c.TriggerType != nil {
			if *c.TriggerType == runr.TriggerSchedule && c.Schedule == nil {
				c.Schedule = strPtr(tm.Schedule)
			}
			if *c.TriggerType == runr.TriggerQueue && c.QueueStream == nil {
				c.QueueStream = strPtr(tm.Queue)
			}
		}
	}
	return c
}

// scheduleRows reads scale_schedule (rows with unknown values are skipped); a known empty list
// gives an empty, non-nil slice.
func scheduleRows(ctx context.Context, l types.List, diags *diag.Diagnostics) []runr.ScaleScheduleRow {
	rows := make([]runr.ScaleScheduleRow, 0, len(l.Elements()))
	for _, e := range l.Elements() {
		obj, ok := e.(types.Object)
		if !ok || !known(obj) {
			continue
		}
		var r scheduleRowModel
		diags.Append(obj.As(ctx, &r, basetypes.ObjectAsOptions{})...)
		if !known(r.Cron) || !known(r.MinScale) {
			continue
		}
		rows = append(rows, runr.ScaleScheduleRow{Cron: runr.NormalizeCron(r.Cron.ValueString()), MinScale: int(r.MinScale.ValueInt64())})
	}
	return rows
}

// scheduleList is the server's schedule as state: an empty list when the warmer is off (never
// null, so `scale_schedule = []` in a configuration reads back unchanged).
func scheduleList(ctx context.Context, rows []runr.ScaleScheduleRow, diags *diag.Diagnostics) types.List {
	models := make([]scheduleRowModel, len(rows))
	for i, r := range rows {
		models[i] = scheduleRowModel{Cron: types.StringValue(r.Cron), MinScale: types.Int64Value(int64(r.MinScale))}
	}
	l, d := types.ListValueFrom(ctx, scheduleRowType, models)
	diags.Append(d...)
	return l
}

func equalInts(a []int, b runr.FlexInts) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

// fromAPI writes the server's project into the model.
func (m *projectModel) fromAPI(ctx context.Context, p *runr.Project, diags *diag.Diagnostics) {
	c := p.Config
	m.ID = types.StringValue(p.ID)
	m.Name = types.StringValue(p.Name)
	m.Type = types.StringValue(p.ServiceType)
	m.Runtime = strOrNull(p.Runtime)
	m.RuntimeVersion = strOrNull(p.RuntimeVersion)
	m.RegionID = types.StringValue(p.RegionID)
	if p.Description != "" || !m.Description.IsNull() {
		m.Description = types.StringValue(p.Description)
	}
	m.Handler = strOrNull(c.Handler)
	m.Filename = strOrNull(c.Filename)
	m.Image = strOrNull(c.Image)
	m.Command = listOfStrings(ctx, c.Command, diags)
	m.Args = listOfStrings(ctx, c.Args, diags)
	m.Ports = listOfInts(ctx, c.Ports, diags)
	m.Slug = types.StringValue(p.Slug)
	m.EndpointURL = strOrNull(p.EndpointURL)
	if p.LatestVersion != nil {
		m.LatestVersionID = types.StringValue(p.LatestVersion.ID)
	} else {
		m.LatestVersionID = types.StringNull()
	}

	res, d := types.ObjectValueFrom(ctx, resourcesAttrTypes, resourcesModel{
		CPU: strOrNull(c.CPU), MemoryMB: intOrNull(c.MemoryMB), TimeoutSeconds: intOrNull(c.TimeoutSeconds),
	})
	diags.Append(d...)
	m.Resources = res
	sc, d := types.ObjectValueFrom(ctx, scaleAttrTypes, scaleModel{
		Min: intOrNull(c.MinScale), Max: intOrNull(c.MaxScale), ToZero: boolOrNull(c.ScaleToZero),
	})
	diags.Append(d...)
	m.Scale = sc
	m.ScaleSchedule = scheduleList(ctx, c.ScaleSchedule, diags)
	// trigger.timezone "" (the organization's) reads back as "" while the configuration says so
	var prior triggerModel
	if known(m.Trigger) {
		diags.Append(m.Trigger.As(ctx, &prior, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	}
	tz := types.StringNull()
	if c.Trigger() == runr.TriggerSchedule {
		tz = triggerTimezone(c.ScheduleTimezone, prior.Timezone)
	}
	tr, d := types.ObjectValueFrom(ctx, triggerAttrTypes, triggerModel{
		Type: types.StringValue(c.Trigger()), Schedule: strOrNull(c.Schedule), Queue: strOrNull(c.QueueStream),
		Timezone: tz, EffectiveTimezone: strOrNull(c.ScheduleEffectiveTimezone),
	})
	diags.Append(d...)
	m.Trigger = tr
}
