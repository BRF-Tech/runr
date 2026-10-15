package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework-validators/datasourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/types"
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
	EndpointURL        types.String `tfsdk:"endpoint_url"`
	LatestVersionID    types.String `tfsdk:"latest_version_id"`
	LatestVersionLabel types.String `tfsdk:"latest_version_label"`
	DeploymentStatus   types.String `tfsdk:"deployment_status"`
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
			"id":                   schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Project id."},
			"name":                 schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Project name or slug."},
			"slug":                 str("Slug."),
			"type":                 str("`faas`, `xaas` or `caas`."),
			"runtime":              str("Runtime (code projects)."),
			"runtime_version":      str("Runtime version."),
			"region_id":            str("Region id."),
			"region_name":          str("Region name."),
			"handler":              str("Handler (code projects)."),
			"image":                str("Container image (caas)."),
			"trigger_type":         str("`http`, `schedule` or `queue`."),
			"endpoint_url":         str("Public URL (HTTP trigger)."),
			"latest_version_id":    str("Newest version id."),
			"latest_version_label": str("Newest version label."),
			"deployment_status":    str("Status of the latest deployment."),
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
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
