package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewAPIPlanResource) }

var (
	_ resource.Resource                   = &apiPlanResource{}
	_ resource.ResourceWithConfigure      = &apiPlanResource{}
	_ resource.ResourceWithImportState    = &apiPlanResource{}
	_ resource.ResourceWithValidateConfig = &apiPlanResource{}
)

// NewAPIPlanResource returns the runr_api_plan resource.
func NewAPIPlanResource() resource.Resource { return &apiPlanResource{} }

type apiPlanResource struct{ client *Client }

type apiPlanModel struct {
	ID            types.String `tfsdk:"id"`
	GatewayID     types.String `tfsdk:"gateway_id"`
	Name          types.String `tfsdk:"name"`
	Description   types.String `tfsdk:"description"`
	RatePerSecond types.Int64  `tfsdk:"rate_per_second"`
	Burst         types.Int64  `tfsdk:"burst"`
	RatePerMinute types.Int64  `tfsdk:"rate_per_minute"`
	QuotaPerDay   types.Int64  `tfsdk:"quota_per_day"`
	QuotaPerMonth types.Int64  `tfsdk:"quota_per_month"`
}

func (r *apiPlanResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_api_plan"
}

func (r *apiPlanResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	limit := func(desc string, max int64) schema.Int64Attribute {
		return schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.Between(1, max)},
			MarkdownDescription: desc + " Left out: no limit of this kind."}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A plan of the API product: rate limits and quotas a consumer (`runr_api_consumer`) or an " +
			"API key (`runr_api_key`) gets ([plans](https://runr.sh/docs/api-product/#plans)). Every limit is optional; " +
			"the region's gateway-auth enforces them and answers 429 when one is reached. A plan belongs to a gateway or " +
			"to the organization (offered on every gateway; `api_products.manage`). A plan that a consumer or an active " +
			"key uses cannot be deleted.\n\n" +
			"Import with the plan id: `terraform import runr_api_plan.pro 01JP…`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Plan id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"gateway_id": schema.StringAttribute{Optional: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				MarkdownDescription: "Gateway id (`runr_gateway`) the plan belongs to. Left out: a plan of the organization, " +
					"offered on every gateway. Changing it creates a new plan."},
			"name": schema.StringAttribute{Required: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 100)},
				MarkdownDescription: "Name, unique per gateway (or in the organization)."},
			"description": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 500)},
				MarkdownDescription: "What the plan offers."},
			"rate_per_second": limit("Token bucket rate, requests per second (1-100000).", 100000),
			"burst": schema.Int64Attribute{Optional: true, Computed: true,
				Validators:    []validator.Int64{int64validator.Between(1, 1000000)},
				PlanModifiers: []planmodifier.Int64{int64FromSibling{sibling: "rate_per_second"}},
				MarkdownDescription: "Requests at once of the token bucket (at least `rate_per_second`, at most 1000000). " +
					"Needs `rate_per_second`. Left out: the rate for a new plan or a changed rate, else the plan's burst stays."},
			"rate_per_minute": limit("Sliding window, requests per minute (1-10000000).", 10000000),
			"quota_per_day":   limit("Requests per calendar day, UTC (1-1000000000000).", 1000000000000),
			"quota_per_month": limit("Requests per calendar month, UTC (1-10000000000000).", 10000000000000),
		},
	}
}

func (r *apiPlanResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *apiPlanResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m apiPlanModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || !known(m.Burst) || m.RatePerSecond.IsUnknown() {
		return
	}
	if m.RatePerSecond.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("burst"), "burst without rate_per_second", "A burst needs a per-second rate.")
	} else if m.Burst.ValueInt64() < m.RatePerSecond.ValueInt64() {
		resp.Diagnostics.AddAttributeError(path.Root("burst"), "burst below rate_per_second", "The burst cannot be lower than the per-second rate.")
	}
}

// fields are the plan's settings for PATCH /api-plans/{plan} (null: no limit).
func (m *apiPlanModel) fields() map[string]any {
	limit := func(v types.Int64) any {
		if !known(v) {
			return nil
		}
		return v.ValueInt64()
	}
	return map[string]any{
		"name": m.Name.ValueString(), "description": nullable(m.Description),
		"rate_per_second": limit(m.RatePerSecond), "burst": limit(m.Burst), "rate_per_minute": limit(m.RatePerMinute),
		"quota_per_day": limit(m.QuotaPerDay), "quota_per_month": limit(m.QuotaPerMonth),
	}
}

func (r *apiPlanResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan apiPlanModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	p, err := r.client.API.CreateAPIPlan(ctx, runr.APIPlanInput{
		GatewayID: plan.GatewayID.ValueString(), Name: plan.Name.ValueString(), Description: plan.Description.ValueString(),
		RatePerSecond: int64Ptr(plan.RatePerSecond), Burst: int64Ptr(plan.Burst), RatePerMinute: int64Ptr(plan.RatePerMinute),
		QuotaPerDay: int64Ptr(plan.QuotaPerDay), QuotaPerMonth: int64Ptr(plan.QuotaPerMonth),
	})
	if err != nil {
		apiError(&resp.Diagnostics, "Create API plan failed", err)
		return
	}
	plan.fromAPI(p)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// find returns the plan (nil when it or its gateway is gone).
func (r *apiPlanResource) find(ctx context.Context, gatewayID, id string) (*runr.APIPlan, error) {
	plans, err := r.client.API.ListAPIPlans(ctx, gatewayID)
	if runr.IsNotFound(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	for _, p := range plans {
		if p.ID == id {
			return &p, nil
		}
	}
	return nil, nil
}

func (r *apiPlanResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state apiPlanModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	p, err := r.find(ctx, state.GatewayID.ValueString(), state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read API plan failed", err)
		return
	}
	if p == nil {
		resp.State.RemoveResource(ctx)
		return
	}
	state.fromAPI(p)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *apiPlanResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state apiPlanModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	p, err := r.client.API.UpdateAPIPlan(ctx, state.ID.ValueString(), plan.fields())
	if err != nil {
		apiError(&resp.Diagnostics, "Update API plan failed", err)
		return
	}
	plan.fromAPI(p)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *apiPlanResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state apiPlanModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteAPIPlan(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete API plan failed", err)
	}
}

// ImportState accepts the plan id.
func (r *apiPlanResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	p, err := r.find(ctx, "", req.ID)
	if err == nil && p == nil {
		err = &runr.NotFoundError{Kind: "API plan", Ref: req.ID}
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Import API plan failed", err)
		return
	}
	var m apiPlanModel
	m.fromAPI(p)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

func (m *apiPlanModel) fromAPI(p *runr.APIPlan) {
	m.ID = types.StringValue(p.ID)
	m.GatewayID = strOrNull(p.GatewayID)
	m.Name = types.StringValue(p.Name)
	m.Description = strOrNull(p.Description)
	m.RatePerSecond = int64OrNull(p.RatePerSecond)
	m.Burst = int64OrNull(p.Burst)
	m.RatePerMinute = int64OrNull(p.RatePerMinute)
	m.QuotaPerDay = int64OrNull(p.QuotaPerDay)
	m.QuotaPerMonth = int64OrNull(p.QuotaPerMonth)
}
