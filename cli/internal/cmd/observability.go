package cmd

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// Observability: runr traces (with request replay), runr uptime, runr alerts and
// runr status-page (docs/observability.md).

func init() {
	registerCommand(newTracesCmd)
	registerCommand(newUptimeCmd)
	registerCommand(newAlertsCmd)
	registerCommand(newStatusPageCmd)
}

// ---------------------------------------------------------------------------------------
// runr traces

type obsTraceFlags struct {
	since, until, status, path string
	minDuration                time.Duration
	limit                      int
}

func (f *obsTraceFlags) register(c *cobra.Command) {
	fs := c.Flags()
	fs.StringVar(&f.since, "since", "1h", "traces from this time on: a duration back from now (15m, 6h, 7d) or RFC 3339")
	fs.StringVar(&f.until, "until", "", "traces up to this time: a duration back from now or RFC 3339 (default: now)")
	fs.StringVar(&f.status, "status", "", "all, error (5xx and timeouts) or ok")
	fs.DurationVar(&f.minDuration, "min-duration", 0, "only traces whose server span took at least this long (250ms)")
	fs.StringVar(&f.path, "path", "", "part of the request path")
	fs.IntVar(&f.limit, "limit", 50, "at most this many traces (1-200)")
}

func (f *obsTraceFlags) filter() (runr.TraceFilter, error) {
	out := runr.TraceFilter{Status: f.status, Path: f.path, Limit: f.limit}
	switch f.status {
	case "", runr.TraceStatusAll, runr.TraceStatusError, runr.TraceStatusOK:
	default:
		return out, usageErrorf("--status must be all, error or ok")
	}
	if f.limit < 1 || f.limit > 200 {
		return out, usageErrorf("--limit must be between 1 and 200")
	}
	if f.minDuration < 0 {
		return out, usageErrorf("--min-duration must not be negative")
	}
	out.MinDurationMs = float64(f.minDuration) / float64(time.Millisecond)
	var err error
	if out.From, err = obsTime("--since", f.since); err != nil {
		return out, err
	}
	if out.To, err = obsTime("--until", f.until); err != nil {
		return out, err
	}
	if !out.From.IsZero() && !out.To.IsZero() && !out.From.Before(out.To) {
		return out, usageErrorf("--since must be before --until")
	}
	return out, nil
}

// obsTime parses an RFC 3339 time or a duration back from now ("" = unset).
func obsTime(flag, v string) (time.Time, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		return time.Time{}, nil
	}
	if t, err := time.Parse(time.RFC3339, v); err == nil {
		return t, nil
	}
	if d, err := parseDays(v); err == nil {
		return time.Now().Add(-d), nil
	}
	return time.Time{}, usageErrorf("%s %q is neither a duration (15m, 6h, 7d) nor an RFC 3339 time", flag, v)
}

// obsMs renders milliseconds with one unit.
func obsMs(ms float64) string {
	switch {
	case ms < 1:
		return fmt.Sprintf("%.2fms", ms)
	case ms < 1000:
		return strconv.FormatFloat(math.Round(ms*10)/10, 'f', -1, 64) + "ms"
	default:
		return strconv.FormatFloat(math.Round(ms/10)/100, 'f', -1, 64) + "s"
	}
}

func obsMsPtr(ms *float64) string {
	if ms == nil {
		return "-"
	}
	return obsMs(*ms)
}

func obsIntPtr(v *int) string {
	if v == nil {
		return "-"
	}
	return strconv.Itoa(*v)
}

func obsPercent(v *float64) string {
	if v == nil {
		return "-"
	}
	return strconv.FormatFloat(*v, 'f', -1, 64) + "%"
}

func obsClock(ms int64) string {
	if ms <= 0 {
		return "-"
	}
	return time.UnixMilli(ms).Format("2006-01-02 15:04:05")
}

func obsOnOff(b bool) string {
	if b {
		return "on"
	}
	return "off"
}

func newTracesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "traces",
		Aliases: []string{"trace"},
		Short:   "Search and show the traces of a project; switch tracing and request replay",
		Long: "Traces are read from the tracing backend of the project's region (OpenTelemetry Collector\n" +
			"and Tempo). Switch tracing on with `runr traces enable`; it applies with the next deployment,\n" +
			"which runs at once unless --no-deploy is given. Reading traces needs traces.view, the log\n" +
			"lines of a trace (--logs) also logs.view. A region whose backend RunR cannot query (external\n" +
			"or off) answers with exit code 5.",
		Example: "  runr traces --status error --since 6h\n" +
			"  runr traces get 4bf92f3577b34da6a3ce929d0e0e4736 --logs\n" +
			"  runr traces enable --sample-ratio 0.2",
	}
	var f obsTraceFlags
	list := func(cmd *cobra.Command, args []string) error {
		filter, err := f.filter()
		if err != nil {
			return err
		}
		c, p, err := app.Project(cmd.Context())
		if err != nil {
			return err
		}
		res, err := c.SearchTraces(cmd.Context(), p.ID, filter)
		if err != nil {
			return err
		}
		return app.Printer().Print(res, func(t *output.TableWriter) {
			t.Header("TRACE ID", "START", "ROOT", "PATH", "STATUS", "DURATION", "COLD START")
			for _, tr := range res.Traces {
				status := obsIntPtr(tr.StatusCode)
				if tr.Error {
					status += " error"
				}
				root := tr.RootName
				if tr.RootService != "" {
					root = tr.RootService + ": " + root
				}
				cold := ""
				if tr.ColdStart == true || tr.ColdStart == "true" {
					cold = "yes"
				}
				t.Row(tr.TraceID, obsClock(tr.StartMs), output.Dash(output.Truncate(root, 40)), output.Dash(tr.Path),
					status, obsMsPtr(tr.DurationMs), cold)
			}
			if len(res.Traces) == 0 {
				t.Note("No traces in this time range. Tracing is switched on with `runr traces enable`.")
			}
		})
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list", "search"},
		Short:   "Search traces (newest first)",
		Args:    cobra.NoArgs,
		RunE:    list,
	}
	f.register(ls)
	f.register(cmd)
	cmd.Args = cobra.NoArgs
	cmd.RunE = list
	cmd.AddCommand(ls)

	var withLogs bool
	get := &cobra.Command{
		Use:   "get TRACE_ID",
		Short: "Show a trace as a waterfall (--logs adds its log lines)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if !runr.ValidTraceID(args[0]) {
				return usageErrorf("%q is not a trace id (32 hexadecimal digits)", args[0])
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			tr, err := c.GetTrace(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			var lines []runr.TraceLogLine
			if withLogs {
				if lines, err = c.TraceLogs(cmd.Context(), p.ID, args[0]); err != nil {
					return err
				}
			}
			var v any = tr
			if withLogs {
				v = map[string]any{"trace": tr, "logs": lines}
			}
			return app.Printer().Print(v, func(t *output.TableWriter) { obsTraceTable(t, tr, lines, withLogs) })
		},
	}
	get.Flags().BoolVar(&withLogs, "logs", false, "also print the log lines that carry the trace id (needs logs.view)")
	cmd.AddCommand(get)

	cmd.AddCommand(&cobra.Command{
		Use:   "status",
		Short: "Show the tracing and request replay settings of the project",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			o, err := c.ProjectObservability(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(o, func(t *output.TableWriter) { obsSettingsTable(t, p, o) })
		},
	})

	var ratio float64
	var noDeploy bool
	var timeout time.Duration
	enable := &cobra.Command{
		Use:   "enable",
		Short: "Switch tracing on and redeploy the live version (--no-deploy: the next deployment applies it)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			var r *float64
			if cmd.Flags().Changed("sample-ratio") {
				if ratio < 0 || ratio > 1 {
					return usageErrorf("--sample-ratio must be between 0 and 1")
				}
				r = &ratio
			}
			return obsSetTracing(cmd.Context(), app, true, r, noDeploy, timeout)
		},
	}
	enable.Flags().Float64Var(&ratio, "sample-ratio", 1, "share of traces recorded, 0-1 (default: the project's ratio, 1 for a new one)")
	enable.Flags().BoolVar(&noDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	enable.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	cmd.AddCommand(enable)

	var offNoDeploy bool
	var offTimeout time.Duration
	disable := &cobra.Command{
		Use:   "disable",
		Short: "Switch tracing off and redeploy the live version",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			return obsSetTracing(cmd.Context(), app, false, nil, offNoDeploy, offTimeout)
		},
	}
	disable.Flags().BoolVar(&offNoDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	disable.Flags().DurationVar(&offTimeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	cmd.AddCommand(disable)

	cmd.AddCommand(newReplayCmd(app))
	return cmd
}

func obsTraceTable(t *output.TableWriter, tr *runr.Trace, lines []runr.TraceLogLine, withLogs bool) {
	t.KV("Trace", tr.TraceID)
	t.KV("Start", output.Dash(tr.Start))
	t.KV("Duration", obsMs(tr.DurationMs))
	spans := countLabel(tr.SpanCount, "span")
	if tr.ErrorCount > 0 {
		spans += fmt.Sprintf(", %s", countLabel(tr.ErrorCount, "error"))
	}
	t.KV("Spans", spans)
	t.KV("Services", joinOrDash(tr.Services))
	t.Header("SPAN", "SERVICE", "KIND", "START", "DURATION", "STATUS")
	for _, sp := range tr.Spans {
		status := sp.Status.Code
		if code := sp.Attributes.String("http.response.status_code"); code != "" {
			status += " " + code
		}
		if sp.Status.Message != "" {
			status += ": " + output.Truncate(sp.Status.Message, 40)
		}
		name := strings.Repeat("  ", sp.Depth) + sp.Name
		if sp.MissingParent {
			name += " (parent not recorded)"
		}
		t.Row(name, sp.Service, sp.Kind, "+"+obsMs(sp.StartOffsetMs), obsMs(sp.DurationMs), status)
	}
	if !withLogs {
		return
	}
	t.Note("")
	if len(lines) == 0 {
		t.Note("No log lines carry this trace id.")
		return
	}
	t.Note("Logs:")
	for _, l := range lines {
		t.Note("%s %s %s", time.UnixMilli(l.TsMs).Format("15:04:05.000"), l.Pod, strings.TrimRight(l.Line, "\n"))
	}
}

func obsSettingsTable(t *output.TableWriter, p *runr.Project, o *runr.ProjectObservability) {
	t.KV("Project", p.Name)
	t.KV("Tracing", obsOnOff(o.TracingEnabled))
	t.KV("Sample ratio", strconv.FormatFloat(o.TracingSampleRatio, 'f', -1, 64))
	if rt := o.RegionTracing; rt != nil {
		t.KV("Region backend", rt.Mode+" ("+rt.Status+")")
		t.KV("Traces searchable", yesNoWord(rt.Queryable))
	}
	t.KV("Request replay", obsOnOff(o.ReplayEnabled))
	if o.ReplayEnabled || o.ReplayAvailable {
		t.KV("Max body", fmt.Sprintf("%d bytes", o.ReplayMaxBodyBytes))
		t.KV("Retention", fmt.Sprintf("%d days", o.ReplayRetentionDays))
		t.KV("Redacted headers", joinOrDash(o.ReplayRedactHeaders))
		t.KV("Redacted query", joinOrDash(o.ReplayRedactQuery))
		t.KV("Replays go to", output.Dash(o.ReplayTarget))
	}
	if !o.ReplayAvailable {
		t.Note("Request replay works for code runtimes with an http or queue trigger.")
	}
}

func yesNoWord(b bool) string {
	if b {
		return "yes"
	}
	return "no"
}

// obsSetTracing switches tracing and, when the project has a live deployment, deploys it again.
func obsSetTracing(ctx context.Context, app *App, enabled bool, ratio *float64, noDeploy bool, timeout time.Duration) error {
	c, p, err := app.Project(ctx)
	if err != nil {
		return err
	}
	o, err := c.SetTracing(ctx, p.ID, enabled, ratio)
	if err != nil {
		return err
	}
	if enabled {
		app.Progress("Tracing of %s is on (sample ratio %s).", p.Name, strconv.FormatFloat(o.TracingSampleRatio, 'f', -1, 64))
	} else {
		app.Progress("Tracing of %s is off.", p.Name)
	}
	if o.RequiresRedeploy {
		return redeployIfLive(ctx, app, c, p, noDeploy, timeout)
	}
	return app.Printer().Print(o, func(t *output.TableWriter) { obsSettingsTable(t, p, o) })
}

// ---------------------------------------------------------------------------------------
// runr traces replay

func newReplayCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "replay",
		Aliases: []string{"replays"},
		Short:   "Captured failed requests: list, show, send again, delete; switch capture on or off",
		Long: "With request replay on, failed requests of the project (HTTP 5xx or timeouts) are captured\n" +
			"with their headers (secrets redacted) and bodies, and can be sent again to the current\n" +
			"revision with a diff of the answers. Captures can hold personal data: keep the retention\n" +
			"short and delete them when they are no longer needed. Needs replay.manage.",
	}
	var status, page int
	var path string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List captured requests (newest first, 50 per page)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, pg, err := c.ListReplays(cmd.Context(), p.ID, status, path, page)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"captures": list, "page": pg}, func(t *output.TableWriter) {
				t.Header("ID", "CAPTURED", "REQUEST", "STATUS", "DURATION", "REPLAYS", "TRACE")
				for _, r := range list {
					st := strconv.Itoa(r.Status)
					if r.TimedOut {
						st = "timeout"
					}
					req := r.Method + " " + r.Path
					if r.Query != "" {
						req += "?" + r.Query
					}
					replays := strconv.Itoa(r.ReplayCount)
					if r.LastReplayStatus != nil {
						replays += fmt.Sprintf(" (last %d)", *r.LastReplayStatus)
					}
					t.Row(r.ID, output.Dash(r.CapturedAt), output.Truncate(req, 50), st, obsMsPtr(r.DurationMs), replays, output.Dash(r.TraceID))
				}
				if pg != nil && pg.LastPage > 1 {
					t.Note("Page %d of %d (%d captures); --page shows another.", pg.CurrentPage, pg.LastPage, pg.Total)
				}
			})
		},
	}
	ls.Flags().IntVar(&status, "status", 0, "only captures with this HTTP status")
	ls.Flags().StringVar(&path, "path", "", "part of the request path")
	ls.Flags().IntVar(&page, "page", 1, "page of the list")
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:   "get CAPTURE",
		Short: "Show a captured request and the answer it got",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			r, err := c.GetReplay(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(r, func(t *output.TableWriter) {
				t.KV("Capture", r.ID)
				t.KV("Captured", output.Dash(r.CapturedAt))
				req := r.Method + " " + r.Path
				if r.Query != "" {
					req += "?" + r.Query
				}
				t.KV("Request", req)
				t.KV("Status", strconv.Itoa(r.Status))
				t.KV("Trace", output.Dash(r.TraceID))
				t.KV("Redacted headers", joinOrDash(r.RedactedHeaders))
				t.Note("")
				t.Note("Request headers:")
				obsHeaderNotes(t, r.Headers)
				t.Note("Request body%s:", obsCut(r.BodyTruncated, r.BodyBase64))
				t.Note("%s", output.Dash(r.Body))
				t.Note("")
				t.Note("Response headers:")
				obsHeaderNotes(t, r.ResponseHeaders)
				t.Note("Response body%s:", obsCut(r.ResponseTruncated, r.ResponseBodyBase64))
				t.Note("%s", output.Dash(r.ResponseBody))
			})
		},
	})

	var headers []string
	var query string
	run := &cobra.Command{
		Use:     "run CAPTURE",
		Aliases: []string{"send"},
		Short:   "Send a captured request again to the current revision and compare the answers",
		Long: "Redacted headers are not sent; give the ones the request needs with --header (used for this\n" +
			"replay only, never stored). --query replaces the captured query string.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			h := map[string]string{}
			for _, raw := range headers {
				k, v, ok := strings.Cut(raw, ":")
				if !ok || strings.TrimSpace(k) == "" {
					return usageErrorf("--header %q is not Name: value", raw)
				}
				h[strings.TrimSpace(k)] = strings.TrimSpace(v)
			}
			var q *string
			if cmd.Flags().Changed("query") {
				q = &query
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			res, err := c.Replay(cmd.Context(), p.ID, args[0], h, q)
			if err != nil {
				return err
			}
			return app.Printer().Print(res, func(t *output.TableWriter) { obsReplayTable(t, res) })
		},
	}
	run.Flags().StringArrayVarP(&headers, "header", "H", nil, "header \"Name: value\" for this replay (repeatable)")
	run.Flags().StringVar(&query, "query", "", "query string instead of the captured one")
	cmd.AddCommand(run)

	var all, yes bool
	rm := &cobra.Command{
		Use:     "rm [CAPTURE...]",
		Aliases: []string{"delete"},
		Short:   "Delete captured requests (--all: every capture of the project)",
		RunE: func(cmd *cobra.Command, args []string) error {
			if all == (len(args) > 0) {
				return usageErrorf("give capture ids or --all")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if all {
				if err := app.confirm("Delete every captured request of "+p.Name, yes); err != nil {
					return err
				}
				n, err := c.DeleteAllReplays(cmd.Context(), p.ID)
				if err != nil {
					return err
				}
				app.Progress("Deleted %s.", countLabel(n, "capture"))
				return app.Printer().Print(map[string]any{"deleted": n}, func(*output.TableWriter) {})
			}
			if err := app.confirm(fmt.Sprintf("Delete %s of %s", countLabel(len(args), "capture"), p.Name), yes); err != nil {
				return err
			}
			for _, id := range args {
				if err := c.DeleteReplay(cmd.Context(), p.ID, id); err != nil {
					return err
				}
				app.Progress("Deleted %s.", id)
			}
			return app.Printer().Print(map[string]any{"deleted": len(args)}, func(*output.TableWriter) {})
		},
	}
	rm.Flags().BoolVar(&all, "all", false, "delete every capture of the project")
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)

	var maxBody, retention int
	var redactHeaders, redactQuery []string
	var noDeploy bool
	var timeout time.Duration
	enable := &cobra.Command{
		Use:   "enable",
		Short: "Capture failed requests from the next deployment on (redeploys the live version)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			in := runr.ReplaySettings{Enabled: true}
			fl := cmd.Flags()
			if fl.Changed("max-body-bytes") {
				if maxBody < 0 || maxBody > 1048576 {
					return usageErrorf("--max-body-bytes must be between 0 and 1048576")
				}
				in.MaxBodyBytes = &maxBody
			}
			if fl.Changed("retention-days") {
				if retention < 1 || retention > 30 {
					return usageErrorf("--retention-days must be between 1 and 30")
				}
				in.RetentionDays = &retention
			}
			if fl.Changed("redact-header") {
				in.RedactHeaders = &redactHeaders
			}
			if fl.Changed("redact-query") {
				in.RedactQuery = &redactQuery
			}
			return obsSetReplay(cmd.Context(), app, in, noDeploy, timeout)
		},
	}
	enable.Flags().IntVar(&maxBody, "max-body-bytes", 65536, "request and response bodies are cut at this size")
	enable.Flags().IntVar(&retention, "retention-days", 7, "days a capture is kept (1-30)")
	enable.Flags().StringArrayVar(&redactHeaders, "redact-header", nil, "header never captured, on top of the built-in list (repeatable; replaces the project's list)")
	enable.Flags().StringArrayVar(&redactQuery, "redact-query", nil, "query parameter whose value is replaced (repeatable; replaces the project's list)")
	enable.Flags().BoolVar(&noDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	enable.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	cmd.AddCommand(enable)

	var offNoDeploy bool
	var offTimeout time.Duration
	disable := &cobra.Command{
		Use:   "disable",
		Short: "Stop capturing failed requests (redeploys the live version)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			return obsSetReplay(cmd.Context(), app, runr.ReplaySettings{Enabled: false}, offNoDeploy, offTimeout)
		},
	}
	disable.Flags().BoolVar(&offNoDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	disable.Flags().DurationVar(&offTimeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	cmd.AddCommand(disable)
	return cmd
}

func obsSetReplay(ctx context.Context, app *App, in runr.ReplaySettings, noDeploy bool, timeout time.Duration) error {
	c, p, err := app.Project(ctx)
	if err != nil {
		return err
	}
	o, err := c.SetReplay(ctx, p.ID, in)
	if err != nil {
		return err
	}
	app.Progress("Request replay of %s is %s.", p.Name, obsOnOff(o.ReplayEnabled))
	if o.RequiresRedeploy {
		return redeployIfLive(ctx, app, c, p, noDeploy, timeout)
	}
	return app.Printer().Print(o, func(t *output.TableWriter) { obsSettingsTable(t, p, o) })
}

func obsHeaderNotes(t *output.TableWriter, h runr.ObsAttrs) {
	if len(h) == 0 {
		t.Note("  -")
		return
	}
	for _, k := range h.Keys() {
		t.Note("  %s: %s", k, h.String(k))
	}
}

func obsCut(truncated, base64 bool) string {
	var parts []string
	if base64 {
		parts = append(parts, "base64")
	}
	if truncated {
		parts = append(parts, "truncated")
	}
	if len(parts) == 0 {
		return ""
	}
	return " (" + strings.Join(parts, ", ") + ")"
}

func obsReplayTable(t *output.TableWriter, res *runr.ReplayResult) {
	t.KV("Capture", res.Capture.ID)
	t.KV("Sent to", output.Dash(res.Replay.URL))
	if res.Replay.Version != nil {
		t.KV("Version", "v"+strconv.Itoa(*res.Replay.Version))
	}
	t.KV("Status", fmt.Sprintf("%d → %d", res.Diff.Status.Before, res.Diff.Status.After))
	t.KV("Duration", obsMs(res.Replay.DurationMs))
	if res.Replay.Error != "" {
		t.KV("Error", res.Replay.Error)
	}
	changed := "no"
	if res.Diff.Changed {
		changed = "yes"
	}
	t.KV("Answer changed", changed)
	d := res.Diff
	if len(d.Headers.Added)+len(d.Headers.Removed)+len(d.Headers.Changed) > 0 {
		t.Note("")
		t.Note("Headers:")
		for _, k := range d.Headers.Added.Keys() {
			t.Note("+ %s: %s", k, d.Headers.Added.String(k))
		}
		for _, k := range d.Headers.Removed.Keys() {
			t.Note("- %s: %s", k, d.Headers.Removed.String(k))
		}
		for _, k := range d.Headers.Changed.Keys() {
			t.Note("~ %s: %s", k, d.Headers.Changed.String(k))
		}
	}
	if d.Body.Changed {
		t.Note("")
		t.Note("Body:")
		for _, l := range d.Body.Lines {
			t.Note("%s %s", l.Op, l.Text)
		}
		if d.Body.Truncated {
			t.Note("(diff cut)")
		}
	}
}

// ---------------------------------------------------------------------------------------
// runr uptime

type obsCheckFlags struct {
	url, path, method, expect, bodyRegex, locations, statusPageName string
	headers, regions                                                []string
	interval, timeout                                               time.Duration
	failure, recovery, statusPageOrder                              int
	enabled, statusPage                                             bool
}

func (f *obsCheckFlags) register(c *cobra.Command) {
	fs := c.Flags()
	fs.StringVar(&f.url, "url", "", "absolute URL to probe (default: a path on the project's endpoint)")
	fs.StringVar(&f.path, "path", "", "path on the project's endpoint (default /)")
	fs.StringVar(&f.method, "method", "", "GET, HEAD or POST (default GET)")
	fs.StringArrayVarP(&f.headers, "header", "H", nil, "request header \"Name: value\" (repeatable; stored write-only)")
	fs.DurationVar(&f.interval, "interval", 0, "probe interval, 30s-1h (default 1m)")
	fs.DurationVar(&f.timeout, "timeout", 0, "probe timeout, up to 60s (default 10s)")
	fs.StringVar(&f.expect, "expect", "", "expected status: 200, 200,204, 200-399 or 2xx (default 200-399)")
	fs.StringVar(&f.bodyRegex, "body-regex", "", "RE2 pattern the body must match")
	fs.StringVar(&f.locations, "locations", "", "control_plane (the RunR API probes) or regions (the agents of --region)")
	fs.StringArrayVar(&f.regions, "region", nil, "region whose agent probes (name or id, repeatable; implies --locations regions)")
	fs.IntVar(&f.failure, "failure-threshold", 0, "consecutive failures that make the check down (default 3)")
	fs.IntVar(&f.recovery, "recovery-threshold", 0, "consecutive successes that make it up again (default 2)")
	fs.BoolVar(&f.enabled, "enabled", true, "probe the check (--enabled=false pauses it)")
	fs.BoolVar(&f.statusPage, "status-page", false, "show the check on the status page")
	fs.StringVar(&f.statusPageName, "status-page-name", "", "name of the check on the status page")
	fs.IntVar(&f.statusPageOrder, "status-page-order", 0, "position on the status page")
}

func obsSeconds(flag string, d time.Duration, min, max int) (*int, error) {
	if d%time.Second != 0 {
		return nil, usageErrorf("%s must be whole seconds", flag)
	}
	n := int(d / time.Second)
	if n < min || n > max {
		return nil, usageErrorf("%s must be between %ds and %ds", flag, min, max)
	}
	return &n, nil
}

// input builds the request from the flags given on the command line.
func (f *obsCheckFlags) input(cmd *cobra.Command, app *App, c *runr.Client) (runr.UptimeCheckInput, error) {
	fl := cmd.Flags()
	var in runr.UptimeCheckInput
	str := func(name, v string) *string {
		if fl.Changed(name) {
			return &v
		}
		return nil
	}
	if fl.Changed("url") && fl.Changed("path") {
		return in, usageErrorf("give --url or --path, not both")
	}
	in.URL, in.Path, in.BodyRegex = str("url", f.url), str("path", f.path), str("body-regex", f.bodyRegex)
	in.ExpectedStatus, in.StatusPageName = str("expect", f.expect), str("status-page-name", f.statusPageName)
	if fl.Changed("method") {
		m := strings.ToUpper(f.method)
		if m != "GET" && m != "HEAD" && m != "POST" {
			return in, usageErrorf("--method must be GET, HEAD or POST")
		}
		in.Method = &m
	}
	if fl.Changed("header") {
		h := map[string]string{}
		for _, raw := range f.headers {
			k, v, ok := strings.Cut(raw, ":")
			if !ok || strings.TrimSpace(k) == "" {
				return in, usageErrorf("--header %q is not Name: value", raw)
			}
			h[strings.TrimSpace(k)] = strings.TrimSpace(v)
		}
		in.Headers = &h
	}
	var err error
	if fl.Changed("interval") {
		if in.IntervalSeconds, err = obsSeconds("--interval", f.interval, 30, 3600); err != nil {
			return in, err
		}
	}
	if fl.Changed("timeout") {
		if in.TimeoutSeconds, err = obsSeconds("--timeout", f.timeout, 1, 60); err != nil {
			return in, err
		}
	}
	if fl.Changed("locations") {
		if f.locations != runr.UptimeControlPlane && f.locations != runr.UptimeRegions {
			return in, usageErrorf("--locations must be control_plane or regions")
		}
		in.Locations = &f.locations
	}
	if fl.Changed("region") {
		if in.Locations != nil && *in.Locations != runr.UptimeRegions {
			return in, usageErrorf("--region needs --locations regions")
		}
		loc := runr.UptimeRegions
		in.Locations = &loc
		ids := []string{}
		for _, ref := range f.regions {
			r, err := c.FindRegion(cmd.Context(), ref)
			if err != nil {
				return in, err
			}
			ids = append(ids, r.ID)
		}
		in.RegionIDs = &ids
	}
	for _, x := range []struct {
		name string
		v    int
		dst  **int
	}{{"failure-threshold", f.failure, &in.FailureThreshold}, {"recovery-threshold", f.recovery, &in.RecoveryThreshold}} {
		if fl.Changed(x.name) {
			if x.v < 1 || x.v > 20 {
				return in, usageErrorf("--%s must be between 1 and 20", x.name)
			}
			v := x.v
			*x.dst = &v
		}
	}
	if fl.Changed("enabled") {
		in.IsEnabled = &f.enabled
	}
	if fl.Changed("status-page") {
		in.ShowOnStatusPage = &f.statusPage
	}
	if fl.Changed("status-page-order") {
		in.StatusPageOrder = &f.statusPageOrder
	}
	return in, nil
}

func newUptimeCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "uptime",
		Short: "Synthetic uptime checks of a project, their results and incidents",
		Long: "An uptime check probes a path of the project's endpoint (or an absolute URL) from the RunR\n" +
			"API or from the agents of chosen regions. It is down after --failure-threshold failed probes\n" +
			"in a row, which opens an incident and fires the uptime_down alert rules. Reading needs\n" +
			"metrics.view, changes uptime.manage.",
		Example: "  runr uptime create health --path /health --interval 30s --expect 200\n" +
			"  runr uptime results health --hours 72\n" +
			"  runr uptime incidents --open",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the checks with their uptime of 24 h, 7 d and 30 d",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, err := c.ListUptimeChecks(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "STATUS", "TARGET", "EVERY", "24H", "7D", "30D", "AVG", "ID")
				for _, u := range list {
					status := u.Status
					if !u.IsEnabled {
						status += " (paused)"
					}
					avg := "-"
					if u.AvgResponseMs24h != nil {
						avg = strconv.Itoa(*u.AvgResponseMs24h) + "ms"
					}
					t.Row(u.Name, status, output.Truncate(u.Method+" "+u.Target(), 40), output.Duration(time.Duration(u.IntervalSeconds)*time.Second),
						obsPercent(u.Uptime24h), obsPercent(u.Uptime7d), obsPercent(u.Uptime30d), avg, u.ID)
				}
				if len(list) == 0 {
					t.Note("No uptime checks; create one with `runr uptime create NAME --path /health`.")
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	var cf obsCheckFlags
	create := &cobra.Command{
		Use:   "create NAME",
		Short: "Create an uptime check",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			in, err := cf.input(cmd, app, c)
			if err != nil {
				return err
			}
			in.Name = &args[0]
			u, err := c.CreateUptimeCheck(cmd.Context(), p.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Created uptime check %s (%s every %s).", u.Name, u.Target(), output.Duration(time.Duration(u.IntervalSeconds)*time.Second))
			return app.Printer().Print(u, func(t *output.TableWriter) { obsCheckDetail(t, u) })
		},
	}
	cf.register(create)
	cmd.AddCommand(create)

	var uf obsCheckFlags
	var rename string
	update := &cobra.Command{
		Use:   "update CHECK",
		Short: "Change an uptime check (only the flags given)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			cur, err := c.FindUptimeCheck(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			in, err := uf.input(cmd, app, c)
			if err != nil {
				return err
			}
			if cmd.Flags().Changed("name") {
				in.Name = &rename
			}
			if in == (runr.UptimeCheckInput{}) {
				return usageErrorf("give at least one flag to change")
			}
			u, err := c.UpdateUptimeCheck(cmd.Context(), p.ID, cur.ID, in)
			if err != nil {
				return err
			}
			return app.Printer().Print(u, func(t *output.TableWriter) { obsCheckDetail(t, u) })
		},
	}
	uf.register(update)
	update.Flags().StringVar(&rename, "name", "", "new name")
	cmd.AddCommand(update)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm CHECK",
		Aliases: []string{"delete"},
		Short:   "Delete an uptime check with its results and incidents",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			u, err := c.FindUptimeCheck(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Delete uptime check "+u.Name+" with its results and incidents", yes); err != nil {
				return err
			}
			if err := c.DeleteUptimeCheck(cmd.Context(), p.ID, u.ID); err != nil {
				return err
			}
			app.Progress("Deleted uptime check %s.", u.Name)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)

	var hours int
	var location string
	results := &cobra.Command{
		Use:   "results CHECK",
		Short: "Recent results, uptime, daily bars and incidents of a check",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if hours < 1 || hours > 720 {
				return usageErrorf("--hours must be between 1 and 720")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			u, err := c.FindUptimeCheck(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			rep, err := c.UptimeResults(cmd.Context(), p.ID, u.ID, hours, location)
			if err != nil {
				return err
			}
			return app.Printer().Print(rep, func(t *output.TableWriter) {
				t.KV("Check", rep.Check.Name+" ("+rep.Check.Status+")")
				t.KV("Uptime", fmt.Sprintf("%s 24h, %s 7d, %s 30d", obsPercent(rep.Uptime.H24), obsPercent(rep.Uptime.D7), obsPercent(rep.Uptime.D30)))
				if rep.AvgResponseMs24h != nil {
					t.KV("Average response", strconv.Itoa(*rep.AvgResponseMs24h)+"ms (24h)")
				}
				t.Header("CHECKED", "LOCATION", "OK", "STATUS", "RESPONSE", "ERROR")
				for _, r := range rep.Results {
					resp := "-"
					if r.ResponseMs != nil {
						resp = strconv.Itoa(*r.ResponseMs) + "ms"
					}
					t.Row(output.Dash(r.CheckedAt), r.Location, yesNoWord(r.OK), obsIntPtr(r.StatusCode), resp, output.Dash(output.Truncate(r.Error, 50)))
				}
				open := 0
				for _, in := range rep.Incidents {
					if in.Open() {
						open++
					}
				}
				if open > 0 {
					t.Note("%s open: `runr uptime incidents --open`.", countLabel(open, "incident"))
				}
			})
		},
	}
	results.Flags().IntVar(&hours, "hours", 24, "results of the last hours (1-720)")
	results.Flags().StringVar(&location, "location", "", "only results of this location (control-plane or a region)")
	cmd.AddCommand(results)

	cmd.AddCommand(&cobra.Command{
		Use:   "run CHECK",
		Short: "Probe a check now from the RunR API",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			u, err := c.FindUptimeCheck(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			res, err := c.RunUptimeCheck(cmd.Context(), p.ID, u.ID)
			if err != nil {
				return err
			}
			if perr := app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Check", u.Name)
				t.KV("OK", yesNoWord(res.OK))
				t.KV("Status", obsIntPtr(res.StatusCode))
				if res.ResponseMs != nil {
					t.KV("Response", strconv.Itoa(*res.ResponseMs)+"ms")
				}
				if res.Error != "" {
					t.KV("Error", res.Error)
				}
				t.KV("Check status", res.Check.Status)
			}); perr != nil {
				return perr
			}
			if !res.OK {
				return &runr.FailedError{What: "uptime check", Ref: u.Name, Message: res.Error}
			}
			return nil
		},
	})

	var openOnly bool
	incidents := &cobra.Command{
		Use:   "incidents",
		Short: "List the incidents of the project's checks (newest first)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			list, err := c.ListUptimeIncidents(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			if openOnly {
				kept := []runr.UptimeIncident{}
				for _, in := range list {
					if in.Open() {
						kept = append(kept, in)
					}
				}
				list = kept
			}
			checks := map[string]string{}
			if all, err := c.ListUptimeChecks(cmd.Context(), p.ID); err == nil {
				for _, u := range all {
					checks[u.ID] = u.Name
				}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "CHECK", "STATUS", "STARTED", "DURATION", "TITLE")
				for _, in := range list {
					dur := "-"
					if in.DurationSeconds != nil {
						dur = output.Duration(time.Duration(*in.DurationSeconds) * time.Second)
					}
					name := checks[in.UptimeCheckID]
					if name == "" {
						name = in.UptimeCheckID
					}
					t.Row(in.ID, output.Dash(name), in.Status, output.Dash(in.StartedAt), dur, output.Dash(output.Truncate(in.Title, 50)))
				}
			})
		},
	}
	incidents.Flags().BoolVar(&openOnly, "open", false, "only incidents that are not resolved")
	var title, message string
	incidentUpdate := &cobra.Command{
		Use:   "update INCIDENT",
		Short: "Set the public title and message of an incident (shown on the status page)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			if !fl.Changed("title") && !fl.Changed("message") {
				return usageErrorf("give --title or --message")
			}
			var tp, mp *string
			if fl.Changed("title") {
				tp = &title
			}
			if fl.Changed("message") {
				data, err := readArg(app, message)
				if err != nil {
					return err
				}
				m := string(data)
				mp = &m
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			in, err := c.UpdateUptimeIncident(cmd.Context(), p.ID, args[0], tp, mp)
			if err != nil {
				return err
			}
			return app.Printer().Print(in, func(t *output.TableWriter) {
				t.KV("Incident", in.ID)
				t.KV("Status", in.Status)
				t.KV("Title", output.Dash(in.Title))
				t.KV("Message", output.Dash(in.Message))
			})
		},
	}
	incidentUpdate.Flags().StringVar(&title, "title", "", "public title")
	incidentUpdate.Flags().StringVar(&message, "message", "", "public message (@file or - reads it)")
	incidents.AddCommand(incidentUpdate)
	cmd.AddCommand(incidents)
	return cmd
}

func obsCheckDetail(t *output.TableWriter, u *runr.UptimeCheck) {
	t.KV("Name", u.Name)
	t.KV("ID", u.ID)
	t.KV("Probe", u.Method+" "+u.Target())
	t.KV("Every", output.Duration(time.Duration(u.IntervalSeconds)*time.Second))
	t.KV("Timeout", output.Duration(time.Duration(u.TimeoutSeconds)*time.Second))
	t.KV("Expected status", output.Dash(u.ExpectedStatus))
	if u.BodyRegex != "" {
		t.KV("Body pattern", u.BodyRegex)
	}
	loc := u.Locations
	if u.Locations == runr.UptimeRegions && len(u.RegionIDs) > 0 {
		loc += " (" + strings.Join(u.RegionIDs, ", ") + ")"
	}
	t.KV("Locations", output.Dash(loc))
	t.KV("Thresholds", fmt.Sprintf("down after %d, up after %d", u.FailureThreshold, u.RecoveryThreshold))
	if len(u.HeaderNames) > 0 {
		t.KV("Headers", strings.Join(u.HeaderNames, ", ")+" (values write-only)")
	}
	t.KV("Enabled", yesNoWord(u.IsEnabled))
	t.KV("Status page", yesNoWord(u.ShowOnStatusPage))
	t.KV("Status", u.Status)
}

// ---------------------------------------------------------------------------------------
// runr alerts

type obsChannelFlags struct {
	emails                                    []string
	webhookURL, botToken, chatID, url, secret string
	config                                    string
	enabled                                   bool
}

func (f *obsChannelFlags) register(c *cobra.Command) {
	fs := c.Flags()
	fs.StringArrayVar(&f.emails, "email", nil, "email: an address (repeatable, up to 20)")
	fs.StringVar(&f.webhookURL, "webhook-url", "", "slack, teams: the incoming webhook URL (@file or - reads it)")
	fs.StringVar(&f.botToken, "bot-token", "", "telegram: the bot token (@file or - reads it)")
	fs.StringVar(&f.chatID, "chat-id", "", "telegram: the chat id (-100… or @channel)")
	fs.StringVar(&f.url, "url", "", "webhook: the URL alerts are posted to (@file or - reads it)")
	fs.StringVar(&f.secret, "secret", "", "webhook: signing secret, at least 16 characters (@file or - reads it)")
	fs.StringVar(&f.config, "config", "", "the whole config as JSON, @file.json or -")
	fs.BoolVar(&f.enabled, "enabled", true, "send alerts through the channel (--enabled=false pauses it)")
}

// configMap builds the channel config from --config and the per-type flags.
func (f *obsChannelFlags) configMap(cmd *cobra.Command, app *App) (map[string]any, error) {
	fl := cmd.Flags()
	cfg := map[string]any{}
	if f.config != "" {
		raw, err := readJSONArg(app, "--config", f.config)
		if err != nil {
			return nil, err
		}
		if err := json.Unmarshal(raw, &cfg); err != nil {
			return nil, usageErrorf("--config must be a JSON object")
		}
	}
	if fl.Changed("email") {
		list := make([]any, 0, len(f.emails))
		for _, e := range f.emails {
			list = append(list, strings.TrimSpace(e))
		}
		cfg["emails"] = list
	}
	for _, x := range []struct{ flag, key, v string }{
		{"webhook-url", "webhook_url", f.webhookURL}, {"bot-token", "bot_token", f.botToken},
		{"url", "url", f.url}, {"secret", "secret", f.secret},
	} {
		if !fl.Changed(x.flag) {
			continue
		}
		data, err := readArg(app, x.v)
		if err != nil {
			return nil, err
		}
		cfg[x.key] = strings.TrimSpace(string(data))
	}
	if fl.Changed("chat-id") {
		cfg["chat_id"] = f.chatID
	}
	return cfg, nil
}

type obsRuleFlags struct {
	region, severity string
	params, channels []string
	cooldown         time.Duration
	enabled          bool
}

func (f *obsRuleFlags) register(c *cobra.Command) {
	fs := c.Flags()
	fs.StringVar(&f.region, "region", "", "only this region (name or id; resource_threshold)")
	fs.StringArrayVar(&f.params, "param", nil, "rule parameter key=value (repeatable): threshold, threshold_ms, window_minutes, min_requests, resource, min_level")
	fs.StringVar(&f.severity, "severity", "", "info, warning or critical (default warning)")
	fs.DurationVar(&f.cooldown, "cooldown", 0, "a still-firing alert is sent again after this long (default 15m)")
	fs.StringArrayVar(&f.channels, "channel", nil, "channel the alerts go to (name or id, repeatable)")
	fs.BoolVar(&f.enabled, "enabled", true, "evaluate the rule (--enabled=false pauses it)")
}

// obsParamValue turns a --param value into a number, a boolean or a string.
func obsParamValue(v string) any {
	if n, err := strconv.ParseFloat(v, 64); err == nil {
		return n
	}
	if b, err := strconv.ParseBool(v); err == nil {
		return b
	}
	return v
}

func (f *obsRuleFlags) input(cmd *cobra.Command, app *App, c *runr.Client) (runr.AlertRuleInput, error) {
	fl := cmd.Flags()
	var in runr.AlertRuleInput
	ctx := cmd.Context()
	if app.ProjectRef != "" {
		p, err := c.FindProject(ctx, app.ProjectRef)
		if err != nil {
			return in, err
		}
		in.ProjectID = &p.ID
	}
	if fl.Changed("region") {
		r, err := c.FindRegion(ctx, f.region)
		if err != nil {
			return in, err
		}
		in.RegionID = &r.ID
	}
	if fl.Changed("param") {
		kv, err := parseKeyValues("--param", f.params)
		if err != nil {
			return in, err
		}
		in.Params = map[string]any{}
		for k, v := range kv {
			in.Params[k] = obsParamValue(v)
		}
	}
	if fl.Changed("severity") {
		ok := false
		for _, s := range runr.AlertSeverities {
			ok = ok || s == f.severity
		}
		if !ok {
			return in, usageErrorf("--severity must be info, warning or critical")
		}
		in.Severity = &f.severity
	}
	if fl.Changed("cooldown") {
		if f.cooldown < 0 || f.cooldown > 7*24*time.Hour {
			return in, usageErrorf("--cooldown must be between 0 and 7 days")
		}
		m := int(f.cooldown.Round(time.Minute) / time.Minute)
		in.CooldownMinutes = &m
	}
	if fl.Changed("channel") {
		ids := []string{}
		for _, ref := range f.channels {
			ch, err := c.FindAlertChannel(ctx, ref)
			if err != nil {
				return in, err
			}
			ids = append(ids, ch.ID)
		}
		in.ChannelIDs = &ids
	}
	if fl.Changed("enabled") {
		in.IsEnabled = &f.enabled
	}
	return in, nil
}

func newAlertsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "alerts",
		Aliases: []string{"alert"},
		Short:   "Alert channels, rules, fired alerts and the delivery log",
		Long: "Alert rules fire on region capacity, uptime incidents, error rate, p95 latency or cold\n" +
			"start rate (the last three need tracing) and send to channels: email, Slack, Teams,\n" +
			"Telegram or a signed webhook. Channel secrets are write-only. Needs alerts.manage.",
		Example: "  runr alerts channels create oncall --type slack --webhook-url @slack-url.txt\n" +
			"  runr alerts rules create api-errors --type error_rate -p orders --param threshold=5 --channel oncall\n" +
			"  runr alerts events --firing\n" +
			"  runr alerts deliveries --failed",
	}
	cmd.AddCommand(newAlertChannelsCmd(app), newAlertRulesCmd(app))

	var status string
	var firing bool
	events := &cobra.Command{
		Use:   "events",
		Short: "Fired and resolved alerts, newest first",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if firing {
				status = "firing"
			}
			if status != "" && status != "firing" && status != "resolved" {
				return usageErrorf("--status must be firing or resolved")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListAlertEvents(cmd.Context(), status)
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("FIRED", "STATUS", "SEVERITY", "TYPE", "TITLE", "RESOLVED")
				for _, e := range list {
					t.Row(output.Dash(e.FiredAt), e.Status, e.Severity, e.Type, output.Truncate(e.Title, 60), output.Dash(e.ResolvedAt))
				}
			})
		},
	}
	events.Flags().StringVar(&status, "status", "", "firing or resolved")
	events.Flags().BoolVar(&firing, "firing", false, "only alerts that still fire")
	cmd.AddCommand(events)

	var dStatus, dChannel string
	var failed bool
	deliveries := &cobra.Command{
		Use:   "deliveries",
		Short: "The delivery log: each alert message to each channel (newest 50)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if failed {
				dStatus = "failed"
			}
			switch dStatus {
			case "", "pending", "sent", "failed":
			default:
				return usageErrorf("--status must be pending, sent or failed")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			channelID := ""
			if dChannel != "" {
				ch, err := c.FindAlertChannel(cmd.Context(), dChannel)
				if err != nil {
					return err
				}
				channelID = ch.ID
			}
			list, pg, err := c.ListAlertDeliveries(cmd.Context(), channelID, dStatus)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"deliveries": list, "page": pg}, func(t *output.TableWriter) {
				t.Header("ID", "CREATED", "CHANNEL", "KIND", "STATUS", "ATTEMPTS", "CODE", "ERROR")
				for _, d := range list {
					ch := d.ChannelName
					if d.ChannelType != "" {
						ch += " (" + d.ChannelType + ")"
					}
					t.Row(d.ID, output.Dash(d.CreatedAt), output.Dash(ch), d.Kind, d.Status, strconv.Itoa(d.Attempts),
						obsIntPtr(d.ResponseCode), output.Dash(output.Truncate(d.LastError, 50)))
				}
				if pg != nil && pg.Total > len(list) {
					t.Note("The newest %d of %d deliveries.", len(list), pg.Total)
				}
			})
		},
	}
	deliveries.Flags().StringVar(&dStatus, "status", "", "pending, sent or failed")
	deliveries.Flags().BoolVar(&failed, "failed", false, "only failed deliveries")
	deliveries.Flags().StringVar(&dChannel, "channel", "", "only deliveries to this channel (name or id)")
	deliveries.AddCommand(&cobra.Command{
		Use:   "retry DELIVERY",
		Short: "Send a failed delivery again",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			d, err := c.RetryAlertDelivery(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			app.Progress("Delivery %s is queued again.", d.ID)
			return app.Printer().Print(d, func(t *output.TableWriter) {
				t.KV("Delivery", d.ID)
				t.KV("Channel", output.Dash(d.ChannelName))
				t.KV("Status", d.Status)
			})
		},
	})
	cmd.AddCommand(deliveries)
	return cmd
}

func newAlertChannelsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "channels",
		Aliases: []string{"channel"},
		Short:   "Alert channels: email, slack, teams, telegram, webhook",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List alert channels (secrets are never shown)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListAlertChannels(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "TYPE", "TARGET", "ENABLED", "LAST TEST", "ID")
				for i := range list {
					ch := &list[i]
					test := "-"
					if ch.LastTestOK != nil {
						test = "ok"
						if !*ch.LastTestOK {
							test = "failed"
						}
					}
					t.Row(ch.Name, ch.Type, obsChannelTarget(ch), yesNoWord(ch.IsEnabled), test, ch.ID)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	var cf obsChannelFlags
	var typ string
	create := &cobra.Command{
		Use:   "create NAME",
		Short: "Create an alert channel",
		Example: "  runr alerts channels create ops --type email --email ops@example.com\n" +
			"  runr alerts channels create oncall --type slack --webhook-url @slack-url.txt\n" +
			"  runr alerts channels create hook --type webhook --url https://hooks.example.com/runr --secret -",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ok := false
			for _, t := range runr.AlertChannelTypes {
				ok = ok || t == typ
			}
			if !ok {
				return usageErrorf("--type must be one of %s", strings.Join(runr.AlertChannelTypes, ", "))
			}
			cfg, err := cf.configMap(cmd, app)
			if err != nil {
				return err
			}
			if len(cfg) == 0 {
				return usageErrorf("give the channel's settings (--email, --webhook-url, --bot-token and --chat-id, --url, or --config)")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			in := runr.AlertChannelInput{Name: &args[0], Type: &typ, Config: cfg}
			if cmd.Flags().Changed("enabled") {
				in.IsEnabled = &cf.enabled
			}
			ch, err := c.CreateAlertChannel(cmd.Context(), in)
			if err != nil {
				return err
			}
			app.Progress("Created alert channel %s; send a test with `runr alerts channels test %s`.", ch.Name, ch.Name)
			return app.Printer().Print(ch, func(t *output.TableWriter) { obsChannelDetail(t, ch) })
		},
	}
	cf.register(create)
	create.Flags().StringVar(&typ, "type", "", "email, slack, teams, telegram or webhook")
	_ = create.MarkFlagRequired("type")
	cmd.AddCommand(create)

	var uf obsChannelFlags
	var rename string
	update := &cobra.Command{
		Use:   "update CHANNEL",
		Short: "Change an alert channel; settings left out keep their stored (secret) values",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := uf.configMap(cmd, app)
			if err != nil {
				return err
			}
			in := runr.AlertChannelInput{}
			if len(cfg) > 0 {
				in.Config = cfg
			}
			if cmd.Flags().Changed("name") {
				in.Name = &rename
			}
			if cmd.Flags().Changed("enabled") {
				in.IsEnabled = &uf.enabled
			}
			if in.Config == nil && in.Name == nil && in.IsEnabled == nil {
				return usageErrorf("give at least one flag to change")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			cur, err := c.FindAlertChannel(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			ch, err := c.UpdateAlertChannel(cmd.Context(), cur.ID, in)
			if err != nil {
				return err
			}
			return app.Printer().Print(ch, func(t *output.TableWriter) { obsChannelDetail(t, ch) })
		},
	}
	uf.register(update)
	update.Flags().StringVar(&rename, "name", "", "new name")
	cmd.AddCommand(update)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm CHANNEL",
		Aliases: []string{"delete"},
		Short:   "Delete an alert channel (the rules stop sending to it)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			ch, err := c.FindAlertChannel(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Delete alert channel "+ch.Name, yes); err != nil {
				return err
			}
			if err := c.DeleteAlertChannel(cmd.Context(), ch.ID); err != nil {
				return err
			}
			app.Progress("Deleted alert channel %s.", ch.Name)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)

	cmd.AddCommand(&cobra.Command{
		Use:   "test CHANNEL",
		Short: "Send a test message through a channel now",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			ch, err := c.FindAlertChannel(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			res, err := c.TestAlertChannel(cmd.Context(), ch.ID)
			if err != nil {
				return err
			}
			if perr := app.Printer().Print(res, func(t *output.TableWriter) {
				t.KV("Channel", ch.Name)
				t.KV("Delivered", yesNoWord(res.OK))
				t.KV("Response code", obsIntPtr(res.ResponseCode))
				if res.Error != "" {
					t.KV("Error", res.Error)
				}
			}); perr != nil {
				return perr
			}
			if !res.OK {
				return &runr.FailedError{What: "test message of alert channel", Ref: ch.Name, Message: res.Error}
			}
			return nil
		},
	})
	return cmd
}

func obsChannelTarget(ch *runr.AlertChannel) string {
	switch ch.Type {
	case "email":
		if list, ok := ch.Config["emails"].([]any); ok {
			parts := make([]string, 0, len(list))
			for _, e := range list {
				parts = append(parts, fmt.Sprint(e))
			}
			return output.Truncate(strings.Join(parts, ", "), 40)
		}
	case "slack", "teams":
		return output.Dash(ch.Config.String("webhook_host"))
	case "telegram":
		return "chat " + output.Dash(ch.Config.String("chat_id"))
	case "webhook":
		host := output.Dash(ch.Config.String("url_host"))
		if ch.Config.String("secret_set") == "true" {
			host += " (signed)"
		}
		return host
	}
	return "-"
}

func obsChannelDetail(t *output.TableWriter, ch *runr.AlertChannel) {
	t.KV("Name", ch.Name)
	t.KV("ID", ch.ID)
	t.KV("Type", ch.Type)
	t.KV("Target", obsChannelTarget(ch))
	t.KV("Enabled", yesNoWord(ch.IsEnabled))
	if ch.LastTestAt != "" {
		res := "ok"
		if ch.LastTestOK != nil && !*ch.LastTestOK {
			res = "failed: " + ch.LastTestError
		}
		t.KV("Last test", ch.LastTestAt+" ("+res+")")
	}
}

func newAlertRulesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "rules",
		Aliases: []string{"rule"},
		Short:   "Alert rules: what fires, its severity, cooldown and channels",
		Long: "Rule types and their parameters (--param key=value):\n" +
			"  resource_threshold  region CPU / memory reservation: resource (cpu, memory, any), min_level (warning, critical); --region\n" +
			"  uptime_down         an uptime incident opens; -p limits it to a project\n" +
			"  error_rate          share of failed invocations: threshold (%), window_minutes, min_requests\n" +
			"  p95_latency         95th percentile duration: threshold_ms, window_minutes, min_requests\n" +
			"  cold_start_rate     share of cold starts: threshold (%), window_minutes, min_requests\n" +
			"The last three read the span metrics of traced projects. -p / --project scopes a rule to a project.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List alert rules",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListAlertRules(cmd.Context())
			if err != nil {
				return err
			}
			names := obsChannelNames(cmd.Context(), c)
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "TYPE", "STATE", "SEVERITY", "PARAMS", "CHANNELS", "ENABLED", "ID")
				for _, r := range list {
					var params []string
					for _, k := range r.Params.Keys() {
						params = append(params, k+"="+r.Params.String(k))
					}
					var chans []string
					for _, id := range r.ChannelIDs {
						if n := names[id]; n != "" {
							chans = append(chans, n)
						} else {
							chans = append(chans, id)
						}
					}
					t.Row(r.Name, r.Type, r.State, r.Severity, joinOrDash(params), joinOrDash(chans), yesNoWord(r.IsEnabled), r.ID)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	var cf obsRuleFlags
	var typ string
	create := &cobra.Command{
		Use:   "create NAME",
		Short: "Create an alert rule",
		Example: "  runr alerts rules create api-errors --type error_rate -p orders --param threshold=5 --channel oncall\n" +
			"  runr alerts rules create slow --type p95_latency -p orders --param threshold_ms=800 --severity critical\n" +
			"  runr alerts rules create capacity --type resource_threshold --region eu-1 --param resource=memory",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ok := false
			for _, t := range runr.AlertRuleTypes {
				ok = ok || t == typ
			}
			if !ok {
				return usageErrorf("--type must be one of %s", strings.Join(runr.AlertRuleTypes, ", "))
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			in, err := cf.input(cmd, app, c)
			if err != nil {
				return err
			}
			in.Name, in.Type = &args[0], &typ
			r, err := c.CreateAlertRule(cmd.Context(), in)
			if err != nil {
				return err
			}
			if len(r.ChannelIDs) == 0 {
				app.Warn("the rule sends to no channel; add one with --channel")
			}
			return app.Printer().Print(r, func(t *output.TableWriter) { obsRuleDetail(t, r) })
		},
	}
	cf.register(create)
	create.Flags().StringVar(&typ, "type", "", strings.Join(runr.AlertRuleTypes, ", "))
	_ = create.MarkFlagRequired("type")
	cmd.AddCommand(create)

	var uf obsRuleFlags
	var rename string
	var noProject bool
	update := &cobra.Command{
		Use:   "update RULE",
		Short: "Change an alert rule (only the flags given; the type is fixed)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if noProject && app.ProjectRef != "" {
				return usageErrorf("give -p or --all-projects, not both")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			cur, err := c.FindAlertRule(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			in, err := uf.input(cmd, app, c)
			if err != nil {
				return err
			}
			if cmd.Flags().Changed("name") {
				in.Name = &rename
			}
			if noProject {
				empty := ""
				in.ProjectID = &empty
			}
			if in.Name == nil && in.ProjectID == nil && in.RegionID == nil && in.Params == nil && in.Severity == nil &&
				in.CooldownMinutes == nil && in.ChannelIDs == nil && in.IsEnabled == nil {
				return usageErrorf("give at least one flag to change")
			}
			r, err := c.UpdateAlertRule(cmd.Context(), cur.ID, in)
			if err != nil {
				return err
			}
			return app.Printer().Print(r, func(t *output.TableWriter) { obsRuleDetail(t, r) })
		},
	}
	uf.register(update)
	update.Flags().StringVar(&rename, "name", "", "new name")
	update.Flags().BoolVar(&noProject, "all-projects", false, "no longer limit the rule to a project")
	cmd.AddCommand(update)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm RULE",
		Aliases: []string{"delete"},
		Short:   "Delete an alert rule (its firing alerts are closed without a message)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.FindAlertRule(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Delete alert rule "+r.Name, yes); err != nil {
				return err
			}
			if err := c.DeleteAlertRule(cmd.Context(), r.ID); err != nil {
				return err
			}
			app.Progress("Deleted alert rule %s.", r.Name)
			return nil
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)
	return cmd
}

func obsChannelNames(ctx context.Context, c *runr.Client) map[string]string {
	out := map[string]string{}
	if list, err := c.ListAlertChannels(ctx); err == nil {
		for _, ch := range list {
			out[ch.ID] = ch.Name
		}
	}
	return out
}

func obsRuleDetail(t *output.TableWriter, r *runr.AlertRule) {
	t.KV("Name", r.Name)
	t.KV("ID", r.ID)
	t.KV("Type", r.Type)
	if r.ProjectID != "" {
		t.KV("Project", r.ProjectID)
	}
	if r.RegionID != "" {
		t.KV("Region", r.RegionID)
	}
	keys := r.Params.Keys()
	sort.Strings(keys)
	var params []string
	for _, k := range keys {
		params = append(params, k+"="+r.Params.String(k))
	}
	t.KV("Params", joinOrDash(params))
	t.KV("Severity", r.Severity)
	t.KV("Cooldown", output.Duration(time.Duration(r.CooldownMinutes)*time.Minute))
	t.KV("Channels", joinOrDash(r.ChannelIDs))
	t.KV("Enabled", yesNoWord(r.IsEnabled))
	t.KV("State", r.State)
}

// ---------------------------------------------------------------------------------------
// runr status-page

func newStatusPageCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "status-page",
		Short: "Show and change the organization's public status page",
		Long: "The status page shows the uptime checks marked for it (`runr uptime update CHECK\n" +
			"--status-page`), their daily uptime and the incident history. Needs status_page.manage.",
	}
	get := &cobra.Command{
		Use:   "get",
		Short: "Show the status page settings and components",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			sp, err := c.StatusPage(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(sp, func(t *output.TableWriter) { obsStatusPageTable(t, app, sp) })
		},
	}
	cmd.AddCommand(get)
	cmd.RunE = get.RunE

	var slug, title, description, logo, domain string
	var public, history bool
	var days int
	var show, hide []string
	set := &cobra.Command{
		Use:   "set",
		Short: "Change the status page (only the flags given; the first save needs --slug and --title)",
		Example: "  runr status-page set --slug acme --title \"Acme status\" --public\n" +
			"  runr status-page set --show health --hide internal-check",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			c, err := app.Client()
			if err != nil {
				return err
			}
			cur, err := c.StatusPage(cmd.Context())
			if err != nil {
				return err
			}
			pg := cur.Page
			in := runr.StatusPageInput{Slug: pg.Slug, Title: pg.Title, IsPublic: pg.IsPublic}
			if pg.Exists {
				in.Description, in.LogoURL, in.CustomDomain = &pg.Description, &pg.LogoURL, &pg.CustomDomain
				in.ShowIncidentHistory, in.HistoryDays = &pg.ShowIncidentHistory, &pg.HistoryDays
			} else if !fl.Changed("slug") || !fl.Changed("title") {
				return usageErrorf("the first save of the status page needs --slug and --title")
			}
			if fl.Changed("slug") {
				in.Slug = slug
			}
			if fl.Changed("title") {
				in.Title = title
			}
			if fl.Changed("description") {
				in.Description = &description
			}
			if fl.Changed("logo-url") {
				in.LogoURL = &logo
			}
			if fl.Changed("custom-domain") {
				in.CustomDomain = &domain
			}
			if fl.Changed("public") {
				in.IsPublic = public
			}
			if fl.Changed("history") {
				in.ShowIncidentHistory = &history
			}
			if fl.Changed("history-days") {
				if days < 7 || days > 90 {
					return usageErrorf("--history-days must be between 7 and 90")
				}
				in.HistoryDays = &days
			}
			for _, x := range []struct {
				refs []string
				on   bool
			}{{show, true}, {hide, false}} {
				for _, ref := range x.refs {
					comp, err := obsComponent(cur.Components, ref)
					if err != nil {
						return err
					}
					item := runr.StatusPageComponentInput{ID: comp.ID, ShowOnStatusPage: x.on}
					if comp.StatusPageName != "" {
						n := comp.StatusPageName
						item.StatusPageName = &n
					}
					order := comp.StatusPageOrder
					item.StatusPageOrder = &order
					in.Components = append(in.Components, item)
				}
			}
			sp, err := c.SetStatusPage(cmd.Context(), in)
			if err != nil {
				return err
			}
			return app.Printer().Print(sp, func(t *output.TableWriter) { obsStatusPageTable(t, app, sp) })
		},
	}
	f := set.Flags()
	f.StringVar(&slug, "slug", "", "the page's address: <console>/status/<slug>")
	f.StringVar(&title, "title", "", "title")
	f.StringVar(&description, "description", "", "description")
	f.StringVar(&logo, "logo-url", "", "https URL of a logo (default: the organization's brand)")
	f.StringVar(&domain, "custom-domain", "", "own domain (a CNAME to the console host); \"\" removes it")
	f.BoolVar(&public, "public", false, "publish the page (--public=false makes it private)")
	f.BoolVar(&history, "history", true, "show the incident history")
	f.IntVar(&days, "history-days", 90, "days of incident history (7-90)")
	f.StringArrayVar(&show, "show", nil, "show this uptime check on the page (name or id, repeatable)")
	f.StringArrayVar(&hide, "hide", nil, "hide this uptime check (name or id, repeatable)")
	cmd.AddCommand(set)
	return cmd
}

func obsComponent(list []runr.StatusPageComponent, ref string) (*runr.StatusPageComponent, error) {
	for i := range list {
		if list[i].ID == ref {
			return &list[i], nil
		}
	}
	var match []*runr.StatusPageComponent
	for i := range list {
		if strings.EqualFold(list[i].Name, ref) {
			match = append(match, &list[i])
		}
	}
	switch len(match) {
	case 1:
		return match[0], nil
	case 0:
		return nil, &runr.NotFoundError{Kind: "uptime check", Ref: ref}
	}
	return nil, usageErrorf("%d uptime checks are named %q; use the id", len(match), ref)
}

func obsStatusPageTable(t *output.TableWriter, app *App, sp *runr.StatusPageSettings) {
	p := sp.Page
	if !p.Exists {
		t.Note("The status page is not set up; save it with `runr status-page set --slug NAME --title TITLE`.")
		return
	}
	url := sp.PublicPath
	if c, err := app.Client(); err == nil {
		url = strings.TrimRight(c.ConsoleURL(), "/") + sp.PublicPath
	}
	t.KV("Title", p.Title)
	t.KV("Slug", p.Slug)
	t.KV("Public", yesNoWord(p.IsPublic))
	t.KV("URL", url)
	if p.CustomDomain != "" {
		t.KV("Custom domain", "https://"+p.CustomDomain+"/status")
	}
	history := "off"
	if p.ShowIncidentHistory {
		history = fmt.Sprintf("%d days", p.HistoryDays)
	}
	t.KV("Incident history", history)
	t.Header("CHECK", "PROJECT", "ON PAGE", "SHOWN AS", "ORDER", "STATUS")
	for _, comp := range sp.Components {
		t.Row(comp.Name, output.Dash(comp.ProjectName), yesNoWord(comp.ShowOnStatusPage), output.Dash(comp.StatusPageName),
			strconv.Itoa(comp.StatusPageOrder), comp.Status)
	}
}
