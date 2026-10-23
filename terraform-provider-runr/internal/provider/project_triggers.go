package provider

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strings"

	"github.com/hashicorp/terraform-plugin-framework-validators/listvalidator"
	"github.com/hashicorp/terraform-plugin-framework-validators/stringvalidator"
	"github.com/hashicorp/terraform-plugin-framework/attr"
	"github.com/hashicorp/terraform-plugin-framework/diag"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/listplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/schema/validator"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/hashicorp/terraform-plugin-framework/types/basetypes"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The project's trigger list (runr_project.triggers, docs/triggers.md): every trigger with its
// type, settings and write-only secrets. Set, the list is the project's whole list and replaces
// it (PUT /projects/{project}/triggers: a trigger whose name and type match an existing one keeps
// its secrets, webhook inbox URL and stored events). Left out, the list stays as it is.
//
// A trigger's config is JSON (jsonencode): each type takes other settings, and RunR stores them
// normalized — defaults filled in, empty values dropped. The state keeps the configuration's JSON
// while RunR's config holds every value it sets.

type projectTriggerModel struct {
	Name              types.String `tfsdk:"name"`
	Type              types.String `tfsdk:"type"`
	Enabled           types.Bool   `tfsdk:"enabled"`
	Config            types.String `tfsdk:"config"`
	Secrets           types.Map    `tfsdk:"secrets"`
	ID                types.String `tfsdk:"id"`
	Primary           types.Bool   `tfsdk:"primary"`
	RunsOn            types.String `tfsdk:"runs_on"`
	URL               types.String `tfsdk:"url"`
	EffectiveTimezone types.String `tfsdk:"effective_timezone"`
	SecretNames       types.List   `tfsdk:"secret_names"`
}

var projectTriggerAttrTypes = map[string]attr.Type{
	"name": types.StringType, "type": types.StringType, "enabled": types.BoolType, "config": types.StringType,
	"secrets": types.MapType{ElemType: types.StringType}, "id": types.StringType, "primary": types.BoolType,
	"runs_on": types.StringType, "url": types.StringType, "effective_timezone": types.StringType,
	"secret_names": types.ListType{ElemType: types.StringType},
}

var projectTriggerType = types.ObjectType{AttrTypes: projectTriggerAttrTypes}

var (
	triggerNamePattern = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$`)
	triggerTypePattern = regexp.MustCompile(`^[a-z][a-z0-9_]*$`)
)

func triggersSchema() schema.ListNestedAttribute {
	return schema.ListNestedAttribute{
		Optional: true, Computed: true,
		MarkdownDescription: "The project's whole trigger list, in order: the first enabled trigger is the primary one " +
			"(`trigger` reads it back). Set, the list replaces the project's list — a trigger whose `name` and `type` match one " +
			"the project has keeps its secrets, webhook inbox URL and stored events; the others are created or removed. Left " +
			"out, the list stays as it is (and `trigger` can set the primary trigger). Set either `trigger` or `triggers`. " +
			"A change reaches the running deployment with the next deploy.",
		PlanModifiers: []planmodifier.List{listplanmodifier.UseStateForUnknown()},
		Validators:    []validator.List{listvalidator.SizeBetween(1, 20)},
		NestedObject: schema.NestedAttributeObject{Attributes: map[string]schema.Attribute{
			"name": schema.StringAttribute{Optional: true, Computed: true,
				MarkdownDescription: "Name, unique in the project (lower-case letters, digits and dashes). Default: the type " +
					"(`schedule`, then `schedule-2`, …). Name triggers whose secrets or inbox URL must survive a reordering.",
				Validators: []validator.String{stringvalidator.RegexMatches(triggerNamePattern, "must be lower-case letters, digits and dashes, at most 63 characters")}},
			"type": schema.StringAttribute{Required: true,
				MarkdownDescription: "`http`, `schedule`, `queue`, `workflow`, `webhook`, `topic`, `platform_event` or an event " +
					"source: `kafka`, `nats`, `rabbitmq`, `mqtt`, `cdc` (Postgres logical replication), `email` (IMAP) or `s3`. " +
					"At most one `http` trigger; a `workflow` trigger is the project's only one.",
				Validators: []validator.String{stringvalidator.RegexMatches(triggerTypePattern, "must be a trigger type such as \"http\" or \"kafka\"")}},
			"enabled": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true),
				MarkdownDescription: "Whether the trigger runs (default `true`)."},
			"config": schema.StringAttribute{Optional: true, Computed: true,
				MarkdownDescription: "The type's settings as JSON (`jsonencode({...})`), e.g. `{cron = \"0 2 * * *\", timezone = " +
					"\"Europe/Berlin\"}` for a schedule, `{brokers = [\"kafka:9092\"], topic = \"orders\"}` for kafka, or " +
					"`{verify = \"github\"}` for a webhook; [triggers](https://runr.sh/docs/triggers/) lists every type's settings. " +
					"RunR fills in defaults: the state keeps this JSON while RunR's config holds every value it sets, and shows " +
					"RunR's config when a value differs. Left out: RunR's config of the trigger (`{}` for `http`).",
				Validators: []validator.String{jsonObjectValidator{}}},
			"secrets": schema.MapAttribute{ElementType: types.StringType, Optional: true, Sensitive: true,
				MarkdownDescription: "Write-only secrets of the type: `signing_secret` (webhook), `sasl_password` (kafka), " +
					"`password` / `token` / `nkey_seed` / `creds` (nats), `url` (rabbitmq), `password` (mqtt, email), `dsn` (cdc), " +
					"`access_key` and `secret_key` (s3). RunR lists only their names; Terraform keeps the values it set and sets a " +
					"secret again when it was removed outside Terraform. Set, the map is the trigger's whole set (a secret it " +
					"leaves out is removed); left out, the trigger's secrets stay as they are."},
			"id": schema.StringAttribute{Computed: true, MarkdownDescription: "Trigger id."},
			"primary": schema.BoolAttribute{Computed: true,
				MarkdownDescription: "Whether it is the primary trigger (the first enabled one)."},
			"runs_on": schema.StringAttribute{Computed: true,
				MarkdownDescription: "What runs it: `service` (the project's service), `gateway` (its event gateway), `cronjob` or `disabled`."},
			"url": schema.StringAttribute{Computed: true,
				MarkdownDescription: "The public inbox URL of a `webhook` trigger (send the provider's webhooks there)."},
			"effective_timezone": schema.StringAttribute{Computed: true,
				MarkdownDescription: "The time zone a `schedule` runs in: its `timezone`, else the organization's."},
			"secret_names": schema.ListAttribute{ElementType: types.StringType, Computed: true,
				MarkdownDescription: "The names of the secrets the trigger has."},
		}},
	}
}

// jsonObjectValidator accepts a JSON object.
type jsonObjectValidator struct{}

func (jsonObjectValidator) Description(context.Context) string { return "a JSON object" }
func (v jsonObjectValidator) MarkdownDescription(ctx context.Context) string {
	return v.Description(ctx)
}

func (jsonObjectValidator) ValidateString(_ context.Context, req validator.StringRequest, resp *validator.StringResponse) {
	if !known(req.ConfigValue) {
		return
	}
	var m map[string]any
	if err := json.Unmarshal([]byte(req.ConfigValue.ValueString()), &m); err != nil {
		resp.Diagnostics.AddAttributeError(req.Path, "config must be a JSON object",
			"Write the trigger's settings with jsonencode({...}): "+err.Error())
	}
}

// triggerModels reads the triggers attribute (nil when it is null or unknown).
func triggerModels(ctx context.Context, l types.List, diags *diag.Diagnostics) []projectTriggerModel {
	if !known(l) {
		return nil
	}
	var out []projectTriggerModel
	diags.Append(l.ElementsAs(ctx, &out, false)...)
	if out == nil {
		out = []projectTriggerModel{}
	}
	return out
}

// triggerSpecs is the list to send: name and config when known, secrets as configured, and ""
// for every secret the trigger has (cur) that a configured map leaves out.
func triggerSpecs(ctx context.Context, planned []projectTriggerModel, cur *runr.TriggerList, diags *diag.Diagnostics) []runr.TriggerSpec {
	specs := make([]runr.TriggerSpec, 0, len(planned))
	for i, t := range planned {
		spec := runr.TriggerSpec{Type: t.Type.ValueString()}
		if known(t.Name) {
			spec.Name = t.Name.ValueString()
		}
		if known(t.Enabled) {
			e := t.Enabled.ValueBool()
			spec.Enabled = &e
		}
		if known(t.Config) {
			if err := json.Unmarshal([]byte(t.Config.ValueString()), &spec.Config); err != nil {
				diags.AddAttributeError(path.Root("triggers").AtListIndex(i).AtName("config"), "config is no JSON object", err.Error())
			}
		}
		if known(t.Secrets) {
			secrets := map[string]string{}
			diags.Append(t.Secrets.ElementsAs(ctx, &secrets, false)...)
			if cur != nil && spec.Name != "" {
				if have := cur.Find(spec.Name); have != nil && have.Type == spec.Type {
					for _, name := range have.Secrets {
						if _, ok := secrets[name]; !ok {
							secrets[name] = ""
						}
					}
				}
			}
			if len(secrets) > 0 {
				spec.Secrets = secrets
			}
		}
		specs = append(specs, spec)
	}
	return specs
}

// triggersValue is the server's list as the attribute's value. prior are the configured (or
// previous) triggers: by index when byIndex (the answer of the list just sent), else by name and
// type. A prior config JSON stays while RunR's config holds every value of it; prior secrets stay
// while the trigger has them. mismatch reports a configured config RunR did not keep.
func triggersValue(ctx context.Context, list *runr.TriggerList, prior []projectTriggerModel, byIndex bool,
	mismatch func(i int, name, want, got string), diags *diag.Diagnostics) types.List {
	if list == nil {
		return types.ListNull(projectTriggerType)
	}
	models := make([]projectTriggerModel, 0, len(list.Triggers))
	for i, t := range list.Triggers {
		var p *projectTriggerModel
		if byIndex && i < len(prior) {
			p = &prior[i]
		} else if !byIndex {
			for j := range prior {
				if prior[j].Name.ValueString() == t.Name && prior[j].Type.ValueString() == t.Type {
					p = &prior[j]
					break
				}
			}
		}
		got := canonicalJSON(t.Config)
		cfg := types.StringValue(got)
		if p != nil && known(p.Config) {
			if jsonSubset(p.Config.ValueString(), t.Config) {
				cfg = p.Config
			} else if mismatch != nil {
				mismatch(i, t.Name, p.Config.ValueString(), got)
			}
		}
		secrets := types.MapNull(types.StringType)
		if p != nil && known(p.Secrets) {
			kept := map[string]string{}
			have := map[string]bool{}
			for _, n := range t.Secrets {
				have[n] = true
			}
			var vals map[string]string
			diags.Append(p.Secrets.ElementsAs(ctx, &vals, false)...)
			for k, v := range vals {
				// a secret removed outside Terraform drops out and is planned again
				if have[k] {
					kept[k] = v
				}
			}
			m, d := types.MapValueFrom(ctx, types.StringType, kept)
			diags.Append(d...)
			secrets = m
		}
		names := append([]string{}, t.Secrets...)
		sort.Strings(names)
		nameList, d := types.ListValueFrom(ctx, types.StringType, names)
		diags.Append(d...)
		models = append(models, projectTriggerModel{
			Name: types.StringValue(t.Name), Type: types.StringValue(t.Type), Enabled: types.BoolValue(t.Enabled),
			Config: cfg, Secrets: secrets, ID: types.StringValue(t.ID), Primary: types.BoolValue(t.Primary),
			RunsOn: strOrNull(t.RunsOn), URL: strOrNull(t.URL), EffectiveTimezone: strOrNull(t.EffectiveTimezone),
			SecretNames: nameList,
		})
	}
	l, d := types.ListValueFrom(ctx, projectTriggerType, models)
	diags.Append(d...)
	return l
}

// sameConfiguredTriggers reports whether the configured triggers (plan) set what the prior ones
// have: the same types, names, enabled flags, config JSON and secrets in the same order (values
// the configuration leaves out are unknown in the plan and match anything).
func sameConfiguredTriggers(plan, prior []projectTriggerModel) bool {
	if len(plan) != len(prior) {
		return false
	}
	for i := range plan {
		a, b := plan[i], prior[i]
		if !a.Type.Equal(b.Type) {
			return false
		}
		for _, pair := range [][2]attr.Value{{a.Name, b.Name}, {a.Enabled, b.Enabled}, {a.Config, b.Config}, {a.Secrets, b.Secrets}} {
			if !pair[0].IsUnknown() && !pair[0].Equal(pair[1]) {
				return false
			}
		}
	}
	return true
}

// triggerListChanged reports whether the planned triggers attribute changes the list: a known
// value other than the state's, or unknown values in it.
func triggerListChanged(plan, state types.List) bool {
	if plan.IsUnknown() {
		return true
	}
	if plan.IsNull() {
		return false
	}
	return !plan.Equal(state)
}

// canonicalJSON is v as compact JSON with sorted keys ("{}" for nil).
func canonicalJSON(v map[string]any) string {
	if v == nil {
		return "{}"
	}
	b, err := json.Marshal(v)
	if err != nil {
		return "{}"
	}
	return string(b)
}

// jsonSubset reports whether every value of the JSON object want is in got as RunR stores it:
// objects key by key, lists element by element, scalars equal (also as text: "5" and 5), and an
// empty value (null, "", false, 0, [], {}) where got has none.
func jsonSubset(want string, got map[string]any) bool {
	dec := json.NewDecoder(bytes.NewReader([]byte(want)))
	var w any
	if err := dec.Decode(&w); err != nil {
		return false
	}
	var g any = got
	if got == nil {
		g = map[string]any{}
	}
	return subsetValue(w, g)
}

func subsetValue(want, got any) bool {
	switch w := want.(type) {
	case map[string]any:
		gm, ok := got.(map[string]any)
		if !ok {
			return emptyJSON(w) && got == nil
		}
		for k, v := range w {
			gv, has := gm[k]
			if !has {
				if !emptyJSON(v) {
					return false
				}
				continue
			}
			if !subsetValue(v, gv) {
				return false
			}
		}
		return true
	case []any:
		gl, ok := got.([]any)
		if !ok {
			return len(w) == 0 && got == nil
		}
		var kept []any
		for _, v := range w {
			if s, isStr := v.(string); isStr && s == "" {
				continue // RunR drops empty entries of a list
			}
			kept = append(kept, v)
		}
		if len(kept) != len(gl) {
			return false
		}
		for i := range kept {
			if !subsetValue(kept[i], gl[i]) {
				return false
			}
		}
		return true
	case nil:
		return got == nil || emptyJSON(got)
	}
	if got == nil {
		return emptyJSON(want)
	}
	if fmt.Sprint(want) == fmt.Sprint(got) {
		return true
	}
	// a boolean RunR casts from a number or a text ("1", "true")
	if gb, ok := got.(bool); ok {
		switch x := want.(type) {
		case float64:
			return (x != 0) == gb
		case string:
			return (x != "" && x != "0" && strings.ToLower(x) != "false") == gb
		}
	}
	return false
}

func emptyJSON(v any) bool {
	switch x := v.(type) {
	case nil:
		return true
	case string:
		return x == ""
	case bool:
		return !x
	case float64:
		return x == 0
	case []any:
		return len(x) == 0
	case map[string]any:
		for _, e := range x {
			if !emptyJSON(e) {
				return false
			}
		}
		return true
	}
	return false
}

// triggersModelOf reads a list value as models with ObjectAsOptions that tolerate unknowns.
func triggersModelOf(ctx context.Context, l types.List, diags *diag.Diagnostics) []projectTriggerModel {
	if l.IsNull() || l.IsUnknown() {
		return nil
	}
	out := make([]projectTriggerModel, 0, len(l.Elements()))
	for _, e := range l.Elements() {
		obj, ok := e.(types.Object)
		if !ok {
			continue
		}
		var m projectTriggerModel
		diags.Append(obj.As(ctx, &m, basetypes.ObjectAsOptions{UnhandledUnknownAsEmpty: false})...)
		out = append(out, m)
	}
	return out
}
