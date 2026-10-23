package provider

import (
	"context"
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewSecretStoreResource) }

var (
	_ resource.Resource                   = &secretStoreResource{}
	_ resource.ResourceWithConfigure      = &secretStoreResource{}
	_ resource.ResourceWithImportState    = &secretStoreResource{}
	_ resource.ResourceWithValidateConfig = &secretStoreResource{}
)

// NewSecretStoreResource returns the runr_secret_store resource.
func NewSecretStoreResource() resource.Resource { return &secretStoreResource{} }

type secretStoreResource struct{ client *Client }

type secretStoreModel struct {
	ID              types.String `tfsdk:"id"`
	Name            types.String `tfsdk:"name"`
	Type            types.String `tfsdk:"type"`
	Auth            types.String `tfsdk:"auth"`
	Settings        types.Map    `tfsdk:"settings"`
	RefreshInterval types.Int64  `tfsdk:"refresh_interval"`
	KubernetesName  types.String `tfsdk:"kubernetes_name"`
	Audience        types.String `tfsdk:"audience"`
	References      types.Int64  `tfsdk:"references"`
	Regions         types.Map    `tfsdk:"regions"`
}

var secretStoreRegionAttrTypes = map[string]attr.Type{
	"status": types.StringType, "message": types.StringType, "checked_at": types.StringType,
}

var secretStoreNamePattern = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?$`)

func (r *secretStoreResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_secret_store"
}

func (r *secretStoreResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A secret store of the organization ([secret stores](https://runr.sh/docs/secret-stores/)): " +
			"HashiCorp Vault or OpenBao, AWS Secrets Manager, GCP Secret Manager or Azure Key Vault. Secret variables refer " +
			"to values in it (`runr_env_var.reference`); External Secrets Operator reads them in the project's region.\n\n" +
			"A store holds settings only — addresses, mounts, roles — never a credential: it trusts the region cluster's " +
			"service account issuer and the service account RunR names (`kubernetes_name`). The data source " +
			"`runr_secret_store_trust` gives what to configure on the store's side per region. Changes reach the regions " +
			"with the next deploy of a project that refers to the store.\n\n" +
			"Deleting a store is refused while a secret variable refers to it. Needs `secret_stores.manage`. " +
			"Import with the store's id or name.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Store id."},
			"name": schema.StringAttribute{Required: true,
				Validators: []validator.String{stringvalidator.LengthAtMost(63),
					stringvalidator.RegexMatches(secretStoreNamePattern, "must be lower case letters, digits and dashes, starting and ending with a letter or digit")},
				MarkdownDescription: "Name, unique in the organization (lower case letters, digits and dashes, at most 63). Can be changed in place."},
			// "provider" is a meta-argument of every resource block: the API's provider is "type" here.
			"type": schema.StringAttribute{Required: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()},
				Validators:    []validator.String{stringvalidator.OneOf("vault", "aws", "gcp", "azure")},
				MarkdownDescription: "The store's provider (the API's `provider`): `vault` (HashiCorp Vault or OpenBao), `aws` " +
					"(Secrets Manager), `gcp` (Secret Manager) or `azure` (Key Vault). Changing it creates a new store."},
			"auth": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				Validators: []validator.String{stringvalidator.OneOf("kubernetes", "jwt", "workload_identity")},
				MarkdownDescription: "How the store authenticates the region: `kubernetes` (default) or `jwt` for `vault`, " +
					"`workload_identity` for the clouds. Left out, the store keeps its current one."},
			"settings": schema.MapAttribute{Required: true, ElementType: types.StringType,
				MarkdownDescription: "The provider's settings (by `type`); all of them (a setting removed here is removed from the store). " +
					"`vault`: `server` and `role` (required), `mount`, `kv_version` (`v1` or `v2`), `vault_namespace`, `ca_bundle` " +
					"(PEM), `auth_mount`, `audience` (`jwt`). `aws`: `region`, `role_arn` (required), `audience`. `gcp`: " +
					"`project_id`, `audience` (the workload identity provider; required), `service_account_email`. `azure`: " +
					"`vault_url` (https), `tenant_id`, `client_id` (required). Any other key is refused — RunR takes no credential."},
			"refresh_interval": schema.Int64Attribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()},
				Validators:    []validator.Int64{int64validator.Between(60, 86400)},
				MarkdownDescription: "Seconds between syncs of the values (60–86400). Left out: a new store syncs at the " +
					"installation's interval (default 3600), an existing one keeps its interval."},
			"kubernetes_name": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Name of the store's SecretStore and service account in a region's project namespace."},
			"audience": schema.StringAttribute{Computed: true,
				MarkdownDescription: "Audience of the service account tokens the store logs in with (null for Vault's Kubernetes auth)."},
			"references": schema.Int64Attribute{Computed: true, MarkdownDescription: "Number of secret variables that refer to the store."},
			"regions": schema.MapNestedAttribute{Computed: true,
				MarkdownDescription: "The store's state per region id, as the last verification found it.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"status":     schema.StringAttribute{Computed: true, MarkdownDescription: "`ready`, `failed` or `pending`."},
					"message":    schema.StringAttribute{Computed: true, MarkdownDescription: "Why, when not ready."},
					"checked_at": schema.StringAttribute{Computed: true, MarkdownDescription: "When it was checked."},
				}}},
		},
	}
}

func (r *secretStoreResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *secretStoreResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m secretStoreModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || !known(m.Type) {
		return
	}
	provider := m.Type.ValueString()
	allowed, ok := runr.SecretStoreProviders[provider]
	if !ok {
		return // the attribute's validator reports it
	}
	if known(m.Auth) {
		valid := false
		for _, a := range allowed {
			valid = valid || a == m.Auth.ValueString()
		}
		if !valid {
			resp.Diagnostics.AddAttributeError(path.Root("auth"), "Invalid auth",
				fmt.Sprintf("A %s store authenticates with %s.", provider, strings.Join(allowed, " or ")))
		}
	}
	if !known(m.Settings) {
		return
	}
	takes := runr.SecretStoreSettings[provider]
	settings := map[string]types.String{}
	resp.Diagnostics.Append(m.Settings.ElementsAs(ctx, &settings, false)...)
	for key, v := range settings {
		if _, ok := takes[key]; !ok {
			resp.Diagnostics.AddAttributeError(path.Root("settings").AtMapKey(key), "Unknown setting",
				fmt.Sprintf("%q is not a setting of a %s store (it takes %s). A store trusts the region's identity; RunR takes no credential for it.",
					key, provider, strings.Join(storeSettingKeys(takes), ", ")))
			continue
		}
		if known(v) && strings.TrimSpace(v.ValueString()) == "" {
			resp.Diagnostics.AddAttributeError(path.Root("settings").AtMapKey(key), "Empty setting",
				"Leave the setting out instead of setting it empty.")
		}
	}
	for _, key := range storeSettingKeys(takes) {
		if v, set := settings[key]; takes[key] && !set {
			resp.Diagnostics.AddAttributeError(path.Root("settings"), "Missing setting",
				fmt.Sprintf("A %s store needs the setting %q.", provider, key))
		} else if set && known(v) && key == "kv_version" && v.ValueString() != "v1" && v.ValueString() != "v2" {
			resp.Diagnostics.AddAttributeError(path.Root("settings").AtMapKey(key), "Invalid kv_version", "Use v1 or v2.")
		}
	}
}

func storeSettingKeys[V any](m map[string]V) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

// secretStoreToState copies what the API answered into m. The settings keep the configuration's
// spelling where the API's is the same value trimmed (the API trims every string).
func secretStoreToState(ctx context.Context, m *secretStoreModel, s *runr.SecretStore, diags *diag.Diagnostics) {
	m.ID = types.StringValue(s.ID)
	m.Name = types.StringValue(s.Name)
	m.Type = types.StringValue(s.Provider)
	m.Auth = types.StringValue(s.Auth)
	prior := map[string]string{}
	if known(m.Settings) {
		diags.Append(m.Settings.ElementsAs(ctx, &prior, false)...)
	}
	settings := map[string]string{}
	for k, v := range s.Settings {
		if p, ok := prior[k]; ok && strings.TrimSpace(p) == v {
			v = p
		}
		settings[k] = v
	}
	sm, d := types.MapValueFrom(ctx, types.StringType, settings)
	diags.Append(d...)
	m.Settings = sm
	m.RefreshInterval = types.Int64Value(int64(s.RefreshInterval))
	m.KubernetesName = types.StringValue(s.KubernetesName)
	m.Audience = strOrNull(s.Audience)
	m.References = types.Int64Value(int64(s.References))
	regions := map[string]attr.Value{}
	for id, st := range s.Regions {
		regions[id] = types.ObjectValueMust(secretStoreRegionAttrTypes, map[string]attr.Value{
			"status": types.StringValue(st.Status), "message": strOrNull(st.Message), "checked_at": strOrNull(st.CheckedAt),
		})
	}
	rm, d := types.MapValue(types.ObjectType{AttrTypes: secretStoreRegionAttrTypes}, regions)
	diags.Append(d...)
	m.Regions = rm
}

func (r *secretStoreResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan secretStoreModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := runr.SecretStoreInput{Name: plan.Name.ValueString(), Provider: plan.Type.ValueString(), Settings: map[string]string{}}
	resp.Diagnostics.Append(plan.Settings.ElementsAs(ctx, &in.Settings, false)...)
	if known(plan.Auth) {
		in.Auth = plan.Auth.ValueString()
	}
	if known(plan.RefreshInterval) {
		in.RefreshInterval = int(plan.RefreshInterval.ValueInt64())
	}
	if resp.Diagnostics.HasError() {
		return
	}
	s, err := r.client.API.CreateSecretStore(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create secret store failed", err)
		return
	}
	secretStoreToState(ctx, &plan, s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *secretStoreResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state secretStoreModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	s, err := r.client.API.GetSecretStore(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read secret store failed", err)
		return
	}
	secretStoreToState(ctx, &state, s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *secretStoreResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state secretStoreModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	want, had := map[string]string{}, map[string]string{}
	resp.Diagnostics.Append(plan.Settings.ElementsAs(ctx, &want, false)...)
	if known(state.Settings) {
		resp.Diagnostics.Append(state.Settings.ElementsAs(ctx, &had, false)...)
	}
	if resp.Diagnostics.HasError() {
		return
	}
	// The settings are merged into the store's: the configuration's, and null for those it
	// no longer has.
	u := runr.SecretStoreUpdate{Settings: map[string]*string{}}
	for k, v := range want {
		u.Settings[k] = &v
	}
	for k := range had {
		if _, ok := want[k]; !ok {
			u.Settings[k] = nil
		}
	}
	if plan.Name.ValueString() != state.Name.ValueString() {
		u.Name = strPtr(plan.Name)
	}
	if known(plan.Auth) && plan.Auth.ValueString() != state.Auth.ValueString() {
		u.Auth = strPtr(plan.Auth)
	}
	if known(plan.RefreshInterval) && plan.RefreshInterval.ValueInt64() != state.RefreshInterval.ValueInt64() {
		u.RefreshInterval = intPtr(plan.RefreshInterval)
	}
	s, err := r.client.API.UpdateSecretStore(ctx, state.ID.ValueString(), u)
	if err != nil {
		apiError(&resp.Diagnostics, "Update secret store failed", err)
		return
	}
	secretStoreToState(ctx, &plan, s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *secretStoreResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state secretStoreModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteSecretStore(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete secret store failed", err)
	}
}

// ImportState accepts the store's id or name.
func (r *secretStoreResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	s, err := r.client.API.FindSecretStore(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import secret store failed", err)
		return
	}
	m := secretStoreModel{Settings: types.MapNull(types.StringType)}
	secretStoreToState(ctx, &m, s, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
