package provider

import (
	"context"
	"errors"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/datasourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerDataSource(NewRuntimeSourceDataSource) }

var (
	_ datasource.DataSourceWithConfigure        = &runtimeSourceDataSource{}
	_ datasource.DataSourceWithConfigValidators = &runtimeSourceDataSource{}
)

// NewRuntimeSourceDataSource returns the runr_runtime_source data source.
func NewRuntimeSourceDataSource() datasource.DataSource { return &runtimeSourceDataSource{} }

// A runtime source and the runtimes it gives (GET /runtime-sources/{source}): any connected
// source, RunR's catalog (the default source, which no resource manages) too.
type runtimeSourceDataSource struct{ client *Client }

type runtimeSourceDataModel struct {
	ID                types.String `tfsdk:"id"`
	Name              types.String `tfsdk:"name"`
	IsDefault         types.Bool   `tfsdk:"is_default"`
	Kind              types.String `tfsdk:"kind"`
	Scope             types.String `tfsdk:"scope"`
	URL               types.String `tfsdk:"url"`
	Ref               types.String `tfsdk:"ref"`
	Path              types.String `tfsdk:"path"`
	PublicKey         types.String `tfsdk:"public_key"`
	PatchFeed         types.String `tfsdk:"patch_feed"`
	RegistryHost      types.String `tfsdk:"registry_host"`
	IntervalMinutes   types.Int64  `tfsdk:"interval_minutes"`
	Enabled           types.Bool   `tfsdk:"enabled"`
	Credentials       types.Object `tfsdk:"credentials"`
	Status            types.String `tfsdk:"status"`
	Error             types.String `tfsdk:"error"`
	Problems          types.List   `tfsdk:"problems"`
	Revision          types.String `tfsdk:"revision"`
	SignatureVerified types.Bool   `tfsdk:"signature_verified"`
	RuntimesCount     types.Int64  `tfsdk:"runtimes_count"`
	CheckedAt         types.String `tfsdk:"checked_at"`
	SyncedAt          types.String `tfsdk:"synced_at"`
	Runtimes          types.List   `tfsdk:"runtimes"`
}

var (
	runtimeSourceVersionAttrTypes = map[string]attr.Type{
		"version": types.StringType, "is_default": types.BoolType, "state": types.StringType, "eol_at": types.StringType,
		"deprecated_at": types.StringType, "sunset_at": types.StringType, "ends_at": types.StringType,
	}
	runtimeSourceRuntimeAttrTypes = map[string]attr.Type{
		"id": types.StringType, "name": types.StringType, "path": types.StringType, "state": types.StringType,
		"deprecated_at": types.StringType, "sunset_at": types.StringType, "orphaned_at": types.StringType,
		"replacement": types.StringType, "notice": types.StringType, "projects_count": types.Int64Type,
		"versions": types.ListType{ElemType: types.ObjectType{AttrTypes: runtimeSourceVersionAttrTypes}},
	}
)

func (d *runtimeSourceDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_runtime_source"
}

func (d *runtimeSourceDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	creds := map[string]schema.Attribute{}
	for k, desc := range runtimeSourceCredentialsAttributes() {
		if strings.HasSuffix(k, "_set") {
			creds[k] = schema.BoolAttribute{Computed: true, MarkdownDescription: desc}
		} else {
			creds[k] = str(desc)
		}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A runtime source ([runtime sources](https://runr.sh/docs/runtimes/#runtime-sources)) and the " +
			"runtimes it gives, each with its lifecycle: any source the token sees (`runtimes.manage`: every one; " +
			"`runtimes.use_custom`: the organization's), RunR's own catalog of built-in runtimes too (`name = \"RunR runtimes\"`, " +
			"`is_default`). Look it up by `name` or `id`. A report to read, e.g. to check that a source was read " +
			"(`status`) or to pick a runtime id for `runr_project.runtime`.",
		Attributes: map[string]schema.Attribute{
			"id":                 schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Source id."},
			"name":               schema.StringAttribute{Optional: true, Computed: true, MarkdownDescription: "Source name (any case)."},
			"is_default":         schema.BoolAttribute{Computed: true, MarkdownDescription: "RunR's own catalog (the built-in runtimes)."},
			"kind":               str("`git` or `oci-catalog`."),
			"scope":              str("`platform` or `organization`."),
			"url":                str("Repository or catalog reference."),
			"ref":                str("`git`: branch, tag or commit."),
			"path":               str("`git`: directory of the runtimes."),
			"public_key":         str("cosign public key the catalog must verify with."),
			"patch_feed":         str("The source's runtime patch feed."),
			"registry_host":      str("Registry the registry credentials are for."),
			"interval_minutes":   schema.Int64Attribute{Computed: true, MarkdownDescription: "Minutes between reads."},
			"enabled":            schema.BoolAttribute{Computed: true, MarkdownDescription: "RunR reads the source on its interval."},
			"credentials":        schema.SingleNestedAttribute{Computed: true, Attributes: creds, MarkdownDescription: "Which credentials the source has (never a value)."},
			"status":             str("The last read: `never`, `ok`, `partial`, `unreachable`, `not_found`, `auth_failed`, `invalid_signature`, `invalid_catalog` or `disabled`."),
			"error":              str("Why the last read failed."),
			"revision":           str("Catalog digest or commit of the last good read."),
			"signature_verified": schema.BoolAttribute{Computed: true, MarkdownDescription: "The last read's catalog verified."},
			"runtimes_count":     schema.Int64Attribute{Computed: true, MarkdownDescription: "Runtimes the source gives."},
			"checked_at":         str("When RunR last read the source."),
			"synced_at":          str("When the last good read was."),
			"problems": schema.ListNestedAttribute{Computed: true,
				MarkdownDescription: "The problems of the last read.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"file": str("The file."), "runtime": str("The runtime id or directory."),
					"path": str("JSON path in the manifest."), "message": str("The problem."),
				}}},
			"runtimes": schema.ListNestedAttribute{Computed: true,
				MarkdownDescription: "The runtimes of the source, each with its lifecycle ([lifecycle](https://runr.sh/docs/runtimes/#lifecycle)).",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"id":             str("Runtime id (`runr_project.runtime`)."),
					"name":           str("Display name."),
					"path":           str("Its directory in the source."),
					"state":          str("`active`, `deprecated`, `sunset` or `orphaned`."),
					"deprecated_at":  str("Deprecated from this date."),
					"sunset_at":      str("After this date its projects can no longer be created, saved or deployed."),
					"orphaned_at":    str("When it left its source."),
					"replacement":    str("The runtime RunR suggests instead."),
					"notice":         str("Why it is deprecated or sunset."),
					"projects_count": schema.Int64Attribute{Computed: true, MarkdownDescription: "Projects that use it."},
					"versions": schema.ListNestedAttribute{Computed: true, MarkdownDescription: "Its versions.",
						NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
							"version":       str("Version."),
							"is_default":    schema.BoolAttribute{Computed: true, MarkdownDescription: "The default version."},
							"state":         str("`active`, `deprecated`, `eol` or `sunset`."),
							"eol_at":        str("End of life."),
							"deprecated_at": str("Deprecated from this date."),
							"sunset_at":     str("Sunset."),
							"ends_at":       str("The earlier of `eol_at` and `sunset_at`: the version leaves the picker, its projects can no longer be saved or deployed."),
						}}},
				}}},
		},
	}
}

func (d *runtimeSourceDataSource) ConfigValidators(context.Context) []datasource.ConfigValidator {
	return []datasource.ConfigValidator{
		datasourcevalidator.ExactlyOneOf(path.MatchRoot("id"), path.MatchRoot("name")),
	}
}

func (d *runtimeSourceDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (d *runtimeSourceDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m runtimeSourceDataModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	ref := m.ID.ValueString()
	if ref == "" {
		ref = m.Name.ValueString()
	}
	s, err := d.client.API.FindRuntimeSource(ctx, ref)
	if errors.Is(err, runr.ErrUnsupported) {
		resp.Diagnostics.AddError("Runtime sources not available", "This RunR API has no runtime sources.")
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read runtime source failed", err)
		return
	}
	detail, err := d.client.API.GetRuntimeSource(ctx, s.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Read runtime source failed", err)
		return
	}
	src := detail.Source
	m.ID, m.Name, m.IsDefault = types.StringValue(src.ID), types.StringValue(src.Name), types.BoolValue(src.IsDefault)
	m.Kind, m.Scope, m.URL = types.StringValue(src.Kind), types.StringValue(src.Scope), types.StringValue(src.URL)
	m.Ref, m.Path, m.PublicKey = strOrNull(src.Ref), strOrNull(src.Path), strOrNull(src.PublicKey)
	m.PatchFeed, m.RegistryHost = strOrNull(src.PatchFeed), strOrNull(src.RegistryHost)
	m.IntervalMinutes, m.Enabled = types.Int64Value(int64(src.IntervalMinutes)), types.BoolValue(src.IsEnabled)
	m.Credentials = runtimeSourceCredentialsValue(src.Credentials, &resp.Diagnostics)
	m.Status, m.Error = types.StringValue(src.Status), strOrNull(src.Error)
	m.Problems = runtimeSourceProblemsValue(src.Problems, &resp.Diagnostics)
	m.Revision, m.SignatureVerified = strOrNull(src.Revision), types.BoolValue(src.SignatureVerified)
	m.RuntimesCount = types.Int64Value(int64(src.RuntimesCount))
	m.CheckedAt, m.SyncedAt = strOrNull(src.CheckedAt), strOrNull(src.SyncedAt)
	m.Runtimes = runtimeSourceRuntimesValue(detail.Runtimes, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

func runtimeSourceRuntimesValue(list []runr.CatalogRuntime, diags *diag.Diagnostics) types.List {
	versionType := types.ObjectType{AttrTypes: runtimeSourceVersionAttrTypes}
	items := make([]attr.Value, 0, len(list))
	for i := range list {
		rt := &list[i]
		lc := rt.Lifecycle
		if lc == nil {
			lc = &runr.RuntimeLifecycle{}
		}
		versions := make([]attr.Value, 0, len(rt.Versions))
		for j := range rt.Versions {
			v := &rt.Versions[j]
			versions = append(versions, types.ObjectValueMust(runtimeSourceVersionAttrTypes, map[string]attr.Value{
				"version": types.StringValue(v.Version), "is_default": types.BoolValue(v.IsDefault), "state": strOrNull(rt.State(v)),
				"eol_at": strOrNull(v.EOLAt), "deprecated_at": strOrNull(v.DeprecatedAt), "sunset_at": strOrNull(v.SunsetAt),
				"ends_at": strOrNull(v.EndsAt),
			}))
		}
		vl, d := types.ListValue(versionType, versions)
		diags.Append(d...)
		items = append(items, types.ObjectValueMust(runtimeSourceRuntimeAttrTypes, map[string]attr.Value{
			"id": types.StringValue(rt.ID), "name": types.StringValue(rt.Name), "path": strOrNull(rt.SourcePath),
			"state": strOrNull(lc.State), "deprecated_at": strOrNull(lc.DeprecatedAt), "sunset_at": strOrNull(lc.SunsetAt),
			"orphaned_at": strOrNull(lc.OrphanedAt), "replacement": strOrNull(lc.Replacement), "notice": strOrNull(lc.Notice),
			"projects_count": types.Int64Value(int64(rt.ProjectsCount)), "versions": vl,
		}))
	}
	l, d := types.ListValue(types.ObjectType{AttrTypes: runtimeSourceRuntimeAttrTypes}, items)
	diags.Append(d...)
	return l
}
