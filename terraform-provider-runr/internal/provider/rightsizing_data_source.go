package provider

import (
	"context"
	"errors"
	"strconv"

	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerDataSource(NewRightsizingDataSource) }

var _ datasource.DataSourceWithConfigure = &rightsizingDataSource{}

// NewRightsizingDataSource returns the runr_rightsizing data source.
func NewRightsizingDataSource() datasource.DataSource { return &rightsizingDataSource{} }

type rightsizingDataSource struct{ client *Client }

type rightsizingModel struct {
	ProjectID     types.String `tfsdk:"project_id"`
	Status        types.String `tfsdk:"status"`
	Reason        types.String `tfsdk:"reason"`
	PendingDeploy types.Bool   `tfsdk:"pending_deploy"`
	Direction     types.String `tfsdk:"direction"`
	Current       types.Object `tfsdk:"current"`
	Recommended   types.Object `tfsdk:"recommended"`
	Reasons       types.List   `tfsdk:"reasons"`
	Evidence      types.Object `tfsdk:"evidence"`
	Savings       types.Object `tfsdk:"savings"`
	Blocked       types.Object `tfsdk:"blocked"`
	ComputedAt    types.String `tfsdk:"computed_at"`
	Collecting    types.Object `tfsdk:"collecting"`
}

var (
	rightsizingSizeAttrTypes = map[string]attr.Type{"cpu_m": types.Int64Type, "cpu": types.StringType, "memory_mb": types.Int64Type}
	rightsizingEvidenceTypes = map[string]attr.Type{
		"window_days": types.Int64Type, "days": types.Int64Type, "sessions": types.Int64Type, "always_on": types.BoolType,
		"memory_max_mb": types.Float64Type, "memory_p95_mb": types.Float64Type, "memory_near_limit": types.BoolType,
		"cpu_max_m": types.Int64Type, "cpu_p95_m": types.Int64Type, "oom_kills": types.Int64Type, "throttled_ratio": types.Float64Type,
	}
	rightsizingSavingsTypes    = map[string]attr.Type{"basis": types.StringType, "monthly": types.Float64Type, "currency": types.StringType}
	rightsizingBlockedTypes    = map[string]attr.Type{"code": types.StringType, "message": types.StringType}
	rightsizingCollectingTypes = map[string]attr.Type{"days": types.Int64Type, "required_days": types.Int64Type,
		"sessions": types.Int64Type, "required_sessions": types.Int64Type, "window_days": types.Int64Type}
)

func (d *rightsizingDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_rightsizing"
}

func (d *rightsizingDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	num := func(desc string) schema.Int64Attribute {
		return schema.Int64Attribute{Computed: true, MarkdownDescription: desc}
	}
	flt := func(desc string) schema.Float64Attribute {
		return schema.Float64Attribute{Computed: true, MarkdownDescription: desc}
	}
	size := func(desc string) schema.SingleNestedAttribute {
		return schema.SingleNestedAttribute{Computed: true, MarkdownDescription: desc, Attributes: map[string]schema.Attribute{
			"cpu_m":     num("CPU in millicores."),
			"cpu":       str("CPU as a Kubernetes quantity (`250m`), as `runr_project.resources.cpu` takes it."),
			"memory_mb": num("Memory in MiB (`runr_project.resources.memory_mb`)."),
		}}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A project's rightsizing recommendation ([rightsizing](https://runr.sh/docs/monitoring/#rightsizing)): " +
			"every night RunR compares what the project's pods used at most in the last 14 days with its size and " +
			"recommends a smaller one — or a larger one after OOM kills, CPU throttling or memory close to its limit. " +
			"Without a recommendation, `status` says why (`collecting`, `right_sized`, `not_eligible`).\n\n" +
			"Applying or dismissing a recommendation stays an action (`runr projects rightsizing apply|dismiss`); with " +
			"Terraform, feed the size into the project explicitly, e.g. `resources = { cpu = data.runr_rightsizing.x." +
			"recommended.cpu, memory_mb = data.runr_rightsizing.x.recommended.memory_mb }` — then the configuration, not " +
			"RunR, owns the size. Needs `metrics.view` on the project.",
		Attributes: map[string]schema.Attribute{
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id."},
			"status": str("`open`, `snoozed`, `dismissed` or `applied` (a recommendation), or `collecting` (not enough data " +
				"yet), `right_sized` or `not_eligible`."),
			"reason":         str("Why a project is `not_eligible`: `gpu`, `stopped`, `not_running`, `unsupported`, `no_metrics` or `disabled`."),
			"pending_deploy": schema.BoolAttribute{Computed: true, MarkdownDescription: "An applied size the running deployment does not have yet."},
			"direction":      str("`down`, `up` or `mixed`; null without a recommendation."),
			"current":        size("The project's size now; null without a recommendation."),
			"recommended":    size("The recommended size; null without a recommendation."),
			"reasons":        schema.ListAttribute{Computed: true, ElementType: types.StringType, MarkdownDescription: "Why, in words."},
			"evidence": schema.SingleNestedAttribute{Computed: true, MarkdownDescription: "What the recommendation is based on (one pod's readings).",
				Attributes: map[string]schema.Attribute{
					"window_days":       num("Days looked at."),
					"days":              num("Days with data."),
					"sessions":          num("Sessions with data."),
					"always_on":         schema.BoolAttribute{Computed: true, MarkdownDescription: "The workload never scales to zero."},
					"memory_max_mb":     flt("Highest memory working set (MiB)."),
					"memory_p95_mb":     flt("95th percentile of memory (MiB)."),
					"memory_near_limit": schema.BoolAttribute{Computed: true, MarkdownDescription: "Memory came close to its limit."},
					"cpu_max_m":         num("Highest CPU (millicores)."),
					"cpu_p95_m":         num("95th percentile of CPU (millicores)."),
					"oom_kills":         num("OOM kills."),
					"throttled_ratio":   flt("Share of throttled CPU periods."),
				}},
			"savings": schema.SingleNestedAttribute{Computed: true,
				MarkdownDescription: "The monthly difference the Billing module estimates (positive: saved); null without billing.",
				Attributes: map[string]schema.Attribute{
					"basis":    str("`reserved` (always-on, pay as you go), `usage` (scale to zero: 0) or `fixed`."),
					"monthly":  flt("Monthly amount."),
					"currency": str("Currency."),
				}},
			"blocked": schema.SingleNestedAttribute{Computed: true, MarkdownDescription: "Why applying would be refused now (region capacity, a fixed allocation).",
				Attributes: map[string]schema.Attribute{"code": str("Refusal code."), "message": str("Message.")}},
			"computed_at": str("When the recommendation was made."),
			"collecting": schema.SingleNestedAttribute{Computed: true, MarkdownDescription: "How much data the project has toward its first recommendation (`collecting`).",
				Attributes: map[string]schema.Attribute{
					"days": num("Days with data."), "required_days": num("Days needed."), "sessions": num("Sessions with data."),
					"required_sessions": num("Sessions needed."), "window_days": num("Days looked at."),
				}},
		},
	}
}

func (d *rightsizingDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func rightsizingSize(s runr.RightsizingSize) types.Object {
	return types.ObjectValueMust(rightsizingSizeAttrTypes, map[string]attr.Value{"cpu_m": types.Int64Value(int64(s.CPUM)),
		"cpu": types.StringValue(strconv.Itoa(s.CPUM) + "m"), "memory_mb": types.Int64Value(int64(s.MemoryMB))})
}

func rightsizingFloat(f *runr.FlexFloat) types.Float64 {
	if f == nil {
		return types.Float64Null()
	}
	return types.Float64Value(float64(*f))
}

func (d *rightsizingDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m rightsizingModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	st, err := d.client.API.ProjectRightsizing(ctx, m.ProjectID.ValueString())
	if errors.Is(err, runr.ErrUnsupported) {
		projectReportUnsupported(ctx, d.client, m.ProjectID.ValueString(), "Rightsizing", "rightsizing recommendations", &resp.Diagnostics)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read rightsizing failed", err)
		return
	}
	m.Status, m.Reason = types.StringValue(st.Status), strOrNull(st.Reason)
	m.PendingDeploy = types.BoolValue(st.PendingDeploy)
	m.Direction, m.ComputedAt = types.StringNull(), types.StringNull()
	m.Current, m.Recommended = types.ObjectNull(rightsizingSizeAttrTypes), types.ObjectNull(rightsizingSizeAttrTypes)
	m.Reasons = types.ListNull(types.StringType)
	m.Evidence = types.ObjectNull(rightsizingEvidenceTypes)
	m.Savings = types.ObjectNull(rightsizingSavingsTypes)
	m.Blocked = types.ObjectNull(rightsizingBlockedTypes)
	m.Collecting = types.ObjectNull(rightsizingCollectingTypes)
	if r := st.Data; r != nil {
		m.Direction, m.ComputedAt = strOrNull(r.Direction), strOrNull(r.ComputedAt)
		m.Current, m.Recommended = rightsizingSize(r.Current), rightsizingSize(r.Recommended)
		reasons, diags := types.ListValueFrom(ctx, types.StringType, append([]string{}, r.Reasons...))
		resp.Diagnostics.Append(diags...)
		m.Reasons = reasons
		e := r.Evidence
		m.Evidence = types.ObjectValueMust(rightsizingEvidenceTypes, map[string]attr.Value{
			"window_days": types.Int64Value(int64(e.WindowDays)), "days": types.Int64Value(int64(e.Days)),
			"sessions": types.Int64Value(int64(e.Sessions)), "always_on": types.BoolValue(e.AlwaysOn),
			"memory_max_mb": rightsizingFloat(e.MemoryMaxMB), "memory_p95_mb": rightsizingFloat(e.MemoryP95MB),
			"memory_near_limit": types.BoolValue(e.MemoryNearLimit), "cpu_max_m": intOrNull(e.CPUMaxM), "cpu_p95_m": intOrNull(e.CPUP95M),
			"oom_kills": types.Int64Value(int64(e.OOMKills)), "throttled_ratio": types.Float64Value(float64(e.ThrottledRatio)),
		})
		if s := r.Savings; s != nil {
			m.Savings = types.ObjectValueMust(rightsizingSavingsTypes, map[string]attr.Value{
				"basis": strOrNull(s.Basis), "monthly": rightsizingFloat(s.Monthly), "currency": strOrNull(s.Currency)})
		}
		if b := r.Blocked; b != nil {
			m.Blocked = types.ObjectValueMust(rightsizingBlockedTypes, map[string]attr.Value{
				"code": strOrNull(b.Code), "message": strOrNull(b.Message)})
		}
	}
	if c := st.Collecting; c != nil {
		m.Collecting = types.ObjectValueMust(rightsizingCollectingTypes, map[string]attr.Value{
			"days": types.Int64Value(int64(c.Days)), "required_days": types.Int64Value(int64(c.RequiredDays)),
			"sessions": types.Int64Value(int64(c.Sessions)), "required_sessions": types.Int64Value(int64(c.RequiredSessions)),
			"window_days": types.Int64Value(int64(c.WindowDays)),
		})
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
