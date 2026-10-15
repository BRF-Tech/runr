package cmd

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

func init() { registerCommand(newSandboxCmd) }

// remoteExitError carries the exit status of a command that ran in a sandbox: runr exits with
// it (Execute prints nothing more, the command's output was shown).
type remoteExitError struct {
	code int
}

func (e *remoteExitError) Error() string { return fmt.Sprintf("the command exited with %d", e.code) }

// ExitStatus is the process exit code.
func (e *remoteExitError) ExitStatus() int {
	if e.code < 0 {
		return ExitError
	}
	return e.code
}

// exitOf turns a command's exit code into the error runr returns.
func exitOf(code int) error {
	if code == 0 {
		return nil
	}
	return &remoteExitError{code: code}
}

func newSandboxCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "sandbox",
		Aliases: []string{"sandboxes", "sbx"},
		Short:   "Run commands and code in isolated sandboxes",
		Long: "Sandboxes are short-lived isolated pods (a Kata Containers micro-VM or gVisor) of your\n" +
			"organization: create one, run commands and code in it with streamed output, copy files in\n" +
			"and out, and remove it again. A sandbox ends at its TTL or after its idle timeout.",
		Example: "  SBX=$(runr sandbox create --runtime python --ttl 30m)\n" +
			"  runr sandbox exec $SBX -- python3 -c 'print(6 * 7)'\n" +
			"  runr sandbox cp ./data $SBX:/workspace/data\n" +
			"  runr sandbox run analysis.py --outputs ./charts\n" +
			"  runr sandbox rm $SBX --yes",
	}
	cmd.AddCommand(newSandboxCreateCmd(app), newSandboxLsCmd(app), newSandboxGetCmd(app), newSandboxRmCmd(app),
		newSandboxExecCmd(app), newSandboxRunCmd(app), newSandboxCpCmd(app), newSandboxFilesCmd(app),
		newSandboxLogsCmd(app))
	cmd.AddCommand(sandboxExtraCmds(app)...)
	return cmd
}

// sandboxCreateFlags are the options of a new sandbox (create, and run without --sandbox).
type sandboxCreateFlags struct {
	runtime, version, image string
	cpu, memory, disk       string
	ttl, idleTimeout        time.Duration
	allow                   []string
	env, labels             []string
	region, class           string
	writableRoot            bool
	pidsLimit               int
	noWait                  bool
	startTimeout            time.Duration
}

// register adds the flags; withEnv adds --env and --label (run has an --env of its own, for the
// command).
func (f *sandboxCreateFlags) register(c *cobra.Command, withEnv bool) {
	fl := c.Flags()
	fl.StringVar(&f.runtime, "runtime", "", "runtime of the catalog (default: python)")
	fl.StringVar(&f.version, "version", "", "runtime version (default: the runtime's default)")
	fl.StringVar(&f.image, "image", "", "any image instead of a runtime (needs /bin/sh, and tar for cp)")
	if withEnv {
		fl.StringArrayVarP(&f.env, "env", "e", nil, "environment variable KEY=VALUE of every command (repeatable; stored encrypted)")
		fl.StringArrayVar(&f.labels, "label", nil, "label KEY=VALUE (metadata, repeatable)")
	}
	fl.StringVar(&f.cpu, "cpu", "", "CPU: 500m, 0.5 or 1 (default 500m)")
	fl.StringVar(&f.memory, "memory", "", "memory in MiB or a quantity such as 1Gi (default 512)")
	fl.StringVar(&f.disk, "disk", "", "disk in MiB or a quantity (default 1024)")
	fl.DurationVar(&f.ttl, "ttl", 0, "how long the sandbox may live (default: the region's, 15m)")
	fl.DurationVar(&f.idleTimeout, "idle-timeout", 0, "remove the sandbox after this long without activity (0 = never; default 10m)")
	fl.StringSliceVar(&f.allow, "allow", nil, "allow egress to an address, CIDR or host name with optional port (repeatable; default: no network)")
	fl.StringVar(&f.region, "region", "", "region name or id (default: the first region with sandboxes)")
	fl.StringVar(&f.class, "class", "", "sandbox class: auto, kata, gvisor or a class name such as kata-fc (default auto)")
	fl.BoolVar(&f.writableRoot, "writable-root", false, "make the root file system writable")
	fl.IntVar(&f.pidsLimit, "pids-limit", 0, "process limit, 16-4096 (default 256)")
	fl.DurationVar(&f.startTimeout, "start-timeout", 2*time.Minute, "how long to wait until the sandbox runs")
}

func (f *sandboxCreateFlags) input(c *cobra.Command) (runr.CreateSandboxInput, error) {
	in := runr.CreateSandboxInput{
		Runtime: f.runtime, Version: f.version, Image: f.image, CPU: f.cpu, Memory: f.memory, Disk: f.disk,
		TTLSeconds: sbxSeconds(f.ttl), Region: f.region, SandboxClass: f.class, PidsLimit: f.pidsLimit,
	}
	if c.Flags().Changed("idle-timeout") {
		idle := sbxSeconds(f.idleTimeout)
		in.IdleTimeout = &idle
	}
	if len(f.allow) > 0 {
		in.Network = runr.SandboxEgress(f.allow)
	}
	if len(f.env) > 0 {
		env, err := parseKeyValues("--env", f.env)
		if err != nil {
			return in, err
		}
		in.Env = env
	}
	if len(f.labels) > 0 {
		md, err := parseKeyValues("--label", f.labels)
		if err != nil {
			return in, err
		}
		in.Metadata = md
	}
	if f.writableRoot {
		ro := false
		in.ReadOnlyRoot = &ro
	}
	if f.noWait {
		w := false
		in.Wait = &w
	}
	if st := sbxSeconds(f.startTimeout); st > 0 {
		if st < 5 {
			st = 5
		}
		if st > 600 {
			st = 600
		}
		in.StartTimeout = st
	}
	return in, nil
}

// createSandbox creates a sandbox and waits until it runs (unless --no-wait).
func createSandbox(ctx context.Context, app *App, c *runr.Client, f *sandboxCreateFlags, in runr.CreateSandboxInput) (*runr.Sandbox, error) {
	s, err := c.CreateSandbox(ctx, in)
	if err != nil {
		return s, err
	}
	if s.Status == runr.SandboxPending && !f.noWait {
		app.Progress("Sandbox %s is starting…", s.ID)
		s, err = c.WaitSandbox(ctx, s.ID, app.waitOptions(f.startTimeout, "Sandbox "+s.ID))
		if err != nil {
			return s, err
		}
	}
	return s, nil
}

func sandboxStarted(app *App, s *runr.Sandbox) {
	if s.Status != runr.SandboxRunning {
		app.Progress("Sandbox %s: %s", s.ID, s.Status)
		return
	}
	how := ""
	if s.StartMS != nil {
		how = fmt.Sprintf(", started in %d ms", *s.StartMS)
	}
	if s.FromPool {
		how += " (warm pool)"
	}
	app.Progress("Sandbox %s running: %s in %s%s", s.ID, output.Dash(s.SandboxClass), output.Dash(s.RegionName()), how)
}

func newSandboxCreateCmd(app *App) *cobra.Command {
	f := &sandboxCreateFlags{}
	c := &cobra.Command{
		Use:   "create",
		Short: "Create a sandbox and print its id",
		Long: "Create a sandbox and wait until it runs. stdout is the sandbox id (-o json: the sandbox),\n" +
			"so SBX=$(runr sandbox create) works in scripts. Without --allow the sandbox has no network.",
		Example: "  runr sandbox create --runtime python --ttl 30m --allow pypi.org:443 --allow files.pythonhosted.org:443\n" +
			"  runr sandbox create --image ghcr.io/acme/tools:1.2 --class gvisor --memory 1Gi -e API_KEY=$KEY",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			in, err := f.input(cmd)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := createSandbox(cmd.Context(), app, c, f, in)
			if err != nil {
				return err
			}
			sandboxStarted(app, s)
			return app.Printer().Print(s, func(t *output.TableWriter) { t.Note("%s", s.ID) })
		},
	}
	f.register(c, true)
	c.Flags().BoolVar(&f.noWait, "no-wait", false, "return at once, while the sandbox is still starting")
	return c
}

func newSandboxLsCmd(app *App) *cobra.Command {
	var status, region, runtime string
	var limit int
	c := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List sandboxes (default: the active ones)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			opts := runr.SandboxListOptions{Status: status, Runtime: runtime, PerPage: limit}
			// The API filters by region id; a name is matched here.
			if region != "" && runr.LooksLikeULID(region) {
				opts.Region = region
			}
			list, page, err := c.ListSandboxes(cmd.Context(), opts)
			if err != nil {
				return err
			}
			if region != "" && opts.Region == "" {
				kept := list[:0]
				for _, s := range list {
					if s.Region != nil && strings.EqualFold(s.Region.Name, region) {
						kept = append(kept, s)
					}
				}
				list = kept
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ID", "STATUS", "RUNTIME", "CLASS", "REGION", "AGE", "EXPIRES")
				for _, s := range list {
					rt := s.Runtime
					if rt == "" {
						rt = s.Image
					} else if s.RuntimeVersion != "" {
						rt += " " + s.RuntimeVersion
					}
					t.Row(s.ID, s.Status, output.Dash(rt), output.Dash(s.SandboxClass), output.Dash(s.RegionName()),
						sbxAge(s.CreatedAt), sbxUntil(s.ExpiresAt, s.Ended()))
				}
				if page != nil && page.Total > len(list) && region == "" {
					t.Note("%d of %d sandboxes; --limit shows more.", len(list), page.Total)
				}
			})
		},
	}
	c.Flags().StringVar(&status, "status", "", "active (default), running, paused, pending, ended or all")
	c.Flags().StringVar(&region, "region", "", "only sandboxes of this region (name or id)")
	c.Flags().StringVar(&runtime, "runtime", "", "only sandboxes of this runtime")
	c.Flags().IntVar(&limit, "limit", 50, "at most this many sandboxes (up to 200)")
	return c
}

func newSandboxGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get ID",
		Short: "Show a sandbox",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := c.GetSandbox(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(s, func(t *output.TableWriter) { sandboxDetail(t, s) })
		},
	}
}

func sandboxDetail(t *output.TableWriter, s *runr.Sandbox) {
	t.KV("ID", s.ID)
	t.KV("Status", s.Status)
	if s.EndReason != "" {
		t.KV("Ended", s.EndReason+" "+s.EndedAt)
	}
	if s.Error != "" {
		t.KV("Error", s.Error)
	}
	rt := s.Runtime
	if s.RuntimeVersion != "" {
		rt += " " + s.RuntimeVersion
	}
	t.KV("Runtime", output.Dash(rt))
	t.KV("Image", output.Dash(s.Image))
	t.KV("Class", fmt.Sprintf("%s (%s)", output.Dash(s.SandboxClass), output.Dash(s.Isolation)))
	t.KV("Region", output.Dash(s.RegionName()))
	r := s.Resources
	t.KV("Resources", fmt.Sprintf("cpu %s, memory %d MiB, disk %d MiB, %d processes", output.Dash(r.CPU), r.MemoryMB, r.DiskMB, r.PidsLimit))
	net := output.Dash(s.Network.Mode)
	if len(s.Network.EgressAllow) > 0 {
		net += ": " + strings.Join(s.Network.EgressAllow, ", ")
	}
	t.KV("Network", net)
	t.KV("Root file system", map[bool]string{true: "read-only", false: "writable"}[s.ReadOnlyRoot])
	if len(s.EnvKeys) > 0 {
		t.KV("Environment", strings.Join(s.EnvKeys, ", "))
	}
	if len(s.Metadata) > 0 {
		keys := make([]string, 0, len(s.Metadata))
		for k := range s.Metadata {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		parts := make([]string, len(keys))
		for i, k := range keys {
			parts[i] = fmt.Sprintf("%s=%v", k, s.Metadata[k])
		}
		t.KV("Labels", strings.Join(parts, ", "))
	}
	t.KV("Created", output.Dash(s.CreatedAt))
	if s.ExpiresAt != "" {
		t.KV("Expires", s.ExpiresAt)
	}
	if s.IdleExpiresAt != "" {
		t.KV("Idle until", s.IdleExpiresAt)
	}
	if s.StartMS != nil {
		t.KV("Start", fmt.Sprintf("%d ms%s", *s.StartMS, map[bool]string{true: " (warm pool)", false: ""}[s.FromPool]))
	}
}

func newSandboxRmCmd(app *App) *cobra.Command {
	var yes, all bool
	c := &cobra.Command{
		Use:     "rm ID...",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove sandboxes",
		RunE: func(cmd *cobra.Command, args []string) error {
			if all == (len(args) > 0) {
				return usageErrorf("give sandbox ids or --all")
			}
			ctx := cmd.Context()
			c, err := app.Client()
			if err != nil {
				return err
			}
			ids := args
			if all {
				list, _, err := c.ListSandboxes(ctx, runr.SandboxListOptions{Status: "active", PerPage: 200})
				if err != nil {
					return err
				}
				ids = nil
				for _, s := range list {
					ids = append(ids, s.ID)
				}
				if len(ids) == 0 {
					app.Progress("No active sandboxes.")
					return app.Printer().Print([]runr.Sandbox{}, func(*output.TableWriter) {})
				}
			}
			if err := app.confirm("Remove "+countLabel(len(ids), "sandbox"), yes); err != nil {
				return err
			}
			removed := []runr.Sandbox{}
			for _, id := range ids {
				s, err := c.DeleteSandbox(ctx, id)
				if err != nil {
					return err
				}
				if s.ID == "" {
					s.ID = id
				}
				removed = append(removed, *s)
				app.Progress("Removed %s.", id)
			}
			return app.Printer().Print(removed, func(*output.TableWriter) {})
		},
	}
	c.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	c.Flags().BoolVar(&all, "all", false, "remove every active sandbox of the organization")
	return c
}

// sandboxCommandFlags are the options of exec and run.
type sandboxCommandFlags struct {
	cwd     string
	env     []string
	timeout time.Duration
}

func (f *sandboxCommandFlags) register(c *cobra.Command) {
	c.Flags().StringVar(&f.cwd, "cwd", "", "working directory (default /workspace)")
	c.Flags().StringArrayVarP(&f.env, "env", "e", nil, "extra environment variable KEY=VALUE for this command (repeatable)")
	c.Flags().DurationVar(&f.timeout, "timeout", 0, "kill the command after this long (default 60s, up to 1h)")
}

func newSandboxExecCmd(app *App) *cobra.Command {
	f := &sandboxCommandFlags{}
	var stdin, useWS bool
	c := &cobra.Command{
		Use:   "exec [flags] ID [--] CMD [ARG...]",
		Short: "Run a command in a sandbox, streaming its output",
		Long: "Run a command in a running sandbox. Its stdout and stderr are streamed to runr's stdout and\n" +
			"stderr as they are written, and runr exits with the command's exit code (137 when the\n" +
			"timeout killed it). One argument is run by /bin/sh -c (pipes and && work); several are the\n" +
			"argv. Flags go before the sandbox id. -i sends runr's stdin to the command. With -o json\n" +
			"or yaml the output is collected and printed as one result.\n\n" +
			"Two transports carry the command. Server-Sent Events send stdin up front (all of it, read\n" +
			"to its end) and stream the output. The exec WebSocket sends stdin while the command runs,\n" +
			"so an interactive program reads each line as it is typed. runr uses the WebSocket for -i\n" +
			"on a terminal and Server-Sent Events otherwise; --ws picks the WebSocket, --ws=false\n" +
			"Server-Sent Events. Neither gives the command a TTY: runr sandbox terminal opens a shell\n" +
			"with one.",
		Example: "  runr sandbox exec $SBX -- python3 -c 'print(6 * 7)'\n" +
			"  runr sandbox exec $SBX 'pip install --user pandas && python3 train.py'\n" +
			"  cat data.csv | runr sandbox exec -i $SBX -- python3 load.py\n" +
			"  runr sandbox exec -i $SBX -- python3 -i          # on a terminal: the exec WebSocket\n" +
			"  runr sandbox exec --ws -i $SBX -- ./consumer < events.ndjson\n" +
			"  runr sandbox exec --cwd /workspace/app -e DEBUG=1 --timeout 10m $SBX -- make test",
		Args: func(cmd *cobra.Command, args []string) error {
			n := len(args)
			if n > 1 && args[1] == "--" {
				n--
			}
			if n < 2 {
				return fmt.Errorf("give the sandbox id and the command (runr sandbox exec ID -- CMD)")
			}
			return nil
		},
		RunE: func(cmd *cobra.Command, args []string) error {
			// Flags end at the sandbox id, so a -- after it reaches the arguments.
			if len(args) > 1 && args[1] == "--" {
				args = append([]string{args[0]}, args[2:]...)
			}
			in := runr.SandboxExecInput{Cwd: f.cwd, Timeout: sbxSeconds(f.timeout)}
			if len(args) == 2 {
				in.Cmd = args[1]
			} else {
				in.Cmd = args[1:]
			}
			env, err := parseKeyValues("--env", f.env)
			if err != nil {
				return err
			}
			if len(env) > 0 {
				in.Env = env
			}
			if !cmd.Flags().Changed("ws") {
				// Typed input reaches the command line by line only over the WebSocket; Server-Sent
				// Events would wait for the end of stdin (Ctrl+D) before the command starts.
				useWS = stdin && app.interactive()
			}
			if useWS {
				return sandboxExecWS(cmd.Context(), app, args[0], in, stdin)
			}
			if stdin {
				data, err := io.ReadAll(app.In)
				if err != nil {
					return err
				}
				in.StdinBase64 = base64.StdEncoding.EncodeToString(data)
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			if app.Printer().Machine() {
				res, err := c.ExecSandbox(ctx, args[0], in)
				if err != nil {
					return err
				}
				if err := app.Printer().Print(res, nil); err != nil {
					return err
				}
				return exitOf(res.ExitCode)
			}
			res, err := c.StreamSandboxExec(ctx, args[0], in, runr.SandboxStream{Stdout: app.Out, Stderr: app.Err})
			return sandboxStreamEnd(ctx, app, res, err)
		},
	}
	c.Flags().SetInterspersed(false)
	f.register(c)
	c.Flags().BoolVarP(&stdin, "stdin", "i", false, "send runr's stdin to the command")
	c.Flags().BoolVar(&useWS, "ws", false, "run over the exec WebSocket, which sends stdin while the command runs (default: with -i on a terminal; --ws=false uses Server-Sent Events)")
	return c
}

// sandboxExecWS runs a command over the exec WebSocket: the output streams to runr's stdout
// and stderr (or is collected for -o json|yaml), and with stdin runr's stdin is sent while the
// command runs.
func sandboxExecWS(ctx context.Context, app *App, id string, in runr.SandboxExecInput, stdin bool) error {
	c, err := app.Client()
	if err != nil {
		return err
	}
	var input io.Reader
	if stdin {
		input = app.In
	}
	if !app.Printer().Machine() {
		res, err := c.StreamSandboxExecWS(ctx, id, in, runr.SandboxStream{Stdout: app.Out, Stderr: app.Err}, input)
		return sandboxStreamEnd(ctx, app, res, err)
	}
	var stdout, stderr bytes.Buffer
	res, err := c.StreamSandboxExecWS(ctx, id, in, runr.SandboxStream{Stdout: &stdout, Stderr: &stderr}, input)
	if err != nil {
		if ctx.Err() != nil {
			return &remoteExitError{code: 130}
		}
		return err
	}
	out := runr.SandboxExecResult{ExitCode: res.ExitCode, TimedOut: res.TimedOut, DurationMS: res.DurationMS}
	out.Stdout, out.StdoutBase64 = runr.TextOrBase64Fields(stdout.Bytes())
	out.Stderr, out.StderrBase64 = runr.TextOrBase64Fields(stderr.Bytes())
	if err := app.Printer().Print(out, nil); err != nil {
		return err
	}
	return exitOf(res.ExitCode)
}

// sandboxStreamEnd reports how a streamed command ended and returns its exit status.
func sandboxStreamEnd(ctx context.Context, app *App, res *runr.SandboxStreamResult, err error) error {
	if err != nil {
		if ctx.Err() != nil {
			// Ctrl+C: the API stops the command when the client goes away.
			fmt.Fprintln(app.Err, "Interrupted; the command was stopped.")
			return &remoteExitError{code: 130}
		}
		return err
	}
	if res.TimedOut {
		app.Warn("the command was killed at its timeout (exit code %d)", res.ExitCode)
	}
	if res.Truncated {
		app.Warn("the output was cut at the API's output limit")
	}
	return exitOf(res.ExitCode)
}

// Languages of the code interpreter: the runtime a throw-away sandbox gets, and the language
// a file extension names.
var (
	sandboxLangRuntime = map[string]string{
		"python": "python", "py": "python", "python3": "python",
		"javascript": "nodejs", "js": "nodejs", "node": "nodejs", "nodejs": "nodejs",
		"typescript": "nodejs", "ts": "nodejs", "php": "php", "go": "go", "golang": "go",
	}
	sandboxExtLang = map[string]string{
		".py": "python", ".js": "javascript", ".mjs": "javascript", ".cjs": "javascript", ".ts": "typescript",
		".sh": "bash", ".bash": "bash", ".php": "php", ".rb": "ruby", ".go": "go",
	}
)

func newSandboxRunCmd(app *App) *cobra.Command {
	f := &sandboxCommandFlags{}
	cf := &sandboxCreateFlags{}
	var lang, code, sandboxID, outputs string
	var keep bool
	c := &cobra.Command{
		Use:   "run [FILE|-]",
		Short: "Run code with the code interpreter, streaming its output",
		Long: "Run a file (or - for stdin, or --code) with the interpreter of its language in a sandbox:\n" +
			"python, javascript, typescript, bash, sh, php, ruby, go or a runtime of the catalog. Without\n" +
			"--sandbox a throw-away sandbox of the language's runtime is created and removed afterwards\n" +
			"(--keep leaves it running). Output streams as it is written; afterwards the files the run\n" +
			"changed under /workspace and /output are listed, and images it wrote to /output are saved\n" +
			"with --outputs DIR. runr exits with the program's exit code.",
		Example: "  runr sandbox run analysis.py --outputs ./charts\n" +
			"  echo 'console.log(1 + 1)' | runr sandbox run - --lang javascript\n" +
			"  runr sandbox run --sandbox $SBX --code 'import sys; print(sys.version)'",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if (len(args) == 1) == (code != "") {
				return usageErrorf("give a FILE (or - for stdin) or --code")
			}
			if len(args) == 1 {
				data, err := readFileArg(app, args[0])
				if err != nil {
					return err
				}
				code = string(data)
				if lang == "" {
					lang = sandboxExtLang[strings.ToLower(filepath.Ext(args[0]))]
				}
			}
			if strings.TrimSpace(code) == "" {
				return usageErrorf("the code is empty")
			}
			env, err := parseKeyValues("--env", f.env)
			if err != nil {
				return err
			}
			in := runr.SandboxRunInput{Code: code, Language: lang, Cwd: f.cwd, Timeout: sbxSeconds(f.timeout)}
			if len(env) > 0 {
				in.Env = env
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()

			id := sandboxID
			if id == "" {
				ci, err := cf.input(cmd)
				if err != nil {
					return err
				}
				if ci.Runtime == "" && ci.Image == "" {
					ci.Runtime = sandboxLangRuntime[strings.ToLower(lang)]
				}
				ci.Metadata = map[string]string{"created_by": "runr sandbox run"}
				s, err := createSandbox(ctx, app, c, cf, ci)
				if err != nil {
					return err
				}
				id = s.ID
				sandboxStarted(app, s)
				if keep {
					defer app.Progress("Kept sandbox %s (runr sandbox rm %s --yes removes it).", id, id)
				} else {
					defer func() {
						// Also after Ctrl+C: the removal gets a context of its own.
						rmCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
						defer cancel()
						if _, err := c.DeleteSandbox(rmCtx, id); err != nil {
							app.Warn("could not remove sandbox %s: %v", id, err)
						} else {
							app.Progress("Removed sandbox %s.", id)
						}
					}()
				}
			}

			if app.Printer().Machine() {
				res, err := c.RunSandboxCode(ctx, id, in)
				if err != nil {
					return err
				}
				if err := saveSandboxOutputs(app, outputs, res.Outputs); err != nil {
					return err
				}
				out := map[string]any{"sandbox_id": id, "kept": keep || sandboxID != "", "result": res}
				if err := app.Printer().Print(out, nil); err != nil {
					return err
				}
				return exitOf(res.ExitCode)
			}
			res, err := c.StreamSandboxRun(ctx, id, in, runr.SandboxStream{Stdout: app.Out, Stderr: app.Err})
			if err == nil && res.Result != nil {
				for _, fc := range res.Result.FilesChanged {
					app.Progress("changed %s (%d bytes)", fc.Path, fc.Size)
				}
				if serr := saveSandboxOutputs(app, outputs, res.Result.Outputs); serr != nil {
					return serr
				}
				if outputs == "" && len(res.Result.Outputs) > 0 {
					app.Progress("%s in /output; --outputs DIR saves them.", countLabel(len(res.Result.Outputs), "image"))
				}
			}
			return sandboxStreamEnd(ctx, app, res, err)
		},
	}
	f.register(c)
	c.Flags().StringVar(&lang, "lang", "", "language (default: from the file extension, else the sandbox's runtime)")
	c.Flags().StringVar(&code, "code", "", "code to run instead of a file")
	c.Flags().StringVar(&sandboxID, "sandbox", "", "run in this sandbox instead of a throw-away one")
	c.Flags().BoolVar(&keep, "keep", false, "keep the throw-away sandbox running afterwards")
	c.Flags().StringVar(&outputs, "outputs", "", "save the images the run wrote to /output into this directory")
	cf.register(c, false)
	return c
}

// saveSandboxOutputs writes the rich outputs of a code run into dir ("" = do not save).
func saveSandboxOutputs(app *App, dir string, outs []runr.SandboxOutput) error {
	if dir == "" || len(outs) == 0 {
		return nil
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	for _, o := range outs {
		data, err := base64.StdEncoding.DecodeString(o.DataBase64)
		if err != nil {
			return fmt.Errorf("output %s: %w", o.Path, err)
		}
		name := filepath.Join(dir, path.Base(o.Path))
		if err := os.WriteFile(name, data, 0o644); err != nil {
			return err
		}
		app.Progress("saved %s (%s, %d bytes)", name, o.Mime, len(data))
	}
	return nil
}

// sandboxPath parses ID:/path. A one-letter prefix is a Windows drive (C:\…), not a sandbox; a
// relative path is relative to /workspace, and ID: alone is the /workspace/ directory.
func sandboxPath(s string) (id, p string, ok bool) {
	i := strings.Index(s, ":")
	if i < 2 || strings.ContainsAny(s[:i], `/\.`) {
		return "", "", false
	}
	id, p = s[:i], s[i+1:]
	switch {
	case p == "":
		p = "/workspace/"
	case !strings.HasPrefix(p, "/"):
		p = "/workspace/" + p
	}
	return id, p, true
}

func newSandboxCpCmd(app *App) *cobra.Command {
	var timeout time.Duration
	c := &cobra.Command{
		Use:   "cp SRC DST",
		Short: "Copy files into or out of a sandbox",
		Long: "Copy a file or a directory between this machine and a sandbox; the sandbox side is ID:/path\n" +
			"(a relative path is relative to /workspace). A directory's contents are copied into the\n" +
			"destination directory, which is created when needed; a file is written to the destination,\n" +
			"or into it when the destination is a directory (or ends with /). - is stdin or stdout: a\n" +
			"file's bytes, or a tar of a directory. Writable places in a sandbox are /workspace,\n" +
			"/output, /tmp and /home/sandbox; each transfer is limited to 50 MiB.",
		Example: "  runr sandbox cp ./src $SBX:/workspace/src\n" +
			"  runr sandbox cp data.csv $SBX:\n" +
			"  runr sandbox cp $SBX:/output ./results\n" +
			"  runr sandbox cp $SBX:/workspace/report.pdf -  > report.pdf",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			srcID, srcPath, srcRemote := sandboxPath(args[0])
			dstID, dstPath, dstRemote := sandboxPath(args[1])
			if srcRemote == dstRemote {
				return usageErrorf("exactly one of SRC and DST must be a sandbox path (ID:/path)")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			if timeout > 0 {
				var cancel context.CancelFunc
				ctx, cancel = context.WithTimeout(ctx, timeout)
				defer cancel()
			}
			var res map[string]any
			if dstRemote {
				res, err = sandboxUpload(ctx, app, c, args[0], dstID, dstPath)
			} else {
				res, err = sandboxDownload(ctx, app, c, srcID, srcPath, args[1])
			}
			if err != nil {
				return err
			}
			if args[1] == "-" {
				return nil // stdout holds the content
			}
			return app.Printer().Print(res, func(*output.TableWriter) {})
		},
	}
	c.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "give up after this long")
	return c
}

func sandboxUpload(ctx context.Context, app *App, c *runr.Client, local, id, remote string) (map[string]any, error) {
	if local == "-" {
		if strings.HasSuffix(remote, "/") {
			return nil, usageErrorf("stdin needs a file path in the sandbox, not a directory")
		}
		data, err := io.ReadAll(app.In)
		if err != nil {
			return nil, err
		}
		r, err := c.WriteSandboxFile(ctx, id, remote, data, "")
		if err != nil {
			return nil, err
		}
		app.Progress("Copied %d bytes to %s:%s.", len(data), id, r.Path)
		return map[string]any{"sandbox_id": id, "path": r.Path, "bytes": len(data), "files": 1}, nil
	}
	info, err := os.Stat(local)
	if err != nil {
		return nil, err
	}
	if !info.IsDir() {
		if !strings.HasSuffix(remote, "/") {
			// Into an existing directory, like cp: anything else (not found) is the file's path.
			if l, err := c.SandboxFiles(ctx, id, remote); err == nil && l.IsDir {
				remote += "/"
			}
		}
		if strings.HasSuffix(remote, "/") {
			remote += filepath.Base(local)
		}
		data, err := os.ReadFile(local)
		if err != nil {
			return nil, err
		}
		mode := ""
		if info.Mode().Perm()&0o111 != 0 {
			mode = "0755"
		}
		r, err := c.WriteSandboxFile(ctx, id, remote, data, mode)
		if err != nil {
			return nil, err
		}
		app.Progress("Copied %s (%d bytes) to %s:%s.", local, len(data), id, r.Path)
		return map[string]any{"sandbox_id": id, "path": r.Path, "bytes": len(data), "files": 1}, nil
	}
	tarball, files, skipped, err := tarDirectory(local)
	if err != nil {
		return nil, err
	}
	for _, s := range skipped {
		app.Warn("skipped %s (not a regular file or directory)", s)
	}
	remote = strings.TrimSuffix(remote, "/")
	if remote == "" {
		remote = "/"
	}
	r, err := c.UploadSandboxArchive(ctx, id, remote, tarball)
	if err != nil {
		return nil, err
	}
	app.Progress("Copied %s (%s, %d bytes) to %s:%s.", local, countLabel(files, "file"), len(tarball), id, r.Path)
	return map[string]any{"sandbox_id": id, "path": r.Path, "bytes": len(tarball), "files": files}, nil
}

func sandboxDownload(ctx context.Context, app *App, c *runr.Client, id, remote, local string) (map[string]any, error) {
	listing, err := c.SandboxFiles(ctx, id, remote)
	if err != nil {
		return nil, err
	}
	if !listing.IsDir {
		if local == "-" {
			d, err := c.ReadSandboxFile(ctx, id, listing.Path, app.Out)
			if err != nil {
				return nil, err
			}
			return map[string]any{"sandbox_id": id, "path": listing.Path, "bytes": d.Bytes, "files": 1}, nil
		}
		target := local
		if st, err := os.Stat(local); (err == nil && st.IsDir()) || strings.HasSuffix(local, "/") || strings.HasSuffix(local, string(os.PathSeparator)) {
			target = filepath.Join(local, path.Base(listing.Path))
		}
		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return nil, err
		}
		f, err := os.Create(target)
		if err != nil {
			return nil, err
		}
		d, err := c.ReadSandboxFile(ctx, id, listing.Path, f)
		if cerr := f.Close(); err == nil {
			err = cerr
		}
		if err != nil {
			_ = os.Remove(target)
			return nil, err
		}
		app.Progress("Copied %s:%s (%d bytes) to %s.", id, listing.Path, d.Bytes, target)
		return map[string]any{"sandbox_id": id, "path": listing.Path, "local": target, "bytes": d.Bytes, "files": 1}, nil
	}
	if local == "-" {
		d, err := c.DownloadSandboxArchive(ctx, id, listing.Path, app.Out)
		if err != nil {
			return nil, err
		}
		return map[string]any{"sandbox_id": id, "path": listing.Path, "bytes": d.Bytes}, nil
	}
	var buf bytes.Buffer
	if _, err := c.DownloadSandboxArchive(ctx, id, listing.Path, &buf); err != nil {
		return nil, err
	}
	size := buf.Len()
	files, skipped, err := untarInto(&buf, local)
	if err != nil {
		return nil, err
	}
	for _, s := range skipped {
		app.Warn("skipped %s (links and special files are not copied)", s)
	}
	app.Progress("Copied %s:%s (%s, %d bytes) to %s.", id, listing.Path, countLabel(files, "file"), size, local)
	return map[string]any{"sandbox_id": id, "path": listing.Path, "local": local, "bytes": size, "files": files}, nil
}

// tarDirectory packs the contents of dir (names relative to it, forward slashes): regular files
// with their permission bits and directories. Other entries are returned as skipped.
func tarDirectory(dir string) ([]byte, int, []string, error) {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	files := 0
	var skipped []string
	err := filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(dir, p)
		if err != nil {
			return err
		}
		if rel == "." {
			return nil
		}
		name := filepath.ToSlash(rel)
		info, err := d.Info()
		if err != nil {
			return err
		}
		switch {
		case info.IsDir():
			return tw.WriteHeader(&tar.Header{Name: name + "/", Typeflag: tar.TypeDir, Mode: 0o755, ModTime: info.ModTime()})
		case info.Mode().IsRegular():
			data, err := os.ReadFile(p)
			if err != nil {
				return err
			}
			mode := int64(0o644)
			if info.Mode().Perm()&0o111 != 0 {
				mode = 0o755
			}
			if err := tw.WriteHeader(&tar.Header{Name: name, Typeflag: tar.TypeReg, Mode: mode, Size: int64(len(data)), ModTime: info.ModTime()}); err != nil {
				return err
			}
			if _, err := tw.Write(data); err != nil {
				return err
			}
			files++
		default:
			skipped = append(skipped, p)
		}
		return nil
	})
	if err != nil {
		return nil, 0, nil, err
	}
	if err := tw.Close(); err != nil {
		return nil, 0, nil, err
	}
	return buf.Bytes(), files, skipped, nil
}

// untarInto extracts a tar into dir (created when needed). Entries that would leave dir are
// refused; links and special files are returned as skipped.
func untarInto(r io.Reader, dir string) (int, []string, error) {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return 0, nil, err
	}
	root, err := filepath.Abs(dir)
	if err != nil {
		return 0, nil, err
	}
	tr := tar.NewReader(r)
	files := 0
	var skipped []string
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return files, skipped, nil
		}
		if err != nil {
			return files, skipped, fmt.Errorf("read the archive: %w", err)
		}
		name := path.Clean("/" + strings.TrimPrefix(h.Name, "./"))
		if name == "/" {
			continue
		}
		target := filepath.Join(root, filepath.FromSlash(strings.TrimPrefix(name, "/")))
		if rel, err := filepath.Rel(root, target); err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(os.PathSeparator)) {
			return files, skipped, fmt.Errorf("the archive entry %q leaves %s", h.Name, dir)
		}
		switch h.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return files, skipped, err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return files, skipped, err
			}
			mode := os.FileMode(0o644)
			if h.Mode&0o111 != 0 {
				mode = 0o755
			}
			f, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
			if err != nil {
				return files, skipped, err
			}
			_, err = io.Copy(f, tr)
			if cerr := f.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return files, skipped, err
			}
			files++
		default:
			skipped = append(skipped, strings.TrimPrefix(name, "/"))
		}
	}
}

func newSandboxFilesCmd(app *App) *cobra.Command {
	c := &cobra.Command{
		Use:     "files ID [PATH]",
		Aliases: []string{"ls-files"},
		Short:   "List a directory of a sandbox (default /workspace); mkdir and rm change files",
		Long: "List a directory of a sandbox (default /workspace; a relative PATH is relative to it).\n" +
			"`files mkdir` creates directories and `files rm` removes files and directories.",
		Example: "  runr sandbox files $SBX\n" +
			"  runr sandbox files $SBX /output\n" +
			"  runr sandbox files mkdir $SBX data/raw data/clean\n" +
			"  runr sandbox files rm -r $SBX data/raw",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			p := "/workspace"
			if len(args) == 2 {
				_, p, _ = sandboxPath(args[0] + ":" + args[1])
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			l, err := c.SandboxFiles(cmd.Context(), args[0], p)
			if err != nil {
				return err
			}
			return app.Printer().Print(l, func(t *output.TableWriter) {
				t.Header("NAME", "TYPE", "SIZE", "MODE", "MODIFIED")
				for _, e := range l.Entries {
					name := e.Name
					if e.Type == "dir" {
						name += "/"
					}
					t.Row(name, e.Type, fmt.Sprint(e.Size), output.Dash(e.Mode), output.Dash(e.ModifiedAt))
				}
				if l.Truncated {
					t.Note("The listing was cut at the API's entry limit.")
				}
			})
		},
	}
	c.AddCommand(newSandboxMkdirCmd(app), newSandboxRmPathCmd(app))
	return c
}

func newSandboxLogsCmd(app *App) *cobra.Command {
	var follow, pod bool
	var tail int
	c := &cobra.Command{
		Use:   "logs ID [PROCESS]",
		Short: "Show the activity log of a sandbox, or the output of a background process",
		Long: "With a sandbox id: the sandbox's activity log (created, commands, files, terminal, …);\n" +
			"--pod adds the Kubernetes pod of a running sandbox: its node, phase and conditions, the\n" +
			"cluster's events about it and the last lines of its log (why a sandbox does not start).\n" +
			"With a process id as well: that background process's stdout and stderr (the last --tail\n" +
			"bytes); -f streams the output until the process ends and exits with its exit code (137 when\n" +
			"it was killed).",
		Example: "  runr sandbox logs $SBX\n" +
			"  runr sandbox logs $SBX --pod\n" +
			"  runr sandbox logs $SBX $PROCESS -f",
		Args: cobra.RangeArgs(1, 2),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 2 {
				if pod {
					return usageErrorf("--pod shows the sandbox's pod; leave out the process id")
				}
				return processLogs(cmd.Context(), app, args[0], args[1], follow, tail)
			}
			if follow || cmd.Flags().Changed("tail") {
				return usageErrorf("-f and --tail need a process id (runr sandbox logs ID PROCESS -f)")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if pod {
				return sandboxPodLogs(cmd.Context(), app, c, args[0])
			}
			events, err := c.SandboxLogs(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(events, func(t *output.TableWriter) {
				t.Header("TIME", "TYPE", "MESSAGE")
				for i := len(events) - 1; i >= 0; i-- {
					e := events[i]
					t.Row(output.Dash(e.CreatedAt), e.Type, output.Truncate(output.Dash(e.Message), 100))
				}
			})
		},
	}
	c.Flags().BoolVarP(&follow, "follow", "f", false, "stream a process's output until it ends")
	c.Flags().IntVar(&tail, "tail", 0, "bytes of a process's output to show (default 65536, up to 4 MiB)")
	c.Flags().BoolVar(&pod, "pod", false, "also show the sandbox's Kubernetes pod: node, phase, conditions, events, log")
	return c
}

// sbxSeconds converts a duration flag to whole seconds (rounded up; 0 stays 0).
func sbxSeconds(d time.Duration) int {
	if d <= 0 {
		return 0
	}
	return int((d + time.Second - 1) / time.Second)
}

func sbxAge(ts string) string {
	t, err := time.Parse(time.RFC3339, ts)
	if err != nil {
		return "-"
	}
	return output.Age(&t)
}

func sbxUntil(ts string, ended bool) string {
	if ended {
		return "-"
	}
	t, err := time.Parse(time.RFC3339, ts)
	if err != nil {
		return "-"
	}
	return "in " + output.Duration(time.Until(t))
}
