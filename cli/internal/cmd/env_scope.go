package cmd

import (
	"context"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/spf13/cobra"
)

// envFlagUsage is the help of the --env flag of the commands that work on one environment of the
// project (docs/environments.md): its bindings, its custom domains.
const envFlagUsage = "an environment of the project (runr environments) instead of its own workload"

// addEnvFlag registers --env on cmd.
func addEnvFlag(cmd *cobra.Command, env *string) {
	cmd.Flags().StringVar(env, "env", "", envFlagUsage)
}

// environmentScope resolves the --env of such a command: "" without the flag (the API's default),
// else the environment's name — "production" for the project's own workload. An API without
// environments, or an environment the project does not have, is an error here, before anything is
// changed (an older API would ignore the field and act on production).
func environmentScope(ctx context.Context, c *runr.Client, p *runr.Project, name string) (string, error) {
	if name == "" {
		return "", nil
	}
	e, err := resolveEnvironment(ctx, c, p, name)
	if err != nil {
		return "", err
	}
	return e.Name, nil
}

// projectIn names the project, and the environment when one was chosen: "shop", "shop (staging)".
func projectIn(p *runr.Project, env string) string {
	if env == "" {
		return p.Name
	}
	return p.Name + " (" + env + ")"
}
