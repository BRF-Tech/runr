package provider

import (
	"context"
	"strings"
	"time"

	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/setvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewAPIKeyResource) }

var (
	_ resource.Resource                   = &apiKeyResource{}
	_ resource.ResourceWithConfigure      = &apiKeyResource{}
	_ resource.ResourceWithImportState    = &apiKeyResource{}
	_ resource.ResourceWithValidateConfig = &apiKeyResource{}
)

// NewAPIKeyResource returns the runr_api_key resource.
func NewAPIKeyResource() resource.Resource { return &apiKeyResource{} }

type apiKeyResource struct{ client *Client }

type apiKeyModel struct {
	ID         types.String `tfsdk:"id"`
	ConsumerID types.String `tfsdk:"consumer_id"`
	Name       types.String `tfsdk:"name"`
	Scopes     types.List   `tfsdk:"scopes"`
	PlanID     types.String `tfsdk:"plan_id"`
	ExpiresAt  types.String `tfsdk:"expires_at"`
	Key        types.String `tfsdk:"key"`
	Prefix     types.String `tfsdk:"prefix"`
	Status     types.String `tfsdk:"status"`
}

type keyScopeModel struct {
	RouteID types.String `tfsdk:"route_id"`
	Methods types.Set    `tfsdk:"methods"`
}

var keyScopeType = types.ObjectType{AttrTypes: map[string]attr.Type{
	"route_id": types.StringType, "methods": types.SetType{ElemType: types.StringType},
}}

func (r *apiKeyResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_api_key"
}

func (r *apiKeyResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "An API key of a consumer ([API keys](https://runr.sh/docs/api-product/#api-keys)): callers of " +
			"`key` routes send it as `X-Api-Key` or `Authorization: Bearer`. The key itself (`key`) is in the answer " +
			"that creates it and nowhere else: Terraform keeps it in the state (sensitive), so keep the state safe or " +
			"hand the key on at once. Name, scopes, plan and expiry change in place; the key stays.\n\n" +
			"Destroying the resource revokes the key (callers get 401 within the region's sync interval). A key revoked " +
			"outside Terraform is dropped from the state and created again. Rotation is an action, not a setting: " +
			"`runr gateway keys rotate` makes a new key with an overlap; the resource then follows the new key (its " +
			"`key` is not in the state; the command printed it).\n\n" +
			"Import with `<consumer id>/<key id>`; an imported key has no `key` (RunR never shows it again).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Key id."},
			"consumer_id": schema.StringAttribute{Required: true,
				PlanModifiers:       []planmodifier.String{stringplanmodifier.RequiresReplace()},
				MarkdownDescription: "Consumer id (`runr_api_consumer`). Changing it creates a new key."},
			"name": schema.StringAttribute{Required: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 120)},
				MarkdownDescription: "Name of the key, e.g. `production`."},
			"scopes": schema.ListNestedAttribute{Optional: true,
				Validators: []validator.List{listvalidator.SizeBetween(1, 200)},
				MarkdownDescription: "Routes (and methods) the key may call: routes of the consumer's gateway (any gateway of " +
					"the organization for an organization consumer). Left out: every route the consumer may call.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"route_id": schema.StringAttribute{Required: true, MarkdownDescription: "Route id (`runr_gateway_route`)."},
					"methods": schema.SetAttribute{Optional: true, ElementType: types.StringType,
						Validators: []validator.Set{setvalidator.SizeAtLeast(1), setvalidator.ValueStringsAre(
							stringvalidator.OneOf("GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"))},
						MarkdownDescription: "Methods on the route (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`). Left out: every method."},
				}}},
			"plan_id": schema.StringAttribute{Optional: true,
				MarkdownDescription: "A plan for this key instead of the consumer's (`runr_api_plan`): its limits are counted " +
					"for the key alone."},
			"expires_at": schema.StringAttribute{Optional: true,
				MarkdownDescription: "When the key stops working (RFC 3339, e.g. `2027-01-01T00:00:00Z`; in the future when " +
					"it is set). Left out: never. An expired key stays in the state (`status = \"expired\"`) until the time changes."},
			"key": schema.StringAttribute{Computed: true, Sensitive: true, PlanModifiers: keep,
				MarkdownDescription: "The key (`rk_…`), known only from its creation; null after an import or a rotation outside Terraform."},
			"prefix": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "The key's first 11 characters, which tell keys apart."},
			"status": schema.StringAttribute{Computed: true, MarkdownDescription: "`active` or `expired`."},
		},
	}
}

func (r *apiKeyResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *apiKeyResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var expires types.String
	resp.Diagnostics.Append(req.Config.GetAttribute(ctx, path.Root("expires_at"), &expires)...)
	if known(expires) {
		if _, err := time.Parse(time.RFC3339, expires.ValueString()); err != nil {
			resp.Diagnostics.AddAttributeError(path.Root("expires_at"), "Invalid time", "Use RFC 3339, e.g. 2027-01-01T00:00:00Z.")
		}
	}
}

// scopes are the planned scopes (nil: every route).
func (m *apiKeyModel) scopes(ctx context.Context, diags *diag.Diagnostics) []runr.KeyScope {
	if !known(m.Scopes) {
		return nil
	}
	var rows []keyScopeModel
	diags.Append(m.Scopes.ElementsAs(ctx, &rows, false)...)
	out := make([]runr.KeyScope, 0, len(rows))
	for _, s := range rows {
		out = append(out, runr.KeyScope{RouteID: s.RouteID.ValueString(), Methods: setStrings(ctx, s.Methods, diags)})
	}
	return out
}

func (r *apiKeyResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan apiKeyModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := runr.APIKeyInput{Name: plan.Name.ValueString(), Scopes: plan.scopes(ctx, &resp.Diagnostics),
		PlanID: plan.PlanID.ValueString(), ExpiresAt: plan.ExpiresAt.ValueString()}
	if resp.Diagnostics.HasError() {
		return
	}
	k, err := r.client.API.CreateAPIKey(ctx, plan.ConsumerID.ValueString(), in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create API key failed", err)
		return
	}
	plan.Key = types.StringValue(k.Key)
	plan.fromAPI(ctx, k, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// current finds the key among the consumer's, following rotations made outside Terraform to the
// newest key; nil when it or its consumer is gone, or it was revoked.
func (r *apiKeyResource) current(ctx context.Context, consumerID, id string) (*runr.APIKey, error) {
	keys, err := r.client.API.ListAPIKeys(ctx, consumerID)
	if runr.IsNotFound(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var cur *runr.APIKey
	for i := range keys {
		if keys[i].ID == id {
			cur = &keys[i]
		}
	}
	for seen := 0; cur != nil && seen < len(keys); seen++ {
		var next *runr.APIKey
		for i := range keys {
			if keys[i].RotatedFrom == cur.ID {
				next = &keys[i]
			}
		}
		if next == nil {
			break
		}
		cur = next
	}
	if cur == nil || cur.Status == "revoked" || cur.RevokedAt != "" {
		return nil, nil
	}
	return cur, nil
}

func (r *apiKeyResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state apiKeyModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	k, err := r.current(ctx, state.ConsumerID.ValueString(), state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read API key failed", err)
		return
	}
	if k == nil {
		resp.State.RemoveResource(ctx)
		return
	}
	if k.ID != state.ID.ValueString() {
		resp.Diagnostics.AddWarning("API key rotated outside Terraform",
			"Key "+state.Prefix.ValueString()+"… ("+state.ID.ValueString()+") was rotated; the state follows the new key "+
				k.Prefix+"… ("+k.ID+"). Its key is not in the state: the rotation printed it.")
		state.Key = types.StringNull()
	}
	state.fromAPI(ctx, k, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *apiKeyResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state apiKeyModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	fields := map[string]any{}
	if !plan.Name.Equal(state.Name) {
		fields["name"] = plan.Name.ValueString()
	}
	if !plan.Scopes.Equal(state.Scopes) {
		var scopes any // null: every route
		if s := plan.scopes(ctx, &resp.Diagnostics); s != nil {
			scopes = s
		}
		fields["scopes"] = scopes
	}
	if !plan.PlanID.Equal(state.PlanID) {
		fields["plan_id"] = nullable(plan.PlanID)
	}
	if !plan.ExpiresAt.Equal(state.ExpiresAt) {
		fields["expires_at"] = nullable(plan.ExpiresAt)
	}
	if resp.Diagnostics.HasError() {
		return
	}
	k, err := r.client.API.UpdateAPIKey(ctx, state.ID.ValueString(), fields)
	if err != nil {
		apiError(&resp.Diagnostics, "Update API key failed", err)
		return
	}
	plan.fromAPI(ctx, k, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *apiKeyResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state apiKeyModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if _, err := r.client.API.RevokeAPIKey(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Revoke API key failed", err)
	}
}

// ImportState accepts "<consumer id>/<key id>".
func (r *apiKeyResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	consumer, id, ok := strings.Cut(req.ID, "/")
	if !ok || consumer == "" || id == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <consumer id>/<key id>.")
		return
	}
	keys, err := r.client.API.ListAPIKeys(ctx, consumer)
	if err != nil {
		apiError(&resp.Diagnostics, "Import API key failed", err)
		return
	}
	for _, k := range keys {
		if k.ID != id {
			continue
		}
		if k.RevokedAt != "" {
			resp.Diagnostics.AddError("Import API key failed", "Key "+k.Prefix+"… is revoked.")
			return
		}
		m := apiKeyModel{Key: types.StringNull(), Scopes: types.ListNull(keyScopeType), ExpiresAt: types.StringNull()}
		m.fromAPI(ctx, &k, &resp.Diagnostics)
		resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
		resp.Diagnostics.AddWarning("Key not imported",
			"RunR shows a key only when it is created: the state has no key for "+k.Prefix+"…, the key itself stays as it is.")
		return
	}
	resp.Diagnostics.AddError("Import API key failed", "Consumer "+consumer+" has no key "+id+".")
}

func (m *apiKeyModel) fromAPI(ctx context.Context, k *runr.APIKey, diags *diag.Diagnostics) {
	m.ID = types.StringValue(k.ID)
	m.ConsumerID = types.StringValue(k.ConsumerID)
	m.Name = types.StringValue(k.Name)
	m.PlanID = strOrNull(k.PlanID)
	m.ExpiresAt = sameInstant(m.ExpiresAt, k.ExpiresAt)
	m.Prefix = types.StringValue(k.Prefix)
	m.Status = types.StringValue(k.Status)
	m.Scopes = types.ListNull(keyScopeType)
	if k.Scopes != nil {
		rows := make([]attr.Value, 0, len(k.Scopes))
		for _, s := range k.Scopes {
			row, d := types.ObjectValue(keyScopeType.AttrTypes, map[string]attr.Value{
				"route_id": types.StringValue(s.RouteID), "methods": stringSet(ctx, s.Methods, diags),
			})
			diags.Append(d...)
			rows = append(rows, row)
		}
		l, d := types.ListValue(keyScopeType, rows)
		diags.Append(d...)
		m.Scopes = l
	}
}
