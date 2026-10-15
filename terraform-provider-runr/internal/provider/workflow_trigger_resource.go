package provider

import (
	"context"
	"errors"
	"fmt"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

var (
	_ resource.Resource                   = &workflowTriggerResource{}
	_ resource.ResourceWithConfigure      = &workflowTriggerResource{}
	_ resource.ResourceWithImportState    = &workflowTriggerResource{}
	_ resource.ResourceWithValidateConfig = &workflowTriggerResource{}
)

func init() { registerResource(NewWorkflowTriggerResource) }

// NewWorkflowTriggerResource returns the runr_workflow_trigger resource.
func NewWorkflowTriggerResource() resource.Resource { return &workflowTriggerResource{} }

type workflowTriggerResource struct{ client *Client }

type workflowTriggerModel struct {
	ID        types.String `tfsdk:"id"`
	ProjectID types.String `tfsdk:"project_id"`
	Workflow  types.String `tfsdk:"workflow"`
	Event     types.String `tfsdk:"event"`
	Condition types.String `tfsdk:"condition"`
	Cron      types.String `tfsdk:"cron"`
	Timezone  types.String `tfsdk:"timezone"`
	Queue     types.String `tfsdk:"queue"`
}

func (m *workflowTriggerModel) trigger() runr.WorkflowTrigger {
	return runr.WorkflowTrigger{Event: m.Event.ValueString(), If: m.Condition.ValueString(), Cron: m.Cron.ValueString(),
		Timezone: m.Timezone.ValueString(), Queue: m.Queue.ValueString()}
}

// triggerKey identifies a trigger in the resource id: event:<name>, queue:<stream> or
// cron:<expression>[@<time zone>].
func triggerKey(t runr.WorkflowTrigger) string {
	switch t.Kind() {
	case "cron":
		if t.Timezone != "" {
			return "cron:" + t.Cron + "@" + t.Timezone
		}
		return "cron:" + t.Cron
	case "queue":
		return "queue:" + t.Stream()
	}
	return "event:" + t.Event
}

func parseTriggerKey(key string) (runr.WorkflowTrigger, bool) {
	kind, value, ok := strings.Cut(key, ":")
	if !ok || value == "" {
		return runr.WorkflowTrigger{}, false
	}
	switch kind {
	case "event":
		return runr.WorkflowTrigger{Event: value}, true
	case "queue":
		return runr.WorkflowTrigger{Queue: value}, true
	case "cron":
		expr, tz, _ := strings.Cut(value, "@")
		return runr.WorkflowTrigger{Cron: expr, Timezone: tz}, expr != ""
	}
	return runr.WorkflowTrigger{}, false
}

// sameTarget reports whether two triggers fire on the same thing (event name, queue stream or
// cron schedule), conditions aside.
func sameTarget(a, b runr.WorkflowTrigger) bool {
	a.If, b.If = "", ""
	return a.Same(b)
}

func (r *workflowTriggerResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_workflow_trigger"
}

func (r *workflowTriggerResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		MarkdownDescription: "One trigger of a durable workflow: an event (with an optional condition), a cron schedule or " +
			"a queue stream. Exactly one of `event`, `cron` and `queue` is set.\n\n" +
			"The workflow must exist and be declared through the API (`runr workflows declare`, `PUT " +
			"/api/projects/{project}/workflows`) or by `runr.workflows.json`; the resource adds its trigger to the " +
			"definition and leaves the definition's other triggers and options as they are. Workflows whose " +
			"definitions the function's SDK reports get their triggers from the code: change them there.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true,
				MarkdownDescription: "`<project_id>/<workflow>/<event:NAME | queue:STREAM | cron:EXPRESSION[@TIME_ZONE]>`.",
				PlanModifiers:       []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, MarkdownDescription: "Project id.", PlanModifiers: replace},
			"workflow":   schema.StringAttribute{Required: true, MarkdownDescription: "Workflow name.", PlanModifiers: replace},
			"event": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Event name that starts a run, e.g. `order.created` (letters, digits and `. _ : / -`)."},
			"condition": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Expression the event must satisfy (`if`), e.g. `event.data.total > 100`; event and queue triggers."},
			"cron": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Five-field cron expression; the engine starts one run per due minute."},
			"timezone": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "IANA time zone of `cron`, e.g. `Europe/Istanbul` (default UTC)."},
			"queue": schema.StringAttribute{Optional: true, PlanModifiers: replace,
				MarkdownDescription: "Redis stream of the project's queue bridge; each message starts a run (event `queue/<stream>`)."},
		},
	}
}

func (r *workflowTriggerResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *workflowTriggerResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m workflowTriggerModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	set, unknown := 0, false
	for _, v := range []types.String{m.Event, m.Cron, m.Queue} {
		if v.IsUnknown() {
			unknown = true
		} else if !v.IsNull() {
			set++
		}
	}
	if !unknown && set != 1 {
		resp.Diagnostics.AddError("Set exactly one of event, cron and queue",
			"A runr_workflow_trigger is one event trigger, one cron schedule or one queue stream.")
	}
	if !m.Timezone.IsNull() && m.Cron.IsNull() && !m.Cron.IsUnknown() {
		resp.Diagnostics.AddAttributeError(path.Root("timezone"), "timezone without cron", "timezone applies to cron triggers.")
	}
	if !m.Condition.IsNull() && !m.Cron.IsNull() && !m.Cron.IsUnknown() {
		resp.Diagnostics.AddAttributeError(path.Root("condition"), "condition on a cron trigger", "condition applies to event and queue triggers.")
	}
	if known(m.Event) && strings.HasPrefix(m.Event.ValueString(), "runr/") {
		resp.Diagnostics.AddAttributeError(path.Root("event"), "Reserved event name", "Event names under runr/ are reserved for the platform.")
	}
}

// change adds (add) or removes the trigger of m from its workflow's definition and declares
// the project's definitions again. The project's definitions are one document, so changes are
// serialised per project.
func (r *workflowTriggerResource) change(ctx context.Context, m *workflowTriggerModel, add bool) error {
	projectID, name := m.ProjectID.ValueString(), m.Workflow.ValueString()
	unlock := r.client.lock("workflows/" + projectID)
	defer unlock()
	list, _, err := r.client.API.ListWorkflows(ctx, projectID)
	if err != nil {
		return err
	}
	want := m.trigger()
	var target *runr.Workflow
	for i := range list {
		if list[i].Name == name && !list[i].Archived {
			target = &list[i]
		}
	}
	if target == nil {
		if !add {
			return nil // the workflow is gone, and its triggers with it
		}
		return fmt.Errorf("workflow %q does not exist in project %s (declare it with `runr workflows declare`)", name, projectID)
	}
	if target.Source == "sdk" {
		if !add {
			return nil // the code owns the workflow's triggers now
		}
		return fmt.Errorf("the triggers of workflow %q come from the project's code (the SDK reports its definition); "+
			"change them in the code, or declare the workflow through the API", name)
	}
	var triggers []runr.WorkflowTrigger
	present := false
	for _, t := range target.Triggers {
		if t.Same(want) {
			present = true
			if !add {
				continue
			}
		}
		triggers = append(triggers, t)
	}
	if add == present {
		return nil // nothing to change
	}
	if add {
		triggers = append(triggers, want)
	}
	target.Triggers = triggers
	defs := make([]map[string]any, 0, len(list))
	for i := range list {
		if !list[i].Archived {
			defs = append(defs, list[i].Definition())
		}
	}
	_, err = r.client.API.DeclareWorkflows(ctx, projectID, defs)
	return err
}

func (r *workflowTriggerResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan workflowTriggerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.change(ctx, &plan, true); err != nil {
		apiError(&resp.Diagnostics, "Add workflow trigger failed", err)
		return
	}
	plan.ID = types.StringValue(plan.ProjectID.ValueString() + "/" + plan.Workflow.ValueString() + "/" + triggerKey(plan.trigger()))
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *workflowTriggerResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state workflowTriggerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	w, err := r.client.API.GetWorkflow(ctx, state.ProjectID.ValueString(), state.Workflow.ValueString())
	if runr.IsNotFound(err) || wfNotFound(err) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		apiError(&resp.Diagnostics, "Read workflow failed", err)
		return
	}
	if w.Archived {
		resp.State.RemoveResource(ctx)
		return
	}
	want := state.trigger()
	var match *runr.WorkflowTrigger
	for i, t := range w.Triggers {
		if t.Same(want) {
			match = &w.Triggers[i]
			break
		}
		if match == nil && state.Condition.IsNull() && sameTarget(t, want) {
			// Imported, or the condition changed outside Terraform: take the API's.
			match = &w.Triggers[i]
		}
	}
	if match == nil {
		resp.State.RemoveResource(ctx)
		return
	}
	state.Condition = strOrNull(match.If)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

// Update is never called with a change: every attribute replaces the resource.
func (r *workflowTriggerResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan workflowTriggerModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *workflowTriggerResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state workflowTriggerModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	err := r.change(ctx, &state, false)
	if err != nil && !runr.IsNotFound(err) {
		apiError(&resp.Diagnostics, "Remove workflow trigger failed", err)
	}
}

// ImportState accepts "<project id or name>/<workflow>/<event:NAME | queue:STREAM | cron:EXPRESSION[@TIME_ZONE]>".
func (r *workflowTriggerResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	parts := strings.SplitN(req.ID, "/", 3)
	if len(parts) != 3 || parts[0] == "" || parts[1] == "" {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<workflow>/<event:NAME | queue:STREAM | cron:EXPRESSION[@TIME_ZONE]>.")
		return
	}
	t, ok := parseTriggerKey(parts[2])
	if !ok {
		resp.Diagnostics.AddError("Invalid import id", "The trigger must be event:NAME, queue:STREAM or cron:EXPRESSION[@TIME_ZONE].")
		return
	}
	p, err := r.client.API.FindProject(ctx, parts[0])
	if err != nil {
		apiError(&resp.Diagnostics, "Import workflow trigger failed", err)
		return
	}
	m := workflowTriggerModel{
		ID:        types.StringValue(p.ID + "/" + parts[1] + "/" + triggerKey(t)),
		ProjectID: types.StringValue(p.ID), Workflow: types.StringValue(parts[1]),
		Event: strOrNull(t.Event), Condition: types.StringNull(), Cron: strOrNull(t.Cron),
		Timezone: strOrNull(t.Timezone), Queue: strOrNull(t.Queue),
	}
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}

// wfNotFound reports a name that did not resolve (*runr.NotFoundError).
func wfNotFound(err error) bool {
	var nf *runr.NotFoundError
	return errors.As(err, &nf)
}
