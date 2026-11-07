package cmd

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"strconv"
	"strings"
	"text/tabwriter"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Runtime sources (docs/runtimes.md#runtime-sources): where runtimes come from — RunR's signed
// catalog (the built-in runtimes) and the OCI catalogs and Git repositories platform
// administrators and organizations connect — and the platform setting Built-in runtimes.
//
//	runr runtimes sources [ls]
//	runr runtimes sources get SOURCE
//	runr runtimes sources add --name N --kind git|oci-catalog --url U [flags]
//	runr runtimes sources update SOURCE [flags]
//	runr runtimes sources test [SOURCE] [flags]
//	runr runtimes sources sync SOURCE
//	runr runtimes sources rm SOURCE --yes
//	runr runtimes catalog [set --all | --only python,go]

func runtimeSourcesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "sources",
		Aliases: []string{"source"},
		Short:   "List, connect and read runtime sources (RunR's catalog, OCI catalogs, Git repositories)",
		Long: "Runtime sources are where runtimes come from: RunR's signed catalog (the built-in runtimes) and the\n" +
			"OCI catalogs and Git repositories (<path>/<id>/runtime.yaml) connected to the platform. RunR reads a\n" +
			"source when it is connected or changed, on its interval and with `sync`; a runtime that leaves its\n" +
			"source stays, orphaned, while projects use it. Platform sources (runtimes.manage) give every\n" +
			"organization their runtimes; sources of your organization (runtimes.use_custom, while the platform\n" +
			"allows custom runtimes) only its members. Credentials are write-only and never taken as flag values:\n" +
			"they come from environment variables, files or stdin.",
	}
	ls := runtimeSourcesLsCmd(app)
	cmd.AddCommand(ls, runtimeSourcesGetCmd(app), runtimeSourcesAddCmd(app), runtimeSourcesUpdateCmd(app),
		runtimeSourcesTestCmd(app), runtimeSourcesSyncCmd(app), runtimeSourcesRmCmd(app))
	cmd.RunE = ls.RunE
	cmd.Args = cobra.NoArgs
	return cmd
}

func runtimeSourcesLsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the runtime sources: kind, scope, status, runtimes, last read",
		Example: "  runr runtimes sources\n" +
			"  runr runtimes sources ls -o json",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListRuntimeSources(cmd.Context())
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "KIND", "SCOPE", "STATUS", "RUNTIMES", "LAST READ", "URL")
				for _, s := range list.Sources {
					name := s.Name
					if s.IsDefault {
						name += " *"
					}
					t.Row(name, s.Kind, s.Scope, sourceStatusLabel(&s), strconv.Itoa(s.RuntimesCount), gitAge(s.CheckedAt), sourceLocation(&s))
				}
				t.Note("* RunR's catalog: the built-in runtimes.")
				switch list.Catalog.Mode {
				case "bundle":
					t.Note("The built-in runtimes come from the API image's offline catalog (%d): RunR's catalog has not been read.", list.Catalog.Runtimes)
				case "source":
					t.Note("The built-in runtimes come from RunR's catalog (%d).", list.Catalog.Runtimes)
				}
			})
		},
	}
}

func runtimeSourcesGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get SOURCE",
		Short: "Show a runtime source: status, credentials set, its runtimes with their lifecycle, problems, patches",
		Example: "  runr runtimes sources get acme-runtimes\n" +
			"  runr runtimes sources get \"RunR runtimes\" -o json",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := c.FindRuntimeSource(cmd.Context(), args[0])
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			d, err := c.GetRuntimeSource(cmd.Context(), s.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(d, func(t *output.TableWriter) { runtimeSourceDetail(t, d) })
		},
	}
}

// sourceFlags are the flags of add, update and test.
type sourceFlags struct {
	name, kind, url, ref, path, publicKeyFile, patchFeed, registryHost string
	interval                                                           int
	disabled, enable, disable                                          bool

	gitTokenEnv, gitUsername, sshKeyFile, knownHostsFile string
	registryUsername, registryPasswordEnv                string
	gitTokenStdin, registryPasswordStdin                 bool
	clearGit, clearRegistry                              bool
}

// register adds the flags; mode is add, update or test.
func (o *sourceFlags) register(cmd *cobra.Command, mode string) {
	f := cmd.Flags()
	clears := ""
	if mode != "add" {
		clears = " (\"\" removes it)"
	}
	f.StringVar(&o.name, "name", "", "name, unique in its scope")
	f.StringVar(&o.kind, "kind", "", "git (a repository of <path>/<id>/runtime.yaml) or oci-catalog (a signed catalog)")
	f.StringVar(&o.url, "url", "", "the repository (https://… or git@…) or the catalog reference (registry/repo:tag)")
	f.StringVar(&o.ref, "ref", "", "git: branch, tag or commit (default the default branch)"+clears)
	f.StringVar(&o.path, "path", "", "git: directory of the runtimes (default runtimes)"+clears)
	f.StringVar(&o.publicKeyFile, "public-key-file", "", "cosign public key (PEM) the catalog and patch feed must verify with"+clears)
	f.StringVar(&o.patchFeed, "patch-feed", "", "OCI reference of the source's signed runtime patch feed (needs the public key)"+clears)
	f.StringVar(&o.registryHost, "registry-host", "", "registry the registry credentials are for (a git source's runtime images)"+clears)
	f.IntVar(&o.interval, "interval", 0, "minutes between reads (default 60; at least the platform's minimum)")
	switch mode {
	case "add":
		f.BoolVar(&o.disabled, "disabled", false, "connect it without reading it on its interval")
	case "update":
		f.BoolVar(&o.enable, "enable", false, "read the source on its interval again")
		f.BoolVar(&o.disable, "disable", false, "stop reading the source on its interval (its runtimes stay)")
	}
	f.StringVar(&o.gitTokenEnv, "git-token-env", "", "git over HTTPS: read the access token from this environment variable")
	f.BoolVar(&o.gitTokenStdin, "git-token-stdin", false, "git over HTTPS: read the access token from stdin")
	f.StringVar(&o.gitUsername, "git-username", "", "git over HTTPS: user name of the token (default x-access-token)"+clears)
	f.StringVar(&o.sshKeyFile, "ssh-key-file", "", "git over SSH: the deploy key (private key file)")
	f.StringVar(&o.knownHostsFile, "known-hosts-file", "", "git over SSH: known_hosts of the host (without it the host key is accepted on first use)")
	f.StringVar(&o.registryUsername, "registry-username", "", "registry user for a private catalog, patch feed or runtime images"+clears)
	f.StringVar(&o.registryPasswordEnv, "registry-password-env", "", "read the registry password from this environment variable")
	f.BoolVar(&o.registryPasswordStdin, "registry-password-stdin", false, "read the registry password from stdin")
	if mode != "add" {
		f.BoolVar(&o.clearGit, "clear-git-credentials", false, "remove the Git token, user name, deploy key and known_hosts")
		f.BoolVar(&o.clearRegistry, "clear-registry-credentials", false, "remove the registry user name and password")
	}
}

// input builds the request from the flags that were given.
func (o *sourceFlags) input(app *App, cmd *cobra.Command) (runr.RuntimeSourceInput, error) {
	var in runr.RuntimeSourceInput
	changed := cmd.Flags().Changed
	str := func(flag, v string) *string {
		if !changed(flag) {
			return nil
		}
		s := strings.TrimSpace(v)
		return &s
	}
	in.Name, in.Kind, in.URL = str("name", o.name), str("kind", o.kind), str("url", o.url)
	in.Ref, in.Path = str("ref", o.ref), str("path", o.path)
	in.PatchFeed, in.RegistryHost = str("patch-feed", o.patchFeed), str("registry-host", o.registryHost)
	if in.Kind != nil && *in.Kind != runr.RuntimeSourceGit && *in.Kind != runr.RuntimeSourceOCI {
		return in, usageErrorf("--kind must be git or oci-catalog")
	}
	if changed("public-key-file") {
		key, err := readOptionalFile("--public-key-file", o.publicKeyFile)
		if err != nil {
			return in, err
		}
		in.PublicKey = &key
	}
	if changed("interval") {
		if o.interval < 1 {
			return in, usageErrorf("--interval is in minutes, at least 1")
		}
		in.IntervalMinutes = &o.interval
	}
	switch {
	case o.enable && o.disable:
		return in, usageErrorf("--enable and --disable exclude each other")
	case o.disabled, o.disable:
		v := false
		in.IsEnabled = &v
	case o.enable:
		v := true
		in.IsEnabled = &v
	}

	if o.gitTokenStdin && o.registryPasswordStdin {
		return in, usageErrorf("--git-token-stdin and --registry-password-stdin both read stdin: give one of them with -env")
	}
	if (o.gitTokenEnv != "" || o.gitTokenStdin) && o.sshKeyFile != "" {
		return in, usageErrorf("a Git source authenticates with a token or a deploy key, not both")
	}
	if o.clearGit {
		in.ClearGitCredentials()
	}
	if o.clearRegistry {
		in.ClearRegistryCredentials()
	}
	token, err := secretFrom(app, "--git-token", o.gitTokenEnv, o.gitTokenStdin)
	if err != nil {
		return in, err
	}
	if token != nil {
		if o.clearGit {
			return in, usageErrorf("--clear-git-credentials removes the Git credentials: give a new token without it")
		}
		in.GitToken = token
	}
	in.GitUsername = orPtr(in.GitUsername, str("git-username", o.gitUsername))
	if o.sshKeyFile != "" {
		if o.clearGit {
			return in, usageErrorf("--clear-git-credentials removes the Git credentials: give a new deploy key without it")
		}
		key, err := readOptionalFile("--ssh-key-file", o.sshKeyFile)
		if err != nil {
			return in, err
		}
		if key == "" {
			return in, usageErrorf("--ssh-key-file %s is empty", o.sshKeyFile)
		}
		in.SSHPrivateKey = &key
	}
	if changed("known-hosts-file") {
		kh, err := readOptionalFile("--known-hosts-file", o.knownHostsFile)
		if err != nil {
			return in, err
		}
		in.SSHKnownHosts = &kh
	}
	in.RegistryUsername = orPtr(in.RegistryUsername, str("registry-username", o.registryUsername))
	password, err := secretFrom(app, "--registry-password", o.registryPasswordEnv, o.registryPasswordStdin)
	if err != nil {
		return in, err
	}
	if password != nil {
		if o.clearRegistry {
			return in, usageErrorf("--clear-registry-credentials removes the registry credentials: give a new password without it")
		}
		in.RegistryPassword = password
	}
	return in, nil
}

// orPtr returns b when it is set, else a.
func orPtr(a, b *string) *string {
	if b != nil {
		return b
	}
	return a
}

// secretFrom reads a credential from an environment variable or stdin (never from a flag value);
// nil when neither is given.
func secretFrom(app *App, flag, env string, stdin bool) (*string, error) {
	switch {
	case env != "" && stdin:
		return nil, usageErrorf("%s-env and %s-stdin exclude each other", flag, flag)
	case env != "":
		v, ok := os.LookupEnv(env)
		v = strings.TrimSpace(v)
		if !ok || v == "" {
			return nil, usageErrorf("%s-env: the environment variable %s is empty or not set", flag, env)
		}
		return &v, nil
	case stdin:
		line, err := app.readLine("")
		if err != nil {
			return nil, fmt.Errorf("read %s from stdin: %w", strings.TrimPrefix(flag, "--"), err)
		}
		v := strings.TrimSpace(line)
		if v == "" {
			return nil, usageErrorf("%s-stdin: stdin is empty", flag)
		}
		return &v, nil
	}
	return nil, nil
}

// readOptionalFile reads a file flag ("" stays "", which removes the value).
func readOptionalFile(flag, path string) (string, error) {
	if strings.TrimSpace(path) == "" {
		return "", nil
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return "", usageErrorf("%s: %v", flag, err)
	}
	return strings.TrimSpace(strings.ReplaceAll(string(data), "\r\n", "\n")), nil
}

func runtimeSourcesAddCmd(app *App) *cobra.Command {
	var o sourceFlags
	add := &cobra.Command{
		Use:     "add --name NAME --kind git|oci-catalog --url URL",
		Aliases: []string{"create", "connect"},
		Short:   "Connect a runtime source; RunR reads it right away",
		Long: "Connect an OCI catalog or a Git repository: a platform source with runtimes.manage, a source of\n" +
			"your organization with runtimes.use_custom. RunR reads it right away and prints its status and every\n" +
			"problem (file, runtime, JSON path). A source that could not be read is connected all the same: fix it\n" +
			"with `update` (`test` checks a change first). Credentials come from environment variables, files\n" +
			"or stdin, never from a flag value.",
		Example: "  runr runtimes sources add --name acme --kind git --url https://github.com/acme/runtimes.git --ref main\n" +
			"  GITLAB_TOKEN=… runr runtimes sources add --name acme --kind git \\\n" +
			"      --url https://gitlab.example.com/acme/runtimes.git --git-token-env GITLAB_TOKEN\n" +
			"  runr runtimes sources add --name acme --kind git --url git@github.com:acme/runtimes.git \\\n" +
			"      --ssh-key-file deploy_key --known-hosts-file known_hosts\n" +
			"  echo \"$REGISTRY_PASSWORD\" | runr runtimes sources add --name acme-catalog --kind oci-catalog \\\n" +
			"      --url registry.example.com/acme/runtimes:catalog --public-key-file cosign.pub \\\n" +
			"      --registry-username ci --registry-password-stdin",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			for _, f := range []string{"name", "kind", "url"} {
				if !cmd.Flags().Changed(f) {
					return usageErrorf("give --name, --kind and --url")
				}
			}
			in, err := o.input(app, cmd)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			s, err := c.CreateRuntimeSource(cmd.Context(), in)
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			app.Progress("Connected runtime source %s (%s, %s).", s.Name, s.Kind, s.Scope)
			sourceReadOutcome(app, s)
			return app.Printer().Print(s, func(t *output.TableWriter) { runtimeSourceKV(t, s) })
		},
	}
	o.register(add, "add")
	return add
}

func runtimeSourcesUpdateCmd(app *App) *cobra.Command {
	var o sourceFlags
	up := &cobra.Command{
		Use:   "update SOURCE",
		Short: "Change a runtime source; RunR reads it right away",
		Long: "Change a source's name, location, key, schedule or credentials; only the flags given change (an\n" +
			"empty --ref, --path, --public-key-file, --patch-feed or --registry-host removes the value). A new\n" +
			"location, key or credentials make RunR read the source anew. RunR's catalog takes --interval,\n" +
			"--enable and --disable only.",
		Example: "  runr runtimes sources update acme --ref v2\n" +
			"  GITHUB_TOKEN=… runr runtimes sources update acme --git-token-env GITHUB_TOKEN\n" +
			"  runr runtimes sources update acme --clear-git-credentials   # a repository that turned public\n" +
			"  runr runtimes sources update \"RunR runtimes\" --interval 30",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in, err := o.input(app, cmd)
			if err != nil {
				return err
			}
			if in.Empty() {
				return usageErrorf("nothing to change: give the flags to change (runr runtimes sources update --help)")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			src, err := c.FindRuntimeSource(cmd.Context(), args[0])
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			s, err := c.UpdateRuntimeSource(cmd.Context(), src.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Updated runtime source %s.", s.Name)
			sourceReadOutcome(app, s)
			return app.Printer().Print(s, func(t *output.TableWriter) { runtimeSourceKV(t, s) })
		},
	}
	o.register(up, "update")
	return up
}

func runtimeSourcesTestCmd(app *App) *cobra.Command {
	var o sourceFlags
	test := &cobra.Command{
		Use:   "test [SOURCE]",
		Short: "Read and validate a source without saving it: every runtime and problem",
		Long: "Read a source as given and validate every runtime.yaml it holds, without saving anything: whether\n" +
			"it can be read and verified, its revision, each runtime and every problem with its file and JSON\n" +
			"path. Without SOURCE the flags describe a new source (--kind and --url); with SOURCE they change a\n" +
			"connected source, whose stored credentials are used. Exit code 6 when the source cannot be read or\n" +
			"does not verify, 5 when a runtime has problems.",
		Example: "  runr runtimes sources test --kind git --url https://github.com/acme/runtimes.git --ref feature-x\n" +
			"  runr runtimes sources test acme --ref feature-x   # acme's credentials, another branch",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in, err := o.input(app, cmd)
			if err != nil {
				return err
			}
			if len(args) == 0 && (in.Kind == nil || in.URL == nil) {
				return usageErrorf("give --kind and --url, or the name of a connected source")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			id, name := "", "the source"
			if len(args) == 1 {
				src, err := c.FindRuntimeSource(cmd.Context(), args[0])
				if err != nil {
					return runtimeSourcesUnsupported(err)
				}
				id, name = src.ID, src.Name
			} else if in.URL != nil {
				name = *in.URL
			}
			res, err := c.TestRuntimeSource(cmd.Context(), id, in)
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			if perr := app.Printer().Print(res, func(t *output.TableWriter) { runtimeSourceTestDetail(t, res) }); perr != nil {
				return perr
			}
			switch {
			case !res.OK:
				return &runr.FailedError{What: "the read of", Ref: name, Status: res.Status, Message: res.Error}
			case len(res.Problems) > 0:
				return &runr.APIError{StatusCode: 422, Method: "POST", Path: "/runtime-sources/test",
					Message: fmt.Sprintf("%s has %s", name, countLabel(len(res.Problems), "problem"))}
			}
			return nil
		},
	}
	o.register(test, "test")
	return test
}

func runtimeSourcesSyncCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "sync SOURCE",
		Aliases: []string{"read"},
		Short:   "Read a runtime source now, even when its revision did not change",
		Long: "Read a source now. A read that fails or does not verify leaves its runtimes as they were and exits\n" +
			"with code 6 (its status and error are printed); a read already running exits with code 5. Problems of\n" +
			"single runtimes (status partial) are printed: the other runtimes were read.",
		Example: "  runr runtimes sources sync acme\n" +
			"  runr runtimes sources sync \"RunR runtimes\"",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			src, err := c.FindRuntimeSource(cmd.Context(), args[0])
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			s, err := c.SyncRuntimeSource(cmd.Context(), src.ID)
			if s != nil {
				if err == nil {
					app.Progress("Read runtime source %s: %s.", s.Name, s.Status)
				}
				if perr := app.Printer().Print(s, func(t *output.TableWriter) { runtimeSourceKV(t, s) }); perr != nil {
					return perr
				}
			}
			return err
		},
	}
}

func runtimeSourcesRmCmd(app *App) *cobra.Command {
	var yes bool
	rm := &cobra.Command{
		Use:     "rm SOURCE",
		Aliases: []string{"remove", "delete", "disconnect"},
		Short:   "Remove a runtime source: its runtimes no project uses go, the others stay orphaned",
		Long: "Remove a source and the patches it carried. Its runtimes that no project uses are deleted; the\n" +
			"others stay, orphaned: hidden from new projects, running and deployable until their sunset at the end\n" +
			"of the grace period. RunR's catalog cannot be removed (turn it off with update --disable).",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			src, err := c.FindRuntimeSource(cmd.Context(), args[0])
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			if err := app.confirm("Remove the runtime source "+src.Name, yes); err != nil {
				return err
			}
			if err := c.DeleteRuntimeSource(cmd.Context(), src.ID); err != nil {
				return err
			}
			app.Progress("Removed runtime source %s.", src.Name)
			return app.Printer().Print(map[string]any{"deleted": src.ID, "name": src.Name}, func(*output.TableWriter) {})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return rm
}

// runtimeSourcesUnsupported explains a 404 of an API before runtime sources.
func runtimeSourcesUnsupported(err error) error {
	if errors.Is(err, runr.ErrUnsupported) {
		return fmt.Errorf("this RunR API has no runtime sources (an older release)")
	}
	return err
}

// sourceReadOutcome tells how the read after add / update went.
func sourceReadOutcome(app *App, s *runr.RuntimeSource) {
	switch {
	case runr.RuntimeSourceFailed(s.Status):
		app.Warn("RunR could not read %s (%s): %s. Fix it with `runr runtimes sources update %s`; `runr runtimes sources test %s` checks a change first.",
			s.Name, s.Status, output.Dash(s.Error), s.Name, s.Name)
	case s.Status == runr.RuntimeSourcePartial:
		app.Warn("%s was read with %s: the runtimes concerned were refused, its other runtimes read.", s.Name, countLabel(len(s.Problems), "problem"))
	}
}

// sourceStatusLabel is the status with "off" for a source that is not read on its interval.
func sourceStatusLabel(s *runr.RuntimeSource) string {
	label := strings.ReplaceAll(s.Status, "_", " ")
	if !s.IsEnabled && s.Status != runr.RuntimeSourceDisabled {
		label += " (off)"
	}
	return label
}

// sourceLocation is the URL with a git source's ref and path.
func sourceLocation(s *runr.RuntimeSource) string {
	loc := s.URL
	if s.Ref != "" {
		loc += "@" + s.Ref
	}
	if s.Path != "" {
		loc += " (" + s.Path + ")"
	}
	return loc
}

// sourceCredentialsLabel says which credentials are set ("none" for a public source).
func sourceCredentialsLabel(c runr.RuntimeSourceCredentials) string {
	var parts []string
	switch c.Git {
	case "token":
		user := c.GitUsername
		if user == "" {
			user = "x-access-token"
		}
		parts = append(parts, "git token "+output.Dash(c.GitTokenHint)+" as "+user)
	case "ssh":
		kh := "host key accepted on first use"
		if c.KnownHostsSet {
			kh = "known_hosts set"
		}
		parts = append(parts, "deploy key ("+kh+")")
	}
	if c.RegistryUsername != "" || c.RegistryPasswordSet {
		reg := "registry user " + output.Dash(c.RegistryUsername)
		if c.RegistryPasswordSet {
			reg += " with a password"
		}
		parts = append(parts, reg)
	}
	if len(parts) == 0 {
		return "none"
	}
	return strings.Join(parts, "; ")
}

// runtimeSourceKV is the detail of a source.
func runtimeSourceKV(t *output.TableWriter, s *runr.RuntimeSource) {
	name := s.Name
	if s.IsDefault {
		name += " (RunR's catalog: the built-in runtimes)"
	}
	t.KV("Source", name)
	t.KV("ID", s.ID)
	scope := s.Scope
	if s.TenantID != "" {
		scope += " " + s.TenantID
	}
	t.KV("Kind", s.Kind+", "+scope)
	t.KV("URL", s.URL)
	if s.Kind == runr.RuntimeSourceGit {
		ref, path := s.Ref, s.Path
		if ref == "" {
			ref = "(the default branch)"
		}
		if path == "" {
			path = "runtimes (the default)"
		}
		t.KV("Ref", ref)
		t.KV("Path", path)
	}
	key := "none"
	if s.PublicKey != "" {
		key = "set"
		if s.SignatureVerified {
			key += ", the last read verified"
		}
	}
	t.KV("Public key", key)
	if s.PatchFeed != "" {
		t.KV("Patch feed", s.PatchFeed)
	}
	if s.RegistryHost != "" {
		t.KV("Registry host", s.RegistryHost)
	}
	if !s.IsDefault {
		t.KV("Credentials", sourceCredentialsLabel(s.Credentials))
	}
	read := fmt.Sprintf("every %d minutes", s.IntervalMinutes)
	if !s.IsEnabled {
		read = "off (only with sync)"
	} else if s.NextCheckAt != "" {
		read += ", next " + patchTime(s.NextCheckAt)
	}
	t.KV("Reads", read)
	status := strings.ReplaceAll(s.Status, "_", " ")
	if s.Error != "" {
		status += ": " + s.Error
	}
	t.KV("Status", status)
	t.KV("Revision", output.Dash(s.Revision))
	t.KV("Runtimes", strconv.Itoa(s.RuntimesCount))
	t.KV("Last read", output.Dash(patchTime(s.CheckedAt)))
	t.KV("Last good read", output.Dash(patchTime(s.SyncedAt)))
	sourceProblems(t, s.Problems)
}

// sourceProblems adds the problems of a read as an aligned list after the table.
func sourceProblems(t *output.TableWriter, problems []runr.RuntimeSourceProblem) {
	if len(problems) == 0 {
		return
	}
	rows := make([][]string, 0, len(problems))
	for _, p := range problems {
		rows = append(rows, []string{output.Dash(p.File), output.Dash(p.Runtime), output.Dash(p.Path), p.Message})
	}
	subTable(t, countLabel(len(problems), "problem")+":", []string{"FILE", "RUNTIME", "PATH", "PROBLEM"}, rows)
}

// subTable renders a second table after the first one (as notes, aligned).
func subTable(t *output.TableWriter, title string, header []string, rows [][]string) {
	var b bytes.Buffer
	tw := tabwriter.NewWriter(&b, 0, 4, 3, ' ', 0)
	fmt.Fprintln(tw, strings.Join(header, "\t"))
	for _, r := range rows {
		fmt.Fprintln(tw, strings.Join(r, "\t"))
	}
	_ = tw.Flush()
	t.Note("")
	t.Note("%s", title)
	for _, line := range strings.Split(strings.TrimRight(b.String(), "\n"), "\n") {
		t.Note("  %s", strings.TrimRight(line, " "))
	}
}

// runtimeSourceDetail is `runr runtimes sources get`: the source, its runtimes with their
// lifecycle, the problems of its last read and the patches it carries.
func runtimeSourceDetail(t *output.TableWriter, d *runr.RuntimeSourceDetail) {
	runtimeSourceKV(t, &d.Source)
	t.Header("RUNTIME", "PATH", "STATE", "VERSIONS", "PROJECTS")
	for i := range d.Runtimes {
		rt := &d.Runtimes[i]
		var versions []string
		for j := range rt.Versions {
			v := &rt.Versions[j]
			label := v.Version
			if v.IsDefault {
				label += "*"
			}
			if st := rt.State(v); st != "" && st != runr.LifecycleActive {
				label += " (" + st + versionEnd(v) + ")"
			} else if end := versionEnd(v); end != "" {
				label += " (" + strings.TrimPrefix(end, " ") + ")"
			}
			versions = append(versions, label)
		}
		t.Row(rt.ID, output.Dash(rt.SourcePath), output.Dash(lifecycleLabel(rt.Lifecycle)), joinOrDash(versions), strconv.Itoa(rt.ProjectsCount))
	}
	if len(d.Runtimes) == 0 {
		t.Note("No runtimes from this source yet.")
	} else {
		t.Note("* the default version.")
	}
	if len(d.Patches) > 0 {
		rows := make([][]string, 0, len(d.Patches))
		for _, x := range d.Patches {
			rows = append(rows, []string{x.Runtime, x.Version, x.Tag, x.Level, gitAge(x.PublishedAt), output.Dash(patchTime(x.DeadlineAt)), joinOrDash(x.CVEs)})
		}
		subTable(t, "Patches:", []string{"RUNTIME", "VERSION", "TAG", "LEVEL", "PUBLISHED", "DEADLINE", "CVES"}, rows)
	}
	if f := d.PatchFeed; f != nil {
		state := f.Status
		if f.Error != "" {
			state += ": " + f.Error
		}
		t.Note("Patch feed %s: %s.", f.Reference, state)
	}
}

// versionEnd is ", ends 2027-01-31" for a version with an end date ("" without).
func versionEnd(v *runr.RuntimeVersion) string {
	switch {
	case v.EndsAt != "":
		return ", ends " + v.EndsAt
	case v.EOLAt != "":
		return ", eol " + v.EOLAt
	}
	return ""
}

// lifecycleLabel renders a runtime's lifecycle: "deprecated since 2026-11-01, sunset 2027-02-01:
// use python (…)"; "" without one.
func lifecycleLabel(lc *runr.RuntimeLifecycle) string {
	if lc == nil {
		return ""
	}
	label := lc.State
	switch lc.State {
	case runr.LifecycleOrphaned:
		label += " (left its source)"
		if lc.SunsetAt != "" {
			label += ", sunset " + lc.SunsetAt
		}
	case runr.LifecycleDeprecated:
		if lc.DeprecatedAt != "" {
			label += " since " + lc.DeprecatedAt
		}
		if lc.SunsetAt != "" {
			label += ", sunset " + lc.SunsetAt
		}
	case runr.LifecycleSunset:
		if lc.SunsetAt != "" {
			label += " since " + lc.SunsetAt
		}
	case runr.LifecycleActive:
		if lc.SunsetAt != "" {
			label += ", sunset " + lc.SunsetAt
		} else if lc.DeprecatedAt != "" {
			label += ", deprecated from " + lc.DeprecatedAt
		}
	}
	if lc.Replacement != "" {
		label += ": use " + lc.Replacement
	}
	if lc.Notice != "" {
		label += " (" + lc.Notice + ")"
	}
	return label
}

// runtimeSourceTestDetail is `runr runtimes sources test`.
func runtimeSourceTestDetail(t *output.TableWriter, res *runr.RuntimeSourceTest) {
	status := strings.ReplaceAll(res.Status, "_", " ")
	if res.Error != "" {
		status += ": " + res.Error
	}
	t.KV("Status", status)
	t.KV("Revision", output.Dash(res.Revision))
	t.KV("Signature", map[bool]string{true: "verified", false: "not verified (no public key, or a git source)"}[res.SignatureVerified])
	t.Header("RUNTIME", "PATH", "VALID")
	for _, e := range res.Runtimes {
		valid := "yes"
		if !e.Valid {
			valid = "no"
		}
		t.Row(output.Dash(e.ID), e.Path, valid)
	}
	if res.OK && len(res.Runtimes) == 0 {
		t.Note("The source defines no runtimes (a git source: <path>/<id>/runtime.yaml).")
	}
	sourceProblems(t, res.Problems)
}

// ---------------------------------------------------------------------------------------
// runr runtimes catalog: the built-in catalog and the Built-in runtimes setting

func runtimeCatalogCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "catalog",
		Short: "Show the built-in catalog and which built-in runtimes the platform offers (runtimes.manage)",
		Long: "The built-in runtimes come from RunR's signed catalog as last read, or — while it cannot be read —\n" +
			"from the API image's offline catalog. The platform setting Built-in runtimes offers every runtime of\n" +
			"the catalog (also those it gains later) or a selection; a built-in left out is not offered for new\n" +
			"projects, its projects keep deploying.",
		Example: "  runr runtimes catalog\n" +
			"  runr runtimes catalog set --only python,nodejs,go\n" +
			"  runr runtimes catalog set --all",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			cat, err := c.GetBuiltinCatalog(cmd.Context())
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			return app.Printer().Print(cat, func(t *output.TableWriter) { builtinCatalogDetail(t, cat) })
		},
	}
	var all bool
	var only []string
	set := &cobra.Command{
		Use:   "set --all | --only ID,…",
		Short: "Offer every built-in runtime of the catalog, or only some",
		Example: "  runr runtimes catalog set --only python,nodejs\n" +
			"  runr runtimes catalog set --all   # also the runtimes the catalog gains later",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if all == (len(only) > 0) {
				return usageErrorf("give --all or --only")
			}
			var ids []string
			if !all {
				ids = []string{}
				for _, id := range only {
					if id = strings.TrimSpace(id); id != "" {
						ids = append(ids, id)
					}
				}
				if len(ids) == 0 {
					return usageErrorf("--only needs runtime ids")
				}
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			cat, err := c.SetBuiltinRuntimes(cmd.Context(), ids)
			if err != nil {
				return runtimeSourcesUnsupported(err)
			}
			if all {
				app.Progress("The platform offers every built-in runtime of the catalog.")
			} else {
				app.Progress("The platform offers the built-in runtimes %s.", strings.Join(ids, ", "))
			}
			return app.Printer().Print(cat, func(t *output.TableWriter) { builtinCatalogDetail(t, cat) })
		},
	}
	set.Flags().BoolVar(&all, "all", false, "every runtime of the catalog, also those it gains later")
	set.Flags().StringSliceVar(&only, "only", nil, "only these built-in runtimes (comma-separated or repeated)")
	cmd.AddCommand(set)
	return cmd
}

func builtinCatalogDetail(t *output.TableWriter, cat *runr.BuiltinCatalog) {
	switch cat.Mode {
	case "bundle":
		t.KV("Catalog", "the API image's offline catalog (RunR's catalog has not been read)")
	default:
		t.KV("Catalog", "RunR's catalog")
	}
	t.KV("Source", cat.Source.Name+" "+cat.Source.URL)
	status := strings.ReplaceAll(cat.Source.Status, "_", " ")
	if cat.Source.Error != "" {
		status += ": " + cat.Source.Error
	}
	t.KV("Status", status+", revision "+output.Dash(cat.Source.Revision)+", read "+gitAge(cat.Source.SyncedAt)+" ago")
	offer := "every runtime of the catalog, also those it gains later"
	if cat.BuiltinRuntimes != nil {
		offer = "only " + joinOrDash(cat.BuiltinRuntimes)
	}
	if !cat.Saved {
		offer += " (not saved: EXECUTOR_RUNTIMES or the default applies)"
	}
	t.KV("Built-in runtimes", offer)
	t.Header("RUNTIME", "NAME", "OFFERED", "OFFLINE")
	for _, r := range cat.Runtimes {
		t.Row(r.ID, r.Name, yesNo(r.Offered), yesNo(r.InBundle))
	}
	t.Note("OFFLINE: in the API image's offline catalog.")
}
