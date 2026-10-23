package provider

import (
	"context"
	"errors"
	"time"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &deploymentResource{}
	_ resource.ResourceWithConfigure      = &deploymentResource{}
	_ resource.ResourceWithImportState    = &deploymentResource{}
	_ resource.ResourceWithValidateConfig = &deploymentResource{}
	_ resource.ResourceWithModifyPlan     = &deploymentResource{}
)

// NewDeploymentResource returns the runr_deployment resource.
func NewDeploymentResource() resource.Resource { return &deploymentResource{} }

type deploymentResource struct{ client *Client }

type deploymentModel struct {
	ID           types.String `tfsdk:"id"`
	ProjectID    types.String `tfsdk:"project_id"`
	Environment  types.String `tfsdk:"environment"`
	VersionID    types.String `tfsdk:"version_id"`
	Image        types.String `tfsdk:"image"`
	Triggers     types.Map    `tfsdk:"triggers"`
	Wait         types.Bool   `tfsdk:"wait"`
	Timeout      types.String `tfsdk:"timeout"`
	Status       types.String `tfsdk:"status"`
	VersionLabel types.String `tfsdk:"version_label"`
	URL          types.String `tfsdk:"url"`
	ReadyAt      types.String `tfsdk:"ready_at"`
}

func (r *deploymentResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_deployment"
}

func (r *deploymentResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		MarkdownDescription: "The running deployment of a project. Creating it deploys a version (default: the latest) or, " +
			"for `caas` projects, an image; changing `version_id`, `image` or `triggers` deploys again in place " +
			"(the previous deployment is shut down by RunR once the new one is ready). Destroying it tears the deployment down. " +
			"With `environment` it is the running deployment of one of the project's environments.\n\n" +
			"Code of `faas`/`xaas` projects is uploaded as versions with `runr deploy`; put a value that changes with the code " +
			"or config (a commit SHA, `runr_env_var` values) into `triggers` to redeploy.\n\n" +
			"A deploy of a project with a blue-green or canary deploy strategy (`runr_project.deploy_strategy`) opens a rollout while another " +
			"deployment serves: the new deployment is `running` next to it until the rollout promotes it (`active`). Promoting, aborting and " +
			"rolling back stay with `runr rollout`, the console or the API; a deployment whose rollout was aborted or rolled back " +
			"drops out of the state, and the next apply deploys again.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Id of the current deployment (changes on every redeploy)."},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"environment": schema.StringAttribute{Optional: true, PlanModifiers: []planmodifier.String{environmentRequiresReplace()},
				MarkdownDescription: "Environment of the project to deploy into, by name or id (`runr_environment.<x>.name`); left out or " +
					"`production`: the project's own workload. It deploys with the environment's configuration; moving it replaces " +
					"the deployment. Promoting a version from one environment to the next stays an action (`runr promote`)."},
			"version_id": schema.StringAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Version to deploy (default: the latest version when the deployment is created).",
				PlanModifiers:       []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"image": schema.StringAttribute{Optional: true,
				MarkdownDescription: "`caas`: image to deploy; it is written to the project config (a new version) first."},
			"triggers": schema.MapAttribute{Optional: true, ElementType: types.StringType,
				MarkdownDescription: "Arbitrary values; any change deploys again."},
			"wait": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Wait until the deployment serves (default `true`); with a deploy strategy, until the new revision is " +
					"ready and its rollout under way."},
			"timeout": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("10m"),
				MarkdownDescription: "How long to wait for the build and the deployment (Go duration, default `10m`)."},
			"status": schema.StringAttribute{Computed: true,
				MarkdownDescription: "Deployment status (`active`, `pending`, …; `running` while a rollout of the deploy strategy is under way)."},
			"version_label": schema.StringAttribute{Computed: true, MarkdownDescription: "Deployed version label (`v7`)."},
			"url":           schema.StringAttribute{Computed: true, MarkdownDescription: "Project URL (HTTP trigger)."},
			"ready_at":      schema.StringAttribute{Computed: true, MarkdownDescription: "When the deployment became ready (RFC 3339)."},
		},
	}
}

func (r *deploymentResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *deploymentResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m deploymentModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if known(m.VersionID) && known(m.Image) {
		resp.Diagnostics.AddAttributeError(path.Root("image"), "version_id and image are exclusive",
			"Deploy either a saved version or an image.")
	}
	if known(m.Timeout) {
		if _, err := time.ParseDuration(m.Timeout.ValueString()); err != nil {
			resp.Diagnostics.AddAttributeError(path.Root("timeout"), "Invalid duration", err.Error())
		}
	}
}

// ModifyPlan makes version_id unknown when a redeploy without a configured version is
// planned (triggers or image changed): it then deploys the latest version.
func (r *deploymentResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.State.Raw.IsNull() || req.Plan.Raw.IsNull() {
		return
	}
	var config, plan, state deploymentModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &config)...)
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if config.VersionID.IsNull() && (!plan.Triggers.Equal(state.Triggers) || !plan.Image.Equal(state.Image)) {
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("version_id"), types.StringUnknown())...)
	}
}

// deploy runs one deployment for the plan and writes the result into it.
func (r *deploymentResource) deploy(ctx context.Context, plan *deploymentModel) error {
	api := r.client.API
	pid := plan.ProjectID.ValueString()
	timeout, _ := time.ParseDuration(plan.Timeout.ValueString())

	var version *runr.Version
	var err error
	switch {
	case known(plan.Image):
		p, err := api.GetProject(ctx, pid)
		if err != nil {
			return err
		}
		if p.ServiceType != runr.TypeCaaS {
			return errors.New("image applies to caas projects; " + p.Name + " is " + p.ServiceType)
		}
		if img := plan.Image.ValueString(); img != p.Config.Image {
			unlock := r.client.lock(pid)
			_, err = api.UpdateProject(ctx, pid, runr.UpdateProjectInput{
				Config: &runr.ConfigInput{Image: &img}, ConfigMessage: "terraform: image " + img,
			})
			unlock()
			if err != nil {
				return err
			}
		}
		version, err = api.FindVersion(ctx, pid, "latest")
	case known(plan.VersionID):
		version, err = api.GetVersion(ctx, pid, plan.VersionID.ValueString())
	default:
		version, err = api.FindVersion(ctx, pid, "latest")
	}
	if err != nil {
		var nf *runr.NotFoundError
		if errors.As(err, &nf) && nf.Ref == "latest" {
			return errors.New("the project has no version yet: upload code with `runr deploy` or set image (caas)")
		}
		return err
	}
	if version.BuildStatus != runr.BuildReady && version.BuildStatus != "" {
		if version, err = api.WaitVersion(ctx, pid, version, r.client.wait(timeout)); err != nil {
			return err
		}
	}
	env := environmentRef(plan.Environment)
	var d *runr.Deployment
	if env == "" {
		if d, err = api.Deploy(ctx, pid, version.ID, nil); err != nil {
			return err
		}
	} else {
		// An API before environments ignores the field: the environment is looked up first
		if _, err := api.GetEnvironment(ctx, pid, env); err != nil {
			return err
		}
		start, err := api.StartDeployWith(ctx, pid, version.ID, runr.DeployOptions{Environment: env})
		if err != nil {
			return err
		}
		d = start.Deployment
		if start.Queued != nil {
			q, err := api.WaitQueuedDeploy(ctx, pid, start.Queued, r.client.wait(timeout))
			if err != nil {
				return err
			}
			if d, err = api.GetDeployment(ctx, pid, q.DeploymentID); err != nil {
				return err
			}
		}
	}
	plan.ID = types.StringValue(d.ID)
	plan.VersionID = types.StringValue(version.ID)
	plan.VersionLabel = types.StringValue(version.Label)
	plan.Status = types.StringValue(d.Status)
	plan.ReadyAt = types.StringNull()
	if plan.Wait.ValueBool() {
		d, err = api.WaitDeployment(ctx, pid, d.ID, r.client.wait(timeout))
		if d != nil {
			plan.Status = types.StringValue(d.Status)
		}
		if err != nil {
			return err
		}
		if d.ReadyAt != nil {
			plan.ReadyAt = types.StringValue(d.ReadyAt.UTC().Format(time.RFC3339))
		}
	}
	plan.URL = types.StringNull()
	if env != "" {
		if e, err := api.GetEnvironment(ctx, pid, env); err == nil {
			plan.URL = strOrNull(e.URL)
		}
	} else if p, err := api.GetProject(ctx, pid); err == nil {
		plan.URL = strOrNull(p.EndpointURL)
	}
	return nil
}

func (r *deploymentResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan deploymentModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.deploy(ctx, &plan); err != nil {
		if known(plan.ID) {
			// The deployment exists but did not become ready: keep it in the state.
			resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
		}
		apiError(&resp.Diagnostics, "Deployment failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *deploymentResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state deploymentModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	d, err := r.client.API.GetDeployment(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read deployment failed", err)
		return
	}
	if !d.Live() {
		// Superseded or failed: plan a new deployment.
		resp.State.RemoveResource(ctx)
		return
	}
	state.Status = types.StringValue(d.Status)
	state.Environment = environmentState(state.Environment, d.EnvironmentID, d.Environment)
	// an imported deployment learns its URL here
	if state.URL.IsNull() {
		if env := environmentRef(state.Environment); env != "" {
			if e, err := r.client.API.GetEnvironment(ctx, state.ProjectID.ValueString(), env); err == nil {
				state.URL = strOrNull(e.URL)
			}
		} else if p, err := r.client.API.GetProject(ctx, state.ProjectID.ValueString()); err == nil {
			state.URL = strOrNull(p.EndpointURL)
		}
	}
	if d.Version != nil {
		state.VersionID = types.StringValue(d.Version.ID)
		state.VersionLabel = types.StringValue(d.VersionLabel)
	}
	if d.ReadyAt != nil {
		state.ReadyAt = types.StringValue(d.ReadyAt.UTC().Format(time.RFC3339))
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *deploymentResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state deploymentModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	redeploy := !plan.VersionID.Equal(state.VersionID) || !plan.Image.Equal(state.Image) || !plan.Triggers.Equal(state.Triggers)
	if !redeploy {
		// Only wait / timeout changed.
		plan.ID, plan.Status, plan.VersionLabel, plan.URL, plan.ReadyAt = state.ID, state.Status, state.VersionLabel, state.URL, state.ReadyAt
		resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
		return
	}
	if err := r.deploy(ctx, &plan); err != nil {
		if known(plan.ID) {
			resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
		}
		apiError(&resp.Diagnostics, "Deployment failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *deploymentResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state deploymentModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.DeleteDeployment(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete deployment failed", err)
	}
}

// ImportState accepts "<project id or name>" (the live deployment) or "<project>/<deployment id>".
func (r *deploymentResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	proj, depID, _ := cutLast(req.ID, "/")
	p, err := r.client.API.FindProject(ctx, proj)
	if err != nil {
		apiError(&resp.Diagnostics, "Import deployment failed", err)
		return
	}
	if depID == "" {
		d, err := r.client.API.CurrentDeployment(ctx, p.ID)
		if err != nil {
			apiError(&resp.Diagnostics, "Import deployment failed", err)
			return
		}
		if d == nil {
			resp.Diagnostics.AddError("Import deployment failed", "project "+p.Name+" has no deployment")
			return
		}
		depID = d.ID
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), depID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("wait"), true)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("timeout"), "10m")...)
}

func cutLast(s, sep string) (before, after string, found bool) {
	for i := len(s) - len(sep); i >= 0; i-- {
		if s[i:i+len(sep)] == sep {
			return s[:i], s[i+len(sep):], true
		}
	}
	return s, "", false
}
