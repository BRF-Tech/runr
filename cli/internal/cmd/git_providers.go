package cmd

import (
	"sort"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

// Git providers administration (git_providers.manage): runr git providers get|create|update|rm.
// Secrets (a GitHub App's private key, webhook secret and client secret, a GitLab OAuth
// application's secret) come from files or stdin only and are never printed.

func addGitProviderAdminCommands(app *App, providers *cobra.Command) {
	providers.AddCommand(newGitProviderGetCmd(app), newGitProviderCreateCmd(app), newGitProviderUpdateCmd(app),
		newGitProviderRmCmd(app))
}

func gitpDetail(t *output.TableWriter, p *runr.GitProviderInfo) {
	t.KV("Name", p.Name)
	t.KV("Type", gitProviderLabel(p.Type))
	t.KV("ID", p.ID)
	web := p.WebURL
	if web == "" {
		web = p.BaseURL
	}
	t.KV("URL", output.Dash(web))
	if p.APIURL != "" {
		t.KV("API URL", p.APIURL)
	}
	if p.AppID != "" || p.AppSlug != "" {
		t.KV("GitHub App", strings.TrimSpace(p.AppSlug+" (id "+p.AppID.String()+")"))
	}
	if p.ClientID != "" {
		t.KV("Client id", p.ClientID)
	}
	t.KV("Enabled", yesNo(p.IsEnabled))
	t.KV("Configured", yesNo(p.Configured))
	t.KV("Connects with", joinOrDash(p.ConnectModes))
	var secrets []string
	if p.HasPrivateKey {
		secrets = append(secrets, "private key")
	}
	if p.HasWebhookSecret {
		secrets = append(secrets, "webhook secret")
	}
	if p.HasClientSecret {
		secrets = append(secrets, "client secret")
	}
	t.KV("Stored secrets", joinOrDash(secrets))
	t.KV("Projects", strconv.Itoa(p.LinksCount))
	if len(p.URLs) > 0 {
		keys := make([]string, 0, len(p.URLs))
		for k := range p.URLs {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		t.Note("")
		t.Note("Enter at the provider:")
		for _, k := range keys {
			t.Note("  %-9s %s", k+" URL", p.URLs[k])
		}
	}
}

func newGitProviderGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get PROVIDER",
		Short: "Show a git provider and the URLs to enter at the provider",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			p, err := c.FindGitProvider(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(p, func(t *output.TableWriter) { gitpDetail(t, p) })
		},
	}
}

// gitpFlags are the settings of create and update.
type gitpFlags struct {
	name, baseURL, apiURL, clientID, appID, appSlug string
	clientSecretFile, privateKeyFile, webhookFile   string
	enable, disable                                 bool
}

func (f *gitpFlags) register(cmd *cobra.Command) {
	fs := cmd.Flags()
	fs.StringVar(&f.name, "name", "", "display name")
	fs.StringVar(&f.baseURL, "base-url", "", "address of a self-hosted GitLab or a Forgejo/Gitea instance")
	fs.StringVar(&f.apiURL, "api-url", "", "API address when it differs (GitHub Enterprise, a GitLab behind a proxy)")
	fs.StringVar(&f.clientID, "client-id", "", "OAuth client id (GitHub App, GitLab OAuth application)")
	fs.StringVar(&f.appID, "app-id", "", "GitHub: the App's numeric id")
	fs.StringVar(&f.appSlug, "app-slug", "", "GitHub: the App's slug (github.com/apps/<slug>)")
	fs.StringVar(&f.clientSecretFile, "client-secret-file", "", "file (or - for stdin) with the OAuth client secret")
	fs.StringVar(&f.privateKeyFile, "private-key-file", "", "GitHub: file (or - for stdin) with the App's PEM private key")
	fs.StringVar(&f.webhookFile, "webhook-secret-file", "", "GitHub: file (or - for stdin) with the App's webhook secret (16+ characters)")
	fs.BoolVar(&f.enable, "enable", false, "allow projects to connect through the provider")
	fs.BoolVar(&f.disable, "disable", false, "stop new connections through the provider")
}

// input builds the request from the flags that were given; secrets are read from their files.
func (f *gitpFlags) input(cmd *cobra.Command, app *App) (runr.GitProviderInput, error) {
	fl := cmd.Flags()
	var in runr.GitProviderInput
	if f.enable && f.disable {
		return in, usageErrorf("--enable and --disable exclude each other")
	}
	stdin := 0
	for _, v := range []string{f.clientSecretFile, f.privateKeyFile, f.webhookFile} {
		if v == "-" {
			stdin++
		}
	}
	if stdin > 1 {
		return in, usageErrorf("only one secret can come from stdin (-)")
	}
	str := func(flag, v string) *string {
		if !fl.Changed(flag) {
			return nil
		}
		s := strings.TrimSpace(v)
		return &s
	}
	in.Name = str("name", f.name)
	in.BaseURL = str("base-url", f.baseURL)
	in.APIURL = str("api-url", f.apiURL)
	in.ClientID = str("client-id", f.clientID)
	in.AppID = str("app-id", f.appID)
	in.AppSlug = str("app-slug", f.appSlug)
	if f.enable || f.disable {
		on := f.enable
		in.IsEnabled = &on
	}
	secret := func(flag, path string) (*string, error) {
		if path == "" {
			return nil, nil
		}
		data, err := readFileArg(app, path)
		if err != nil {
			return nil, err
		}
		s := strings.TrimRight(string(data), "\r\n")
		if strings.TrimSpace(s) == "" {
			return nil, usageErrorf("--%s is empty", flag)
		}
		return &s, nil
	}
	var err error
	if in.ClientSecret, err = secret("client-secret-file", f.clientSecretFile); err != nil {
		return in, err
	}
	if in.PrivateKey, err = secret("private-key-file", f.privateKeyFile); err != nil {
		return in, err
	}
	if in.PrivateKey != nil {
		pk := strings.TrimSpace(*in.PrivateKey) + "\n"
		in.PrivateKey = &pk
	}
	if in.WebhookSecret, err = secret("webhook-secret-file", f.webhookFile); err != nil {
		return in, err
	}
	if in.WebhookSecret != nil && len(*in.WebhookSecret) < 16 {
		return in, usageErrorf("the webhook secret needs at least 16 characters")
	}
	return in, nil
}

func newGitProviderCreateCmd(app *App) *cobra.Command {
	var typ string
	var f gitpFlags
	cmd := &cobra.Command{
		Use:   "create --type github|gitlab|forgejo --name NAME",
		Short: "Add a GitHub App, a GitLab instance or a Forgejo/Gitea instance",
		Long: "Add a git provider projects can connect through (git_providers.manage):\n" +
			"  github   a GitHub App: --app-id, --app-slug, --client-id, --client-secret-file,\n" +
			"           --private-key-file, --webhook-secret-file (all required)\n" +
			"  gitlab   gitlab.com or --base-url; --client-id and --client-secret-file enable the sign-in\n" +
			"           (OAuth), else projects connect with an access token\n" +
			"  forgejo  a Forgejo or Gitea instance: --base-url (projects connect with an access token)\n" +
			"Secrets are read from files or stdin (-), never from arguments, and RunR never shows them\n" +
			"again. The answer lists the URLs to enter at the provider (webhook, setup, callback).",
		Example: "  runr git providers create --type github --name acme-github --app-id 123456 --app-slug acme-runr \\\n" +
			"      --client-id Iv1.abc --client-secret-file client-secret.txt --private-key-file app.pem \\\n" +
			"      --webhook-secret-file webhook-secret.txt\n" +
			"  runr git providers create --type forgejo --name codeberg --base-url https://codeberg.org",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			t := strings.ToLower(strings.TrimSpace(typ))
			if t == "gitea" {
				t = runr.GitProviderForgejo
			}
			switch t {
			case runr.GitProviderGitHub, runr.GitProviderGitLab, runr.GitProviderForgejo:
			default:
				return usageErrorf("--type must be github, gitlab or forgejo")
			}
			if strings.TrimSpace(f.name) == "" {
				return usageErrorf("give --name")
			}
			in, err := f.input(cmd, app)
			if err != nil {
				return err
			}
			if t == runr.GitProviderGitHub {
				var lacking []string
				for flag, v := range map[string]any{"--app-id": in.AppID, "--app-slug": in.AppSlug, "--client-id": in.ClientID,
					"--client-secret-file": in.ClientSecret, "--private-key-file": in.PrivateKey, "--webhook-secret-file": in.WebhookSecret} {
					if p, _ := v.(*string); p == nil || *p == "" {
						lacking = append(lacking, flag)
					}
				}
				if len(lacking) > 0 {
					sort.Strings(lacking)
					return usageErrorf("a GitHub App needs %s", strings.Join(lacking, ", "))
				}
			}
			if t == runr.GitProviderForgejo && (in.BaseURL == nil || *in.BaseURL == "") {
				return usageErrorf("a Forgejo/Gitea provider needs --base-url")
			}
			in.Type = &t
			c, err := app.Client()
			if err != nil {
				return err
			}
			p, err := c.CreateGitProvider(cmd.Context(), in)
			if err != nil {
				return err
			}
			app.Progress("Added git provider %s.", p.Name)
			return app.Printer().Print(p, func(tw *output.TableWriter) { gitpDetail(tw, p) })
		},
	}
	cmd.Flags().StringVar(&typ, "type", "", "github, gitlab or forgejo (also gitea)")
	f.register(cmd)
	return cmd
}

func newGitProviderUpdateCmd(app *App) *cobra.Command {
	var f gitpFlags
	cmd := &cobra.Command{
		Use:   "update PROVIDER",
		Short: "Change a git provider (flags left out keep their values)",
		Long: "Change a git provider. Only the flags given are sent; a secret file replaces the stored\n" +
			"secret, a secret left out keeps it. The type cannot change.",
		Example: "  runr git providers update acme-github --private-key-file new-app.pem\n" +
			"  runr git providers update codeberg --disable",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in, err := f.input(cmd, app)
			if err != nil {
				return err
			}
			if in == (runr.GitProviderInput{}) {
				return usageErrorf("give at least one setting to change")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			cur, err := c.FindGitProvider(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			p, err := c.UpdateGitProvider(cmd.Context(), cur.ID, in)
			if err != nil {
				return err
			}
			app.Progress("Updated git provider %s.", p.Name)
			return app.Printer().Print(p, func(tw *output.TableWriter) { gitpDetail(tw, p) })
		},
	}
	f.register(cmd)
	return cmd
}

func newGitProviderRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm PROVIDER",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a git provider (refused while projects are connected through it)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			p, err := c.FindGitProvider(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove git provider "+p.Name+" and its linked installations", yes); err != nil {
				return err
			}
			if err := c.DeleteGitProvider(cmd.Context(), p.ID); err != nil {
				return err
			}
			app.Progress("Removed git provider %s.", p.Name)
			return app.Printer().Print(map[string]any{"id": p.ID, "name": p.Name, "deleted": true}, func(*output.TableWriter) {})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}
