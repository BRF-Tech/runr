package provider

import (
	"context"
	"regexp"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/resourcevalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64default"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/listplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewPrivateConnectionResource) }

var (
	_ resource.Resource                     = &privateConnectionResource{}
	_ resource.ResourceWithConfigure        = &privateConnectionResource{}
	_ resource.ResourceWithImportState      = &privateConnectionResource{}
	_ resource.ResourceWithConfigValidators = &privateConnectionResource{}
)

// NewPrivateConnectionResource returns the runr_private_connection resource.
func NewPrivateConnectionResource() resource.Resource { return &privateConnectionResource{} }

// A private connection of a project — of its own workload or of one of its environments — to a
// managed PostgreSQL database through Skupper (PrivateDatabaseBindingController). The API has no
// update: every argument replaces the connection.
type privateConnectionResource struct{ client *Client }

type privateConnectionModel struct {
	ID                   types.String `tfsdk:"id"`
	ProjectID            types.String `tfsdk:"project_id"`
	Environment          types.String `tfsdk:"environment"`
	Name                 types.String `tfsdk:"name"`
	EnvPrefix            types.String `tfsdk:"env_prefix"`
	RoutingKey           types.String `tfsdk:"routing_key"`
	Port                 types.Int64  `tfsdk:"port"`
	SSLMode              types.String `tfsdk:"sslmode"`
	LinkToken            types.String `tfsdk:"link_token"`
	LinkTokenWO          types.String `tfsdk:"link_token_wo"`
	Database             types.String `tfsdk:"database"`
	Username             types.String `tfsdk:"username"`
	Password             types.String `tfsdk:"password"`
	PasswordWO           types.String `tfsdk:"password_wo"`
	CredentialsWOVersion types.String `tfsdk:"credentials_wo_version"`
	ResourceName         types.String `tfsdk:"resource_name"`
	Host                 types.String `tfsdk:"host"`
	SecretName           types.String `tfsdk:"secret_name"`
	EnvNames             types.List   `tfsdk:"env_names"`
	LinkKind             types.String `tfsdk:"link_kind"`
	Status               types.String `tfsdk:"status"`
}

var (
	privateConnectionNamePattern    = regexp.MustCompile(`^[a-z]([-a-z0-9]*[a-z0-9])?$`)
	privateConnectionPrefixPattern  = regexp.MustCompile(`^([A-Z][A-Z0-9_]*_)?$`)
	privateConnectionRoutingPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:/-]*$`)
)

func (r *privateConnectionResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_private_connection"
}

func (r *privateConnectionResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	credential := []planmodifier.String{credentialRequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A private connection of a project to a managed PostgreSQL database through Skupper " +
			"([private database connections](https://runr.sh/docs/environments/#private-database-connections)): a Skupper Link, " +
			"a Listener and a credentials Secret in the project namespace of the region the workload runs in. The project's own " +
			"workload, or with `environment` one of its environments, gets `<env_prefix>DATABASE_URL`, `<env_prefix>PGHOST`, … " +
			"(`env_names`); the running workload gets them at once, else with its next deploy. The region must run Skupper v2; " +
			"global projects cannot have private connections.\n\n" +
			"The credentials (`link_token`, `database`, `username`, `password`) live in the region only and never come back from " +
			"the API: Terraform keeps what it set (the token and the password sensitive), or with `link_token_wo` / `password_wo` " +
			"(Terraform 1.11 and later) no token and password at all — change `credentials_wo_version` to connect with new ones. " +
			"RunR cannot change a connection: changing any argument replaces it (its workload is without the connection in " +
			"between). Needs `connections.manage` on the project. Import with `<project name or id>/<connection name or id>` " +
			"(the project's own workload) or `<project name or id>/<environment>/<connection name or id>`; the credentials are " +
			"not imported, the next apply takes the configured ones into the state without replacing the connection.",
		Attributes: map[string]schema.Attribute{
			"id":         schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Connection id."},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace, MarkdownDescription: "Project id."},
			"environment": schema.StringAttribute{Optional: true, PlanModifiers: []planmodifier.String{environmentRequiresReplace()},
				MarkdownDescription: "Environment the connection belongs to (`runr_environment.<name>.name` or its id); its connection " +
					"lives in the region that environment runs in, and only its workload gets the variables. Left out (or " +
					"`production`): the project's own workload."},
			"name": schema.StringAttribute{Required: true, PlanModifiers: replace,
				Validators: []validator.String{stringvalidator.LengthAtMost(30),
					stringvalidator.RegexMatches(privateConnectionNamePattern, "must be lowercase letters, digits and -, starting with a letter")},
				MarkdownDescription: "Name, unique per workload (at most 30 characters)."},
			"env_prefix": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(""), PlanModifiers: replace,
				Validators: []validator.String{stringvalidator.LengthAtMost(32),
					stringvalidator.RegexMatches(privateConnectionPrefixPattern, "must be upper case letters, digits and _, start with a letter and end with _")},
				MarkdownDescription: "Prefix of the variable names, e.g. `ORDERS_` (default none); unique per workload."},
			"routing_key": schema.StringAttribute{Required: true, PlanModifiers: replace,
				Validators: []validator.String{stringvalidator.LengthAtMost(253),
					stringvalidator.RegexMatches(privateConnectionRoutingPattern, "must start with a letter or digit and contain letters, digits, . _ : / -")},
				MarkdownDescription: "Skupper routing key of the database's connector."},
			"port": schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(5432),
				PlanModifiers:       []planmodifier.Int64{int64planmodifier.RequiresReplace()},
				Validators:          []validator.Int64{int64validator.Between(1, 65535)},
				MarkdownDescription: "Port of the local listener (default `5432`)."},
			"sslmode": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("require"), PlanModifiers: replace,
				Validators:          []validator.String{stringvalidator.OneOf("disable", "allow", "prefer", "require", "verify-ca", "verify-full")},
				MarkdownDescription: "PostgreSQL `sslmode` of the connection (default `require`)."},
			"link_token": schema.StringAttribute{Optional: true, Sensitive: true, PlanModifiers: credential,
				Validators:          []validator.String{stringvalidator.LengthBetween(1, 65535)},
				MarkdownDescription: "The Skupper v2 AccessToken, or Link with its TLS Secret (YAML), the database's site issued. One of `link_token` and `link_token_wo` is required."},
			"link_token_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true,
				Validators:          []validator.String{stringvalidator.LengthBetween(1, 65535)},
				MarkdownDescription: "`link_token` that Terraform never stores (write-only, Terraform 1.11 and later); change `credentials_wo_version` to use a new one."},
			"database": schema.StringAttribute{Required: true, PlanModifiers: credential,
				Validators: []validator.String{stringvalidator.LengthBetween(1, 63)}, MarkdownDescription: "Database name."},
			"username": schema.StringAttribute{Required: true, PlanModifiers: credential,
				Validators: []validator.String{stringvalidator.LengthBetween(1, 63)}, MarkdownDescription: "Database user."},
			"password": schema.StringAttribute{Optional: true, Sensitive: true, PlanModifiers: credential,
				Validators:          []validator.String{stringvalidator.LengthBetween(1, 1024)},
				MarkdownDescription: "Password of the database user (sensitive). One of `password` and `password_wo` is required."},
			"password_wo": schema.StringAttribute{Optional: true, Sensitive: true, WriteOnly: true,
				Validators:          []validator.String{stringvalidator.LengthBetween(1, 1024)},
				MarkdownDescription: "`password` that Terraform never stores (write-only, Terraform 1.11 and later); change `credentials_wo_version` to use a new one."},
			"credentials_wo_version": schema.StringAttribute{Optional: true, PlanModifiers: credential,
				MarkdownDescription: "Any string; a change replaces the connection with the current `link_token_wo` / `password_wo`."},
			"resource_name": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Name of its Skupper Link and Listener in the region (`<name>-<suffix>`)."},
			"host": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Host name the functions connect to (the Listener's Service)."},
			"secret_name": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "The credentials Secret in the region."},
			"env_names": schema.ListAttribute{Computed: true, ElementType: types.StringType,
				PlanModifiers:       []planmodifier.List{listplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "The variables the workload gets."},
			"link_kind": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "`access_token` (redeemed into a Link by Skupper) or `link`."},
			"status": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "What the region reports: `ready`, `pending` or `error` (read with every refresh)."},
		},
	}
}

func (r *privateConnectionResource) ConfigValidators(context.Context) []resource.ConfigValidator {
	return []resource.ConfigValidator{
		resourcevalidator.ExactlyOneOf(path.MatchRoot("link_token"), path.MatchRoot("link_token_wo")),
		resourcevalidator.ExactlyOneOf(path.MatchRoot("password"), path.MatchRoot("password_wo")),
	}
}

func (r *privateConnectionResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// configured is the value of the plan, or the write-only one of the configuration.
func configured(plan, cfgWO types.String) string {
	if known(plan) {
		return plan.ValueString()
	}
	return cfgWO.ValueString()
}

func (r *privateConnectionResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg privateConnectionModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := plan.ProjectID.ValueString()
	c, _, err := r.client.API.CreatePrivateConnection(ctx, pid, runr.PrivateConnectionInput{
		Environment: environmentRef(plan.Environment), Name: plan.Name.ValueString(), EnvPrefix: plan.EnvPrefix.ValueString(),
		RoutingKey: plan.RoutingKey.ValueString(), Port: int(plan.Port.ValueInt64()), SSLMode: plan.SSLMode.ValueString(),
		LinkToken: configured(plan.LinkToken, cfg.LinkTokenWO), Database: plan.Database.ValueString(),
		Username: plan.Username.ValueString(), Password: configured(plan.Password, cfg.PasswordWO),
	})
	if err != nil {
		environmentError(&resp.Diagnostics, "Create private connection failed", err)
		return
	}
	plan.fromAPI(ctx, c, &resp.Diagnostics)
	plan.Status = types.StringValue(r.status(ctx, pid, c.ID, &resp.Diagnostics))
	plan.LinkTokenWO, plan.PasswordWO = types.StringNull(), types.StringNull()
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// status is what the region reports about the connection ("unknown" with a warning when it does
// not answer).
func (r *privateConnectionResource) status(ctx context.Context, projectID, id string, diags *diag.Diagnostics) string {
	_, st, err := r.client.API.GetPrivateConnectionStatus(ctx, projectID, id)
	if err != nil || st == nil || st.State == "" {
		if err != nil {
			diags.AddWarning("Private connection status unknown", "The region did not report the connection's status: "+err.Error())
		}
		return "unknown"
	}
	return st.State
}

func (r *privateConnectionResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state privateConnectionModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := state.ProjectID.ValueString()
	list, err := r.client.API.ListPrivateConnections(ctx, pid, environmentRef(state.Environment))
	if err != nil && environmentGone(ctx, r.client, pid, err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		environmentError(&resp.Diagnostics, "Read private connections failed", err)
		return
	}
	for i := range list {
		if list[i].ID == state.ID.ValueString() {
			state.fromAPI(ctx, &list[i], &resp.Diagnostics)
			state.Status = types.StringValue(r.status(ctx, pid, list[i].ID, &resp.Diagnostics))
			resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
			return
		}
	}
	// Removed outside Terraform (or with its environment).
	resp.State.RemoveResource(ctx)
}

// Update changes nothing on the server (every argument replaces the connection); the write-only
// values stay out of the state.
func (r *privateConnectionResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan privateConnectionModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.LinkTokenWO, plan.PasswordWO = types.StringNull(), types.StringNull()
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *privateConnectionResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state privateConnectionModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.DeletePrivateConnection(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Remove private connection failed", err)
	}
}

// ImportState accepts "<project id or name>/<connection name or id>" (the project's own workload;
// an id finds a connection of any environment) or "<project id or name>/<environment>/<connection
// name or id>".
func (r *privateConnectionResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	parts := strings.Split(req.ID, "/")
	if (len(parts) != 2 && len(parts) != 3) || parts[0] == "" || parts[len(parts)-1] == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<connection> or <project id or name>/<environment>/<connection>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, parts[0])
	if err != nil {
		apiError(&resp.Diagnostics, "Import private connection failed", err)
		return
	}
	env, ref := "", parts[len(parts)-1]
	if len(parts) == 3 {
		env = parts[1]
	}
	c, err := r.client.API.FindPrivateConnection(ctx, p.ID, environmentRef(types.StringValue(env)), ref)
	if err != nil && env == "" {
		// an id of a connection of one of the environments
		if found, _, serr := r.client.API.GetPrivateConnectionStatus(ctx, p.ID, ref); serr == nil {
			c, err = found, nil
		}
	}
	if err != nil {
		environmentError(&resp.Diagnostics, "Import private connection failed", err)
		return
	}
	resp.Diagnostics.AddWarning("Credentials not imported",
		"RunR never returns a private connection's link token, database, user and password: the state holds none. Keep the "+
			"ones the connection was made with in the configuration: the next apply takes them into the state without replacing "+
			"the connection; a later change replaces it.")
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), c.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	switch {
	case env != "":
		resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("environment"), env)...)
	case c.EnvironmentID != "":
		resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("environment"), c.EnvironmentName())...)
	}
}

func (m *privateConnectionModel) fromAPI(ctx context.Context, c *runr.PrivateConnection, diags *diag.Diagnostics) {
	m.ID = types.StringValue(c.ID)
	if c.ProjectID != "" {
		m.ProjectID = types.StringValue(c.ProjectID)
	}
	m.Environment = environmentState(m.Environment, c.EnvironmentID, c.Environment)
	m.Name = types.StringValue(c.Name)
	m.EnvPrefix = types.StringValue(c.EnvPrefix)
	m.RoutingKey = types.StringValue(c.RoutingKey)
	m.Port = types.Int64Value(int64(c.Port))
	m.SSLMode = types.StringValue(c.SSLMode)
	m.ResourceName = types.StringValue(c.ResourceName)
	m.Host = types.StringValue(c.Host)
	m.SecretName = types.StringValue(c.SecretName)
	m.LinkKind = types.StringValue(c.LinkKind)
	names, d := types.ListValueFrom(ctx, types.StringType, append([]string{}, c.EnvNames...))
	diags.Append(d...)
	m.EnvNames = names
}

// credentialRequiresReplace replaces the connection when a credential it was created with
// changes; an imported connection (no credential in its state) takes the configured ones into
// the state without a replacement.
func credentialRequiresReplace() planmodifier.String {
	const why = "A new credential replaces the connection (RunR cannot change one); an imported connection keeps its own."
	return stringplanmodifier.RequiresReplaceIf(func(_ context.Context, req planmodifier.StringRequest, resp *stringplanmodifier.RequiresReplaceIfFuncResponse) {
		resp.RequiresReplace = !req.StateValue.IsNull()
	}, why, why)
}

// environmentRequiresReplace replaces a resource that moves to another workload of the project;
// null and "production" both name the project's own.
func environmentRequiresReplace() planmodifier.String {
	const why = "Moving it to another environment of the project replaces it."
	return stringplanmodifier.RequiresReplaceIf(func(_ context.Context, req planmodifier.StringRequest, resp *stringplanmodifier.RequiresReplaceIfFuncResponse) {
		resp.RequiresReplace = req.PlanValue.IsUnknown() || environmentRef(req.StateValue) != environmentRef(req.PlanValue)
	}, why, why)
}

// environmentRef is the environment a request names: "" for the project's own workload (null or
// "production").
func environmentRef(v types.String) string {
	if !known(v) || v.ValueString() == runr.PrimaryEnvironment {
		return ""
	}
	return v.ValueString()
}

// environmentState is an environment attribute read back from the API (id and name of the
// environment, "" for the project's own workload): the prior value while it names the same
// environment — a name or an id, null or "production" for the project's own workload — so that
// reading back never turns one form into the other.
func environmentState(prior types.String, id, name string) types.String {
	if id == "" {
		if known(prior) && prior.ValueString() == runr.PrimaryEnvironment {
			return prior
		}
		return types.StringNull()
	}
	if known(prior) && (prior.ValueString() == id || prior.ValueString() == name) {
		return prior
	}
	if name != "" {
		return types.StringValue(name)
	}
	return types.StringValue(id)
}
