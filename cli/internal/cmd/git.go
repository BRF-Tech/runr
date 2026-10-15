package cmd

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

func init() {
	registerCommand(newGitCmd)
	registerCommand(func(app *App) *cobra.Command { return newPreviewsCmd(app) })
}

// gitRunner runs the git CLI in dir (runr git remote --add); tests replace it. RUNR_GIT names
// another binary.
var gitRunner = func(ctx context.Context, dir string, args ...string) (string, error) {
	bin := os.Getenv("RUNR_GIT")
	if bin == "" {
		bin = "git"
	}
	cmd := exec.CommandContext(ctx, bin, args...)
	cmd.Dir = dir
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if err := cmd.Run(); err != nil {
		msg := strings.TrimSpace(stderr.String())
		if msg == "" {
			msg = err.Error()
		}
		return stdout.String(), fmt.Errorf("git %s: %s", strings.Join(args, " "), msg)
	}
	return stdout.String(), nil
}

func newGitCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "git",
		Args:  cobra.NoArgs,
		Short: "Deploy from git: the project's RunR remote, a connected repository, pull request previews",
		Long: "Every project has a git remote on the console domain: `git push runr main` creates a new\n" +
			"version and deploys it (the password is an API token with git.push). A project can be\n" +
			"connected to a GitHub, GitLab or Forgejo/Gitea repository instead; its pull and merge\n" +
			"requests then get preview environments. Without a subcommand, `runr git` shows the status.",
	}
	status := newGitStatusCmd(app)
	cmd.AddCommand(status)
	cmd.RunE = status.RunE
	cmd.AddCommand(
		newGitRemoteCmd(app), newGitDeploysCmd(app), newGitRedeployCmd(app), newGitSetCmd(app),
		newGitReposCmd(app), newGitInstallationsCmd(app), newGitConnectCmd(app), newGitDisconnectCmd(app),
		newPreviewsCmd(app), newGitProvidersCmd(app),
	)
	return cmd
}

// gitAge renders an API timestamp as a relative age.
func gitAge(s string) string {
	if s == "" {
		return "-"
	}
	t, err := time.Parse(time.RFC3339, s)
	if err != nil {
		return s
	}
	return output.Age(&t)
}

func gitOnOff(b bool) string {
	if b {
		return "on"
	}
	return "off"
}

func gitProviderLabel(t string) string {
	switch t {
	case runr.GitProviderGitHub:
		return "GitHub"
	case runr.GitProviderGitLab:
		return "GitLab"
	case runr.GitProviderForgejo:
		return "Forgejo/Gitea"
	}
	return t
}

func gitDeployVersion(d runr.GitDeploy) string {
	if d.Version != nil && d.Version.Label != "" {
		return d.Version.Label
	}
	return "-"
}

func gitDeploySHA(d runr.GitDeploy) string {
	if d.ShortSHA != "" {
		return d.ShortSHA
	}
	if len(d.SHA) > 7 {
		return d.SHA[:7]
	}
	return output.Dash(d.SHA)
}

func gitDeployKind(d runr.GitDeploy) string {
	if d.Kind == "preview" && d.PRNumber != nil {
		return fmt.Sprintf("preview #%d", *d.PRNumber)
	}
	return output.Dash(d.Kind)
}

func gitDeployRows(t *output.TableWriter, list []runr.GitDeploy) {
	t.Header("CREATED", "COMMIT", "KIND", "BRANCH", "STATUS", "VERSION", "AUTHOR", "MESSAGE")
	for _, d := range list {
		t.Row(gitAge(d.CreatedAt), gitDeploySHA(d), gitDeployKind(d), output.Dash(d.Branch), d.Status,
			gitDeployVersion(d), output.Dash(d.AuthorName), output.Truncate(output.Dash(d.Message), 50))
	}
	for _, d := range list {
		if d.Failed() && d.StatusMessage != "" {
			t.Note("%s %s: %s", gitDeploySHA(d), d.Status, d.StatusMessage)
		}
	}
}

func gitAbilitiesLabel(a runr.GitAbilities) string {
	var out []string
	if a.Pull {
		out = append(out, "pull")
	}
	if a.Push {
		out = append(out, "push")
	}
	if a.Manage {
		out = append(out, "manage")
	}
	if a.ManagePreviews {
		out = append(out, "previews")
	}
	return joinOrDash(out)
}

func gitPreviewsLabel(s runr.GitSettings) string {
	if !s.PreviewsEnabled {
		return "off"
	}
	label := fmt.Sprintf("on, at most %d live, deleted after %d h without a commit", s.PreviewLimit, s.PreviewTTLHours)
	if s.ForkPreviews {
		label += "; forks too"
		if s.ForkSecrets {
			label += " (with the secret variables)"
		} else {
			label += " (without the secret variables)"
		}
	}
	return label
}

func gitConnectionLabel(c *runr.GitConnection) string {
	if c == nil {
		return "-"
	}
	via := map[string]string{"app": "GitHub App", "pat": "access token", "oauth": "GitLab sign-in"}[c.AuthMode]
	if via == "" {
		via = c.AuthMode
	}
	label := c.RepoFullName + " on " + gitProviderLabel(c.ProviderType)
	if c.ProviderName != "" && c.ProviderName != gitProviderLabel(c.ProviderType) {
		label += " (" + c.ProviderName + ")"
	}
	if via != "" {
		label += ", " + via
	}
	if !c.WebhookRegistered {
		label += ", no webhook"
	}
	return label
}

func gitStatusDetail(t *output.TableWriter, p *runr.Project, info *runr.GitInfo) {
	t.KV("Project", p.Name)
	remote := info.RemoteURL
	if info.ReadOnly {
		remote += " (read-only)"
	}
	t.KV("Remote", remote)
	branch := info.Settings.DeployBranch
	if len(info.RemoteHead) >= 7 {
		branch += " (head " + info.RemoteHead[:7] + ")"
	} else if !info.RemoteReady {
		branch += " (nothing pushed)"
	}
	t.KV("Deploy branch", branch)
	t.KV("Root directory", output.Dash(info.Settings.RootDir))
	t.KV("Auto-deploy", gitOnOff(info.Settings.AutoDeploy))
	t.KV("Force pushes", map[bool]string{true: "allowed", false: "refused"}[info.Settings.AllowForcePush])
	t.KV("Repository", gitConnectionLabel(info.Connection))
	t.KV("Previews", gitPreviewsLabel(info.Settings))
	if keys := info.Settings.PreviewEnv.Keys(); len(keys) > 0 {
		t.KV("Preview env", strings.Join(keys, ", "))
	}
	t.KV("Your access", gitAbilitiesLabel(info.Abilities))
}

func newGitStatusCmd(app *App) *cobra.Command {
	deploys := 5
	cmd := &cobra.Command{
		Use:   "status",
		Short: "Show the remote, the settings, the connected repository and the last deploys",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			info, err := c.GetGit(ctx, p.ID)
			if err != nil {
				return err
			}
			list := []runr.GitDeploy{}
			if deploys > 0 {
				if list, _, err = c.GitDeploys(ctx, p.ID, "", deploys); err != nil {
					return err
				}
				if list == nil {
					list = []runr.GitDeploy{}
				}
			}
			return app.Printer().Print(map[string]any{"git": info, "deploys": list}, func(t *output.TableWriter) {
				gitStatusDetail(t, p, info)
				if len(list) > 0 {
					gitDeployRows(t, list)
				} else if deploys > 0 {
					t.Note("")
					t.Note("No git deploys yet: `runr git remote --add`, then `git push runr %s`.", info.Settings.DeployBranch)
				}
			})
		},
	}
	cmd.Flags().IntVar(&deploys, "deploys", 5, "how many of the last deploys to show (0: none)")
	return cmd
}

// gitHost returns scheme://host of a remote URL (credential helper scope).
func gitHost(remote string) string {
	u, err := url.Parse(remote)
	if err != nil || u.Host == "" {
		return remote
	}
	return u.Scheme + "://" + u.Host
}

func newGitRemoteCmd(app *App) *cobra.Command {
	var add, force, printOnly bool
	cmd := &cobra.Command{
		Use:   "remote [NAME]",
		Short: "Print the project's git remote; --add adds it to the local repository",
		Long: "Prints the URL of the project's RunR git remote. With --add the remote is added to the git\n" +
			"repository of the project directory (`git remote add NAME URL`, NAME default runr); --force\n" +
			"points an existing remote of that name to it. git asks for credentials on the first push:\n" +
			"any user name, an API token with git.push as the password.",
		Example: "  runr git remote\n" +
			"  runr git remote --add            # git remote add runr https://…/git/<org>/<project>.git\n" +
			"  runr git remote --add deploy --force\n" +
			"  git push runr main",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			name := "runr"
			if len(args) == 1 {
				name = args[0]
			}
			if (force || printOnly) && !add {
				return usageErrorf("--force and --print go with --add")
			}
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			info, err := c.GetGit(ctx, p.ID)
			if err != nil {
				return err
			}
			res := map[string]any{"project": p.Name, "remote": name, "remote_url": info.RemoteURL, "read_only": info.ReadOnly,
				"deploy_branch": info.Settings.DeployBranch, "username_hint": info.UsernameHint, "added": false}
			var gitArgs []string
			dir := ""
			if add {
				pf, _ := app.ProjectFile()
				if dir, err = app.projectDir(pf); err != nil {
					return err
				}
				gitArgs = []string{"remote", "add", name, info.RemoteURL}
				if printOnly {
					res["command"] = append([]string{"git", "-C", dir}, gitArgs...)
				} else {
					current, getErr := gitRunner(ctx, dir, "remote", "get-url", name)
					current = strings.TrimSpace(current)
					switch {
					case getErr != nil:
					case current == info.RemoteURL:
						gitArgs = nil
					case !force:
						return usageErrorf("the git remote %s already points to %s; --force points it to %s", name, current, info.RemoteURL)
					default:
						gitArgs = []string{"remote", "set-url", name, info.RemoteURL}
					}
					if gitArgs != nil {
						if _, err := gitRunner(ctx, dir, gitArgs...); err != nil {
							return err
						}
						res["added"] = true
						app.Progress("git %s", strings.Join(gitArgs, " "))
					} else {
						app.Progress("The git remote %s already points to %s.", name, info.RemoteURL)
					}
				}
			}
			return app.Printer().Print(res, func(t *output.TableWriter) {
				if printOnly {
					t.Note("git -C %s", shellJoin(append([]string{dir}, gitArgs...)))
					return
				}
				t.KV("Remote", info.RemoteURL)
				t.KV("Deploy branch", info.Settings.DeployBranch)
				t.Note("")
				if info.ReadOnly && info.Connection != nil {
					t.Note("Read-only: %s deploys from %s; push there (clone and fetch work here).", p.Name, info.Connection.RepoFullName)
					return
				}
				if !add {
					t.Note("Add it:  git remote add %s %s", name, info.RemoteURL)
				}
				t.Note("Deploy:  git push %s %s", name, info.Settings.DeployBranch)
				user := info.UsernameHint
				if user == "" {
					user = "any"
				}
				t.Note("Sign in: user name %s, password an API token with git.push (runr tokens create git --ability git.push).", user)
				t.Note("Keep it: git config --global credential.%s.helper store", gitHost(info.RemoteURL))
			})
		},
	}
	cmd.Flags().BoolVar(&add, "add", false, "add the remote to the git repository of the project directory")
	cmd.Flags().BoolVar(&force, "force", false, "with --add: point an existing remote of that name to the project")
	cmd.Flags().BoolVar(&printOnly, "print", false, "with --add: print the git command instead of running it")
	return cmd
}

func newGitDeploysCmd(app *App) *cobra.Command {
	var kind string
	var limit int
	cmd := &cobra.Command{
		Use:     "deploys",
		Aliases: []string{"history"},
		Short:   "List git deploys: pushes to the deploy branch and pull request previews, newest first",
		Example: "  runr git deploys\n  runr git deploys --kind preview --limit 50",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			switch kind {
			case "", "production", "preview":
			default:
				return usageErrorf("--kind must be production or preview")
			}
			if limit < 1 || limit > 100 {
				return usageErrorf("--limit must be between 1 and 100")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			list, page, err := c.GitDeploys(ctx, p.ID, kind, limit)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.GitDeploy{}
			}
			return app.Printer().Print(map[string]any{"deploys": list, "total": page.Total}, func(t *output.TableWriter) {
				gitDeployRows(t, list)
				if page.Total > len(list) {
					t.Note("%d of %d deploys; --limit shows more.", len(list), page.Total)
				}
			})
		},
	}
	cmd.Flags().StringVar(&kind, "kind", "", "production or preview")
	cmd.Flags().IntVar(&limit, "limit", 30, "how many deploys (1-100)")
	return cmd
}

func newGitRedeployCmd(app *App) *cobra.Command {
	var wait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "redeploy",
		Short: "Deploy the head of the deploy branch again",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			d, err := c.RedeployGit(ctx, p.ID)
			if err != nil {
				return err
			}
			app.Progress("Redeploying %s of %s (%s)", gitDeploySHA(*d), p.Name, output.Dash(d.Branch))
			if wait {
				d, err = c.WaitGitDeploy(ctx, p.ID, d, app.waitOptions(timeout, "Deploy "+gitDeploySHA(*d)))
			}
			if d != nil {
				if perr := app.Printer().Print(d, func(t *output.TableWriter) {
					t.KV("Commit", gitDeploySHA(*d))
					t.KV("Branch", output.Dash(d.Branch))
					t.KV("Status", d.Status)
					if d.StatusMessage != "" {
						t.KV("Message", d.StatusMessage)
					}
					t.KV("Version", gitDeployVersion(*d))
					if d.URL != "" {
						t.KV("URL", d.URL)
					}
				}); perr != nil && err == nil {
					err = perr
				}
			}
			return err
		},
	}
	cmd.Flags().BoolVar(&wait, "wait", false, "wait until the deploy ended (exit code 6 when it failed)")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	return cmd
}

func newGitSetCmd(app *App) *cobra.Command {
	var (
		branch, rootDir                                     string
		autoDeploy, forcePush, previews, forks, forkSecrets bool
		limit, ttl                                          int
		envSet, envUnset                                    []string
		envClear                                            bool
	)
	cmd := &cobra.Command{
		Use:   "set",
		Short: "Change the git settings: deploy branch, root directory, auto-deploy, previews",
		Example: "  runr git set --branch release --root-dir services/api\n" +
			"  runr git set --auto-deploy=false\n" +
			"  runr git set --previews --preview-limit 5 --preview-ttl 48 --preview-env FEATURE_FLAGS=beta",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			fl := cmd.Flags()
			in := runr.GitSettingsInput{}
			if fl.Changed("branch") {
				if strings.TrimSpace(branch) == "" {
					return usageErrorf("--branch must not be empty")
				}
				in.DeployBranch = &branch
			}
			if fl.Changed("root-dir") {
				d := strings.Trim(rootDir, "/ ")
				in.RootDir = &d
			}
			boolFlag := func(name string, v bool, dst **bool) {
				if fl.Changed(name) {
					b := v
					*dst = &b
				}
			}
			boolFlag("auto-deploy", autoDeploy, &in.AutoDeploy)
			boolFlag("force-push", forcePush, &in.AllowForcePush)
			boolFlag("previews", previews, &in.PreviewsEnabled)
			boolFlag("fork-previews", forks, &in.ForkPreviews)
			boolFlag("fork-secrets", forkSecrets, &in.ForkSecrets)
			if fl.Changed("preview-limit") {
				if limit < 0 || limit > 100 {
					return usageErrorf("--preview-limit must be 0 or more")
				}
				in.PreviewLimit = &limit
			}
			if fl.Changed("preview-ttl") {
				if ttl < 1 {
					return usageErrorf("--preview-ttl must be at least 1 hour")
				}
				in.PreviewTTLHours = &ttl
			}
			sets, err := parseKeyValues("--preview-env", envSet)
			if err != nil {
				return err
			}
			for k := range sets {
				if err := runr.ValidateEnvName(k); err != nil {
					return usageErrorf("--preview-env: %v", err)
				}
			}
			envChange := len(sets) > 0 || len(envUnset) > 0 || envClear
			if in.Empty() && !envChange {
				return usageErrorf("give at least one setting (see runr git set --help)")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			if envChange {
				env := map[string]string{}
				if !envClear {
					cur, err := c.GetGit(ctx, p.ID)
					if err != nil {
						return err
					}
					for k, v := range cur.Settings.PreviewEnv {
						env[k] = v
					}
				}
				for _, k := range envUnset {
					delete(env, k)
				}
				for k, v := range sets {
					env[k] = v
				}
				in.PreviewEnv = &env
			}
			info, err := c.UpdateGit(ctx, p.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Git settings of %s saved.", p.Name)
			return app.Printer().Print(info, func(t *output.TableWriter) { gitStatusDetail(t, p, info) })
		},
	}
	f := cmd.Flags()
	f.StringVar(&branch, "branch", "", "deploy branch: a push to it deploys")
	f.StringVar(&rootDir, "root-dir", "", "directory of the repository that becomes the version (monorepos; \"\" for the root)")
	f.BoolVar(&autoDeploy, "auto-deploy", true, "deploy a pushed version once it is built (false: it waits for a manual deploy)")
	f.BoolVar(&forcePush, "force-push", false, "accept force pushes to the deploy branch")
	f.BoolVar(&previews, "previews", true, "preview environments of pull and merge requests (needs previews.manage)")
	f.IntVar(&limit, "preview-limit", 3, "previews live at the same time")
	f.IntVar(&ttl, "preview-ttl", 72, "hours a preview lives without a new commit")
	f.StringArrayVar(&envSet, "preview-env", nil, "environment override of previews KEY=VALUE (repeatable, non-secret)")
	f.StringArrayVar(&envUnset, "unset-preview-env", nil, "remove an environment override of previews (repeatable)")
	f.BoolVar(&envClear, "clear-preview-env", false, "remove every environment override of previews")
	f.BoolVar(&forks, "fork-previews", false, "previews of requests from forks")
	f.BoolVar(&forkSecrets, "fork-secrets", false, "give fork previews the project's secret variables")
	return cmd
}

// gitConnectFlags are the credential flags of repos and connect.
type gitConnectFlags struct {
	provider, installation string
	tokenStdin             bool
}

func (g *gitConnectFlags) register(cmd *cobra.Command) {
	cmd.Flags().StringVar(&g.provider, "provider", "", "provider: its id, its name, or github / gitlab / forgejo when only one of that type exists")
	cmd.Flags().StringVar(&g.installation, "installation", "", "GitHub: the App installation id (default: the only linked installation)")
	cmd.Flags().BoolVar(&g.tokenStdin, "token-stdin", false, "GitLab, Forgejo/Gitea: read the access token from stdin")
}

// errGitAppNotInstalled means the organization has no installation of the GitHub App yet.
var errGitAppNotInstalled = errors.New("no installation of the GitHub App is linked to your organization yet")

// credentials resolves the provider and the credentials of a repos / connect request.
func (g *gitConnectFlags) credentials(ctx context.Context, app *App, c *runr.Client, p *runr.Project, info *runr.GitInfo) (runr.GitCredentials, *runr.GitProviderRef, error) {
	if g.provider == "" {
		return runr.GitCredentials{}, nil, usageErrorf("--provider is required (github, gitlab, forgejo, or a provider's name or id)")
	}
	pr, err := runr.PickGitProvider(info.Providers, g.provider)
	if err != nil {
		var nf *runr.NotFoundError
		if errors.As(err, &nf) {
			return runr.GitCredentials{}, nil, err
		}
		return runr.GitCredentials{}, nil, usageErrorf("%v", err)
	}
	cred := runr.GitCredentials{ProviderID: pr.ID}
	if pr.Type == runr.GitProviderGitHub {
		if g.tokenStdin {
			return cred, pr, usageErrorf("GitHub connects through the GitHub App: use --installation, not --token-stdin")
		}
		if g.installation != "" {
			cred.InstallationID = g.installation
			return cred, pr, nil
		}
		list, err := c.GitInstallations(ctx, p.ID, pr.ID)
		if err != nil {
			return cred, pr, err
		}
		switch len(list) {
		case 1:
			cred.InstallationID = list[0].InstallationID.String()
			return cred, pr, nil
		case 0:
			u, err := c.GitHubInstallURL(ctx, p.ID, pr.ID)
			if err != nil {
				return cred, pr, err
			}
			app.Progress("Install the GitHub App %s for the accounts whose repositories RunR deploys:", pr.Name)
			app.Progress("  %s", u)
			if app.OpenURL != nil {
				_ = app.OpenURL(u)
			}
			return cred, pr, fmt.Errorf("%w: finish the installation in the browser, then run the command again", errGitAppNotInstalled)
		}
		accounts := make([]string, 0, len(list))
		for _, i := range list {
			accounts = append(accounts, i.AccountLogin+" ("+i.InstallationID.String()+")")
		}
		return cred, pr, usageErrorf("several GitHub App installations are linked (%s): pick one with --installation", strings.Join(accounts, ", "))
	}
	if g.installation != "" {
		return cred, pr, usageErrorf("--installation applies to GitHub; %s connects with an access token (--token-stdin)", gitProviderLabel(pr.Type))
	}
	var token string
	switch {
	case g.tokenStdin:
		line, err := app.readLine("")
		if err != nil {
			return cred, pr, fmt.Errorf("read the access token from stdin: %w", err)
		}
		token = strings.TrimSpace(line)
	case app.interactive():
		t, err := app.ReadSecret("Access token for " + pr.Name + ": ")
		if err != nil {
			return cred, pr, err
		}
		token = strings.TrimSpace(t)
	default:
		return cred, pr, usageErrorf("%s needs an access token: pipe it with --token-stdin", gitProviderLabel(pr.Type))
	}
	if token == "" {
		return cred, pr, usageErrorf("no access token given")
	}
	cred.AccessToken = token
	return cred, pr, nil
}

func newGitReposCmd(app *App) *cobra.Command {
	var g gitConnectFlags
	var search string
	cmd := &cobra.Command{
		Use:     "repos",
		Aliases: []string{"repositories"},
		Short:   "List the repositories a provider's credentials can deploy from",
		Example: "  runr git repos --provider github --search orders\n" +
			"  echo \"$GITLAB_TOKEN\" | runr git repos --provider gitlab --token-stdin",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			info, err := c.GetGit(ctx, p.ID)
			if err != nil {
				return err
			}
			cred, _, err := g.credentials(ctx, app, c, p, info)
			if err != nil {
				return err
			}
			list, err := c.GitRepositories(ctx, p.ID, cred, search)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.GitRepository{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("REPOSITORY", "DEFAULT BRANCH", "PRIVATE", "URL")
				for _, r := range list {
					t.Row(r.FullName, output.Dash(r.DefaultBranch), yesNo(r.Private), output.Dash(r.URL))
				}
			})
		},
	}
	g.register(cmd)
	cmd.Flags().StringVar(&search, "search", "", "only repositories whose name contains this")
	return cmd
}

func newGitInstallationsCmd(app *App) *cobra.Command {
	var provider string
	cmd := &cobra.Command{
		Use:   "installations",
		Short: "List the GitHub App installations linked to your organization",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			info, err := c.GetGit(ctx, p.ID)
			if err != nil {
				return err
			}
			if provider == "" {
				provider = runr.GitProviderGitHub
			}
			pr, err := runr.PickGitProvider(info.Providers, provider)
			if err != nil {
				return err
			}
			list, err := c.GitInstallations(ctx, p.ID, pr.ID)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.GitInstallation{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("ACCOUNT", "TYPE", "INSTALLATION")
				for _, i := range list {
					t.Row(i.AccountLogin, output.Dash(i.AccountType), i.InstallationID.String())
				}
				if len(list) == 0 {
					t.Note("No installation yet: `runr git connect --provider github --repo OWNER/NAME` opens the installation page.")
				}
			})
		},
	}
	cmd.Flags().StringVar(&provider, "provider", "", "GitHub provider: its id or name (default: the only GitHub provider)")
	return cmd
}

func newGitConnectCmd(app *App) *cobra.Command {
	var g gitConnectFlags
	var repo, branch, rootDir string
	var previews, oauth bool
	cmd := &cobra.Command{
		Use:   "connect",
		Short: "Connect the project to a GitHub, GitLab or Forgejo/Gitea repository",
		Long: "Connects the project to a provider repository: a push to its deploy branch deploys, and pull\n" +
			"or merge requests get previews (--previews). GitHub connects through the organization's\n" +
			"installation of the GitHub App (the installation page opens when there is none yet);\n" +
			"GitLab and Forgejo/Gitea with an access token read from stdin or a prompt (never from an\n" +
			"argument). --oauth opens the GitLab sign-in instead; the connection is then finished on the\n" +
			"project's Git tab. The RunR remote of a connected project is read-only.",
		Example: "  runr git connect --provider github --repo acme/orders --branch main --previews\n" +
			"  echo \"$GITLAB_TOKEN\" | runr git connect --provider gitlab --repo acme/orders --token-stdin\n" +
			"  runr git connect --provider gitlab --oauth",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if !oauth && repo == "" {
				return usageErrorf("--repo OWNER/NAME is required")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			info, err := c.GetGit(ctx, p.ID)
			if err != nil {
				return err
			}
			if info.Connection != nil {
				return &runr.APIError{StatusCode: 409, Method: "POST", Path: "/projects/" + p.ID + "/git/connect",
					Message: p.Name + " is already connected to " + info.Connection.RepoFullName + "; run `runr git disconnect` first"}
			}
			if oauth {
				if g.provider == "" {
					g.provider = runr.GitProviderGitLab
				}
				pr, err := runr.PickGitProvider(info.Providers, g.provider)
				if err != nil {
					return err
				}
				if pr.Type != runr.GitProviderGitLab || !pr.HasMode("oauth") {
					return usageErrorf("--oauth needs a GitLab provider with an OAuth application; %s connects with an access token (--token-stdin)", pr.Name)
				}
				u, err := c.GitLabAuthorizeURL(ctx, p.ID, pr.ID)
				if err != nil {
					return err
				}
				if app.OpenURL != nil {
					_ = app.OpenURL(u)
				}
				app.Progress("Sign in to GitLab in the browser; the project's Git tab then lists the repositories to connect.")
				return app.Printer().Print(map[string]any{"url": u, "provider_id": pr.ID}, func(t *output.TableWriter) {
					t.Note("%s", u)
				})
			}
			cred, pr, err := g.credentials(ctx, app, c, p, info)
			if err != nil {
				return err
			}
			info, err = c.ConnectGit(ctx, p.ID, cred, repo)
			if err != nil {
				return err
			}
			app.Progress("Connected %s to %s on %s.", p.Name, repo, gitProviderLabel(pr.Type))
			in := runr.GitSettingsInput{}
			if cmd.Flags().Changed("branch") {
				in.DeployBranch = &branch
			}
			if cmd.Flags().Changed("root-dir") || cmd.Flags().Changed("path") {
				d := strings.Trim(rootDir, "/ ")
				in.RootDir = &d
			}
			if cmd.Flags().Changed("previews") {
				in.PreviewsEnabled = &previews
			}
			if !in.Empty() {
				if info, err = c.UpdateGit(ctx, p.ID, in); err != nil {
					return err
				}
			}
			return app.Printer().Print(info, func(t *output.TableWriter) { gitStatusDetail(t, p, info) })
		},
	}
	g.register(cmd)
	cmd.Flags().StringVar(&repo, "repo", "", "repository OWNER/NAME (or its id)")
	cmd.Flags().StringVar(&branch, "branch", "", "deploy branch (default: unchanged, main for a new project)")
	cmd.Flags().StringVar(&rootDir, "root-dir", "", "directory of the repository that becomes the version (monorepos)")
	cmd.Flags().StringVar(&rootDir, "path", "", "alias of --root-dir")
	cmd.Flags().BoolVar(&previews, "previews", false, "preview environments of pull and merge requests")
	cmd.Flags().BoolVar(&oauth, "oauth", false, "GitLab: open the sign-in page instead of using an access token")
	_ = cmd.Flags().MarkHidden("path")
	return cmd
}

func newGitDisconnectCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:   "disconnect",
		Short: "Disconnect the provider repository (its webhook and previews go, the versions stay)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			info, err := c.GetGit(ctx, p.ID)
			if err != nil {
				return err
			}
			if info.Connection == nil {
				app.Progress("%s is not connected to a repository.", p.Name)
				return app.Printer().Print(info, func(t *output.TableWriter) { gitStatusDetail(t, p, info) })
			}
			if err := app.confirm("Disconnect "+p.Name+" from "+info.Connection.RepoFullName+" (its previews are deleted)", yes); err != nil {
				return err
			}
			info, err = c.DisconnectGit(ctx, p.ID)
			if err != nil {
				return err
			}
			app.Progress("Disconnected; the RunR remote of %s accepts pushes again.", p.Name)
			return app.Printer().Print(info, func(t *output.TableWriter) { gitStatusDetail(t, p, info) })
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func previewTable(t *output.TableWriter, list []runr.GitPreview) {
	t.Header("PR", "BRANCH", "STATUS", "COMMIT", "AUTHOR", "DEPLOYED", "URL")
	for _, pv := range list {
		pr := "#" + strconv.Itoa(pv.Number)
		if pv.IsFork {
			pr += " (fork)"
		}
		t.Row(pr, output.Dash(pv.Branch), pv.Status, output.Dash(pv.ShortSHA), output.Dash(pv.Author),
			gitAge(pv.LastDeployedAt), output.Dash(pv.URL))
	}
	for _, pv := range list {
		if pv.StatusMessage != "" && pv.Status != runr.PreviewReady {
			t.Note("#%d %s: %s", pv.Number, pv.Status, pv.StatusMessage)
		}
	}
}

func previewDetail(t *output.TableWriter, pv *runr.GitPreview) {
	t.KV("Request", fmt.Sprintf("#%d %s", pv.Number, pv.Title))
	t.KV("Branch", output.Dash(pv.Branch)+" → "+output.Dash(pv.BaseBranch))
	t.KV("Commit", output.Dash(pv.ShortSHA))
	t.KV("Status", pv.Status)
	if pv.StatusMessage != "" {
		t.KV("Message", pv.StatusMessage)
	}
	t.KV("Service", output.Dash(pv.ServiceName))
	t.KV("URL", output.Dash(pv.URL))
	if pv.PRURL != "" {
		t.KV("Request URL", pv.PRURL)
	}
	if pv.ExpiresAt != "" && pv.Status != runr.PreviewDeleted {
		t.KV("Expires", pv.ExpiresAt)
	}
}

// newPreviewsCmd builds `runr git previews` and `runr previews`.
func newPreviewsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "previews",
		Aliases: []string{"preview"},
		Args:    cobra.NoArgs,
		Short:   "Preview environments of pull and merge requests",
		Long: "Every open pull/merge request of a connected repository gets a preview environment with its\n" +
			"own URL when previews are on (runr git set --previews). PREVIEW is the request number (12,\n" +
			"#12, pr-12), the preview's id or its branch.",
	}
	var all bool
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the project's previews",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			list, err := c.ListPreviews(ctx, p.ID, all)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.GitPreview{}
			}
			sort.SliceStable(list, func(i, j int) bool { return list[i].Number > list[j].Number })
			return app.Printer().Print(list, func(t *output.TableWriter) {
				previewTable(t, list)
				if len(list) == 0 {
					t.Note("No previews. Previews need a connected repository and runr git set --previews.")
				}
			})
		},
	}
	ls.Flags().BoolVar(&all, "all", false, "deleted previews too")
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE

	cmd.AddCommand(&cobra.Command{
		Use:   "get PREVIEW",
		Short: "Show a preview",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			pv, err := c.FindPreview(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(pv, func(t *output.TableWriter) { previewDetail(t, pv) })
		},
	})

	var wait bool
	var timeout time.Duration
	redeploy := &cobra.Command{
		Use:   "redeploy PREVIEW",
		Short: "Deploy a ready or failed preview again",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			pv, err := c.FindPreview(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			pv, err = c.RedeployPreview(ctx, p.ID, pv.ID)
			if err != nil {
				return err
			}
			app.Progress("Redeploying preview #%d of %s", pv.Number, p.Name)
			if wait {
				pv, err = c.WaitPreview(ctx, p.ID, pv, app.waitOptions(timeout, fmt.Sprintf("Preview #%d", pv.Number)))
			}
			if pv != nil {
				if perr := app.Printer().Print(pv, func(t *output.TableWriter) { previewDetail(t, pv) }); perr != nil && err == nil {
					err = perr
				}
			}
			return err
		},
	}
	redeploy.Flags().BoolVar(&wait, "wait", false, "wait until the preview is ready (exit code 6 when it failed)")
	redeploy.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long --wait waits")
	cmd.AddCommand(redeploy)

	var yes bool
	rm := &cobra.Command{
		Use:     "rm PREVIEW",
		Aliases: []string{"remove", "delete"},
		Short:   "Delete a preview now (a new commit of the request creates it again)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			pv, err := c.FindPreview(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			if pv.Status == runr.PreviewDeleted {
				app.Progress("Preview #%d is already deleted.", pv.Number)
				return app.Printer().Print(pv, func(t *output.TableWriter) { previewDetail(t, pv) })
			}
			if err := app.confirm(fmt.Sprintf("Delete preview #%d (%s) of %s", pv.Number, output.Dash(pv.URL), p.Name), yes); err != nil {
				return err
			}
			pv, err = c.DeletePreview(ctx, p.ID, pv.ID)
			if err != nil {
				return err
			}
			app.Progress("Deleted preview #%d.", pv.Number)
			return app.Printer().Print(pv, func(t *output.TableWriter) { previewDetail(t, pv) })
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(rm)
	return cmd
}

func newGitProvidersCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "providers",
		Short: "Git providers of the installation: list, add, change, remove (git_providers.manage)",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List git providers: type, address, connect modes, connected projects",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListGitProviders(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.GitProviderInfo{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "TYPE", "URL", "ENABLED", "CONFIGURED", "CONNECTS WITH", "PROJECTS", "ID")
				for _, pr := range list {
					web := pr.WebURL
					if web == "" {
						web = pr.BaseURL
					}
					t.Row(pr.Name, gitProviderLabel(pr.Type), output.Dash(web), yesNo(pr.IsEnabled), yesNo(pr.Configured),
						joinOrDash(pr.ConnectModes), strconv.Itoa(pr.LinksCount), pr.ID)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	addGitProviderAdminCommands(app, cmd) // git_providers.go
	return cmd
}
