package provider

import (
	"context"
	"regexp"
	"strings"

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

func init() { registerResource(NewBindingAttachmentResource) }

var (
	_ resource.Resource                = &bindingAttachmentResource{}
	_ resource.ResourceWithConfigure   = &bindingAttachmentResource{}
	_ resource.ResourceWithImportState = &bindingAttachmentResource{}
)

// NewBindingAttachmentResource returns the runr_binding_attachment resource.
func NewBindingAttachmentResource() resource.Resource { return &bindingAttachmentResource{} }

type bindingAttachmentResource struct{ client *Client }

type bindingAttachmentModel struct {
	ID          types.String `tfsdk:"id"`
	ProjectID   types.String `tfsdk:"project_id"`
	BindingID   types.String `tfsdk:"binding_id"`
	Environment types.String `tfsdk:"environment"`
	EnvPrefix   types.String `tfsdk:"env_prefix"`
	Credentials types.String `tfsdk:"credentials"`
	EnvNames    types.List   `tfsdk:"env_names"`
}

func (r *bindingAttachmentResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_binding_attachment"
}

func (r *bindingAttachmentResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		MarkdownDescription: "Attaches a `runr_binding` to a project: the binding's connection details reach the project's " +
			"functions as environment variables (with `env_prefix` in front, e.g. `ORDERS_DATABASE_URL`). Attaching, " +
			"changing the prefix or the credentials and detaching roll the running service; nothing needs to be redeployed.\n\n" +
			"A `kv`, `object` or `postgres` binding can be attached to projects of its region; external bindings to any " +
			"project. With `environment` the binding is attached to one environment of the project instead of its own workload " +
			"(it must be in the region the environment runs in); bindings are never inherited, and a binding is attached to at " +
			"most one environment of a project. Needs `env.edit` on the project and `bindings.manage`. Import with " +
			"`<project name or id>/<binding name or id>` or `<project name or id>/<environment>/<binding name or id>`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Attachment id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"binding_id": schema.StringAttribute{Required: true, MarkdownDescription: "Binding id (`runr_binding.<name>.id`).",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplace()}},
			"environment": schema.StringAttribute{Optional: true,
				PlanModifiers: []planmodifier.String{environmentRequiresReplace()},
				MarkdownDescription: "Environment of the project the binding is attached to (`runr_environment.<name>.name` or its " +
					"id): its variables go into the environment's bindings Secret and only its pods may reach the binding. Left out " +
					"(or `production`): the project's own workload. Changing it attaches the binding anew."},
			"credentials": schema.StringAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()},
				Validators:    []validator.String{stringvalidator.OneOf(runr.CredentialsStatic, runr.CredentialsDynamic)},
				MarkdownDescription: "`static` (the binding's own user; the default) or `dynamic`: a Postgres user of the " +
					"attachment's own from the OpenBao of its region, renewed by RunR (a ready `postgres` binding; the region runs " +
					"OpenBao and External Secrets Operator). Changed in place. Left out, the attachment keeps its credentials."},
			"env_prefix": schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString(""),
				MarkdownDescription: "Prefix of the variable names: upper case letters, digits and `_`, starting with a letter " +
					"and ending with `_` (default: none). Changing it updates the attachment in place.",
				Validators: []validator.String{stringvalidator.LengthAtMost(32), stringvalidator.RegexMatches(
					regexp.MustCompile(`^([A-Z][A-Z0-9_]*_)?$`), "must be upper case letters, digits and _, start with a letter and end with _")}},
			"env_names": schema.ListAttribute{Computed: true, ElementType: types.StringType,
				MarkdownDescription: "The variables the project gets from this attachment."},
		},
	}
}

func (r *bindingAttachmentResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *bindingAttachmentResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan bindingAttachmentModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := plan.ProjectID.ValueString()
	// Attachments of one project are checked against each other (variable names): one at a time.
	unlock := r.client.lock(pid)
	defer unlock()
	in := runr.AttachBindingInput{BindingID: plan.BindingID.ValueString(), EnvPrefix: plan.EnvPrefix.ValueString(),
		Environment: environmentRef(plan.Environment)}
	if known(plan.Credentials) && plan.Credentials.ValueString() != runr.CredentialsStatic {
		in.Credentials = plan.Credentials.ValueString()
	}
	a, err := r.client.API.AttachBinding(ctx, pid, in)
	if err != nil {
		environmentError(&resp.Diagnostics, "Attach binding failed", err)
		return
	}
	plan.fromAPI(ctx, a, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *bindingAttachmentResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state bindingAttachmentModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pb, err := r.client.API.ProjectBindings(ctx, state.ProjectID.ValueString())
	if runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read binding attachments failed", err)
		return
	}
	for i := range pb.Attachments {
		if pb.Attachments[i].ID == state.ID.ValueString() {
			state.fromAPI(ctx, &pb.Attachments[i], &resp.Diagnostics)
			resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
			return
		}
	}
	// Detached (or the binding deleted) outside Terraform.
	resp.State.RemoveResource(ctx)
}

func (r *bindingAttachmentResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state bindingAttachmentModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid, id := state.ProjectID.ValueString(), state.ID.ValueString()
	unlock := r.client.lock(pid)
	defer unlock()
	var a *runr.BindingAttachment
	var err error
	if !plan.EnvPrefix.Equal(state.EnvPrefix) {
		if a, err = r.client.API.UpdateAttachment(ctx, pid, id, plan.EnvPrefix.ValueString()); err != nil {
			apiError(&resp.Diagnostics, "Change binding prefix failed", err)
			return
		}
	}
	// static ↔ dynamic in place; left out (unknown) the credentials stay
	if known(plan.Credentials) && !plan.Credentials.Equal(state.Credentials) {
		if a, err = r.client.API.SetAttachmentCredentials(ctx, pid, id, plan.Credentials.ValueString()); err != nil {
			apiError(&resp.Diagnostics, "Change binding credentials failed", err)
			return
		}
	}
	if a == nil {
		if a, err = r.client.API.FindAttachment(ctx, pid, id); err != nil {
			apiError(&resp.Diagnostics, "Read binding attachment failed", err)
			return
		}
	}
	plan.fromAPI(ctx, a, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *bindingAttachmentResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state bindingAttachmentModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	pid := state.ProjectID.ValueString()
	unlock := r.client.lock(pid)
	defer unlock()
	if err := r.client.API.DetachBinding(ctx, pid, state.ID.ValueString()); err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Detach binding failed", err)
	}
}

// ImportState accepts "<project id or name>/<binding id or name>" (an attachment of the project's
// own workload or of any of its environments) or "<project id or name>/<environment>/<binding id
// or name>".
func (r *bindingAttachmentResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	parts := strings.Split(req.ID, "/")
	if (len(parts) != 2 && len(parts) != 3) || parts[0] == "" || parts[len(parts)-1] == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<binding id or name> or <project id or name>/<environment>/<binding id or name>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, parts[0])
	if err != nil {
		apiError(&resp.Diagnostics, "Import binding attachment failed", err)
		return
	}
	env := ""
	if len(parts) == 3 {
		env = parts[1]
	}
	a, err := r.client.API.FindEnvironmentAttachment(ctx, p.ID, environmentRef(types.StringValue(env)), parts[len(parts)-1])
	if err == nil && env == runr.PrimaryEnvironment && a.EnvironmentID != "" {
		err = &runr.NotFoundError{Kind: "attached binding", Ref: parts[2]}
	}
	if err != nil {
		environmentError(&resp.Diagnostics, "Import binding attachment failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), a.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), p.ID)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("binding_id"), a.BindingID)...)
	switch {
	case env != "":
		resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("environment"), env)...)
	case a.EnvironmentID != "":
		resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("environment"), a.EnvironmentName())...)
	}
}

func (m *bindingAttachmentModel) fromAPI(ctx context.Context, a *runr.BindingAttachment, diags *diag.Diagnostics) {
	m.ID = types.StringValue(a.ID)
	if a.ProjectID != "" {
		m.ProjectID = types.StringValue(a.ProjectID)
	}
	if a.BindingID != "" {
		m.BindingID = types.StringValue(a.BindingID)
	}
	m.Environment = environmentState(m.Environment, a.EnvironmentID, a.Environment)
	m.EnvPrefix = types.StringValue(a.EnvPrefix)
	m.Credentials = types.StringValue(runr.CredentialsStatic)
	if a.Dynamic() {
		m.Credentials = types.StringValue(runr.CredentialsDynamic)
	}
	names, d := types.ListValueFrom(ctx, types.StringType, append([]string{}, a.EnvNames...))
	diags.Append(d...)
	m.EnvNames = names
}
