package provider

import (
	"context"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &brandingResource{}
	_ resource.ResourceWithConfigure      = &brandingResource{}
	_ resource.ResourceWithImportState    = &brandingResource{}
	_ resource.ResourceWithValidateConfig = &brandingResource{}
)

func init() { registerResource(NewBrandingResource) }

// NewBrandingResource returns the runr_branding resource.
func NewBrandingResource() resource.Resource { return &brandingResource{} }

type brandingResource struct{ client *Client }

type brandingModel struct {
	ID                    types.String `tfsdk:"id"`
	Scope                 types.String `tfsdk:"scope"`
	ProductName           types.String `tfsdk:"product_name"`
	CompanyName           types.String `tfsdk:"company_name"`
	PrimaryColor          types.String `tfsdk:"primary_color"`
	AccentColor           types.String `tfsdk:"accent_color"`
	LoginHeadline         types.String `tfsdk:"login_headline"`
	LoginText             types.String `tfsdk:"login_text"`
	SupportEmail          types.String `tfsdk:"support_email"`
	SupportURL            types.String `tfsdk:"support_url"`
	EmailFromName         types.String `tfsdk:"email_from_name"`
	LogoLightURL          types.String `tfsdk:"logo_light_url"`
	LogoDarkURL           types.String `tfsdk:"logo_dark_url"`
	LogoMarkURL           types.String `tfsdk:"logo_mark_url"`
	FaviconURL            types.String `tfsdk:"favicon_url"`
	EmailLogoURL          types.String `tfsdk:"email_logo_url"`
	FooterLinks           types.List   `tfsdk:"footer_links"`
	PoweredBy             types.Bool   `tfsdk:"powered_by"`
	TenantBrandingEnabled types.Bool   `tfsdk:"tenant_branding_enabled"`
}

// texts maps the API field names to the model's text attributes (runr.BrandingTextFields).
func (m *brandingModel) texts() map[string]*types.String {
	return map[string]*types.String{
		"product_name": &m.ProductName, "company_name": &m.CompanyName, "primary_color": &m.PrimaryColor,
		"accent_color": &m.AccentColor, "login_headline": &m.LoginHeadline, "login_text": &m.LoginText,
		"support_email": &m.SupportEmail, "support_url": &m.SupportURL, "email_from_name": &m.EmailFromName,
		"logo_light_url": &m.LogoLightURL, "logo_dark_url": &m.LogoDarkURL, "logo_mark_url": &m.LogoMarkURL,
		"favicon_url": &m.FaviconURL, "email_logo_url": &m.EmailLogoURL,
	}
}

var footerLinkType = types.ObjectType{AttrTypes: map[string]attr.Type{"label": types.StringType, "url": types.StringType}}

func (r *brandingResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_branding"
}

func (r *brandingResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	color := []validator.String{stringvalidator.RegexMatches(regexp.MustCompile(`^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$`), "must be a hex colour such as #2563eb")}
	text := func(desc string, max int) schema.StringAttribute {
		return schema.StringAttribute{Optional: true, MarkdownDescription: desc,
			Validators: []validator.String{stringvalidator.LengthBetween(1, max)}}
	}
	image := func(what string) schema.StringAttribute {
		return schema.StringAttribute{Optional: true,
			MarkdownDescription: what + ": an `https://` URL or a `data:` URI, e.g. " +
				"`\"data:image/png;base64,${filebase64(\"logo.png\")}\"`. An image uploaded in the console stays while this is unset."}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "The white-label brand of the console, login page and e-mails (one per scope). " +
			"Terraform owns the whole brand: attributes left out use RunR's defaults (for an organization: the install's brand), " +
			"and destroying the resource resets the brand. Images can be set as URLs or `data:` URIs; uploads stay in the console.\n\n" +
			"`scope = \"install\"` is the installation's brand (`branding.manage`; multi-tenant: platform administrators), " +
			"`scope = \"organization\"` an organization's own brand in the multi-tenant edition (`branding.manage` in the " +
			"organization, while the platform allows organization brands).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "The scope.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"scope": schema.StringAttribute{Optional: true, Computed: true,
				MarkdownDescription: "`install` or `organization`. Default: `organization` for members of a multi-tenant " +
					"installation who are not platform administrators, `install` otherwise.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplaceIfConfigured(), stringplanmodifier.UseStateForUnknown()},
				Validators:    []validator.String{stringvalidator.OneOf(runr.BrandingInstall, runr.BrandingOrganization)}},
			"product_name":   text("Product name shown in the console, the login page and e-mails.", 80),
			"company_name":   text("Company name (footer, e-mails).", 120),
			"primary_color":  schema.StringAttribute{Optional: true, MarkdownDescription: "Primary colour (`#rrggbb`).", Validators: color},
			"accent_color":   schema.StringAttribute{Optional: true, MarkdownDescription: "Accent colour (`#rrggbb`).", Validators: color},
			"login_headline": text("Headline of the login page.", 120),
			"login_text":     text("Text of the login page.", 600),
			"support_email": schema.StringAttribute{Optional: true, MarkdownDescription: "Support e-mail address.",
				Validators: []validator.String{stringvalidator.LengthBetween(3, 190), stringvalidator.RegexMatches(regexp.MustCompile(`^[^@\s]+@[^@\s]+$`), "must be an e-mail address")}},
			"support_url": schema.StringAttribute{Optional: true, MarkdownDescription: "Support page (`https://…`).",
				Validators: []validator.String{stringvalidator.LengthBetween(8, 500), stringvalidator.RegexMatches(regexp.MustCompile(`^https?://\S+$`), "must be an http or https URL")}},
			"email_from_name": text("Sender name of e-mails.", 120),
			"logo_light_url":  image("Logo for light backgrounds"),
			"logo_dark_url":   image("Logo for dark backgrounds"),
			"logo_mark_url":   image("Square logo mark"),
			"favicon_url":     image("Favicon"),
			"email_logo_url":  image("Logo of e-mails"),
			"footer_links": schema.ListNestedAttribute{Optional: true,
				MarkdownDescription: "Links of the console footer (at most 8).",
				Validators:          []validator.List{listvalidator.SizeAtMost(8)},
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"label": schema.StringAttribute{Required: true, MarkdownDescription: "Link text.",
						Validators: []validator.String{stringvalidator.LengthBetween(1, 60)}},
					"url": schema.StringAttribute{Required: true, MarkdownDescription: "`https://…`, `http://…` or `mailto:…`.",
						Validators: []validator.String{stringvalidator.RegexMatches(regexp.MustCompile(`(?i)^(https?://|mailto:)\S+$`), "must be an http(s) or mailto: link")}},
				}}},
			"powered_by": schema.BoolAttribute{Optional: true,
				MarkdownDescription: "Show \"Powered by RunR\" (install scope only)."},
			"tenant_branding_enabled": schema.BoolAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Allow organizations of a multi-tenant installation to set their own brand (install scope " +
					"only). Unset: the switch stays as it is set in the console; destroying the resource keeps it.",
				PlanModifiers: []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()}},
		},
	}
}

func (r *brandingResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m brandingModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || m.Scope.ValueString() != runr.BrandingOrganization {
		return
	}
	if !m.PoweredBy.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("powered_by"), "Install-only setting", "powered_by is a platform decision; set it on the install scope.")
	}
	if known(m.TenantBrandingEnabled) {
		resp.Diagnostics.AddAttributeError(path.Root("tenant_branding_enabled"), "Install-only setting",
			"tenant_branding_enabled is set on the install scope.")
	}
}

func (r *brandingResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// fields returns the PUT body for the model: every field the scope accepts, nil for unset
// ones (RunR's default).
func (r *brandingResource) fields(ctx context.Context, m *brandingModel, scope string, diags *diag.Diagnostics) map[string]any {
	out := map[string]any{}
	for name, v := range m.texts() {
		if known(*v) && v.ValueString() != "" {
			out[name] = v.ValueString()
		} else {
			out[name] = nil
		}
	}
	out["footer_links"] = nil
	if known(m.FooterLinks) {
		var links []struct {
			Label types.String `tfsdk:"label"`
			URL   types.String `tfsdk:"url"`
		}
		diags.Append(m.FooterLinks.ElementsAs(ctx, &links, false)...)
		list := make([]runr.FooterLink, 0, len(links))
		for _, l := range links {
			list = append(list, runr.FooterLink{Label: l.Label.ValueString(), URL: l.URL.ValueString()})
		}
		out["footer_links"] = list
	}
	if scope == runr.BrandingInstall {
		out["powered_by"] = nil
		if known(m.PoweredBy) {
			out["powered_by"] = m.PoweredBy.ValueBool()
		}
		if known(m.TenantBrandingEnabled) {
			out["tenant_overrides_enabled"] = m.TenantBrandingEnabled.ValueBool()
		}
	}
	return out
}

func (r *brandingResource) scope(ctx context.Context, m *brandingModel, diags *diag.Diagnostics) string {
	if known(m.Scope) {
		return m.Scope.ValueString()
	}
	s, err := r.client.API.BrandingScope(ctx)
	if err != nil {
		apiError(diags, "Read permissions failed", err)
		return ""
	}
	return s
}

// apply copies the stored brand into the model. An image uploaded in the console is kept out
// of the state while its URL attribute is unset.
func (r *brandingResource) apply(ctx context.Context, m *brandingModel, b *runr.BrandingSettings, diags *diag.Diagnostics) {
	m.ID = types.StringValue(b.Scope)
	m.Scope = types.StringValue(b.Scope)
	for name, v := range m.texts() {
		stored, ok := b.StoredString(name)
		switch {
		case !ok:
			*v = types.StringNull()
		case v.IsNull() && b.Uploaded(name):
			// uploaded in the console: not managed here
		default:
			*v = types.StringValue(stored)
		}
	}
	if links := b.FooterLinks(); len(links) > 0 {
		vals := make([]attr.Value, 0, len(links))
		for _, l := range links {
			o, d := types.ObjectValue(footerLinkType.AttrTypes, map[string]attr.Value{
				"label": types.StringValue(l.Label), "url": types.StringValue(l.URL)})
			diags.Append(d...)
			vals = append(vals, o)
		}
		lv, d := types.ListValue(footerLinkType, vals)
		diags.Append(d...)
		m.FooterLinks = lv
	} else if !(known(m.FooterLinks) && len(m.FooterLinks.Elements()) == 0) {
		m.FooterLinks = types.ListNull(footerLinkType)
	}
	if b.Scope == runr.BrandingInstall {
		if v, ok := b.Stored["powered_by"].(bool); ok {
			m.PoweredBy = types.BoolValue(v)
		} else {
			m.PoweredBy = types.BoolNull()
		}
	} else {
		m.PoweredBy = types.BoolNull()
	}
	m.TenantBrandingEnabled = types.BoolValue(b.TenantOverridesEnabled)
}

func (r *brandingResource) put(ctx context.Context, m *brandingModel, diags *diag.Diagnostics) {
	scope := r.scope(ctx, m, diags)
	if diags.HasError() {
		return
	}
	body := r.fields(ctx, m, scope, diags)
	if diags.HasError() {
		return
	}
	b, err := r.client.API.UpdateBranding(ctx, scope, body)
	if err != nil {
		apiError(diags, "Update branding failed", err)
		return
	}
	r.apply(ctx, m, b, diags)
}

func (r *brandingResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan brandingModel
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

func (r *brandingResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state brandingModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	scope := state.ID.ValueString()
	if scope == "" {
		scope = state.Scope.ValueString()
	}
	b, err := r.client.API.GetBranding(ctx, scope)
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read branding failed", err)
		return
	}
	r.apply(ctx, &state, b, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *brandingResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state brandingModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.Scope = state.Scope
	r.put(ctx, &plan, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// Delete resets the brand to its defaults.
func (r *brandingResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state brandingModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	scope := state.Scope.ValueString()
	reset := brandingModel{}
	body := r.fields(ctx, &reset, scope, &resp.Diagnostics)
	if _, err := r.client.API.UpdateBranding(ctx, scope, body); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Reset branding failed", err)
	}
}

// ImportState accepts the scope: install or organization.
func (r *brandingResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if req.ID != runr.BrandingInstall && req.ID != runr.BrandingOrganization {
		resp.Diagnostics.AddError("Invalid import id", "Use install or organization.")
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), req.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("scope"), req.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("footer_links"), types.ListNull(footerLinkType))...)
}
