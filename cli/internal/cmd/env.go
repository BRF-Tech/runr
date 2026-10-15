package cmd

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"os"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/pkg/runr"
)

func newEnvCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "env",
		Short: "List and change environment variables and secrets",
		Long: "A project has plain variables (part of the project config: a change saves a new version)\n" +
			"and secret variables (--secret: stored write-only, listed by name, never shown again\n" +
			"unless you hold secrets.view_values and pass --reveal). Either kind reaches the running\n" +
			"function with the next deploy: unless --no-deploy is given, a change redeploys the live\n" +
			"version so it takes effect. A name is plain or secret, never both.",
	}
	var showValues, reveal bool
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List variables (secret values only with --reveal)",
		Example: "  runr env ls\n" +
			"  runr env ls --show-values\n" +
			"  runr env ls --reveal -o json      # needs secrets.view_values; every read is audited",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			vars, err := c.EnvDetails(cmd.Context(), p, reveal)
			if err != nil {
				return err
			}
			show := showValues || reveal
			return app.Printer().Print(vars, func(t *output.TableWriter) {
				t.Header("NAME", "VALUE", "SECRET", "UPDATED")
				masked := false
				for _, v := range vars {
					val := v.Value
					switch {
					case v.Secret && !v.Revealed:
						val = "(write-only)"
					case !v.Secret && !show:
						val = maskValue(val)
						masked = masked || v.Value != ""
					}
					updated := output.Dash(v.UpdatedAt)
					if v.UpdatedBy != nil && v.UpdatedBy.Name != "" {
						updated += " by " + v.UpdatedBy.Name
					}
					if !v.Secret {
						updated = "-"
					}
					t.Row(v.Name, val, yesNo(v.Secret), updated)
				}
				if masked {
					t.Note("Values are masked; --show-values prints them.")
				}
			})
		},
	}
	ls.Flags().BoolVar(&showValues, "show-values", false, "print the values of plain variables")
	ls.Flags().BoolVar(&reveal, "reveal", false, "also read secret values back (needs secrets.view_values; each read is audited)")
	cmd.AddCommand(ls)

	var secret, noDeploy bool
	var envFile string
	var timeout time.Duration
	set := &cobra.Command{
		Use:   "set KEY=VALUE...",
		Short: "Set variables (--secret: write-only)",
		Long: "Set plain variables, or with --secret secret variables. A secret value never appears\n" +
			"again; with --secret a single KEY without =VALUE reads the value from a hidden prompt or\n" +
			"from stdin, so it stays out of the shell history. Setting a name of the other kind moves\n" +
			"it (plain to secret or back) in one update.",
		Example: "  runr env set LOG_LEVEL=debug REGION=eu\n" +
			"  runr env set --secret STRIPE_KEY            # prompts, or reads stdin\n" +
			"  printf %s \"$DB_URL\" | runr env set --secret DATABASE_URL\n" +
			"  runr env set --secret --from-file .env.secrets --no-deploy",
		RunE: func(cmd *cobra.Command, args []string) error {
			vars := map[string]string{}
			if envFile != "" {
				fileVars, err := readEnvFile(envFile)
				if err != nil {
					return err
				}
				for k, v := range fileVars {
					vars[k] = v
				}
			}
			if secret && len(args) == 1 && !strings.Contains(args[0], "=") {
				v, err := readSecretValue(app, args[0])
				if err != nil {
					return err
				}
				vars[strings.TrimSpace(args[0])] = v
				args = nil
			}
			for _, a := range args {
				k, v, ok := strings.Cut(a, "=")
				if !ok {
					return usageErrorf("%q is not KEY=VALUE", a)
				}
				vars[strings.TrimSpace(k)] = v
			}
			if len(vars) == 0 {
				return usageErrorf("give at least one KEY=VALUE (or --from-file)")
			}
			for k := range vars {
				if err := runr.ValidateEnvName(k); err != nil {
					return usageErrorf("%v", err)
				}
			}
			return applyEnv(cmd.Context(), app, runr.EnvChange{Set: vars, Secret: secret}, noDeploy, timeout)
		},
	}
	set.Flags().BoolVar(&secret, "secret", false, "store the values as secret variables (write-only)")
	set.Flags().StringVar(&envFile, "from-file", "", "read KEY=VALUE lines from a dotenv file")
	set.Flags().BoolVar(&noDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	set.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	cmd.AddCommand(set)

	var unsetNoDeploy bool
	var unsetTimeout time.Duration
	unset := &cobra.Command{
		Use:     "unset KEY...",
		Aliases: []string{"rm"},
		Short:   "Remove variables (plain or secret)",
		Args:    cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return applyEnv(cmd.Context(), app, runr.EnvChange{Unset: args}, unsetNoDeploy, unsetTimeout)
		},
	}
	unset.Flags().BoolVar(&unsetNoDeploy, "no-deploy", false, "only save; the next deployment picks the change up")
	unset.Flags().DurationVar(&unsetTimeout, "timeout", 10*time.Minute, "how long to wait for the redeploy")
	cmd.AddCommand(unset)
	cmd.RunE = ls.RunE
	return cmd
}

// readSecretValue reads the value of one secret: a hidden prompt on a terminal, else stdin
// (one trailing newline removed).
func readSecretValue(app *App, key string) (string, error) {
	if app.interactive() && app.ReadSecret != nil {
		v, err := app.ReadSecret("Value of " + key + ": ")
		if err != nil {
			return "", err
		}
		if v == "" {
			return "", usageErrorf("empty value for %s", key)
		}
		return v, nil
	}
	data, err := io.ReadAll(app.In)
	if err != nil {
		return "", err
	}
	v := strings.TrimSuffix(strings.TrimSuffix(string(data), "\n"), "\r")
	if v == "" {
		return "", usageErrorf("no value for %s: pass %s=VALUE or pipe the value to stdin", key, key)
	}
	return v, nil
}

func maskValue(v string) string {
	if v == "" {
		return ""
	}
	if len(v) <= 4 {
		return "****"
	}
	return v[:2] + strings.Repeat("*", 6)
}

func applyEnv(ctx context.Context, app *App, change runr.EnvChange, noDeploy bool, timeout time.Duration) error {
	c, p, err := app.Project(ctx)
	if err != nil {
		return err
	}
	keys := make([]string, 0, len(change.Set)+len(change.Unset))
	for k := range change.Set {
		keys = append(keys, k)
	}
	keys = append(keys, change.Unset...)
	sort.Strings(keys)
	verb := "set"
	if len(change.Unset) > 0 {
		verb = "unset"
	}
	res, err := c.ApplyEnv(ctx, p, change, "runr env "+verb+": "+strings.Join(keys, ", "))
	if err != nil {
		return err
	}
	if res.SecretStoredPlain {
		app.Warn("this RunR API keeps all variables in the project config; %s readable through the API", strings.Join(keys, ", ")+" stay")
	}
	if !res.Changed {
		app.Progress("Nothing changed.")
		return app.Printer().Print(map[string]any{"changed": false, "variables": runr.EnvList(res.Project)}, func(*output.TableWriter) {})
	}
	if len(res.SecretsSet) > 0 {
		app.Progress("Secret variables set: %s", strings.Join(res.SecretsSet, ", "))
	}
	if len(res.SecretsRemoved) > 0 {
		app.Progress("Secret variables removed: %s", strings.Join(res.SecretsRemoved, ", "))
	}
	if plain := plainKeys(keys, res); len(plain) > 0 {
		app.Progress("Variables %s: %s", verb, strings.Join(plain, ", "))
	}
	return redeployIfLive(ctx, app, c, res.Project, noDeploy, timeout)
}

// plainKeys are the changed names that are not secret changes.
func plainKeys(keys []string, res *runr.EnvResult) []string {
	secret := map[string]bool{}
	for _, k := range res.SecretsSet {
		secret[k] = true
	}
	for _, k := range res.SecretsRemoved {
		secret[k] = true
	}
	var out []string
	for _, k := range keys {
		if !secret[k] {
			out = append(out, k)
		}
	}
	return out
}

// readEnvFile parses KEY=VALUE lines (# comments, optional export, quoted values).
func readEnvFile(path string) (map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	out := map[string]string{}
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 64*1024), runr.MaxSecretBytes*2)
	n := 0
	for sc.Scan() {
		n++
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			return nil, fmt.Errorf("%s:%d: not KEY=VALUE", path, n)
		}
		v = strings.TrimSpace(v)
		if len(v) >= 2 && (v[0] == '"' && v[len(v)-1] == '"' || v[0] == '\'' && v[len(v)-1] == '\'') {
			v = v[1 : len(v)-1]
		}
		out[strings.TrimSpace(k)] = v
	}
	return out, sc.Err()
}
