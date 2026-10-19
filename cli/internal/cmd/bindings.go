package cmd

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerCommand(newBindingsCmd) }

// bindingTypeAliases maps the names users type to the API's binding types.
var bindingTypeAliases = map[string]string{
	"kv": runr.BindingKV, "redis": runr.BindingKV,
	"object": runr.BindingObject, "s3": runr.BindingObject, "objects": runr.BindingObject,
	"postgres": runr.BindingPostgres, "postgresql": runr.BindingPostgres, "pg": runr.BindingPostgres,
	"external": runr.BindingExternal,
}

func bindingType(s string) (string, error) {
	if t, ok := bindingTypeAliases[strings.ToLower(strings.TrimSpace(s))]; ok {
		return t, nil
	}
	return "", usageErrorf("unknown binding type %q (kv, s3/object, postgres or external)", s)
}

func newBindingsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "bindings",
		Aliases: []string{"binding"},
		Short:   "Manage data bindings: KV, object storage, Postgres and external credentials",
		Long: "A binding is a managed data service of the organization — a KV store on Redis, an object\n" +
			"storage bucket, a Postgres database — or external credentials. Attached to a project, its\n" +
			"connection details reach the functions as environment variables (with an optional prefix);\n" +
			"attaching, detaching and rotating roll the running service, nothing needs a redeploy.",
	}
	ls := newBindingsLsCmd(app)
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	cmd.AddCommand(
		newBindingsTypesCmd(app), newBindingsQuotaCmd(app), newBindingsGetCmd(app), newBindingsCreateCmd(app),
		newBindingsUpdateCmd(app), newBindingsAttachCmd(app), newBindingsDetachCmd(app),
		newBindingsAttachmentsCmd(app), newBindingsRotateCmd(app), newBindingsRmCmd(app),
		newBindingsRestoreCmd(app), newBindingsCredentialsCmd(app),
	)
	return cmd
}

func newBindingsLsCmd(app *App) *cobra.Command {
	var typ, region string
	var deleted bool
	cmd := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the organization's bindings and the projects they are attached to",
		Example: "  runr bindings ls\n" +
			"  runr bindings ls --type postgres --region eu-1\n" +
			"  runr bindings ls --deleted        # deleted bindings that can still be restored",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			f := runr.BindingFilter{}
			if typ != "" {
				if f.Type, err = bindingType(typ); err != nil {
					return err
				}
			}
			if region != "" {
				r, err := c.FindRegion(ctx, region)
				if err != nil {
					return err
				}
				f.RegionID = r.ID
			}
			all, err := c.ListBindings(ctx, f)
			if err != nil {
				return err
			}
			list := []runr.Binding{}
			for _, b := range all {
				if b.Deleted() == deleted {
					list = append(list, b)
				}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				if deleted {
					t.Header("NAME", "TYPE", "REGION", "STATUS", "PURGE AT")
					for _, b := range list {
						t.Row(b.Name, bindingTypeLabel(&b), output.Dash(b.RegionName()), b.Status, output.Dash(b.PurgeAt))
					}
					if len(list) > 0 {
						t.Note("Restore one with `runr bindings restore NAME` before its purge date.")
					}
					return
				}
				t.Header("NAME", "TYPE", "REGION", "STATUS", "PROJECTS", "SETTINGS")
				for _, b := range list {
					projects := make([]string, 0, len(b.Attachments))
					for _, a := range b.Attachments {
						projects = append(projects, a.ProjectName())
					}
					t.Row(b.Name, bindingTypeLabel(&b), output.Dash(b.RegionName()), b.Status, joinOrDash(projects),
						output.Dash(b.Settings.String()))
				}
			})
		},
	}
	cmd.Flags().StringVar(&typ, "type", "", "only bindings of this type: kv, s3 (object), postgres or external")
	cmd.Flags().StringVar(&region, "region", "", "only bindings of this region (and external ones)")
	cmd.Flags().BoolVar(&deleted, "deleted", false, "list the deleted bindings that can still be restored")
	return cmd
}

func bindingTypeLabel(b *runr.Binding) string {
	if b.Type == runr.BindingPostgres && b.Plan == runr.BindingPlanDedicated {
		return "postgres (dedicated)"
	}
	return b.Type
}

func newBindingsTypesCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "types",
		Short: "List the binding types, their variables, plans, defaults and limits",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			info, err := c.GetBindingTypes(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(info, func(t *output.TableWriter) {
				t.Header("TYPE", "PLANS", "VARIABLES", "DEFAULTS", "MAX")
				names := make([]string, 0, len(info.Types))
				for n := range info.Types {
					names = append(names, n)
				}
				sort.Slice(names, func(i, j int) bool { return bindingTypeOrder(names[i]) < bindingTypeOrder(names[j]) })
				for _, n := range names {
					ti := info.Types[n]
					vars := joinOrDash(ti.EnvKeys)
					if n == runr.BindingExternal {
						vars = "(the keys of its values)"
					}
					t.Row(n, joinOrDash(ti.Plans), vars, output.Dash(ti.Defaults.String()), output.Dash(ti.Max.String()))
				}
				if info.RetentionDays > 0 {
					t.Note("A deleted binding's data is kept %d days (runr bindings restore).", info.RetentionDays)
				}
			})
		},
	}
}

func bindingTypeOrder(t string) int {
	for i, x := range runr.BindingTypes {
		if x == t {
			return i
		}
	}
	return len(runr.BindingTypes)
}

func newBindingsQuotaCmd(app *App) *cobra.Command {
	var tenant string
	cmd := &cobra.Command{
		Use:   "quota",
		Short: "Show the organization's binding limits and what is claimed",
		Long: "Show the organization's binding limits and what its bindings claim (0 is unlimited).\n" +
			"Platform administrators of an installation with several organizations see another\n" +
			"organization's limits with --tenant (its overrides next to the defaults) and change them\n" +
			"with `quota set`.",
		Example: "  runr bindings quota\n" +
			"  runr bindings quota --tenant 01J…\n" +
			"  runr bindings quota set --tenant 01J… --max-bindings 50 --object-gb 200 --unset kv-mb",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if tenant != "" {
				q, err := c.TenantBindingQuota(cmd.Context(), tenant)
				if err != nil {
					return tenantBindingQuotaErr(err)
				}
				return printTenantBindingQuota(app, q)
			}
			q, err := c.GetBindingQuota(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(q, func(t *output.TableWriter) {
				t.Header("LIMIT", "USED", "MAX")
				keys := make([]string, 0, len(q.Limits))
				for k := range q.Limits {
					keys = append(keys, k)
				}
				sort.Strings(keys)
				for _, k := range keys {
					max := runr.BindingValueString(q.Limits[k])
					if max == "0" {
						max = "unlimited"
					}
					used := "-"
					if v, ok := q.Used[k]; ok {
						used = runr.BindingValueString(v)
					}
					t.Row(k, used, max)
				}
			})
		},
	}
	cmd.Flags().StringVar(&tenant, "tenant", "", "another organization's limits (tenant id; platform administrators)")
	cmd.AddCommand(newBindingsQuotaSetCmd(app))
	return cmd
}

// tenantBindingQuotaErr explains a 404 of /tenants/{tenant}/binding-quotas.
func tenantBindingQuotaErr(err error) error {
	if runr.IsNotFound(err) {
		return fmt.Errorf("%w (no such organization, or this installation is single-tenant)", err)
	}
	return err
}

func bindingQuotaLimit(v int64) string {
	if v == 0 {
		return "unlimited"
	}
	return strconv.FormatInt(v, 10)
}

func printTenantBindingQuota(app *App, q *runr.TenantBindingQuota) error {
	return app.Printer().Print(q, func(t *output.TableWriter) {
		t.KV("Organization", q.TenantID)
		t.Header("LIMIT", "USED", "LIMIT THAT APPLIES", "OVERRIDE", "DEFAULT")
		for _, k := range runr.BindingQuotaKeys {
			override := "-"
			if v := q.Overrides[k]; v != nil {
				override = bindingQuotaLimit(*v)
			}
			t.Row(k, strconv.FormatInt(q.Used[k], 10), bindingQuotaLimit(q.Limits[k]), override, bindingQuotaLimit(q.Defaults[k]))
		}
	})
}

func newBindingsQuotaSetCmd(app *App) *cobra.Command {
	var tenant string
	var unset []string
	values := map[string]*int64{}
	flagOf := func(key string) string { return strings.ReplaceAll(key, "_", "-") }
	cmd := &cobra.Command{
		Use:   "set --tenant ID",
		Short: "Change an organization's binding limits (platform administrators)",
		Long: "Change the binding limits of an organization: --max-bindings, --kv-mb (MiB of KV memory),\n" +
			"--object-gb, --postgres-gb (shared Postgres) and --dedicated-postgres (clusters); 0 is\n" +
			"unlimited. --unset NAME (max-bindings, kv-mb, …) puts one back to the installation's\n" +
			"default. Limits left out keep their value.",
		Example: "  runr bindings quota set --tenant 01J… --max-bindings 50 --object-gb 200\n" +
			"  runr bindings quota set --tenant 01J… --unset kv-mb --unset dedicated-postgres",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if tenant == "" {
				return usageErrorf("--tenant names the organization (tenant id)")
			}
			change := map[string]*int64{}
			for _, k := range runr.BindingQuotaKeys {
				if cmd.Flags().Changed(flagOf(k)) {
					if *values[k] < 0 {
						return usageErrorf("--%s must be 0 (unlimited) or more", flagOf(k))
					}
					v := *values[k]
					change[k] = &v
				}
			}
			for _, u := range unset {
				key := strings.ReplaceAll(strings.ToLower(strings.TrimPrefix(u, "--")), "-", "_")
				known := false
				for _, k := range runr.BindingQuotaKeys {
					known = known || k == key
				}
				if !known {
					return usageErrorf("--unset takes max-bindings, kv-mb, object-gb, postgres-gb or dedicated-postgres, not %q", u)
				}
				if _, set := change[key]; set {
					return usageErrorf("--unset %s and --%s cannot be combined", u, flagOf(key))
				}
				change[key] = nil
			}
			if len(change) == 0 {
				return usageErrorf("give at least one limit to change")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			q, err := c.SetTenantBindingQuota(cmd.Context(), tenant, change)
			if err != nil {
				return tenantBindingQuotaErr(err)
			}
			app.Progress("Updated the binding limits of %s.", q.TenantID)
			return printTenantBindingQuota(app, q)
		},
	}
	cmd.Flags().StringVar(&tenant, "tenant", "", "organization (tenant id)")
	for _, k := range runr.BindingQuotaKeys {
		values[k] = new(int64)
	}
	cmd.Flags().Int64Var(values["max_bindings"], "max-bindings", 0, "bindings the organization may have")
	cmd.Flags().Int64Var(values["kv_mb"], "kv-mb", 0, "MiB of KV memory")
	cmd.Flags().Int64Var(values["object_gb"], "object-gb", 0, "GiB of object storage")
	cmd.Flags().Int64Var(values["postgres_gb"], "postgres-gb", 0, "GiB of shared Postgres")
	cmd.Flags().Int64Var(values["dedicated_postgres"], "dedicated-postgres", 0, "dedicated Postgres clusters")
	cmd.Flags().StringArrayVar(&unset, "unset", nil, "put a limit back to the default (repeatable)")
	return cmd
}

func newBindingsGetCmd(app *App) *cobra.Command {
	var usage bool
	var days int
	cmd := &cobra.Command{
		Use:     "get BINDING",
		Aliases: []string{"show", "status"},
		Short:   "Show a binding, its live health and the projects it is attached to",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			b, err := c.FindBinding(ctx, args[0])
			if err != nil {
				return err
			}
			cur, health, err := c.BindingStatus(ctx, b.ID)
			if err != nil {
				return err
			}
			// The status answer carries no attachments; keep those of the list.
			cur.Attachments = b.Attachments
			out := map[string]any{"binding": cur, "health": health}
			var records []runr.BindingUsageRecord
			if usage {
				if records, err = c.BindingUsage(ctx, b.ID, days); err != nil {
					return err
				}
				out["usage"] = records
			}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				bindingDetail(t, cur)
				if health != nil && health.State != "" {
					h := health.State
					if health.Message != "" {
						h += " (" + health.Message + ")"
					}
					t.KV("Health", h)
					if d := bindingHealthDetails(health.Details); d != "" {
						t.KV("Health details", d)
					}
				}
				if usage {
					t.Header("DAY", "STORAGE GB-H", "INSTANCE H", "PEAK")
					for _, r := range records {
						t.Row(r.RecordDate, strconv.FormatFloat(r.StorageGBHours, 'f', 2, 64),
							strconv.FormatFloat(r.InstanceHours, 'f', 2, 64), bindingBytes(r.PeakBytes))
					}
				}
			})
		},
	}
	cmd.Flags().BoolVar(&usage, "usage", false, "also show the daily usage records")
	cmd.Flags().IntVar(&days, "days", 30, "days of usage records (1-366)")
	return cmd
}

func bindingDetail(t *output.TableWriter, b *runr.Binding) {
	t.KV("Name", b.Name)
	t.KV("ID", b.ID)
	t.KV("Type", bindingTypeLabel(b))
	if b.RegionName() != "" {
		t.KV("Region", b.RegionName())
	}
	status := b.Status
	if b.StatusMessage != "" {
		status += " (" + b.StatusMessage + ")"
	}
	t.KV("Status", status)
	if s := b.Settings.String(); s != "" {
		t.KV("Settings", s)
	}
	if d := b.Details.String(); d != "" {
		t.KV("Details", d)
	}
	t.KV("Variables", joinOrDash(b.EnvKeys))
	if b.UsageMeasuredAt != "" {
		t.KV("Usage", bindingBytes(b.UsageBytes)+" (measured "+b.UsageMeasuredAt+")")
	}
	if b.RotatedAt != "" {
		t.KV("Rotated", b.RotatedAt)
	}
	if b.PurgeAt != "" {
		t.KV("Purged at", b.PurgeAt+" (runr bindings restore "+b.Name+" until then)")
	}
	if len(b.Attachments) > 0 {
		names := make([]string, 0, len(b.Attachments))
		for _, a := range b.Attachments {
			n := a.ProjectName()
			if a.EnvPrefix != "" {
				n += " (" + a.EnvPrefix + ")"
			}
			names = append(names, n)
		}
		t.KV("Projects", strings.Join(names, ", "))
	}
	t.KV("Created", output.Dash(b.CreatedAt))
}

func bindingHealthDetails(raw json.RawMessage) string {
	if len(raw) == 0 || raw[0] != '{' {
		return ""
	}
	var m runr.BindingValues
	if json.Unmarshal(raw, &m) != nil {
		return ""
	}
	return m.String()
}

func bindingBytes(n int64) string {
	switch {
	case n >= 1<<30:
		return strconv.FormatFloat(float64(n)/(1<<30), 'f', 1, 64) + " GiB"
	case n >= 1<<20:
		return strconv.FormatFloat(float64(n)/(1<<20), 'f', 1, 64) + " MiB"
	case n >= 1<<10:
		return strconv.FormatFloat(float64(n)/(1<<10), 'f', 1, 64) + " KiB"
	}
	return strconv.FormatInt(n, 10) + " B"
}

// bindingSettingsFlags are the flags that build a binding's settings and values.
type bindingSettingsFlags struct {
	set      []string
	config   string
	values   []string
	fromFile string
}

func (f *bindingSettingsFlags) register(cmd *cobra.Command) {
	cmd.Flags().StringArrayVar(&f.set, "set", nil, "setting KEY=VALUE, e.g. memory_mb=128, storage_gb=2, statement_timeout=15s (repeatable)")
	cmd.Flags().StringVar(&f.config, "config", "", "settings as a JSON object, @file.json or - (stdin); --set wins")
	cmd.Flags().StringArrayVar(&f.values, "value", nil, "external binding: variable KEY=VALUE (repeatable)")
	cmd.Flags().StringVar(&f.fromFile, "from-file", "", "external binding: read the variables from a dotenv file")
}

// settings returns the settings of --config and --set (nil when none were given).
func (f *bindingSettingsFlags) settings(app *App) (map[string]any, error) {
	out := map[string]any{}
	raw, err := readJSONArg(app, "--config", f.config)
	if err != nil {
		return nil, err
	}
	if raw != nil {
		if err := json.Unmarshal(raw, &out); err != nil {
			return nil, usageErrorf("--config must be a JSON object of settings: %v", err)
		}
	}
	kv, err := parseKeyValues("--set", f.set)
	if err != nil {
		return nil, err
	}
	for k, v := range kv {
		out[k] = bindingSettingValue(v)
	}
	if len(out) == 0 {
		return nil, nil
	}
	return out, nil
}

// valuesOf returns the variables of --from-file and --value (nil when none were given).
func (f *bindingSettingsFlags) valuesOf() (map[string]string, error) {
	out := map[string]string{}
	if f.fromFile != "" {
		vars, err := readEnvFile(f.fromFile)
		if err != nil {
			return nil, err
		}
		for k, v := range vars {
			out[k] = v
		}
	}
	kv, err := parseKeyValues("--value", f.values)
	if err != nil {
		return nil, err
	}
	for k, v := range kv {
		out[k] = v
	}
	if len(out) == 0 {
		return nil, nil
	}
	return out, nil
}

// bindingSettingValue types a --set value: integers and booleans as JSON numbers and booleans.
func bindingSettingValue(v string) any {
	if n, err := strconv.Atoi(v); err == nil {
		return n
	}
	if b, err := strconv.ParseBool(v); err == nil && (v == "true" || v == "false") {
		return b
	}
	return v
}

func newBindingsCreateCmd(app *App) *cobra.Command {
	var typ, plan, region, envPrefix string
	var attach, wait bool
	var timeout time.Duration
	var sf bindingSettingsFlags
	cmd := &cobra.Command{
		Use:   "create NAME --type kv|s3|postgres|external",
		Short: "Create a binding (and optionally attach it to the project)",
		Long: "Create a binding of the organization. kv, s3 (object) and postgres bindings live in a region\n" +
			"(--region, default the only active region); external bindings carry the variables you give\n" +
			"(--value, --from-file). With --attach the binding is created in the project's region and\n" +
			"attached to it in one step. Provisioning runs in the background; --wait waits until the\n" +
			"binding is ready.",
		Example: "  runr bindings create cache --type kv --set memory_mb=128\n" +
			"  runr bindings create orders-db --type postgres --set storage_gb=2 --set statement_timeout=15s --wait\n" +
			"  runr bindings create reports --type postgres --plan dedicated --set instances=2 --set backups=true\n" +
			"  runr bindings create files --type s3 --set quota_gb=10 --attach --env-prefix FILES_\n" +
			"  runr bindings create payments --type external --value PAYMENTS_URL=https://pay.example.com --from-file payments.env",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			t, err := bindingType(typ)
			if err != nil {
				return err
			}
			if !runr.ValidEnvPrefix(envPrefix) {
				return usageErrorf("--env-prefix %q: use upper case letters, digits and _, start with a letter and end with _ (e.g. ORDERS_)", envPrefix)
			}
			if envPrefix != "" && !attach {
				return usageErrorf("--env-prefix needs --attach (or set it later with `runr bindings attach`)")
			}
			if plan != "" && plan != runr.BindingPlanShared && plan != runr.BindingPlanDedicated {
				return usageErrorf("--plan must be shared or dedicated")
			}
			if plan == runr.BindingPlanDedicated && t != runr.BindingPostgres {
				return usageErrorf("only postgres bindings have a dedicated plan")
			}
			settings, err := sf.settings(app)
			if err != nil {
				return err
			}
			values, err := sf.valuesOf()
			if err != nil {
				return err
			}
			if t == runr.BindingExternal && len(values) == 0 {
				return usageErrorf("an external binding needs its variables: --value KEY=VALUE or --from-file FILE")
			}
			if t != runr.BindingExternal && len(values) > 0 {
				return usageErrorf("--value and --from-file are for external bindings; use --set for settings")
			}
			in := runr.CreateBindingInput{Name: args[0], Type: t, Plan: plan, Settings: settings, Values: values}

			var b *runr.Binding
			var att *runr.BindingAttachment
			var c *runr.Client
			if attach {
				var p *runr.Project
				c, p, err = app.Project(ctx)
				if err != nil {
					return err
				}
				if region != "" {
					return usageErrorf("--attach creates the binding in the project's region; drop --region")
				}
				att, err = c.AttachBinding(ctx, p.ID, runr.AttachBindingInput{Binding: &in, EnvPrefix: envPrefix})
				if err != nil {
					return err
				}
				b = att.Binding
				if b == nil {
					if b, err = c.FindBinding(ctx, in.Name); err != nil {
						return err
					}
				}
				app.Progress("Created binding %s and attached it to %s.", b.Name, p.Name)
			} else {
				if c, err = app.Client(); err != nil {
					return err
				}
				if t != runr.BindingExternal {
					r, err := c.FindRegion(ctx, region)
					if err != nil {
						return err
					}
					in.RegionID = r.ID
				} else if region != "" {
					return usageErrorf("external bindings have no region; drop --region")
				}
				if b, err = c.CreateBinding(ctx, in); err != nil {
					return err
				}
				app.Progress("Created binding %s (%s).", b.Name, b.Status)
			}
			if wait && b.Status != runr.BindingReady {
				b, err = c.WaitBinding(ctx, b, app.waitOptions(timeout, "binding "+b.Name))
				if err != nil {
					return err
				}
			}
			out := map[string]any{"binding": b}
			if att != nil {
				out["attachment"] = att
			}
			return app.Printer().Print(out, func(tw *output.TableWriter) {
				bindingDetail(tw, b)
				if att != nil {
					tw.KV("Project variables", joinOrDash(att.EnvNames))
				}
				if b.Status == runr.BindingProvisioning {
					tw.Note("Provisioning runs in the background; `runr bindings get %s` shows when it is ready.", b.Name)
				}
				if att == nil {
					tw.Note("Attach it to a project with `runr bindings attach %s -p PROJECT`.", b.Name)
				}
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&typ, "type", "", "binding type: kv, s3 (object), postgres or external")
	f.StringVar(&plan, "plan", "", "postgres: shared (default, a database on the region's shared cluster) or dedicated (a cluster of its own)")
	f.StringVar(&region, "region", "", "region name or id (default: the only active region)")
	f.BoolVar(&attach, "attach", false, "attach the new binding to the project (-p, $RUNR_PROJECT or runr.yaml), in its region")
	f.StringVar(&envPrefix, "env-prefix", "", "with --attach: prefix of the project's variables, e.g. ORDERS_")
	f.BoolVar(&wait, "wait", false, "wait until the binding is ready")
	f.DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	sf.register(cmd)
	_ = cmd.MarkFlagRequired("type")
	return cmd
}

func newBindingsUpdateCmd(app *App) *cobra.Command {
	var sf bindingSettingsFlags
	cmd := &cobra.Command{
		Use:     "update BINDING",
		Aliases: []string{"set"},
		Short:   "Change a binding's limits, or the variables of an external binding",
		Example: "  runr bindings update cache --set memory_mb=256\n" +
			"  runr bindings update payments --from-file payments.env   # replaces the values",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			settings, err := sf.settings(app)
			if err != nil {
				return err
			}
			values, err := sf.valuesOf()
			if err != nil {
				return err
			}
			if settings == nil && values == nil {
				return usageErrorf("give --set, --config, --value or --from-file")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			b, err := c.FindBinding(ctx, args[0])
			if err != nil {
				return err
			}
			if values != nil && b.Type != runr.BindingExternal {
				return usageErrorf("%s is a %s binding: only external bindings have values", b.Name, b.Type)
			}
			b, err = c.UpdateBinding(ctx, b.ID, runr.UpdateBindingInput{Settings: settings, Values: values})
			if err != nil {
				return err
			}
			app.Progress("Updated binding %s.", b.Name)
			return app.Printer().Print(b, func(t *output.TableWriter) { bindingDetail(t, b) })
		},
	}
	sf.register(cmd)
	return cmd
}

func newBindingsAttachCmd(app *App) *cobra.Command {
	var envPrefix string
	cmd := &cobra.Command{
		Use:   "attach BINDING",
		Short: "Attach a binding to the project; its variables reach the running functions",
		Long: "Attach a binding to the project (-p, $RUNR_PROJECT or runr.yaml). --env-prefix puts a prefix in\n" +
			"front of every variable (ORDERS_ → ORDERS_DATABASE_URL), so a project can use two bindings of the\n" +
			"same type. On a binding that is already attached, attach changes the prefix. The project's\n" +
			"service rolls onto the new variables; nothing needs to be redeployed.",
		Example: "  runr bindings attach orders-db\n" +
			"  runr bindings attach orders-db -p reports --env-prefix ORDERS_",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			if !runr.ValidEnvPrefix(envPrefix) {
				return usageErrorf("--env-prefix %q: use upper case letters, digits and _, start with a letter and end with _ (e.g. ORDERS_)", envPrefix)
			}
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			pb, err := c.ProjectBindings(ctx, p.ID)
			if err != nil {
				return err
			}
			var att *runr.BindingAttachment
			if cur := runr.PickAttachment(pb.Attachments, args[0]); cur != nil {
				prefixGiven := cmd.Flags().Changed("env-prefix") || cmd.Flags().Changed("prefix")
				if !prefixGiven || cur.EnvPrefix == envPrefix {
					app.Progress("%s is already attached to %s.", bindingAttachmentName(cur, args[0]), p.Name)
					att = cur
				} else {
					if att, err = c.UpdateAttachment(ctx, p.ID, cur.ID, envPrefix); err != nil {
						return err
					}
					app.Progress("Changed the prefix of %s on %s to %s.", bindingAttachmentName(att, args[0]), p.Name, output.Dash(envPrefix))
				}
			} else {
				b := runr.PickBinding(pb.Available, args[0])
				if b == nil {
					if b, err = c.FindBinding(ctx, args[0]); err != nil {
						return err
					}
				}
				if att, err = c.AttachBinding(ctx, p.ID, runr.AttachBindingInput{BindingID: b.ID, EnvPrefix: envPrefix}); err != nil {
					return err
				}
				app.Progress("Attached %s to %s; the running service rolls onto the new variables.", b.Name, p.Name)
			}
			return app.Printer().Print(att, func(t *output.TableWriter) { bindingAttachmentDetail(t, att) })
		},
	}
	cmd.Flags().StringVar(&envPrefix, "env-prefix", "", "prefix of the project's variables, e.g. ORDERS_ (upper case, ends with _)")
	cmd.Flags().StringVar(&envPrefix, "prefix", "", "alias of --env-prefix")
	_ = cmd.Flags().MarkHidden("prefix")
	return cmd
}

func bindingAttachmentName(a *runr.BindingAttachment, fallback string) string {
	if a != nil && a.Binding != nil && a.Binding.Name != "" {
		return a.Binding.Name
	}
	return fallback
}

func bindingAttachmentDetail(t *output.TableWriter, a *runr.BindingAttachment) {
	if a.Binding != nil {
		t.KV("Binding", a.Binding.Name)
		t.KV("Type", bindingTypeLabel(a.Binding))
		t.KV("Status", a.Binding.Status)
	} else {
		t.KV("Binding", a.BindingID)
	}
	t.KV("Project", a.ProjectName())
	t.KV("Prefix", output.Dash(a.EnvPrefix))
	t.KV("Variables", joinOrDash(a.EnvNames))
}

func newBindingsDetachCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "detach BINDING",
		Short: "Detach a binding from the project (the binding and its data stay)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			a, err := c.FindAttachment(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			if err := c.DetachBinding(ctx, p.ID, a.ID); err != nil {
				return err
			}
			name := bindingAttachmentName(a, args[0])
			app.Progress("Detached %s from %s; its variables leave the running service.", name, p.Name)
			return app.Printer().Print(map[string]any{"detached": true, "binding": name, "project": p.Name},
				func(*output.TableWriter) {})
		},
	}
}

func newBindingsAttachmentsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "attachments",
		Aliases: []string{"attached"},
		Short:   "List the bindings attached to the project and those that can be attached",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			pb, err := c.ProjectBindings(ctx, p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(pb, func(t *output.TableWriter) {
				t.Header("BINDING", "TYPE", "STATUS", "PREFIX", "VARIABLES")
				for i := range pb.Attachments {
					a := &pb.Attachments[i]
					typ, status := "-", "-"
					if a.Binding != nil {
						typ, status = bindingTypeLabel(a.Binding), a.Binding.Status
					}
					t.Row(bindingAttachmentName(a, a.BindingID), typ, status, output.Dash(a.EnvPrefix), joinOrDash(a.EnvNames))
				}
				if len(pb.Available) > 0 {
					names := make([]string, 0, len(pb.Available))
					for _, b := range pb.Available {
						names = append(names, b.Name)
					}
					t.Note("Can be attached: %s (runr bindings attach NAME).", strings.Join(names, ", "))
				}
				if pb.Secret != "" && len(pb.Attachments) > 0 {
					t.Note("The variables are in the Secret %s of the project's namespace.", pb.Secret)
				}
			})
		},
	}
}

func newBindingsRotateCmd(app *App) *cobra.Command {
	var yes, wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "rotate BINDING",
		Short: "Issue new credentials; every attached project rolls onto them",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			b, err := c.FindBinding(ctx, args[0])
			if err != nil {
				return err
			}
			q := "Rotate the credentials of " + b.Name
			if n := len(b.Attachments); n > 0 {
				q += " and restart " + countLabel(n, "attached project")
			}
			if err := app.confirm(q, yes); err != nil {
				return err
			}
			b, err = c.RotateBinding(ctx, b.ID)
			if err != nil {
				return err
			}
			app.Progress("Rotating the credentials of %s.", b.Name)
			if wait {
				if b, err = c.WaitBinding(ctx, b, app.waitOptions(timeout, "binding "+b.Name)); err != nil {
					return err
				}
				app.Progress("New credentials of %s are in place.", b.Name)
			}
			return app.Printer().Print(b, func(t *output.TableWriter) { bindingDetail(t, b) })
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the rotation is done")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	return cmd
}

func newBindingsRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm BINDING",
		Aliases: []string{"delete", "remove"},
		Short:   "Delete a binding: detached at once, data kept for the retention period",
		Long: "Delete a binding. It is detached from every project at once and its access is revoked; the\n" +
			"data is kept for the retention period (runr bindings types shows it) and `runr bindings restore`\n" +
			"brings it back until then. Afterwards the data and resources are purged.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			b, err := c.FindBinding(ctx, args[0])
			if err != nil {
				return err
			}
			q := "Delete binding " + b.Name
			if n := len(b.Attachments); n > 0 {
				q += " and detach it from " + countLabel(n, "project")
			}
			if err := app.confirm(q, yes); err != nil {
				return err
			}
			deleted, msg, err := c.DeleteBinding(ctx, b)
			if err != nil {
				return err
			}
			if msg != "" {
				app.Progress("%s", msg)
			}
			return app.Printer().Print(deleted, func(t *output.TableWriter) {
				t.KV("Name", deleted.Name)
				t.KV("Status", deleted.Status)
				t.KV("Purged at", output.Dash(deleted.PurgeAt))
			})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newBindingsRestoreCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "restore BINDING",
		Short: "Restore a deleted binding before its data is purged (attach it again afterwards)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			b, err := c.FindBinding(ctx, args[0])
			if err != nil {
				return err
			}
			b, err = c.RestoreBinding(ctx, b.ID)
			if err != nil {
				return err
			}
			app.Progress("Restored %s; attach it to projects again with `runr bindings attach %s`.", b.Name, b.Name)
			return app.Printer().Print(b, func(t *output.TableWriter) { bindingDetail(t, b) })
		},
	}
}

func newBindingsCredentialsCmd(app *App) *cobra.Command {
	var envPrefix string
	cmd := &cobra.Command{
		Use:     "credentials BINDING",
		Aliases: []string{"creds"},
		Short:   "Print a binding's connection values (needs bindings.view_credentials; audited)",
		Long: "Print the variables of a binding with their values — what attached projects get, without an\n" +
			"attachment's prefix (--env-prefix adds one). The values are secret: every reveal is recorded in\n" +
			"the audit log. Needs the permission bindings.view_credentials.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			b, err := c.FindBinding(ctx, args[0])
			if err != nil {
				return err
			}
			env, err := c.BindingCredentials(ctx, b.ID)
			if err != nil {
				return err
			}
			if envPrefix != "" {
				prefixed := make(map[string]string, len(env))
				for k, v := range env {
					prefixed[envPrefix+k] = v
				}
				env = prefixed
			}
			fmt.Fprintf(app.Err, "The values of %s are secret; keep them out of logs and shells' history.\n", b.Name)
			return app.Printer().Print(map[string]any{"binding": b.Name, "env": env}, func(t *output.TableWriter) {
				t.Header("NAME", "VALUE")
				keys := make([]string, 0, len(env))
				for k := range env {
					keys = append(keys, k)
				}
				sort.Strings(keys)
				for _, k := range keys {
					t.Row(k, env[k])
				}
			})
		},
	}
	cmd.Flags().StringVar(&envPrefix, "env-prefix", "", "put this prefix in front of the names, like an attachment does")
	return cmd
}
