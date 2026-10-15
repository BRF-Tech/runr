// Package cmd implements the runr commands.
package cmd

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"golang.org/x/term"

	"github.com/brf-tech/runr/cli/internal/dev"
	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/internal/runtimes"
	"github.com/brf-tech/runr/cli/internal/version"
	"github.com/brf-tech/runr/cli/pkg/config"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// EnvProject names the default project (like --project).
const EnvProject = "RUNR_PROJECT"

// App holds what every command shares. Tests build one with buffers and fakes.
type App struct {
	In  io.Reader
	Out io.Writer
	Err io.Writer

	// Flags shared by all commands.
	ContextName string
	OutputFmt   string
	Debug       bool
	Quiet       bool
	ProjectRef  string
	Dir         string

	// PollInterval of deploy / build / log polling.
	PollInterval time.Duration
	// HTTPClient is used for API calls (tests: the fake's client).
	HTTPClient *http.Client
	// IsTerminal reports whether stdin is interactive.
	IsTerminal func() bool
	// ReadSecret reads a line without echo.
	ReadSecret func(prompt string) (string, error)
	// OpenURL opens a URL in the browser.
	OpenURL func(url string) error
	// Docker runs docker for `runr dev`.
	Docker dev.Docker
	// DevCacheDir overrides the `runr dev` cache directory.
	DevCacheDir string
	// MaxFollowPolls stops `logs -f` after that many polls (tests); 0 = until interrupted.
	MaxFollowPolls int
	// ReleaseURL is the GitHub API URL of the latest CLI release (update check).
	ReleaseURL string

	cfg    *config.File
	client *runr.Client
	stdin  *bufio.Reader
}

// NewApp returns an App wired to the process's stdio.
func NewApp() *App {
	return &App{
		In:           os.Stdin,
		Out:          os.Stdout,
		Err:          os.Stderr,
		PollInterval: 2 * time.Second,
		IsTerminal:   func() bool { return term.IsTerminal(int(os.Stdin.Fd())) },
		ReadSecret: func(prompt string) (string, error) {
			fmt.Fprint(os.Stderr, prompt)
			b, err := term.ReadPassword(int(os.Stdin.Fd()))
			fmt.Fprintln(os.Stderr)
			return string(b), err
		},
		OpenURL:    openBrowser,
		Docker:     dev.ExecDocker{},
		ReleaseURL: "https://api.github.com/repos/brf-tech/runr/releases/latest",
	}
}

// Printer returns the output printer of the -o flag.
func (a *App) Printer() *output.Printer {
	return &output.Printer{Out: a.Out, Format: a.OutputFmt}
}

// Progress writes a status line to stderr unless -q or a machine format is set.
func (a *App) Progress(format string, args ...any) {
	if a.Quiet {
		return
	}
	fmt.Fprintf(a.Err, format+"\n", args...)
}

// Warn writes a warning to stderr.
func (a *App) Warn(format string, args ...any) {
	fmt.Fprintf(a.Err, "Warning: "+format+"\n", args...)
}

// Config loads the configuration file once.
func (a *App) Config() (*config.File, error) {
	if a.cfg != nil {
		return a.cfg, nil
	}
	f, err := config.Load()
	if err != nil {
		return nil, err
	}
	a.cfg = f
	return f, nil
}

// errNotLoggedIn means no API URL or token is configured.
var errNotLoggedIn = errors.New("not logged in: run `runr login`, or set RUNR_API_URL and RUNR_TOKEN")

// Client returns the API client of the selected context.
func (a *App) Client() (*runr.Client, error) {
	if a.client != nil {
		return a.client, nil
	}
	cfg, err := a.Config()
	if err != nil {
		return nil, err
	}
	res, err := cfg.Resolve(a.ContextName)
	if err != nil {
		return nil, err
	}
	if res.APIURL == "" || res.Token == "" {
		return nil, errNotLoggedIn
	}
	c, err := a.newClient(res.APIURL, res.Token)
	if err != nil {
		return nil, err
	}
	a.client = c
	return c, nil
}

func (a *App) newClient(apiURL, token string) (*runr.Client, error) {
	opts := runr.Options{
		APIURL:     apiURL,
		Token:      token,
		HTTPClient: a.HTTPClient,
		UserAgent:  "runr-cli/" + version.Version + " (" + runtime.GOOS + "/" + runtime.GOARCH + ")",
	}
	if a.Debug {
		opts.Debug = a.Err
	}
	return runr.New(opts)
}

// Resolver returns the runtime resolver: the platform's runtime list when logged in, the
// built-in defaults otherwise.
func (a *App) Resolver() runtimes.Resolver {
	var chain []runtimes.Resolver
	if c, err := a.Client(); err == nil {
		chain = append(chain, &runtimes.Catalog{Client: c}, &runtimes.API{Client: c})
	}
	chain = append(chain, runtimes.Builtin{})
	return runtimes.Chain{Resolvers: chain, Warn: func(err error) {
		a.Warn("runtime list of the platform unavailable (%v); using built-in defaults", err)
	}}
}

// ProjectFile loads runr.yaml from --dir / the working directory (and its parents).
func (a *App) ProjectFile() (*projectfile.File, error) {
	dir := a.Dir
	if dir == "" {
		dir = "."
	}
	p, err := projectfile.Find(dir)
	if err != nil {
		return nil, err
	}
	return projectfile.Load(p)
}

// ProjectName returns the project named by --project, RUNR_PROJECT or runr.yaml.
func (a *App) ProjectName() (string, error) {
	if a.ProjectRef != "" {
		return a.ProjectRef, nil
	}
	if v := os.Getenv(EnvProject); v != "" {
		return v, nil
	}
	pf, err := a.ProjectFile()
	if err != nil {
		if errors.Is(err, projectfile.ErrNotFound) {
			return "", usageErrorf("no project given: pass --project, set RUNR_PROJECT or run in a directory with runr.yaml")
		}
		return "", err
	}
	return pf.Name, nil
}

// Project resolves the project of the command.
func (a *App) Project(ctx context.Context) (*runr.Client, *runr.Project, error) {
	name, err := a.ProjectName()
	if err != nil {
		return nil, nil, err
	}
	c, err := a.Client()
	if err != nil {
		return nil, nil, err
	}
	p, err := c.FindProject(ctx, name)
	if err != nil {
		return nil, nil, err
	}
	return c, p, nil
}

// interactive reports whether stdin is a terminal.
func (a *App) interactive() bool { return a.IsTerminal != nil && a.IsTerminal() }

// readLine reads one line of stdin.
func (a *App) readLine(prompt string) (string, error) {
	if a.stdin == nil {
		a.stdin = bufio.NewReader(a.In)
	}
	if prompt != "" {
		fmt.Fprint(a.Err, prompt)
	}
	line, err := a.stdin.ReadString('\n')
	if err != nil && !(errors.Is(err, io.EOF) && line != "") {
		return "", err
	}
	return strings.TrimRight(line, "\r\n"), nil
}

// confirm asks a yes/no question; non-interactive sessions need --yes.
func (a *App) confirm(question string, yes bool) error {
	if yes {
		return nil
	}
	if !a.interactive() {
		return usageErrorf("%s: pass --yes to confirm in a non-interactive session", question)
	}
	ans, err := a.readLine(question + " [y/N] ")
	if err != nil {
		return err
	}
	if a := strings.ToLower(strings.TrimSpace(ans)); a == "y" || a == "yes" {
		return nil
	}
	return errAborted
}

var errAborted = errors.New("aborted")

func (a *App) waitOptions(timeout time.Duration, what string) runr.WaitOptions {
	return runr.WaitOptions{
		Timeout:  timeout,
		Interval: a.PollInterval,
		Progress: func(status string) { a.Progress("%s: %s", what, status) },
	}
}

// usageError is a wrong invocation (exit code 2).
type usageError struct{ msg string }

func (e *usageError) Error() string { return e.msg }

func usageErrorf(format string, args ...any) error {
	return &usageError{msg: fmt.Sprintf(format, args...)}
}

// Exit codes.
const (
	ExitOK          = 0
	ExitError       = 1
	ExitUsage       = 2
	ExitAuth        = 3
	ExitNotFound    = 4
	ExitValidation  = 5
	ExitFailed      = 6
	ExitTimeout     = 7
	ExitUnreachable = 8
)

// ExitCode maps an error to the process exit code.
func ExitCode(err error) int {
	if err == nil {
		return ExitOK
	}
	// A remote command's own exit status (runr sandbox exec / run) passes through.
	var es interface{ ExitStatus() int }
	if errors.As(err, &es) {
		return es.ExitStatus()
	}
	var (
		ue  *usageError
		ae  *runr.APIError
		ne  *runr.NetworkError
		nf  *runr.NotFoundError
		ve  *projectfile.ValidationError
		de  *runr.DeploymentFailedError
		be  *runr.BuildError
		fe  *runr.FailedError
		te  *runr.TimeoutError
		dve *runr.DomainVerifyError
	)
	switch {
	case errors.As(err, &ue):
		return ExitUsage
	case errors.Is(err, errNotLoggedIn):
		return ExitAuth
	case errors.As(err, &de), errors.As(err, &be), errors.As(err, &fe):
		return ExitFailed
	case errors.As(err, &te):
		return ExitTimeout
	case errors.As(err, &nf), errors.Is(err, projectfile.ErrNotFound):
		return ExitNotFound
	case errors.As(err, &ve), errors.As(err, &dve):
		return ExitValidation
	case errors.As(err, &ne):
		return ExitUnreachable
	case errors.As(err, &ae):
		switch {
		case ae.StatusCode == 401 || ae.StatusCode == 403:
			return ExitAuth
		case ae.StatusCode == 404:
			return ExitNotFound
		case ae.StatusCode == 422 || ae.StatusCode == 400 || ae.StatusCode == 409:
			return ExitValidation
		case ae.StatusCode >= 500:
			return ExitUnreachable
		}
	}
	return ExitError
}

// Execute runs the CLI and returns the exit code.
func Execute(app *App, args []string) int {
	root := NewRoot(app)
	root.SetArgs(args)
	root.SetIn(app.In)
	root.SetOut(app.Out)
	root.SetErr(app.Err)
	ctx, cancel := signalContext()
	defer cancel()
	err := root.ExecuteContext(ctx)
	if err != nil && isCobraUsage(err) {
		err = &usageError{msg: err.Error()}
	}
	if err != nil {
		if errors.Is(err, errAborted) {
			fmt.Fprintln(app.Err, "Aborted.")
			return ExitError
		}
		var es interface{ ExitStatus() int }
		if errors.As(err, &es) {
			// The remote command printed its own output; only its status is passed on.
			return es.ExitStatus()
		}
		fmt.Fprintf(app.Err, "Error: %v\n", err)
		switch {
		case len(runr.MissingPermissions(err)) > 0:
			perms := runr.MissingPermissions(err)
			fmt.Fprintf(app.Err, "The token lacks permission %s. Use a token with that scope "+
				"(runr tokens create NAME --ability %s) or ask an administrator for a role that grants it.\n",
				strings.Join(perms, " or "), perms[0])
		case runr.IsUnauthorized(err) && statusOf(err) == 403:
			fmt.Fprintln(app.Err, "The token or your role does not allow this.")
		case runr.IsUnauthorized(err):
			fmt.Fprintln(app.Err, "The token was rejected: run `runr login` again or check RUNR_TOKEN.")
		}
	}
	return ExitCode(err)
}

func statusOf(err error) int {
	var ae *runr.APIError
	if errors.As(err, &ae) {
		return ae.StatusCode
	}
	return 0
}

// NewRoot builds the command tree.
func NewRoot(app *App) *cobra.Command {
	root := &cobra.Command{
		Use:   "runr",
		Short: "Deploy and run functions and containers on RunR",
		Long: "runr is the command line of RunR, the serverless platform on Kubernetes and Knative.\n\n" +
			"Log in with `runr login`, scaffold a project with `runr init`, run it locally with\n" +
			"`runr dev` and ship it with `runr deploy`.",
		SilenceUsage:  true,
		SilenceErrors: true,
		PersistentPreRunE: func(cmd *cobra.Command, args []string) error {
			if !output.Valid(app.OutputFmt) {
				return usageErrorf("unknown output format %q (table, json or yaml)", app.OutputFmt)
			}
			if app.OutputFmt == "" {
				app.OutputFmt = output.Table
			}
			return nil
		},
	}
	root.SetFlagErrorFunc(func(c *cobra.Command, err error) error {
		return &usageError{msg: err.Error() + "\nSee '" + c.CommandPath() + " --help'."}
	})
	pf := root.PersistentFlags()
	pf.StringVar(&app.ContextName, "context", "", "context to use (default: the current context, or $RUNR_CONTEXT)")
	pf.StringVarP(&app.OutputFmt, "output", "o", output.Table, "output format: table, json or yaml")
	pf.BoolVar(&app.Debug, "debug", false, "print the HTTP trace (token redacted) to stderr")
	pf.BoolVarP(&app.Quiet, "quiet", "q", false, "print no progress messages")
	pf.StringVarP(&app.ProjectRef, "project", "p", "", "project name or id (default: $RUNR_PROJECT, or name in runr.yaml)")
	pf.StringVar(&app.Dir, "dir", "", "project directory holding runr.yaml (default: the working directory)")

	root.AddCommand(
		newLoginCmd(app), newLogoutCmd(app), newWhoamiCmd(app), newContextCmd(app),
		newInitCmd(app), newDeployCmd(app), newProjectsCmd(app), newVersionsCmd(app),
		newRollbackCmd(app), newLogsCmd(app), newEnvCmd(app), newDomainsCmd(app),
		newScaleCmd(app), newOpenCmd(app), newRegionsCmd(app), newStatusCmd(app),
		newDevCmd(app), newTokensCmd(app), newRuntimesCmd(app), newVersionCmd(app),
		newImagesCmd(app),
	)
	for _, group := range commandGroups {
		root.AddCommand(group(app))
	}
	wrapArgs(root)
	return root
}

// commandGroups are the command groups of the feature files (workflows, bindings, models, …);
// each registers its constructor in init with registerCommand.
var commandGroups []func(*App) *cobra.Command

func registerCommand(newCmd func(*App) *cobra.Command) {
	commandGroups = append(commandGroups, newCmd)
}

// wrapArgs makes argument validation errors usage errors (exit code 2).
func wrapArgs(c *cobra.Command) {
	if c.Args != nil {
		orig := c.Args
		c.Args = func(cmd *cobra.Command, args []string) error {
			if err := orig(cmd, args); err != nil {
				return &usageError{msg: err.Error() + "\nSee '" + cmd.CommandPath() + " --help'."}
			}
			return nil
		}
	}
	for _, sub := range c.Commands() {
		wrapArgs(sub)
	}
}

// isCobraUsage recognises the usage errors cobra returns itself.
func isCobraUsage(err error) bool {
	msg := err.Error()
	for _, p := range []string{"unknown command", "unknown flag", "unknown shorthand flag", "required flag", "invalid argument", "flag needs an argument"} {
		if strings.HasPrefix(msg, p) {
			return true
		}
	}
	return false
}

// projectDir returns the directory of runr.yaml or --dir / the working directory.
func (a *App) projectDir(pf *projectfile.File) (string, error) {
	if pf != nil && pf.Path() != "" {
		return filepath.Abs(pf.Dir())
	}
	dir := a.Dir
	if dir == "" {
		dir = "."
	}
	return filepath.Abs(dir)
}

func openBrowser(url string) error {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "darwin":
		cmd = exec.Command("open", url)
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	default:
		cmd = exec.Command("xdg-open", url)
	}
	return cmd.Start()
}
