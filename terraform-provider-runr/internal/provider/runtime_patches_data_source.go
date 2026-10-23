package provider

import (
	"context"
	"errors"

	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerDataSource(NewRuntimePatchesDataSource) }

var _ datasource.DataSourceWithConfigure = &runtimePatchesDataSource{}

// NewRuntimePatchesDataSource returns the runr_runtime_patches data source.
func NewRuntimePatchesDataSource() datasource.DataSource { return &runtimePatchesDataSource{} }

type runtimePatchesDataSource struct{ client *Client }

type runtimePatchesModel struct {
	ProjectID   types.String `tfsdk:"project_id"`
	State       types.String `tfsdk:"state"`
	Level       types.String `tfsdk:"level"`
	DeadlineAt  types.String `tfsdk:"deadline_at"`
	Deployed    types.Bool   `tfsdk:"deployed"`
	Current     types.Object `tfsdk:"current"`
	Latest      types.Object `tfsdk:"latest"`
	Pin         types.Object `tfsdk:"pin"`
	Pending     types.List   `tfsdk:"pending"`
	AutoMigrate types.Bool   `tfsdk:"auto_migrate"`
	Keep        types.Int64  `tfsdk:"keep"`
	Patches     types.List   `tfsdk:"patches"`
}

var (
	patchRefAttrTypes = map[string]attr.Type{"id": types.StringType, "tag": types.StringType, "level": types.StringType,
		"digest": types.StringType, "published_at": types.StringType}
	patchPinAttrTypes = map[string]attr.Type{"id": types.StringType, "tag": types.StringType, "digest": types.StringType,
		"available": types.BoolType, "by": types.StringType}
	patchAttrTypes = map[string]attr.Type{"id": types.StringType, "tag": types.StringType, "level": types.StringType,
		"reason": types.StringType, "cves": types.ListType{ElemType: types.StringType}, "image": types.StringType,
		"digest": types.StringType, "url": types.StringType, "published_at": types.StringType, "deadline_at": types.StringType}
)

func (d *runtimePatchesDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_runtime_patches"
}

func (d *runtimePatchesDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	ref := func(desc string) schema.SingleNestedAttribute {
		return schema.SingleNestedAttribute{Computed: true, MarkdownDescription: desc, Attributes: map[string]schema.Attribute{
			"id":           str("Patch id (null for a digest deployed before patches were known)."),
			"tag":          str("Patch tag, e.g. `exec-php:8.3.36-r1`."),
			"level":        str("`routine`, `security`, `critical` or `zero-day`."),
			"digest":       str("Image digest."),
			"published_at": str("When the patch was published."),
		}}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A project's runtime patch state ([runtime patches](https://runr.sh/docs/runtimes/#runtime-patches)): " +
			"the patch its deployment runs, the newest one, the pin and the newest patches of its runtime version. A report " +
			"to read, e.g. for checks or outputs.\n\n" +
			"Applying, pinning and unpinning a patch stay actions (`runr projects patch apply|pin|unpin`): a pin names one " +
			"of the five newest patches of a feed that keeps moving, and RunR itself lifts or sets pins when a critical or " +
			"zero-day deadline passes, so a pin declared in Terraform would fight RunR.",
		Attributes: map[string]schema.Attribute{
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id."},
			"state": str("`up_to_date`, `patch_available`, `pinned` or `overdue` (a critical / zero-day deadline passed); " +
				"null when no patch concerns the project (a container project, a runtime version without patches)."),
			"level":       str("Most urgent level of the patches the project runs behind."),
			"deadline_at": str("Earliest critical / zero-day deadline of those patches."),
			"deployed":    schema.BoolAttribute{Computed: true, MarkdownDescription: "The project has a live deployment."},
			"current":     ref("The patch the live deployment runs."),
			"latest":      ref("The newest patch of the project's runtime version."),
			"pin": schema.SingleNestedAttribute{Computed: true, MarkdownDescription: "The patch the project is pinned to; null without a pin.",
				Attributes: map[string]schema.Attribute{
					"id":        str("Patch id."),
					"tag":       str("Patch tag."),
					"digest":    str("Image digest."),
					"available": schema.BoolAttribute{Computed: true, MarkdownDescription: "Still one of the newest patches (else the next deploy uses the newest)."},
					"by":        str("Who pinned it (`runr` for the pin of a rolled-back automatic migration)."),
				}},
			"pending":      schema.ListAttribute{Computed: true, ElementType: types.StringType, MarkdownDescription: "Ids of the newer patches the project does not run yet."},
			"auto_migrate": schema.BoolAttribute{Computed: true, MarkdownDescription: "RunR moves projects onto critical / zero-day patches at their deadline."},
			"keep":         schema.Int64Attribute{Computed: true, MarkdownDescription: "How many of the newest patches a project can be pinned to."},
			"patches": schema.ListNestedAttribute{Computed: true,
				MarkdownDescription: "The newest patches of the project's runtime version, newest first.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"id":           str("Patch id."),
					"tag":          str("Patch tag."),
					"level":        str("`routine`, `security`, `critical` or `zero-day`."),
					"reason":       str("Why it came."),
					"cves":         schema.ListAttribute{Computed: true, ElementType: types.StringType, MarkdownDescription: "CVEs it fixes."},
					"image":        str("Image."),
					"digest":       str("Image digest."),
					"url":          str("Release notes."),
					"published_at": str("When it was published."),
					"deadline_at":  str("Deadline of a critical / zero-day patch."),
				}}},
		},
	}
}

func (d *runtimePatchesDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// projectReportUnsupported reports a 404 of a project report (title "Runtime patches", what "runtime
// patches"): the project does not exist, or the API has no such report.
func projectReportUnsupported(ctx context.Context, c *Client, projectID, title, what string, diags *diag.Diagnostics) {
	if _, err := c.API.GetProject(ctx, projectID); err != nil {
		apiError(diags, "Read "+what+" failed", err)
		return
	}
	diags.AddError(title+" not available", "This RunR API has no "+what+".")
}

func patchRefObject(r *runr.PatchRef) types.Object {
	if r == nil {
		return types.ObjectNull(patchRefAttrTypes)
	}
	return types.ObjectValueMust(patchRefAttrTypes, map[string]attr.Value{"id": strOrNull(r.ID), "tag": strOrNull(r.Tag),
		"level": strOrNull(r.Level), "digest": strOrNull(r.Digest), "published_at": strOrNull(r.PublishedAt)})
}

func (d *runtimePatchesDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m runtimePatchesModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	rp, err := d.client.API.ProjectRuntimePatches(ctx, m.ProjectID.ValueString())
	if errors.Is(err, runr.ErrUnsupported) {
		projectReportUnsupported(ctx, d.client, m.ProjectID.ValueString(), "Runtime patches", "runtime patches", &resp.Diagnostics)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read runtime patches failed", err)
		return
	}
	st := rp.State
	if st == nil {
		st = &runr.RuntimePatchState{}
	}
	m.State, m.Level, m.DeadlineAt = strOrNull(st.State), strOrNull(st.Level), strOrNull(st.DeadlineAt)
	m.Deployed = types.BoolValue(st.Deployed)
	m.Current, m.Latest = patchRefObject(st.Current), patchRefObject(st.Latest)
	m.Pin = types.ObjectNull(patchPinAttrTypes)
	if st.Pin != nil {
		m.Pin = types.ObjectValueMust(patchPinAttrTypes, map[string]attr.Value{"id": strOrNull(st.Pin.ID), "tag": strOrNull(st.Pin.Tag),
			"digest": strOrNull(st.Pin.Digest), "available": types.BoolValue(st.Pin.Available), "by": strOrNull(st.Pin.By)})
	}
	pending, diags := types.ListValueFrom(ctx, types.StringType, append([]string{}, st.Pending...))
	resp.Diagnostics.Append(diags...)
	m.Pending = pending
	m.AutoMigrate = types.BoolValue(rp.AutoMigrate)
	m.Keep = types.Int64Value(int64(rp.Keep))
	patches := make([]attr.Value, 0, len(rp.Patches))
	for _, p := range rp.Patches {
		cves, diags := types.ListValueFrom(ctx, types.StringType, append([]string{}, p.CVEs...))
		resp.Diagnostics.Append(diags...)
		patches = append(patches, types.ObjectValueMust(patchAttrTypes, map[string]attr.Value{
			"id": types.StringValue(p.ID), "tag": types.StringValue(p.Tag), "level": strOrNull(p.Level), "reason": strOrNull(p.Reason),
			"cves": cves, "image": strOrNull(p.Image), "digest": strOrNull(p.Digest), "url": strOrNull(p.URL),
			"published_at": strOrNull(p.PublishedAt), "deadline_at": strOrNull(p.DeadlineAt),
		}))
	}
	list, diags := types.ListValue(types.ObjectType{AttrTypes: patchAttrTypes}, patches)
	resp.Diagnostics.Append(diags...)
	m.Patches = list
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
