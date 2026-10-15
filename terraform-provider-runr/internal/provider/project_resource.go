package provider

import (
	"context"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/objectplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/runrsh/core/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &projectResource{}
	_ resource.ResourceWithConfigure      = &projectResource{}
	_ resource.ResourceWithImportState    = &projectResource{}
	_ resource.ResourceWithValidateConfig = &projectResource{}
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
	Trigger         types.Object `tfsdk:"trigger"`
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
	Type     types.String `tfsdk:"type"`
	Schedule types.String `tfsdk:"schedule"`
	Queue    types.String `tfsdk:"queue"`
}

var (
	resourcesAttrTypes = map[string]attr.Type{"cpu": types.StringType, "memory_mb": types.Int64Type, "timeout_seconds": types.Int64Type}
	scaleAttrTypes     = map[string]attr.Type{"min": types.Int64Type, "max": types.Int64Type, "to_zero": types.BoolType}
	triggerAttrTypes   = map[string]attr.Type{"type": types.StringType, "schedule": types.StringType, "queue": types.StringType}
)

var projectNamePattern = regexp.MustCompile(`^[A-Za-z][-_ A-Za-z0-9]*$`)

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
			"trigger": schema.SingleNestedAttribute{
				Optional: true, Computed: true, MarkdownDescription: "What starts the code.",
				PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				Attributes: map[string]schema.Attribute{
					"type": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "`http` (default), `schedule` or `queue`.",
						Validators:          []validator.String{stringvalidator.OneOf(runr.TriggerHTTP, runr.TriggerSchedule, runr.TriggerQueue)}},
					"schedule": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "Cron expression of a `schedule` trigger, e.g. `*/5 * * * *`."},
					"queue": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
						MarkdownDescription: "Redis stream of a `queue` trigger (the region needs Redis)."},
				},
			},
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
	if resp.Diagnostics.HasError() || m.Type.IsUnknown() {
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
	p, err := r.client.API.CreateProject(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create project failed", err)
		return
	}
	// The create answer may lack relations; read the project back.
	if full, err := r.client.API.GetProject(ctx, p.ID); err == nil {
		p = full
	}
	plan.fromAPI(ctx, p, &resp.Diagnostics)
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
	state.fromAPI(ctx, p, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
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
	if rid := plan.RegionID.ValueString(); rid != cur.RegionID {
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
	plan.ID = state.ID
	plan.fromAPI(ctx, p, &resp.Diagnostics)
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
	if known(m.Trigger) {
		var tm triggerModel
		diags.Append(m.Trigger.As(ctx, &tm, basetypes.ObjectAsOptions{})...)
		setStr(tm.Type, have.Trigger(), &c.TriggerType)
		setStr(tm.Schedule, have.Schedule, &c.Schedule)
		setStr(tm.Queue, have.QueueStream, &c.QueueStream)
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
	tr, d := types.ObjectValueFrom(ctx, triggerAttrTypes, triggerModel{
		Type: types.StringValue(c.Trigger()), Schedule: strOrNull(c.Schedule), Queue: strOrNull(c.QueueStream),
	})
	diags.Append(d...)
	m.Trigger = tr
}
