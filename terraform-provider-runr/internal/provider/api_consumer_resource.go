package provider

import (
	"context"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/mapvalidator"
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

func init() { registerResource(NewAPIConsumerResource) }

var (
	_ resource.Resource                = &apiConsumerResource{}
	_ resource.ResourceWithConfigure   = &apiConsumerResource{}
	_ resource.ResourceWithImportState = &apiConsumerResource{}
)

// NewAPIConsumerResource returns the runr_api_consumer resource.
func NewAPIConsumerResource() resource.Resource { return &apiConsumerResource{} }

type apiConsumerResource struct{ client *Client }

type apiConsumerModel struct {
	ID         types.String `tfsdk:"id"`
	GatewayID  types.String `tfsdk:"gateway_id"`
	Name       types.String `tfsdk:"name"`
	Email      types.String `tfsdk:"email"`
	ExternalID types.String `tfsdk:"external_id"`
	JWTSubject types.String `tfsdk:"jwt_subject"`
	PlanID     types.String `tfsdk:"plan_id"`
	Status     types.String `tfsdk:"status"`
	Metadata   types.Map    `tfsdk:"metadata"`
}

func (r *apiConsumerResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_api_consumer"
}

func (r *apiConsumerResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	text := func(desc string, max int) schema.StringAttribute {
		return schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, max)},
			MarkdownDescription: desc}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A consumer of the API product: someone who calls a gateway's API, such as a customer, " +
			"identified by its API keys (`runr_api_key`) on key routes or by its JWT subject on JWT routes " +
			"([consumers](https://runr.sh/docs/api-product/#consumers)). A consumer belongs to a gateway or to the " +
			"organization (accepted on every gateway; `api_products.manage`). Deleting it deletes its keys; its usage " +
			"stays in the reports (`runr gateway usage`).\n\n" +
			"Import with the consumer id: `terraform import runr_api_consumer.acme 01JC…`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Consumer id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"gateway_id": schema.StringAttribute{Optional: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				MarkdownDescription: "Gateway id (`runr_gateway`). Left out: a consumer of the organization, accepted on every " +
					"gateway. Changing it creates a new consumer (with new keys)."},
			"name": schema.StringAttribute{Required: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 120)},
				MarkdownDescription: "Name, e.g. the customer's."},
			"email": schema.StringAttribute{Optional: true,
				Validators: []validator.String{stringvalidator.LengthBetween(3, 255),
					stringvalidator.RegexMatches(regexp.MustCompile(`^[^@\s]+@[^@\s]+\.[^@\s]+$`), "must be an e-mail address")},
				MarkdownDescription: "Contact address (shown only to those who may manage the consumer)."},
			"external_id": text("The consumer's id in your own systems (the invoicing system's customer number): in the "+
				"usage, the usage events and the `X-Runr-Consumer-External` header.", 191),
			"jwt_subject": text("JWT routes: the claim value that names this consumer (unique among the consumers a gateway accepts).", 191),
			"plan_id": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Its plan (`runr_api_plan`): one of the gateway's or of the organization's (an " +
					"organization consumer: only the organization's). Left out: no limits beyond the gateway's IP rate limit."},
			"status": schema.StringAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()},
				Validators:    []validator.String{stringvalidator.OneOf("active", "suspended")},
				MarkdownDescription: "`active` or `suspended` (every key gets 403 `consumer_suspended`). Left out: kept as it is " +
					"(a new consumer is `active`)."},
			"metadata": schema.MapAttribute{Optional: true, ElementType: types.StringType,
				Validators:          []validator.Map{mapvalidator.SizeAtMost(50)},
				MarkdownDescription: "Up to 50 key / value pairs of your own."},
		},
	}
}

func (r *apiConsumerResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (m *apiConsumerModel) metadata(ctx context.Context, diags *diag.Diagnostics) map[string]any {
	if !known(m.Metadata) {
		return nil
	}
	var raw map[string]string
	diags.Append(m.Metadata.ElementsAs(ctx, &raw, false)...)
	out := make(map[string]any, len(raw))
	for k, v := range raw {
		out[k] = v
	}
	return out
}

func (r *apiConsumerResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan apiConsumerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := runr.APIConsumerInput{GatewayID: plan.GatewayID.ValueString(), Name: plan.Name.ValueString(), Email: plan.Email.ValueString(),
		ExternalID: plan.ExternalID.ValueString(), JWTSubject: plan.JWTSubject.ValueString(), PlanID: plan.PlanID.ValueString(),
		Status: plan.Status.ValueString(), Metadata: plan.metadata(ctx, &resp.Diagnostics)}
	if resp.Diagnostics.HasError() {
		return
	}
	c, err := r.client.API.CreateAPIConsumer(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create API consumer failed", err)
		return
	}
	plan.fromAPI(ctx, c, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *apiConsumerResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state apiConsumerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	c, err := r.client.API.GetAPIConsumer(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read API consumer failed", err)
		return
	}
	state.fromAPI(ctx, c, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *apiConsumerResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state apiConsumerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	fields := map[string]any{}
	for field, v := range map[string][2]types.String{
		"name": {plan.Name, state.Name}, "email": {plan.Email, state.Email}, "external_id": {plan.ExternalID, state.ExternalID},
		"jwt_subject": {plan.JWTSubject, state.JWTSubject}, "plan_id": {plan.PlanID, state.PlanID},
	} {
		if !v[0].Equal(v[1]) {
			fields[field] = nullable(v[0])
		}
	}
	if known(plan.Status) && !plan.Status.Equal(state.Status) {
		fields["status"] = plan.Status.ValueString()
	}
	if !plan.Metadata.Equal(state.Metadata) {
		var metadata any // null: none
		if m := plan.metadata(ctx, &resp.Diagnostics); m != nil {
			metadata = m
		}
		fields["metadata"] = metadata
	}
	if resp.Diagnostics.HasError() {
		return
	}
	c, err := r.client.API.UpdateAPIConsumer(ctx, state.ID.ValueString(), fields)
	if err != nil {
		apiError(&resp.Diagnostics, "Update API consumer failed", err)
		return
	}
	plan.fromAPI(ctx, c, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *apiConsumerResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state apiConsumerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteAPIConsumer(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete API consumer failed", err)
	}
}

// ImportState accepts the consumer id.
func (r *apiConsumerResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	c, err := r.client.API.GetAPIConsumer(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import API consumer failed", err)
		return
	}
	m := apiConsumerModel{Metadata: types.MapNull(types.StringType)}
	m.fromAPI(ctx, c, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

func (m *apiConsumerModel) fromAPI(ctx context.Context, c *runr.APIConsumer, diags *diag.Diagnostics) {
	m.ID = types.StringValue(c.ID)
	m.GatewayID = strOrNull(c.GatewayID)
	m.Name = types.StringValue(c.Name)
	m.Email = strOrNull(c.Email)
	m.ExternalID = strOrNull(c.ExternalID)
	m.JWTSubject = strOrNull(c.JWTSubject)
	m.PlanID = strOrNull(c.PlanID)
	m.Status = types.StringValue(c.Status)
	m.Metadata = metadataMap(ctx, c.Metadata, m.Metadata, diags)
}
