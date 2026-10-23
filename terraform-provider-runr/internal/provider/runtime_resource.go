package provider

import (
	"context"
	"errors"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"gopkg.in/yaml.v3"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                = &runtimeResource{}
	_ resource.ResourceWithConfigure   = &runtimeResource{}
	_ resource.ResourceWithImportState = &runtimeResource{}
)

func init() { registerResource(NewRuntimeResource) }

// NewRuntimeResource returns the runr_runtime resource.
func NewRuntimeResource() resource.Resource { return &runtimeResource{} }

type runtimeResource struct{ client *Client }

type runtimeModel struct {
	ID       types.String `tfsdk:"id"`
	Manifest types.String `tfsdk:"manifest"`
	Files    types.Map    `tfsdk:"files"`
	Regions  types.List   `tfsdk:"regions"`
	Enabled  types.Bool   `tfsdk:"enabled"`
	Name     types.String `tfsdk:"name"`
	Source   types.String `tfsdk:"source"`
	RunMode  types.String `tfsdk:"run_mode"`
	Versions types.List   `tfsdk:"versions"`
}

var runtimeVersionType = types.ObjectType{AttrTypes: map[string]attr.Type{
	"version": types.StringType, "is_default": types.BoolType, "eol_at": types.StringType,
	"image": types.StringType, "built": types.BoolType,
}}

// manifestID returns the id of a manifest text ("" when it cannot be read).
func manifestID(text string) string {
	var m struct {
		ID string `yaml:"id"`
	}
	if yaml.Unmarshal([]byte(text), &m) != nil {
		return ""
	}
	return strings.TrimSpace(m.ID)
}

func (r *runtimeResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_runtime"
}

func (r *runtimeResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A runtime plugin registered from a `runtime.yaml` manifest (docs/runtimes.md): a platform " +
			"runtime for tokens with `runtimes.manage`, a custom runtime of the organization with `runtimes.use_custom`. " +
			"RunR validates the manifest and builds the image of `dockerfile` and `git` versions in each region. " +
			"A change of the manifest's `id` replaces the runtime; deleting a runtime that projects use fails.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep,
				MarkdownDescription: "Runtime id (the manifest's `id`), stored on projects as `runtime`."},
			"manifest": schema.StringAttribute{Required: true,
				MarkdownDescription: "The manifest as YAML or JSON text, e.g. `file(\"runtime.yaml\")` or `yamlencode({...})`.",
				PlanModifiers: []planmodifier.String{stringplanmodifier.RequiresReplaceIf(
					func(_ context.Context, req planmodifier.StringRequest, resp *stringplanmodifier.RequiresReplaceIfFuncResponse) {
						if known(req.StateValue) && known(req.PlanValue) {
							resp.RequiresReplace = manifestID(req.StateValue.ValueString()) != manifestID(req.PlanValue.ValueString())
						}
					}, "A new manifest id replaces the runtime.", "A new manifest `id` replaces the runtime.")}},
			"files": schema.MapAttribute{Optional: true, ElementType: types.StringType,
				MarkdownDescription: "Files uploaded with the runtime, name → content: the Dockerfile and build context of " +
					"`dockerfile` versions (flat names, at most 900 KB together)."},
			"regions": schema.ListAttribute{Optional: true, ElementType: types.StringType,
				MarkdownDescription: "Ids of the regions the runtime is offered in; unset offers it in every region."},
			"enabled": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Whether new projects can pick the runtime and its projects deploy (default `true`)."},
			"name":     schema.StringAttribute{Computed: true, MarkdownDescription: "Display name."},
			"source":   schema.StringAttribute{Computed: true, MarkdownDescription: "`platform` or `custom`."},
			"run_mode": schema.StringAttribute{Computed: true, MarkdownDescription: "`http`, `exec` or `persistent`."},
			"versions": schema.ListAttribute{Computed: true, ElementType: runtimeVersionType,
				MarkdownDescription: "Versions: `version`, `is_default`, `eol_at`, `image` (empty for versions RunR builds per region) and `built`."},
		},
	}
}

func (r *runtimeResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (m *runtimeModel) input(ctx context.Context, diags *diag.Diagnostics, update bool) runr.RuntimeInput {
	text := m.Manifest.ValueString()
	in := runr.RuntimeInput{Manifest: &text}
	if known(m.Files) {
		files := map[string]string{}
		diags.Append(m.Files.ElementsAs(ctx, &files, false)...)
		in.Files = files
	} else if update {
		in.Files = map[string]string{}
	}
	if known(m.Regions) {
		var regions []string
		diags.Append(m.Regions.ElementsAs(ctx, &regions, false)...)
		if regions == nil {
			regions = []string{}
		}
		in.Regions = regions
	} else if update {
		in.AllRegions = true
	}
	return in
}

func (m *runtimeModel) fromAPI(ctx context.Context, d *runr.RuntimeDetail, diags *diag.Diagnostics) {
	m.ID = types.StringValue(d.ID)
	m.Name = types.StringValue(d.Name)
	m.Source = strOrNull(d.Source)
	m.RunMode = strOrNull(d.RunMode)
	m.Enabled = types.BoolValue(d.IsEnabled)
	if d.ManifestSource != "" {
		m.Manifest = types.StringValue(d.ManifestSource)
	}
	if len(d.Files) > 0 || known(m.Files) {
		files := map[string]attr.Value{}
		for k, v := range d.Files {
			files[k] = types.StringValue(v)
		}
		fm, dd := types.MapValue(types.StringType, files)
		diags.Append(dd...)
		m.Files = fm
	}
	if len(d.Regions) > 0 {
		list, dd := types.ListValueFrom(ctx, types.StringType, d.Regions)
		diags.Append(dd...)
		m.Regions = list
	} else if !known(m.Regions) {
		m.Regions = types.ListNull(types.StringType)
	}
	versions := make([]attr.Value, 0, len(d.Versions))
	for _, v := range d.Versions {
		obj, dd := types.ObjectValue(runtimeVersionType.AttrTypes, map[string]attr.Value{
			"version": types.StringValue(v.Version), "is_default": types.BoolValue(v.IsDefault),
			"eol_at": strOrNull(v.EOLAt), "image": strOrNull(v.Image), "built": types.BoolValue(v.Built),
		})
		diags.Append(dd...)
		versions = append(versions, obj)
	}
	list, dd := types.ListValue(runtimeVersionType, versions)
	diags.Append(dd...)
	m.Versions = list
}

func (r *runtimeResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan runtimeModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := plan.input(ctx, &resp.Diagnostics, false)
	if resp.Diagnostics.HasError() {
		return
	}
	d, err := r.client.API.RegisterRuntime(ctx, in)
	if err != nil {
		runtimeError(&resp.Diagnostics, "Register runtime failed", err)
		return
	}
	if !plan.Enabled.IsNull() && !plan.Enabled.ValueBool() {
		off := false
		if d, err = r.client.API.UpdateRuntime(ctx, d.ID, runr.RuntimeInput{IsEnabled: &off}); err != nil {
			apiError(&resp.Diagnostics, "Disable runtime failed", err)
			return
		}
	}
	plan.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *runtimeResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state runtimeModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	d, err := r.client.API.GetRuntime(ctx, state.ID.ValueString())
	var nf *runr.NotFoundError
	if errors.As(err, &nf) || runr.IsNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read runtime failed", err)
		return
	}
	state.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *runtimeResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state runtimeModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	in := plan.input(ctx, &resp.Diagnostics, true)
	if known(plan.Enabled) {
		v := plan.Enabled.ValueBool()
		in.IsEnabled = &v
	}
	if resp.Diagnostics.HasError() {
		return
	}
	d, err := r.client.API.UpdateRuntime(ctx, state.ID.ValueString(), in)
	if err != nil {
		runtimeError(&resp.Diagnostics, "Update runtime failed", err)
		return
	}
	plan.fromAPI(ctx, d, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *runtimeResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state runtimeModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.DeleteRuntime(ctx, state.ID.ValueString())
	var nf *runr.NotFoundError
	if err != nil && !errors.As(err, &nf) {
		apiError(&resp.Diagnostics, "Delete runtime failed", err)
	}
}

// ImportState accepts the runtime id.
func (r *runtimeResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("id"), req, resp)
}

// runtimeError adds an API error; an invalid manifest lists each problem with its JSON path.
func runtimeError(diags *diag.Diagnostics, summary string, err error) {
	problems := runr.ManifestProblems(err)
	if len(problems) == 0 {
		apiError(diags, summary, err)
		return
	}
	lines := make([]string, len(problems))
	for i, p := range problems {
		lines[i] = p.Path + ": " + p.Message
	}
	diags.AddAttributeError(path.Root("manifest"), "Invalid runtime manifest", strings.Join(lines, "\n"))
}
