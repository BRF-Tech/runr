package provider

import (
	"context"
	"regexp"
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

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                = &roleResource{}
	_ resource.ResourceWithConfigure   = &roleResource{}
	_ resource.ResourceWithImportState = &roleResource{}
)

func init() { registerResource(NewRoleResource) }

// NewRoleResource returns the runr_role resource.
func NewRoleResource() resource.Resource { return &roleResource{} }

type roleResource struct{ client *Client }

type roleModel struct {
	ID                   types.String `tfsdk:"id"`
	Key                  types.String `tfsdk:"key"`
	Name                 types.String `tfsdk:"name"`
	Description          types.String `tfsdk:"description"`
	Permissions          types.Set    `tfsdk:"permissions"`
	EffectivePermissions types.Set    `tfsdk:"effective_permissions"`
}

func (r *roleResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_role"
}

func (r *roleResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A custom role of the organization (role-based access control). Give it to members " +
			"(`runr members set-role`), groups (`runr_group.role`) or on single projects (`runr_project_access`). " +
			"Built-in roles (owner, admin, developer, viewer) are part of RunR and cannot be managed. Needs `roles.manage`; " +
			"nobody can create a role with permissions they do not hold themselves.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "The role key."},
			"key": schema.StringAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Role key (letters, digits, `-`, `_`). Default: the slug of `name`. Changing it replaces the role.",
				PlanModifiers:       []planmodifier.String{stringplanmodifier.RequiresReplaceIfConfigured(), stringplanmodifier.UseStateForUnknown()},
				Validators: []validator.String{stringvalidator.LengthBetween(1, 64),
					stringvalidator.RegexMatches(regexp.MustCompile(`^[A-Za-z0-9_-]+$`), "must contain letters, digits, - and _ only")}},
			"name": schema.StringAttribute{Required: true, MarkdownDescription: "Display name.",
				Validators: []validator.String{stringvalidator.LengthBetween(1, 100)}},
			"description": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(""),
				MarkdownDescription: "Description.", Validators: []validator.String{stringvalidator.LengthAtMost(500)}},
			"permissions": schema.SetAttribute{Required: true, ElementType: types.StringType,
				MarkdownDescription: "Permissions of the role (`runr roles permissions` lists the catalog). " +
					"The permissions they imply are added by RunR and listed in `effective_permissions`."},
			"effective_permissions": schema.SetAttribute{Computed: true, ElementType: types.StringType,
				MarkdownDescription: "`permissions` with every permission they imply (`env.edit` brings `env.view`)."},
		},
	}
}

func (r *roleResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func setOfStrings(ctx context.Context, s []string, diags *diag.Diagnostics) types.Set {
	if s == nil {
		s = []string{}
	}
	v, d := types.SetValueFrom(ctx, types.StringType, s)
	diags.Append(d...)
	return v
}

func stringsOfSet(ctx context.Context, v types.Set, diags *diag.Diagnostics) []string {
	if !known(v) {
		return nil
	}
	var out []string
	diags.Append(v.ElementsAs(ctx, &out, false)...)
	if out == nil {
		out = []string{}
	}
	sort.Strings(out)
	return out
}

func sameStrings(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	x := append([]string(nil), a...)
	y := append([]string(nil), b...)
	sort.Strings(x)
	sort.Strings(y)
	for i := range x {
		if x[i] != y[i] {
			return false
		}
	}
	return true
}

// apply writes the role's state from an API answer. The configured permissions are kept as long
// as they expand to what the API holds (the API stores them with their implied permissions).
func (r *roleResource) apply(ctx context.Context, m *roleModel, role *runr.Role, diags *diag.Diagnostics) {
	m.ID = types.StringValue(role.Key)
	m.Key = types.StringValue(role.Key)
	m.Name = types.StringValue(role.Name)
	m.Description = types.StringValue(role.Description)
	m.EffectivePermissions = setOfStrings(ctx, role.Permissions, diags)
	configured := stringsOfSet(ctx, m.Permissions, diags)
	if configured != nil {
		if sameStrings(configured, role.Permissions) {
			return
		}
		if cat, err := r.client.API.ListPermissions(ctx); err == nil && sameStrings(cat.Expand(configured), role.Permissions) {
			return
		}
	}
	m.Permissions = setOfStrings(ctx, role.Permissions, diags)
}

func (r *roleResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan roleModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	perms := stringsOfSet(ctx, plan.Permissions, &resp.Diagnostics)
	desc := plan.Description.ValueString()
	in := runr.RoleInput{Name: plan.Name.ValueString(), Description: &desc, Permissions: &perms}
	if known(plan.Key) {
		in.Key = plan.Key.ValueString()
	}
	role, err := r.client.API.CreateRole(ctx, in)
	if err != nil {
		apiError(&resp.Diagnostics, "Create role failed", err)
		return
	}
	r.apply(ctx, &plan, role, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *roleResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state roleModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	role, err := r.client.API.GetRole(ctx, state.ID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read role failed", err)
		return
	}
	r.apply(ctx, &state, role, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *roleResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state roleModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	perms := stringsOfSet(ctx, plan.Permissions, &resp.Diagnostics)
	desc := plan.Description.ValueString()
	role, err := r.client.API.UpdateRole(ctx, state.ID.ValueString(),
		runr.RoleInput{Name: plan.Name.ValueString(), Description: &desc, Permissions: &perms})
	if err != nil {
		apiError(&resp.Diagnostics, "Update role failed", err)
		return
	}
	r.apply(ctx, &plan, role, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *roleResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state roleModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.client.API.DeleteRole(ctx, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Delete role failed", err)
	}
}

// ImportState accepts the key of a custom role.
func (r *roleResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	role, err := r.client.API.GetRole(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import role failed", err)
		return
	}
	if role.Builtin {
		resp.Diagnostics.AddError("Built-in role", "The built-in role "+role.Key+" is part of RunR and cannot be managed with Terraform.")
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), role.Key)...)
}
