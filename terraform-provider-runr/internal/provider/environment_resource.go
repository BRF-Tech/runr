package provider

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/mapvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/setvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/listplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/objectplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewEnvironmentResource) }

var (
	_ resource.Resource                   = &environmentResource{}
	_ resource.ResourceWithConfigure      = &environmentResource{}
	_ resource.ResourceWithImportState    = &environmentResource{}
	_ resource.ResourceWithValidateConfig = &environmentResource{}
	_ resource.ResourceWithModifyPlan     = &environmentResource{}
)

// NewEnvironmentResource returns the runr_environment resource.
func NewEnvironmentResource() resource.Resource { return &environmentResource{} }

// An environment of a project (docs/environments.md): its settings, its overrides of the
// project's configuration, its own timed warmer and deploy strategy. The primary environment
// "production" (the project's own workload) exists with every project: the resource adopts it and
// manages its approval and position only. Promotion, approvals and deploys are actions, not
// managed here.
type environmentResource struct{ client *Client }

type environmentModel struct {
	ID                      types.String `tfsdk:"id"`
	ProjectID               types.String `tfsdk:"project_id"`
	Name                    types.String `tfsdk:"name"`
	RegionID                types.String `tfsdk:"region_id"`
	GitBranch               types.String `tfsdk:"git_branch"`
	AutoDeploy              types.Bool   `tfsdk:"auto_deploy"`
	RequiresApproval        types.Bool   `tfsdk:"requires_approval"`
	InheritSecrets          types.Bool   `tfsdk:"inherit_secrets"`
	Position                types.Int64  `tfsdk:"position"`
	Overrides               types.Object `tfsdk:"overrides"`
	ScaleSchedule           types.List   `tfsdk:"scale_schedule"`
	ScaleScheduleInherited  types.Bool   `tfsdk:"scale_schedule_inherited"`
	DeployStrategy          types.Object `tfsdk:"deploy_strategy"`
	DeployStrategyInherited types.Bool   `tfsdk:"deploy_strategy_inherited"`
	WorkloadName            types.String `tfsdk:"workload_name"`
	URL                     types.String `tfsdk:"url"`
	DefaultURL              types.String `tfsdk:"default_url"`
	OwnRegion               types.Bool   `tfsdk:"own_region"`
}

type environmentOverridesModel struct {
	EnvVars        types.Map    `tfsdk:"env_vars"`
	RemoveEnvVars  types.Set    `tfsdk:"remove_env_vars"`
	CPU            types.String `tfsdk:"cpu"`
	MemoryMB       types.Int64  `tfsdk:"memory_mb"`
	TimeoutSeconds types.Int64  `tfsdk:"timeout_seconds"`
	MinScale       types.Int64  `tfsdk:"min_scale"`
	MaxScale       types.Int64  `tfsdk:"max_scale"`
	ScaleToZero    types.Bool   `tfsdk:"scale_to_zero"`
	Schedule       types.String `tfsdk:"schedule"`
	QueueStream    types.String `tfsdk:"queue_stream"`
}

var environmentOverridesAttrTypes = map[string]attr.Type{
	"env_vars": types.MapType{ElemType: types.StringType}, "remove_env_vars": types.SetType{ElemType: types.StringType},
	"cpu": types.StringType, "memory_mb": types.Int64Type, "timeout_seconds": types.Int64Type, "min_scale": types.Int64Type,
	"max_scale": types.Int64Type, "scale_to_zero": types.BoolType, "schedule": types.StringType, "queue_stream": types.StringType,
}

var (
	// environmentBranchPattern: the API's branch rule; "" clears the branch.
	environmentBranchPattern = regexp.MustCompile(`^([A-Za-z0-9._/-]{1,255})?$`)
	// kubernetesCPUPattern: a CPU quantity as the API takes it (250m, 0.5, 2).
	kubernetesCPUPattern = regexp.MustCompile(`^(\d+m|\d+(\.\d{1,3})?)$`)
	queueStreamPattern   = regexp.MustCompile(`^[a-zA-Z0-9._-]+$`)
	// cronSchedulePattern: five cron fields or one of the API's macros (CronSchedule).
	cronSchedulePattern = regexp.MustCompile(`^(@(hourly|daily|weekly|monthly|yearly)|\S+(\s+\S+){4})$`)
)

func (r *environmentResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_environment"
}

func (r *environmentResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	keepBool := []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()}
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "An environment of a project (`dev`, `staging`, …): a workload of its own, `<slug>-<name>`, that runs " +
			"the project's versions with the project's configuration, its `overrides` on top, its own secret variables " +
			"(`runr_environment_secret`), bindings (`runr_binding_attachment.environment`), custom domains " +
			"(`runr_domain.environment`), private database connections (`runr_private_connection.environment`) and, " +
			"optionally, another region ([environments](https://runr.sh/docs/environments/)). Nothing runs in it until a " +
			"version is deployed or promoted to it: promotions, approvals and deploys are actions, not managed here.\n\n" +
			"The primary environment `production` is the project's own workload and exists with every project: " +
			"`name = \"production\"` adopts it to manage its `requires_approval` and `position` only (its configuration is " +
			"the project's); destroying the resource sets them back to production's defaults (approval required, last in the " +
			"pipeline). Destroying another environment deletes it with its workload, deployments and secrets; its bindings are " +
			"detached, its custom domains, gateway route and private connections removed.\n\n" +
			"Needs `environments.manage` on the project (overrides with `env_vars` also `env.edit`, `deploy_strategy` " +
			"`projects.update`). Import with `<project name or id>/<environment name>`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Environment id."},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Project id. Changing it creates a new environment."},
			"name": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Lowercase DNS label of at most 20 characters (`staging`, `qa-eu`), unique in the project; " +
					"its workload is `<slug>-<name>`. Not a name the project's resources use (`trigger`, `qc`, `bindings`, " +
					"`pr-<n>`, `v<n>`, …). `production` adopts the project's own workload. Changing it creates a new environment.",
				Validators: []validator.String{environmentNameValidator{}}},
			"region_id": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Region it runs in (`data.runr_region`); default the project's, which it then follows. " +
					"The organization's data residency must allow the region. RunR changes it only while nothing runs in the " +
					"environment and it has no gateway route or private connection. Left out, the region stays."},
			"git_branch": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Branch whose pushes deploy the environment (`develop`); one environment per branch, never the " +
					"project's deploy branch. `\"\"` maps no branch (the default). Left out, the branch stays.",
				Validators: []validator.String{stringvalidator.RegexMatches(environmentBranchPattern, "must be a branch name (letters, digits, . _ / -)"),
					branchNameValidator{}}},
			"auto_deploy": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Whether a push to `git_branch` deploys (default `true`). Left out, the setting stays."},
			"requires_approval": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Whether promotions into the environment wait for an approver with `environments.approve` " +
					"(default `false`; `true` for production). Left out, the setting stays."},
			"inherit_secrets": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Whether the project's secret variables reach the environment (default `true`); off, it has only " +
					"its own (`runr_environment_secret`). Left out, the setting stays."},
			"position": schema.Int64Attribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()},
				Validators:    []validator.Int64{int64validator.Between(0, 2000)},
				MarkdownDescription: "Place in the pipeline (0-2000): environments run by position, production's is 1000. Default: after " +
					"the environments added before it, before production. Left out, the position stays."},
			"overrides": schema.SingleNestedAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "Configuration over the project's. RunR saves the overrides as a whole: a configured block is " +
					"the complete set (a field left out of it follows the project's value; `{}` overrides nothing). Left out, the " +
					"overrides stay as they are. A change reaches the workload with the environment's next deploy.",
				Attributes: map[string]schema.Attribute{
					"env_vars": schema.MapAttribute{ElementType: types.StringType, Optional: true, Sensitive: true,
						Validators: []validator.Map{mapvalidator.KeysAre(envNameValidator{})},
						MarkdownDescription: "Plain variables merged over the project's by name. A token without `env.view` cannot read " +
							"them back: the configured values are kept (with a warning)."},
					"remove_env_vars": schema.SetAttribute{ElementType: types.StringType, Optional: true,
						Validators: []validator.Set{setvalidator.ValueStringsAre(envNameValidator{})},
						MarkdownDescription: "Names of the project's plain variables the environment does not get (RunR's `null` override). " +
							"A name is in `env_vars` or here, not both."},
					"cpu": schema.StringAttribute{Optional: true,
						Validators:          []validator.String{cpuQuantityValidator{}},
						MarkdownDescription: "Kubernetes CPU quantity of the container (`250m`, `0.5`); also what the environment reserves."},
					"memory_mb": schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.AtLeast(64)},
						MarkdownDescription: "Memory in MB (at least 64); also what the environment reserves."},
					"timeout_seconds": schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.AtLeast(1)},
						MarkdownDescription: "Request timeout in seconds (within the region's limit)."},
					"min_scale": schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.Between(0, 10)},
						MarkdownDescription: "Minimum instances (0-10)."},
					"max_scale": schema.Int64Attribute{Optional: true, Validators: []validator.Int64{int64validator.Between(1, 1000)},
						MarkdownDescription: "Maximum instances (1-1000)."},
					"scale_to_zero": schema.BoolAttribute{Optional: true,
						MarkdownDescription: "Scale to zero instances when idle."},
					"schedule": schema.StringAttribute{Optional: true,
						Validators: []validator.String{stringvalidator.RegexMatches(cronSchedulePattern,
							"must be a five-field cron expression or @hourly, @daily, @weekly, @monthly or @yearly")},
						MarkdownDescription: "Cron schedule of a `schedule` trigger."},
					"queue_stream": schema.StringAttribute{Optional: true,
						Validators: []validator.String{stringvalidator.LengthAtMost(255),
							stringvalidator.RegexMatches(queueStreamPattern, "must be letters, digits, . _ or -")},
						MarkdownDescription: "Stream a `queue` trigger reads. Without one the environment reads `<stream>-<name>`, never " +
							"production's stream."},
				},
			},
			"scale_schedule": schema.ListNestedAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.List{listplanmodifier.UseStateForUnknown()},
				Validators:    []validator.List{listvalidator.SizeAtMost(runr.MaxScaleScheduleRows)},
				MarkdownDescription: "The environment's own timed warmer: each row keeps at least `min_scale` containers warm from the " +
					"moment its five-field `cron` expression fires until the next row fires, in the organization's time zone. The rows " +
					"replace the project's schedule in the environment (at most 10, each at most its `max_scale`, else the project's); " +
					"`[]` keeps no containers warm there. A change reaches the running workload within a minute, without a deploy. " +
					"Reads back the schedule the environment runs, the project's while `scale_schedule_inherited`. Left out, the " +
					"environment keeps what it has.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"cron": schema.StringAttribute{Required: true,
						MarkdownDescription: "Five-field cron expression (minute hour day-of-month month day-of-week), fields separated by one space, e.g. `0 9 * * 1-5`.",
						Validators:          []validator.String{stringvalidator.RegexMatches(cronPattern, "must be five cron fields separated by single spaces, e.g. \"0 9 * * 1-5\"")}},
					"min_scale": schema.Int64Attribute{Required: true,
						MarkdownDescription: "Containers kept warm from this moment on (0-10).",
						Validators:          []validator.Int64{int64validator.Between(0, runr.MaxWarmScale)}},
				}},
			},
			"scale_schedule_inherited": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Whether the environment runs the project's timed warmer (it has no schedule of its own). Set it " +
					"to `true` to drop the environment's own schedule and follow the project's again; `false` only together with " +
					"`scale_schedule`."},
			"deploy_strategy": deployStrategySchema("How the environment's deploys and the promotions into it roll out, over the project's " +
				"[deploy strategy](https://runr.sh/docs/deploy-strategies/#environments). The configured values become the " +
				"environment's own and stay when the project's settings change; the others follow the project's. Reads back the " +
				"settings the environment deploys with. Left out, the environment keeps its settings."),
			"deploy_strategy_inherited": schema.BoolAttribute{Optional: true, Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Whether the environment sets nothing of its own deploy strategy and follows the project's. Set it " +
					"to `true` to drop what it sets and follow the project's again; `false` only together with `deploy_strategy`."},
			"workload_name": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Name of its workload (Knative Service) in the region: `<slug>-<name>`; the slug for production."},
			"url": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "URL of the environment: its gateway URL while it has a gateway route, else `default_url`."},
			"default_url": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Its own URL on the function domain of its region, `https://<workload>.<domain>`."},
			"own_region": schema.BoolAttribute{Computed: true, PlanModifiers: keepBool,
				MarkdownDescription: "Whether the environment has a region of its own (`false`: it follows the project's)."},
		},
	}
}

func (r *environmentResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// environmentNameValidator is the API's name rule; production (the project's own workload) is
// adopted, the other reserved names are refused.
type environmentNameValidator struct{}

func (environmentNameValidator) Description(context.Context) string {
	return "a lowercase DNS label of at most 20 characters that the project's resources do not use"
}
func (v environmentNameValidator) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}
func (environmentNameValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	name := req.ConfigValue.ValueString()
	switch {
	case name == runr.PrimaryEnvironment:
	case !runr.EnvironmentNamePattern.MatchString(name):
		resp.Diagnostics.AddAttributeError(req.Path, "Invalid environment name",
			"An environment name is a lowercase DNS label: letters, digits and hyphens, starting with a letter and not ending "+
				"with a hyphen, at most 20 characters.")
	case runr.ReservedEnvironmentName(name):
		resp.Diagnostics.AddAttributeError(req.Path, "Reserved environment name",
			fmt.Sprintf("%q is reserved: the project's own workload, its previews and its resources use it.", name))
	}
}

// branchNameValidator refuses what the API would store differently (refs/heads/…) or refuse (..).
type branchNameValidator struct{}

func (branchNameValidator) Description(context.Context) string {
	return "a branch name without refs/heads/ and without .."
}
func (v branchNameValidator) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}
func (branchNameValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	b := req.ConfigValue.ValueString()
	if strings.HasPrefix(b, "refs/heads/") {
		resp.Diagnostics.AddAttributeError(req.Path, "Branch given as a ref",
			"Give the branch name without refs/heads/ (RunR stores "+strings.TrimPrefix(b, "refs/heads/")+").")
	}
	if strings.Contains(b, "..") {
		resp.Diagnostics.AddAttributeError(req.Path, "Invalid branch name", "A branch name cannot contain \"..\".")
	}
}

// cpuQuantityValidator is the API's KubernetesCpu rule: millicores or cores, greater than zero.
type cpuQuantityValidator struct{}

func (cpuQuantityValidator) Description(context.Context) string {
	return "a Kubernetes CPU quantity greater than zero (250m, 0.5)"
}
func (v cpuQuantityValidator) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}
func (cpuQuantityValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	s := req.ConfigValue.ValueString()
	if !kubernetesCPUPattern.MatchString(s) || strings.Trim(s, "0.m") == "" {
		resp.Diagnostics.AddAttributeError(req.Path, "Invalid CPU quantity",
			`CPU must be a Kubernetes quantity greater than zero, e.g. "250m" or "0.5".`)
	}
}

func (r *environmentResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m environmentModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	// production: only its approval and position (its configuration is the project's)
	if known(m.Name) && m.Name.ValueString() == runr.PrimaryEnvironment {
		for name, v := range map[string]attr.Value{
			"region_id": m.RegionID, "git_branch": m.GitBranch, "auto_deploy": m.AutoDeploy, "inherit_secrets": m.InheritSecrets,
			"overrides": m.Overrides, "scale_schedule": m.ScaleSchedule, "scale_schedule_inherited": m.ScaleScheduleInherited,
			"deploy_strategy": m.DeployStrategy, "deploy_strategy_inherited": m.DeployStrategyInherited,
		} {
			if !v.IsNull() {
				resp.Diagnostics.AddAttributeError(path.Root(name), name+" does not apply to production",
					"production is the project's own workload: its region, configuration, timed warmer and deploy strategy are "+
						"the project's (runr_project), its secrets runr_env_var with secret = true. A production environment "+
						"manages requires_approval and position only.")
			}
		}
	}
	// a variable is set or removed, not both
	if known(m.Overrides) {
		var o environmentOverridesModel
		resp.Diagnostics.Append(m.Overrides.As(ctx, &o, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		if known(o.EnvVars) && known(o.RemoveEnvVars) {
			set := o.EnvVars.Elements()
			for _, e := range o.RemoveEnvVars.Elements() {
				if s, ok := e.(types.String); ok && known(s) {
					if _, both := set[s.ValueString()]; both {
						resp.Diagnostics.AddAttributeError(path.Root("overrides").AtName("remove_env_vars"), "Variable set and removed",
							s.ValueString()+" is in env_vars and in remove_env_vars: keep it in one of them.")
					}
				}
			}
		}
		// a warm row may not keep more containers than the environment's max_scale
		if known(o.MaxScale) && known(m.ScaleSchedule) {
			for i, row := range scheduleRows(ctx, m.ScaleSchedule, &resp.Diagnostics) {
				if int64(row.MinScale) > o.MaxScale.ValueInt64() {
					resp.Diagnostics.AddAttributeError(path.Root("scale_schedule").AtListIndex(i).AtName("min_scale"), "above overrides.max_scale",
						fmt.Sprintf("Row %d keeps %d containers warm; the environment's max_scale is %d.", i+1, row.MinScale, o.MaxScale.ValueInt64()))
				}
			}
		}
	}
	inheritedRule(m.ScaleScheduleInherited, m.ScaleSchedule, "scale_schedule", &resp.Diagnostics)
	inheritedRule(m.DeployStrategyInherited, m.DeployStrategy, "deploy_strategy", &resp.Diagnostics)
}

// inheritedRule: <attr>_inherited = true drops the environment's own settings, so it cannot come
// with them; false is what setting them gives.
func inheritedRule(inherited types.Bool, own attr.Value, name string, diags *diag.Diagnostics) {
	if !known(inherited) {
		return
	}
	if inherited.ValueBool() && !own.IsNull() {
		diags.AddAttributeError(path.Root(name+"_inherited"), name+"_inherited conflicts with "+name,
			name+"_inherited = true follows the project's settings; remove "+name+" or the flag.")
	}
	if !inherited.ValueBool() && own.IsNull() {
		diags.AddAttributeError(path.Root(name+"_inherited"), name+"_inherited = false needs "+name,
			"The environment has settings of its own when "+name+" sets them; set "+name+" or leave the flag out.")
	}
}

// strategyPinned reports whether a configured deploy strategy sets something of the
// environment's own (the strategy, or a blue_green / canary block, whose nullable options it
// sets too).
func strategyPinned(ctx context.Context, cfg types.Object, diags *diag.Diagnostics) bool {
	if cfg.IsNull() {
		return false
	}
	if cfg.IsUnknown() {
		return true
	}
	var m deployStrategyModel
	diags.Append(cfg.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true, UnhandledNullAsEmpty: true})...)
	return !m.Strategy.IsNull() || !m.BlueGreen.IsNull() || !m.Canary.IsNull()
}

// ModifyPlan plans what the API computes: setting a schedule or a strategy of the environment's
// own makes it not inherited any more, dropping them (… _inherited = true) gives the project's,
// another region another URL. A strategy other than direct is checked against the project's
// trigger.
func (r *environmentResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.Plan.Raw.IsNull() {
		return
	}
	var cfg, plan environmentModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var state *environmentModel
	if !req.State.Raw.IsNull() {
		state = &environmentModel{}
		resp.Diagnostics.Append(req.State.Get(ctx, state)...)
		if resp.Diagnostics.HasError() {
			return
		}
	}
	// The timed warmer: rows of its own, or the project's again
	switch {
	case cfg.ScaleScheduleInherited.IsNull() && !cfg.ScaleSchedule.IsNull():
		plan.ScaleScheduleInherited = types.BoolValue(false)
	case known(cfg.ScaleScheduleInherited) && cfg.ScaleScheduleInherited.ValueBool() && (state == nil || !state.ScaleScheduleInherited.ValueBool()):
		plan.ScaleSchedule = types.ListUnknown(scheduleRowType)
	}
	// The deploy strategy: values of its own, or the project's again
	switch {
	case cfg.DeployStrategyInherited.IsNull() && strategyPinned(ctx, cfg.DeployStrategy, &resp.Diagnostics):
		plan.DeployStrategyInherited = types.BoolValue(false)
	case known(cfg.DeployStrategyInherited) && cfg.DeployStrategyInherited.ValueBool() && (state == nil || !state.DeployStrategyInherited.ValueBool()):
		plan.DeployStrategy = types.ObjectUnknown(deployStrategyAttrTypes)
	}
	// Another region: other URLs
	if state != nil && known(cfg.RegionID) && cfg.RegionID.ValueString() != state.RegionID.ValueString() {
		plan.URL, plan.DefaultURL, plan.OwnRegion = types.StringUnknown(), types.StringUnknown(), types.BoolUnknown()
	}
	resp.Diagnostics.Append(resp.Plan.Set(ctx, &plan)...)

	// A blue-green or canary strategy needs an http or workflow trigger of the project
	if r.client != nil && r.client.API != nil && known(cfg.ProjectID) && known(cfg.DeployStrategy) {
		var m deployStrategyModel
		resp.Diagnostics.Append(cfg.DeployStrategy.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true, UnhandledNullAsEmpty: true})...)
		if known(m.Strategy) && m.Strategy.ValueString() != runr.StrategyDirect {
			if p, err := r.client.API.GetProject(ctx, cfg.ProjectID.ValueString()); err == nil {
				validateDeployStrategy(ctx, cfg.DeployStrategy, p.Config.Trigger(), path.Root("deploy_strategy"), &resp.Diagnostics)
			}
		}
	}
}

// environmentGone reports whether err means the environment, or its project, no longer exists:
// an unknown environment, or — the client reads a 404 of an environment request as an API
// without environments — a project that is gone.
func environmentGone(ctx context.Context, c *Client, projectID string, err error) bool {
	var nf *runr.NotFoundError
	if errors.As(err, &nf) || runr.IsNotFound(err) {
		return true
	}
	if errors.Is(err, runr.ErrUnsupported) {
		_, perr := c.API.GetProject(ctx, projectID)
		return runr.IsNotFound(perr)
	}
	return false
}

// environmentError explains an API without environments.
func environmentError(diags *diag.Diagnostics, summary string, err error) {
	if errors.Is(err, runr.ErrUnsupported) {
		diags.AddError(summary, "This RunR API has no project environments (RunR 0.6.0 or later).")
		return
	}
	apiError(diags, summary, err)
}

// read returns the environment with its deploy strategy (nil for production, or on APIs without
// deploy strategies).
func (r *environmentResource) read(ctx context.Context, projectID, ref string) (*runr.Environment, *runr.DeployStrategy, error) {
	e, err := r.client.API.GetEnvironment(ctx, projectID, ref)
	if err != nil {
		return nil, nil, err
	}
	if e.IsPrimary {
		return e, nil, nil
	}
	ds, err := r.client.API.GetDeployStrategyIn(ctx, projectID, e.ID)
	if errors.Is(err, runr.ErrUnsupported) {
		return e, nil, nil
	}
	return e, ds, err
}

func (r *environmentResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg environmentModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := plan.ProjectID.ValueString()

	var e *runr.Environment
	var err error
	if plan.Name.ValueString() == runr.PrimaryEnvironment {
		// production exists with the project: adopt it
		if e, err = r.client.API.GetEnvironment(ctx, pid, runr.PrimaryEnvironment); err != nil {
			environmentError(&resp.Diagnostics, "Read production environment failed", err)
			return
		}
		if in := primaryInput(cfg, e); in != nil {
			if e, err = r.client.API.UpdateEnvironment(ctx, pid, e.ID, *in); err != nil {
				environmentError(&resp.Diagnostics, "Change production environment failed", err)
				return
			}
		}
	} else {
		in := runr.EnvironmentInput{Name: plan.Name.ValueString(), RegionID: strPtr(cfg.RegionID), GitBranch: strPtr(cfg.GitBranch),
			AutoDeploy: boolPtr(cfg.AutoDeploy), RequiresApproval: boolPtr(cfg.RequiresApproval),
			InheritSecrets: boolPtr(cfg.InheritSecrets), Position: intPtr(cfg.Position)}
		o, send := plan.overridesInput(ctx, nil, &resp.Diagnostics)
		if resp.Diagnostics.HasError() {
			return
		}
		if send {
			in.Overrides = &o
		}
		if e, err = r.client.API.CreateEnvironment(ctx, pid, in); err != nil {
			environmentError(&resp.Diagnostics, "Create environment failed", err)
			return
		}
		// Keep the environment in the state even if a later step fails, so it is not orphaned.
		created := plan
		created.fromAPI(ctx, e, nil, &resp.Diagnostics)
		resp.Diagnostics.Append(resp.State.Set(ctx, &created)...)

		if r.applyStrategy(ctx, pid, e.ID, cfg, &resp.Diagnostics); resp.Diagnostics.HasError() {
			return
		}
	}
	r.finish(ctx, pid, e.ID, cfg, plan, &resp.State, &resp.Diagnostics)
}

// primaryInput is what changes of production: its approval and position, when configured and
// different (nil: nothing).
func primaryInput(cfg environmentModel, e *runr.Environment) *runr.EnvironmentInput {
	var in runr.EnvironmentInput
	if v := boolPtr(cfg.RequiresApproval); v != nil && *v != e.RequiresApproval {
		in.RequiresApproval = v
	}
	if v := intPtr(cfg.Position); v != nil && *v != e.Position {
		in.Position = v
	}
	if in.RequiresApproval == nil && in.Position == nil {
		return nil
	}
	return &in
}

// applyStrategy sets the environment's own deploy strategy from the configuration: the
// configured values become its own; deploy_strategy_inherited = true drops them.
func (r *environmentResource) applyStrategy(ctx context.Context, projectID, envID string, cfg environmentModel, diags *diag.Diagnostics) {
	reset := known(cfg.DeployStrategyInherited) && cfg.DeployStrategyInherited.ValueBool()
	if !known(cfg.DeployStrategy) && !reset {
		return
	}
	cur, err := r.client.API.GetDeployStrategyIn(ctx, projectID, envID)
	if err != nil {
		if errors.Is(err, runr.ErrUnsupported) {
			diags.AddAttributeError(path.Root("deploy_strategy"), "Deploy strategies not supported",
				"This RunR API has no deploy strategies; remove deploy_strategy.")
			return
		}
		apiError(diags, "Read deploy strategy failed", err)
		return
	}
	if reset {
		if !cur.Inherited {
			if _, err := r.client.API.ResetDeployStrategy(ctx, projectID, envID); err != nil {
				apiError(diags, "Reset deploy strategy failed", err)
			}
		}
		return
	}
	in := environmentStrategyInput(ctx, cfg.DeployStrategy, cur, diags)
	if diags.HasError() || in.Empty() {
		return
	}
	if _, err := r.client.API.UpdateDeployStrategyIn(ctx, projectID, envID, in); err != nil {
		apiError(diags, "Set deploy strategy failed", err)
	}
}

// environmentStrategyInput is what a configured deploy strategy sets of the environment's own:
// what differs from the settings it deploys with, and every configured value it does not set of
// its own yet, so that the value stays when the project's settings change.
func environmentStrategyInput(ctx context.Context, cfg types.Object, cur *runr.DeployStrategy, diags *diag.Diagnostics) runr.DeployStrategyInput {
	in := deployStrategyInput(ctx, cfg, cur, diags)
	if !known(cfg) || cur == nil {
		return in
	}
	own := cur.Own
	var m deployStrategyModel
	diags.Append(cfg.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	if known(m.Strategy) && in.Strategy == "" && !hasKey(own, "strategy") {
		in.Strategy = m.Strategy.ValueString()
	}
	if known(m.BlueGreen) {
		var bg blueGreenModel
		diags.Append(m.BlueGreen.As(ctx, &bg, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		ownBG, _ := own["blue_green"].(map[string]any)
		var b runr.BlueGreenInput
		if in.BlueGreen != nil {
			b = *in.BlueGreen
		}
		if v := boolPtr(bg.AutoPromote); v != nil && b.AutoPromote == nil && !hasKey(ownBG, "auto_promote") {
			b.AutoPromote = v
		}
		if !bg.HealthPath.IsUnknown() && b.HealthPath == nil && !hasKey(ownBG, "health_path") {
			b.HealthPath = nullableOf(strPtr(bg.HealthPath))
		}
		if v := intPtr(bg.HealthChecks); v != nil && b.HealthChecks == nil && !hasKey(ownBG, "health_checks") {
			b.HealthChecks = v
		}
		if v := intPtr(bg.KeepPreviousMinutes); v != nil && b.KeepPreviousMinutes == nil && !hasKey(ownBG, "keep_previous_minutes") {
			b.KeepPreviousMinutes = v
		}
		if b != (runr.BlueGreenInput{}) {
			in.BlueGreen = &b
		}
	}
	if known(m.Canary) {
		var cm canaryModel
		diags.Append(m.Canary.As(ctx, &cm, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		ownCanary, _ := own["canary"].(map[string]any)
		var c runr.CanaryInput
		if in.Canary != nil {
			c = *in.Canary
		}
		if l := intList(ctx, cm.Steps, diags); l != nil && c.Steps == nil && !hasKey(ownCanary, "steps") {
			c.Steps = *l
		}
		if v := intPtr(cm.IntervalMinutes); v != nil && c.IntervalMinutes == nil && !hasKey(ownCanary, "interval_minutes") {
			c.IntervalMinutes = v
		}
		if !cm.ErrorRateThreshold.IsUnknown() && c.ErrorRateThreshold == nil && !hasKey(ownCanary, "error_rate_threshold") {
			if cm.ErrorRateThreshold.IsNull() {
				c.ErrorRateThreshold = runr.NullOf[float64]()
			} else {
				c.ErrorRateThreshold = runr.NullableOf(cm.ErrorRateThreshold.ValueFloat64())
			}
		}
		if !cm.P95ThresholdMs.IsUnknown() && c.P95ThresholdMs == nil && !hasKey(ownCanary, "p95_threshold_ms") {
			c.P95ThresholdMs = nullableOf(intPtr(cm.P95ThresholdMs))
		}
		if c.Steps != nil || c.IntervalMinutes != nil || c.ErrorRateThreshold != nil || c.P95ThresholdMs != nil {
			in.Canary = &c
		}
	}
	return in
}

func hasKey(m map[string]any, key string) bool {
	_, ok := m[key]
	return ok
}

// nullableOf sends the value, or null for nil.
func nullableOf[T any](v *T) *runr.Nullable[T] {
	if v == nil {
		return runr.NullOf[T]()
	}
	return runr.NullableOf(*v)
}

// finish reads the environment back into the state; values the configuration leaves out keep
// what the plan promised (see keepPlanned).
func (r *environmentResource) finish(ctx context.Context, projectID, envID string, cfg, plan environmentModel, state interface {
	Set(context.Context, any) diag.Diagnostics
}, diags *diag.Diagnostics) {
	e, ds, err := r.read(ctx, projectID, envID)
	if err != nil {
		environmentError(diags, "Read environment failed", err)
		return
	}
	got := plan
	if got.fromAPI(ctx, e, ds, diags) {
		diags.AddWarning("Environment variables hidden", "The token cannot read the variables of environment "+e.Name+
			" (permission env.view); overrides.env_vars and overrides.remove_env_vars are kept as configured.")
	}
	got.keepPlanned(ctx, cfg, plan, diags)
	diags.Append(state.Set(ctx, &got)...)
}

func (r *environmentResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state environmentModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := state.ProjectID.ValueString()
	e, ds, err := r.read(ctx, pid, state.ID.ValueString())
	if err != nil && environmentGone(ctx, r.client, pid, err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		environmentError(&resp.Diagnostics, "Read environment failed", err)
		return
	}
	if state.fromAPI(ctx, e, ds, &resp.Diagnostics) {
		resp.Diagnostics.AddWarning("Environment variables hidden", "The token cannot read the variables of environment "+e.Name+
			" (permission env.view); overrides.env_vars and overrides.remove_env_vars are kept as configured.")
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *environmentResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, cfg, state environmentModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.Config.Get(ctx, &cfg)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid, id := state.ProjectID.ValueString(), state.ID.ValueString()
	cur, err := r.client.API.GetEnvironment(ctx, pid, id)
	if err != nil {
		environmentError(&resp.Diagnostics, "Read environment failed", err)
		return
	}
	if cur.IsPrimary {
		if in := primaryInput(cfg, cur); in != nil {
			if _, err := r.client.API.UpdateEnvironment(ctx, pid, id, *in); err != nil {
				environmentError(&resp.Diagnostics, "Change production environment failed", err)
				return
			}
		}
		r.finish(ctx, pid, id, cfg, plan, &resp.State, &resp.Diagnostics)
		return
	}

	var in runr.EnvironmentInput
	changed := false
	setStr := func(c, s types.String, dst **string) {
		if known(c) && c.ValueString() != s.ValueString() {
			*dst, changed = strPtr(c), true
		}
	}
	setBool := func(c, s types.Bool, dst **bool) {
		if known(c) && !c.Equal(s) {
			*dst, changed = boolPtr(c), true
		}
	}
	setStr(cfg.RegionID, types.StringValue(cur.RegionID), &in.RegionID)
	setStr(cfg.GitBranch, types.StringValue(cur.GitBranch), &in.GitBranch)
	setBool(cfg.AutoDeploy, types.BoolValue(cur.AutoDeploy), &in.AutoDeploy)
	setBool(cfg.RequiresApproval, types.BoolValue(cur.RequiresApproval), &in.RequiresApproval)
	setBool(cfg.InheritSecrets, types.BoolValue(cur.InheritSecrets), &in.InheritSecrets)
	if v := intPtr(cfg.Position); v != nil && *v != cur.Position {
		in.Position, changed = v, true
	}
	// The overrides are saved as a whole, the environment's own timed warmer with them
	if !plan.Overrides.Equal(state.Overrides) || !plan.ScaleSchedule.Equal(state.ScaleSchedule) ||
		!plan.ScaleScheduleInherited.Equal(state.ScaleScheduleInherited) {
		o, _ := plan.overridesInput(ctx, &state, &resp.Diagnostics)
		if cur.Overrides.EnvVarsHidden && plan.envVarsUnchanged(ctx, state, &resp.Diagnostics) {
			o.EnvVars = nil // a token without env.view that sends none keeps them
		}
		in.Overrides, changed = &o, true
	}
	if resp.Diagnostics.HasError() {
		return
	}
	if changed {
		if _, err := r.client.API.UpdateEnvironment(ctx, pid, id, in); err != nil {
			environmentError(&resp.Diagnostics, "Change environment failed", err)
			return
		}
	}
	if r.applyStrategy(ctx, pid, id, cfg, &resp.Diagnostics); resp.Diagnostics.HasError() {
		return
	}
	r.finish(ctx, pid, id, cfg, plan, &resp.State, &resp.Diagnostics)
}

func (r *environmentResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state environmentModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid, id := state.ProjectID.ValueString(), state.ID.ValueString()
	if state.Name.ValueString() == runr.PrimaryEnvironment {
		// production stays with the project: back to its defaults
		cur, err := r.client.API.GetEnvironment(ctx, pid, id)
		if err != nil {
			if !environmentGone(ctx, r.client, pid, err) {
				environmentError(&resp.Diagnostics, "Read production environment failed", err)
			}
			return
		}
		defaults := environmentModel{RequiresApproval: types.BoolValue(true), Position: types.Int64Value(1000)}
		if in := primaryInput(defaults, cur); in != nil {
			if _, err := r.client.API.UpdateEnvironment(ctx, pid, id, *in); err != nil && !environmentGone(ctx, r.client, pid, err) {
				environmentError(&resp.Diagnostics, "Reset production environment failed", err)
			}
		}
		return
	}
	incomplete, err := r.client.API.DeleteEnvironment(ctx, pid, id)
	if err != nil {
		if !environmentGone(ctx, r.client, pid, err) {
			environmentError(&resp.Diagnostics, "Delete environment failed", err)
		}
		return
	}
	if incomplete {
		resp.Diagnostics.AddWarning("Environment deleted, its region not fully cleaned up",
			"RunR deleted environment "+state.Name.ValueString()+" but could not remove everything it ran from its region "+
				"(the API logged what is left).")
	}
}

// ImportState accepts "<project id or name>/<environment name>".
func (r *environmentResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	proj, name, ok := strings.Cut(req.ID, "/")
	if !ok || proj == "" || name == "" || strings.Contains(name, "/") {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<environment name>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, proj)
	if err != nil {
		apiError(&resp.Diagnostics, "Import environment failed", err)
		return
	}
	e, err := r.client.API.GetEnvironment(ctx, p.ID, name)
	if err != nil {
		environmentError(&resp.Diagnostics, "Import environment failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), e.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("name"), e.Name)...)
}

// overridesInput is the complete overrides object of the plan with the environment's own timed
// warmer (send: there is something to send on a create; an update always sends it, prior set).
func (m *environmentModel) overridesInput(ctx context.Context, prior *environmentModel, diags *diag.Diagnostics) (runr.EnvironmentOverrides, bool) {
	var o runr.EnvironmentOverrides
	send := false
	if known(m.Overrides) {
		var om environmentOverridesModel
		diags.Append(m.Overrides.As(ctx, &om, basetypes.ObjectAsOptions{})...)
		if known(om.EnvVars) || known(om.RemoveEnvVars) {
			o.EnvVars = map[string]*string{}
			for k, v := range om.EnvVars.Elements() {
				if s, ok := v.(types.String); ok && known(s) {
					val := s.ValueString()
					o.EnvVars[k] = &val
				}
			}
			for _, v := range om.RemoveEnvVars.Elements() {
				if s, ok := v.(types.String); ok && known(s) {
					o.EnvVars[s.ValueString()] = nil
				}
			}
		}
		o.CPU, o.MemoryMB, o.TimeoutSeconds = strPtr(om.CPU), intPtr(om.MemoryMB), intPtr(om.TimeoutSeconds)
		o.MinScale, o.MaxScale, o.ScaleToZero = intPtr(om.MinScale), intPtr(om.MaxScale), boolPtr(om.ScaleToZero)
		o.Schedule, o.QueueStream = strPtr(om.Schedule), strPtr(om.QueueStream)
		send = !o.Empty()
	}
	// its own schedule: rows, or [] for none; inherited (or to follow the project's again): none
	own := known(m.ScaleSchedule) && known(m.ScaleScheduleInherited) && !m.ScaleScheduleInherited.ValueBool()
	if prior == nil {
		own = known(m.ScaleSchedule) && !m.ScaleScheduleInherited.IsUnknown() && !m.ScaleScheduleInherited.ValueBool()
	}
	if own {
		rows := scheduleRows(ctx, m.ScaleSchedule, diags)
		o.ScaleSchedule = &rows
		send = true
	}
	return o, send || prior != nil
}

// envVarsUnchanged reports whether the plan keeps the variables of the state.
func (m *environmentModel) envVarsUnchanged(ctx context.Context, state environmentModel, diags *diag.Diagnostics) bool {
	if !known(m.Overrides) || !known(state.Overrides) {
		return true
	}
	var a, b environmentOverridesModel
	diags.Append(m.Overrides.As(ctx, &a, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	diags.Append(state.Overrides.As(ctx, &b, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	return a.EnvVars.Equal(b.EnvVars) && a.RemoveEnvVars.Equal(b.RemoveEnvVars)
}

// fromAPI writes the environment and its deploy strategy into the model; the model's overrides
// are the prior value (configured empty maps stay empty; hidden variables stay as they are).
// hidden reports variables the token may not read.
func (m *environmentModel) fromAPI(ctx context.Context, e *runr.Environment, ds *runr.DeployStrategy, diags *diag.Diagnostics) (hidden bool) {
	m.ID = types.StringValue(e.ID)
	m.Name = types.StringValue(e.Name)
	m.RegionID = strOrNull(e.RegionID)
	m.AutoDeploy = types.BoolValue(e.AutoDeploy)
	m.RequiresApproval = types.BoolValue(e.RequiresApproval)
	m.InheritSecrets = types.BoolValue(e.InheritSecrets)
	m.Position = types.Int64Value(int64(e.Position))
	m.WorkloadName = strOrNull(e.WorkloadName)
	m.URL = strOrNull(e.URL)
	m.DefaultURL = strOrNull(e.DefaultURL)
	if e.DefaultURL == "" {
		m.DefaultURL = m.URL
	}
	m.OwnRegion = types.BoolValue(e.OwnRegion)
	if e.IsPrimary {
		// production's configuration, timed warmer and strategy are the project's
		m.GitBranch = types.StringNull()
		m.Overrides = environmentOverridesValue(ctx, runr.EnvironmentOverrides{}, types.ObjectNull(environmentOverridesAttrTypes), diags)
		m.ScaleSchedule = types.ListNull(scheduleRowType)
		m.ScaleScheduleInherited = types.BoolValue(true)
		m.DeployStrategy = types.ObjectNull(deployStrategyAttrTypes)
		m.DeployStrategyInherited = types.BoolValue(true)
		return false
	}
	m.GitBranch = types.StringValue(e.GitBranch)
	m.Overrides = environmentOverridesValue(ctx, e.Overrides, m.Overrides, diags)
	m.ScaleSchedule = scheduleList(ctx, e.ScaleSchedule, diags)
	m.ScaleScheduleInherited = types.BoolValue(e.ScaleScheduleInherited)
	m.DeployStrategy = deployStrategyValue(ctx, ds, diags)
	m.DeployStrategyInherited = types.BoolNull()
	if ds != nil {
		m.DeployStrategyInherited = types.BoolValue(ds.Inherited || len(ds.Own) == 0)
	}
	return e.Overrides.EnvVarsHidden
}

// environmentOverridesValue is the overrides object of the API's answer; prior is the value it
// replaces (its variables are kept while the token may not read them; an empty map stays one).
func environmentOverridesValue(ctx context.Context, o runr.EnvironmentOverrides, prior types.Object, diags *diag.Diagnostics) types.Object {
	var p environmentOverridesModel
	if known(prior) {
		diags.Append(prior.As(ctx, &p, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true, UnhandledNullAsEmpty: true})...)
	}
	vars, removed := p.EnvVars, p.RemoveEnvVars
	if vars.IsUnknown() || vars.ElementType(ctx) == nil {
		vars = types.MapNull(types.StringType)
	}
	if removed.IsUnknown() || removed.ElementType(ctx) == nil {
		removed = types.SetNull(types.StringType)
	}
	if !o.EnvVarsHidden {
		set := map[string]string{}
		var gone []string
		for k, v := range o.EnvVars {
			if v == nil {
				gone = append(gone, k)
			} else {
				set[k] = *v
			}
		}
		switch {
		case len(set) > 0:
			mv, d := types.MapValueFrom(ctx, types.StringType, set)
			diags.Append(d...)
			vars = mv
		case !(known(vars) && len(vars.Elements()) == 0):
			vars = types.MapNull(types.StringType)
		}
		switch {
		case len(gone) > 0:
			sv, d := types.SetValueFrom(ctx, types.StringType, gone)
			diags.Append(d...)
			removed = sv
		case !(known(removed) && len(removed.Elements()) == 0):
			removed = types.SetNull(types.StringType)
		}
	}
	out, d := types.ObjectValueFrom(ctx, environmentOverridesAttrTypes, environmentOverridesModel{
		EnvVars: vars, RemoveEnvVars: removed, CPU: optStr(o.CPU), MemoryMB: intOrNull(o.MemoryMB),
		TimeoutSeconds: intOrNull(o.TimeoutSeconds), MinScale: intOrNull(o.MinScale), MaxScale: intOrNull(o.MaxScale),
		ScaleToZero: boolOrNull(o.ScaleToZero), Schedule: optStr(o.Schedule), QueueStream: optStr(o.QueueStream),
	})
	diags.Append(d...)
	return out
}

// keepPlanned keeps, after a create or update, the planned value of everything the
// configuration leaves out: Terraform requires a known planned value to come back unchanged,
// and what an environment follows from its project (region, timed warmer, deploy strategy
// options) may move when the project changes in the same run. The next refresh reads the
// server's values again.
func (m *environmentModel) keepPlanned(ctx context.Context, cfg, plan environmentModel, diags *diag.Diagnostics) {
	m.ID = keepPlannedAs(ctx, cfg.ID, plan.ID, m.ID, diags)
	m.RegionID = keepPlannedAs(ctx, cfg.RegionID, plan.RegionID, m.RegionID, diags)
	m.GitBranch = keepPlannedAs(ctx, cfg.GitBranch, plan.GitBranch, m.GitBranch, diags)
	m.AutoDeploy = keepPlannedAs(ctx, cfg.AutoDeploy, plan.AutoDeploy, m.AutoDeploy, diags)
	m.RequiresApproval = keepPlannedAs(ctx, cfg.RequiresApproval, plan.RequiresApproval, m.RequiresApproval, diags)
	m.InheritSecrets = keepPlannedAs(ctx, cfg.InheritSecrets, plan.InheritSecrets, m.InheritSecrets, diags)
	m.Position = keepPlannedAs(ctx, cfg.Position, plan.Position, m.Position, diags)
	m.Overrides = keepPlannedAs(ctx, cfg.Overrides, plan.Overrides, m.Overrides, diags)
	m.ScaleSchedule = keepPlannedAs(ctx, cfg.ScaleSchedule, plan.ScaleSchedule, m.ScaleSchedule, diags)
	m.ScaleScheduleInherited = keepPlannedAs(ctx, cfg.ScaleScheduleInherited, plan.ScaleScheduleInherited, m.ScaleScheduleInherited, diags)
	m.DeployStrategy = keepPlannedAs(ctx, cfg.DeployStrategy, plan.DeployStrategy, m.DeployStrategy, diags)
	m.DeployStrategyInherited = keepPlannedAs(ctx, cfg.DeployStrategyInherited, plan.DeployStrategyInherited, m.DeployStrategyInherited, diags)
	m.WorkloadName = keepPlannedAs(ctx, cfg.WorkloadName, plan.WorkloadName, m.WorkloadName, diags)
	m.URL = keepPlannedAs(ctx, cfg.URL, plan.URL, m.URL, diags)
	m.DefaultURL = keepPlannedAs(ctx, cfg.DefaultURL, plan.DefaultURL, m.DefaultURL, diags)
	m.OwnRegion = keepPlannedAs(ctx, cfg.OwnRegion, plan.OwnRegion, m.OwnRegion, diags)
}

// keepPlannedAs is keepPlannedValue for one attribute of type T.
func keepPlannedAs[T attr.Value](ctx context.Context, cfg, plan attr.Value, got T, diags *diag.Diagnostics) T {
	if v, ok := keepPlannedValue(ctx, cfg, plan, got, diags).(T); ok {
		return v
	}
	return got
}

// keepPlannedValue is got, except where the configuration leaves the value out and the plan has a
// known one; a configured object is compared attribute by attribute.
func keepPlannedValue(ctx context.Context, cfg, plan, got attr.Value, diags *diag.Diagnostics) attr.Value {
	if plan == nil || plan.IsUnknown() {
		return got
	}
	if cfg == nil || cfg.IsNull() {
		return plan
	}
	co, cok := cfg.(basetypes.ObjectValue)
	po, pok := plan.(basetypes.ObjectValue)
	g, gok := got.(basetypes.ObjectValue)
	if !cok || !pok || !gok || co.IsUnknown() || po.IsNull() || g.IsNull() || g.IsUnknown() {
		return got
	}
	attrs := map[string]attr.Value{}
	for k, v := range g.Attributes() {
		attrs[k] = keepPlannedValue(ctx, co.Attributes()[k], po.Attributes()[k], v, diags)
	}
	out, d := types.ObjectValue(g.AttributeTypes(ctx), attrs)
	diags.Append(d...)
	return out
}
