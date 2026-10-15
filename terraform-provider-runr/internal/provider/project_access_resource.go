package provider

import (
	"context"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/runrsh/core/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &projectAccessResource{}
	_ resource.ResourceWithConfigure      = &projectAccessResource{}
	_ resource.ResourceWithImportState    = &projectAccessResource{}
	_ resource.ResourceWithValidateConfig = &projectAccessResource{}
)

func init() { registerResource(NewProjectAccessResource) }

// NewProjectAccessResource returns the runr_project_access resource.
func NewProjectAccessResource() resource.Resource { return &projectAccessResource{} }

type projectAccessResource struct{ client *Client }

type projectAccessModel struct {
	ID          types.String `tfsdk:"id"`
	ProjectID   types.String `tfsdk:"project_id"`
	User        types.String `tfsdk:"user"`
	GroupID     types.String `tfsdk:"group_id"`
	Role        types.String `tfsdk:"role"`
	SubjectType types.String `tfsdk:"subject_type"`
	SubjectID   types.String `tfsdk:"subject_id"`
}

func (r *projectAccessResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_project_access"
}

func (r *projectAccessResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A role for a user or a group on one project only (project access). The role's " +
			"project-scoped permissions apply to that project; organization-wide roles cover every project already. " +
			"Needs `projects.manage_access` on the project. A user or group holds one role per project: a second " +
			"resource for the same subject changes the role of the same grant.",
		Attributes: map[string]schema.Attribute{
			"id":         schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Grant id."},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace, MarkdownDescription: "Project id."},
			"user": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "The user, by id, e-mail address or username. Exactly one of `user` and `group_id`."},
			"group_id": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "The group (`runr_group.<name>.id`)."},
			"role": schema.StringAttribute{Required: true,
				MarkdownDescription: "Role key on the project (built-in such as `developer` or `viewer`, or `runr_role.<name>.key`). " +
					"The Owner role is given to members directly only.",
				Validators: []validator.String{stringvalidator.NoneOf(runr.RoleOwner), stringvalidator.LengthBetween(1, 64)}},
			"subject_type": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "`user` or `group`."},
			"subject_id":   schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "User or group id."},
		},
	}
}

func (r *projectAccessResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m projectAccessModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() || m.User.IsUnknown() || m.GroupID.IsUnknown() {
		return
	}
	if m.User.IsNull() == m.GroupID.IsNull() {
		resp.Diagnostics.AddAttributeError(path.Root("user"), "Invalid project access",
			"Set exactly one of user and group_id.")
	}
}

func (r *projectAccessResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *projectAccessResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan projectAccessModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	subjectType, subjectID := runr.SubjectGroup, plan.GroupID.ValueString()
	if known(plan.User) {
		m, err := r.client.API.FindMember(ctx, plan.User.ValueString())
		if err != nil {
			resp.Diagnostics.AddAttributeError(path.Root("user"), "Unknown user", err.Error())
			return
		}
		subjectType, subjectID = runr.SubjectUser, m.ID.String()
	}
	g, err := r.client.API.GrantAccess(ctx, plan.ProjectID.ValueString(), subjectType, subjectID, plan.Role.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Grant project access failed", err)
		return
	}
	plan.ID = types.StringValue(g.ID)
	plan.SubjectType = types.StringValue(g.SubjectType)
	plan.SubjectID = types.StringValue(g.SubjectID.String())
	plan.Role = types.StringValue(g.Role)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

// find returns the grant of the model's id, nil when it (or the project) is gone.
func (r *projectAccessResource) find(ctx context.Context, projectID, id string) (*runr.AccessGrant, error) {
	list, _, err := r.client.API.ListAccess(ctx, projectID)
	if runr.IsNotFound(err) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ID == id {
			return &list[i], nil
		}
	}
	return nil, nil
}

func (r *projectAccessResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state projectAccessModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	g, err := r.find(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Read project access failed", err)
		return
	}
	if g == nil {
		resp.State.RemoveResource(ctx)
		return
	}
	state.Role = types.StringValue(g.Role)
	state.SubjectType = types.StringValue(g.SubjectType)
	state.SubjectID = types.StringValue(g.SubjectID.String())
	switch {
	case g.SubjectType == runr.SubjectGroup:
		state.GroupID = types.StringValue(g.SubjectID.String())
		state.User = types.StringNull()
	case state.User.IsNull():
		// Imported: name the user by e-mail when the API knows it.
		ref := g.SubjectEmail
		if ref == "" {
			ref = g.SubjectID.String()
		}
		state.User = types.StringValue(ref)
		state.GroupID = types.StringNull()
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *projectAccessResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state projectAccessModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	// Only the role changes in place; granting again to the same subject updates the grant.
	g, err := r.client.API.GrantAccess(ctx, state.ProjectID.ValueString(), state.SubjectType.ValueString(),
		state.SubjectID.ValueString(), plan.Role.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Change project access failed", err)
		return
	}
	plan.ID = types.StringValue(g.ID)
	plan.SubjectType = state.SubjectType
	plan.SubjectID = state.SubjectID
	plan.Role = types.StringValue(g.Role)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *projectAccessResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state projectAccessModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.RevokeAccess(ctx, state.ProjectID.ValueString(), state.ID.ValueString())
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Revoke project access failed", err)
	}
}

// ImportState accepts "<project id or name>/<grant id>".
func (r *projectAccessResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	i := strings.LastIndex(req.ID, "/")
	if i <= 0 || i == len(req.ID)-1 {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<grant id> (runr access ls -p <project> lists the grant ids).")
		return
	}
	p, err := r.client.API.FindProject(ctx, req.ID[:i])
	if err != nil {
		apiError(&resp.Diagnostics, "Import project access failed", err)
		return
	}
	g, err := r.find(ctx, p.ID, req.ID[i+1:])
	if err != nil {
		apiError(&resp.Diagnostics, "Import project access failed", err)
		return
	}
	if g == nil {
		resp.Diagnostics.AddError("Import project access failed", "Project "+p.Name+" has no access grant "+req.ID[i+1:]+".")
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), g.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
}
