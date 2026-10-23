package cmd

import (
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerCommand(newPrivateConnectionsCmd) }

// Private connections of a project to a managed PostgreSQL database through Skupper
// (docs/environments.md#private-database-connections): `runr private-connections` lists, adds,
// checks and removes them — of the project's own workload, or with --env of one environment.

func newPrivateConnectionsCmd(app *App) *cobra.Command {
	var envName string
	cmd := &cobra.Command{
		Use:     "private-connections",
		Aliases: []string{"private-connection", "pconn"},
		Short:   "Private connections to a managed PostgreSQL database (Skupper) of a project or an environment",
		Long: "A private connection links the region of a workload to a managed PostgreSQL database through\n" +
			"Skupper: the workload reaches the database at a local host and gets <prefix>DATABASE_URL,\n" +
			"<prefix>PGHOST, … from a Secret in its region; the credentials never leave the region.\n" +
			"Without --env the connections of the project's own workload; with --env those of one of its\n" +
			"environments (runr environments), which live in the region that environment runs in and\n" +
			"reach only its pods.",
		Example: "  runr private-connections ls\n" +
			"  runr private-connections add orders --routing-key pg-orders --link @link.yaml \\\n" +
			"      --database orders --username app --password-stdin < password.txt\n" +
			"  runr private-connections add orders --env staging --routing-key pg-orders-staging --link @staging-link.yaml ...\n" +
			"  runr private-connections status orders\n" +
			"  runr private-connections rm orders --env staging --yes",
	}
	cmd.PersistentFlags().StringVar(&envName, "env", "", envFlagUsage)

	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List the private connections and the variables they give",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			list, err := c.ListPrivateConnections(ctx, p.ID, env)
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.PrivateConnection{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "ENVIRONMENT", "HOST", "PORT", "ROUTING KEY", "SSLMODE", "VARIABLES")
				for i := range list {
					pc := &list[i]
					t.Row(pc.Name, pc.EnvironmentName(), pc.Host, strconv.Itoa(pc.Port), pc.RoutingKey, pc.SSLMode, joinOrDash(pc.EnvNames))
				}
				if len(list) == 0 {
					t.Note("No private connections on %s.", projectIn(p, env))
				}
			})
		},
	}

	var prefix, routingKey, link, database, username, password, sslmode string
	var port int
	var passwordStdin bool
	add := &cobra.Command{
		Use:   "add NAME",
		Short: "Connect the workload to a managed PostgreSQL database through Skupper",
		Long: "NAME is a DNS label. --link is the Skupper Link or AccessToken (YAML) the database's site\n" +
			"issued: inline, @file or - (stdin). --routing-key is the key the database's Connector\n" +
			"listens on. The password comes from --password-stdin (or a hidden prompt), --password\n" +
			"for scripts. The region of the workload must run Skupper (runr regions skupper).",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if routingKey == "" || link == "" || database == "" || username == "" {
				return usageErrorf("--routing-key, --link, --database and --username are required")
			}
			if passwordStdin && password != "" {
				return usageErrorf("--password and --password-stdin exclude each other")
			}
			linkData, err := readArg(app, link)
			if err != nil {
				return err
			}
			if password == "" {
				if !passwordStdin && !app.interactive() {
					return usageErrorf("pass the password with --password-stdin (or --password)")
				}
				if password, err = readSecretValue(app, "the database password"); err != nil {
					return err
				}
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			in := runr.PrivateConnectionInput{
				Environment: env, Name: strings.TrimSpace(args[0]), EnvPrefix: prefix, RoutingKey: routingKey, Port: port,
				SSLMode: sslmode, LinkToken: string(linkData), Database: database, Username: username, Password: password,
			}
			pc, synced, err := c.CreatePrivateConnection(ctx, p.ID, in)
			if err != nil {
				return err
			}
			if synced {
				app.Progress("Connected %s to %s; the running workload rolls onto its variables.", projectIn(p, env), pc.Name)
			} else {
				app.Progress("Connected %s to %s; the next deploy gives the workload its variables.", projectIn(p, env), pc.Name)
			}
			return app.Printer().Print(pc, func(t *output.TableWriter) { privateConnectionDetail(t, pc) })
		},
	}
	af := add.Flags()
	af.StringVar(&prefix, "env-prefix", "", "prefix of the variables, e.g. ORDERS_ (upper case, ends with _)")
	af.StringVar(&routingKey, "routing-key", "", "the routing key of the database's Skupper Connector")
	af.StringVar(&link, "link", "", "the Skupper Link or AccessToken YAML: inline, @file or -")
	af.StringVar(&database, "database", "", "database name")
	af.StringVar(&username, "username", "", "database user")
	af.StringVar(&password, "password", "", "database password (prefer --password-stdin)")
	af.BoolVar(&passwordStdin, "password-stdin", false, "read the password from stdin")
	af.StringVar(&sslmode, "sslmode", "", "PGSSLMODE: disable, allow, prefer, require (default), verify-ca or verify-full")
	af.IntVar(&port, "port", 0, "the port the workload connects to (default 5432)")

	status := &cobra.Command{
		Use:   "status NAME",
		Short: "What the region reports about a connection's Skupper Link and Listener",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			found, err := c.FindPrivateConnection(ctx, p.ID, env, args[0])
			if err != nil {
				return err
			}
			pc, st, err := c.GetPrivateConnectionStatus(ctx, p.ID, found.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"connection": pc, "status": st}, func(t *output.TableWriter) {
				privateConnectionDetail(t, pc)
				t.KV("State", st.State)
				t.KV("Link", skupperStatus(st.Link))
				t.KV("Listener", skupperStatus(st.Listener))
				if st.AccessToken != nil {
					t.KV("Access token", skupperStatus(st.AccessToken))
				}
			})
		},
	}

	var yes bool
	rm := &cobra.Command{
		Use:     "rm NAME",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a connection from its region; its variables leave the workload",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			pc, err := c.FindPrivateConnection(ctx, p.ID, env, args[0])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove the private connection "+pc.Name+" of "+projectIn(p, env), yes); err != nil {
				return err
			}
			if err := c.DeletePrivateConnection(ctx, p.ID, pc.ID); err != nil {
				return err
			}
			app.Progress("Removed %s from %s.", pc.Name, projectIn(p, env))
			return app.Printer().Print(map[string]any{"removed": true, "name": pc.Name, "environment": pc.EnvironmentName()}, func(*output.TableWriter) {})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")

	cmd.AddCommand(ls, add, status, rm)
	cmd.RunE = ls.RunE
	return cmd
}

func privateConnectionDetail(t *output.TableWriter, pc *runr.PrivateConnection) {
	t.KV("Name", pc.Name)
	t.KV("Environment", pc.EnvironmentName())
	t.KV("Host", pc.Host+":"+strconv.Itoa(pc.Port))
	t.KV("Routing key", pc.RoutingKey)
	t.KV("SSL mode", pc.SSLMode)
	t.KV("Secret", pc.SecretName)
	t.KV("Variables", joinOrDash(pc.EnvNames))
}

// skupperStatus is "Ready", "Pending: <message>".
func skupperStatus(s map[string]any) string {
	status, _ := s["status"].(string)
	if msg, _ := s["message"].(string); msg != "" {
		return status + ": " + msg
	}
	return output.Dash(status)
}
