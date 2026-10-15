package cmd

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/dev"
	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func newDevCmd(app *App) *cobra.Command {
	var port int
	var noWatch bool
	var envArgs []string
	var printPlan bool
	cmd := &cobra.Command{
		Use:   "dev [path]",
		Short: "Run the project locally in Docker with hot reload",
		Long: "Run the project on your machine with the executor image and wrapper the cluster uses\n" +
			"(the same RUNR_MODE / RUNR_HANDLER / RUNR_FILENAME, code in /app/code, dependencies\n" +
			"installed with the build's commands). The container restarts when a file changes.\n" +
			"Runtime plugins run as their manifest says: exec and persistent runtimes behind RunR's\n" +
			"universal wrapper (installed from its image like the cluster's init container), versions\n" +
			"and compiled functions RunR builds per region built locally with docker build.\n" +
			"Functions with a schedule or queue trigger are served over HTTP too: send them an event\n" +
			"with `runr dev invoke --event '{...}'`. Needs Docker.",
		Example: "  runr dev\n" +
			"  runr dev --port 9000 --env DEBUG=1\n" +
			"  runr dev invoke --event '{\"name\":\"Ada\"}'",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.Dir = args[0]
			}
			pf, err := app.ProjectFile()
			if err != nil {
				return err
			}
			dir, err := app.projectDir(pf)
			if err != nil {
				return err
			}
			env := map[string]string{}
			for _, e := range envArgs {
				k, v, ok := strings.Cut(e, "=")
				if !ok {
					return usageErrorf("--env %q is not KEY=VALUE", e)
				}
				env[k] = v
			}
			opts := dev.Options{
				Project: pf, Dir: dir, Port: port, Env: env, Watch: !noWatch,
				Resolver: app.Resolver(), Docker: app.Docker, CacheDir: app.DevCacheDir,
				Out: app.Out, Err: app.Err,
			}
			if printPlan {
				plan, err := dev.MakePlan(cmd.Context(), opts)
				if err != nil {
					return err
				}
				out := map[string]any{"image": plan.Image, "container": plan.Container, "port": plan.Port,
					"docker_run": plan.RunArgs()}
				if plan.DepsFile != "" {
					out["docker_install"] = plan.InstallArgs()
				}
				prepare := plan.PrepareArgs()
				if pp := plan.Plugin; pp != nil {
					out["runtime"] = pp.Runtime
					out["run_mode"] = pp.Mode
					out["wrapper"] = pp.Wrapper
					if len(prepare) > 0 {
						out["docker_prepare"] = prepare
					}
					if pp.FunctionBuild != nil {
						out["dockerfile"] = pp.Dockerfile
					}
				}
				return app.Printer().Print(out, func(t *output.TableWriter) {
					for _, a := range prepare {
						t.Note("docker %s", shellJoin(a))
					}
					t.Note("docker %s", shellJoin(plan.RunArgs()))
					if pp := plan.Plugin; pp != nil && pp.FunctionBuild != nil {
						t.Note("")
						t.Note("# %s", pp.FunctionBuild.Dockerfile)
						t.Note("%s", strings.TrimRight(pp.Dockerfile, "\n"))
					}
				})
			}
			return dev.Run(cmd.Context(), opts)
		},
	}
	cmd.Flags().IntVar(&port, "port", 0, "local port (default: dev.port in runr.yaml, else 8080)")
	cmd.Flags().BoolVar(&noWatch, "no-watch", false, "do not restart on file changes")
	cmd.Flags().StringArrayVarP(&envArgs, "env", "e", nil, "extra environment variable KEY=VALUE (repeatable)")
	cmd.Flags().BoolVar(&printPlan, "print", false, "print the docker commands instead of running them")

	var (
		event, trigger, method, path, url string
		headers                           []string
	)
	invoke := &cobra.Command{
		Use:   "invoke",
		Short: "Send an event to the running `runr dev` session",
		Long: "Send an event the way the trigger does:\n" +
			"  http      the event as request body (--method, --path)\n" +
			"  queue     POST with CloudEvent headers, like the queue consumer delivers a stream message\n" +
			"  schedule  POST {\"trigger\":\"schedule\"} (or --event), like the scheduler starts the function;\n" +
			"            for exec and persistent runtime plugins the wrapper runs one tick in the container\n" +
			"            (RUNR_TRIGGER=schedule), for http plugins the tick is the wrapper's schedule CloudEvent\n" +
			"The trigger defaults to the one in runr.yaml. --event @file.json reads the event from a file.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			pf, _ := app.ProjectFile()
			queue := ""
			if pf != nil && pf.Trigger != nil {
				queue = pf.Trigger.Queue
			}
			var st *dev.State
			if url == "" {
				dir, err := app.projectDir(pf)
				if err != nil {
					return err
				}
				st, err = dev.ReadState(app.DevCacheDir, dir)
				if err != nil {
					return err
				}
				url = st.URL
				if trigger == "" {
					trigger = st.Trigger
				}
				if queue == "" {
					queue = st.Queue
				}
			}
			if trigger == "" && pf != nil {
				trigger = pf.TriggerType()
			}
			if trigger == "" {
				trigger = runr.TriggerHTTP
			}
			if strings.HasPrefix(event, "@") {
				data, err := os.ReadFile(strings.TrimPrefix(event, "@"))
				if err != nil {
					return err
				}
				event = string(data)
			}
			hdr := map[string]string{}
			for _, h := range headers {
				k, v, ok := strings.Cut(h, ":")
				if !ok {
					return usageErrorf("--header %q is not Name: value", h)
				}
				hdr[strings.TrimSpace(k)] = strings.TrimSpace(v)
			}
			var res *dev.InvokeResult
			var err error
			if trigger == runr.TriggerSchedule && st != nil && st.ScheduleExec && event == "" {
				// Runtime plugins on the wrapper: one tick the way the CronJob runs it.
				res, err = dev.InvokeSchedule(cmd.Context(), app.Docker, st.Container)
				if err == nil && res.Logs != "" && !app.Quiet {
					fmt.Fprint(app.Err, res.Logs)
				}
			} else {
				res, err = dev.Invoke(cmd.Context(), dev.InvokeOptions{
					URL: url, Trigger: trigger, Event: event, Method: method, Path: path, Queue: queue,
					Header: hdr, Client: &http.Client{Timeout: 5 * time.Minute},
					ScheduleCloudEvent: st != nil && (st.CloudEventSchedule || st.ScheduleExec),
				})
			}
			if err != nil {
				if strings.Contains(err.Error(), "not valid JSON") {
					return usageErrorf("%v", err)
				}
				return err
			}
			if app.Printer().Machine() {
				out := map[string]any{"status": res.Status, "headers": res.Headers, "duration_ms": res.Duration.Milliseconds()}
				if res.Logs != "" {
					out["logs"] = res.Logs
				}
				var body any
				if json.Unmarshal([]byte(res.Body), &body) == nil {
					out["body"] = body
				} else {
					out["body"] = res.Body
				}
				return app.Printer().Print(out, nil)
			}
			app.Progress("%d %s (%s)", res.Status, http.StatusText(res.Status), res.Duration.Round(time.Millisecond))
			fmt.Fprintln(app.Out, res.Body)
			if res.Status >= 500 {
				return fmt.Errorf("the function answered %d", res.Status)
			}
			return nil
		},
	}
	f := invoke.Flags()
	f.StringVar(&event, "event", "", "event JSON, or @file.json")
	f.StringVar(&trigger, "trigger", "", "http, schedule or queue (default: the project's trigger)")
	f.StringVarP(&method, "method", "X", "", "HTTP method of an http invocation (default: POST with an event, GET without)")
	f.StringVar(&path, "path", "/", "request path of an http invocation")
	f.StringVar(&url, "url", "", "dev server URL (default: the running session's)")
	f.StringArrayVarP(&headers, "header", "H", nil, "extra header \"Name: value\" (repeatable)")
	cmd.AddCommand(invoke)
	return cmd
}

func shellJoin(args []string) string {
	out := make([]string, len(args))
	for i, a := range args {
		if a == "" || strings.ContainsAny(a, " \t\"'$;&|<>*?(){}[]\\`!#") {
			out[i] = "'" + strings.ReplaceAll(a, "'", `'\''`) + "'"
		} else {
			out[i] = a
		}
	}
	return strings.Join(out, " ")
}
