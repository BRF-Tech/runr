package provider

import (
	"context"
	"regexp"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/setvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewGatewayRouteResource) }

var (
	_ resource.Resource                   = &gatewayRouteResource{}
	_ resource.ResourceWithConfigure      = &gatewayRouteResource{}
	_ resource.ResourceWithImportState    = &gatewayRouteResource{}
	_ resource.ResourceWithValidateConfig = &gatewayRouteResource{}
)

// NewGatewayRouteResource returns the runr_gateway_route resource.
func NewGatewayRouteResource() resource.Resource { return &gatewayRouteResource{} }

type gatewayRouteResource struct{ client *Client }

type gatewayRouteModel struct {
	ID          types.String `tfsdk:"id"`
	GatewayID   types.String `tfsdk:"gateway_id"`
	ProjectID   types.String `tfsdk:"project_id"`
	Environment types.String `tfsdk:"environment"`
	Path        types.String `tfsdk:"path"`
	Methods     types.Set    `tfsdk:"methods"`
	AuthMode    types.String `tfsdk:"auth_mode"`
	JWT         types.Object `tfsdk:"jwt"`
	GatewayOnly types.Bool   `tfsdk:"gateway_only"`
	URL         types.String `tfsdk:"url"`
}

type routeJWTModel struct {
	JWKSURL         types.String `tfsdk:"jwks_url"`
	Issuer          types.String `tfsdk:"issuer"`
	Audience        types.String `tfsdk:"audience"`
	SubjectClaim    types.String `tfsdk:"subject_claim"`
	RequireConsumer types.Bool   `tfsdk:"require_consumer"`
}

var routeJWTAttrTypes = map[string]attr.Type{
	"jwks_url": types.StringType, "issuer": types.StringType, "audience": types.StringType,
	"subject_claim": types.StringType, "require_consumer": types.BoolType,
}

var (
	gatewayRoutePath = regexp.MustCompile(`^/[a-zA-Z0-9/_-]*$`)
	jwtClaimName     = regexp.MustCompile(`^[A-Za-z0-9_.:-]+$`)
)

func (r *gatewayRouteResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_gateway_route"
}

func (r *gatewayRouteResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A route of a gateway: a path that leads to a project's own workload, or to one of its " +
			"[environments](https://runr.sh/docs/environments/#gateway-routes), with its authentication — public, an " +
			"API key of a consumer, or a JWT ([API product](https://runr.sh/docs/api-product/#routes)). The gateway must " +
			"be in the region the workload runs in (a global project: one of its regions), a path is used once per " +
			"gateway, and a workload has one route. A route to the project's own workload makes the gateway its " +
			"endpoint; removing the route gives it its own URL back. A key or JWT route is created with its " +
			"authentication in one call: it is never public, not even for a moment.\n\n" +
			"Import with `<gateway id or region>/<route id or path>`: `terraform import runr_gateway_route.orders eu-1/orders`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Route id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"gateway_id": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Gateway id (`runr_gateway`). Changing it creates a new route."},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Project id. Changing it creates a new route."},
			"environment": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				Validators: []validator.String{stringvalidator.LengthBetween(1, 64)},
				MarkdownDescription: "Name or id of the project environment the route leads to. Left out (or `production`): " +
					"the project's own workload. Changing it creates a new route."},
			"path": schema.StringAttribute{Required: true,
				Validators: []validator.String{stringvalidator.LengthAtMost(255),
					stringvalidator.RegexMatches(gatewayRoutePath, "must start with / and hold only letters, digits, /, _ and -")},
				MarkdownDescription: "Path of the route, e.g. `/orders`. Changes in place (the route keeps its id, which API key scopes name)."},
			"methods": schema.SetAttribute{Optional: true, ElementType: types.StringType,
				Validators: []validator.Set{setvalidator.SizeAtLeast(1),
					setvalidator.ValueStringsAre(stringvalidator.OneOf("GET", "POST", "PUT", "PATCH", "DELETE"))},
				MarkdownDescription: "Methods the route accepts (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`). Left out: every method."},
			"auth_mode": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(runr.RouteAuthPublic),
				Validators: []validator.String{stringvalidator.OneOf(runr.RouteAuthPublic, runr.RouteAuthKey, runr.RouteAuthJWT)},
				MarkdownDescription: "`public` (anyone, default), `key` (an API key of a consumer of the gateway or the organization: " +
					"`runr_api_key`) or `jwt` (a JWT the route's JWKS verifies; set `jwt`). Key and JWT routes are checked by the " +
					"region's gateway-auth and answer 503 `auth_unavailable` until it is ready (the gateway's `status`)."},
			"jwt": schema.SingleNestedAttribute{Optional: true,
				MarkdownDescription: "How a `jwt` route verifies tokens (required with `auth_mode = \"jwt\"`, refused otherwise).",
				Attributes: map[string]schema.Attribute{
					"jwks_url": schema.StringAttribute{Required: true,
						Validators: []validator.String{stringvalidator.LengthAtMost(2048),
							stringvalidator.RegexMatches(regexp.MustCompile(`^https?://\S+$`), "must be an http or https URL")},
						MarkdownDescription: "JWKS of the token issuer (fetched from inside the region)."},
					"issuer": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
						MarkdownDescription: "Expected `iss` claim."},
					"audience": schema.StringAttribute{Optional: true, Validators: []validator.String{stringvalidator.LengthBetween(1, 255)},
						MarkdownDescription: "Expected `aud` claim."},
					"subject_claim": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("sub"),
						Validators: []validator.String{stringvalidator.LengthBetween(1, 64),
							stringvalidator.RegexMatches(jwtClaimName, "must be a claim name (letters, digits, _ . : -)")},
						MarkdownDescription: "Claim that names the consumer (its `jwt_subject`; default `sub`)."},
					"require_consumer": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false),
						MarkdownDescription: "`true`: a valid token whose subject is no consumer gets 403. `false` (default): it " +
							"passes without a plan and its usage is counted under the subject."},
				}},
			"gateway_only": schema.BoolAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "Only through the gateway: the workload's own URL stops serving, so callers cannot get " +
					"around the route's keys. Refused for a workload with custom domains and for a global project. Left out: " +
					"kept as it is (a new route: `false`)."},
			"url": schema.StringAttribute{Computed: true,
				MarkdownDescription: "URL of the route (on the gateway's custom domain once it is verified)."},
		},
	}
}

func (r *gatewayRouteResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *gatewayRouteResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m gatewayRouteModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || m.AuthMode.IsUnknown() || m.JWT.IsUnknown() {
		return
	}
	jwt := m.AuthMode.ValueString() == runr.RouteAuthJWT
	if jwt && m.JWT.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("jwt"), "jwt required", "auth_mode = \"jwt\" needs the jwt settings (at least jwks_url).")
	}
	if !jwt && !m.JWT.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("jwt"), "jwt without auth_mode jwt", "Set auth_mode = \"jwt\" or remove jwt.")
	}
}

// jwtBody is the jwt of a POST or PATCH (nil: none).
func (m *gatewayRouteModel) jwtBody(ctx context.Context, diags *diag.Diagnostics) *runr.RouteJWT {
	if !known(m.JWT) {
		return nil
	}
	var j routeJWTModel
	diags.Append(m.JWT.As(ctx, &j, basetypes.ObjectAsOptions{})...)
	return &runr.RouteJWT{JWKSURL: j.JWKSURL.ValueString(), Issuer: j.Issuer.ValueString(), Audience: j.Audience.ValueString(),
		SubjectClaim: j.SubjectClaim.ValueString(), RequireConsumer: j.RequireConsumer.ValueBool()}
}

// authFields are the PATCH fields that make the route's authentication and gateway-only
// setting what the plan says (prior: the state; nil: a public route).
func (m *gatewayRouteModel) authFields(ctx context.Context, prior *gatewayRouteModel, diags *diag.Diagnostics) map[string]any {
	fields := map[string]any{}
	mode := m.AuthMode.ValueString()
	priorMode, priorJWT := runr.RouteAuthPublic, types.ObjectNull(routeJWTAttrTypes)
	if prior != nil {
		priorMode, priorJWT = prior.AuthMode.ValueString(), prior.JWT
	}
	if mode != priorMode {
		fields["auth_mode"] = mode
	}
	if !m.JWT.Equal(priorJWT) || (mode != priorMode && (mode == runr.RouteAuthJWT || priorMode == runr.RouteAuthJWT)) {
		var jwt *runr.RouteJWT
		if mode == runr.RouteAuthJWT {
			jwt = m.jwtBody(ctx, diags)
		}
		fields["jwt"] = jwt
	}
	if known(m.GatewayOnly) && (prior == nil && m.GatewayOnly.ValueBool() || prior != nil && !m.GatewayOnly.Equal(prior.GatewayOnly)) {
		fields["gateway_only"] = m.GatewayOnly.ValueBool()
	}
	return fields
}

// read returns the route as the gateway shows it (with its url and JWT settings); nil when it
// or the gateway is gone.
func (r *gatewayRouteResource) read(ctx context.Context, gatewayID, routeID string) (*runr.GatewayRoute, error) {
	g, err := r.client.API.GetGateway(ctx, gatewayID)
	if runr.IsNotFound(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	for _, rt := range g.Routes {
		if rt.ID == routeID {
			return &rt, nil
		}
	}
	return nil, nil
}

func (r *gatewayRouteResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan gatewayRouteModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	gatewayID := plan.GatewayID.ValueString()
	// One call: the route reaches the region with its authentication, a key or JWT route is
	// never public (an API before 0.6.0 creates it public; the client sets it right after).
	in := runr.GatewayRouteInput{ProjectID: plan.ProjectID.ValueString(), Environment: plan.Environment.ValueString(),
		Path: plan.Path.ValueString(), Methods: setStrings(ctx, plan.Methods, &resp.Diagnostics)}
	if mode := plan.AuthMode.ValueString(); mode != runr.RouteAuthPublic {
		in.AuthMode = mode
		if mode == runr.RouteAuthJWT {
			in.JWT = plan.jwtBody(ctx, &resp.Diagnostics)
		}
	}
	if known(plan.GatewayOnly) && plan.GatewayOnly.ValueBool() {
		only := true
		in.GatewayOnly = &only
	}
	if resp.Diagnostics.HasError() {
		return
	}
	created, err := r.client.API.AddGatewayRoute(ctx, gatewayID, in)
	if created == nil {
		apiError(&resp.Diagnostics, "Add gateway route failed", err)
		return
	}
	// The route exists from here: a failure keeps it in the state (tainted).
	if err != nil {
		apiError(&resp.Diagnostics, "Set the route's authentication failed", err)
	}
	rt := created
	if rt.URL == "" {
		// the answer of an earlier API: the gateway shows the URL and the JWT settings
		if shown, err := r.read(ctx, gatewayID, created.ID); err != nil {
			apiError(&resp.Diagnostics, "Read gateway route failed", err)
		} else if shown != nil {
			rt = shown
		}
	}
	plan.fromAPI(ctx, gatewayID, rt, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *gatewayRouteResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state gatewayRouteModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	rt, err := r.read(ctx, state.GatewayID.ValueString(), state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read gateway route failed", err)
		return
	}
	if rt == nil {
		resp.State.RemoveResource(ctx)
		return
	}
	state.fromAPI(ctx, state.GatewayID.ValueString(), rt, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *gatewayRouteResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state gatewayRouteModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	gatewayID, routeID := state.GatewayID.ValueString(), state.ID.ValueString()
	fields := plan.authFields(ctx, &state, &resp.Diagnostics)
	if !plan.Path.Equal(state.Path) {
		fields["path"] = plan.Path.ValueString()
	}
	if !plan.Methods.Equal(state.Methods) {
		fields["methods"] = setStrings(ctx, plan.Methods, &resp.Diagnostics)
	}
	if resp.Diagnostics.HasError() {
		return
	}
	// the answer is the route as the gateway shows it; an earlier API's has no URL: read it
	var rt *runr.GatewayRoute
	if len(fields) > 0 {
		updated, err := r.client.API.UpdateGatewayRoute(ctx, gatewayID, routeID, fields)
		if err != nil {
			apiError(&resp.Diagnostics, "Update gateway route failed", err)
			return
		}
		if updated.URL != "" {
			rt = updated
		}
	}
	if rt == nil {
		shown, err := r.read(ctx, gatewayID, routeID)
		if err != nil || shown == nil {
			if err == nil {
				err = &runr.NotFoundError{Kind: "gateway route", Ref: routeID}
			}
			apiError(&resp.Diagnostics, "Read gateway route failed", err)
			return
		}
		rt = shown
	}
	plan.ID = state.ID
	plan.fromAPI(ctx, gatewayID, rt, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *gatewayRouteResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state gatewayRouteModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.RemoveGatewayRoute(ctx, state.GatewayID.ValueString(), state.ID.ValueString())
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Remove gateway route failed", err)
	}
}

// ImportState accepts "<gateway id or region>/<route id or path>".
func (r *gatewayRouteResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	ref, route, ok := strings.Cut(req.ID, "/")
	if !ok || ref == "" || route == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <gateway id or region>/<route id or path>, e.g. eu-1/orders.")
		return
	}
	g, err := findGateway(ctx, r.client.API, ref)
	if err != nil {
		apiError(&resp.Diagnostics, "Import gateway route failed", err)
		return
	}
	for _, rt := range g.Routes {
		if rt.ID == route || rt.Path == "/"+strings.TrimLeft(route, "/") {
			var m gatewayRouteModel
			m.Environment = types.StringNull()
			m.fromAPI(ctx, g.ID, &rt, &resp.Diagnostics)
			resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
			return
		}
	}
	resp.Diagnostics.AddError("Import gateway route failed", "Gateway "+g.Host+" has no route "+route+".")
}

// fromAPI sets the state from the route of a gateway. The environment keeps the configuration's
// spelling (a name in another case, its id, production for the project's own workload).
func (m *gatewayRouteModel) fromAPI(ctx context.Context, gatewayID string, rt *runr.GatewayRoute, diags *diag.Diagnostics) {
	m.ID = types.StringValue(rt.ID)
	m.GatewayID = types.StringValue(gatewayID)
	m.ProjectID = types.StringValue(rt.ProjectID)
	prior := m.Environment.ValueString()
	switch {
	case known(m.Environment) && (strings.EqualFold(prior, rt.Environment) || (rt.EnvironmentID != "" && prior == rt.EnvironmentID) ||
		(rt.Environment == "" && strings.EqualFold(prior, runr.PrimaryEnvironment))):
	default:
		m.Environment = strOrNull(rt.Environment)
	}
	m.Path = types.StringValue(rt.Path)
	m.Methods = stringSet(ctx, rt.Methods, diags)
	m.AuthMode = types.StringValue(rt.AuthMode)
	if rt.AuthMode == "" {
		m.AuthMode = types.StringValue(runr.RouteAuthPublic)
	}
	m.JWT = types.ObjectNull(routeJWTAttrTypes)
	if j := rt.JWT; j != nil && m.AuthMode.ValueString() == runr.RouteAuthJWT {
		subject := j.SubjectClaim
		if subject == "" {
			subject = "sub"
		}
		v, d := types.ObjectValue(routeJWTAttrTypes, map[string]attr.Value{
			"jwks_url": types.StringValue(j.JWKSURL), "issuer": strOrNull(j.Issuer), "audience": strOrNull(j.Audience),
			"subject_claim": types.StringValue(subject), "require_consumer": types.BoolValue(j.RequireConsumer),
		})
		diags.Append(d...)
		m.JWT = v
	}
	m.GatewayOnly = types.BoolValue(rt.GatewayOnly)
	m.URL = strOrNull(rt.URL)
}
