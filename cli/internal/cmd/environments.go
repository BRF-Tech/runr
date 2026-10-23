package cmd

import (
	"context"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Environments of a project and promotion (docs/environments.md). `runr env` stays the
// command of a project's environment variables; environments are `runr environments` (envs).

func init() {
	registerCommand(newEnvironmentsCmd)
	registerCommand(newPromoteCmd)
	registerCommand(newApprovalsCmd)
}

// errNoEnvironments explains ErrUnsupported of the environment endpoints.
var errNoEnvironments = errors.New("this RunR API has no environments (an older RunR version)")

func environmentsErr(err error) error {
	if errors.Is(err, runr.ErrUnsupported) {
		return errNoEnvironments
	}
	return err
}

// resolveEnvironment reads an environment of the project; an API before environments is an
// error, so that a deploy meant for an environment never reaches the project's own workload.
func resolveEnvironment(ctx context.Context, c *runr.Client, p *runr.Project, name string) (*runr.Environment, error) {
	e, err := c.GetEnvironment(ctx, p.ID, name)
	if err != nil {
		return nil, environmentsErr(err)
	}
	return e, nil
}

func newEnvironmentsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "environments",
		Aliases: []string{"envs", "environment"},
		Short:   "Manage a project's environments (dev, staging, …) and their secrets",
		Long: "An environment runs the project's versions as a workload of its own — <slug>-<name>, its\n" +
			"default URL <slug>-<name>.<function domain> — with the project's config and its own\n" +
			"overrides (scale, resources, env vars), its own secret variables, bindings and custom\n" +
			"domains, in the project's region or another one. \"production\" is the project's own\n" +
			"workload, last in the pipeline: `runr promote` moves a version to the next environment.\n" +
			"How many environments a project may have is decided by the region capacity and the\n" +
			"organization quota: each one reserves its size from its creation on.\n\n" +
			"Environment variables of the project itself are `runr env`.",
	}
	ls := newEnvironmentsLsCmd(app)
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	cmd.AddCommand(newEnvironmentsShowCmd(app), newEnvironmentsCreateCmd(app), newEnvironmentsUpdateCmd(app),
		newEnvironmentsDeleteCmd(app), newEnvironmentSecretsCmd(app))
	return cmd
}

func environmentVersion(e *runr.Environment) string {
	if e.Deployment == nil || e.Deployment.Version == nil {
		return "-"
	}
	return e.Deployment.Version.Label
}

func environmentStatus(e *runr.Environment) string {
	if e.Deployment == nil {
		return "-"
	}
	if e.Deployment.ConfigSource == runr.ConfigRestored {
		return e.Deployment.Status + " (config restored)"
	}
	return e.Deployment.Status
}

func newEnvironmentsLsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the project's environments in pipeline order",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, err := c.ListEnvironments(cmd.Context(), p.ID)
			if err != nil {
				return environmentsErr(err)
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "REGION", "VERSION", "STATUS", "APPROVAL", "BRANCH", "URL")
				for i := range list.Environments {
					e := &list.Environments[i]
					approval := ""
					if e.RequiresApproval {
						approval = "required"
					}
					t.Row(e.Name, output.Dash(e.RegionName()), environmentVersion(e), environmentStatus(e), output.Dash(approval),
						output.Dash(e.GitBranch), output.Dash(e.URL))
				}
				if len(list.Environments) > 1 {
					names := make([]string, 0, len(list.Environments))
					for _, e := range list.Environments {
						names = append(names, e.Name)
					}
					t.Note("Pipeline: %s", strings.Join(names, " → "))
				}
			})
		},
	}
}

func newEnvironmentsShowCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "show NAME",
		Short: "Show an environment: region, URL, live version, overrides and secrets",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			e, err := resolveEnvironment(cmd.Context(), c, p, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(e, func(t *output.TableWriter) { describeEnvironment(t, e) })
		},
	}
}

func describeEnvironment(t *output.TableWriter, e *runr.Environment) {
	name := e.Name
	if e.IsPrimary {
		name += " (the project's own workload)"
	}
	t.KV("Environment", name)
	t.KV("Workload", output.Dash(e.WorkloadName))
	t.KV("Region", output.Dash(e.RegionName()))
	t.KV("URL", output.Dash(e.URL))
	if e.DeployStrategy != "" {
		t.KV("Deploy strategy", runr.StrategyLabel(e.DeployStrategy))
	}
	t.KV("Timed warmer", environmentWarmLabel(e))
	t.KV("Live", environmentVersion(e)+" "+environmentStatus(e))
	t.KV("Promotions in", map[bool]string{true: "need approval", false: "deploy at once"}[e.RequiresApproval])
	if e.GitBranch != "" {
		branch := e.GitBranch
		if !e.AutoDeploy {
			branch += " (auto-deploy off)"
		}
		t.KV("Git branch", branch)
	}
	if !e.IsPrimary {
		t.KV("Project secrets", map[bool]string{true: "inherited", false: "not inherited"}[e.InheritSecrets])
		for _, line := range overrideLines(e.Overrides) {
			t.KV("Override", line)
		}
		names := make([]string, 0, len(e.Secrets))
		for _, s := range e.Secrets {
			names = append(names, s.Name)
		}
		t.KV("Own secrets", joinOrDash(names))
	}
}

// environmentWarmLabel is an environment's timed warmer: whose schedule, and what it keeps warm now.
func environmentWarmLabel(e *runr.Environment) string {
	s := "off"
	if len(e.ScaleSchedule) > 0 {
		s = runr.ScaleScheduleLabel(e.ScaleSchedule) + " (organization time zone)"
	}
	switch {
	case e.IsPrimary:
	case e.ScaleScheduleInherited && len(e.ScaleSchedule) > 0:
		s += ", the project's schedule"
	case e.ScaleScheduleInherited:
		s += " (as the project)"
	case len(e.ScaleSchedule) > 0:
		s += ", its own schedule"
	default:
		s += " in this environment"
	}
	if w := e.WarmScale; w != nil && w.Applied != nil {
		s += fmt.Sprintf("; %d kept warm now", *w.Applied)
		if w.AppliedAt != nil {
			s += " (set " + output.Age(w.AppliedAt) + " ago)"
		}
	}
	return s
}

// overrideLines renders an environment's overrides, env var values masked.
func overrideLines(o runr.EnvironmentOverrides) []string {
	var lines []string
	add := func(k string, v any, ok bool) {
		if ok {
			lines = append(lines, fmt.Sprintf("%s=%v", k, v))
		}
	}
	if o.CPU != nil {
		add("cpu", *o.CPU, true)
	}
	if o.MemoryMB != nil {
		add("memory_mb", *o.MemoryMB, true)
	}
	if o.TimeoutSeconds != nil {
		add("timeout_seconds", *o.TimeoutSeconds, true)
	}
	if o.MinScale != nil {
		add("min_scale", *o.MinScale, true)
	}
	if o.MaxScale != nil {
		add("max_scale", *o.MaxScale, true)
	}
	if o.ScaleToZero != nil {
		add("scale_to_zero", *o.ScaleToZero, true)
	}
	if o.Schedule != nil {
		add("schedule", *o.Schedule, true)
	}
	if o.QueueStream != nil {
		add("queue_stream", *o.QueueStream, true)
	}
	if o.ScaleSchedule != nil {
		warm := "none"
		if len(*o.ScaleSchedule) > 0 {
			warm = runr.ScaleScheduleLabel(*o.ScaleSchedule)
		}
		add("scale_schedule", warm, true)
	}
	keys := make([]string, 0, len(o.EnvVars))
	for k := range o.EnvVars {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		if v := o.EnvVars[k]; v == nil {
			lines = append(lines, "env "+k+" (removed)")
		} else {
			lines = append(lines, "env "+k+"="+maskValue(*v))
		}
	}
	if o.EnvVarsHidden {
		lines = append(lines, "env (hidden: needs env.view)")
	}
	return lines
}

// environmentFlags are the settings of create and update.
type environmentFlags struct {
	region, branch, cpu, schedule, stream string
	requiresApproval, autoDeploy          bool
	noInheritSecrets, inheritSecrets      bool
	env                                   []string
	memory, minScale, maxScale, timeout   int
	position                              int
	scaleToZero                           bool
	// the environment's own timed warmer: --warm rows, --no-warm (none), --project-warm (the project's)
	warm                []string
	noWarm, projectWarm bool
}

func (f *environmentFlags) register(cmd *cobra.Command, create bool) {
	fl := cmd.Flags()
	fl.StringVar(&f.region, "region", "", "region name or id (default: the project's region)")
	fl.StringVar(&f.branch, "branch", "", "a push to this git branch deploys the environment (e.g. develop)")
	fl.BoolVar(&f.requiresApproval, "requires-approval", false, "promotions into the environment wait for an approver (environments.approve)")
	fl.StringArrayVar(&f.env, "env", nil, "environment variable over the project's, KEY=VALUE (repeatable); KEY= removes the project's variable here")
	fl.StringVar(&f.cpu, "cpu", "", "CPU of a pod, e.g. 250m or 1")
	fl.IntVar(&f.memory, "memory", 0, "memory of a pod in MiB")
	fl.IntVar(&f.minScale, "min-scale", 0, "minimum replicas")
	fl.IntVar(&f.maxScale, "max-scale", 0, "maximum replicas")
	fl.BoolVar(&f.scaleToZero, "scale-to-zero", true, "scale to zero when idle")
	fl.IntVar(&f.timeout, "timeout", 0, "request timeout in seconds")
	fl.StringVar(&f.schedule, "schedule", "", "cron schedule (schedule-triggered projects)")
	fl.StringVar(&f.stream, "queue-stream", "", "queue stream (queue-triggered projects; default <stream>-<environment>)")
	fl.IntVar(&f.position, "position", 0, "position in the pipeline (lower first; production is 1000)")
	fl.StringArrayVar(&f.warm, "warm", nil, "the environment's own timed warmer row \"<cron>=<count>\" (repeatable; the rows replace the schedule there)")
	fl.BoolVar(&f.noWarm, "no-warm", false, "keep no containers warm in this environment (whatever the project's schedule)")
	if !create {
		fl.BoolVar(&f.projectWarm, "project-warm", false, "follow the project's timed warmer again")
	}
	if create {
		fl.BoolVar(&f.noInheritSecrets, "no-inherit-secrets", false, "the environment does not get the project's secret variables")
	} else {
		fl.BoolVar(&f.inheritSecrets, "inherit-secrets", true, "the environment gets the project's secret variables")
		fl.BoolVar(&f.autoDeploy, "auto-deploy", true, "a push to the branch deploys the environment")
	}
}

// input builds the request: only the flags given; overrides are merged over current.
func (f *environmentFlags) input(ctx context.Context, cmd *cobra.Command, c *runr.Client, current *runr.EnvironmentOverrides) (runr.EnvironmentInput, error) {
	changed := cmd.Flags().Changed
	var in runr.EnvironmentInput
	if changed("region") {
		region, err := c.FindRegion(ctx, f.region)
		if err != nil {
			return in, err
		}
		in.RegionID = &region.ID
	}
	if changed("branch") {
		in.GitBranch = &f.branch
	}
	if changed("requires-approval") {
		in.RequiresApproval = &f.requiresApproval
	}
	if changed("no-inherit-secrets") {
		v := !f.noInheritSecrets
		in.InheritSecrets = &v
	}
	if changed("inherit-secrets") {
		in.InheritSecrets = &f.inheritSecrets
	}
	if changed("auto-deploy") {
		in.AutoDeploy = &f.autoDeploy
	}
	if changed("position") {
		in.Position = &f.position
	}

	o := runr.EnvironmentOverrides{}
	if current != nil {
		o = *current
		o.EnvVarsHidden = false
	}
	touched := false
	if len(f.env) > 0 {
		if current != nil && current.EnvVarsHidden {
			return in, usageErrorf("the environment's variables are hidden from this token (needs env.view): --env would replace them")
		}
		vars := map[string]*string{}
		for k, v := range o.EnvVars {
			vars[k] = v
		}
		for _, pair := range f.env {
			k, v, ok := strings.Cut(pair, "=")
			k = strings.TrimSpace(k)
			if !ok || k == "" {
				return in, usageErrorf("--env %q is not KEY=VALUE", pair)
			}
			if err := runr.ValidateEnvName(k); err != nil {
				return in, usageErrorf("%v", err)
			}
			if v == "" {
				vars[k] = nil // removes the project's variable in this environment
				continue
			}
			val := v
			vars[k] = &val
		}
		o.EnvVars = vars
		touched = true
	}
	setStr := func(flag string, dst **string, v string) {
		if changed(flag) {
			val := v
			*dst = &val
			touched = true
		}
	}
	setInt := func(flag string, dst **int, v int) {
		if changed(flag) {
			val := v
			*dst = &val
			touched = true
		}
	}
	setStr("cpu", &o.CPU, f.cpu)
	setInt("memory", &o.MemoryMB, f.memory)
	setInt("min-scale", &o.MinScale, f.minScale)
	setInt("max-scale", &o.MaxScale, f.maxScale)
	setInt("timeout", &o.TimeoutSeconds, f.timeout)
	setStr("schedule", &o.Schedule, f.schedule)
	setStr("queue-stream", &o.QueueStream, f.stream)
	if changed("scale-to-zero") {
		v := f.scaleToZero
		o.ScaleToZero = &v
		touched = true
	}
	// The environment's own timed warmer (docs/scaling.md#timed-warmer)
	warmFlags := 0
	for _, flag := range []string{"warm", "no-warm", "project-warm"} {
		if changed(flag) {
			warmFlags++
		}
	}
	if warmFlags > 1 {
		return in, usageErrorf("--warm, --no-warm and --project-warm exclude each other")
	}
	switch {
	case changed("warm"):
		rows := make([]runr.ScaleScheduleRow, 0, len(f.warm))
		for _, w := range f.warm {
			row, err := runr.ParseScaleScheduleRow(w)
			if err != nil {
				return in, usageErrorf("--warm %s", err.Error())
			}
			rows = append(rows, row)
		}
		maxScale := 0
		if o.MaxScale != nil {
			maxScale = *o.MaxScale
		}
		if err := runr.ValidateScaleSchedule(rows, maxScale); err != nil {
			return in, usageErrorf("--warm: %s", err.Error())
		}
		o.ScaleSchedule = &rows
		touched = true
	case f.noWarm:
		none := []runr.ScaleScheduleRow{}
		o.ScaleSchedule = &none
		touched = true
	case f.projectWarm:
		o.ScaleSchedule = nil
		touched = true
	}
	if touched {
		in.Overrides = &o
	}
	return in, nil
}

func newEnvironmentsCreateCmd(app *App) *cobra.Command {
	var f environmentFlags
	cmd := &cobra.Command{
		Use:   "create NAME",
		Short: "Add an environment to the project",
		Long: "Add an environment: a lowercase DNS label (dev, staging, qa-eu). It must fit its region's\n" +
			"capacity and the organization quota with its size. Nothing runs in it until a version is\n" +
			"deployed (`runr deploy --env NAME`) or promoted (`runr promote`) there.",
		Example: "  runr environments create staging --branch develop --min-scale 1\n" +
			"  runr environments create qa --no-warm                 # no warm containers there\n" +
			"  runr environments create dev --env LOG_LEVEL=debug --env SENTRY_DSN= --scale-to-zero\n" +
			"  runr environments create eu --region eu-2 --requires-approval",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			in, err := f.input(ctx, cmd, c, nil)
			if err != nil {
				return err
			}
			in.Name = strings.ToLower(strings.TrimSpace(args[0]))
			e, err := c.CreateEnvironment(ctx, p.ID, in)
			if err != nil {
				return environmentsErr(err)
			}
			return app.Printer().Print(e, func(t *output.TableWriter) {
				describeEnvironment(t, e)
				t.Note("Deploy to it with `runr deploy --env %s`, or promote a version with `runr promote`.", e.Name)
			})
		},
	}
	f.register(cmd, true)
	return cmd
}

func newEnvironmentsUpdateCmd(app *App) *cobra.Command {
	var f environmentFlags
	cmd := &cobra.Command{
		Use:   "update NAME",
		Short: "Change an environment's settings and overrides",
		Long: "Change an environment. The flags given change; overrides are merged over the current\n" +
			"ones (--env KEY= removes the project's variable in the environment). Overrides reach the\n" +
			"workload with the next deploy. The region changes only while nothing runs there.\n" +
			"`runr environments update production --requires-approval=false` lets promotions into\n" +
			"production deploy without an approver.\n\n" +
			"Timed warmer: an environment runs the project's schedule (runr scale --warm) unless it has\n" +
			"its own: --warm \"<cron>=<count>\" rows (in the organization's time zone) replace it there,\n" +
			"--no-warm keeps no containers warm there, --project-warm follows the project's again. A\n" +
			"warmer change reaches the running workload within a minute, without a deploy.",
		Example: "  runr environments update staging --min-scale 2 --env FEATURE_X=on\n" +
			"  runr environments update staging --warm \"0 9 * * 1-5=2\" --warm \"0 18 * * 1-5=0\"\n" +
			"  runr environments update staging --project-warm\n" +
			"  runr environments update production --requires-approval=false",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			current, err := resolveEnvironment(ctx, c, p, args[0])
			if err != nil {
				return err
			}
			in, err := f.input(ctx, cmd, c, &current.Overrides)
			if err != nil {
				return err
			}
			e, err := c.UpdateEnvironment(ctx, p.ID, args[0], in)
			if err != nil {
				return environmentsErr(err)
			}
			return app.Printer().Print(e, func(t *output.TableWriter) { describeEnvironment(t, e) })
		},
	}
	f.register(cmd, false)
	return cmd
}

func newEnvironmentsDeleteCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "delete NAME",
		Aliases: []string{"rm"},
		Short:   "Delete an environment with its workload, deployments and secrets",
		Long: "Delete an environment: its workload leaves its region, its custom domains and bindings are\n" +
			"detached (the bindings themselves stay), its deployments and secrets go. Usage records\n" +
			"stay. The production environment is the project itself and cannot be deleted.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			if err := app.confirm(fmt.Sprintf("Delete environment %s of %s", args[0], p.Name), yes); err != nil {
				return err
			}
			incomplete, err := c.DeleteEnvironment(ctx, p.ID, args[0])
			if err != nil {
				return environmentsErr(err)
			}
			out := map[string]any{"deleted": args[0], "incomplete": incomplete}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.Note("Environment %s deleted.", args[0])
				if incomplete {
					t.Note("Some of its resources could not be removed from the region (see the API log).")
				}
			})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newEnvironmentSecretsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "secrets",
		Short: "Secret variables of an environment (over the project's)",
		Long: "An environment gets the project's secret variables (unless created with\n" +
			"--no-inherit-secrets) and its own over them by name. Values are write-only; a change\n" +
			"reaches the workload with the next deploy.",
	}
	ls := &cobra.Command{
		Use:     "ls ENVIRONMENT",
		Aliases: []string{"list"},
		Short:   "List the secret variables an environment gets (names only)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, err := c.ListEnvironmentSecrets(cmd.Context(), p.ID, args[0])
			if err != nil {
				return environmentsErr(err)
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "FROM", "UPDATED")
				for _, s := range list.Secrets {
					from := "environment"
					if s.Scope == "project" {
						from = "project"
					}
					t.Row(s.Name, from, output.Dash(s.UpdatedAt))
				}
				if !list.InheritSecrets {
					t.Note("The project's secret variables are not inherited.")
				}
			})
		},
	}
	set := &cobra.Command{
		Use:   "set ENVIRONMENT KEY[=VALUE]",
		Short: "Set a secret variable of an environment (KEY alone: hidden prompt or stdin)",
		Example: "  runr environments secrets set staging STRIPE_KEY           # prompts, or reads stdin\n" +
			"  printf %s \"$DB_URL\" | runr environments secrets set staging DATABASE_URL",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			key, value, ok := strings.Cut(args[1], "=")
			key = strings.TrimSpace(key)
			if err := runr.ValidateEnvName(key); err != nil {
				return usageErrorf("%v", err)
			}
			if !ok {
				v, err := readSecretValue(app, key)
				if err != nil {
					return err
				}
				value = v
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			s, err := c.SetEnvironmentSecret(cmd.Context(), p.ID, args[0], key, value)
			if err != nil {
				return environmentsErr(err)
			}
			return app.Printer().Print(s, func(t *output.TableWriter) {
				t.Note("Secret %s of %s set; it reaches the workload with the next deploy.", s.Name, args[0])
			})
		},
	}
	unset := &cobra.Command{
		Use:     "unset ENVIRONMENT KEY...",
		Aliases: []string{"rm"},
		Short:   "Remove secret variables of an environment",
		Args:    cobra.MinimumNArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			for _, key := range args[1:] {
				if err := c.DeleteEnvironmentSecret(cmd.Context(), p.ID, args[0], key); err != nil {
					return environmentsErr(err)
				}
			}
			out := map[string]any{"environment": args[0], "removed": args[1:]}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.Note("Removed %s from %s; the next deploy picks the change up.", strings.Join(args[1:], ", "), args[0])
			})
		},
	}
	cmd.AddCommand(ls, set, unset)
	return cmd
}

// ---------------------------------------------------------------------------------------
// Promotion

// configChangeLine renders one entry of a "what will change" diff.
func configChangeLine(ch runr.ConfigChange) string {
	if len(ch.Added)+len(ch.Removed)+len(ch.Changed) > 0 {
		var parts []string
		if len(ch.Added) > 0 {
			parts = append(parts, "+"+strings.Join(ch.Added, " +"))
		}
		if len(ch.Removed) > 0 {
			parts = append(parts, "-"+strings.Join(ch.Removed, " -"))
		}
		if len(ch.Changed) > 0 {
			parts = append(parts, "~"+strings.Join(ch.Changed, " ~"))
		}
		return strings.Join(parts, " ")
	}
	str := func(v any) string {
		if v == nil {
			return "-"
		}
		return fmt.Sprint(v)
	}
	return str(ch.From) + " → " + str(ch.To)
}

func versionLabel(v *runr.PromotionVersion) string {
	if v == nil {
		return "-"
	}
	if v.Label != "" {
		return v.Label
	}
	return "v" + strconv.Itoa(v.Number)
}

func newPromoteCmd(app *App) *cobra.Command {
	var to, message string
	var dryRun, noWait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "promote FROM",
		Short: "Promote the version an environment runs to the next environment",
		Long: "Promote the version FROM runs — the same version and image, never built again — to the\n" +
			"next environment of the pipeline (or --to), which runs it with its own configuration.\n" +
			"--dry-run shows what would change: the code version and the config differences (env var\n" +
			"names only). Into an environment that requires approval (production by default) the\n" +
			"promotion waits for someone else with environments.approve (`runr approvals`).",
		Example: "  runr promote staging --dry-run\n" +
			"  runr promote staging -m \"release 1.4\"\n" +
			"  runr promote dev --to staging",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			if dryRun {
				pv, err := c.PreviewPromotion(ctx, p.ID, args[0], to)
				if err != nil {
					return environmentsErr(err)
				}
				return app.Printer().Print(pv, func(t *output.TableWriter) {
					t.KV("From", pv.Source.Environment+" ("+versionLabel(pv.Code.To)+")")
					t.KV("To", pv.Target.Environment+" (runs "+versionLabel(pv.Code.From)+")")
					if pv.Code.To != nil && pv.Code.To.Image != "" {
						t.KV("Image", pv.Code.To.Image)
					}
					t.KV("Approval", map[bool]string{true: "required", false: "not required"}[pv.RequiresApproval])
					if pv.Problem != "" {
						t.KV("Problem", pv.Problem)
					}
					t.Header("CHANGES IN "+strings.ToUpper(pv.Target.Environment), "")
					if pv.Code.Changes {
						t.Row("code", versionLabel(pv.Code.From)+" → "+versionLabel(pv.Code.To))
					}
					for _, ch := range pv.Config {
						t.Row(ch.Field, configChangeLine(ch))
					}
					if !pv.Code.Changes && len(pv.Config) == 0 {
						t.Note("Nothing changes in %s.", pv.Target.Environment)
					}
				})
			}

			pr, err := c.Promote(ctx, p.ID, args[0], to, message)
			if err != nil {
				return environmentsErr(err)
			}
			out := map[string]any{"promotion": pr}
			if pr.Status == runr.PromotionPendingApproval {
				return app.Printer().Print(out, func(t *output.TableWriter) {
					t.Note("Promotion %s of %s to %s waits for approval: someone with environments.approve other than you", pr.ID, versionLabel(pr.Version), pr.Target)
					t.Note("approves it with `runr approvals approve %s` (or in the console).", pr.ID)
				})
			}
			if pr.DeploymentID == "" || noWait {
				return app.Printer().Print(out, func(t *output.TableWriter) {
					t.Note("Promotion %s: %s%s", pr.ID, pr.Status, suffixIf(pr.StatusMessage))
				})
			}
			app.Progress("Promoting %s to %s", versionLabel(pr.Version), pr.Target)
			d, werr := c.WaitDeployment(ctx, p.ID, pr.DeploymentID, app.waitOptions(timeout, "Deployment"))
			if d != nil {
				out["deployment"] = d
			}
			if perr := app.Printer().Print(out, func(t *output.TableWriter) {
				if werr == nil {
					t.Note("%s is live in %s.", versionLabel(pr.Version), pr.Target)
				}
			}); perr != nil && werr == nil {
				werr = perr
			}
			return werr
		},
	}
	cmd.Flags().StringVar(&to, "to", "", "target environment (default: the next one in the pipeline)")
	cmd.Flags().StringVarP(&message, "message", "m", "", "why (shown to the approver)")
	cmd.Flags().BoolVar(&dryRun, "dry-run", false, "only show what would change")
	cmd.Flags().BoolVar(&noWait, "no-wait", false, "return once the deployment started")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the deployment")
	return cmd
}

func suffixIf(s string) string {
	if s == "" {
		return ""
	}
	return ": " + s
}

func newApprovalsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "approvals",
		Aliases: []string{"approval"},
		Short:   "Promotions waiting for approval: list, approve, reject, cancel",
		Long: "Promotions into an environment that requires approval (production by default) wait for\n" +
			"someone with environments.approve on the project who did not request them. `runr approvals`\n" +
			"lists those of every project you may see; approve deploys the promotion.",
	}
	var status string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List promotions waiting for approval (--status all: every promotion)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ApprovalInbox(cmd.Context(), status)
			if err != nil {
				return environmentsErr(err)
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "PROJECT", "PROMOTION", "VERSION", "STATUS", "REQUESTED BY", "YOU MAY APPROVE")
				for _, pr := range list {
					project := pr.ProjectID
					if pr.Project != nil && pr.Project.Name != "" {
						project = pr.Project.Name
					}
					t.Row(pr.ID, project, pr.Source+" → "+pr.Target, versionLabel(pr.Version), pr.Status, output.Dash(pr.RequestedBy), yesNo(pr.CanApprove))
				}
				if len(list) == 0 {
					t.Note("Nothing waits for approval.")
				}
			})
		},
	}
	ls.Flags().StringVar(&status, "status", "", "status to list: pending_approval (default), deployed, rejected, … or all")
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	decide := func(use, short, decision string) *cobra.Command {
		var comment string
		var wait bool
		var timeout time.Duration
		c := &cobra.Command{
			Use:   use + " ID",
			Short: short,
			Args:  cobra.ExactArgs(1),
			RunE: func(cmd *cobra.Command, args []string) error {
				ctx := cmd.Context()
				c, projectID, err := promotionProject(ctx, app, args[0])
				if err != nil {
					return err
				}
				var pr *runr.Promotion
				switch decision {
				case "approve":
					pr, err = c.ApprovePromotion(ctx, projectID, args[0], comment)
				case "reject":
					pr, err = c.RejectPromotion(ctx, projectID, args[0], comment)
				default:
					pr, err = c.CancelPromotion(ctx, projectID, args[0])
				}
				if err != nil {
					return environmentsErr(err)
				}
				out := map[string]any{"promotion": pr}
				var werr error
				live := false
				if decision == "approve" && wait && pr.DeploymentID != "" {
					d, err := c.WaitDeployment(ctx, projectID, pr.DeploymentID, app.waitOptions(timeout, "Deployment"))
					if d != nil {
						out["deployment"] = d
					}
					werr = err
					live = err == nil
				}
				if perr := app.Printer().Print(out, func(t *output.TableWriter) {
					if live {
						t.Note("Promotion %s approved: %s is live in %s.", pr.ID, versionLabel(pr.Version), pr.Target)
						return
					}
					t.Note("Promotion %s of %s to %s: %s%s", pr.ID, versionLabel(pr.Version), pr.Target, pr.Status, suffixIf(pr.StatusMessage))
				}); perr != nil && werr == nil {
					werr = perr
				}
				return werr
			},
		}
		if decision != "cancel" {
			c.Flags().StringVar(&comment, "comment", "", "recorded with the decision")
		}
		if decision == "approve" {
			c.Flags().BoolVar(&wait, "wait", false, "wait until the promoted deployment is live")
			c.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait")
		}
		return c
	}
	cmd.AddCommand(
		decide("approve", "Approve a promotion and deploy it (environments.approve; not your own)", "approve"),
		decide("reject", "Reject a promotion that waits for approval", "reject"),
		decide("cancel", "Withdraw a promotion you requested", "cancel"),
	)
	return cmd
}

// promotionProject finds the project of a promotion: the command's project (--project,
// $RUNR_PROJECT, runr.yaml), else the one the approvals inbox names.
func promotionProject(ctx context.Context, app *App, promotionID string) (*runr.Client, string, error) {
	if name, err := app.ProjectName(); err == nil && name != "" {
		c, p, err := app.Project(ctx)
		if err != nil {
			return nil, "", err
		}
		return c, p.ID, nil
	}
	c, err := app.Client()
	if err != nil {
		return nil, "", err
	}
	list, err := c.ApprovalInbox(ctx, "all")
	if err != nil {
		return nil, "", environmentsErr(err)
	}
	for _, pr := range list {
		if pr.ID == promotionID {
			return c, pr.ProjectID, nil
		}
	}
	return nil, "", &runr.NotFoundError{Kind: "promotion", Ref: promotionID}
}
