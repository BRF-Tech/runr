package provider

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewCostLimitResource) }

var (
	_ resource.Resource                = &costLimitResource{}
	_ resource.ResourceWithConfigure   = &costLimitResource{}
	_ resource.ResourceWithImportState = &costLimitResource{}
)

// NewCostLimitResource returns the runr_cost_limit resource.
func NewCostLimitResource() resource.Resource { return &costLimitResource{} }

type costLimitResource struct{ client *Client }

type costLimitModel struct {
	ID        types.String  `tfsdk:"id"`
	ProjectID types.String  `tfsdk:"project_id"`
	Amount    types.Float64 `tfsdk:"amount"`
	Action    types.String  `tfsdk:"action"`
	Currency  types.String  `tfsdk:"currency"`
}

// costLimitAmount checks an amount like the API: above 0, at most 1 000 000 000 and three
// decimals.
type costLimitAmount struct{}

func (costLimitAmount) Description(context.Context) string {
	return "greater than 0, at most 1000000000, at most three decimals"
}
func (v costLimitAmount) MarkdownDescription(ctx context.Context) string { return v.Description(ctx) }
func (costLimitAmount) ValidateFloat64(_ context.Context, req validator.Float64Request, resp *validator.Float64Response) {
	if !known(req.ConfigValue) {
		return
	}
	f := req.ConfigValue.ValueFloat64()
	text := strconv.FormatFloat(f, 'f', -1, 64)
	_, decimals, _ := strings.Cut(text, ".")
	if f <= 0 || f > 1e9 || len(decimals) > 3 {
		resp.Diagnostics.AddAttributeError(req.Path, "Invalid amount",
			fmt.Sprintf("The amount must be greater than 0, at most 1000000000 and have at most three decimals (got %s).", text))
	}
}

func (r *costLimitResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_cost_limit"
}

func (r *costLimitResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		MarkdownDescription: "The monthly cost limit of a pay-as-you-go project ([monthly cost limit](https://runr.sh/docs/billing/#monthly-cost-limit)): " +
			"one amount in the billing currency, and what happens when the month's cost reaches it — `notify` the owner, or " +
			"`stop` the project as an administrator would. At 80 % the owner is warned. A limit is checked when it is saved, " +
			"so a `stop` limit at or below this month so far stops the project at once. A fixed-price project has no limit " +
			"(the API refuses it with `cost_limit_not_available`); without the Billing module there are no limits.\n\n" +
			"Destroying the resource removes the limit; a project the limit stopped stays stopped until it is resumed " +
			"(Resume on the project's Billing tab, `POST /api/projects/{project}/billing/cost-limit/resume`, or `runr " +
			"projects resume`) — or RunR resumes it when the next month begins. The month's figures are in the " +
			"`runr_cost_limit` data source. Needs the project's owner, `projects.billing` on the project or `billing.manage`. " +
			"Import with the project's name or id.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "The project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id. Changing it moves the limit to another project.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"amount": schema.Float64Attribute{Required: true, Validators: []validator.Float64{costLimitAmount{}},
				MarkdownDescription: "The limit per calendar month in the billing currency: greater than 0, at most three decimals."},
			"action": schema.StringAttribute{Required: true,
				Validators:          []validator.String{stringvalidator.OneOf(runr.CostLimitNotify, runr.CostLimitStop)},
				MarkdownDescription: "At 100 %: `notify` (the project keeps running) or `stop` (the project is stopped)."},
			"currency": schema.StringAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()},
				Validators:    []validator.String{stringvalidator.RegexMatches(regexp.MustCompile(`^[A-Z]{3}$`), "must be an ISO 4217 code in capitals, e.g. EUR")},
				MarkdownDescription: "The billing currency of the installation (computed). Set it to make the apply fail when " +
					"the installation bills in another currency."},
		},
	}
}

func (r *costLimitResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// costLimitUnsupported reports an API without cost limits: the project exists, the route not.
func (r *costLimitResource) costLimitUnsupported(ctx context.Context, projectID string, diags *diag.Diagnostics) {
	if _, err := r.client.API.GetProject(ctx, projectID); err != nil {
		apiError(diags, "Cost limit failed", err)
		return
	}
	diags.AddError("Cost limits not available",
		"This RunR API has no monthly cost limits: billing is off (the Billing module) or the API predates them.")
}

func (r *costLimitResource) put(ctx context.Context, m *costLimitModel, diags *diag.Diagnostics) {
	in := runr.CostLimitInput{Amount: m.Amount.ValueFloat64(), Action: m.Action.ValueString()}
	if known(m.Currency) {
		in.Currency = m.Currency.ValueString()
	}
	cl, err := r.client.API.SetCostLimit(ctx, m.ProjectID.ValueString(), in)
	if errors.Is(err, runr.ErrUnsupported) {
		r.costLimitUnsupported(ctx, m.ProjectID.ValueString(), diags)
		return
	}
	if err != nil {
		apiError(diags, "Set cost limit failed", err)
		return
	}
	if cl.Stopped && m.Action.ValueString() == runr.CostLimitStop {
		diags.AddWarning("Project stopped by its cost limit",
			"This month's cost is at or above the limit, so the project is stopped. Raise the limit or switch it to notify "+
				"and resume the project, or wait for the next month.")
	}
	m.ID = types.StringValue(m.ProjectID.ValueString())
	m.Currency = types.StringValue(cl.Currency)
}

func (r *costLimitResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan costLimitModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.put(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *costLimitResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state costLimitModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	cl, err := r.client.API.GetCostLimit(ctx, state.ProjectID.ValueString())
	if errors.Is(err, runr.ErrUnsupported) {
		if _, perr := r.client.API.GetProject(ctx, state.ProjectID.ValueString()); runr.IsNotFound(perr) {
			resp.State.RemoveResource(ctx) // the project is gone, and its limit with it
			return
		}
		r.costLimitUnsupported(ctx, state.ProjectID.ValueString(), &resp.Diagnostics)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read cost limit failed", err)
		return
	}
	if cl.Limit == nil {
		resp.State.RemoveResource(ctx) // removed outside Terraform
		return
	}
	state.ID = types.StringValue(state.ProjectID.ValueString())
	state.Amount = types.Float64Value(cl.Limit.Amount)
	state.Action = types.StringValue(cl.Limit.Action)
	state.Currency = types.StringValue(cl.Currency)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *costLimitResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan costLimitModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.put(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *costLimitResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state costLimitModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	cl, err := r.client.API.DeleteCostLimit(ctx, state.ProjectID.ValueString())
	if errors.Is(err, runr.ErrUnsupported) {
		return // the project is gone, or there are no limits any more
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Remove cost limit failed", err)
		return
	}
	if cl.Stopped {
		resp.Diagnostics.AddWarning("Project stays stopped",
			"The cost limit stopped the project; removing the limit does not resume it. Resume it on the project's Billing "+
				"tab or with `runr projects resume`.")
	}
}

// ImportState accepts the project's name or id.
func (r *costLimitResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	p, err := r.client.API.FindProject(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import cost limit failed", err)
		return
	}
	cl, err := r.client.API.GetCostLimit(ctx, p.ID)
	if errors.Is(err, runr.ErrUnsupported) {
		r.costLimitUnsupported(ctx, p.ID, &resp.Diagnostics)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Import cost limit failed", err)
		return
	}
	if cl.Limit == nil {
		resp.Diagnostics.AddError("Import cost limit failed", "Project "+p.Name+" has no monthly cost limit.")
		return
	}
	m := costLimitModel{ID: types.StringValue(p.ID), ProjectID: types.StringValue(p.ID),
		Amount: types.Float64Value(cl.Limit.Amount), Action: types.StringValue(cl.Limit.Action), Currency: types.StringValue(cl.Currency)}
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
