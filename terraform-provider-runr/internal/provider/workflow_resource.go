package provider

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/boolplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerResource(NewWorkflowResource) }

var (
	_ resource.Resource                   = &workflowResource{}
	_ resource.ResourceWithConfigure      = &workflowResource{}
	_ resource.ResourceWithImportState    = &workflowResource{}
	_ resource.ResourceWithValidateConfig = &workflowResource{}
	_ resource.ResourceWithModifyPlan     = &workflowResource{}
)

// NewWorkflowResource returns the runr_workflow resource.
func NewWorkflowResource() resource.Resource { return &workflowResource{} }

type workflowResource struct{ client *Client }

type workflowModel struct {
	ID          types.String `tfsdk:"id"`
	ProjectID   types.String `tfsdk:"project_id"`
	Environment types.String `tfsdk:"environment"`
	Name        types.String `tfsdk:"name"`
	Description types.String `tfsdk:"description"`
	Triggers    types.List   `tfsdk:"triggers"`
	Retries     types.Int64  `tfsdk:"retries"`
	Timeout     types.String `tfsdk:"timeout"`
	Concurrency types.List   `tfsdk:"concurrency"`
	Idempotency types.String `tfsdk:"idempotency"`
	CancelOn    types.List   `tfsdk:"cancel_on"`
	Active      types.Bool   `tfsdk:"active"`
}

type workflowTriggerItem struct {
	Event     types.String `tfsdk:"event"`
	Condition types.String `tfsdk:"condition"`
	Cron      types.String `tfsdk:"cron"`
	Timezone  types.String `tfsdk:"timezone"`
	Queue     types.String `tfsdk:"queue"`
}

type workflowConcurrencyItem struct {
	Limit types.Int64  `tfsdk:"limit"`
	Key   types.String `tfsdk:"key"`
}

type workflowCancelOnItem struct {
	Event      types.String `tfsdk:"event"`
	Expression types.String `tfsdk:"expression"`
}

var (
	workflowTriggerType = types.ObjectType{AttrTypes: map[string]attr.Type{
		"event": types.StringType, "condition": types.StringType, "cron": types.StringType,
		"timezone": types.StringType, "queue": types.StringType,
	}}
	workflowConcurrencyType = types.ObjectType{AttrTypes: map[string]attr.Type{"limit": types.Int64Type, "key": types.StringType}}
	workflowCancelOnType    = types.ObjectType{AttrTypes: map[string]attr.Type{"event": types.StringType, "expression": types.StringType}}

	workflowNamePattern     = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$`)
	workflowEventPattern    = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:/-]{0,199}$`)
	workflowStreamPattern   = regexp.MustCompile(`^[a-zA-Z0-9._-]{1,255}$`)
	workflowDurationPattern = regexp.MustCompile(`^(?i)((\d+(\.\d+)?(ms|s|m|h|d|w))+|\d+(\.\d+)?|P[0-9A-Z.]+)$`)
)

func (r *workflowResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_workflow"
}

func (r *workflowResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	nonEmpty := []validator.String{stringvalidator.LengthAtLeast(1)}
	resp.Schema = schema.Schema{
		MarkdownDescription: "One durable workflow definition declared through the API ([workflows](https://runr.sh/docs/workflows/)): " +
			"its triggers, retries, timeout, concurrency limits, idempotency key and cancel events. The function's code " +
			"implements the workflow under this name; the definition says when and how it runs.\n\n" +
			"The resource declares this one definition (`PUT /api/projects/{project}/workflows/{workflow}`); destroying " +
			"it archives the workflow (`DELETE …/workflows/{workflow}`). The project's other definitions are not sent and " +
			"stay as they are, their source too: what the function's SDK reported stays `sdk`. (An API before 0.6.0 has " +
			"only the set: the resource declares the set again with this definition added, replaced or left out.) " +
			"Definitions the function's SDK reports (source `sdk`) belong to the code and are refused. `triggers` left out keeps the definition's " +
			"triggers — for example those of `runr_workflow_trigger`; set, it is the whole list (do not combine both for " +
			"one workflow). Pausing (`active = false`) survives later declarations and syncs. Runs, events and the signing " +
			"key are not configuration. Needs `workflows.manage`.\n\n" +
			"Import with `<project name or id>/<workflow>` or `<project name or id>/<environment>/<workflow>`.",
		Attributes: map[string]schema.Attribute{
			"id": schema.StringAttribute{Computed: true,
				MarkdownDescription: "`<project_id>/<name>`, or `<project_id>/<environment>/<name>` for an environment's workflow.",
				PlanModifiers:       []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id": schema.StringAttribute{Required: true, PlanModifiers: replace, MarkdownDescription: "Project id."},
			"environment": schema.StringAttribute{Optional: true, PlanModifiers: replace, Validators: nonEmpty,
				MarkdownDescription: "The project environment whose workflows these are (name or id; " +
					"[environments](https://runr.sh/docs/workflows/#environments)). Left out: the project's own workload (production)."},
			"name": schema.StringAttribute{Required: true, PlanModifiers: replace,
				Validators: []validator.String{stringvalidator.RegexMatches(workflowNamePattern,
					"must be letters, digits, '.', '_' and '-', starting with a letter or digit, at most 100 characters"),
					stringvalidator.NoneOfCaseInsensitive("app", "sync")},
				MarkdownDescription: "Workflow name, as the function's code knows it (not `app` or `sync`)."},
			"description": schema.StringAttribute{Optional: true,
				Validators: []validator.String{stringvalidator.LengthBetween(1, 2000)}, MarkdownDescription: "Description."},
			"triggers": schema.ListNestedAttribute{Optional: true, Computed: true,
				Validators: []validator.List{listvalidator.SizeAtMost(20)},
				MarkdownDescription: "What starts a run: each an `event` (with an optional `condition`), a `cron` schedule (with an " +
					"optional `timezone`) or a `queue` stream. Left out: the definition keeps the triggers it has (none for a new " +
					"workflow), e.g. those `runr_workflow_trigger` adds. Set: exactly these (`[]`: none).",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"event": schema.StringAttribute{Optional: true,
						Validators:          []validator.String{stringvalidator.RegexMatches(workflowEventPattern, "must be letters, digits and . _ : / -")},
						MarkdownDescription: "Event name, e.g. `order.created`."},
					"condition": schema.StringAttribute{Optional: true, Validators: nonEmpty,
						MarkdownDescription: "Expression the event must satisfy (`if`), e.g. `event.data.total > 100`; event and queue triggers."},
					"cron": schema.StringAttribute{Optional: true, Validators: nonEmpty,
						MarkdownDescription: "Five-field cron expression; one run per due minute."},
					"timezone": schema.StringAttribute{Optional: true, Validators: nonEmpty,
						MarkdownDescription: "IANA time zone of `cron` (default UTC)."},
					"queue": schema.StringAttribute{Optional: true,
						Validators:          []validator.String{stringvalidator.RegexMatches(workflowStreamPattern, "must be letters, digits and . _ -")},
						MarkdownDescription: "Redis stream of the project's queue bridge; each message starts a run."},
				}},
			},
			"retries": schema.Int64Attribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Int64{int64planmodifier.UseStateForUnknown()},
				Validators:    []validator.Int64{int64validator.Between(0, 20)},
				MarkdownDescription: "Retries of a failed step (0–20). Left out: a new workflow gets the installation's " +
					"default (3), an existing one keeps its value."},
			"timeout": schema.StringAttribute{Optional: true,
				Validators: []validator.String{stringvalidator.RegexMatches(workflowDurationPattern, `must be a duration such as "30m", "1h30m", "1d" or "PT1H"`)},
				MarkdownDescription: "Longest a run may take, e.g. `1d` (`500ms`, `30s`, `15m`, `2h`, `1d`, `1w`, combinations, " +
					"ISO 8601). Left out: no limit of the workflow's own."},
			"concurrency": schema.ListNestedAttribute{Optional: true,
				Validators:          []validator.List{listvalidator.SizeAtMost(2)},
				MarkdownDescription: "At most two concurrency limits, at most one of them keyed.",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"limit": schema.Int64Attribute{Required: true, Validators: []validator.Int64{int64validator.Between(1, 1000)},
						MarkdownDescription: "Runs at the same time (1–1000)."},
					"key": schema.StringAttribute{Optional: true, Validators: nonEmpty,
						MarkdownDescription: "Expression the limit counts per value of, e.g. `event.data.customer_id`."},
				}},
			},
			"idempotency": schema.StringAttribute{Optional: true, Validators: nonEmpty,
				MarkdownDescription: "Expression of the idempotency key: a second event with the same key starts no run, e.g. `event.data.order_id`."},
			"cancel_on": schema.ListNestedAttribute{Optional: true,
				Validators:          []validator.List{listvalidator.SizeAtMost(10)},
				MarkdownDescription: "Events that cancel matching active runs (at most 10).",
				NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
					"event": schema.StringAttribute{Required: true,
						Validators:          []validator.String{stringvalidator.RegexMatches(workflowEventPattern, "must be letters, digits and . _ : / -")},
						MarkdownDescription: "Event name, e.g. `order.cancelled`."},
					"expression": schema.StringAttribute{Optional: true, Validators: nonEmpty,
						MarkdownDescription: "Expression the event must satisfy to cancel a run (`async` is the run's own event), " +
							"e.g. `event.data.order_id == async.data.order_id`. Left out: every active run."},
				}},
			},
			"active": schema.BoolAttribute{Optional: true, Computed: true,
				PlanModifiers: []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()},
				MarkdownDescription: "`false` pauses the workflow: triggers and the API start no runs; runs in progress go on. " +
					"Left out: a new workflow is active, an existing one keeps its state (e.g. paused with `runr workflows disable`)."},
		},
	}
}

func (r *workflowResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.client = clientFrom(req.ProviderData, &resp.Diagnostics)
}

func (r *workflowResource) ValidateConfig(ctx context.Context, req resource.ValidateConfigRequest, resp *resource.ValidateConfigResponse) {
	var m workflowModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &m)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if known(m.Triggers) {
		var triggers []workflowTriggerItem
		resp.Diagnostics.Append(m.Triggers.ElementsAs(ctx, &triggers, false)...)
		for i, t := range triggers {
			at := path.Root("triggers").AtListIndex(i)
			set, unknown := 0, false
			for _, v := range []types.String{t.Event, t.Cron, t.Queue} {
				if v.IsUnknown() {
					unknown = true
				} else if !v.IsNull() {
					set++
				}
			}
			if !unknown && set != 1 {
				resp.Diagnostics.AddAttributeError(at, "Set exactly one of event, cron and queue",
					"A trigger is one event, one cron schedule or one queue stream.")
			}
			if !t.Timezone.IsNull() && t.Cron.IsNull() {
				resp.Diagnostics.AddAttributeError(at.AtName("timezone"), "timezone without cron", "timezone applies to cron triggers.")
			}
			if !t.Condition.IsNull() && !t.Cron.IsNull() {
				resp.Diagnostics.AddAttributeError(at.AtName("condition"), "condition on a cron trigger", "condition applies to event and queue triggers.")
			}
			if known(t.Event) && strings.HasPrefix(t.Event.ValueString(), "runr/") {
				resp.Diagnostics.AddAttributeError(at.AtName("event"), "Reserved event name", "Event names under runr/ are reserved for the platform.")
			}
			if known(t.Event) && strings.HasPrefix(t.Event.ValueString(), runr.QueuePrefix) {
				resp.Diagnostics.AddAttributeError(at.AtName("event"), "Queue event name",
					"Set queue = \""+strings.TrimPrefix(t.Event.ValueString(), runr.QueuePrefix)+"\" for a queue stream.")
			}
		}
	}
	if known(m.Concurrency) {
		var limits []workflowConcurrencyItem
		resp.Diagnostics.Append(m.Concurrency.ElementsAs(ctx, &limits, false)...)
		keyed := 0
		for _, l := range limits {
			if !l.Key.IsNull() {
				keyed++
			}
		}
		if keyed > 1 {
			resp.Diagnostics.AddAttributeError(path.Root("concurrency"), "Two keyed concurrency limits",
				"At most one concurrency limit can have a key.")
		}
	}
}

// ModifyPlan keeps the definition's triggers in the plan when the configuration leaves them out
// and nothing else changes (Terraform proposes null for a nested attribute left out). With
// another change they stay unknown: the declaration takes the triggers the definition has then,
// which runr_workflow_trigger may have changed in the meantime.
func (r *workflowResource) ModifyPlan(ctx context.Context, req resource.ModifyPlanRequest, resp *resource.ModifyPlanResponse) {
	if req.State.Raw.IsNull() || req.Plan.Raw.IsNull() {
		return
	}
	var cfg types.List
	resp.Diagnostics.Append(req.Config.GetAttribute(ctx, path.Root("triggers"), &cfg)...)
	if !cfg.IsNull() || resp.Diagnostics.HasError() {
		return
	}
	var plan, state workflowModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.Triggers = state.Triggers
	if plan.equal(&state) {
		resp.Diagnostics.Append(resp.Plan.SetAttribute(ctx, path.Root("triggers"), state.Triggers)...)
	}
}

func (m *workflowModel) equal(o *workflowModel) bool {
	return m.ID.Equal(o.ID) && m.ProjectID.Equal(o.ProjectID) && m.Environment.Equal(o.Environment) && m.Name.Equal(o.Name) &&
		m.Description.Equal(o.Description) && m.Triggers.Equal(o.Triggers) && m.Retries.Equal(o.Retries) &&
		m.Timeout.Equal(o.Timeout) && m.Concurrency.Equal(o.Concurrency) && m.Idempotency.Equal(o.Idempotency) &&
		m.CancelOn.Equal(o.CancelOn) && m.Active.Equal(o.Active)
}

// workflowContext addresses the workflows of the model's environment.
func (m *workflowModel) context(ctx context.Context) context.Context {
	return runr.WithWorkflowEnvironment(ctx, m.Environment.ValueString())
}

func (m *workflowModel) id() string {
	if known(m.Environment) {
		return m.ProjectID.ValueString() + "/" + m.Environment.ValueString() + "/" + m.Name.ValueString()
	}
	return m.ProjectID.ValueString() + "/" + m.Name.ValueString()
}

// definition is the definition of m to declare; current is the workflow as the API has it (nil
// for a new one): its triggers stay when m leaves them out, its retries when m does.
func (m *workflowModel) definition(ctx context.Context, current *runr.Workflow, diags *diag.Diagnostics) map[string]any {
	def := map[string]any{"name": m.Name.ValueString()}
	if known(m.Description) {
		def["description"] = m.Description.ValueString()
	}
	switch {
	case known(m.Triggers):
		var items []workflowTriggerItem
		diags.Append(m.Triggers.ElementsAs(ctx, &items, false)...)
		triggers := make([]map[string]any, 0, len(items))
		for _, t := range items {
			tr := map[string]any{}
			switch {
			case known(t.Cron):
				tr["cron"] = t.Cron.ValueString()
				if known(t.Timezone) {
					tr["timezone"] = t.Timezone.ValueString()
				}
			case known(t.Queue):
				tr["queue"] = t.Queue.ValueString()
			default:
				tr["event"] = t.Event.ValueString()
			}
			if known(t.Condition) {
				tr["if"] = t.Condition.ValueString()
			}
			triggers = append(triggers, tr)
		}
		def["triggers"] = triggers
	case current != nil:
		def["triggers"] = current.Definition()["triggers"]
	}
	switch {
	case known(m.Retries):
		def["retries"] = m.Retries.ValueInt64()
	case current != nil:
		def["retries"] = current.Retries
	}
	if known(m.Timeout) {
		def["timeout"] = m.Timeout.ValueString()
	}
	if known(m.Concurrency) {
		var items []workflowConcurrencyItem
		diags.Append(m.Concurrency.ElementsAs(ctx, &items, false)...)
		limits := make([]map[string]any, 0, len(items))
		for _, l := range items {
			lm := map[string]any{"limit": l.Limit.ValueInt64()}
			if known(l.Key) {
				lm["key"] = l.Key.ValueString()
			}
			limits = append(limits, lm)
		}
		def["concurrency"] = limits
	}
	if known(m.Idempotency) {
		def["idempotency"] = m.Idempotency.ValueString()
	}
	if known(m.CancelOn) {
		var items []workflowCancelOnItem
		diags.Append(m.CancelOn.ElementsAs(ctx, &items, false)...)
		cancel := make([]map[string]any, 0, len(items))
		for _, c := range items {
			cm := map[string]any{"event": c.Event.ValueString()}
			if known(c.Expression) {
				cm["if"] = c.Expression.ValueString()
			}
			cancel = append(cancel, cm)
		}
		def["cancel_on"] = cancel
	}
	return def
}

// spelling keeps the configuration's spelling of a value the API stores trimmed.
func spelling(prior types.String, server string) types.String {
	if known(prior) && strings.TrimSpace(prior.ValueString()) == server {
		return prior
	}
	return strOrNull(server)
}

// workflowToState copies the API's definition into m; values the API stores trimmed keep the
// spelling of m (the plan, or the prior state).
func workflowToState(ctx context.Context, m *workflowModel, w *runr.Workflow, diags *diag.Diagnostics) {
	m.ID = types.StringValue(m.id())
	m.Description = spelling(m.Description, w.Description)
	m.Retries = types.Int64Value(int64(w.Retries))
	m.Timeout = spelling(m.Timeout, w.Timeout)
	m.Idempotency = spelling(m.Idempotency, w.Idempotency)
	m.Active = types.BoolValue(w.IsActive)

	var priorTriggers []workflowTriggerItem
	if known(m.Triggers) {
		diags.Append(m.Triggers.ElementsAs(ctx, &priorTriggers, false)...)
	}
	triggers := make([]attr.Value, 0, len(w.Triggers))
	for i, t := range w.Triggers {
		var p workflowTriggerItem
		if i < len(priorTriggers) {
			p = priorTriggers[i]
		}
		item := map[string]attr.Value{"event": types.StringNull(), "condition": types.StringNull(), "cron": types.StringNull(),
			"timezone": types.StringNull(), "queue": types.StringNull()}
		switch t.Kind() {
		case "cron":
			item["cron"] = spelling(p.Cron, t.Cron)
			item["timezone"] = spelling(p.Timezone, t.Timezone)
		case "queue":
			item["queue"] = types.StringValue(t.Stream())
			item["condition"] = spelling(p.Condition, t.If)
		default:
			item["event"] = types.StringValue(t.Event)
			item["condition"] = spelling(p.Condition, t.If)
		}
		triggers = append(triggers, types.ObjectValueMust(workflowTriggerType.AttrTypes, item))
	}
	m.Triggers = workflowList(workflowTriggerType, triggers, known(m.Triggers), diags)

	var priorLimits []workflowConcurrencyItem
	if known(m.Concurrency) {
		diags.Append(m.Concurrency.ElementsAs(ctx, &priorLimits, false)...)
	}
	limits := make([]attr.Value, 0, len(w.Concurrency))
	for i, l := range w.Concurrency {
		var p workflowConcurrencyItem
		if i < len(priorLimits) {
			p = priorLimits[i]
		}
		limits = append(limits, types.ObjectValueMust(workflowConcurrencyType.AttrTypes, map[string]attr.Value{
			"limit": types.Int64Value(int64(l.Limit)), "key": spelling(p.Key, l.Key)}))
	}
	m.Concurrency = workflowList(workflowConcurrencyType, limits, known(m.Concurrency), diags)

	var priorCancel []workflowCancelOnItem
	if known(m.CancelOn) {
		diags.Append(m.CancelOn.ElementsAs(ctx, &priorCancel, false)...)
	}
	cancel := make([]attr.Value, 0, len(w.CancelOn))
	for i, c := range w.CancelOn {
		var p workflowCancelOnItem
		if i < len(priorCancel) {
			p = priorCancel[i]
		}
		cancel = append(cancel, types.ObjectValueMust(workflowCancelOnType.AttrTypes, map[string]attr.Value{
			"event": types.StringValue(c.Event), "expression": spelling(p.Expression, c.Condition())}))
	}
	m.CancelOn = workflowList(workflowCancelOnType, cancel, known(m.CancelOn), diags)
}

// workflowList is the list of items; empty it is null unless the prior value was a (empty) list.
func workflowList(t types.ObjectType, items []attr.Value, wasList bool, diags *diag.Diagnostics) types.List {
	if len(items) == 0 && !wasList {
		return types.ListNull(t)
	}
	l, d := types.ListValue(t, items)
	diags.Append(d...)
	return l
}

// workflowEnvironmentGone reports the API's 422 for an environment that does not exist (any more).
func workflowEnvironmentGone(err error) bool {
	var ae *runr.APIError
	return errors.As(err, &ae) && ae.StatusCode == 422 && ae.Code == "environment_not_found"
}

// declare declares the definition of m (remove: archives it) on its own
// (PUT / DELETE /projects/{project}/workflows/{workflow}): the project's other definitions are
// not sent, so they stay as they are — what the function's SDK reported stays source sdk. Changes
// are serialised per project (with runr_workflow_trigger): the checks below read the set first.
// An API before 0.6.0 has no such endpoints: the set is declared with the definition of m added,
// replaced or left out (declareSet).
func (r *workflowResource) declare(ctx context.Context, m *workflowModel, create, remove bool, diags *diag.Diagnostics) *runr.Workflow {
	projectID, name := m.ProjectID.ValueString(), m.Name.ValueString()
	ctx = m.context(ctx)
	unlock := r.client.lock("workflows/" + projectID)
	defer unlock()
	list, _, err := r.client.API.ListWorkflows(ctx, projectID)
	if remove && (runr.IsNotFound(err) || workflowEnvironmentGone(err)) {
		return nil // the project or the environment is gone, and its workflows with it
	}
	if err != nil {
		apiError(diags, "Read workflows failed", err)
		return nil
	}
	var current *runr.Workflow
	for i := range list {
		if list[i].Name == name && !list[i].Archived {
			current = &list[i]
		}
	}
	switch {
	case remove && current == nil:
		return nil // archived already
	case current != nil && current.Source == "sdk":
		if remove {
			diags.AddWarning("Workflow left to the code",
				"The function's SDK now reports workflow "+name+": it belongs to the code, so it was not archived.")
			return nil
		}
		diags.AddError("Workflow defined by the code",
			fmt.Sprintf("The function's SDK reports workflow %q (source sdk): its definition comes from the project's code. "+
				"Change it there, or declare it only through the API.", name))
		return nil
	case create && current != nil:
		diags.AddError("Workflow already declared",
			fmt.Sprintf("Workflow %q is already declared (source %s); import it with `terraform import <address> %s`.",
				name, current.Source, m.id()))
		return nil
	}
	var def map[string]any
	if !remove {
		def = m.definition(ctx, current, diags)
		if diags.HasError() {
			return nil
		}
	}

	var w *runr.Workflow
	if remove {
		_, err = r.client.API.ArchiveWorkflow(ctx, projectID, name)
		if wfNotFound(err) {
			return nil // gone already
		}
	} else {
		w, err = r.client.API.DeclareWorkflow(ctx, projectID, name, def)
	}
	if errors.Is(err, runr.ErrUnsupported) {
		w, err = r.declareSet(ctx, projectID, name, list, def, remove)
	}
	if err != nil {
		apiError(diags, "Declare workflow failed", err)
		return nil
	}
	if remove {
		return nil
	}
	if w == nil {
		diags.AddError("Declare workflows failed", "The API's answer does not list workflow "+name+".")
		return nil
	}
	if known(m.Active) && m.Active.ValueBool() != w.IsActive {
		if w, err = r.client.API.SetWorkflowActive(ctx, projectID, name, m.Active.ValueBool()); err != nil {
			apiError(diags, "Pause or resume workflow failed", err)
			return nil
		}
	}
	return w
}

// declareSet declares the set of definitions (an API before 0.6.0): every live one as the API
// has it, the definition def of workflow name added or replaced, or left out (remove: archived).
// Returns the declared workflow (nil when removed or not in the answer).
func (r *workflowResource) declareSet(ctx context.Context, projectID, name string, list []runr.Workflow, def map[string]any, remove bool) (*runr.Workflow, error) {
	defs := make([]map[string]any, 0, len(list)+1)
	for i := range list {
		if !list[i].Archived && list[i].Name != name {
			defs = append(defs, list[i].Definition())
		}
	}
	if !remove {
		defs = append(defs, def)
	}
	res, err := r.client.API.DeclareWorkflows(ctx, projectID, defs)
	if err != nil || remove {
		return nil, err
	}
	for i := range res.Data {
		if res.Data[i].Name == name && !res.Data[i].Archived {
			return &res.Data[i], nil
		}
	}
	return nil, nil
}

func (r *workflowResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan workflowModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	w := r.declare(ctx, &plan, true, false, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	workflowToState(ctx, &plan, w, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *workflowResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state workflowModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	w, err := r.client.API.GetWorkflow(state.context(ctx), state.ProjectID.ValueString(), state.Name.ValueString())
	if runr.IsNotFound(err) || wfNotFound(err) || workflowEnvironmentGone(err) {
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
	workflowToState(ctx, &state, w, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &state)...)
}

func (r *workflowResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var plan workflowModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	w := r.declare(ctx, &plan, false, false, &resp.Diagnostics)
	if resp.Diagnostics.HasError() {
		return
	}
	workflowToState(ctx, &plan, w, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &plan)...)
}

func (r *workflowResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	var state workflowModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	r.declare(ctx, &state, false, true, &resp.Diagnostics)
}

// ImportState accepts "<project id or name>/<workflow>" and
// "<project id or name>/<environment>/<workflow>".
func (r *workflowResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	if notConfigured(r.client, &resp.Diagnostics) {
		return
	}
	parts := strings.Split(req.ID, "/")
	for _, p := range parts {
		if p == "" {
			parts = nil
		}
	}
	if len(parts) != 2 && len(parts) != 3 {
		resp.Diagnostics.AddError("Invalid import id", "Use <project id or name>/<workflow> or <project id or name>/<environment>/<workflow>.")
		return
	}
	p, err := r.client.API.FindProject(ctx, parts[0])
	if err != nil {
		apiError(&resp.Diagnostics, "Import workflow failed", err)
		return
	}
	m := workflowModel{ProjectID: types.StringValue(p.ID), Environment: types.StringNull(), Name: types.StringValue(parts[len(parts)-1]),
		Description: types.StringNull(), Timeout: types.StringNull(), Idempotency: types.StringNull(),
		Triggers: types.ListNull(workflowTriggerType), Concurrency: types.ListNull(workflowConcurrencyType),
		CancelOn: types.ListNull(workflowCancelOnType)}
	if len(parts) == 3 {
		m.Environment = types.StringValue(parts[1])
	}
	w, err := r.client.API.GetWorkflow(m.context(ctx), p.ID, m.Name.ValueString())
	if err != nil {
		apiError(&resp.Diagnostics, "Import workflow failed", err)
		return
	}
	if w.Archived {
		resp.Diagnostics.AddError("Import workflow failed", "Workflow "+w.Name+" is archived.")
		return
	}
	if w.Source == "sdk" {
		resp.Diagnostics.AddError("Import workflow failed",
			"Workflow "+w.Name+" is defined by the project's code (source sdk); Terraform manages workflows declared through the API.")
		return
	}
	workflowToState(ctx, &m, w, &resp.Diagnostics)
	resp.Diagnostics.Append(resp.State.Set(ctx, &m)...)
}
