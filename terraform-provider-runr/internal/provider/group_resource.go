package provider

import (
	"context"
	"sort"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/runrsh/core/cli/pkg/runr"
)

var (
	_ resource.Resource                = &groupResource{}
	_ resource.ResourceWithConfigure   = &groupResource{}
	_ resource.ResourceWithImportState = &groupResource{}
)

func init() { registerResource(NewGroupResource) }

// NewGroupResource returns the runr_group resource.
func NewGroupResource() resource.Resource { return &groupResource{} }

type groupResource struct{ client *Client }

type groupModel struct {
	ID          types.String `tfsdk:"id"`
	Name        types.String `tfsdk:"name"`
	Description types.String `tfsdk:"description"`
	Role        types.String `tfsdk:"role"`
	Members     types.Set    `tfsdk:"members"`
}

func (r *groupResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_group"
}

func (r *groupResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		MarkdownDescription: "A group (team) of members. Members get the group's organization-wide `role` and the " +
			"project access granted to the group (`runr_project_access` with `group_id`). Needs `groups.manage` " +
			"(and `roles.manage` to set the role). Members synced from single sign-on (source `sso`) are kept by the " +
			"SSO group mapping and are not part of `members`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Group id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"name": schema.StringAttribute{Required: true, MarkdownDescription: "Group name (unique).",
				Validators: []validator.String{stringvalidator.LengthBetween(1, 100)}},
			"description": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(""),
				MarkdownDescription: "Description.", Validators: []validator.String{stringvalidator.LengthAtMost(500)}},
			"role": schema.StringAttribute{Optional: true,
				MarkdownDescription: "Organization-wide role of the members (a built-in role key or `runr_role.<name>.key`). " +
					"Unset: the group grants project access only.",
				Validators: []validator.String{stringvalidator.NoneOf(runr.RoleOwner)}},
			"members": schema.SetAttribute{Optional: true, ElementType: types.StringType,
				MarkdownDescription: "Members, each given by user id, e-mail address or username. Unset: Terraform leaves " +
					"the membership alone; an empty set removes every manually added member."},
		},
	}
}

func (r *groupResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

// memberIDs resolves member references (id, e-mail, username) to user ids.
func (r *groupResource) memberIDs(ctx context.Context, refs []string, diags *diag.Diagnostics) []string {
	if len(refs) == 0 {
		return []string{}
	}
	list, err := r.client.API.ListMembers(ctx)
	if err != nil {
		apiError(diags, "List members failed", err)
		return nil
	}
	ids := make([]string, 0, len(refs))
	for _, ref := range refs {
		m, err := runr.PickMember(list, ref)
		if err != nil {
			diags.AddAttributeError(path.Root("members"), "Unknown member", err.Error())
			continue
		}
		ids = append(ids, m.ID.String())
	}
	return ids
}

// syncMembers makes the group's manually added members match want (user ids).
func (r *groupResource) syncMembers(ctx context.Context, g *runr.Group, want []string, diags *diag.Diagnostics) {
	have := map[string]bool{}
	for _, m := range g.Members {
		if m.Source != "sso" {
			have[m.ID.String()] = true
		}
	}
	wanted := map[string]bool{}
	var add []string
	for _, id := range want {
		wanted[id] = true
		if !have[id] {
			add = append(add, id)
		}
	}
	if len(add) > 0 {
		if _, err := r.client.API.AddGroupMembers(ctx, g.ID, add); err != nil {
			apiError(diags, "Add group members failed", err)
			return
		}
	}
	var remove []string
	for id := range have {
		if !wanted[id] {
			remove = append(remove, id)
		}
	}
	sort.Strings(remove)
	for _, id := range remove {
		if _, err := r.client.API.RemoveGroupMember(ctx, g.ID, id); err != nil && !runr.IsNotFound(err) {
			apiError(diags, "Remove group member failed", err)
			return
		}
	}
}

// read fills the model from the API. Members keep the form they were configured in (id,
// e-mail or username); members added outside Terraform appear by e-mail.
func (r *groupResource) read(ctx context.Context, m *groupModel, diags *diag.Diagnostics) (found bool) {
	g, err := r.client.API.GetGroup(ctx, m.ID.ValueString())
	if runr.IsNotFound(err) {
		return false
	}
	if err != nil {
		apiError(diags, "Read group failed", err)
		return true
	}
	m.ID = types.StringValue(g.ID)
	m.Name = types.StringValue(g.Name)
	m.Description = types.StringValue(g.Description)
	m.Role = strOrNull(g.Role)
	if m.Members.IsNull() || m.Members.IsUnknown() {
		if m.Members.IsUnknown() {
			m.Members = types.SetNull(types.StringType)
		}
		return true
	}
	configured := stringsOfSet(ctx, m.Members, diags)
	members, err := r.client.API.ListMembers(ctx)
	if err != nil {
		apiError(diags, "List members failed", err)
		return true
	}
	byID := map[string]*runr.Member{}
	for i := range members {
		byID[members[i].ID.String()] = &members[i]
	}
	out := []string{}
	for _, gm := range g.Members {
		if gm.Source == "sso" {
			continue
		}
		ref := gm.Email
		if full := byID[gm.ID.String()]; full != nil {
			for _, c := range configured {
				if runr.MatchMember(full, c) {
					ref = c
					break
				}
			}
		}
		out = append(out, ref)
	}
	m.Members = setOfStrings(ctx, out, diags)
	return true
}

func (r *groupResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan groupModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var ids []string
	if known(plan.Members) {
		ids = r.memberIDs(ctx, stringsOfSet(ctx, plan.Members, &resp.Diagnostics), &resp.Diagnostics)
		if resp.Diagnostics.HasError() {
			return
		}
	}
	desc := plan.Description.ValueString()
	in := runr.GroupInput{Name: plan.Name.ValueString(), Description: &desc}
	if known(plan.Role) {
		role := plan.Role.ValueString()
		in.Role = &role
	}
	g, err := r.client.API.CreateGroup(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create group failed", err)
		return
	}
	plan.ID = types.StringValue(g.ID)
	// Saved before the members are added, so a failure leaves the group in the state.
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
	if len(ids) > 0 {
		r.syncMembers(ctx, g, ids, &resp.Diagnostics)
	}
	if resp.Diagnostics.HasError() {
		return
	}
	r.read(ctx, &plan, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *groupResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state groupModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if !r.read(ctx, &state, &resp.Diagnostics) {
		resp.State.RemoveResource(ctx)
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *groupResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state groupModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := runr.GroupInput{}
	changed := false
	if plan.Name.ValueString() != state.Name.ValueString() {
		in.Name, changed = plan.Name.ValueString(), true
	}
	if plan.Description.ValueString() != state.Description.ValueString() {
		desc := plan.Description.ValueString()
		in.Description, changed = &desc, true
	}
	// The role is sent only when it changes: setting it needs roles.manage.
	if plan.Role.ValueString() != state.Role.ValueString() {
		role := plan.Role.ValueString()
		in.Role, changed = &role, true
	}
	id := state.ID.ValueString()
	if changed {
		if _, err := r.client.API.UpdateGroup(ctx, id, in); err != nil {
			apiError(&resp.Diagnostics, "Update group failed", err)
			return
		}
	}
	plan.ID = state.ID
	if known(plan.Members) {
		ids := r.memberIDs(ctx, stringsOfSet(ctx, plan.Members, &resp.Diagnostics), &resp.Diagnostics)
		if resp.Diagnostics.HasError() {
			return
		}
		g, err := r.client.API.GetGroup(ctx, id)
		if err != nil {
			apiError(&resp.Diagnostics, "Read group failed", err)
			return
		}
		r.syncMembers(ctx, g, ids, &resp.Diagnostics)
		if resp.Diagnostics.HasError() {
			return
		}
	}
	r.read(ctx, &plan, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *groupResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state groupModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteGroup(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete group failed", err)
	}
}

// ImportState accepts the group id or name. Membership stays unmanaged until members is set.
func (r *groupResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	g, err := r.client.API.FindGroup(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import group failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), g.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("members"), types.SetNull(types.StringType))...)
}
