package provider

import (
	"context"
	"errors"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                = &topicResource{}
	_ resource.ResourceWithConfigure   = &topicResource{}
	_ resource.ResourceWithImportState = &topicResource{}
)

func init() { registerResource(NewTopicResource) }

// NewTopicResource returns the runr_topic resource.
func NewTopicResource() resource.Resource { return &topicResource{} }

type topicResource struct{ client *Client }

type topicModel struct {
	ID          types.String `tfsdk:"id"`
	Name        types.String `tfsdk:"name"`
	RegionID    types.String `tfsdk:"region_id"`
	Description types.String `tfsdk:"description"`
	Stream      types.String `tfsdk:"stream"`
}

var topicNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`)

func (r *topicResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_topic"
}

func (r *topicResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	keep := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "A fan-out topic of the organization ([topics](https://runr.sh/docs/triggers/#topics)): an event " +
			"published to it (`runr topics publish`, `POST /api/topics/{topic}/publish`) reaches every project with a `topic` " +
			"trigger on it (`runr_project.triggers`). The region needs Redis. A topic with subscribing triggers cannot be " +
			"destroyed: remove the triggers first.\n\nImport: `terraform import runr_topic.orders orders` (name or id).",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "Topic id."},
			"name": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Name, unique in the organization (letters, digits and `. _ -`, at most 100). Changing it creates a new topic.",
				Validators: []validator.String{stringvalidator.RegexMatches(topicNamePattern,
					"must start with a letter or digit and contain letters, digits, dots, underscores or dashes (at most 100)")}},
			"region_id": schema.StringAttribute{Required: true, PlanModifiers: replace,
				MarkdownDescription: "Region whose Redis carries the topic. Changing it creates a new topic."},
			"description": schema.StringAttribute{Optional: true, MarkdownDescription: "Free text (at most 500 characters).",
				Validators: []validator.String{stringvalidator.LengthAtMost(500)}},
			"stream": schema.StringAttribute{Computed: true, PlanModifiers: keep, MarkdownDescription: "The topic's Redis stream."},
		},
	}
}

func (r *topicResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (m *topicModel) fromAPI(t *runr.Topic) {
	m.ID = types.StringValue(t.ID)
	m.Name = types.StringValue(t.Name)
	m.RegionID = types.StringValue(t.RegionID)
	if t.Description != "" || !m.Description.IsNull() {
		m.Description = types.StringValue(t.Description)
	}
	m.Stream = strOrNull(t.Stream)
}

func (r *topicResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan topicModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	t, err := r.client.API.CreateTopic(ctx, plan.Name.ValueString(), plan.RegionID.ValueString(), plan.Description.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Create topic failed", err)
		return
	}
	plan.fromAPI(t)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *topicResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state topicModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	t, err := r.client.API.GetTopic(ctx, state.ID.ValueString())
	var nf *runr.NotFoundError
	if errors.As(err, &nf) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read topic failed", err)
		return
	}
	state.fromAPI(t)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *topicResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan, state topicModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	t, err := r.client.API.UpdateTopicDescription(ctx, state.ID.ValueString(), plan.Description.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Update topic failed", err)
		return
	}
	plan.fromAPI(t)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *topicResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state topicModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.client.API.DeleteTopic(ctx, state.ID.ValueString())
	var nf *runr.NotFoundError
	if err != nil && !errors.As(err, &nf) {
		apiError(&resp.Diagnostics, "Delete topic failed", err)
	}
}

// ImportState accepts the topic's name or id.
func (r *topicResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	t, err := r.client.API.GetTopic(ctx, req.ID)
	if err != nil {
		apiError(&resp.Diagnostics, "Import topic failed", err)
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), t.ID)...)
}
