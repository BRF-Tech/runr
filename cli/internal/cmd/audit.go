package cmd

import (
	"fmt"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerCommand(newAuditCmd) }

// auditFlags are the filters ls and export share.
type auditFlags struct {
	actor, action, targetType, targetID, target, result, since, until, requestID, query, tenant string
	project                                                                                     bool
}

func (f *auditFlags) register(c *cobra.Command) {
	fs := c.Flags()
	fs.StringVar(&f.actor, "actor", "", "user id, or part of the name or e-mail")
	fs.StringVar(&f.action, "action", "", "action (project.update), prefix (project.*) or comma-separated list")
	fs.StringVar(&f.targetType, "target-type", "", "target type (project, binding, role, …)")
	fs.StringVar(&f.targetID, "target-id", "", "target id")
	fs.StringVar(&f.target, "target", "", "part of the target's name")
	fs.StringVar(&f.result, "result", "", "success, denied or failure")
	fs.StringVar(&f.since, "since", "", "events from this time on: RFC 3339 or a duration back from now (24h, 7d)")
	fs.StringVar(&f.until, "until", "", "events up to this time: RFC 3339 or a duration back from now")
	fs.StringVar(&f.requestID, "request-id", "", "the X-Request-ID of an API request")
	fs.StringVar(&f.query, "query", "", "free text (action, actor, target, IP address)")
	fs.StringVar(&f.tenant, "tenant", "", "tenant id, or platform (platform administrators outside a tenant)")
	fs.BoolVar(&f.project, "this-project", false, "only events of the project (--project, $RUNR_PROJECT or runr.yaml)")
}

func (f *auditFlags) filter(cmd *cobra.Command, app *App) (runr.AuditFilter, error) {
	out := runr.AuditFilter{Actor: f.actor, Action: f.action, TargetType: f.targetType, TargetID: f.targetID,
		Target: f.target, Result: f.result, RequestID: f.requestID, Query: f.query, TenantID: f.tenant}
	switch f.result {
	case "", "success", "denied", "failure":
	default:
		return out, usageErrorf("--result must be success, denied or failure")
	}
	var err error
	if out.From, err = auditTime("--since", f.since); err != nil {
		return out, err
	}
	if out.To, err = auditTime("--until", f.until); err != nil {
		return out, err
	}
	if f.project {
		if f.targetType != "" || f.targetID != "" {
			return out, usageErrorf("--this-project sets the target; leave out --target-type and --target-id")
		}
		_, p, err := app.Project(cmd.Context())
		if err != nil {
			return out, err
		}
		out.TargetType, out.TargetID = "project", p.ID
	}
	return out, nil
}

// auditTime accepts RFC 3339, a date (2026-10-01) or a duration back from now (90m, 24h, 7d).
func auditTime(flag, v string) (string, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		return "", nil
	}
	if t, err := time.Parse(time.RFC3339, v); err == nil {
		return t.UTC().Format(time.RFC3339), nil
	}
	if t, err := time.Parse("2006-01-02", v); err == nil {
		return t.UTC().Format(time.RFC3339), nil
	}
	if d, err := parseDays(v); err == nil && d > 0 {
		return time.Now().UTC().Add(-d).Format(time.RFC3339), nil
	}
	return "", usageErrorf("%s %q is neither a time (2026-10-01T12:00:00Z, 2026-10-01) nor a duration (24h, 7d)", flag, v)
}

func newAuditCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "audit",
		Short: "Read and export the audit log",
		Long: "The audit log records who did what in the organization: sign-ins, project and secret\n" +
			"changes (names only), deployments, roles and access, settings. It is append-only.\n" +
			"Listing needs audit.view, exporting audit.export.",
	}

	var lsFlags auditFlags
	var limit int
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the newest events",
		Example: "  runr audit ls\n" +
			"  runr audit ls --action 'project.secret.*' --since 7d\n" +
			"  runr audit ls --this-project --limit 100 -o json\n" +
			"  runr audit ls --actor ada --result denied",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if limit < 1 || limit > 100 {
				return usageErrorf("--limit must be between 1 and 100 (runr audit export writes more)")
			}
			f, err := lsFlags.filter(cmd, app)
			if err != nil {
				return err
			}
			f.PerPage = limit
			c, err := app.Client()
			if err != nil {
				return err
			}
			page, err := c.ListAuditEvents(cmd.Context(), f)
			if err != nil {
				return err
			}
			return app.Printer().Print(page, func(t *output.TableWriter) {
				t.Header("TIME", "ACTOR", "ACTION", "RESULT", "TARGET", "ID")
				for _, e := range page.Events {
					t.Row(e.OccurredAt, auditActorLabel(e.Actor), e.Action, e.Result, auditTargetLabel(e.Target), e.ID)
				}
				if page.Page.Total > len(page.Events) {
					t.Note("%d of %d events; narrow the filters or use `runr audit export`.", len(page.Events), page.Page.Total)
				}
			})
		},
	}
	lsFlags.register(ls)
	ls.Flags().IntVar(&limit, "limit", 25, "number of events (1-100)")
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:   "get ID",
		Short: "Show one event with its metadata",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			e, err := c.GetAuditEvent(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(e, func(t *output.TableWriter) {
				t.KV("ID", e.ID)
				t.KV("Time", e.OccurredAt)
				t.KV("Actor", auditActorLabel(e.Actor))
				if e.Actor.AuthMethod != "" {
					t.KV("Auth", e.Actor.AuthMethod)
				}
				if e.Actor.Impersonator != nil {
					t.KV("Impersonator", e.Actor.Impersonator.Name)
				}
				t.KV("Action", e.Action)
				t.KV("Result", e.Result)
				t.KV("Target", auditTargetLabel(e.Target))
				if e.HTTPMethod != "" {
					t.KV("Request", fmt.Sprintf("%s %s → %d", e.HTTPMethod, e.Route, e.StatusCode))
				}
				if e.IPAddress != "" {
					t.KV("IP address", e.IPAddress)
				}
				if e.RequestID != "" {
					t.KV("Request id", e.RequestID)
				}
				if m := strings.TrimSpace(string(e.Metadata)); m != "" && m != "{}" && m != "[]" && m != "null" {
					t.KV("Metadata", m)
				}
			})
		},
	})

	var exFlags auditFlags
	var format, outFile string
	export := &cobra.Command{
		Use:   "export",
		Short: "Export the matching events as CSV or JSON",
		Long: "Streams the events matching the filters (newest first, up to the API's export limit,\n" +
			"100 000 by default) to --file or stdout. Needs audit.export.",
		Example: "  runr audit export --since 30d --file audit.csv\n" +
			"  runr audit export --format json --action 'role.*,project.access.*' > access.json",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if format != "csv" && format != "json" {
				return usageErrorf("--format must be csv or json")
			}
			f, err := exFlags.filter(cmd, app)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			w, closeOut, err := createOut(app, outFile)
			if err != nil {
				return err
			}
			d := &runr.Download{W: w}
			err = c.ExportAuditEvents(cmd.Context(), f, format, d)
			if cerr := closeOut(); err == nil {
				err = cerr
			}
			if err != nil {
				return err
			}
			if outFile != "" && outFile != "-" {
				app.Progress("Exported %d bytes (%s) to %s.", d.Bytes, format, outFile)
			}
			return nil
		},
	}
	exFlags.register(export)
	export.Flags().StringVar(&format, "format", "csv", "csv or json")
	export.Flags().StringVar(&outFile, "file", "", "write to this file (default: stdout)")
	cmd.AddCommand(export, newAuditSettingsCmd(app))
	return cmd
}

func auditRetentionLabel(days int) string {
	if days == 0 {
		return "forever"
	}
	return fmt.Sprintf("%d days", days)
}

func printAuditSettings(app *App, s *runr.AuditSettings) error {
	return app.Printer().Print(s, func(t *output.TableWriter) {
		t.KV("Retention", auditRetentionLabel(s.RetentionDays))
		t.KV("Longest retention", auditRetentionLabel(s.MaxDays))
		t.KV("Forwarded to an external sink", yesNoWord(s.ForwardEnabled))
		t.KV("You can change it", yesNoWord(s.CanUpdate))
	})
}

func newAuditSettingsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "settings",
		Short: "How long audit events are kept, and whether they are forwarded",
		Long: "The audit log's retention applies to the whole installation: events older than it are\n" +
			"deleted by a daily job (0 keeps them forever). Reading needs audit.view; changing it needs\n" +
			"settings.manage on a single-tenant installation, and a platform administrator outside any\n" +
			"organization on one with several organizations.",
		Example: "  runr audit settings\n" +
			"  runr audit settings set --retention-days 730",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := c.GetAuditSettings(cmd.Context())
			if err != nil {
				return err
			}
			return printAuditSettings(app, s)
		},
	}
	var days int
	set := &cobra.Command{
		Use:   "set --retention-days N",
		Short: "Change how many days audit events are kept (0 = forever)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if !cmd.Flags().Changed("retention-days") {
				return usageErrorf("give --retention-days (0 keeps events forever)")
			}
			if days < 0 {
				return usageErrorf("--retention-days must be 0 (forever) or more")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := c.SetAuditRetention(cmd.Context(), days)
			if err != nil {
				return err
			}
			app.Progress("Audit events are kept %s.", auditRetentionLabel(s.RetentionDays))
			return printAuditSettings(app, s)
		},
	}
	set.Flags().IntVar(&days, "retention-days", 0, "days events are kept (0 = forever)")
	cmd.AddCommand(set)
	return cmd
}

func auditActorLabel(a runr.AuditActor) string {
	name := a.Name
	if name == "" {
		name = a.Email
	}
	if name == "" {
		name = a.ID.String()
	}
	if name == "" {
		return a.Type
	}
	if a.Type != "" && a.Type != "user" {
		return name + " (" + a.Type + ")"
	}
	return name
}

func auditTargetLabel(t *runr.AuditTarget) string {
	if t == nil {
		return "-"
	}
	name := t.Name
	if name == "" {
		name = t.ID.String()
	}
	if name == "" {
		return t.Type
	}
	return t.Type + " " + name
}
