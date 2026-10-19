package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"golang.org/x/term"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Background processes, pause / resume, lifetime, the terminal, warm pools, quotas, usage and
// metrics of `runr sandbox`.

// sandboxExtraCmds are added to `runr sandbox` by newSandboxCmd.
func sandboxExtraCmds(app *App) []*cobra.Command {
	return []*cobra.Command{
		newSandboxPsCmd(app), newSandboxStartCmd(app), newSandboxKillCmd(app),
		newSandboxPauseCmd(app), newSandboxResumeCmd(app), newSandboxExtendCmd(app), newSandboxKeepaliveCmd(app),
		newSandboxTerminalCmd(app), newSandboxNetworkCmd(app), newSandboxRegionsCmd(app),
		newSandboxPoolsCmd(app), newSandboxQuotasCmd(app), newSandboxUsageCmd(app), newSandboxMetricsCmd(app),
	}
}

// ---------------------------------------------------------------------------------------
// Background processes

func processRows(t *output.TableWriter, list []runr.SandboxProcess) {
	t.Header("ID", "PID", "STATUS", "EXIT", "STARTED", "COMMAND")
	for _, p := range list {
		pid, exit := "-", "-"
		if p.PID != nil {
			pid = strconv.Itoa(*p.PID)
		}
		if p.ExitCode != nil {
			exit = strconv.Itoa(*p.ExitCode)
		}
		t.Row(p.ID, pid, p.Status, exit, sbxAge(p.StartedAt), output.Truncate(p.Command, 60))
	}
}

func newSandboxPsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ps ID",
		Aliases: []string{"processes"},
		Short:   "List the background processes of a sandbox",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListSandboxProcesses(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) { processRows(t, list) })
		},
	}
}

// processEnd turns how a followed process ended into runr's exit status: its exit code, 137
// when it was killed.
func processEnd(app *App, p string, end *runr.SandboxProcessEnd) error {
	if end.ExitCode != nil {
		return exitOf(*end.ExitCode)
	}
	if end.Status == runr.ProcessKilled {
		app.Warn("process %s was killed", p)
		return &remoteExitError{code: 137}
	}
	return nil
}

func newSandboxStartCmd(app *App) *cobra.Command {
	var cwd string
	var env []string
	var follow bool
	c := &cobra.Command{
		Use:   "start [flags] ID [--] CMD [ARG...]",
		Short: "Start a background process in a sandbox and print its id",
		Long: "Start a command in the background of a running sandbox; it keeps running after runr exits.\n" +
			"stdout is the process id (-o json: the process). One argument is run by /bin/sh -c, several\n" +
			"are the argv; flags go before the sandbox id. --follow streams its output until it ends and\n" +
			"exits with its exit code. Read the output later with `runr sandbox logs ID PROCESS [-f]`,\n" +
			"stop it with `runr sandbox kill ID PROCESS`.",
		Example: "  runr sandbox start $SBX -- python3 -m http.server 8000\n" +
			"  P=$(runr sandbox start --cwd /workspace/app $SBX 'npm run build')\n" +
			"  runr sandbox logs $SBX $P -f",
		Args: func(cmd *cobra.Command, args []string) error {
			n := len(args)
			if n > 1 && args[1] == "--" {
				n--
			}
			if n < 2 {
				return fmt.Errorf("give the sandbox id and the command (runr sandbox start ID -- CMD)")
			}
			return nil
		},
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 1 && args[1] == "--" {
				args = append([]string{args[0]}, args[2:]...)
			}
			in := runr.StartProcessInput{Cwd: cwd}
			if len(args) == 2 {
				in.Cmd = args[1]
			} else {
				in.Cmd = args[1:]
			}
			vars, err := parseKeyValues("--env", env)
			if err != nil {
				return err
			}
			if len(vars) > 0 {
				in.Env = vars
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			p, err := c.StartSandboxProcess(ctx, args[0], in)
			if err != nil {
				return err
			}
			pid := ""
			if p.PID != nil {
				pid = fmt.Sprintf(" (pid %d)", *p.PID)
			}
			app.Progress("Started process %s%s in %s.", p.ID, pid, args[0])
			if !follow {
				return app.Printer().Print(p, func(t *output.TableWriter) { t.Note("%s", p.ID) })
			}
			return followProcess(ctx, app, c, args[0], p.ID)
		},
	}
	c.Flags().SetInterspersed(false)
	c.Flags().StringVar(&cwd, "cwd", "", "working directory (default /workspace)")
	c.Flags().StringArrayVarP(&env, "env", "e", nil, "extra environment variable KEY=VALUE (repeatable)")
	c.Flags().BoolVarP(&follow, "follow", "f", false, "stream the output until the process ends")
	return c
}

// followProcess streams a process's output and returns its exit status (-o json: collected).
func followProcess(ctx context.Context, app *App, c *runr.Client, id, process string) error {
	if app.Printer().Machine() {
		var stdout, stderr bytes.Buffer
		end, err := c.FollowSandboxProcess(ctx, id, process, &stdout, &stderr)
		if err != nil {
			return err
		}
		out := map[string]any{"process_id": process, "status": end.Status, "exit_code": end.ExitCode,
			"stdout": stdout.String(), "stderr": stderr.String()}
		if err := app.Printer().Print(out, nil); err != nil {
			return err
		}
		return processEnd(app, process, end)
	}
	end, err := c.FollowSandboxProcess(ctx, id, process, app.Out, app.Err)
	if err != nil {
		if ctx.Err() != nil {
			// Ctrl+C stops following; the process keeps running.
			fmt.Fprintln(app.Err, "Stopped following; the process keeps running.")
			return &remoteExitError{code: 130}
		}
		return err
	}
	return processEnd(app, process, end)
}

// processLogs prints the output of a background process (runr sandbox logs ID PROCESS).
func processLogs(ctx context.Context, app *App, id, process string, follow bool, tail int) error {
	c, err := app.Client()
	if err != nil {
		return err
	}
	if follow {
		return followProcess(ctx, app, c, id, process)
	}
	l, err := c.SandboxProcessOutput(ctx, id, process, tail)
	if err != nil {
		return err
	}
	if app.Printer().Machine() {
		return app.Printer().Print(l, nil)
	}
	if _, err := app.Out.Write(l.StdoutBytes()); err != nil {
		return err
	}
	if _, err := app.Err.Write(l.StderrBytes()); err != nil {
		return err
	}
	if l.Truncated {
		app.Warn("only the last %d bytes are shown; --tail shows more", sbxTail(tail))
	}
	return nil
}

func sbxTail(n int) int {
	if n <= 0 {
		return 65536
	}
	return n
}

func newSandboxKillCmd(app *App) *cobra.Command {
	var signal string
	c := &cobra.Command{
		Use:   "kill ID PROCESS",
		Short: "Send a signal to a background process (default TERM)",
		Args:  cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			sig := strings.ToUpper(strings.TrimPrefix(strings.ToUpper(signal), "SIG"))
			valid := false
			for _, s := range runr.SandboxSignals {
				valid = valid || s == sig
			}
			if !valid {
				return usageErrorf("--signal must be one of %s", strings.Join(runr.SandboxSignals, ", "))
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			p, err := c.KillSandboxProcess(cmd.Context(), args[0], args[1], sig)
			if err != nil {
				return err
			}
			app.Progress("Sent %s to %s; it is %s.", sig, p.ID, p.Status)
			return app.Printer().Print(p, func(*output.TableWriter) {})
		},
	}
	c.Flags().StringVarP(&signal, "signal", "s", "TERM", "signal: "+strings.Join(runr.SandboxSignals, ", "))
	return c
}

// ---------------------------------------------------------------------------------------
// Lifetime

func sandboxActionCmd(app *App, use, short string, act func(ctx context.Context, c *runr.Client, id string) (*runr.Sandbox, error), done string) *cobra.Command {
	return &cobra.Command{
		Use:   use + " ID",
		Short: short,
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := act(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			app.Progress("Sandbox %s %s (status %s).", s.ID, done, s.Status)
			return app.Printer().Print(s, func(*output.TableWriter) {})
		},
	}
}

func newSandboxPauseCmd(app *App) *cobra.Command {
	c := sandboxActionCmd(app, "pause", "Pause a running sandbox (its processes are frozen)",
		func(ctx context.Context, c *runr.Client, id string) (*runr.Sandbox, error) {
			return c.PauseSandbox(ctx, id)
		}, "paused")
	c.Long = "Freeze every process of a running sandbox. A paused sandbox runs no commands and keeps its\n" +
		"files and memory; its TTL keeps counting. `runr sandbox resume` lets it run again."
	return c
}

func newSandboxResumeCmd(app *App) *cobra.Command {
	return sandboxActionCmd(app, "resume", "Resume a paused sandbox",
		func(ctx context.Context, c *runr.Client, id string) (*runr.Sandbox, error) {
			return c.ResumeSandbox(ctx, id)
		}, "resumed")
}

func newSandboxKeepaliveCmd(app *App) *cobra.Command {
	return sandboxActionCmd(app, "keepalive", "Reset the idle timer of a sandbox",
		func(ctx context.Context, c *runr.Client, id string) (*runr.Sandbox, error) {
			return c.KeepaliveSandbox(ctx, id)
		}, "kept alive")
}

func newSandboxExtendCmd(app *App) *cobra.Command {
	var ttl time.Duration
	c := &cobra.Command{
		Use:     "extend ID",
		Aliases: []string{"timeout"},
		Short:   "Set a sandbox to end a given time from now (--ttl)",
		Long: "Set the remaining lifetime of a sandbox: it ends --ttl from now. The region's maximum TTL,\n" +
			"counted from the sandbox's start, still applies.",
		Example: "  runr sandbox extend $SBX --ttl 30m",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			secs := sbxSeconds(ttl)
			if secs < 10 {
				return usageErrorf("--ttl must be at least 10s")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := c.ExtendSandbox(cmd.Context(), args[0], secs)
			if err != nil {
				return err
			}
			app.Progress("Sandbox %s ends at %s.", s.ID, output.Dash(s.ExpiresAt))
			return app.Printer().Print(s, func(*output.TableWriter) {})
		},
	}
	c.Flags().DurationVar(&ttl, "ttl", 0, "how long the sandbox may live from now (at least 10s)")
	_ = c.MarkFlagRequired("ttl")
	return c
}

// ---------------------------------------------------------------------------------------
// Terminal

func newSandboxTerminalCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "terminal ID",
		Aliases: []string{"shell", "attach"},
		Short:   "Open an interactive shell in a sandbox",
		Long: "Open an interactive shell (/bin/sh) in a running sandbox through RunR's web shell, the\n" +
			"terminal of the console. On a terminal, keys go to the shell as typed (Ctrl+C too) and the\n" +
			"size follows the window; leave with exit or Ctrl+D. Piped stdin is sent as typed input and\n" +
			"ends with Ctrl+D. With -o json or yaml the session (a one-time WebSocket URL, valid for 120\n" +
			"seconds) is printed instead of attaching. Any other command runs on a terminal with\n" +
			"`runr sandbox exec -it ID -- CMD`, which also passes on its exit code.",
		Example: "  runr sandbox terminal $SBX\n" +
			"  printf 'ls -la\\nexit\\n' | runr sandbox terminal $SBX",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			return attachShell(cmd.Context(), app, c, func(cols, rows int) (*runr.SandboxTerminalSession, error) {
				return c.OpenSandboxTerminal(cmd.Context(), args[0], cols, rows)
			})
		},
	}
}

// attachShell opens a web shell session with the terminal's size (open) and attaches to it;
// with -o json|yaml it prints the one-time session instead.
func attachShell(ctx context.Context, app *App, c *runr.Client, open func(cols, rows int) (*runr.SandboxTerminalSession, error)) error {
	inFile, _ := app.In.(*os.File)
	outFile, _ := app.Out.(*os.File)
	interactive := inFile != nil && app.interactive() && term.IsTerminal(int(inFile.Fd()))
	cols, rows := 80, 24
	if outFile != nil && term.IsTerminal(int(outFile.Fd())) {
		if w, h, err := term.GetSize(int(outFile.Fd())); err == nil && w > 0 && h > 0 {
			cols, rows = w, h
		}
	}
	sess, err := open(cols, rows)
	if err != nil {
		return err
	}
	if app.Printer().Machine() {
		return app.Printer().Print(sess, nil)
	}
	return runTerminal(ctx, app, c, sess, interactive, inFile, outFile, cols, rows)
}

func runTerminal(ctx context.Context, app *App, c *runr.Client, sess *runr.SandboxTerminalSession, interactive bool,
	inFile, outFile *os.File, cols, rows int) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	t, err := c.DialSandboxTerminal(ctx, sess)
	if err != nil {
		return err
	}
	defer t.Close()

	var restore func()
	defer func() {
		if restore != nil {
			restore()
		}
	}()
	started := false
	start := func() {
		if started {
			return
		}
		started = true
		if interactive {
			if old, err := term.MakeRaw(int(inFile.Fd())); err == nil {
				restore = func() { _ = term.Restore(int(inFile.Fd()), old) }
			}
			go func() {
				// The size follows the window.
				tick := time.NewTicker(500 * time.Millisecond)
				defer tick.Stop()
				w0, h0 := cols, rows
				for {
					select {
					case <-ctx.Done():
						return
					case <-tick.C:
						if outFile == nil {
							continue
						}
						if w, h, err := term.GetSize(int(outFile.Fd())); err == nil && (w != w0 || h != h0) {
							w0, h0 = w, h
							_ = t.Resize(w, h)
						}
					}
				}
			}()
		}
		go func() {
			buf := make([]byte, 4096)
			for {
				n, err := app.In.Read(buf)
				if n > 0 {
					if serr := t.SendInput(buf[:n]); serr != nil {
						return
					}
				}
				if err != nil {
					if !interactive {
						// End of piped input: Ctrl+D ends the shell.
						_ = t.SendInput([]byte{0x04})
					}
					return
				}
			}
		}()
	}

	for {
		f, err := t.Next()
		if errors.Is(err, io.EOF) {
			if restore != nil {
				restore()
				restore = nil
			}
			if interactive {
				fmt.Fprintln(app.Err)
			}
			return nil
		}
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		switch {
		case f.Error != "":
			if restore != nil {
				restore()
				restore = nil
			}
			return fmt.Errorf("terminal: %s", f.Error)
		case f.Status != "":
			app.Progress("Terminal: %s…", strings.ReplaceAll(f.Status, "_", " "))
		case f.Connected:
			app.Progress("Connected to %s. Leave with exit or Ctrl+D.", output.Dash(f.Pod))
			start()
		case f.Output != nil:
			start()
			if _, err := app.Out.Write(f.Output); err != nil {
				return err
			}
		}
	}
}

// ---------------------------------------------------------------------------------------
// Warm pools

func poolRows(t *output.TableWriter, list []runr.SandboxPool) {
	t.Header("ID", "REGION", "RUNTIME", "CLASS", "SIZE", "WARM", "STARTING", "HITS", "MISSES", "ENABLED", "ERROR")
	for _, p := range list {
		rt := p.Runtime
		if p.RuntimeVersion != "" {
			rt += " " + p.RuntimeVersion
		}
		t.Row(p.ID, output.Dash(p.RegionName()), rt, output.Dash(p.SandboxClass), strconv.Itoa(p.Size), strconv.Itoa(p.Warm),
			strconv.Itoa(p.Starting), strconv.Itoa(p.Hits), strconv.Itoa(p.Misses), yesNo(p.IsEnabled),
			output.Truncate(output.Dash(p.LastError), 40))
	}
}

func newSandboxPoolsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "pools",
		Aliases: []string{"pool"},
		Short:   "Warm pools: pre-started sandboxes that make creates fast",
		Long: "A warm pool keeps sandboxes of one runtime, version, size and class running in a region; a\n" +
			"create that matches gets one at once (from_pool). The pool refills in the background.\n" +
			"Changing pools needs sandboxes.manage; the pools of an organization count against its\n" +
			"pool size quota.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the warm pools",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListSandboxPools(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) { poolRows(t, list) })
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	var in runr.CreateSandboxPoolInput
	create := &cobra.Command{
		Use:     "create",
		Short:   "Create a warm pool",
		Example: "  runr sandbox pools create --region eu-1 --runtime python --size 3 --memory 1Gi",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if in.Region == "" || in.Runtime == "" {
				return usageErrorf("--region and --runtime are required")
			}
			if in.Size < 0 {
				return usageErrorf("--size must not be negative")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			p, err := c.CreateSandboxPool(cmd.Context(), in)
			if err != nil {
				return err
			}
			app.Progress("Created pool %s (%d sandboxes of %s in %s).", p.ID, p.Size, p.Runtime, output.Dash(p.RegionName()))
			return app.Printer().Print(p, func(t *output.TableWriter) { poolRows(t, []runr.SandboxPool{*p}) })
		},
	}
	f := create.Flags()
	f.StringVar(&in.Region, "region", "", "region name or id")
	f.StringVar(&in.Runtime, "runtime", "", "runtime of the catalog")
	f.StringVar(&in.Version, "version", "", "runtime version (default: the runtime's default)")
	f.IntVar(&in.Size, "size", 1, "how many sandboxes to keep warm")
	f.StringVar(&in.CPU, "cpu", "", "CPU of each sandbox: 500m, 0.5 or 1 (default 500m)")
	f.StringVar(&in.Memory, "memory", "", "memory of each sandbox in MiB or a quantity such as 1Gi (default 512)")
	f.StringVar(&in.Disk, "disk", "", "disk of each sandbox in MiB or a quantity (default 1024)")
	f.StringVar(&in.SandboxClass, "class", "", "sandbox class (default: the region's default)")
	cmd.AddCommand(create)

	var size int
	var enable, disable bool
	update := &cobra.Command{
		Use:   "update POOL",
		Short: "Change the size of a pool or switch it on or off",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if enable && disable {
				return usageErrorf("give --enable or --disable, not both")
			}
			in := runr.UpdateSandboxPoolInput{}
			if cmd.Flags().Changed("size") {
				if size < 0 {
					return usageErrorf("--size must not be negative")
				}
				in.Size = &size
			}
			if enable || disable {
				on := enable
				in.IsEnabled = &on
			}
			if in.Size == nil && in.IsEnabled == nil {
				return usageErrorf("give --size, --enable or --disable")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			p, err := c.UpdateSandboxPool(cmd.Context(), args[0], in)
			if err != nil {
				return err
			}
			return app.Printer().Print(p, func(t *output.TableWriter) { poolRows(t, []runr.SandboxPool{*p}) })
		},
	}
	update.Flags().IntVar(&size, "size", 0, "how many sandboxes to keep warm")
	update.Flags().BoolVar(&enable, "enable", false, "switch the pool on")
	update.Flags().BoolVar(&disable, "disable", false, "switch the pool off (its warm sandboxes are removed)")
	cmd.AddCommand(update)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm POOL",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a pool and its warm sandboxes",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm("Remove pool "+args[0]+" and its warm sandboxes", yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.DeleteSandboxPool(cmd.Context(), args[0]); err != nil {
				return err
			}
			app.Progress("Removed pool %s.", args[0])
			return app.Printer().Print(map[string]any{"id": args[0], "deleted": true}, func(*output.TableWriter) {})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)
	return cmd
}

// ---------------------------------------------------------------------------------------
// Quotas

func limitLabel(n int, unit string) string {
	if n <= 0 {
		return "unlimited"
	}
	if unit == "" {
		return strconv.Itoa(n)
	}
	return strconv.Itoa(n) + " " + unit
}

func quotaRows(t *output.TableWriter, l runr.SandboxQuotaLimits, u *runr.SandboxQuotaUsage) {
	t.Header("LIMIT", "USED", "MAXIMUM")
	used := func(n int, unit string) string {
		if u == nil {
			return "-"
		}
		if unit == "" {
			return strconv.Itoa(n)
		}
		return strconv.Itoa(n) + " " + unit
	}
	var uu runr.SandboxQuotaUsage
	if u != nil {
		uu = *u
	}
	t.Row("concurrent sandboxes", used(uu.Concurrent, ""), limitLabel(l.MaxConcurrent, ""))
	t.Row("CPU", used(uu.CPUm, "m"), limitLabel(l.MaxCPUm, "m"))
	t.Row("memory", used(uu.MemoryMB, "MiB"), limitLabel(l.MaxMemoryMB, "MiB"))
	t.Row("sandbox time this month", used(uu.SandboxSecondsMonth, "s"), limitLabel(l.MaxSandboxSecondsMonth, "s"))
	t.Row("warm pool sandboxes", used(uu.PoolSize, ""), limitLabel(l.MaxPoolSize, ""))
	classes := "every class of the region"
	if len(l.AllowedClasses) > 0 {
		classes = strings.Join(l.AllowedClasses, ", ")
	}
	t.Row("sandbox classes", "-", classes)
}

func newSandboxQuotasCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "quotas",
		Aliases: []string{"quota"},
		Short:   "Sandbox quotas: limits and usage",
		Long: "Every organization has limits for concurrent sandboxes, their CPU and memory, sandbox time\n" +
			"per month, the size of its warm pools and the sandbox classes it may use (0 = unlimited).\n" +
			"`get` shows your organization's; `ls`, `set` and `reset` change them: platform\n" +
			"administrators (every user with sandboxes.manage on a single-tenant installation), where\n" +
			"\"default\" is the defaults row that applies to organizations without limits of their own.",
	}
	get := &cobra.Command{
		Use:   "get",
		Short: "Show the limits and usage of your organization",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			q, err := c.GetSandboxQuota(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(q, func(t *output.TableWriter) { quotaRows(t, q.Limits, q.Usage) })
		},
	}
	cmd.AddCommand(get)
	cmd.RunE = get.RunE

	cmd.AddCommand(&cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the defaults row and every organization's limits and usage",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListSandboxQuotas(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ORGANIZATION", "ID", "OWN", "CONCURRENT", "CPU", "MEMORY", "TIME/MONTH", "POOLS", "CLASSES")
				for _, r := range list {
					name, id := "(defaults)", "default"
					if r.Tenant != nil {
						name, id = r.Tenant.Name, r.Tenant.ID.String()
					}
					var u runr.SandboxQuotaUsage
					if r.Usage != nil {
						u = *r.Usage
					}
					l := r.Limits
					pair := func(used, max int) string { return strconv.Itoa(used) + "/" + limitLabel(max, "") }
					classes := "all"
					if len(l.AllowedClasses) > 0 {
						classes = strings.Join(l.AllowedClasses, ",")
					}
					t.Row(name, id, yesNo(r.Custom), pair(u.Concurrent, l.MaxConcurrent), pair(u.CPUm, l.MaxCPUm),
						pair(u.MemoryMB, l.MaxMemoryMB), pair(u.SandboxSecondsMonth, l.MaxSandboxSecondsMonth),
						pair(u.PoolSize, l.MaxPoolSize), classes)
				}
			})
		},
	})

	var (
		maxConcurrent, maxPool int
		maxCPU, maxMemory      string
		maxTime                time.Duration
		classes, unset         []string
		allClasses             bool
	)
	set := &cobra.Command{
		Use:   "set [ORGANIZATION|default]",
		Short: "Change the limits of an organization (default: the defaults row)",
		Long: "Change limits; flags left out are kept, 0 means unlimited. --unset NAME puts a limit back\n" +
			"to the default (names: concurrent, cpu, memory, time, pool-size, classes).",
		Example: "  runr sandbox quotas set --max-concurrent 20 --max-cpu 16 --max-memory 32Gi\n" +
			"  runr sandbox quotas set 01J… --max-time 200h --allowed-class kata --allowed-class gvisor\n" +
			"  runr sandbox quotas set 01J… --unset concurrent",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			tenant := "default"
			if len(args) == 1 {
				tenant = args[0]
			}
			fl := cmd.Flags()
			change := runr.SandboxQuotaChange{}
			if fl.Changed("max-concurrent") {
				if maxConcurrent < 0 {
					return usageErrorf("--max-concurrent must not be negative")
				}
				change["max_concurrent"] = maxConcurrent
			}
			if fl.Changed("max-cpu") {
				m, err := sbxParseMillicores(maxCPU)
				if err != nil {
					return usageErrorf("--max-cpu: %v", err)
				}
				change["max_cpu_m"] = m
			}
			if fl.Changed("max-memory") {
				m, err := sbxParseMiB(maxMemory)
				if err != nil {
					return usageErrorf("--max-memory: %v", err)
				}
				change["max_memory_mb"] = m
			}
			if fl.Changed("max-time") {
				if maxTime < 0 {
					return usageErrorf("--max-time must not be negative")
				}
				change["max_sandbox_seconds_month"] = sbxSeconds(maxTime)
			}
			if fl.Changed("max-pool-size") {
				if maxPool < 0 {
					return usageErrorf("--max-pool-size must not be negative")
				}
				change["max_pool_size"] = maxPool
			}
			if len(classes) > 0 && allClasses {
				return usageErrorf("give --allowed-class or --all-classes, not both")
			}
			if len(classes) > 0 {
				change["allowed_classes"] = classes
			}
			if allClasses {
				change["allowed_classes"] = nil
			}
			fields := map[string]string{"concurrent": "max_concurrent", "cpu": "max_cpu_m", "memory": "max_memory_mb",
				"time": "max_sandbox_seconds_month", "pool-size": "max_pool_size", "classes": "allowed_classes"}
			for _, u := range unset {
				f, ok := fields[strings.ToLower(u)]
				if !ok {
					return usageErrorf("--unset takes concurrent, cpu, memory, time, pool-size or classes, not %q", u)
				}
				change[f] = nil
			}
			if len(change) == 0 {
				return usageErrorf("give at least one limit to change")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			q, err := c.SetSandboxQuota(cmd.Context(), tenant, change)
			if err != nil {
				return err
			}
			return app.Printer().Print(q, func(t *output.TableWriter) { quotaRows(t, q.Limits, q.Usage) })
		},
	}
	sf := set.Flags()
	sf.IntVar(&maxConcurrent, "max-concurrent", 0, "concurrent sandboxes (0 = unlimited)")
	sf.StringVar(&maxCPU, "max-cpu", "", "CPU of all sandboxes together: cores (16) or millicores (16000m)")
	sf.StringVar(&maxMemory, "max-memory", "", "memory of all sandboxes together: MiB or a quantity such as 32Gi")
	sf.DurationVar(&maxTime, "max-time", 0, "sandbox time per month, e.g. 200h (0 = unlimited)")
	sf.IntVar(&maxPool, "max-pool-size", 0, "sandboxes in warm pools (0 = unlimited)")
	sf.StringArrayVar(&classes, "allowed-class", nil, "a sandbox class the organization may use (repeatable): kata, gvisor, kata-fc, …")
	sf.BoolVar(&allClasses, "all-classes", false, "allow every class of the region")
	sf.StringArrayVar(&unset, "unset", nil, "put a limit back to the default (repeatable)")
	cmd.AddCommand(set)

	var yes bool
	reset := &cobra.Command{
		Use:   "reset ORGANIZATION|default",
		Short: "Remove an organization's own limits (the defaults apply again)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm("Reset the sandbox limits of "+args[0], yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.ResetSandboxQuota(cmd.Context(), args[0]); err != nil {
				return err
			}
			app.Progress("The limits of %s are the defaults again.", args[0])
			return app.Printer().Print(map[string]any{"tenant": args[0], "reset": true}, func(*output.TableWriter) {})
		},
	}
	reset.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(reset)
	return cmd
}

// sbxParseMillicores reads "4", "0.5" (cores) or "4000m".
func sbxParseMillicores(s string) (int, error) {
	s = strings.TrimSpace(s)
	if strings.HasSuffix(s, "m") {
		n, err := strconv.Atoi(strings.TrimSuffix(s, "m"))
		if err != nil || n < 0 {
			return 0, fmt.Errorf("%q is not a CPU quantity (cores such as 4, or millicores such as 4000m)", s)
		}
		return n, nil
	}
	f, err := strconv.ParseFloat(s, 64)
	if err != nil || f < 0 {
		return 0, fmt.Errorf("%q is not a CPU quantity (cores such as 4, or millicores such as 4000m)", s)
	}
	return int(f*1000 + 0.5), nil
}

// sbxParseMiB reads MiB ("512") or a quantity ("512Mi", "16Gi", "1Ti").
func sbxParseMiB(s string) (int, error) {
	s = strings.TrimSpace(s)
	mul := 1.0
	for suffix, m := range map[string]float64{"Mi": 1, "Gi": 1024, "Ti": 1024 * 1024} {
		if strings.HasSuffix(s, suffix) {
			s, mul = strings.TrimSuffix(s, suffix), m
			break
		}
	}
	f, err := strconv.ParseFloat(s, 64)
	if err != nil || f < 0 {
		return 0, fmt.Errorf("%q is not a memory quantity (MiB, or 512Mi, 16Gi)", s)
	}
	return int(f*mul + 0.5), nil
}

// ---------------------------------------------------------------------------------------
// Usage and metrics

func newSandboxUsageCmd(app *App) *cobra.Command {
	var from, to string
	c := &cobra.Command{
		Use:   "usage",
		Short: "Daily sandbox usage of your organization (default: this month)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			for _, d := range []string{from, to} {
				if d == "" {
					continue
				}
				if _, err := time.Parse("2006-01-02", d); err != nil {
					return usageErrorf("--from and --to are dates such as 2026-10-01")
				}
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			rows, err := c.SandboxUsage(cmd.Context(), from, to)
			if err != nil {
				return err
			}
			return app.Printer().Print(rows, func(t *output.TableWriter) {
				t.Header("DATE", "REGION", "SANDBOXES", "TIME", "CPU CORE-HOURS", "MEMORY GIB-HOURS")
				var seconds, cpu, memory int64
				for _, r := range rows {
					t.Row(r.RecordDate, output.Dash(r.RegionID), strconv.Itoa(r.SandboxCount),
						output.Duration(time.Duration(r.SandboxSeconds)*time.Second),
						strconv.FormatFloat(float64(r.CPUmcMS)/1000/3600/1000, 'f', 2, 64),
						strconv.FormatFloat(float64(r.MemoryMiBMS)/1024/3600/1000, 'f', 2, 64))
					seconds += r.SandboxSeconds
					cpu += r.CPUmcMS
					memory += r.MemoryMiBMS
				}
				if len(rows) > 0 {
					t.Note("Total: %s, %s CPU core-hours, %s memory GiB-hours",
						output.Duration(time.Duration(seconds)*time.Second),
						strconv.FormatFloat(float64(cpu)/1000/3600/1000, 'f', 2, 64),
						strconv.FormatFloat(float64(memory)/1024/3600/1000, 'f', 2, 64))
				}
			})
		},
	}
	c.Flags().StringVar(&from, "from", "", "first day (YYYY-MM-DD; default: the first of this month)")
	c.Flags().StringVar(&to, "to", "", "last day (YYYY-MM-DD; default: today)")
	return c
}

func newSandboxMetricsCmd(app *App) *cobra.Command {
	var window, region string
	var all, prometheus bool
	c := &cobra.Command{
		Use:   "metrics",
		Short: "Active and warm sandboxes, warm pool hit rate and start times",
		Long: "Active and warm sandboxes, the warm pool hit rate and the average start time from a pool\n" +
			"and cold, for your organization (--all-organizations: every organization, for platform\n" +
			"administrators). --prometheus prints the installation's sandbox metrics in the Prometheus\n" +
			"text format instead, as the scrape endpoint serves them (platform administrators; on a\n" +
			"single-tenant installation every user with sandboxes.manage).",
		Example: "  runr sandbox metrics --window 7d --region eu-1\n" +
			"  runr sandbox metrics --prometheus | grep runr_sandbox",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if prometheus {
				if app.Printer().Machine() {
					return usageErrorf("--prometheus prints the Prometheus text format; leave out -o")
				}
				if cmd.Flags().Changed("window") || region != "" || all {
					return usageErrorf("--prometheus takes no --window, --region or --all-organizations")
				}
				c, err := app.Client()
				if err != nil {
					return err
				}
				return c.WriteSandboxPrometheus(cmd.Context(), app.Out)
			}
			switch window {
			case "", "1h", "24h", "7d", "30d":
			default:
				return usageErrorf("--window must be 1h, 24h, 7d or 30d")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			ctx := cmd.Context()
			o := runr.SandboxMetricsOptions{Window: window, AllTenants: all}
			if region != "" {
				r, err := c.FindRegion(ctx, region)
				if err != nil {
					return err
				}
				o.Region = r.ID
			}
			m, err := c.GetSandboxMetrics(ctx, o)
			if err != nil {
				return err
			}
			return app.Printer().Print(m, func(t *output.TableWriter) {
				t.KV("Window", m.Window)
				t.KV("Active sandboxes", strconv.Itoa(m.Active))
				t.KV("Warm sandboxes", strconv.Itoa(m.Warm))
				rate := "-"
				if m.Pool.HitRate != nil {
					rate = strconv.FormatFloat(*m.Pool.HitRate*100, 'f', 1, 64) + " %"
				}
				t.KV("Warm pool", fmt.Sprintf("%d hits, %d misses, hit rate %s", m.Pool.Hits, m.Pool.Misses, rate))
				t.KV("Starts from a pool", fmt.Sprintf("%d, %d ms on average", m.Starts.PoolHit, m.StartTime.PoolHit.AverageMS()))
				t.KV("Cold starts", fmt.Sprintf("%d, %d ms on average", m.Starts.Cold, m.StartTime.Cold.AverageMS()))
			})
		},
	}
	c.Flags().StringVar(&window, "window", "24h", "1h, 24h, 7d or 30d")
	c.Flags().StringVar(&region, "region", "", "only this region (name or id)")
	c.Flags().BoolVar(&all, "all-organizations", false, "every organization together (platform administrators)")
	c.Flags().BoolVar(&prometheus, "prometheus", false, "print the installation's sandbox metrics in the Prometheus text format")
	return c
}
