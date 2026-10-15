package cmd

import (
	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// `runr shell`: a shell in a pod of the project's live deployment, through RunR's web shell.

func init() { registerCommand(newShellCmd) }

func newShellCmd(app *App) *cobra.Command {
	var deploymentID string
	cmd := &cobra.Command{
		Use:   "shell",
		Short: "Open a shell in a pod of the project's live deployment",
		Long: "Open an interactive shell in a pod of the project's live deployment (or of --deployment\n" +
			"ID, which must be active) through RunR's web shell, the Shell tab of the console. A\n" +
			"service scaled to zero is woken and kept running while the session is open. On a\n" +
			"terminal, keys go to the shell as typed and the size follows the window; leave with exit\n" +
			"or Ctrl+D. Piped stdin is sent as typed input and ends with Ctrl+D. With -o json or yaml\n" +
			"the one-time session (valid for 120 seconds) is printed instead of attaching.",
		Example: "  runr shell\n" +
			"  runr shell -p orders\n" +
			"  printf 'env | sort\\nexit\\n' | runr shell",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			id := deploymentID
			if id == "" {
				d, err := c.CurrentDeployment(ctx, p.ID)
				if err != nil {
					return err
				}
				if d == nil || !d.Live() {
					return &runr.NotFoundError{Kind: "live deployment of project", Ref: p.Name}
				}
				id = d.ID
			}
			return attachShell(ctx, app, c, func(cols, rows int) (*runr.SandboxTerminalSession, error) {
				return c.OpenDeploymentShell(ctx, p.ID, id, cols, rows)
			})
		},
	}
	cmd.Flags().StringVar(&deploymentID, "deployment", "", "an active deployment instead of the live one")
	return cmd
}
