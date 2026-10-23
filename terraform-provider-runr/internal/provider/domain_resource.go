package provider

import (
	"context"
	"errors"
	"regexp"
	"strings"
	"time"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &domainResource{}
	_ resource.ResourceWithConfigure      = &domainResource{}
	_ resource.ResourceWithImportState    = &domainResource{}
	_ resource.ResourceWithValidateConfig = &domainResource{}
)

// NewDomainResource returns the runr_domain resource.
func NewDomainResource() resource.Resource { return &domainResource{} }

type domainResource struct{ client *Client }

type domainModel struct {
	ID                types.String `tfsdk:"id"`
	ProjectID         types.String `tfsdk:"project_id"`
	Environment       types.String `tfsdk:"environment"`
	Domain            types.String `tfsdk:"domain"`
	TLSMode           types.String `tfsdk:"tls_mode"`
	Certificate       types.String `tfsdk:"certificate"`
	PrivateKey        types.String `tfsdk:"private_key"`
	Verify            types.Bool   `tfsdk:"verify"`
	VerifyTimeout     types.String `tfsdk:"verify_timeout"`
	Status            types.String `tfsdk:"status"`
	URL               types.String `tfsdk:"url"`
	VerificationType  types.String `tfsdk:"verification_type"`
	VerificationName  types.String `tfsdk:"verification_name"`
	VerificationValue types.String `tfsdk:"verification_value"`
	DNSTargets        types.List   `tfsdk:"dns_targets"`
}

var dnsRecordType = types.ObjectType{AttrTypes: map[string]attr.Type{
	"type": types.StringType, "name": types.StringType, "value": types.StringType,
}}

func (r *domainResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_domain"
}

func (r *domainResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A custom domain of a project. Create the TXT record (`verification_name` / `verification_value`) " +
			"and the record in `dns_targets` at your DNS provider; the domain serves once it is verified " +
			"(`verify = true` waits for that, or run `runr domains verify`).\n\n" +
			"With `environment` the domain routes to one environment of the project, with the DNS targets and certificates of the " +
			"region the environment runs in. Import with `<project name or id>/<domain>` or " +
			"`<project name or id>/<environment>/<domain>`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Domain id."},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"environment": schema.StringAttribute{Optional: true, PlanModifiers: []planmodifier.String{environmentRequiresReplace()},
				MarkdownDescription: "Environment the domain routes to (`runr_environment.<name>.name` or its id). Left out (or " +
					"`production`): the project's own workload. Changing it adds the domain anew."},
			"domain": schema.StringAttribute{Required: true, MarkdownDescription: "Host name, e.g. `api.example.com`.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				Validators: []validator.String{stringvalidator.LengthBetween(3, 253), stringvalidator.RegexMatches(
					regexp.MustCompile(`^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$`), "must be a lower-case host name without a trailing dot")}},
			"tls_mode": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "HTTPS certificate: `none` (the region's default certificate, which does not name the domain), " +
					"`cert-manager` (a certificate for the domain from the region's cert-manager issuer) or `custom`. Left out, " +
					"RunR chooses when the domain is created — `cert-manager` where the region issues certificates, `none` elsewhere — " +
					"and the mode the domain has is kept (also one set in the console or with `runr domains tls`).",
				Validators: []validator.String{stringvalidator.OneOf(runr.TLSNone, runr.TLSCertManager, runr.TLSCustom)}},
			"certificate": schema.StringAttribute{Optional: true, MarkdownDescription: "PEM certificate chain (`tls_mode = \"custom\"`)."},
			"private_key": schema.StringAttribute{Optional: true, Sensitive: true, MarkdownDescription: "PEM private key (`tls_mode = \"custom\"`)."},
			"verify": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false),
				MarkdownDescription: "Wait at create until the DNS records are found and the domain is active (default `false`)."},
			"verify_timeout": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("10m"),
				MarkdownDescription: "How long `verify` waits (Go duration, default `10m`)."},
			"status":             schema.StringAttribute{Computed: true, MarkdownDescription: "`pending`, `verified`, `active` or `error`."},
			"url":                schema.StringAttribute{Computed: true, MarkdownDescription: "URL of the domain."},
			"verification_type":  schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Ownership record type (`TXT`)."},
			"verification_name":  schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Ownership record name."},
			"verification_value": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Ownership record value."},
			"dns_targets": schema.ListAttribute{Computed: true, ElementType: dnsRecordType,
				MarkdownDescription: "Records that route the domain to the project (`type`, `name`, `value`)."},
		},
	}
}

func (r *domainResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *domainResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m domainModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || m.TLSMode.IsUnknown() {
		return
	}
	custom := m.TLSMode.ValueString() == runr.TLSCustom // null = RunR's choice, never custom
	if custom && (m.Certificate.IsNull() || m.PrivateKey.IsNull()) {
		resp.Diagnostics.AddAttributeError(path.Root("certificate"), "certificate and private_key required", "tls_mode custom needs both.")
	}
	if !custom && (!m.Certificate.IsNull() || !m.PrivateKey.IsNull()) {
		resp.Diagnostics.AddAttributeError(path.Root("tls_mode"), "certificate given without tls_mode custom", "Set tls_mode = \"custom\" or remove the certificate.")
	}
	if known(m.VerifyTimeout) {
		if _, err := time.ParseDuration(m.VerifyTimeout.ValueString()); err != nil {
			resp.Diagnostics.AddAttributeError(path.Root("verify_timeout"), "Invalid duration", err.Error())
		}
	}
}

func (r *domainResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan domainModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid, env := plan.ProjectID.ValueString(), environmentRef(plan.Environment)
	// tls_mode left out (unknown): the mode RunR gives the new domain stays
	wantTLS := plan.TLSMode
	d, err := r.client.API.AddEnvironmentDomain(ctx, pid, env, plan.Domain.ValueString())
	if err != nil {
		environmentError(&resp.Diagnostics, "Add domain failed", err)
		return
	}
	// Keep the domain in the state even if a later step fails, so it is not orphaned.
	plan.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)

	if mode := wantTLS.ValueString(); known(wantTLS) && mode != d.TLSMode {
		d, err = r.client.API.SetDomainTLS(ctx, pid, d.ID, mode, plan.Certificate.ValueString(), plan.PrivateKey.ValueString())
		if err != nil {
			apiError(&resp.Diagnostics, "Set domain TLS failed", err)
			return
		}
	}
	if plan.Verify.ValueBool() {
		timeout, _ := time.ParseDuration(plan.VerifyTimeout.ValueString())
		if d, err = r.waitVerified(ctx, pid, env, d.ID, timeout); err != nil {
			apiError(&resp.Diagnostics, "Domain verification failed", err)
			if d != nil {
				plan.fromAPI(ctx, d, &resp.Diagnostics)
				resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
			}
			return
		}
	}
	plan.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *domainResource) waitVerified(ctx context.Context, projectID, environment, domainID string, timeout time.Duration) (*runr.Domain, error) {
	if timeout <= 0 {
		timeout = 10 * time.Minute
	}
	deadline := time.Now().Add(timeout)
	for {
		d, err := r.client.API.VerifyEnvironmentDomain(ctx, projectID, environment, domainID)
		var ve *runr.DomainVerifyError
		if err == nil {
			return d, nil
		}
		if !errors.As(err, &ve) {
			return d, err
		}
		if time.Now().After(deadline) {
			return d, err
		}
		select {
		case <-ctx.Done():
			return d, ctx.Err()
		case <-time.After(r.client.PollInterval * 5):
		}
	}
}

func (r *domainResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state domainModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := state.ProjectID.ValueString()
	d, err := r.client.API.GetEnvironmentDomain(ctx, pid, environmentRef(state.Environment), state.ID.ValueString())
	if err != nil && environmentGone(ctx, r.client, pid, err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		environmentError(&resp.Diagnostics, "Read domain failed", err)
		return
	}
	state.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *domainResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state domainModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.ID = state.ID
	d, err := r.client.API.GetEnvironmentDomain(ctx, state.ProjectID.ValueString(), environmentRef(state.Environment), state.ID.ValueString())
	if err != nil {
		environmentError(&resp.Diagnostics, "Read domain failed", err)
		return
	}
	tlsChanged := (known(plan.TLSMode) && !plan.TLSMode.Equal(state.TLSMode)) ||
		!plan.Certificate.Equal(state.Certificate) || !plan.PrivateKey.Equal(state.PrivateKey)
	if tlsChanged && !known(plan.TLSMode) {
		plan.TLSMode = types.StringValue(d.TLSMode)
	}
	if tlsChanged {
		d, err = r.client.API.SetDomainTLS(ctx, state.ProjectID.ValueString(), d.ID, plan.TLSMode.ValueString(),
			plan.Certificate.ValueString(), plan.PrivateKey.ValueString())
		if err != nil {
			apiError(&resp.Diagnostics, "Set domain TLS failed", err)
			return
		}
	}
	plan.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *domainResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state domainModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.DeleteDomain(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Remove domain failed", err)
	}
}

// ImportState accepts "<project id or name>/<domain or domain id>" (a domain of the project's own
// workload) or "<project id or name>/<environment>/<domain or domain id>".
func (r *domainResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	parts := strings.Split(req.ID, "/")
	if (len(parts) != 2 && len(parts) != 3) || parts[0] == "" || parts[len(parts)-1] == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<domain> or <project id or name>/<environment>/<domain>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, parts[0])
	if err != nil {
		apiError(&resp.Diagnostics, "Import domain failed", err)
		return
	}
	env := ""
	if len(parts) == 3 {
		env = parts[1]
	}
	d, err := r.client.API.FindEnvironmentDomain(ctx, p.ID, environmentRef(types.StringValue(env)), parts[len(parts)-1])
	if err != nil {
		environmentError(&resp.Diagnostics, "Import domain failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), d.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	if env != "" {
		resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("environment"), env)...)
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("verify"), false)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("verify_timeout"), "10m")...)
}

func (m *domainModel) fromAPI(ctx context.Context, d *runr.Domain, diags *diag.Diagnostics) {
	m.ID = types.StringValue(d.ID)
	m.ProjectID = types.StringValue(d.ProjectID)
	m.Domain = types.StringValue(d.Domain)
	m.TLSMode = types.StringValue(d.TLSMode)
	m.Status = types.StringValue(d.Status)
	m.URL = types.StringValue(d.URL)
	m.VerificationType = types.StringValue(d.Verification.Type)
	m.VerificationName = types.StringValue(d.Verification.Name)
	m.VerificationValue = types.StringValue(d.Verification.Value)
	type rec struct {
		Type  types.String `tfsdk:"type"`
		Name  types.String `tfsdk:"name"`
		Value types.String `tfsdk:"value"`
	}
	recs := make([]rec, 0, len(d.DNSTargets))
	for _, t := range d.DNSTargets {
		recs = append(recs, rec{Type: types.StringValue(t.Type), Name: types.StringValue(t.Name), Value: types.StringValue(t.Value)})
	}
	l, dd := types.ListValueFrom(ctx, dnsRecordType, recs)
	diags.Append(dd...)
	m.DNSTargets = l
}
