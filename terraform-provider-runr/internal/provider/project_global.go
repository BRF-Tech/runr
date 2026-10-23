package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/objectplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// A global project (runr_project.global, docs/global-projects.md): one project in several
// regions behind one host name. A project is created global (POST /projects with placement
// global) or regional; its regions, mode and failover setting change in place
// (PUT /projects/{project}/global), its primary region is region_id (a switchover).

type globalModel struct {
	Regions              types.List   `tfsdk:"regions"`
	Mode                 types.String `tfsdk:"mode"`
	Failover             types.String `tfsdk:"failover"`
	FailoverAfterSeconds types.Int64  `tfsdk:"failover_after_seconds"`
	Hostname             types.String `tfsdk:"hostname"`
	Bindings             types.List   `tfsdk:"bindings"`
}

type globalBindingModel struct {
	BindingID    types.String `tfsdk:"binding_id"`
	Name         types.String `tfsdk:"name"`
	Type         types.String `tfsdk:"type"`
	Access       types.String `tfsdk:"access"`
	HomeRegionID types.String `tfsdk:"home_region_id"`
}

var (
	globalBindingAttrTypes = map[string]attr.Type{
		"binding_id": types.StringType, "name": types.StringType, "type": types.StringType, "access": types.StringType,
		"home_region_id": types.StringType,
	}
	globalBindingType = types.ObjectType{AttrTypes: globalBindingAttrTypes}
	globalAttrTypes   = map[string]attr.Type{
		"regions": types.ListType{ElemType: types.StringType}, "mode": types.StringType, "failover": types.StringType,
		"failover_after_seconds": types.Int64Type, "hostname": types.StringType,
		"bindings": types.ListType{ElemType: globalBindingType},
	}
)

func globalSchema() schema.SingleNestedAttribute {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	return schema.SingleNestedAttribute{
		Optional: true, Computed: true,
		MarkdownDescription: "A global project ([global projects](https://runr.sh/docs/global-projects/)): the project runs in " +
			"every region of `regions` behind one host name, `region_id` is its primary region. A project is created global or " +
			"regional: adding `global` to a regional project replaces it. Left out, a global project stays as it is. The regions " +
			"need global DNS.",
		PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown(), globalPlacementReplace{}},
		Attributes: map[string]schema.Attribute{
			"regions": schema.ListAttribute{ElementType: types.StringType, Required: true,
				MarkdownDescription: "Region ids in HA order (2-8), `region_id` (the primary) among them. A region left out has its " +
					"copy removed; a new one gets the active version.",
				Validators: []validator.List{listvalidator.SizeBetween(2, runr.MaxGlobalRegions), listvalidator.UniqueValues()}},
			"mode": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`ha` (default: DNS answers the primary while it is healthy, the others when it is not) or " +
					"`proximity` (the healthy region closest to the client).",
				Validators: []validator.String{stringvalidator.OneOf(runr.GlobalModes...)}},
			"failover": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`automatic` (default: RunR moves the primary when its health check keeps failing) or " +
					"`manual` (`runr projects global failover`).",
				Validators: []validator.String{stringvalidator.OneOf(runr.GlobalFailovers...)}},
			"failover_after_seconds": schema.Int64Attribute{Computed: true,
				PlanModifiers:       []planmodifier.Int64{int64planmodifier.UseStateForUnknown()},
				MarkdownDescription: "How long the primary's health check fails before an automatic failover."},
			"hostname": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "The global host name `<slug>.<zone>` (empty while the platform has no base domain)."},
			"bindings": schema.ListNestedAttribute{Computed: true,
				MarkdownDescription: "How the project's bindings are reached from every region: `access` is `direct` (an " +
					"external binding), `link` (the data link to its home region) or `replicas` (a replica cluster in every region).",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"binding_id":     schema.StringAttribute{Computed: true, MarkdownDescription: "Binding id."},
					"name":           schema.StringAttribute{Computed: true, MarkdownDescription: "Binding name."},
					"type":           schema.StringAttribute{Computed: true, MarkdownDescription: "Binding type."},
					"access":         schema.StringAttribute{Computed: true, MarkdownDescription: "`direct`, `link` or `replicas`."},
					"home_region_id": schema.StringAttribute{Computed: true, MarkdownDescription: "The region its data lives in."},
				}}},
		},
	}
}

// globalPlacementReplace replaces a regional project that gets a global block: a project is
// created global or regional.
type globalPlacementReplace struct{}

func (globalPlacementReplace) Description(context.Context) string {
	return "a regional project that gets a global block is created again as a global project"
}

func (m globalPlacementReplace) MarkdownDescription(ctx context.Context) string {
	return m.Description(ctx)
}

func (globalPlacementReplace) PlanModifyObject(_ context.Context, req planmodifier.ObjectRequest, resp *planmodifier.ObjectResponse) {
	if req.State.Raw.IsNull() || req.ConfigValue.IsNull() || !req.StateValue.IsNull() {
		return
	}
	resp.RequiresReplace = true
}

type globalSettings struct {
	regions  []string
	mode     string
	failover string
}

// globalConfigured reads the configured global block (nil when it is null or unknown).
func globalConfigured(ctx context.Context, v types.Object, diags *diag.Diagnostics) (*globalSettings, bool) {
	if !known(v) {
		return nil, false
	}
	var m globalModel
	diags.Append(v.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	out := &globalSettings{}
	complete := true
	if known(m.Regions) {
		for _, e := range m.Regions.Elements() {
			s, ok := e.(types.String)
			if !ok || !known(s) {
				complete = false
				continue
			}
			out.regions = append(out.regions, s.ValueString())
		}
	} else {
		complete = false
	}
	if known(m.Mode) {
		out.mode = m.Mode.ValueString()
	}
	if known(m.Failover) {
		out.failover = m.Failover.ValueString()
	}
	return out, complete
}

// validateGlobal checks that the primary region is one of the regions.
func validateGlobal(ctx context.Context, cfg types.Object, regionID types.String, diags *diag.Diagnostics) {
	g, complete := globalConfigured(ctx, cfg, diags)
	if g == nil || !complete || !known(regionID) {
		return
	}
	for _, id := range g.regions {
		if id == regionID.ValueString() {
			return
		}
	}
	diags.AddAttributeError(path.Root("global").AtName("regions"), "region_id missing from global.regions",
		"The primary region (region_id) must be one of the global project's regions.")
}

// globalUpdate is what the configured global block (and the primary region) change against the
// server's global project.
func globalUpdate(g *globalSettings, primary string, cur *runr.ProjectGlobal) runr.GlobalUpdateInput {
	var in runr.GlobalUpdateInput
	if g == nil || cur == nil {
		return in
	}
	if g.mode != "" && g.mode != cur.Mode {
		in.Mode = &g.mode
	}
	if g.failover != "" && g.failover != cur.Failover {
		in.Failover = &g.failover
	}
	have := make([]string, 0, len(cur.Regions))
	for _, r := range cur.Regions {
		have = append(have, r.RegionID)
	}
	if len(g.regions) > 0 && !equalStrings(g.regions, have) {
		in.RegionIDs = g.regions
	}
	if primary != "" && primary != cur.PrimaryRegionID {
		in.PrimaryRegionID = &primary
	}
	return in
}

func globalUpdateEmpty(in runr.GlobalUpdateInput) bool {
	return in.Mode == nil && in.Failover == nil && in.RegionIDs == nil && in.PrimaryRegionID == nil
}

// globalValue is the server's global project as the attribute's value (null for a regional one).
func globalValue(ctx context.Context, g *runr.ProjectGlobal, diags *diag.Diagnostics) types.Object {
	if g == nil {
		return types.ObjectNull(globalAttrTypes)
	}
	regions := make([]string, 0, len(g.Regions))
	for _, r := range g.Regions {
		regions = append(regions, r.RegionID)
	}
	regionList, d := types.ListValueFrom(ctx, types.StringType, regions)
	diags.Append(d...)
	bindings := make([]globalBindingModel, 0, len(g.Bindings))
	for _, b := range g.Bindings {
		bindings = append(bindings, globalBindingModel{
			BindingID: types.StringValue(b.BindingID), Name: types.StringValue(b.Name), Type: types.StringValue(b.Type),
			Access: types.StringValue(b.Access), HomeRegionID: strOrNull(b.HomeRegionID),
		})
	}
	bindingList, d := types.ListValueFrom(ctx, globalBindingType, bindings)
	diags.Append(d...)
	failover := g.Failover
	if failover == "" {
		failover = runr.GlobalFailoverAutomatic
	}
	mode := g.Mode
	if mode == "" {
		mode = runr.GlobalModeHA
	}
	out, d := types.ObjectValueFrom(ctx, globalAttrTypes, globalModel{
		Regions: regionList, Mode: types.StringValue(mode), Failover: types.StringValue(failover),
		FailoverAfterSeconds: types.Int64Value(int64(g.FailoverAfterSeconds)), Hostname: types.StringValue(g.Hostname),
		Bindings: bindingList,
	})
	diags.Append(d...)
	return out
}

func equalStrings(a, b []string) bool {
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
