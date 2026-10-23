package cmd

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr strategy` sets how a project deploys (direct, blue-green or canary, with their
// options); `runr rollout` follows and decides the rollouts that blue-green and canary deploys
// open. `runr deploy --strategy` picks a strategy for one deploy (deploy.go). --env works on one
// environment of the project: its own settings over the project's, its rollouts.

func init() {
	registerCommand(newRolloutCmd)
	registerCommand(newStrategyCmd)
}

// strategyFlag normalizes a --strategy value ("" keeps the project's strategy).
func strategyFlag(v string) (string, error) {
	if strings.TrimSpace(v) == "" {
		return "", nil
	}
	s, ok := runr.NormalizeStrategy(v)
	if !ok {
		return "", usageErrorf("--strategy %q is not a deploy strategy (direct, blue-green or canary)", v)
	}
	return s, nil
}

// ---------------------------------------------------------------------------------------
// runr rollout

func newRolloutCmd(app *App) *cobra.Command {
	var deploymentID, envName string
	cmd := &cobra.Command{
		Use:     "rollout",
		Aliases: []string{"rollouts"},
		Args:    cobra.NoArgs,
		Short:   "Follow, promote, abort or roll back the blue-green or canary rollout of a deploy",
		Long: "A deploy of a project with the blue-green or canary strategy (runr strategy) opens a rollout\n" +
			"while a deployment serves the project: the new revision starts next to the live one with a\n" +
			"test URL and takes the traffic on promotion (blue-green) or step by step (canary). These\n" +
			"commands act on the project's newest rollout, or on the rollout of --deployment. Each\n" +
			"environment of the project rolls out on its own, with its own strategy: --env acts on the\n" +
			"newest rollout of that environment. Without a subcommand, `runr rollout` shows the status.",
		Example: "  runr rollout status --watch\n" +
			"  runr rollout promote -p shop\n" +
			"  runr rollout promote --env staging\n" +
			"  runr rollout abort --reason \"5xx on /checkout\"\n" +
			"  runr rollout rollback",
	}
	cmd.PersistentFlags().StringVar(&deploymentID, "deployment", "", "the rollout of this deployment id (default: the project's newest rollout)")
	cmd.PersistentFlags().StringVar(&envName, "env", "", "the newest rollout of this environment of the project (runr environments) instead of its own workload's")
	scope := &rolloutScope{deploymentID: &deploymentID, env: &envName}
	status := newRolloutStatusCmd(app, scope)
	cmd.AddCommand(status, newRolloutPromoteCmd(app, scope), newRolloutAbortCmd(app, scope),
		newRolloutRollbackCmd(app, scope))
	cmd.RunE = status.RunE
	return cmd
}

// rolloutScope is what `runr rollout` acts on: the rollout of --deployment, else the newest
// rollout of --env (default: the project's own workload).
type rolloutScope struct {
	deploymentID *string
	env          *string
}

// projectRollout returns the newest rollout of env ("" the project's own workload), an error when
// it never had one.
func projectRollout(ctx context.Context, c *runr.Client, p *runr.Project, env string) (*runr.Rollout, error) {
	r, err := c.ProjectRolloutIn(ctx, p.ID, env)
	if errors.Is(err, runr.ErrUnsupported) {
		return nil, fmt.Errorf("rollouts: %w", err)
	}
	if err != nil {
		return nil, err
	}
	if r == nil {
		set := "runr strategy set"
		if env != "" && env != runr.PrimaryEnvironment {
			set += " --env " + env
		}
		return nil, fmt.Errorf("%w: deploys with the blue-green or canary strategy open one (%s)",
			&runr.NotFoundError{Kind: "rollout of project", Ref: projectIn(p, env)}, set)
	}
	return r, nil
}

// notRolloutDeployment turns the 404 of a deployment's rollout into a not-found error.
func notRolloutDeployment(err error, deploymentID string) error {
	if runr.IsNotFound(err) {
		return fmt.Errorf("%w: it is not the new deployment of a rollout",
			&runr.NotFoundError{Kind: "rollout of deployment", Ref: deploymentID})
	}
	return err
}

// findRollout resolves the project and the rollout of the scope's deployment, else the newest
// rollout of its environment.
func findRollout(ctx context.Context, app *App, scope *rolloutScope) (*runr.Client, *runr.Project, *runr.Rollout, error) {
	c, p, err := app.Project(ctx)
	if err != nil {
		return nil, nil, nil, err
	}
	env, err := environmentScope(ctx, c, p, *scope.env)
	if err != nil {
		return nil, nil, nil, err
	}
	if deploymentID := *scope.deploymentID; deploymentID != "" {
		r, err := c.DeploymentRollout(ctx, p.ID, deploymentID)
		if err != nil {
			return nil, nil, nil, notRolloutDeployment(err, deploymentID)
		}
		return c, p, r, nil
	}
	r, err := projectRollout(ctx, c, p, env)
	if err != nil {
		return nil, nil, nil, err
	}
	return c, p, r, nil
}

func newRolloutStatusCmd(app *App, scope *rolloutScope) *cobra.Command {
	var watch bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "status",
		Short: "Show the rollout: status, traffic split, test URL, step, health checks, metrics and history",
		Long: "Show the project's newest rollout (--env: of that environment), or the rollout of\n" +
			"--deployment. --watch follows an open\n" +
			"rollout until it is promoted, aborted, rolled back or fails, printing its events, then shows\n" +
			"it; the exit code is then 6 unless it was promoted.",
		Example: "  runr rollout status\n" +
			"  runr rollout status --watch -p shop\n" +
			"  runr rollout status --env staging\n" +
			"  runr rollout status --deployment 01JB… -o json",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, r, err := findRollout(ctx, app, scope)
			if err != nil {
				return err
			}
			var werr error
			if watch && r.Open {
				limit := timeout
				if limit <= 0 {
					limit = 365 * 24 * time.Hour // until the rollout ends or Ctrl+C
				}
				done, err := c.WaitRollout(ctx, p.ID, r.DeploymentID, app.waitOptions(limit, "Rollout"))
				if done != nil {
					r = done
				}
				// Interrupted (Ctrl+C): show where it is, without an error.
				if ctx.Err() == nil {
					werr = err
				}
			}
			if perr := app.Printer().Print(r, func(t *output.TableWriter) { rolloutDetail(t, p, r) }); perr != nil && werr == nil {
				werr = perr
			}
			return werr
		},
	}
	cmd.Flags().BoolVarP(&watch, "watch", "w", false, "follow an open rollout until it ends (exit code 6 unless it is promoted)")
	cmd.Flags().DurationVar(&timeout, "timeout", 0, "how long --watch waits (default: until the rollout ends)")
	return cmd
}

// rolloutAction runs promote, abort or rollback on the rollout of the scope's deployment
// (default: the newest of its environment, else of the project's own workload) and shows the
// rollout; done is the progress line, with the project name (and the environment).
func rolloutAction(ctx context.Context, app *App, scope *rolloutScope, done string,
	act func(c *runr.Client, p *runr.Project, deploymentID string) (*runr.Rollout, error)) error {
	c, p, err := app.Project(ctx)
	if err != nil {
		return err
	}
	env, err := environmentScope(ctx, c, p, *scope.env)
	if err != nil {
		return err
	}
	deploymentID := *scope.deploymentID
	id := deploymentID
	if id == "" {
		r, err := projectRollout(ctx, c, p, env)
		if err != nil {
			return err
		}
		id = r.DeploymentID
	}
	r, err := act(c, p, id)
	if err != nil {
		if deploymentID != "" {
			return notRolloutDeployment(err, deploymentID)
		}
		return err
	}
	app.Progress(done, projectIn(p, rolloutEnvironment(r)))
	return app.Printer().Print(r, func(t *output.TableWriter) { rolloutDetail(t, p, r) })
}

// rolloutEnvironment is the environment a rollout moves the traffic of: "" for the project's own
// workload.
func rolloutEnvironment(r *runr.Rollout) string {
	if r == nil || r.Environment == runr.PrimaryEnvironment {
		return ""
	}
	return r.Environment
}

func newRolloutPromoteCmd(app *App, scope *rolloutScope) *cobra.Command {
	return &cobra.Command{
		Use:   "promote",
		Short: "Give the new revision of the rollout all the traffic now",
		Long: "Promote an open rollout: a blue-green rollout waiting for promotion, its health checks or a\n" +
			"canary at any step. The new revision gets all the traffic; after a blue-green promotion the\n" +
			"previous revision stays warm for keep_previous_minutes (`runr rollout rollback`).",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			return rolloutAction(cmd.Context(), app, scope, "Promoted: the new revision of %s has all the traffic.",
				func(c *runr.Client, p *runr.Project, id string) (*runr.Rollout, error) {
					return c.PromoteRollout(cmd.Context(), p.ID, id)
				})
		},
	}
}

func newRolloutAbortCmd(app *App, scope *rolloutScope) *cobra.Command {
	var reason string
	cmd := &cobra.Command{
		Use:   "abort",
		Short: "Stop an open rollout: the traffic stays on (or goes back to) the previous revision",
		Long: "Abort an open rollout. The traffic stays on the revision that served before (a canary's\n" +
			"share goes back to it) and the new deployment shuts down. --reason is kept in the\n" +
			"rollout's history.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if n := len([]rune(reason)); n > 500 {
				return usageErrorf("--reason is %d characters long; the limit is 500", n)
			}
			return rolloutAction(cmd.Context(), app, scope, "Rollout of %s aborted: the traffic stays on the previous revision.",
				func(c *runr.Client, p *runr.Project, id string) (*runr.Rollout, error) {
					return c.AbortRollout(cmd.Context(), p.ID, id, reason)
				})
		},
	}
	cmd.Flags().StringVar(&reason, "reason", "", "why the rollout is stopped (up to 500 characters)")
	return cmd
}

func newRolloutRollbackCmd(app *App, scope *rolloutScope) *cobra.Command {
	return &cobra.Command{
		Use:   "rollback",
		Short: "Give a promoted rollout's previous revision the traffic back while it is kept warm",
		Long: "Roll a promoted blue-green rollout back: the previous revision, kept warm until keep_until,\n" +
			"gets all the traffic again and the new deployment shuts down. Once the previous revision is\n" +
			"released, deploy an earlier version instead (runr rollback VERSION).",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			return rolloutAction(cmd.Context(), app, scope, "Rolled back: the previous revision of %s has the traffic again.",
				func(c *runr.Client, p *runr.Project, id string) (*runr.Rollout, error) {
					return c.RollbackRollout(cmd.Context(), p.ID, id)
				})
		},
	}
}

// ---------------------------------------------------------------------------------------
// Rollout views

// rolloutDetail renders a rollout for `runr rollout`.
func rolloutDetail(t *output.TableWriter, p *runr.Project, r *runr.Rollout) {
	t.KV("Project", p.Name)
	if env := rolloutEnvironment(r); env != "" {
		t.KV("Environment", env)
	}
	t.KV("Rollout", r.ID+" ("+runr.StrategyLabel(r.Strategy)+")")
	t.KV("Status", rolloutStatusLabel(r))
	t.KV("Traffic", trafficLabel(r))
	t.KV("New", revisionLabel(r.DeploymentID, r.Revision))
	t.KV("Previous", revisionLabel(r.StableDeploymentID, r.StableRevision))
	rolloutKVs(t, r)
	if r.Reason != "" {
		t.KV("Reason", r.Reason)
	}
	started := gitAge(r.CreatedAt) + " ago"
	if r.CreatedBy != "" {
		started += " by " + r.CreatedBy
	}
	t.KV("Started", started)
	if len(r.Events) > 0 {
		t.Header("AGE", "EVENT", "TRAFFIC", "BY", "MESSAGE")
		for _, e := range r.Events {
			t.Row(gitAge(e.At), e.Type, fmt.Sprintf("%d %%", e.Traffic), output.Dash(e.Actor), output.Truncate(e.Message, 80))
		}
	}
	rolloutNextNotes(t, p, r)
}

// deployRolloutDetail renders the rollout a deploy opened (runr deploy, rollback).
func deployRolloutDetail(t *output.TableWriter, res *DeployResult, r *runr.Rollout) {
	t.KV("Rollout", runr.StrategyLabel(r.Strategy)+", "+rolloutStatusLabel(r))
	t.KV("Traffic", trafficLabel(r))
	rolloutKVs(t, r)
	if res.URL != "" {
		t.KV("URL", res.URL)
	}
	if r.Reason != "" {
		t.KV("Reason", r.Reason)
	}
	if res.Project != nil {
		rolloutNextNotes(t, res.Project, r)
	}
}

// rolloutKVs adds the URLs, the canary step, the health checks, the metrics and the kept
// previous revision.
func rolloutKVs(t *output.TableWriter, r *runr.Rollout) {
	if r.TestURL != "" {
		t.KV("Test URL", r.TestURL)
	}
	if r.PreviousURL != "" {
		t.KV("Previous URL", r.PreviousURL)
	}
	if r.Step != nil && r.Open {
		t.KV("Step", stepLabel(r.Step))
	}
	if r.Health != nil && r.Open {
		t.KV("Health checks", healthLabel(r.Health))
	}
	if r.Metrics != nil {
		t.KV("Metrics", metricsLabel(r.Metrics))
	}
	if r.Status == runr.RolloutPromoted && r.KeepUntil != "" && r.ReleasedAt == "" {
		t.KV("Previous released", rolloutWhen(r.KeepUntil))
	}
}

// rolloutNextNotes say what can be done with the rollout now.
func rolloutNextNotes(t *output.TableWriter, p *runr.Project, r *runr.Rollout) {
	ref := " -p " + projectArg(p)
	if env := rolloutEnvironment(r); env != "" {
		ref += " --env " + env
	}
	switch {
	case r.Status == runr.RolloutPending:
		t.Note("The new revision is starting: `runr rollout status%s --watch` follows the rollout.", ref)
	case r.Status == runr.RolloutWaitingPromotion:
		t.Note("Check the new revision at its test URL, then `runr rollout promote%s` gives it all the traffic; `runr rollout abort%s` stops the rollout.", ref, ref)
	case r.Status == runr.RolloutProgressing && r.Strategy == runr.StrategyCanary:
		t.Note("The canary moves on by itself: `runr rollout status%s --watch` follows it, `runr rollout promote%s` gives it all the traffic now, `runr rollout abort%s` stops it.", ref, ref, ref)
	case r.Status == runr.RolloutProgressing:
		t.Note("It is promoted once the health checks pass: `runr rollout status%s --watch` follows it, `runr rollout promote%s` promotes it now, `runr rollout abort%s` stops it.", ref, ref, ref)
	case r.Can.Rollback:
		t.Note("The previous revision stays warm (released %s): `runr rollout rollback%s` gives it the traffic back.", rolloutWhen(r.KeepUntil), ref)
	}
}

// projectArg is a project name as a -p argument (its slug when the name needs quoting).
func projectArg(p *runr.Project) string {
	if !strings.ContainsAny(p.Name, " \t'\"") {
		return p.Name
	}
	if p.Slug != "" {
		return p.Slug
	}
	return strconv.Quote(p.Name)
}

func rolloutStatusLabel(r *runr.Rollout) string {
	return strings.ReplaceAll(r.Status, "_", " ")
}

func trafficLabel(r *runr.Rollout) string {
	return fmt.Sprintf("%d %% on the new revision, %d %% on the previous one", r.Traffic.Candidate, r.Traffic.Stable)
}

func revisionLabel(deploymentID, revision string) string {
	if deploymentID == "" {
		return "-"
	}
	if revision == "" {
		return "deployment " + deploymentID
	}
	return "deployment " + deploymentID + " (revision " + revision + ")"
}

// stepLabel: "1 of 3 at 10 %, 50 % in 4m".
func stepLabel(s *runr.RolloutStep) string {
	out := fmt.Sprintf("%d of %d at %d %%", s.Index+1, s.Count, s.Percent)
	switch {
	case s.NextPercent != nil && s.EndsAt != "":
		out += fmt.Sprintf(", %d %% %s", *s.NextPercent, rolloutWhen(s.EndsAt))
	case s.EndsAt != "":
		out += ", promotion " + rolloutWhen(s.EndsAt)
	}
	return out
}

// healthLabel: "1 of 3 passed on /healthz (last answer 200), deadline in 4m".
func healthLabel(h *runr.RolloutHealth) string {
	out := fmt.Sprintf("%d of %d passed on %s", h.Passes, h.Required, h.Path)
	switch {
	case h.LastError != "":
		out += " (last error: " + h.LastError + ")"
	case h.LastStatus != nil:
		out += fmt.Sprintf(" (last answer %d)", *h.LastStatus)
	}
	if h.DeadlineAt != "" {
		out += ", deadline " + rolloutWhen(h.DeadlineAt)
	}
	return out
}

// metricsLabel: "error rate 0.4 % (limit 5 %), p95 120 ms (limit 800 ms), 312 requests in 3 min
// of the new revision (span metrics)".
func metricsLabel(m *runr.RolloutMetrics) string {
	source := m.Source
	if source == "" && m.Tracing && m.MeasuredAt != "" && m.Revision == "" {
		source = runr.MetricsFromSpans // an API of an earlier release: the project's span metrics
	}
	switch {
	case source == "" && m.MeasuredAt == "":
		return "not measured yet in this step"
	case source == "":
		return "no requests of the new revision measured: the canary moves on time only"
	}
	from := "span metrics"
	if source == runr.MetricsFromKnative {
		from = "Knative request metrics"
	}
	value := func(v *runr.FlexFloat, prec int, unit string) string {
		if v == nil {
			return "-"
		}
		return flexText(v, prec) + unit
	}
	limit := func(v *runr.FlexFloat, unit string) string {
		if v == nil {
			return " (not checked)"
		}
		return " (limit " + flexText(v, -1) + unit + ")"
	}
	return fmt.Sprintf("error rate %s%s, p95 %s%s, %d requests in %s min of the new revision (%s)",
		value(m.ErrorRate, 1, " %"), limit(m.ErrorRateThreshold, " %"), value(m.P95Ms, 0, " ms"), limit(m.P95ThresholdMs, " ms"),
		m.Volume, flexText(&m.WindowMinutes, -1), from)
}

// rolloutWhen renders an API timestamp relative to now: "in 4m" or "2m ago".
func rolloutWhen(s string) string {
	t, err := time.Parse(time.RFC3339, s)
	if err != nil {
		return output.Dash(s)
	}
	if d := time.Until(t); d > 0 {
		return "in " + output.Duration(d)
	}
	return output.Age(&t) + " ago"
}

// ---------------------------------------------------------------------------------------
// runr strategy

func newStrategyCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "strategy",
		Aliases: []string{"deploy-strategy"},
		Args:    cobra.NoArgs,
		Short:   "How the project deploys: direct (default), blue-green or canary, with their options",
		Long: "direct replaces the live revision once the new one is ready. blue-green starts the new\n" +
			"revision next to the live one with a test URL and moves all the traffic on promotion\n" +
			"(manual, or automatic after health checks); the previous revision stays warm for an\n" +
			"instant rollback. canary moves the traffic to the new revision step by step and rolls back\n" +
			"when the new revision's error rate or p95 latency exceeds its limit (its span metrics with\n" +
			"tracing, else Knative's request metrics of the revision; without either the canary moves\n" +
			"on time only). Strategies apply to http and workflow\n" +
			"triggers. runr.yaml sets the same in its deploy: section. Each environment of the project\n" +
			"(runr environments) deploys with the project's settings unless it sets its own: --env\n" +
			"shows or changes those of one environment, `runr strategy reset --env` goes back to the\n" +
			"project's. Without a subcommand, `runr strategy` shows the settings.",
	}
	var envName string
	show := &cobra.Command{
		Use:     "show",
		Aliases: []string{"get"},
		Short:   "Show the deploy strategy and the options of both strategies",
		Example: "  runr strategy show\n" +
			"  runr strategy show --env staging",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			env, err := environmentScope(cmd.Context(), c, p, envName)
			if err != nil {
				return err
			}
			s, err := c.GetDeployStrategyIn(cmd.Context(), p.ID, env)
			if errors.Is(err, runr.ErrUnsupported) {
				return fmt.Errorf("deploy strategies: %w", err)
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(s, func(t *output.TableWriter) { strategyDetail(t, p, s) })
		},
	}
	addEnvFlag(show, &envName)
	cmd.AddCommand(show, newStrategySetCmd(app), newStrategyResetCmd(app))
	cmd.RunE = show.RunE
	// `runr strategy --env staging` shows that environment's settings too
	addEnvFlag(cmd, &envName)
	return cmd
}

func newStrategyResetCmd(app *App) *cobra.Command {
	var envName string
	cmd := &cobra.Command{
		Use:   "reset --env NAME",
		Short: "Let an environment deploy with the project's settings again",
		Long: "Drop what an environment sets of its own: its deploys (and the promotions into it) roll out\n" +
			"with the project's strategy and options again.",
		Example: "  runr strategy reset --env staging",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if envName == "" {
				return usageErrorf("name the environment: --env NAME (the project's own settings are changed with runr strategy set)")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			if env == runr.PrimaryEnvironment {
				return usageErrorf("production is the project's own workload: its settings are the project's (runr strategy set)")
			}
			s, err := c.ResetDeployStrategy(ctx, p.ID, env)
			if errors.Is(err, runr.ErrUnsupported) {
				return fmt.Errorf("deploy strategies: %w", err)
			}
			if err != nil {
				return err
			}
			app.Progress("%s deploys with the project's settings again: %s.", projectIn(p, env), runr.StrategyLabel(s.Strategy))
			return app.Printer().Print(s, func(t *output.TableWriter) { strategyDetail(t, p, s) })
		},
	}
	addEnvFlag(cmd, &envName)
	return cmd
}

func newStrategySetCmd(app *App) *cobra.Command {
	var (
		autoPromote                                                         bool
		healthChecks                                                        int
		healthPath, keepPrevious, steps, interval, maxRate, maxP95, envName string
	)
	cmd := &cobra.Command{
		Use:   "set [direct|blue-green|canary]",
		Short: "Choose the deploy strategy and change its options; flags change only what they name",
		Long: "Set the project's deploy strategy (direct, blue-green or canary) and change options of either\n" +
			"strategy; what is not given keeps its value. The settings apply to the next deploys and do not\n" +
			"create a version. Durations take minutes (15) or a duration (15m, 1h); --max-p95 takes\n" +
			"milliseconds (800) or a duration (800ms, 1.5s); off clears --health-path, --max-error-rate\n" +
			"and --max-p95.\n\n" +
			"--env changes the settings of one environment of the project instead: what is given becomes\n" +
			"its own, over the project's settings (the rest keeps following the project).",
		Example: "  runr strategy set blue-green\n" +
			"  runr strategy set canary --env staging --steps 25,100\n" +
			"  runr strategy set blue-green --auto-promote --health-path /healthz --health-checks 5 --keep-previous 30m\n" +
			"  runr strategy set canary --steps 10,50,100 --interval 5m --max-error-rate 2 --max-p95 800ms\n" +
			"  runr strategy set --max-p95 off         # stop checking the latency\n" +
			"  runr strategy set direct",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			var in runr.DeployStrategyInput
			if len(args) == 1 {
				s, ok := runr.NormalizeStrategy(args[0])
				if !ok {
					return usageErrorf("%q is not a deploy strategy (direct, blue-green or canary)", args[0])
				}
				in.Strategy = s
			}
			var bg runr.BlueGreenInput
			if fl.Changed("auto-promote") {
				v := autoPromote
				bg.AutoPromote = &v
			}
			if fl.Changed("health-path") {
				if hp := strings.TrimSpace(healthPath); offValue(hp) {
					bg.HealthPath = runr.NullOf[string]()
				} else if err := projectfile.ValidateHealthPath(hp); err != nil {
					return usageErrorf("--health-path %v", err)
				} else {
					bg.HealthPath = runr.NullableOf(hp)
				}
			}
			if fl.Changed("health-checks") {
				if healthChecks < 1 || healthChecks > 20 {
					return usageErrorf("--health-checks must be between 1 and 20")
				}
				v := healthChecks
				bg.HealthChecks = &v
			}
			if fl.Changed("keep-previous") {
				m, err := parseMinutes("--keep-previous", keepPrevious, 0, 1440)
				if err != nil {
					return err
				}
				bg.KeepPreviousMinutes = &m
			}
			if bg != (runr.BlueGreenInput{}) {
				in.BlueGreen = &bg
			}
			var cn runr.CanaryInput
			canary := false
			if fl.Changed("steps") {
				list, err := parseSteps(steps)
				if err != nil {
					return err
				}
				cn.Steps, canary = list, true
			}
			if fl.Changed("interval") {
				m, err := parseMinutes("--interval", interval, 1, 1440)
				if err != nil {
					return err
				}
				cn.IntervalMinutes, canary = &m, true
			}
			if fl.Changed("max-error-rate") {
				v, err := parseErrorRate(maxRate)
				if err != nil {
					return err
				}
				cn.ErrorRateThreshold, canary = v, true
			}
			if fl.Changed("max-p95") {
				v, err := parseP95(maxP95)
				if err != nil {
					return err
				}
				cn.P95ThresholdMs, canary = v, true
			}
			if canary {
				in.Canary = &cn
			}
			if in.Empty() {
				return usageErrorf("give a strategy (direct, blue-green or canary) or an option to change (see runr strategy set --help)")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			if env == runr.PrimaryEnvironment {
				env = "" // production's settings are the project's
			}
			s, err := c.UpdateDeployStrategyIn(ctx, p.ID, env, in)
			if errors.Is(err, runr.ErrUnsupported) {
				return fmt.Errorf("deploy strategies: %w", err)
			}
			if err != nil {
				return err
			}
			app.Progress("Deploy strategy of %s saved: %s.", projectIn(p, env), runr.StrategyLabel(s.Strategy))
			return app.Printer().Print(s, func(t *output.TableWriter) { strategyDetail(t, p, s) })
		},
	}
	f := cmd.Flags()
	f.BoolVar(&autoPromote, "auto-promote", false, "blue-green: promote once the health checks pass (false: wait for runr rollout promote)")
	f.StringVar(&healthPath, "health-path", "", "blue-green: path the health checks call, e.g. /healthz (off: readiness alone)")
	f.IntVar(&healthChecks, "health-checks", 3, "blue-green: 2xx answers in a row that auto promotion needs (1-20, one every 10 s)")
	f.StringVar(&keepPrevious, "keep-previous", "", "blue-green: how long the previous revision stays warm after promotion, minutes or a duration such as 15m (0-24h)")
	f.StringVar(&steps, "steps", "", "canary: traffic percentages of the new revision, e.g. 10,50,100 (1-10 increasing steps)")
	f.StringVar(&interval, "interval", "", "canary: how long each step lasts, minutes or a duration such as 5m (1m-24h)")
	f.StringVar(&maxRate, "max-error-rate", "", "canary: roll back above this error rate in percent, e.g. 5 (off: not checked)")
	f.StringVar(&maxP95, "max-p95", "", "canary: roll back above this p95 latency, milliseconds or a duration such as 800ms (off: not checked)")
	addEnvFlag(cmd, &envName)
	return cmd
}

// offValue reports whether a flag value clears a setting.
func offValue(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "", "off", "none", "null":
		return true
	}
	return false
}

// parseMinutes reads minutes ("15") or a duration of whole minutes ("15m", "1h30m").
func parseMinutes(flag, v string, min, max int) (int, error) {
	v = strings.TrimSpace(v)
	n, err := strconv.Atoi(v)
	if err != nil {
		d, derr := time.ParseDuration(v)
		if derr != nil || d%time.Minute != 0 {
			return 0, usageErrorf("%s %q is not minutes or a duration of whole minutes such as 15m", flag, v)
		}
		n = int(d / time.Minute)
	}
	if n < min || n > max {
		return 0, usageErrorf("%s must be between %d and %d minutes", flag, min, max)
	}
	return n, nil
}

// parseSteps reads canary steps: "10,50,100" (a % sign is allowed).
func parseSteps(v string) ([]int, error) {
	var out []int
	for _, part := range strings.Split(v, ",") {
		n, err := strconv.Atoi(strings.TrimSpace(strings.TrimSuffix(strings.TrimSpace(part), "%")))
		if err != nil {
			return nil, usageErrorf("--steps %q is not a list of percentages such as 10,50,100", v)
		}
		out = append(out, n)
	}
	if err := projectfile.ValidateCanarySteps(out); err != nil {
		return nil, usageErrorf("--steps %v", err)
	}
	return out, nil
}

// parseErrorRate reads --max-error-rate: a percentage ("5", "0.5%") or off.
func parseErrorRate(v string) (*runr.Nullable[float64], error) {
	if offValue(v) {
		return runr.NullOf[float64](), nil
	}
	f, err := strconv.ParseFloat(strings.TrimSpace(strings.TrimSuffix(strings.TrimSpace(v), "%")), 64)
	if err != nil || f < 0 || f > 100 {
		return nil, usageErrorf("--max-error-rate must be a percentage between 0 and 100, or off")
	}
	return runr.NullableOf(f), nil
}

// parseP95 reads --max-p95: milliseconds ("800"), a duration ("800ms", "1.5s") or off.
func parseP95(v string) (*runr.Nullable[int], error) {
	if offValue(v) {
		return runr.NullOf[int](), nil
	}
	v = strings.TrimSpace(v)
	n, err := strconv.Atoi(v)
	if err != nil {
		d, derr := time.ParseDuration(v)
		if derr != nil || d%time.Millisecond != 0 {
			return nil, usageErrorf("--max-p95 %q is not milliseconds or a duration such as 800ms", v)
		}
		n = int(d / time.Millisecond)
	}
	if n < 1 || n > 600000 {
		return nil, usageErrorf("--max-p95 must be between 1 ms and 10 minutes")
	}
	return runr.NullableOf(n), nil
}

// strategyDetail renders the deploy strategy settings.
func strategyDetail(t *output.TableWriter, p *runr.Project, s *runr.DeployStrategy) {
	t.KV("Project", p.Name)
	if s.OfEnvironment() {
		settings := "its own over the project's (" + runr.StrategyLabel(s.Project) + ")"
		if s.Inherited {
			settings = "the project's"
		}
		t.KV("Environment", s.Environment+": "+settings)
	}
	t.KV("Strategy", runr.StrategyLabel(s.Strategy))
	bg := s.BlueGreen
	promotion := "manual (runr rollout promote)"
	if bg.AutoPromote {
		promotion = "automatic once the new revision is ready"
		if bg.HealthPath != nil {
			promotion = fmt.Sprintf("automatic after %d health checks in a row", bg.HealthChecks)
		}
	}
	t.KV("Blue-green promotion", promotion)
	health := "readiness only"
	if bg.HealthPath != nil {
		health = fmt.Sprintf("%s, %d checks in a row", *bg.HealthPath, bg.HealthChecks)
	}
	t.KV("Blue-green health", health)
	previous := "released at promotion"
	if bg.KeepPreviousMinutes > 0 {
		previous = "kept warm " + minutesLabel(bg.KeepPreviousMinutes) + " after promotion"
	}
	t.KV("Blue-green previous", previous)
	c := s.Canary
	stepTexts := make([]string, len(c.Steps))
	for i, st := range c.Steps {
		stepTexts[i] = strconv.Itoa(st) + " %"
	}
	t.KV("Canary steps", joinOrDash(stepTexts)+", "+minutesLabel(c.IntervalMinutes)+" each")
	rate, p95 := "not checked", "not checked"
	if c.ErrorRateThreshold != nil {
		rate = strconv.FormatFloat(*c.ErrorRateThreshold, 'f', -1, 64) + " %"
	}
	if c.P95ThresholdMs != nil {
		p95 = strconv.Itoa(*c.P95ThresholdMs) + " ms"
	}
	t.KV("Canary limits", "error rate "+rate+", p95 "+p95)
	switch {
	case !s.Eligible:
		t.Note("Deploys of %s stay direct: strategies apply to http and workflow triggers (this project: %s).", p.Name, output.Dash(s.TriggerType))
	case s.Strategy == runr.StrategyCanary && !s.Tracing:
		t.Note("The project has no tracing: the canary's limits are checked against Knative's request metrics of the new revision where the region's Prometheus has them; otherwise the canary moves on time only.")
	}
}

// minutesLabel renders minutes: "15m", "1h", "1h30m".
func minutesLabel(n int) string {
	switch {
	case n < 60:
		return strconv.Itoa(n) + "m"
	case n%60 == 0:
		return strconv.Itoa(n/60) + "h"
	}
	return fmt.Sprintf("%dh%dm", n/60, n%60)
}
