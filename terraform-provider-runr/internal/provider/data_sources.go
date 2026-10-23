package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework-validators/datasourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ datasource.DataSourceWithConfigure        = &regionDataSource{}
	_ datasource.DataSourceWithConfigValidators = &regionDataSource{}
	_ datasource.DataSourceWithConfigure        = &projectDataSource{}
	_ datasource.DataSourceWithConfigValidators = &projectDataSource{}
)

// NewRegionDataSource returns the runr_region data source.
func NewRegionDataSource() datasource.DataSource { return &regionDataSource{} }

type regionDataSource struct{ client *Client }

type regionModel struct {
	ID       types.String `tfsdk:"id"`
	Name     types.String `tfsdk:"name"`
	IsActive types.Bool   `tfsdk:"is_active"`
	Status   types.String `tfsdk:"status"`
	IsDemo   types.Bool   `tfsdk:"is_demo"`
	HasRedis types.Bool   `tfsdk:"has_redis"`
}

func (d *regionDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_region"
}

func (d *regionDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	resp.Schema = schema.Schema{
		MarkdownDescription: "A region (Kubernetes cluster with Knative) projects run in. Look it up by `name` or `id`; " +
			"with neither, the only active region is returned.",
		Attributes: map[string]schema.Attribute{
			"id":        schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Region id."},
			"name":      schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Region name."},
			"is_active": schema.BoolAttribute{Computed: true, MarkdownDescription: "Projects can be deployed to it."},
			"status":    schema.StringAttribute{Computed: true, MarkdownDescription: "Region status (`active`, `maintenance`, …)."},
			"is_demo":   schema.BoolAttribute{Computed: true, MarkdownDescription: "Demo region (deployments are simulated)."},
			"has_redis": schema.BoolAttribute{Computed: true, MarkdownDescription: "Redis is configured (needed by queue triggers)."},
		},
	}
}

func (d *regionDataSource) ConfigValidators(context.Context) []datasource.ConfigValidator {
	return []datasource.ConfigValidator{
		datasourcevalidator.Conflicting(path.MatchRoot("id"), path.MatchRoot("name")),
	}
}

func (d *regionDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (d *regionDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m regionModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	ref := m.ID.ValueString()
	if ref == "" {
		ref = m.Name.ValueString()
	}
	r, err := d.client.API.FindRegion(ctx, ref)
	if err != nil {
		apiError(&resp.Diagnostics, "Region lookup failed", err)
		return
	}
	m.ID = types.StringValue(r.ID)
	m.Name = types.StringValue(r.Name)
	m.IsActive = types.BoolValue(r.IsActive)
	m.Status = types.StringValue(r.Status())
	m.IsDemo = types.BoolValue(r.IsDemo)
	m.HasRedis = types.BoolValue(r.HasRedis)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

// NewProjectDataSource returns the runr_project data source.
func NewProjectDataSource() datasource.DataSource { return &projectDataSource{} }

type projectDataSource struct{ client *Client }

type projectDataModel struct {
	ID                 types.String `tfsdk:"id"`
	Name               types.String `tfsdk:"name"`
	Slug               types.String `tfsdk:"slug"`
	Type               types.String `tfsdk:"type"`
	Runtime            types.String `tfsdk:"runtime"`
	RuntimeVersion     types.String `tfsdk:"runtime_version"`
	RegionID           types.String `tfsdk:"region_id"`
	RegionName         types.String `tfsdk:"region_name"`
	Handler            types.String `tfsdk:"handler"`
	Image              types.String `tfsdk:"image"`
	TriggerType        types.String `tfsdk:"trigger_type"`
	ScheduleTimezone   types.String `tfsdk:"schedule_timezone"`
	EffectiveTimezone  types.String `tfsdk:"schedule_effective_timezone"`
	EndpointURL        types.String `tfsdk:"endpoint_url"`
	LatestVersionID    types.String `tfsdk:"latest_version_id"`
	LatestVersionLabel types.String `tfsdk:"latest_version_label"`
	DeploymentStatus   types.String `tfsdk:"deployment_status"`
	ScaleSchedule      types.List   `tfsdk:"scale_schedule"`
	WarmScaleApplied   types.Int64  `tfsdk:"warm_scale_applied"`
	Triggers           types.List   `tfsdk:"triggers"`
	AccessMode         types.String `tfsdk:"access_mode"`
	InternalName       types.String `tfsdk:"internal_name"`
	InternalURL        types.String `tfsdk:"internal_url"`
	DeployStrategy     types.String `tfsdk:"deploy_strategy"`
	Placement          types.String `tfsdk:"placement"`
	GlobalRegions      types.List   `tfsdk:"global_regions"`
}

// dataTriggerAttrTypes are a trigger of the data source's triggers (no secrets).
var dataTriggerAttrTypes = map[string]attr.Type{
	"name": types.StringType, "type": types.StringType, "enabled": types.BoolType, "config": types.StringType,
	"primary": types.BoolType, "runs_on": types.StringType, "url": types.StringType, "effective_timezone": types.StringType,
}

func (d *projectDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_project"
}

func (d *projectDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "An existing project, looked up by `name` (or slug) or `id`.",
		Attributes: map[string]schema.Attribute{
			"id":                          schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Project id."},
			"name":                        schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Project name or slug."},
			"slug":                        str("Slug."),
			"type":                        str("`faas`, `xaas` or `caas`."),
			"runtime":                     str("Runtime (code projects)."),
			"runtime_version":             str("Runtime version."),
			"region_id":                   str("Region id."),
			"region_name":                 str("Region name."),
			"handler":                     str("Handler (code projects)."),
			"image":                       str("Container image (caas)."),
			"trigger_type":                str("Type of the primary trigger, the first enabled one of the project's trigger list: `http`, `schedule`, `queue`, `workflow` or another trigger type (`webhook`, `topic`, …)."),
			"schedule_timezone":           str("IANA time zone of the schedule trigger; null when it follows the organization's time zone."),
			"schedule_effective_timezone": str("The time zone the schedule trigger runs in; null without a schedule."),
			"endpoint_url":                str("Public URL (HTTP trigger)."),
			"latest_version_id":           str("Newest version id."),
			"latest_version_label":        str("Newest version label."),
			"deployment_status":           str("Status of the latest deployment."),
			"scale_schedule": schema.ListNestedAttribute{
				Computed: true, MarkdownDescription: timedWarmerDescription + " Empty when the warmer is off.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"cron":      schema.StringAttribute{Computed: true, MarkdownDescription: "Five-field cron expression."},
					"min_scale": schema.Int64Attribute{Computed: true, MarkdownDescription: "Containers kept warm from this moment on."},
				}},
			},
			"warm_scale_applied": schema.Int64Attribute{Computed: true,
				MarkdownDescription: "Warm containers the timed warmer last set on the running workload; null when it never ran for the project."},
			"triggers": schema.ListNestedAttribute{Computed: true,
				MarkdownDescription: "The project's trigger list in order (null on APIs without one); secrets are never read.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"name":               str("Trigger name."),
					"type":               str("Trigger type."),
					"enabled":            schema.BoolAttribute{Computed: true, MarkdownDescription: "Whether it runs."},
					"config":             str("Its settings as RunR stores them (JSON)."),
					"primary":            schema.BoolAttribute{Computed: true, MarkdownDescription: "Whether it is the primary trigger."},
					"runs_on":            str("`service`, `gateway`, `cronjob` or `disabled`."),
					"url":                str("The inbox URL of a `webhook` trigger."),
					"effective_timezone": str("The time zone a `schedule` runs in."),
				}}},
			"access_mode":     str("Who reaches the project: `public`, `allowlist` or `internal` (null on APIs without network settings)."),
			"internal_name":   str("Internal name `<project>.<organization>`, for another project's `network.callers`."),
			"internal_url":    str("The URL other projects of the region call it at on the internal network."),
			"deploy_strategy": str("How deploys roll out: `direct`, `blue_green` or `canary`."),
			"placement":       str("`regional` or `global`."),
			"global_regions": schema.ListAttribute{ElementType: types.StringType, Computed: true,
				MarkdownDescription: "Region ids of a global project in HA order; null for a regional project."},
		},
	}
}

func (d *projectDataSource) ConfigValidators(context.Context) []datasource.ConfigValidator {
	return []datasource.ConfigValidator{
		datasourcevalidator.ExactlyOneOf(path.MatchRoot("id"), path.MatchRoot("name")),
	}
}

func (d *projectDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (d *projectDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m projectDataModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	ref := m.ID.ValueString()
	if ref == "" {
		ref = m.Name.ValueString()
	}
	p, err := d.client.API.FindProject(ctx, ref)
	if err != nil {
		apiError(&resp.Diagnostics, "Project lookup failed", err)
		return
	}
	m.ID = types.StringValue(p.ID)
	m.Name = types.StringValue(p.Name)
	m.Slug = types.StringValue(p.Slug)
	m.Type = types.StringValue(p.ServiceType)
	m.Runtime = strOrNull(p.Runtime)
	m.RuntimeVersion = strOrNull(p.RuntimeVersion)
	m.RegionID = types.StringValue(p.RegionID)
	m.RegionName = strOrNull(p.RegionName())
	m.Handler = strOrNull(p.Config.Handler)
	m.Image = strOrNull(p.Config.Image)
	m.TriggerType = types.StringValue(p.Config.Trigger())
	m.ScheduleTimezone = strOrNull(p.Config.ScheduleTimezone)
	m.EffectiveTimezone = strOrNull(p.Config.ScheduleEffectiveTimezone)
	m.EndpointURL = strOrNull(p.EndpointURL)
	m.LatestVersionID, m.LatestVersionLabel = types.StringNull(), types.StringNull()
	if p.LatestVersion != nil {
		m.LatestVersionID = types.StringValue(p.LatestVersion.ID)
		m.LatestVersionLabel = types.StringValue(p.LatestVersion.Label)
	}
	m.DeploymentStatus = types.StringNull()
	if p.LatestDeployment != nil {
		m.DeploymentStatus = types.StringValue(p.LatestDeployment.Status)
	}
	m.ScaleSchedule = scheduleList(ctx, p.Config.ScaleSchedule, &resp.Diagnostics)
	m.WarmScaleApplied = types.Int64Null()
	if p.WarmScale != nil {
		m.WarmScaleApplied = intOrNull(p.WarmScale.Applied)
	}
	extras, err := readProjectExtras(ctx, d.client.API, p)
	if err != nil {
		apiError(&resp.Diagnostics, "Project lookup failed", err)
		return
	}
	m.setExtras(ctx, p, extras, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

// setExtras writes the trigger list, network, deploy strategy and placement (null where the API
// has none).
func (m *projectDataModel) setExtras(ctx context.Context, p *runr.Project, e *projectExtras, diags *diag.Diagnostics) {
	dataTriggerType := types.ObjectType{AttrTypes: dataTriggerAttrTypes}
	m.Triggers = types.ListNull(dataTriggerType)
	if e.triggers != nil {
		items := make([]attr.Value, 0, len(e.triggers.Triggers))
		for _, t := range e.triggers.Triggers {
			o, d := types.ObjectValue(dataTriggerAttrTypes, map[string]attr.Value{
				"name": types.StringValue(t.Name), "type": types.StringValue(t.Type), "enabled": types.BoolValue(t.Enabled),
				"config": types.StringValue(canonicalJSON(t.Config)), "primary": types.BoolValue(t.Primary),
				"runs_on": strOrNull(t.RunsOn), "url": strOrNull(t.URL), "effective_timezone": strOrNull(t.EffectiveTimezone),
			})
			diags.Append(d...)
			items = append(items, o)
		}
		l, d := types.ListValue(dataTriggerType, items)
		diags.Append(d...)
		m.Triggers = l
	}
	m.AccessMode, m.InternalName, m.InternalURL = types.StringNull(), types.StringNull(), types.StringNull()
	if e.network != nil {
		m.AccessMode = strOrNull(e.network.AccessMode)
		m.InternalName = strOrNull(e.network.InternalName)
		m.InternalURL = strOrNull(e.network.InternalURL)
	}
	m.DeployStrategy = types.StringNull()
	if e.strategy != nil {
		m.DeployStrategy = types.StringValue(e.strategy.Strategy)
		if e.strategy.Strategy == "" {
			m.DeployStrategy = types.StringValue(runr.StrategyDirect)
		}
	}
	m.Placement = types.StringValue(runr.PlacementRegional)
	m.GlobalRegions = types.ListNull(types.StringType)
	if p.IsGlobal() {
		m.Placement = types.StringValue(runr.PlacementGlobal)
		if e.global != nil {
			ids := make([]string, 0, len(e.global.Regions))
			for _, r := range e.global.Regions {
				ids = append(ids, r.RegionID)
			}
			l, d := types.ListValueFrom(ctx, types.StringType, ids)
			diags.Append(d...)
			m.GlobalRegions = l
		}
	}
}
