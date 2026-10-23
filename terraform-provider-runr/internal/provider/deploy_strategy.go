package provider

import (
	"context"
	"regexp"

	"github.com/hashicorp/terraform-plugin-framework-validators/float64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/int64validator"
	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
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

// The deploy strategy of a project (runr_project.deploy_strategy) or of one of its environments
// (runr_environment.deploy_strategy): direct, blue-green or canary with the options of both
// (docs/deploy-strategies.md). PUT …/deploy-strategy merges what is sent onto the stored
// settings, so only the configured values that differ from the server's are sent.
//
// Every value is optional: left out, the server's value stays. The nullable options — a
// blue-green health_path and the canary thresholds — follow their block instead: a configured
// blue_green / canary block sets them, and one it leaves out is cleared (no health path: the
// readiness of the new revision alone; no threshold: not checked).

type deployStrategyModel struct {
	Strategy  types.String `tfsdk:"strategy"`
	BlueGreen types.Object `tfsdk:"blue_green"`
	Canary    types.Object `tfsdk:"canary"`
}

type blueGreenModel struct {
	AutoPromote         types.Bool   `tfsdk:"auto_promote"`
	HealthPath          types.String `tfsdk:"health_path"`
	HealthChecks        types.Int64  `tfsdk:"health_checks"`
	KeepPreviousMinutes types.Int64  `tfsdk:"keep_previous_minutes"`
}

type canaryModel struct {
	Steps              types.List    `tfsdk:"steps"`
	IntervalMinutes    types.Int64   `tfsdk:"interval_minutes"`
	ErrorRateThreshold types.Float64 `tfsdk:"error_rate_threshold"`
	P95ThresholdMs     types.Int64   `tfsdk:"p95_threshold_ms"`
}

var (
	blueGreenAttrTypes = map[string]attr.Type{
		"auto_promote": types.BoolType, "health_path": types.StringType, "health_checks": types.Int64Type,
		"keep_previous_minutes": types.Int64Type,
	}
	canaryAttrTypes = map[string]attr.Type{
		"steps": types.ListType{ElemType: types.Int64Type}, "interval_minutes": types.Int64Type,
		"error_rate_threshold": types.Float64Type, "p95_threshold_ms": types.Int64Type,
	}
	deployStrategyAttrTypes = map[string]attr.Type{
		"strategy":   types.StringType,
		"blue_green": types.ObjectType{AttrTypes: blueGreenAttrTypes},
		"canary":     types.ObjectType{AttrTypes: canaryAttrTypes},
	}
)

// healthPathPattern is the API's rule: a path of the function (optionally with a query), no
// scheme, host or spaces.
var healthPathPattern = regexp.MustCompile(`^/[^\s#]*$`)

// deployStrategySchema is the deploy_strategy attribute; what names the owner ("project",
// "environment") in the descriptions.
func deployStrategySchema(description string) schema.SingleNestedAttribute {
	keepStr := []planmodifier.String{stringplanmodifier.UseStateForUnknown()}
	keepInt := []planmodifier.Int64{int64planmodifier.UseStateForUnknown()}
	return schema.SingleNestedAttribute{
		Optional: true, Computed: true, MarkdownDescription: description,
		PlanModifiers: []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
		Attributes: map[string]schema.Attribute{
			"strategy": schema.StringAttribute{Optional: true, Computed: true, PlanModifiers: keepStr,
				MarkdownDescription: "`direct` (the new revision replaces the live one once it is ready), `blue_green` or `canary`. " +
					"A strategy other than `direct` needs an `http` or `workflow` trigger.",
				Validators: []validator.String{stringvalidator.OneOf(runr.StrategyDirect, runr.StrategyBlueGreen, runr.StrategyCanary)}},
			"blue_green": schema.SingleNestedAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Options of the blue-green strategy. Left out, the options stay as they are.",
				PlanModifiers:       []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				Attributes: map[string]schema.Attribute{
					"auto_promote": schema.BoolAttribute{Optional: true, Computed: true,
						PlanModifiers:       []planmodifier.Bool{boolplanmodifier.UseStateForUnknown()},
						MarkdownDescription: "Promote once the health checks passed; `false` waits for `runr rollout promote`."},
					"health_path": schema.StringAttribute{Optional: true,
						MarkdownDescription: "Path the health checks call, e.g. `/healthz`. Not set in a configured `blue_green` block: " +
							"the new revision's readiness alone decides.",
						Validators: []validator.String{stringvalidator.LengthAtMost(255),
							stringvalidator.RegexMatches(healthPathPattern, "must be a path of the function starting with /, without spaces")}},
					"health_checks": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
						MarkdownDescription: "Consecutive 2xx answers auto promotion needs (1-20, one every 10 s).",
						Validators:          []validator.Int64{int64validator.Between(1, 20)}},
					"keep_previous_minutes": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
						MarkdownDescription: "Minutes the previous revision stays warm after promotion for an instant rollback (0-1440).",
						Validators:          []validator.Int64{int64validator.Between(0, 1440)}},
				},
			},
			"canary": schema.SingleNestedAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Options of the canary strategy. Left out, the options stay as they are.",
				PlanModifiers:       []planmodifier.Object{objectplanmodifier.UseStateForUnknown()},
				Attributes: map[string]schema.Attribute{
					"steps": schema.ListAttribute{ElementType: types.Int64Type, Optional: true, Computed: true,
						PlanModifiers:       []planmodifier.List{listplanmodifier.UseStateForUnknown()},
						MarkdownDescription: "Traffic percentages of the new revision, strictly increasing (1-10 steps, 1-100); reaching 100 promotes it.",
						Validators: []validator.List{listvalidator.SizeBetween(1, 10),
							listvalidator.ValueInt64sAre(int64validator.Between(1, 100)), increasingSteps{}}},
					"interval_minutes": schema.Int64Attribute{Optional: true, Computed: true, PlanModifiers: keepInt,
						MarkdownDescription: "How long each step lasts (1-1440 minutes).",
						Validators:          []validator.Int64{int64validator.Between(1, 1440)}},
					"error_rate_threshold": schema.Float64Attribute{Optional: true,
						MarkdownDescription: "Roll back above this error rate of the new revision in percent (0-100). Not set in a " +
							"configured `canary` block: not checked.",
						Validators: []validator.Float64{float64validator.Between(0, 100)}},
					"p95_threshold_ms": schema.Int64Attribute{Optional: true,
						MarkdownDescription: "Roll back above this 95th percentile latency in milliseconds (1-600000). Not set in a " +
							"configured `canary` block: not checked.",
						Validators: []validator.Int64{int64validator.Between(1, 600000)}},
				},
			},
		},
	}
}

// increasingSteps checks that canary steps increase strictly.
type increasingSteps struct{}

func (increasingSteps) Description(context.Context) string { return "steps must increase strictly" }
func (v increasingSteps) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}

func (increasingSteps) ValidateList(ctx context.Context, req validator.ListRequest, resp *validator.ListResponse) {
	if !known(req.ConfigValue) {
		return
	}
	prev := int64(0)
	for i, e := range req.ConfigValue.Elements() {
		n, ok := e.(types.Int64)
		if !ok || !known(n) {
			return
		}
		if n.ValueInt64() <= prev {
			resp.Diagnostics.AddAttributeError(req.Path.AtListIndex(i), "canary steps must increase",
				"Each step must give the new revision more traffic than the one before, e.g. [10, 50, 100].")
			return
		}
		prev = n.ValueInt64()
	}
}

// deployStrategyInput is what the configured deploy strategy changes against the server's
// settings (cur nil: everything configured); an empty input changes nothing.
func deployStrategyInput(ctx context.Context, cfg types.Object, cur *runr.DeployStrategy, diags *diag.Diagnostics) runr.DeployStrategyInput {
	var in runr.DeployStrategyInput
	if !known(cfg) {
		return in
	}
	var have runr.DeployStrategySettings
	if cur != nil {
		have = cur.DeployStrategySettings
	}
	var m deployStrategyModel
	diags.Append(cfg.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	if known(m.Strategy) && (cur == nil || m.Strategy.ValueString() != have.Strategy) {
		in.Strategy = m.Strategy.ValueString()
	}
	if known(m.BlueGreen) {
		var bg blueGreenModel
		diags.Append(m.BlueGreen.As(ctx, &bg, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		var b runr.BlueGreenInput
		if v := boolPtr(bg.AutoPromote); v != nil && (cur == nil || *v != have.BlueGreen.AutoPromote) {
			b.AutoPromote = v
		}
		if !bg.HealthPath.IsUnknown() {
			want := strPtr(bg.HealthPath)
			if cur == nil || !equalOptStr(want, have.BlueGreen.HealthPath) {
				if want == nil {
					b.HealthPath = runr.NullOf[string]()
				} else {
					b.HealthPath = runr.NullableOf(*want)
				}
			}
		}
		if v := intPtr(bg.HealthChecks); v != nil && (cur == nil || *v != have.BlueGreen.HealthChecks) {
			b.HealthChecks = v
		}
		if v := intPtr(bg.KeepPreviousMinutes); v != nil && (cur == nil || *v != have.BlueGreen.KeepPreviousMinutes) {
			b.KeepPreviousMinutes = v
		}
		if b != (runr.BlueGreenInput{}) {
			in.BlueGreen = &b
		}
	}
	if known(m.Canary) {
		var cm canaryModel
		diags.Append(m.Canary.As(ctx, &cm, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
		var c runr.CanaryInput
		if l := intList(ctx, cm.Steps, diags); l != nil && (cur == nil || !equalIntSlices(*l, have.Canary.Steps)) {
			c.Steps = *l
		}
		if v := intPtr(cm.IntervalMinutes); v != nil && (cur == nil || *v != have.Canary.IntervalMinutes) {
			c.IntervalMinutes = v
		}
		if !cm.ErrorRateThreshold.IsUnknown() {
			var want *float64
			if !cm.ErrorRateThreshold.IsNull() {
				f := cm.ErrorRateThreshold.ValueFloat64()
				want = &f
			}
			if cur == nil || !equalOptFloat(want, have.Canary.ErrorRateThreshold) {
				if want == nil {
					c.ErrorRateThreshold = runr.NullOf[float64]()
				} else {
					c.ErrorRateThreshold = runr.NullableOf(*want)
				}
			}
		}
		if !cm.P95ThresholdMs.IsUnknown() {
			want := intPtr(cm.P95ThresholdMs)
			if cur == nil || !equalOptInt(want, have.Canary.P95ThresholdMs) {
				if want == nil {
					c.P95ThresholdMs = runr.NullOf[int]()
				} else {
					c.P95ThresholdMs = runr.NullableOf(*want)
				}
			}
		}
		if c.Steps != nil || c.IntervalMinutes != nil || c.ErrorRateThreshold != nil || c.P95ThresholdMs != nil {
			in.Canary = &c
		}
	}
	// On create the defaults need no sending: nulls of a new strategy are the defaults' nulls
	if cur == nil {
		if in.BlueGreen != nil && in.BlueGreen.HealthPath != nil && in.BlueGreen.HealthPath.Null {
			in.BlueGreen.HealthPath = nil
		}
	}
	return in
}

// deployStrategyValue is the server's deploy strategy as the attribute's value.
func deployStrategyValue(ctx context.Context, s *runr.DeployStrategy, diags *diag.Diagnostics) types.Object {
	if s == nil {
		return types.ObjectNull(deployStrategyAttrTypes)
	}
	bg, d := types.ObjectValueFrom(ctx, blueGreenAttrTypes, blueGreenModel{
		AutoPromote:         types.BoolValue(s.BlueGreen.AutoPromote),
		HealthPath:          optStr(s.BlueGreen.HealthPath),
		HealthChecks:        types.Int64Value(int64(s.BlueGreen.HealthChecks)),
		KeepPreviousMinutes: types.Int64Value(int64(s.BlueGreen.KeepPreviousMinutes)),
	})
	diags.Append(d...)
	steps := make([]int64, len(s.Canary.Steps))
	for i, n := range s.Canary.Steps {
		steps[i] = int64(n)
	}
	stepList, d := types.ListValueFrom(ctx, types.Int64Type, steps)
	diags.Append(d...)
	errRate := types.Float64Null()
	if s.Canary.ErrorRateThreshold != nil {
		errRate = types.Float64Value(*s.Canary.ErrorRateThreshold)
	}
	cn, d := types.ObjectValueFrom(ctx, canaryAttrTypes, canaryModel{
		Steps: stepList, IntervalMinutes: types.Int64Value(int64(s.Canary.IntervalMinutes)),
		ErrorRateThreshold: errRate, P95ThresholdMs: intOrNull(s.Canary.P95ThresholdMs),
	})
	diags.Append(d...)
	strategy := s.Strategy
	if strategy == "" {
		strategy = runr.StrategyDirect
	}
	out, d := types.ObjectValueFrom(ctx, deployStrategyAttrTypes, deployStrategyModel{
		Strategy: types.StringValue(strategy), BlueGreen: bg, Canary: cn,
	})
	diags.Append(d...)
	return out
}

// validateDeployStrategy reports a strategy other than direct for a trigger type that cannot use
// one, when both are known at plan time (the API refuses it with errors.strategy).
func validateDeployStrategy(ctx context.Context, cfg types.Object, triggerType string, at path.Path, diags *diag.Diagnostics) {
	if !known(cfg) || triggerType == "" {
		return
	}
	var m deployStrategyModel
	diags.Append(cfg.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	if !known(m.Strategy) || m.Strategy.ValueString() == runr.StrategyDirect {
		return
	}
	if triggerType != runr.TriggerHTTP && triggerType != runr.TriggerWorkflow {
		diags.AddAttributeError(at.AtName("strategy"), "strategy needs an http or workflow trigger",
			"A "+m.Strategy.ValueString()+" rollout moves HTTP traffic; a project whose primary trigger is "+triggerType+" deploys directly.")
	}
}

func optStr(p *string) types.String {
	if p == nil {
		return types.StringNull()
	}
	return types.StringValue(*p)
}

func equalOptStr(a, b *string) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return *a == *b
}

func equalOptInt(a, b *int) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return *a == *b
}

func equalOptFloat(a, b *float64) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return *a == *b
}

func equalIntSlices(a, b []int) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}
