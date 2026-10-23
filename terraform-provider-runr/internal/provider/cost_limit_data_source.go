package provider

import (
	"context"
	"errors"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerDataSource(NewCostLimitDataSource) }

var _ datasource.DataSourceWithConfigure = &costLimitDataSource{}

// NewCostLimitDataSource returns the runr_cost_limit data source.
func NewCostLimitDataSource() datasource.DataSource { return &costLimitDataSource{} }

type costLimitDataSource struct{ client *Client }

type costLimitDataModel struct {
	ProjectID      types.String  `tfsdk:"project_id"`
	Available      types.Bool    `tfsdk:"available"`
	Currency       types.String  `tfsdk:"currency"`
	Period         types.String  `tfsdk:"period"`
	Amount         types.Float64 `tfsdk:"amount"`
	Action         types.String  `tfsdk:"action"`
	MonthToDate    types.Float64 `tfsdk:"month_to_date"`
	Forecast       types.Float64 `tfsdk:"forecast"`
	Percent        types.Float64 `tfsdk:"percent"`
	WarningPercent types.Int64   `tfsdk:"warning_percent"`
	State          types.String  `tfsdk:"state"`
	WarnedAt       types.String  `tfsdk:"warned_at"`
	ReachedAt      types.String  `tfsdk:"reached_at"`
	Stopped        types.Bool    `tfsdk:"stopped"`
	CanResume      types.Bool    `tfsdk:"can_resume"`
	CheckedAt      types.String  `tfsdk:"checked_at"`
}

func (d *costLimitDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_cost_limit"
}

func (d *costLimitDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	num := func(desc string) schema.Float64Attribute {
		return schema.Float64Attribute{Computed: true, MarkdownDescription: desc}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A project's monthly cost limit and this month so far: the figures the project's Billing tab " +
			"shows (`runr_cost_limit` the resource sets the limit). Null figures for a project the limit does not apply to " +
			"(`available = false`: a fixed-price project or a free region). Needs the project's owner, `projects.billing` on " +
			"the project or `billing.view`.",
		Attributes: map[string]schema.Attribute{
			"project_id":      schema.StringAttribute{Required: true, MarkdownDescription: "Project id."},
			"available":       schema.BoolAttribute{Computed: true, MarkdownDescription: "A limit applies to the project (a pay-as-you-go project)."},
			"currency":        str("The billing currency."),
			"period":          str("This month (`2026-10`)."),
			"amount":          num("The limit; null without one."),
			"action":          str("`notify` or `stop`; null without a limit."),
			"month_to_date":   num("What the project's bills of this month come to so far."),
			"forecast":        num("This month so far ÷ elapsed days × days in the month."),
			"percent":         num("This month so far in percent of the limit."),
			"warning_percent": schema.Int64Attribute{Computed: true, MarkdownDescription: "Percent of the limit at which the owner is warned (80)."},
			"state":           str("`none` (no limit), `ok`, `warning` or `reached`."),
			"warned_at":       str("When this month's warning went out."),
			"reached_at":      str("When this month reached the limit."),
			"stopped":         schema.BoolAttribute{Computed: true, MarkdownDescription: "The limit keeps the project stopped."},
			"can_resume":      schema.BoolAttribute{Computed: true, MarkdownDescription: "The project can be resumed: the limit stopped it and no longer stops it."},
			"checked_at":      str("When the limit was last checked."),
		},
	}
}

func (d *costLimitDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func costFigure(p *float64) types.Float64 {
	if p == nil {
		return types.Float64Null()
	}
	return types.Float64Value(*p)
}

func (d *costLimitDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m costLimitDataModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	cl, err := d.client.API.GetCostLimit(ctx, m.ProjectID.ValueString())
	if errors.Is(err, runr.ErrUnsupported) {
		(&costLimitResource{client: d.client}).costLimitUnsupported(ctx, m.ProjectID.ValueString(), &resp.Diagnostics)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read cost limit failed", err)
		return
	}
	m.Available = types.BoolValue(cl.Available)
	m.Currency = types.StringValue(cl.Currency)
	m.Period = types.StringValue(cl.Period)
	m.Amount, m.Action = types.Float64Null(), types.StringNull()
	if cl.Limit != nil {
		m.Amount = types.Float64Value(cl.Limit.Amount)
		m.Action = types.StringValue(cl.Limit.Action)
	}
	m.MonthToDate = costFigure(cl.MonthToDate)
	m.Forecast = costFigure(cl.Forecast)
	m.Percent = costFigure(cl.Percent)
	m.WarningPercent = types.Int64Value(int64(cl.WarningPercent))
	m.State = types.StringValue(cl.State)
	m.WarnedAt = strOrNull(cl.WarnedAt)
	m.ReachedAt = strOrNull(cl.ReachedAt)
	m.Stopped = types.BoolValue(cl.Stopped)
	m.CanResume = types.BoolValue(cl.CanResume)
	m.CheckedAt = strOrNull(cl.CheckedAt)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
