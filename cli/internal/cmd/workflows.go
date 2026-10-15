package cmd

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"gopkg.in/yaml.v3"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerCommand(newWorkflowsCmd) }

func newWorkflowsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "workflows",
		Aliases: []string{"workflow", "wf"},
		Short:   "Durable workflows: definitions, runs and events",
		Long: "Durable workflows of a project (docs/workflows.md): list the definitions the engine knows,\n" +
			"start runs and follow them, cancel, retry and replay runs, pause and resume workflows,\n" +
			"send events of the organization that trigger workflows, manage the function the engine\n" +
			"drives (endpoint, signing key) and read the usage.",
		Example: "  runr workflows ls\n" +
			"  runr workflows run process-order --event @order.json --wait\n" +
			"  runr workflows runs --status failed\n" +
			"  runr workflows run replay 01J… --from-step ship\n" +
			"  runr workflows events send order.created --data '{\"order_id\":\"o-1\"}'",
		Args: cobra.NoArgs,
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the project's workflows with their triggers and runs",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, wa, err := c.ListWorkflows(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return printWorkflowList(app, p, list, wa, "")
		},
	}
	cmd.RunE = ls.RunE
	cmd.AddCommand(ls, newWfGetCmd(app), newWfRunsCmd(app), newWfRunCmd(app),
		newWfActiveCmd(app, true), newWfActiveCmd(app, false), newWfSyncCmd(app), newWfDeclareCmd(app),
		newWfEventsCmd(app), newWfAppCmd(app), newWfUsageCmd(app))
	return cmd
}

func printWorkflowList(app *App, p *runr.Project, list []runr.Workflow, wa *runr.WorkflowApp, note string) error {
	if list == nil {
		list = []runr.Workflow{}
	}
	return app.Printer().Print(map[string]any{"workflows": list, "app": wa}, func(t *output.TableWriter) {
		t.Header("NAME", "STATE", "TRIGGERS", "RUNS", "LAST RUN", "SOURCE")
		for _, w := range list {
			t.Row(w.Name, w.State(), output.Truncate(wfTriggers(w.Triggers), 60), wfCounts(w.RunCounts),
				wfAge(w.LastRunAt), output.Dash(w.Source))
		}
		if note != "" {
			t.Note("%s", note)
		}
		if len(list) == 0 {
			t.Note("%s has no workflows. Deploy it with the workflow trigger, or run `runr workflows sync`.", p.Name)
		}
		if wa != nil && wa.SyncStatus == "error" && wa.SyncError != "" {
			t.Note("The last sync failed: %s", wa.SyncError)
		}
	})
}

func newWfGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get WORKFLOW",
		Short: "Show a workflow definition (name or id)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			w, err := c.FindWorkflow(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(w, func(t *output.TableWriter) { workflowDetail(t, w) })
		},
	}
}

func workflowDetail(t *output.TableWriter, w *runr.Workflow) {
	t.KV("Workflow", w.Name)
	t.KV("State", w.State())
	if w.Description != "" {
		t.KV("Description", w.Description)
	}
	t.KV("Source", output.Dash(w.Source))
	if len(w.Triggers) == 0 {
		t.KV("Triggers", "none (API and step.invoke only)")
	}
	for i, tr := range w.Triggers {
		label := ""
		if i == 0 {
			label = "Triggers"
		}
		t.KV(label, tr.String())
	}
	t.KV("Retries", fmt.Sprintf("%d per step", w.Retries))
	t.KV("Timeout", output.Dash(w.Timeout))
	if len(w.Concurrency) > 0 {
		var parts []string
		for _, l := range w.Concurrency {
			if l.Key != "" {
				parts = append(parts, fmt.Sprintf("%d per %s", l.Limit, l.Key))
			} else {
				parts = append(parts, fmt.Sprintf("%d", l.Limit))
			}
		}
		t.KV("Concurrency", strings.Join(parts, ", "))
	}
	if w.Idempotency != "" {
		t.KV("Idempotency", w.Idempotency)
	}
	for i, co := range w.CancelOn {
		label := ""
		if i == 0 {
			label = "Cancel on"
		}
		s := co.Event
		if co.Expression != "" {
			s += " if " + co.Expression
		}
		t.KV(label, s)
	}
	t.KV("Runs", wfCounts(w.RunCounts))
	t.KV("Last run", wfAge(w.LastRunAt))
	if w.SyncedAt != "" {
		t.KV("Synced", wfAgo(w.SyncedAt))
	}
}

// ---------------------------------------------------------------------------------------
// Runs

func newWfRunsCmd(app *App) *cobra.Command {
	var (
		statuses              []string
		source, search, since string
		from, to              string
		limit                 int
	)
	list := func(cmd *cobra.Command, args []string) error {
		if limit < 1 || limit > 100 {
			return usageErrorf("--limit must be between 1 and 100")
		}
		f := runr.RunFilter{Status: statuses, Source: source, Query: search, From: from, To: to, PerPage: limit}
		if since != "" {
			d, err := time.ParseDuration(since)
			if err != nil || d <= 0 {
				return usageErrorf("--since %q is not a duration such as 30m or 24h", since)
			}
			f.From = time.Now().Add(-d).UTC().Format(time.RFC3339)
		}
		c, p, err := app.Project(cmd.Context())
		if err != nil {
			return err
		}
		if len(args) == 1 {
			w, err := c.FindWorkflow(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			f.Workflow = w.Name
		}
		runs, page, err := c.ListWorkflowRuns(cmd.Context(), p.ID, f)
		if err != nil {
			return err
		}
		return app.Printer().Print(runs, func(t *output.TableWriter) {
			t.Header("RUN", "WORKFLOW", "STATUS", "SOURCE", "EVENT", "STARTED", "DURATION")
			for _, r := range runs {
				t.Row(r.ID, r.Workflow.Name, r.Status, output.Dash(r.TriggerSource), output.Dash(r.EventName),
					wfAge(wfFirst(r.StartedAt, r.CreatedAt)), wfDuration(r.DurationMs))
			}
			if page != nil && page.Total > len(runs) {
				t.Note("%d of %d runs; narrow the list with --status, --since or --search, or raise --limit.", len(runs), page.Total)
			}
			if len(runs) == 0 {
				t.Note("No runs.")
			}
		})
	}
	cmd := &cobra.Command{
		Use:   "runs [WORKFLOW]",
		Short: "List the newest runs of the project or of one workflow",
		Example: "  runr workflows runs\n" +
			"  runr workflows runs process-order --status failed,timed_out --since 24h\n" +
			"  runr workflows runs get 01J…",
		Args: cobra.MaximumNArgs(1),
		RunE: list,
	}
	f := cmd.Flags()
	f.StringSliceVar(&statuses, "status", nil, "only runs with these statuses: queued, running, sleeping, waiting, completed, failed, cancelled, timed_out")
	f.StringVar(&source, "source", "", "only runs started by api, event, cron, queue, invoke, replay or test")
	f.StringVar(&search, "search", "", "match a run id, event id, idempotency key or event text")
	f.StringVar(&since, "since", "", "only runs created within this duration (e.g. 24h)")
	f.StringVar(&from, "from", "", "only runs created at or after this time (ISO 8601)")
	f.StringVar(&to, "to", "", "only runs created at or before this time (ISO 8601)")
	f.IntVar(&limit, "limit", 25, "how many runs to show (1-100)")
	ls := &cobra.Command{Use: "ls [WORKFLOW]", Aliases: []string{"list"}, Short: "List runs (like `runr workflows runs`)",
		Args: cobra.MaximumNArgs(1), RunE: list}
	ls.Flags().AddFlagSet(f)
	cmd.AddCommand(ls, newWfRunGetCmd(app), newWfRunCancelCmd(app), newWfRunRetryCmd(app), newWfRunReplayCmd(app))
	return cmd
}

func newWfRunCmd(app *App) *cobra.Command {
	var event, idempotencyKey string
	var test, wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "run WORKFLOW",
		Short: "Start a run of a workflow (get, cancel, retry, replay manage runs)",
		Long: "Start a run of a workflow with --event as the trigger event's data (a JSON object, inline,\n" +
			"@file.json or - for stdin). --wait follows the run until it ends and exits with code 6 when it\n" +
			"does not complete. A second start with the same --idempotency-key answers the first run.",
		Example: "  runr workflows run process-order --event '{\"order_id\":\"o-1\"}'\n" +
			"  runr workflows run process-order --event @order.json --wait --timeout 30m\n" +
			"  runr workflows run get 01J…",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			data, err := readJSONArg(app, "--event", event)
			if err != nil {
				return err
			}
			if data != nil && !wfIsObject(data) {
				return usageErrorf("--event must be a JSON object")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			w, err := c.FindWorkflow(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			run, created, err := c.StartWorkflowRun(ctx, p.ID, w.Name, runr.StartRunInput{Data: data, IdempotencyKey: idempotencyKey, Test: test})
			if err != nil {
				return err
			}
			if created {
				app.Progress("Started run %s of %s.", run.ID, w.Name)
			} else {
				app.Progress("Run %s of %s already exists for idempotency key %q.", run.ID, w.Name, idempotencyKey)
			}
			return wfFinish(ctx, app, c, p, run, wait, timeout)
		},
	}
	f := cmd.Flags()
	f.StringVar(&event, "event", "", "the trigger event's data: a JSON object, @file.json or - (stdin)")
	f.StringVar(&event, "data", "", "same as --event")
	_ = f.MarkHidden("data")
	f.StringVar(&idempotencyKey, "idempotency-key", "", "start at most one run for this key")
	f.BoolVar(&test, "test", false, "mark the run as a test run")
	f.BoolVar(&wait, "wait", false, "follow the run until it ends")
	f.DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	cmd.AddCommand(newWfRunGetCmd(app), newWfRunCancelCmd(app), newWfRunRetryCmd(app), newWfRunReplayCmd(app))
	return cmd
}

// wfFinish prints a run, or with wait follows it first and returns the outcome as the error.
func wfFinish(ctx context.Context, app *App, c *runr.Client, p *runr.Project, run *runr.WorkflowRun, wait bool, timeout time.Duration) error {
	if !wait {
		return printWfRun(app, run)
	}
	final, err := c.WaitWorkflowRun(ctx, p.ID, run, app.waitOptions(timeout, "Run "+run.ID))
	if final != nil {
		if perr := printWfRun(app, final); perr != nil && err == nil {
			err = perr
		}
	}
	return err
}

func newWfRunGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get RUN_ID",
		Short: "Show a run with its event, output, error and steps",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			run, err := c.GetWorkflowRun(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			return printWfRun(app, run)
		},
	}
}

func newWfRunCancelCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:   "cancel RUN_ID",
		Short: "Cancel an active run (open sleeps and waits, invoked child runs too)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if err := app.confirm("Cancel run "+args[0]+" of "+p.Name, yes); err != nil {
				return err
			}
			run, err := c.CancelWorkflowRun(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			app.Progress("Cancelled run %s.", run.ID)
			return printWfRun(app, run)
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newWfRunRetryCmd(app *App) *cobra.Command {
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "retry RUN_ID",
		Short: "Run a failed, cancelled or timed-out run again in place (completed steps stay)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			run, err := c.RetryWorkflowRun(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			app.Progress("Retrying run %s.", run.ID)
			return wfFinish(ctx, app, c, p, run, wait, timeout)
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "follow the run until it ends")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	return cmd
}

func newWfRunReplayCmd(app *App) *cobra.Command {
	var fromStep string
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "replay RUN_ID",
		Short: "Start a new run with the event of a finished run (--from-step keeps the steps before it)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			run, err := c.ReplayWorkflowRun(ctx, p.ID, args[0], fromStep)
			if err != nil {
				return err
			}
			app.Progress("Replay of %s started as run %s.", args[0], run.ID)
			return wfFinish(ctx, app, c, p, run, wait, timeout)
		},
	}
	cmd.Flags().StringVar(&fromStep, "from-step", "", "step id to replay from; the memoized steps before it are kept")
	cmd.Flags().BoolVar(&wait, "wait", false, "follow the new run until it ends")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	return cmd
}

func printWfRun(app *App, r *runr.WorkflowRun) error {
	return app.Printer().Print(r, func(t *output.TableWriter) {
		t.KV("Run", r.ID)
		t.KV("Workflow", output.Dash(r.Workflow.Name))
		t.KV("Status", r.Status)
		t.KV("Source", output.Dash(r.TriggerSource))
		if r.EventName != "" {
			t.KV("Event", r.EventName)
		}
		if r.IdempotencyKey != "" {
			t.KV("Idempotency key", r.IdempotencyKey)
		}
		if r.ReplayOfRunID != "" {
			from := r.ReplayOfRunID
			if r.ReplayFromStep != "" {
				from += " from step " + r.ReplayFromStep
			}
			t.KV("Replay of", from)
		}
		if r.RetryCount > 0 {
			t.KV("Retries", fmt.Sprintf("%d", r.RetryCount))
		}
		t.KV("Started", wfAgo(wfFirst(r.StartedAt, r.CreatedAt)))
		if r.EndedAt != "" {
			t.KV("Duration", wfDuration(r.DurationMs))
		}
		if msg := r.ErrorMessage(); msg != "" {
			t.KV("Error", msg)
		}
		if out := wfCompact(r.Output); out != "" {
			t.KV("Output", output.Truncate(out, 200))
		}
		if len(r.Steps) > 0 {
			t.Header("#", "STEP", "TYPE", "STATUS", "ATTEMPT", "DURATION", "ERROR")
			steps := append([]runr.WorkflowStep{}, r.Steps...)
			sort.SliceStable(steps, func(i, j int) bool { return steps[i].Position < steps[j].Position })
			for _, s := range steps {
				attempt := "-"
				if s.Attempt > 0 {
					attempt = fmt.Sprintf("%d/%d", s.Attempt, s.MaxAttempts)
				}
				t.Row(fmt.Sprintf("%d", s.Position), s.StepKey, s.Type, s.Status, attempt, wfDuration(s.DurationMs),
					output.Truncate(output.Dash(s.ErrorMessage()), 60))
			}
		}
	})
}

// ---------------------------------------------------------------------------------------
// Definitions

func newWfActiveCmd(app *App, active bool) *cobra.Command {
	use, alias, short := "enable", "resume", "Resume a paused workflow: triggers and the API start runs again"
	if !active {
		use, alias, short = "disable", "pause", "Pause a workflow: triggers and the API start no runs; runs in progress continue"
	}
	return &cobra.Command{
		Use:     use + " WORKFLOW",
		Aliases: []string{alias},
		Short:   short,
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			w, err := c.FindWorkflow(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			w, err = c.SetWorkflowActive(cmd.Context(), p.ID, w.Name, active)
			if err != nil {
				return err
			}
			app.Progress("Workflow %s is %s.", w.Name, w.State())
			return app.Printer().Print(w, func(t *output.TableWriter) { workflowDetail(t, w) })
		},
	}
}

func newWfSyncCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "sync",
		Short: "Read the workflow definitions from the deployed function",
		Long: "Ask the deployed function (a signed introspect request) which workflows it serves; when it\n" +
			"cannot answer, runr.workflows.json of the latest version is used. RunR syncs by itself after a\n" +
			"deployment of a project with the workflow trigger becomes active.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			res, err := c.SyncWorkflows(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			if app.Printer().Machine() {
				return app.Printer().Print(res, nil)
			}
			return printWorkflowList(app, p, res.Data, &res.App, "Synced from "+wfSyncSource(res.Source)+".")
		},
	}
}

func wfSyncSource(s string) string {
	switch s {
	case "sdk":
		return "the function (SDK)"
	case "manifest":
		return "runr.workflows.json"
	}
	return output.Dash(s)
}

func newWfDeclareCmd(app *App) *cobra.Command {
	var file string
	var yes bool
	cmd := &cobra.Command{
		Use:   "declare -f FILE",
		Short: "Declare the project's workflow definitions through the API",
		Long: "Set the project's workflow definitions from a file: JSON or YAML, a list of definitions or\n" +
			"{\"workflows\": [...]} (the format of runr.workflows.json, docs/workflows.md § Options).\n" +
			"Workflows that the file does not list are archived: their runs stay, they start no new runs.\n" +
			"A project whose function serves the SDK gets its definitions from the code again at the next\n" +
			"sync.",
		Example: "  runr workflows declare -f runr.workflows.json",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			data, err := readFileArg(app, file)
			if err != nil {
				return err
			}
			defs, err := parseWorkflowDefinitions(data)
			if err != nil {
				return &usageError{msg: file + ": " + err.Error()}
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			current, _, err := c.ListWorkflows(ctx, p.ID)
			if err != nil {
				return err
			}
			declared := map[string]bool{}
			for _, d := range defs {
				if n, ok := d["name"].(string); ok {
					declared[n] = true
				}
			}
			var archive []string
			for _, w := range current {
				if !w.Archived && !declared[w.Name] {
					archive = append(archive, w.Name)
				}
			}
			if len(archive) > 0 {
				if err := app.confirm("Archive "+strings.Join(archive, ", ")+" of "+p.Name, yes); err != nil {
					return err
				}
			}
			res, err := c.DeclareWorkflows(ctx, p.ID, defs)
			if err != nil {
				return err
			}
			app.Progress("Declared %s for %s.", countLabel(len(defs), "workflow"), p.Name)
			if app.Printer().Machine() {
				return app.Printer().Print(res, nil)
			}
			return printWorkflowList(app, p, res.Data, &res.App, "")
		},
	}
	cmd.Flags().StringVarP(&file, "file", "f", "", "definitions file (JSON or YAML; - for stdin)")
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "archive workflows the file does not list without asking")
	_ = cmd.MarkFlagRequired("file")
	return cmd
}

// parseWorkflowDefinitions reads a definitions file: JSON or YAML, a list or {"workflows": […]}.
func parseWorkflowDefinitions(data []byte) ([]map[string]any, error) {
	var doc any
	if err := json.Unmarshal(data, &doc); err != nil {
		if yerr := yaml.Unmarshal(data, &doc); yerr != nil {
			return nil, fmt.Errorf("neither JSON nor YAML: %v", yerr)
		}
		// Through JSON so YAML maps become map[string]any like JSON objects.
		enc, err := json.Marshal(doc)
		if err != nil {
			return nil, fmt.Errorf("the YAML holds values JSON cannot represent: %v", err)
		}
		doc = nil
		if err := json.Unmarshal(enc, &doc); err != nil {
			return nil, err
		}
	}
	if m, ok := doc.(map[string]any); ok {
		doc = m["workflows"]
	}
	list, ok := doc.([]any)
	if !ok {
		return nil, fmt.Errorf("expected a list of workflow definitions or {\"workflows\": [...]}")
	}
	defs := make([]map[string]any, 0, len(list))
	for i, it := range list {
		m, ok := it.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("definition #%d is not an object", i+1)
		}
		if n, _ := m["name"].(string); n == "" {
			return nil, fmt.Errorf("definition #%d has no name", i+1)
		}
		defs = append(defs, m)
	}
	return defs, nil
}

// ---------------------------------------------------------------------------------------
// Events

func newWfEventsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "events",
		Aliases: []string{"event"},
		Short:   "Send and list the organization's events",
		Long: "Events belong to the organization: every workflow with a matching event trigger starts a\n" +
			"run, waiting steps resume and cancel_on cancels matching runs. Sending needs events.send,\n" +
			"listing workflows.view.",
		Args: cobra.NoArgs,
	}
	var data, id string
	var ts int64
	var test bool
	send := &cobra.Command{
		Use:   "send NAME",
		Short: "Send an event",
		Example: "  runr workflows events send order.created --data '{\"order_id\":\"o-1\"}' --id order-o-1\n" +
			"  runr workflows events send order.shipped --data @shipped.json",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			raw, err := readJSONArg(app, "--data", data)
			if err != nil {
				return err
			}
			if raw != nil && !wfIsObject(raw) {
				return usageErrorf("--data must be a JSON object")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			res, err := c.SendEvents(cmd.Context(), []runr.EventInput{{Name: args[0], Data: raw, ID: id, TS: ts}}, test)
			if err != nil {
				return err
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				t.Header("EVENT", "NAME", "DUPLICATE", "RUNS STARTED", "RESUMED", "CANCELLED")
				for _, e := range res.Events {
					t.Row(e.ID, e.Name, yesNo(e.Duplicate), joinOrDash(e.Runs), fmt.Sprintf("%d", e.Resumed), fmt.Sprintf("%d", e.Cancelled))
				}
			})
		},
	}
	send.Flags().StringVar(&data, "data", "", "event data: a JSON object, @file.json or - (stdin)")
	send.Flags().StringVar(&id, "id", "", "deduplication id: an id sent before answers the original event")
	send.Flags().Int64Var(&ts, "ts", 0, "event time in Unix milliseconds (default: now)")
	send.Flags().BoolVar(&test, "test", false, "mark it as a test event")

	var name, source, since, from, to string
	var limit int
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List received events, newest first (-p limits them to a project)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if limit < 1 || limit > 100 {
				return usageErrorf("--limit must be between 1 and 100")
			}
			f := runr.EventFilter{Name: name, Source: source, From: from, To: to, PerPage: limit}
			if since != "" {
				d, err := time.ParseDuration(since)
				if err != nil || d <= 0 {
					return usageErrorf("--since %q is not a duration such as 30m or 24h", since)
				}
				f.From = time.Now().Add(-d).UTC().Format(time.RFC3339)
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if app.ProjectRef != "" {
				p, err := c.FindProject(cmd.Context(), app.ProjectRef)
				if err != nil {
					return err
				}
				f.ProjectID = p.ID
			}
			events, page, err := c.ListEvents(cmd.Context(), f)
			if err != nil {
				return err
			}
			return app.Printer().Print(events, func(t *output.TableWriter) {
				t.Header("EVENT", "NAME", "SOURCE", "RUNS", "RESUMED", "CANCELLED", "RECEIVED")
				for _, e := range events {
					t.Row(e.ID, e.Name, output.Dash(e.Source), fmt.Sprintf("%d", e.RunsStarted), fmt.Sprintf("%d", e.WaitsResumed),
						fmt.Sprintf("%d", e.RunsCancelled), wfAge(e.CreatedAt))
				}
				if page != nil && page.Total > len(events) {
					t.Note("%d of %d events; narrow the list with --name, --source or --since, or raise --limit.", len(events), page.Total)
				}
				if len(events) == 0 {
					t.Note("No events.")
				}
			})
		},
	}
	lf := ls.Flags()
	lf.StringVar(&name, "name", "", "only events whose name starts with this")
	lf.StringVar(&source, "source", "", "only events from api, workflow, queue, cron or test")
	lf.StringVar(&since, "since", "", "only events received within this duration (e.g. 1h)")
	lf.StringVar(&from, "from", "", "only events received at or after this time (ISO 8601)")
	lf.StringVar(&to, "to", "", "only events received at or before this time (ISO 8601)")
	lf.IntVar(&limit, "limit", 25, "how many events to show (1-100)")

	get := &cobra.Command{
		Use:   "get EVENT_ID",
		Short: "Show an event with its data and the runs it started",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			e, runs, err := c.GetEvent(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"event": e, "runs": runs}, func(t *output.TableWriter) {
				t.KV("Event", e.ID)
				t.KV("Name", e.Name)
				t.KV("Source", output.Dash(e.Source))
				if e.ExternalID != "" {
					t.KV("Id", e.ExternalID)
				}
				t.KV("Received", wfAgo(e.CreatedAt))
				t.KV("Data", output.Truncate(output.Dash(wfCompact(e.Data)), 200))
				t.KV("Effects", fmt.Sprintf("%d runs started, %d waits resumed, %d runs cancelled", e.RunsStarted, e.WaitsResumed, e.RunsCancelled))
				if len(runs) > 0 {
					t.Header("RUN", "WORKFLOW", "STATUS")
					for _, r := range runs {
						t.Row(r.ID, r.Workflow.Name, r.Status)
					}
				}
			})
		},
	}
	cmd.AddCommand(send, ls, get)
	cmd.RunE = ls.RunE
	return cmd
}

// ---------------------------------------------------------------------------------------
// Rendering

func wfTriggers(ts []runr.WorkflowTrigger) string {
	if len(ts) == 0 {
		return "-"
	}
	parts := make([]string, len(ts))
	for i, t := range ts {
		parts[i] = t.String()
	}
	return strings.Join(parts, "; ")
}

func wfCounts(c runr.RunCounts) string {
	if len(c) == 0 {
		return "-"
	}
	keys := make([]string, 0, len(c))
	for k := range c {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		parts = append(parts, fmt.Sprintf("%d %s", c[k], k))
	}
	return strings.Join(parts, ", ")
}

// wfAge renders an API timestamp (ISO 8601 or "2006-01-02 15:04:05") as an age.
func wfAge(s string) string {
	if s == "" {
		return "-"
	}
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02 15:04:05", "2006-01-02T15:04:05"} {
		if t, err := time.Parse(layout, s); err == nil {
			return output.Age(&t)
		}
	}
	return s
}

// wfAgo renders an API timestamp as "5m ago" ("-" without one).
func wfAgo(s string) string {
	if a := wfAge(s); a != "-" {
		return a + " ago"
	}
	return "-"
}

func wfDuration(ms *int64) string {
	if ms == nil {
		return "-"
	}
	if *ms < 1000 {
		return fmt.Sprintf("%dms", *ms)
	}
	d := time.Duration(*ms) * time.Millisecond
	if d < time.Minute {
		return fmt.Sprintf("%.1fs", d.Seconds())
	}
	return output.Duration(d)
}

func wfFirst(vals ...string) string {
	for _, v := range vals {
		if v != "" {
			return v
		}
	}
	return ""
}

// wfCompact renders a JSON value on one line ("" for none).
func wfCompact(raw json.RawMessage) string {
	t := bytes.TrimSpace(raw)
	if len(t) == 0 || bytes.Equal(t, []byte("null")) {
		return ""
	}
	var b bytes.Buffer
	if json.Compact(&b, t) != nil {
		return string(t)
	}
	return b.String()
}

func wfIsObject(raw json.RawMessage) bool {
	t := bytes.TrimSpace(raw)
	return len(t) > 0 && t[0] == '{'
}
