package cmd

import (
	"fmt"
	"strconv"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// `runr metrics`: the recorded metrics of a project — a summary of a period and the snapshots.

func init() { registerCommand(newMetricsCmd) }

func newMetricsCmd(app *App) *cobra.Command {
	var period string
	cmd := &cobra.Command{
		Use:   "metrics",
		Short: "Recorded metrics of the project: CPU, memory, requests, cold starts, sessions",
		Long: "A summary of the project's recorded metrics over the last 7, 30 or 90 days (--period):\n" +
			"average and peak CPU, memory and requests per second, cold starts, and the deployment\n" +
			"sessions (how long the workload ran and what it used). `runr metrics history` lists the\n" +
			"recorded snapshots.",
		Example: "  runr metrics\n" +
			"  runr metrics --period 30d -p orders\n" +
			"  runr metrics history --since 6h\n" +
			"  runr metrics history --from 2026-10-01 --to 2026-10-08 --granularity 1d",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			days := 0
			switch period {
			case "7d", "":
				days = 7
			case "30d":
				days = 30
			case "90d":
				days = 90
			default:
				return usageErrorf("--period must be 7d, 30d or 90d")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			sum, err := c.ProjectMetricsSummary(cmd.Context(), p.ID, days)
			if err != nil {
				return err
			}
			return app.Printer().Print(sum, func(t *output.TableWriter) { metricsSummaryDetail(t, p, sum) })
		},
	}
	cmd.Flags().StringVar(&period, "period", "7d", "7d, 30d or 90d")
	cmd.AddCommand(newMetricsHistoryCmd(app))
	return cmd
}

func flexText(v *runr.FlexFloat, prec int) string {
	if v == nil {
		return "-"
	}
	return strconv.FormatFloat(float64(*v), 'f', prec, 64)
}

func metricsSummaryDetail(t *output.TableWriter, p *runr.Project, s *runr.MetricsSummary) {
	m, ss := s.Metrics, s.Sessions
	t.KV("Project", p.Name)
	t.KV("Period", fmt.Sprintf("the last %d days (%d samples)", m.PeriodDays, m.SampleCount))
	if m.SampleCount > 0 {
		t.KV("CPU", fmt.Sprintf("%s cores on average, %s at most", flexText(m.AvgCPU, 3), flexText(m.MaxCPU, 3)))
		t.KV("Memory", fmt.Sprintf("%s MiB on average, %s MiB at most", flexText(m.AvgMemoryMB, 0), flexText(m.MaxMemoryMB, 0)))
		t.KV("Requests", fmt.Sprintf("%s/s on average, %s/s at most", flexText(m.AvgRPS, 2), flexText(m.MaxRPS, 2)))
		t.KV("Cold starts", strconv.Itoa(m.TotalColdStarts))
	}
	sessions := strconv.Itoa(ss.TotalSessions)
	if ss.TotalSessions > 0 {
		sessions += fmt.Sprintf(" (%d cold), %s on average, %s in total, %s of CPU", ss.ColdStarts,
			output.Duration(time.Duration(ss.AvgDurationMS)*time.Millisecond),
			output.Duration(time.Duration(ss.TotalDurationMS)*time.Millisecond),
			output.Duration(time.Duration(ss.TotalCPUMS)*time.Millisecond))
	}
	t.KV("Sessions", sessions)
	if m.SampleCount == 0 {
		t.Note("No metrics were recorded in this period.")
	}
}

// metricsTime parses --from / --to: RFC 3339 or a day (YYYY-MM-DD, its start in UTC).
func metricsTime(flag, v string) (time.Time, error) {
	if t, err := time.Parse(time.RFC3339, v); err == nil {
		return t, nil
	}
	if t, err := time.Parse("2006-01-02", v); err == nil {
		return t, nil
	}
	return time.Time{}, usageErrorf("--%s %q is not a time such as 2026-10-01 or 2026-10-01T12:00:00Z", flag, v)
}

func newMetricsHistoryCmd(app *App) *cobra.Command {
	var since, from, to, granularity string
	cmd := &cobra.Command{
		Use:   "history",
		Short: "Recorded snapshots: CPU, memory, requests per second, concurrency, pods, cold starts",
		Long: "List the project's recorded snapshots of the last --since (default 24h) or between --from and\n" +
			"--to (RFC 3339 or YYYY-MM-DD). --granularity picks the series: 1m, 5m, 1h or 1d (default:\n" +
			"5m up to a day, 1h up to two weeks, 1d beyond).",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			end := time.Now().UTC()
			var start time.Time
			switch {
			case from != "" || to != "":
				if since != "" {
					return usageErrorf("give --since or --from/--to, not both")
				}
				if from == "" {
					return usageErrorf("--to needs --from")
				}
				var err error
				if start, err = metricsTime("from", from); err != nil {
					return err
				}
				if to != "" {
					if end, err = metricsTime("to", to); err != nil {
						return err
					}
				}
			default:
				d := 24 * time.Hour
				if since != "" {
					var err error
					if d, err = time.ParseDuration(since); err != nil || d <= 0 {
						return usageErrorf("--since %q is not a duration such as 6h or 72h", since)
					}
				}
				start = end.Add(-d)
			}
			if !end.After(start) {
				return usageErrorf("the end of the period must be after its start")
			}
			if granularity == "" {
				switch span := end.Sub(start); {
				case span <= 24*time.Hour:
					granularity = "5m"
				case span <= 14*24*time.Hour:
					granularity = "1h"
				default:
					granularity = "1d"
				}
			} else {
				ok := false
				for _, g := range runr.MetricGranularities {
					ok = ok || g == granularity
				}
				if !ok {
					return usageErrorf("--granularity must be 1m, 5m, 1h or 1d")
				}
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			h, err := c.ProjectMetricsHistory(cmd.Context(), p.ID, start, end, granularity)
			if err != nil {
				return err
			}
			return app.Printer().Print(h, func(t *output.TableWriter) {
				t.Header("TIME", "CPU", "MEMORY MIB", "REQ/S", "CONCURRENCY", "PODS", "COLD STARTS")
				for _, m := range h.Data {
					pods, cold := "-", "-"
					if m.PodCount != nil {
						pods = strconv.Itoa(*m.PodCount)
					}
					if m.ColdStarts != nil {
						cold = strconv.Itoa(*m.ColdStarts)
					}
					t.Row(output.Dash(m.RecordedAt), flexText(m.CPUCores, 3), flexText(m.MemoryMB, 0), flexText(m.RPS, 2),
						flexText(m.Concurrency, 2), pods, cold)
				}
				if len(h.Data) == 0 {
					t.Note("No %s snapshots between %s and %s.", h.Granularity, h.From, h.To)
				}
			})
		},
	}
	cmd.Flags().StringVar(&since, "since", "", "snapshots of the last duration (default 24h)")
	cmd.Flags().StringVar(&from, "from", "", "start (RFC 3339 or YYYY-MM-DD)")
	cmd.Flags().StringVar(&to, "to", "", "end (RFC 3339 or YYYY-MM-DD; default: now)")
	cmd.Flags().StringVar(&granularity, "granularity", "", "1m, 5m, 1h or 1d (default: from the period's length)")
	return cmd
}
