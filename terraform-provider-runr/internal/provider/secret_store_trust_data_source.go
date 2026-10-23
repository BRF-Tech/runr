package provider

import (
	"context"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/datasource/schema"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerDataSource(NewSecretStoreTrustDataSource) }

var _ datasource.DataSourceWithConfigure = &secretStoreTrustDataSource{}

// NewSecretStoreTrustDataSource returns the runr_secret_store_trust data source.
func NewSecretStoreTrustDataSource() datasource.DataSource { return &secretStoreTrustDataSource{} }

type secretStoreTrustDataSource struct{ client *Client }

type secretStoreTrustModel struct {
	StoreID           types.String `tfsdk:"store_id"`
	RegionID          types.String `tfsdk:"region_id"`
	RegionName        types.String `tfsdk:"region_name"`
	Issuer            types.String `tfsdk:"issuer"`
	JWKSURI           types.String `tfsdk:"jwks_uri"`
	JWKS              types.String `tfsdk:"jwks"`
	PEMKeys           types.List   `tfsdk:"pem_keys"`
	PubliclyReachable types.Bool   `tfsdk:"publicly_reachable"`
	Namespace         types.String `tfsdk:"namespace"`
	ServiceAccount    types.String `tfsdk:"service_account"`
	Subject           types.String `tfsdk:"subject"`
	Audience          types.String `tfsdk:"audience"`
	ESOStatus         types.String `tfsdk:"eso_status"`
}

func (d *secretStoreTrustDataSource) Metadata(_ context.Context, req datasource.MetadataRequest, resp *datasource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_secret_store_trust"
}

func (d *secretStoreTrustDataSource) Schema(_ context.Context, _ datasource.SchemaRequest, resp *datasource.SchemaResponse) {
	str := func(desc string) schema.StringAttribute {
		return schema.StringAttribute{Computed: true, MarkdownDescription: desc}
	}
	resp.Schema = schema.Schema{
		MarkdownDescription: "What a secret store has to trust so a region reads from it without a credential: the region " +
			"cluster's service account issuer and its keys, the service account RunR names in the project namespace, the " +
			"token subject and audience. Use it to configure the store's side — a Vault / OpenBao Kubernetes or JWT auth " +
			"role, an AWS IAM OIDC provider and role trust policy, a GCP workload identity pool provider, an Azure " +
			"federated credential. Needs `secret_stores.view`.",
		Attributes: map[string]schema.Attribute{
			"store_id":           schema.StringAttribute{Required: true, MarkdownDescription: "Store id (`runr_secret_store.<name>.id`) or name."},
			"region_id":          schema.StringAttribute{Required: true, MarkdownDescription: "Region id (`data.runr_region`)."},
			"region_name":        str("Region name."),
			"issuer":             str("The cluster's service account issuer URL."),
			"jwks_uri":           str("URL of the issuer's keys (JWKS)."),
			"jwks":               str("The issuer's keys as a JWKS document (JSON)."),
			"pem_keys":           schema.ListAttribute{Computed: true, ElementType: types.StringType, MarkdownDescription: "The issuer's public keys as PEM (Vault's `jwt_validation_pubkeys`)."},
			"publicly_reachable": schema.BoolAttribute{Computed: true, MarkdownDescription: "Whether a cloud provider could fetch the issuer's discovery document (a hint; the provider decides). When false, give the keys to the store instead of the issuer URL."},
			"namespace":          str("The region's project namespace."),
			"service_account":    str("The service account the store logs in with (the store's `kubernetes_name`)."),
			"subject":            str("Subject of its tokens: `system:serviceaccount:<namespace>:<service account>`."),
			"audience":           str("Audience of its tokens (null for Vault's Kubernetes auth)."),
			"eso_status":         str("External Secrets Operator in the region: `ready`, `not_installed`, `installing`, `uninstalling` or `error`."),
		},
	}
}

func (d *secretStoreTrustDataSource) Configure(_ context.Context, req datasource.ConfigureRequest, resp *datasource.ConfigureResponse) {
	d.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (d *secretStoreTrustDataSource) Read(ctx context.Context, req datasource.ReadRequest, resp *datasource.ReadResponse) {
	if notConfigured(d.client, &resp.Diagnostics) {
		return
	}
	var m secretStoreTrustModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	storeID := m.StoreID.ValueString()
	if !runr.LooksLikeULID(storeID) {
		s, err := d.client.API.FindSecretStore(ctx, storeID)
		if err != nil {
			apiError(&resp.Diagnostics, "Secret store lookup failed", err)
			return
		}
		storeID = s.ID
	}
	t, err := d.client.API.SecretStoreTrust(ctx, storeID, m.RegionID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read secret store trust failed", err)
		return
	}
	m.RegionName = strOrNull(t.Region.Name)
	m.Issuer = types.StringValue(t.Issuer)
	m.JWKSURI = strOrNull(t.JWKSURI)
	m.JWKS = types.StringNull()
	if len(t.JWKS) > 0 && string(t.JWKS) != "null" {
		m.JWKS = types.StringValue(string(t.JWKS))
	}
	pem, diags := types.ListValueFrom(ctx, types.StringType, append([]string{}, t.PEMKeys...))
	resp.Diagnostics.Append(diags...)
	m.PEMKeys = pem
	m.PubliclyReachable = types.BoolValue(t.PubliclyReachable)
	m.Namespace = types.StringValue(t.Namespace)
	m.ServiceAccount = types.StringValue(t.ServiceAccount)
	m.Subject = types.StringValue(t.Subject)
	m.Audience = strOrNull(t.Audience)
	m.ESOStatus = strOrNull(t.ESOStatus)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
