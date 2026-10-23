package provider

import (
	"context"
	"fmt"
	"time"
	_ "time/tzdata" // the zones the validator knows, whatever the host has

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
)

// Time zone of a schedule trigger (docs/triggers.md#schedules-and-time-zones): trigger.timezone
// is an IANA zone, "" the organization's time zone (which the schedule then follows);
// trigger.effective_timezone is the zone the schedule runs in.

// timezoneValidator accepts "" and the IANA zones Go knows. The API checks the exact name
// against PHP's zone list (backward-compatible names such as Europe/Kiev included).
type timezoneValidator struct{}

func (timezoneValidator) Description(context.Context) string {
	return `an IANA time zone such as "Europe/Berlin", or "" for the organization's time zone`
}

func (v timezoneValidator) MarkdownDescription(ctx context.Context) string { return v.Description(ctx) }

func (timezoneValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	zone := req.ConfigValue.ValueString()
	if zone == "" {
		return
	}
	if _, err := time.LoadLocation(zone); err != nil || zone == "Local" {
		resp.Diagnostics.AddAttributeError(req.Path, "unknown time zone",
			fmt.Sprintf("%q is not an IANA time zone (e.g. \"Europe/Berlin\", \"UTC\"); \"\" uses the organization's time zone.", zone))
	}
}

// timezonePlan plans a trigger.timezone the configuration leaves out: the schedule keeps its
// zone (the state's), and a trigger that is no schedule has none.
type timezonePlan struct{}

func (timezonePlan) Description(context.Context) string {
	return "leaving the time zone out keeps the schedule's zone; a trigger that is no schedule has none"
}

func (p timezonePlan) MarkdownDescription(ctx context.Context) string { return p.Description(ctx) }

func (timezonePlan) PlanModifyString(ctx context.Context, req planmodifier.StringRequest, resp *planmodifier.StringResponse) {
	if !req.PlanValue.IsUnknown() {
		return
	}
	var typ types.String
	if d := req.Plan.GetAttribute(ctx, path.Root("trigger").AtName("type"), &typ); d.HasError() || typ.IsUnknown() {
		return
	}
	if typ.ValueString() != "schedule" {
		resp.PlanValue = types.StringNull()
		return
	}
	if !req.State.Raw.IsNull() {
		resp.PlanValue = req.StateValue
	}
}

// effectiveTimezonePlan keeps trigger.effective_timezone as it is when the trigger's type,
// schedule and timezone stay as they are; otherwise the new zone is known after apply.
type effectiveTimezonePlan struct{}

func (effectiveTimezonePlan) Description(context.Context) string {
	return "keeps the effective time zone while the trigger's type, schedule and time zone do not change"
}

func (p effectiveTimezonePlan) MarkdownDescription(ctx context.Context) string {
	return p.Description(ctx)
}

func (effectiveTimezonePlan) PlanModifyString(ctx context.Context, req planmodifier.StringRequest, resp *planmodifier.StringResponse) {
	if req.State.Raw.IsNull() || !req.PlanValue.IsUnknown() {
		return
	}
	for _, name := range []string{"type", "schedule", "timezone"} {
		var planned, prior types.String
		at := path.Root("trigger").AtName(name)
		if d := req.Plan.GetAttribute(ctx, at, &planned); d.HasError() {
			return
		}
		if d := req.State.GetAttribute(ctx, at, &prior); d.HasError() {
			return
		}
		// unknown in the plan: kept from the state by its own plan modifier, or changing
		if planned.IsUnknown() || !planned.Equal(prior) {
			return
		}
	}
	resp.PlanValue = req.StateValue
}

// triggerTimezone is trigger.timezone as state from the API's config.schedule_timezone: the
// zone, or — following the organization — "" when the configuration (prior) says "", else null.
func triggerTimezone(api string, prior types.String) types.String {
	if api != "" {
		return types.StringValue(api)
	}
	if known(prior) && prior.ValueString() == "" {
		return types.StringValue("")
	}
	return types.StringNull()
}
