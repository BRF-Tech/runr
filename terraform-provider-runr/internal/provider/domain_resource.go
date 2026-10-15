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

	"github.com/runrsh/core/cli/pkg/runr"
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
			"(`verify = true` waits for that, or run `runr domains verify`).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Domain id."},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"domain": schema.StringAttribute{Required: true, MarkdownDescription: "Host name, e.g. `api.example.com`.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				Validators: []validator.String{stringvalidator.LengthBetween(3, 253), stringvalidator.RegexMatches(
					regexp.MustCompile(`^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$`), "must be a lower-case host name without a trailing dot")}},
			"tls_mode": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(runr.TLSNone),
				MarkdownDescription: "HTTPS certificate: `none` (the region's default), `cert-manager` or `custom`.",
				Validators:          []validator.String{stringvalidator.OneOf(runr.TLSNone, runr.TLSCertManager, runr.TLSCustom)}},
			"certificate": schema.StringAttribute{Optional: true, MarkdownDescription: "PEM certificate chain (`tls_mode = \"custom\"`)."},
			"private_key": schema.StringAttribute{Optional: true, Sensitive: true, MarkdownDescription: "PEM private key (`tls_mode = \"custom\"`)."},
			"verify": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false),
				MarkdownDescription: "Wait at create until the DNS records are found and the domain is active."},
			"verify_timeout": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("10m"),
				MarkdownDescription: "How long `verify` waits (Go duration)."},
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
	custom := m.TLSMode.ValueString() == runr.TLSCustom // null = the default, none
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
	pid := plan.ProjectID.ValueString()
	d, err := r.client.API.AddDomain(ctx, pid, plan.Domain.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Add domain failed", err)
		return
	}
	// Keep the domain in the state even if a later step fails, so it is not orphaned.
	plan.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)

	if mode := plan.TLSMode.ValueString(); mode != "" && mode != d.TLSMode {
		d, err = r.client.API.SetDomainTLS(ctx, pid, d.ID, mode, plan.Certificate.ValueString(), plan.PrivateKey.ValueString())
		if err != nil {
			apiError(&resp.Diagnostics, "Set domain TLS failed", err)
			return
		}
	}
	if plan.Verify.ValueBool() {
		timeout, _ := time.ParseDuration(plan.VerifyTimeout.ValueString())
		if d, err = r.waitVerified(ctx, pid, d.ID, timeout); err != nil {
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

func (r *domainResource) waitVerified(ctx context.Context, projectID, domainID string, timeout time.Duration) (*runr.Domain, error) {
	if timeout <= 0 {
		timeout = 10 * time.Minute
	}
	deadline := time.Now().Add(timeout)
	for {
		d, err := r.client.API.VerifyDomain(ctx, projectID, domainID)
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
	d, err := r.client.API.GetDomain(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	var nf *runr.NotFoundError
	if errors.As(err, &nf) || runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read domain failed", err)
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
	d, err := r.client.API.GetDomain(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read domain failed", err)
		return
	}
	tlsChanged := !plan.TLSMode.Equal(state.TLSMode) || !plan.Certificate.Equal(state.Certificate) || !plan.PrivateKey.Equal(state.PrivateKey)
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

// ImportState accepts "<project id or name>/<domain or domain id>".
func (r *domainResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	proj, host, ok := strings.Cut(req.ID, "/")
	if !ok || proj == "" || host == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<domain>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, proj)
	if err != nil {
		apiError(&resp.Diagnostics, "Import domain failed", err)
		return
	}
	d, err := r.client.API.FindDomain(ctx, p.ID, host)
	if err != nil {
		apiError(&resp.Diagnostics, "Import domain failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), d.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
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
