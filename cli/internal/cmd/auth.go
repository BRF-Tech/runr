package cmd

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/config"
	"github.com/runrsh/core/cli/pkg/runr"
)

func newLoginCmd(app *App) *cobra.Command {
	var (
		apiURL        string
		name          string
		tokenStdin    bool
		withPassword  bool
		username      string
		passwordStdin bool
		otp           string
		tokenName     string
	)
	cmd := &cobra.Command{
		Use:   "login [api-url]",
		Short: "Log in to a RunR API and save it as a context",
		Long: "Log in to a RunR API and save the API URL and token as a context in the config file\n" +
			"(0600 permissions). The token is an API token from the console (Account → API tokens):\n" +
			"paste it at the prompt or pipe it with --token-stdin. With --with-password the CLI logs in\n" +
			"with your username and password and creates a dedicated API token for this machine.\n\n" +
			"In CI, skip login and set RUNR_API_URL and RUNR_TOKEN instead.",
		Example: "  runr login https://runr.example.com\n" +
			"  echo \"$RUNR_TOKEN\" | runr login https://runr.example.com --token-stdin\n" +
			"  runr login https://runr.example.com --with-password --username ada",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			if len(args) == 1 {
				apiURL = args[0]
			}
			if apiURL == "" {
				apiURL = os.Getenv(config.EnvAPIURL)
			}
			if apiURL == "" {
				if !app.interactive() {
					return usageErrorf("give the API URL: runr login https://runr.example.com")
				}
				var err error
				if apiURL, err = app.readLine("RunR URL: "); err != nil {
					return err
				}
			}
			base, err := runr.NormalizeAPIURL(apiURL)
			if err != nil {
				return usageErrorf("%v", err)
			}
			if name == "" {
				name = contextNameFor(base)
			}
			anon, err := app.newClient(base.String(), "")
			if err != nil {
				return err
			}

			stored := &config.Context{APIURL: strings.TrimSuffix(anon.ConsoleURL(), "/")}
			var token string
			if withPassword {
				token, stored.TokenID, err = loginWithPassword(cmd, app, anon, username, passwordStdin, otp, tokenName)
				if err != nil {
					return err
				}
			} else {
				token, err = readToken(app, tokenStdin)
				if err != nil {
					return err
				}
			}
			c := anon.WithToken(token)
			user, err := c.Me(ctx)
			if err != nil {
				if runr.IsUnauthorized(err) {
					return fmt.Errorf("the API rejected the token: %w", err)
				}
				return err
			}
			stored.Token = token
			stored.User = user.Username
			cfg, err := app.Config()
			if err != nil {
				return err
			}
			cfg.Set(name, stored)
			if err := cfg.Save(); err != nil {
				return err
			}
			perms, _ := c.Permissions(ctx)
			out := map[string]any{"context": name, "api_url": stored.APIURL, "user": user}
			if perms != nil {
				out["permissions"] = perms
			}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.Note("Logged in to %s as %s (context %q, saved in %s).", stored.APIURL, user.Username, name, cfg.FilePath())
				if perms != nil {
					t.Note("%s", scopeSummary(perms))
				}
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&name, "name", "", "context name (default: the API host)")
	f.BoolVar(&tokenStdin, "token-stdin", false, "read the API token from stdin")
	f.BoolVar(&withPassword, "with-password", false, "log in with username and password and create an API token")
	f.StringVar(&username, "username", "", "username or e-mail for --with-password")
	f.BoolVar(&passwordStdin, "password-stdin", false, "read the password from stdin (--with-password)")
	f.StringVar(&otp, "otp", "", "two-factor code (--with-password)")
	f.StringVar(&tokenName, "token-name", "", "name of the API token --with-password creates (default: runr-cli@<host>)")
	return cmd
}

// scopeSummary describes what a token may do in one line.
func scopeSummary(p *runr.Permissions) string {
	if !p.TokenScoped {
		return "Token scopes: all permissions of the user (" + strconv.Itoa(len(p.Permissions)) + ")."
	}
	return "Token scopes: " + output.Dash(strings.Join(p.Permissions, ", ")) + "."
}

func contextNameFor(u *url.URL) string {
	host := u.Hostname()
	if host == "" {
		return "default"
	}
	return host
}

func readToken(app *App, fromStdin bool) (string, error) {
	if fromStdin {
		line, err := app.readLine("")
		if err != nil {
			return "", fmt.Errorf("read the token from stdin: %w", err)
		}
		if t := strings.TrimSpace(line); t != "" {
			return t, nil
		}
		return "", usageErrorf("stdin held no token")
	}
	if t := os.Getenv(config.EnvToken); t != "" {
		return t, nil
	}
	if !app.interactive() {
		return "", usageErrorf("no token: pipe it with --token-stdin or set RUNR_TOKEN")
	}
	t, err := app.ReadSecret("API token: ")
	if err != nil {
		return "", err
	}
	t = strings.TrimSpace(t)
	if t == "" {
		return "", usageErrorf("no token entered")
	}
	return t, nil
}

func loginWithPassword(cmd *cobra.Command, app *App, anon *runr.Client, username string, passwordStdin bool, otp, tokenName string) (string, string, error) {
	ctx := cmd.Context()
	var err error
	if username == "" {
		if !app.interactive() {
			return "", "", usageErrorf("pass --username")
		}
		if username, err = app.readLine("Username or e-mail: "); err != nil {
			return "", "", err
		}
	}
	var password string
	switch {
	case passwordStdin:
		if password, err = app.readLine(""); err != nil {
			return "", "", err
		}
	case app.interactive():
		if password, err = app.ReadSecret("Password: "); err != nil {
			return "", "", err
		}
	default:
		return "", "", usageErrorf("pass --password-stdin in a non-interactive session")
	}
	session, _, err := anon.Login(ctx, username, password, otp)
	if errors.Is(err, runr.ErrTwoFactorRequired) {
		if !app.interactive() {
			return "", "", usageErrorf("the account uses two-factor authentication: pass --otp")
		}
		if otp, err = app.readLine("Two-factor code: "); err != nil {
			return "", "", err
		}
		session, _, err = anon.Login(ctx, username, password, strings.TrimSpace(otp))
	}
	if err != nil {
		return "", "", err
	}
	sc := anon.WithToken(session)
	if tokenName == "" {
		host, _ := os.Hostname()
		if host == "" {
			host = "cli"
		}
		tokenName = "runr-cli@" + host
	}
	plain, tok, err := sc.CreateToken(ctx, runr.CreateTokenInput{Name: tokenName})
	if err != nil {
		return "", "", fmt.Errorf("create an API token: %w", err)
	}
	// The session token is not needed any more.
	if err := sc.Logout(ctx); err != nil {
		app.Warn("could not end the login session: %v", err)
	}
	return plain, tok.ID.String(), nil
}

func newLogoutCmd(app *App) *cobra.Command {
	var keep bool
	cmd := &cobra.Command{
		Use:   "logout",
		Short: "Remove the current context (and revoke the token runr login created)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := app.Config()
			if err != nil {
				return err
			}
			name := app.ContextName
			if name == "" {
				name = os.Getenv(config.EnvContext)
			}
			if name == "" {
				name = cfg.CurrentContext
			}
			c, ok := cfg.Contexts[name]
			if !ok {
				return usageErrorf("no context to log out of")
			}
			if c.TokenID != "" && !keep {
				client, err := app.newClient(c.APIURL, c.Token)
				if err == nil {
					if err := client.DeleteToken(cmd.Context(), c.TokenID); err != nil && !runr.IsNotFound(err) {
						app.Warn("could not revoke the API token: %v", err)
					}
				}
			}
			if err := cfg.Remove(name); err != nil {
				return err
			}
			if err := cfg.Save(); err != nil {
				return err
			}
			app.Progress("Logged out of %s (context %q removed).", c.APIURL, name)
			return nil
		},
	}
	cmd.Flags().BoolVar(&keep, "keep-token", false, "do not revoke the API token created by runr login --with-password")
	return cmd
}

func newWhoamiCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "whoami",
		Short: "Show the user and API of the current context",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			u, err := c.Me(cmd.Context())
			if err != nil {
				return err
			}
			cfg, _ := app.Config()
			res, _ := cfg.Resolve(app.ContextName)
			ctxName := ""
			if res != nil {
				ctxName = res.Name
				if res.FromEnv {
					ctxName = "(environment)"
				}
			}
			perms, err := c.Permissions(cmd.Context())
			if err != nil && !errors.Is(err, runr.ErrUnsupported) {
				app.Warn("could not read the permissions: %v", err)
			}
			out := map[string]any{"user": u, "api_url": c.ConsoleURL(), "context": ctxName}
			if perms != nil {
				out["permissions"] = perms
			}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.KV("User", u.Username)
				t.KV("Name", output.Dash(u.Name))
				t.KV("E-mail", output.Dash(u.Email))
				t.KV("API", c.ConsoleURL())
				t.KV("Context", output.Dash(ctxName))
				if perms != nil {
					role := perms.Role
					if perms.PlatformAdmin {
						role = strings.TrimSpace(role + " (platform administrator)")
					}
					t.KV("Role", output.Dash(role))
					if perms.TokenScoped {
						t.KV("Token", "scoped")
					} else {
						t.KV("Token", "all permissions of the user")
					}
					t.KV("Permissions", output.Dash(strings.Join(perms.Permissions, ", ")))
					if n := len(perms.Projects); n > 0 {
						t.KV("Project access", fmt.Sprintf("%d project(s) with extra permissions", n))
					}
				}
			})
		},
	}
}

func newContextCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "context",
		Aliases: []string{"contexts", "ctx"},
		Short:   "List and switch between saved RunR APIs",
	}
	cmd.AddCommand(&cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List contexts",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := app.Config()
			if err != nil {
				return err
			}
			type row struct {
				Name    string `json:"name"`
				APIURL  string `json:"api_url"`
				User    string `json:"user,omitempty"`
				Current bool   `json:"current"`
			}
			var rows []row
			for _, n := range cfg.Names() {
				c := cfg.Contexts[n]
				rows = append(rows, row{Name: n, APIURL: c.APIURL, User: c.User, Current: n == cfg.CurrentContext})
			}
			if rows == nil {
				rows = []row{}
			}
			return app.Printer().Print(rows, func(t *output.TableWriter) {
				t.Header("CURRENT", "NAME", "API", "USER")
				for _, r := range rows {
					cur := ""
					if r.Current {
						cur = "*"
					}
					t.Row(cur, r.Name, r.APIURL, output.Dash(r.User))
				}
				if len(rows) == 0 {
					t.Note("No contexts: run `runr login`.")
				}
			})
		},
	})
	cmd.AddCommand(&cobra.Command{
		Use:   "use NAME",
		Short: "Make a context the current one",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := app.Config()
			if err != nil {
				return err
			}
			if err := cfg.Use(args[0]); err != nil {
				return &runr.NotFoundError{Kind: "context", Ref: args[0]}
			}
			if err := cfg.Save(); err != nil {
				return err
			}
			app.Progress("Switched to context %q (%s).", args[0], cfg.Contexts[args[0]].APIURL)
			return nil
		},
	})
	cmd.AddCommand(&cobra.Command{
		Use:     "rm NAME",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a context",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := app.Config()
			if err != nil {
				return err
			}
			if _, ok := cfg.Contexts[args[0]]; !ok {
				return &runr.NotFoundError{Kind: "context", Ref: args[0]}
			}
			_ = cfg.Remove(args[0])
			if err := cfg.Save(); err != nil {
				return err
			}
			app.Progress("Removed context %q.", args[0])
			return nil
		},
	})
	cmd.AddCommand(&cobra.Command{
		Use:   "current",
		Short: "Print the current context",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			cfg, err := app.Config()
			if err != nil {
				return err
			}
			res, err := cfg.Resolve(app.ContextName)
			if err != nil {
				return err
			}
			if res.Name == "" && !res.FromEnv {
				return usageErrorf("no current context: run `runr login`")
			}
			out := map[string]any{"name": res.Name, "api_url": res.APIURL, "from_env": res.FromEnv}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				name := res.Name
				if res.FromEnv {
					name += " (RUNR_API_URL / RUNR_TOKEN override)"
				}
				t.Note("%s", strings.TrimSpace(name+" "+res.APIURL))
			})
		},
	})
	return cmd
}

func newTokensCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "tokens",
		Aliases: []string{"token"},
		Short:   "Manage API tokens (for CI, the GitHub Action, Terraform)",
	}
	cmd.AddCommand(&cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List your API tokens (the one in use is not listed)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, abilities, err := c.ListTokens(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.Token{}
			}
			return app.Printer().Print(map[string]any{"tokens": list, "available_abilities": abilities}, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "ABILITIES", "LAST USED", "EXPIRES")
				for _, tk := range list {
					t.Row(tk.ID.String(), tk.Name, strings.Join(tk.Abilities, ","), output.Dash(tk.LastUsedAt), output.Dash(tk.ExpiresAt))
				}
			})
		},
	})
	var abilities []string
	var expires, preset string
	create := &cobra.Command{
		Use:   "create NAME",
		Short: "Create an API token; its value is printed once",
		Long: "Create an API token. Without --ability or --preset it gets every permission you hold.\n" +
			"Scopes are permission names (projects.deploy, code.edit, env.edit, logs.view, …);\n" +
			"a token never exceeds its owner's permissions. Presets:\n" +
			"  deploy         " + strings.Join(runr.ScopesDeploy, ", ") + "\n" +
			"  deploy-create  deploy + projects.create (the first deploy creates the project)\n" +
			"  terraform      " + strings.Join(runr.ScopesTerraform, ", "),
		Example: "  runr tokens create github-actions --preset deploy --expires 90d\n" +
			"  runr tokens create logs-reader --ability logs.view",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			scopes := append([]string{}, abilities...)
			switch preset {
			case "":
			case "deploy":
				scopes = append(scopes, runr.ScopesDeploy...)
			case "deploy-create":
				scopes = append(scopes, runr.ScopesDeployCreate...)
			case "terraform":
				scopes = append(scopes, runr.ScopesTerraform...)
			default:
				return usageErrorf("--preset must be deploy, deploy-create or terraform")
			}
			in := runr.CreateTokenInput{Name: args[0], Abilities: uniqSorted(scopes)}
			if expires != "" {
				d, err := parseDays(expires)
				if err != nil {
					return usageErrorf("--expires: %v", err)
				}
				t := time.Now().Add(d).UTC().Truncate(time.Second)
				in.ExpiresAt = &t
			}
			plain, tok, err := c.CreateToken(cmd.Context(), in)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"token": plain, "record": tok}, func(t *output.TableWriter) {
				t.Note("%s", plain)
				t.Note("")
				t.Note("Token %q created (id %s). Store it now: it is not shown again.", tok.Name, tok.ID)
			})
		},
	}
	create.Flags().StringSliceVar(&abilities, "ability", nil, "permission the token gets (repeatable; default: all of yours)")
	create.Flags().StringVar(&preset, "preset", "", "scope set: deploy, deploy-create or terraform")
	create.Flags().StringVar(&expires, "expires", "", "lifetime, e.g. 30d or 720h (default: no expiry)")
	cmd.AddCommand(create)
	cmd.AddCommand(&cobra.Command{
		Use:     "rm ID",
		Aliases: []string{"revoke", "delete"},
		Short:   "Revoke an API token",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.DeleteToken(cmd.Context(), args[0]); err != nil {
				return err
			}
			app.Progress("Token %s revoked.", args[0])
			return nil
		},
	})
	return cmd
}

func uniqSorted(s []string) []string {
	if len(s) == 0 {
		return nil
	}
	set := map[string]bool{}
	var out []string
	for _, v := range s {
		if v = strings.TrimSpace(v); v != "" && !set[v] {
			set[v] = true
			out = append(out, v)
		}
	}
	sort.Strings(out)
	return out
}

// parseDays accepts Go durations and a "d" suffix for days.
func parseDays(s string) (time.Duration, error) {
	s = strings.TrimSpace(s)
	if strings.HasSuffix(s, "d") {
		var n int
		if _, err := fmt.Sscanf(strings.TrimSuffix(s, "d"), "%d", &n); err != nil || n <= 0 {
			return 0, fmt.Errorf("invalid duration %q", s)
		}
		return time.Duration(n) * 24 * time.Hour, nil
	}
	d, err := time.ParseDuration(s)
	if err != nil || d <= 0 {
		return 0, fmt.Errorf("invalid duration %q", s)
	}
	return d, nil
}
