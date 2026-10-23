package provider

import (
	"context"
	"errors"
	"regexp"
	"strings"
	"time"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
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

func init() { registerResource(NewGatewayResource) }

var (
	_ resource.Resource                   = &gatewayResource{}
	_ resource.ResourceWithConfigure      = &gatewayResource{}
	_ resource.ResourceWithImportState    = &gatewayResource{}
	_ resource.ResourceWithValidateConfig = &gatewayResource{}
	_ resource.ResourceWithModifyPlan     = &gatewayResource{}
)

// NewGatewayResource returns the runr_gateway resource.
func NewGatewayResource() resource.Resource { return &gatewayResource{} }

type gatewayResource struct{ client *Client }

type gatewayModel struct {
	ID                       types.String `tfsdk:"id"`
	RegionID                 types.String `tfsdk:"region_id"`
	RateLimit                types.Object `tfsdk:"rate_limit"`
	DeveloperPage            types.Bool   `tfsdk:"developer_page"`
	Description              types.String `tfsdk:"description"`
	DeveloperPageMetrics     types.Bool   `tfsdk:"developer_page_metrics"`
	CustomDomain             types.String `tfsdk:"custom_domain"`
	Verify                   types.Bool   `tfsdk:"verify"`
	VerifyTimeout            types.String `tfsdk:"verify_timeout"`
	CustomDomainVerified     types.Bool   `tfsdk:"custom_domain_verified"`
	CustomDomainVerification types.Object `tfsdk:"custom_domain_verification"`
	CustomDomainDNSTargets   types.List   `tfsdk:"custom_domain_dns_targets"`
	Subdomain                types.String `tfsdk:"subdomain"`
	Host                     types.String `tfsdk:"host"`
	Status                   types.String `tfsdk:"status"`
	StatusMessage            types.String `tfsdk:"status_message"`
	DeveloperPageKey         types.String `tfsdk:"developer_page_key"`
	DeveloperPageURL         types.String `tfsdk:"developer_page_url"`
}

type gatewayLimitModel struct {
	Average types.Int64 `tfsdk:"average"`
	Burst   types.Int64 `tfsdk:"burst"`
}

var gatewayLimitAttrTypes = map[string]attr.Type{"average": types.Int64Type, "burst": types.Int64Type}

var hostName = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)

func (r *gatewayResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_gateway"
}

func (r *gatewayResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	keepBool := []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "Your API gateway in a region ([API product](https://runr.sh/docs/api-product/)): " +
			"`https://<user>.<region>.<base domain>` or its custom domain, routing by path to projects " +
			"(`runr_gateway_route`). Every member has one gateway per region: the resource takes the existing one or " +
			"creates it, and manages its IP rate limit, public developer page and custom domain.\n\n" +
			"The API cannot delete a gateway: destroying the resource resets what it manages (no rate limit, no " +
			"developer page, no description, no custom domain) and forgets the gateway; its routes, consumers and " +
			"plans are resources of their own.\n\n" +
			"Import with the gateway id or the name or id of its region: `terraform import runr_gateway.eu eu-1`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Gateway id."},
			"region_id": schema.StringAttribute{Required: true,
				PlanModifiers:       []planmodifier.String{stringplanmodifier.RequiresReplace()},
				MarkdownDescription: "Region id (`data.runr_region`). The gateway routes to the workloads that run there. Changing it manages the gateway of the other region."},
			"rate_limit": schema.SingleNestedAttribute{Optional: true,
				MarkdownDescription: "Rate limit per client IP for every route of the gateway (Traefik, requests per second). " +
					"Left out: none (a limit the gateway has is removed).",
				Attributes: map[string]schema.Attribute{
					"average": schema.Int64Attribute{Required: true, Validators: []validator.Int64{int64validator.Between(1, 100000)},
						MarkdownDescription: "Requests per second (1-100000)."},
					"burst": schema.Int64Attribute{Optional: true, Computed: true,
						Validators:    []validator.Int64{int64validator.Between(1, 100000)},
						PlanModifiers: []planmodifier.Int64{int64FromSibling{sibling: "average"}},
						MarkdownDescription: "Requests at once (1-100000). Left out: `average` for a new limit or a changed " +
							"average, else the gateway's burst stays."},
				}},
			"developer_page": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Publish the public developer page at `developer_page_url`: the description, base URLs, " +
					"routes and how to send a key or token, without sign-in. Left out: kept as it is (a new gateway has none)."},
			"description": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				Validators:          []validator.String{stringvalidator.LengthAtMost(5000)},
				MarkdownDescription: "Text of the developer page. Left out: kept as it is; `\"\"` removes it."},
			"developer_page_metrics": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "The developer page also shows each key or JWT route's success rate and latency of the " +
					"last 7 days (never call counts or consumers). Left out: kept as it is (default `false`)."},
			"custom_domain": schema.StringAttribute{Optional: true,
				Validators: []validator.String{stringvalidator.LengthBetween(3, 253),
					stringvalidator.RegexMatches(hostName, "must be a lower-case host name without a trailing dot")},
				MarkdownDescription: "The gateway's own domain, e.g. `api.example.com`. It waits until RunR finds the TXT record " +
					"(`custom_domain_verification`) and one of the records in `custom_domain_dns_targets`, then the routes " +
					"answer on it (`verify = true` waits for that, or run `runr gateway domain verify`). Changing it requests " +
					"the new name, the verified one serves until the new one verifies. Left out: none (a domain the gateway " +
					"has is removed)."},
			"verify": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false),
				MarkdownDescription: "Wait until the custom domain is verified (default `false`). While the domain waits, every " +
					"plan checks it again."},
			"verify_timeout": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("10m"),
				MarkdownDescription: "How long `verify` waits (Go duration, default `10m`)."},
			"custom_domain_verified": schema.BoolAttribute{Computed: true,
				MarkdownDescription: "Whether `custom_domain` is verified and served."},
			"custom_domain_verification": schema.SingleNestedAttribute{Computed: true,
				MarkdownDescription: "The TXT record that proves the domain (`type`, `name`, `value`), while it waits.",
				Attributes: map[string]schema.Attribute{
					"type":  schema.StringAttribute{Computed: true, MarkdownDescription: "`TXT`."},
					"name":  schema.StringAttribute{Computed: true, MarkdownDescription: "Record name (`_runr-challenge.<domain>`)."},
					"value": schema.StringAttribute{Computed: true, MarkdownDescription: "Record value (`runr-verify=<token>`)."},
				}},
			"custom_domain_dns_targets": schema.ListAttribute{Computed: true, ElementType: dnsRecordType,
				MarkdownDescription: "Records that route the domain to the gateway, any one of them (`type`, `name`, `value`: a " +
					"CNAME to `host`, or an address of the region), while the domain waits."},
			"subdomain": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Subdomain (your user name)."},
			"host":      schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Default host of the gateway."},
			"status": schema.StringAttribute{Computed: true,
				MarkdownDescription: "`active`, `pending` or `error` (e.g. the key and JWT routes answer 503 until the region's gateway-auth is ready)."},
			"status_message":     schema.StringAttribute{Computed: true, MarkdownDescription: "Why the gateway is pending or in error."},
			"developer_page_key": schema.StringAttribute{Computed: true, MarkdownDescription: "Key of the developer page, while it is published."},
			"developer_page_url": schema.StringAttribute{Computed: true, MarkdownDescription: "URL of the developer page, while it is published."},
		},
	}
}

func (r *gatewayResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *gatewayResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var timeout types.String
	resp.Diagnostics.Append(req.Config.GetAttribute(ctx, path.Root("verify_timeout"), &timeout)...)
	if known(timeout) {
		if d, err := time.ParseDuration(timeout.ValueString()); err != nil || d <= 0 {
			resp.Diagnostics.AddAttributeError(path.Root("verify_timeout"), "Invalid duration", "Use a Go duration such as 10m.")
		}
	}
}

// ModifyPlan keeps the derived values a change does not touch: the developer page's key and URL
// while the page stays as it is, the custom domain's records and verification while the domain
// stays — unless verify waits for a domain that is still pending, which the apply checks again.
func (r *gatewayResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.State.Raw.IsNull() || req.Plan.Raw.IsNull() {
		return
	}
	var plan, state gatewayModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if known(plan.DeveloperPage) && plan.DeveloperPage.Equal(state.DeveloperPage) {
		plan.DeveloperPageKey, plan.DeveloperPageURL = state.DeveloperPageKey, state.DeveloperPageURL
	}
	switch {
	case plan.CustomDomain.IsUnknown() || !plan.CustomDomain.Equal(state.CustomDomain):
		if plan.CustomDomain.IsNull() {
			plan.CustomDomainVerified = types.BoolValue(false)
			plan.CustomDomainVerification = types.ObjectNull(dnsRecordType.AttrTypes)
			plan.CustomDomainDNSTargets = types.ListNull(dnsRecordType)
		}
	case plan.CustomDomain.IsNull() || state.CustomDomainVerified.ValueBool() || !plan.Verify.ValueBool():
		plan.CustomDomainVerified = state.CustomDomainVerified
		plan.CustomDomainVerification, plan.CustomDomainDNSTargets = state.CustomDomainVerification, state.CustomDomainDNSTargets
	default:
		// verify waits for a pending domain: the apply checks it again
		plan.CustomDomainVerified = types.BoolUnknown()
		plan.CustomDomainVerification = types.ObjectUnknown(dnsRecordType.AttrTypes)
		plan.CustomDomainDNSTargets = types.ListUnknown(dnsRecordType)
	}
	resp.Diagnostics.Append(resp.Plan.Set(ctx, &plan)...)
}

// limit is the planned rate limit (nil: none).
func (m *gatewayModel) limit(ctx context.Context, diags *diag.Diagnostics) *runr.GatewayLimit {
	if !known(m.RateLimit) {
		return nil
	}
	var l gatewayLimitModel
	diags.Append(m.RateLimit.As(ctx, &l, basetypes.ObjectAsOptions{})...)
	out := &runr.GatewayLimit{Average: int(l.Average.ValueInt64())}
	if known(l.Burst) {
		out.Burst = int(l.Burst.ValueInt64())
	}
	return out
}

func pendingDomain(g *runr.Gateway) string {
	if g.CustomDomainPending == nil {
		return ""
	}
	return g.CustomDomainPending.Domain
}

// apply makes the gateway what the plan says: rate limit, developer page, custom domain (and,
// with verify, its verification). It returns the gateway as it is after each step that ran.
func (r *gatewayResource) apply(ctx context.Context, plan *gatewayModel, g *runr.Gateway, diags *diag.Diagnostics) *runr.Gateway {
	fields := map[string]any{}
	want := plan.limit(ctx, diags)
	switch {
	case want == nil && g.RateLimit != nil:
		fields["rate_limit"] = want
	case want != nil && (g.RateLimit == nil || g.RateLimit.Average != want.Average || (want.Burst > 0 && g.RateLimit.Burst != want.Burst)):
		fields["rate_limit"] = want
	}
	if known(plan.DeveloperPage) && plan.DeveloperPage.ValueBool() != g.DeveloperPage {
		fields["developer_page"] = plan.DeveloperPage.ValueBool()
	}
	if known(plan.Description) && plan.Description.ValueString() != g.Description {
		fields["description"] = nullable(plan.Description)
	}
	if known(plan.DeveloperPageMetrics) && plan.DeveloperPageMetrics.ValueBool() != g.DeveloperPageMetrics {
		fields["developer_page_metrics"] = plan.DeveloperPageMetrics.ValueBool()
	}
	if len(fields) > 0 {
		updated, err := r.client.API.UpdateGateway(ctx, g.ID, fields)
		if err != nil {
			apiError(diags, "Update gateway failed", err)
			return g
		}
		g = updated
	}

	desired := plan.CustomDomain.ValueString()
	switch {
	case desired == "":
		if g.CustomDomain != "" || pendingDomain(g) != "" {
			updated, err := r.client.API.RemoveGatewayDomain(ctx, g.ID)
			if err != nil {
				apiError(diags, "Remove the gateway's custom domain failed", err)
				return g
			}
			g = updated
		}
	case desired == g.CustomDomain || desired == pendingDomain(g):
		// requested already: asking again would change the TXT token
	default:
		updated, err := r.client.API.SetGatewayDomain(ctx, g.ID, desired)
		if err != nil {
			apiError(diags, "Set the gateway's custom domain failed", err)
			return g
		}
		g = updated
	}
	if desired != "" && desired != g.CustomDomain && plan.Verify.ValueBool() {
		timeout, _ := time.ParseDuration(plan.VerifyTimeout.ValueString())
		verified, err := r.waitVerified(ctx, g.ID, timeout)
		if verified != nil {
			g = verified
		}
		if err != nil {
			diags.AddError("Custom domain not verified", err.Error()+
				"\n\nCreate the records in custom_domain_verification and custom_domain_dns_targets; the next apply checks again.")
		}
	}
	return g
}

func (r *gatewayResource) waitVerified(ctx context.Context, gatewayID string, timeout time.Duration) (*runr.Gateway, error) {
	if timeout <= 0 {
		timeout = 10 * time.Minute
	}
	deadline := time.Now().Add(timeout)
	for {
		g, err := r.client.API.VerifyGatewayDomain(ctx, gatewayID)
		var de *runr.GatewayDomainError
		if err == nil || !errors.As(err, &de) || time.Now().After(deadline) {
			return g, err
		}
		select {
		case <-ctx.Done():
			return g, ctx.Err()
		case <-time.After(r.client.PollInterval * 5):
		}
	}
}

func (r *gatewayResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan gatewayModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	g, err := r.client.API.EnsureGateway(ctx, plan.RegionID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Create gateway failed", err)
		return
	}
	// A failed step leaves nothing in the state: the gateway exists anyway, and the next apply
	// takes it again (a tainted gateway would be reset and its pending domain asked for again).
	g = r.apply(ctx, &plan, g, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.fromAPI(ctx, g, plan.CustomDomain, plan.Description, r.client.API.ConsoleURL(), &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *gatewayResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state gatewayModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	g, err := r.client.API.GetGateway(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read gateway failed", err)
		return
	}
	state.fromAPI(ctx, g, state.CustomDomain, state.Description, r.client.API.ConsoleURL(), &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *gatewayResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state gatewayModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	g, err := r.client.API.GetGateway(ctx, state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read gateway failed", err)
		return
	}
	var diags diag.Diagnostics
	g = r.apply(ctx, &plan, g, &diags)
	resp.Diagnostics.Append(diags...)
	if diags.HasError() {
		// the state says what was applied (e.g. a requested domain that is not verified yet)
		state.fromAPI(ctx, g, plan.CustomDomain, plan.Description, r.client.API.ConsoleURL(), &resp.Diagnostics)
		state.Verify, state.VerifyTimeout = plan.Verify, plan.VerifyTimeout
		resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
		return
	}
	plan.fromAPI(ctx, g, plan.CustomDomain, plan.Description, r.client.API.ConsoleURL(), &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *gatewayResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state gatewayModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	// No DELETE in the API: reset what the resource manages
	var none *runr.GatewayLimit
	g, err := r.client.API.UpdateGateway(ctx, state.ID.ValueString(), map[string]any{
		"rate_limit": none, "developer_page": false, "description": nil, "developer_page_metrics": false,
	})
	if runr.IsNotFound(err) {
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Reset gateway failed", err)
		return
	}
	if g.CustomDomain != "" || pendingDomain(g) != "" {
		if _, err := r.client.API.RemoveGatewayDomain(ctx, g.ID); err != nil && !runr.IsNotFound(err) {
			apiError(&resp.Diagnostics, "Remove the gateway's custom domain failed", err)
		}
	}
}

// ImportState accepts the gateway id or the name or id of its region.
func (r *gatewayResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	g, err := findGateway(ctx, r.client.API, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import gateway failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), g.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("region_id"), g.RegionID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("verify"), false)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("verify_timeout"), "10m")...)
}

// fromAPI sets the state from the gateway. domain is the custom domain the configuration or the
// state names: kept while the gateway serves it, also when another name waits for verification
// (requested outside Terraform); else the waiting name, else the served one.
func (m *gatewayModel) fromAPI(ctx context.Context, g *runr.Gateway, domain, description types.String, console string, diags *diag.Diagnostics) {
	m.ID = types.StringValue(g.ID)
	m.RegionID = types.StringValue(g.RegionID)
	m.Subdomain = types.StringValue(g.Subdomain)
	m.Host = types.StringValue(g.Host)
	m.Status = types.StringValue(g.Status)
	m.StatusMessage = strOrNull(g.StatusMessage)
	m.DeveloperPage = types.BoolValue(g.DeveloperPage)
	m.DeveloperPageMetrics = types.BoolValue(g.DeveloperPageMetrics)
	m.Description = keepEmpty(description, g.Description)
	m.DeveloperPageKey, m.DeveloperPageURL = types.StringNull(), types.StringNull()
	if g.DeveloperPageKey != "" {
		m.DeveloperPageKey = types.StringValue(g.DeveloperPageKey)
		m.DeveloperPageURL = types.StringValue(strings.TrimRight(console, "/") + "/developers/" + g.DeveloperPageKey)
	}

	m.RateLimit = types.ObjectNull(gatewayLimitAttrTypes)
	if g.RateLimit != nil {
		v, d := types.ObjectValue(gatewayLimitAttrTypes, map[string]attr.Value{
			"average": types.Int64Value(int64(g.RateLimit.Average)), "burst": types.Int64Value(int64(g.RateLimit.Burst)),
		})
		diags.Append(d...)
		m.RateLimit = v
	}

	pending := pendingDomain(g)
	name := g.CustomDomain
	switch {
	case known(domain) && domain.ValueString() != "" && domain.ValueString() == g.CustomDomain:
	case pending != "":
		name = pending
	}
	m.CustomDomain = strOrNull(name)
	m.CustomDomainVerified = types.BoolValue(name != "" && name == g.CustomDomain)
	m.CustomDomainVerification = types.ObjectNull(dnsRecordType.AttrTypes)
	m.CustomDomainDNSTargets = types.ListNull(dnsRecordType)
	if p := g.CustomDomainPending; p != nil && name == pending {
		v, d := types.ObjectValue(dnsRecordType.AttrTypes, map[string]attr.Value{
			"type": types.StringValue(p.Verification.Type), "name": types.StringValue(p.Verification.Name),
			"value": types.StringValue(p.Verification.Value),
		})
		diags.Append(d...)
		m.CustomDomainVerification = v
		recs := make([]attr.Value, 0, len(p.DNSTargets))
		for _, t := range p.DNSTargets {
			rec, d := types.ObjectValue(dnsRecordType.AttrTypes, map[string]attr.Value{
				"type": types.StringValue(t.Type), "name": types.StringValue(t.Name), "value": types.StringValue(t.Value),
			})
			diags.Append(d...)
			recs = append(recs, rec)
		}
		l, d := types.ListValue(dnsRecordType, recs)
		diags.Append(d...)
		m.CustomDomainDNSTargets = l
	}
}
