package provider

import (
	"context"
	"errors"
	"fmt"

	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"
	"github.com/hashicorp/terraform-plugin-go/tftypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// projectExtras are a project's settings outside its config: the trigger list, the network,
// the deploy strategy and the global placement. A nil part is one the API does not have.
type projectExtras struct {
	triggers *runr.TriggerList
	network  *runr.Network
	strategy *runr.DeployStrategy
	global   *runr.ProjectGlobal
}

// readProjectExtras reads them; an API without one of them leaves it nil.
func readProjectExtras(ctx context.Context, api *runr.Client, p *runr.Project) (*projectExtras, error) {
	var e projectExtras
	var err error
	if e.triggers, err = api.ListTriggers(ctx, p.ID); err != nil {
		if !errors.Is(err, runr.ErrUnsupported) {
			return nil, fmt.Errorf("read the project's triggers: %w", err)
		}
		e.triggers = nil
	}
	if e.network, err = api.GetNetwork(ctx, p.ID); err != nil {
		if !runr.IsNotFound(err) {
			return nil, fmt.Errorf("read the project's network: %w", err)
		}
		e.network = nil
	}
	if e.strategy, err = api.GetDeployStrategy(ctx, p.ID); err != nil {
		if !errors.Is(err, runr.ErrUnsupported) {
			return nil, fmt.Errorf("read the project's deploy strategy: %w", err)
		}
		e.strategy = nil
	}
	if p.IsGlobal() {
		if e.global, err = api.ProjectGlobal(ctx, p.ID); err != nil {
			if !runr.IsNotFound(err) {
				return nil, fmt.Errorf("read the global project: %w", err)
			}
			e.global = nil
		}
	}
	return &e, nil
}

// applyProjectExtras changes the trigger list, network, deploy strategy and global settings to
// what the plan says (state: the prior state, nil on create; cur: the server's settings).
func (r *projectResource) applyProjectExtras(ctx context.Context, plan, state *projectModel, p *runr.Project, cur *projectExtras, diags *diag.Diagnostics) {
	api := r.client.API
	// The trigger list first: a deploy strategy needs an http or workflow trigger
	if planned := triggerModels(ctx, plan.Triggers, diags); planned != nil {
		changed := state == nil || triggerListChanged(plan.Triggers, state.Triggers)
		if changed {
			if cur.triggers == nil {
				diags.AddAttributeError(path.Root("triggers"), "Trigger list not supported", "This RunR API has no trigger list (RunR 0.6.0 or later).")
				return
			}
			unlock := r.client.lock(p.ID)
			list, err := api.ReplaceTriggers(ctx, p.ID, triggerSpecs(ctx, planned, cur.triggers, diags))
			unlock()
			if err != nil {
				apiError(diags, "Set the project's triggers failed", err)
				return
			}
			cur.triggers = list
		}
	}
	if known(plan.Network) {
		if cur.network == nil {
			diags.AddAttributeError(path.Root("network"), "Network settings not supported", "This RunR API has no project network settings.")
			return
		}
		cur.network = applyNetwork(ctx, api, p.ID, plan.Network, cur.network, diags)
		if diags.HasError() {
			return
		}
	}
	if known(plan.DeployStrategy) {
		in := deployStrategyInput(ctx, plan.DeployStrategy, cur.strategy, diags)
		if !in.Empty() {
			if cur.strategy == nil {
				diags.AddAttributeError(path.Root("deploy_strategy"), "Deploy strategies not supported", "This RunR API has no deploy strategies.")
				return
			}
			s, err := api.UpdateDeployStrategy(ctx, p.ID, in)
			if err != nil {
				apiError(diags, "Set the deploy strategy failed", err)
				return
			}
			cur.strategy = s
		}
	}
	if p.IsGlobal() && cur.global != nil {
		g, _ := globalConfigured(ctx, plan.Global, diags)
		primary := ""
		if known(plan.RegionID) {
			primary = plan.RegionID.ValueString()
		}
		if in := globalUpdate(g, primary, cur.global); !globalUpdateEmpty(in) {
			gl, err := api.UpdateProjectGlobal(ctx, p.ID, in)
			if err != nil {
				apiError(diags, "Change the global project failed", err)
				return
			}
			cur.global = gl
		}
	}
}

// setExtras writes the server's settings into the model. After an apply (applied) a trigger's
// configured config must be what RunR kept; on a read the prior state's config JSON stays while
// RunR's config holds it.
func (m *projectModel) setExtras(ctx context.Context, e *projectExtras, prior types.List, applied bool, diags *diag.Diagnostics) {
	if e == nil {
		return
	}
	var mismatch func(i int, name, want, got string)
	if applied {
		mismatch = func(i int, name, want, got string) {
			diags.AddAttributeError(path.Root("triggers").AtListIndex(i).AtName("config"), "RunR stored another trigger config",
				fmt.Sprintf("Trigger %s: RunR keeps %s for the configured %s. Set the values as RunR stores them (see the trigger "+
					"type's settings); values a type does not know are dropped.", name, got, want))
		}
	}
	m.Triggers = triggersValue(ctx, e.triggers, triggersModelOf(ctx, prior, diags), applied, mismatch, diags)
	m.Network = networkValue(ctx, e.network, diags)
	m.DeployStrategy = deployStrategyValue(ctx, e.strategy, diags)
	m.Global = globalValue(ctx, e.global, diags)
}

// filledFromState is v with every unknown value replaced by the state's value at its path; an
// unknown value the state has no value for stays unknown.
func filledFromState(v, state tftypes.Value) tftypes.Value {
	out, err := tftypes.Transform(v, func(p *tftypes.AttributePath, x tftypes.Value) (tftypes.Value, error) {
		if x.IsKnown() {
			return x, nil
		}
		found, _, err := tftypes.WalkAttributePath(state, p)
		if err != nil {
			return x, nil
		}
		if s, ok := found.(tftypes.Value); ok && s.Type().UsableAs(x.Type()) {
			return s, nil
		}
		return x, nil
	})
	if err != nil {
		return v
	}
	return out
}

// changedFromState reports whether a planned value changes the state's: it differs once its
// unknown parts are taken from the state.
func changedFromState(ctx context.Context, planned, prior attr.Value) bool {
	p, err := planned.ToTerraformValue(ctx)
	if err != nil {
		return true
	}
	s, err := prior.ToTerraformValue(ctx)
	if err != nil {
		return true
	}
	return !filledFromState(p, s).Equal(s)
}

// keepPlannedComputed keeps the planned values of computed parts an apply cannot have changed
// but another resource of the same apply can (a global project's bindings are attachments of
// their own): Terraform requires the planned values.
func (m *projectModel) keepPlannedComputed(ctx context.Context, plan *projectModel, diags *diag.Diagnostics) {
	if !known(plan.Global) || !known(m.Global) {
		return
	}
	var planned, got globalModel
	diags.Append(plan.Global.As(ctx, &planned, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	diags.Append(m.Global.As(ctx, &got, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: true})...)
	if known(planned.Bindings) && !planned.Bindings.Equal(got.Bindings) {
		got.Bindings = planned.Bindings
		v, d := types.ObjectValueFrom(ctx, globalAttrTypes, got)
		diags.Append(d...)
		m.Global = v
	}
}
