package cmd

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// runr loadtest: load tests of the project (docs/load-test.md) — a k6 Job in the project's
// region against the project's own URL, its results and the last runs side by side.

func init() {
	registerCommand(newLoadTestCmd)
}

func newLoadTestCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "loadtest",
		Aliases: []string{"load-test"},
		Short:   "Load tests of the project: run one, follow it, compare the last runs",
		Long: "A load test runs k6 inside the project's region: 10, 50 or 100 concurrent users send one\n" +
			"request (GET, or POST with a small body) to a path of the project's own URL for 30 s, 1 min\n" +
			"or 5 min. It reports p50 / p95 / p99 latency, the error rate, requests per second, the pods\n" +
			"the project scaled to and its cold starts. The test traffic is metered and billed like any\n" +
			"other traffic of the project. One test per project at a time; the organization has a daily\n" +
			"limit. Reading needs metrics.view, running a test projects.deploy.",
		Example: "  runr loadtest run /api/orders --users 50 --duration 1m\n" +
			"  runr loadtest run /api/orders --method POST --body '{\"item\":42}' --users 10 --duration 30s\n" +
			"  runr loadtest            # the last runs side by side\n" +
			"  runr loadtest get 01JLOADTEST0000000000000001",
	}

	var limit int
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list", "compare"},
		Short:   "The last runs side by side, with the choices and the day's count",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if limit < 1 || limit > 50 {
				return usageErrorf("--limit must be between 1 and 50")
			}
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			tests, opts, err := c.ListLoadTests(cmd.Context(), p.ID, limit)
			if err != nil {
				return err
			}
			out := struct {
				Tests   []runr.LoadTest       `json:"tests"`
				Options *runr.LoadTestOptions `json:"options"`
			}{tests, opts}
			return app.Printer().Print(out, func(t *output.TableWriter) { loadTestCompare(t, tests, opts) })
		},
	}
	ls.Flags().IntVar(&limit, "limit", 5, "runs to show (1-50)")
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	var rf struct {
		method, body, bodyFile, contentType, host string
		users                                     int
		duration, timeout                         time.Duration
		noWait                                    bool
	}
	run := &cobra.Command{
		Use:   "run [PATH]",
		Short: "Run a load test against a path of the project's URL and wait for its results",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			path := "/"
			if len(args) == 1 {
				path = args[0]
			}
			if !strings.HasPrefix(path, "/") {
				return usageErrorf("the path must start with / (the host is the project's: --host picks one of its domains)")
			}
			if rf.duration%time.Second != 0 || rf.duration <= 0 {
				return usageErrorf("--duration must be whole seconds, e.g. 30s, 1m or 5m")
			}
			method := strings.ToUpper(rf.method)
			if method != "GET" && method != "POST" {
				return usageErrorf("--method must be GET or POST")
			}
			body := rf.body
			if rf.bodyFile != "" {
				if body != "" {
					return usageErrorf("give --body or --body-file, not both")
				}
				data, err := os.ReadFile(rf.bodyFile)
				if err != nil {
					return err
				}
				body = string(data)
			}
			if body != "" && method != "POST" {
				return usageErrorf("a request body needs --method POST")
			}

			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			in := runr.LoadTestInput{
				Host: rf.host, Path: path, Method: method, Body: body, Users: rf.users,
				DurationSeconds: int(rf.duration / time.Second),
			}
			if body != "" {
				in.ContentType = rf.contentType
			}
			lt, err := c.StartLoadTest(cmd.Context(), p.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Load test %s started: %s %s, %d users for %s. The test traffic is metered like any other traffic.",
				lt.ID, lt.Method, lt.URL, lt.Users, output.Duration(time.Duration(lt.DurationSeconds)*time.Second))
			if rf.noWait {
				return app.Printer().Print(lt, func(t *output.TableWriter) { loadTestDetail(t, lt) })
			}

			timeout := rf.timeout
			if timeout <= 0 {
				// the test, the start of its Job (an image pull on a fresh node) and the results
				timeout = time.Duration(lt.DurationSeconds)*time.Second + 5*time.Minute
			}
			done, werr := c.WaitLoadTest(cmd.Context(), p.ID, lt, app.waitOptions(timeout, "load test"))
			if perr := app.Printer().Print(done, func(t *output.TableWriter) { loadTestDetail(t, done) }); perr != nil {
				return perr
			}
			return werr
		},
	}
	run.Flags().StringVar(&rf.method, "method", "GET", "GET or POST")
	run.Flags().StringVar(&rf.body, "body", "", "request body of a POST (a few KiB at most)")
	run.Flags().StringVar(&rf.bodyFile, "body-file", "", "read the POST body from this file")
	run.Flags().StringVar(&rf.contentType, "content-type", "application/json", "Content-Type of the body")
	run.Flags().StringVar(&rf.host, "host", "", "the project's host to test: its function host (default) or one of its active custom domains")
	run.Flags().IntVarP(&rf.users, "users", "u", 10, "concurrent users: 10, 50 or 100")
	run.Flags().DurationVarP(&rf.duration, "duration", "d", 30*time.Second, "how long the test runs: 30s, 1m or 5m")
	run.Flags().BoolVar(&rf.noWait, "no-wait", false, "start the test and return (follow it with runr loadtest get ID)")
	run.Flags().DurationVar(&rf.timeout, "timeout", 0, "longest wait for the results (default: the duration plus 5m)")
	cmd.AddCommand(run)

	cmd.AddCommand(&cobra.Command{
		Use:     "get ID",
		Aliases: []string{"show"},
		Short:   "One load test with its results",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			lt, err := c.GetLoadTest(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(lt, func(t *output.TableWriter) { loadTestDetail(t, lt) })
		},
	})

	cmd.AddCommand(&cobra.Command{
		Use:     "cancel ID",
		Aliases: []string{"stop"},
		Short:   "Stop a running load test",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			lt, err := c.CancelLoadTest(cmd.Context(), p.ID, args[0])
			if err != nil {
				return err
			}
			app.Progress("Load test %s: %s.", lt.ID, lt.Status)
			return app.Printer().Print(lt, func(t *output.TableWriter) { loadTestDetail(t, lt) })
		},
	})

	return cmd
}

// loadTestDetail prints one test: what it sent and, once completed, what it measured.
func loadTestDetail(t *output.TableWriter, lt *runr.LoadTest) {
	t.KV("Load test", lt.ID)
	t.KV("Status", lt.Status)
	t.KV("Request", lt.Method+" "+lt.URL)
	t.KV("Load", fmt.Sprintf("%d users for %s", lt.Users, output.Duration(time.Duration(lt.DurationSeconds)*time.Second)))
	if lt.StartedAt != "" {
		t.KV("Started", lt.StartedAt)
	}
	if lt.FinishedAt != "" {
		t.KV("Finished", lt.FinishedAt)
	}
	if lt.Error != "" {
		t.KV("Error", lt.Error)
	}
	r := lt.Results
	if r == nil {
		return
	}
	t.KV("Latency", fmt.Sprintf("p50 %s, p95 %s, p99 %s, max %s", obsMsPtr(r.LatencyMs.P50), obsMsPtr(r.LatencyMs.P95), obsMsPtr(r.LatencyMs.P99), obsMsPtr(r.LatencyMs.Max)))
	t.KV("Requests", fmt.Sprintf("%d (%s/s)", r.Requests, loadTestRate(r.RPS)))
	t.KV("Errors", fmt.Sprintf("%s (%d requests)", loadTestPercent(r.ErrorRate), r.FailedRequests))
	pods := obsIntPtr(r.PodsPeak)
	if r.PodsSource == "sessions" {
		pods += " (from the function sessions)"
	}
	t.KV("Pods at peak", pods)
	t.KV("Cold starts", obsIntPtr(r.ColdStarts))
}

// loadTestCompare prints the last completed runs side by side, one column per run.
func loadTestCompare(t *output.TableWriter, tests []runr.LoadTest, opts *runr.LoadTestOptions) {
	var runs []runr.LoadTest
	for _, lt := range tests {
		if lt.Status == runr.LoadTestCompleted && lt.Results != nil {
			runs = append(runs, lt)
		}
	}
	for _, lt := range tests {
		if !lt.Done() {
			t.KV("Running", fmt.Sprintf("%s (%s, %s %s)", lt.ID, lt.Status, lt.Method, lt.URL))
		}
	}
	if opts != nil {
		t.KV("Today", fmt.Sprintf("%d of %d tests", opts.UsedToday, opts.DailyLimit))
	}
	if len(runs) == 0 {
		t.Note("No completed load test yet; run one with `runr loadtest run /path --users 10 --duration 30s`.")
		return
	}

	header := []string{"METRIC"}
	for _, lt := range runs {
		header = append(header, loadTestShortID(lt.ID))
	}
	t.Header(header...)
	row := func(name string, value func(r *runr.LoadTestResults, lt runr.LoadTest) string) {
		cols := []string{name}
		for _, lt := range runs {
			cols = append(cols, value(lt.Results, lt))
		}
		t.Row(cols...)
	}
	row("when", func(_ *runr.LoadTestResults, lt runr.LoadTest) string { return output.Dash(lt.FinishedAt) })
	row("request", func(_ *runr.LoadTestResults, lt runr.LoadTest) string {
		return output.Truncate(lt.Method+" "+lt.Path, 24)
	})
	row("load", func(_ *runr.LoadTestResults, lt runr.LoadTest) string {
		return fmt.Sprintf("%d × %s", lt.Users, output.Duration(time.Duration(lt.DurationSeconds)*time.Second))
	})
	row("p50", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return obsMsPtr(r.LatencyMs.P50) })
	row("p95", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return obsMsPtr(r.LatencyMs.P95) })
	row("p99", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return obsMsPtr(r.LatencyMs.P99) })
	row("errors", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return loadTestPercent(r.ErrorRate) })
	row("req/s", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return loadTestRate(r.RPS) })
	row("pods", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return obsIntPtr(r.PodsPeak) })
	row("cold starts", func(r *runr.LoadTestResults, _ runr.LoadTest) string { return obsIntPtr(r.ColdStarts) })
}

// loadTestShortID is the end of a ULID: unique enough for a column header.
func loadTestShortID(id string) string {
	if len(id) > 8 {
		return id[len(id)-8:]
	}
	return id
}

func loadTestPercent(rate float64) string {
	return strconv.FormatFloat(float64(int64(rate*10000+0.5))/100, 'f', -1, 64) + "%"
}

func loadTestRate(rps float64) string {
	return strconv.FormatFloat(float64(int64(rps*10+0.5))/10, 'f', -1, 64)
}
