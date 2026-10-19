package cmd

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func newProjectsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "projects",
		Aliases: []string{"project", "ps"},
		Short:   "List, show and delete projects",
	}
	cmd.AddCommand(&cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List projects",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListProjects(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.Project{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "TYPE", "RUNTIME", "REGION", "STATUS", "VERSION", "URL")
				for _, p := range list {
					status, ver := "-", "-"
					if p.LatestDeployment != nil {
						status = p.LatestDeployment.Status
						if p.LatestDeployment.Version != nil {
							ver = p.LatestDeployment.Version.Label
						}
					}
					if p.Stopped != nil {
						status = "stopped"
					}
					t.Row(p.Name, p.ServiceType, runtimeLabel(&p), output.Dash(p.RegionName()), status, ver, output.Dash(p.EndpointURL))
				}
				if len(list) == 0 {
					t.Note("No projects yet: `runr init` and `runr deploy` create one.")
				}
			})
		},
	})
	cmd.AddCommand(&cobra.Command{
		Use:   "get [NAME]",
		Short: "Show a project (default: the project of runr.yaml)",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.ProjectRef = args[0]
			}
			_, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(p, func(t *output.TableWriter) { projectDetail(t, p) })
		},
	})
	var yes bool
	del := &cobra.Command{
		Use:     "delete NAME",
		Aliases: []string{"rm"},
		Short:   "Delete a project with its versions, deployments and domains",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			app.ProjectRef = args[0]
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if err := app.confirm(fmt.Sprintf("Delete project %s and everything it runs", p.Name), yes); err != nil {
				return err
			}
			if err := c.DeleteProject(cmd.Context(), p.ID); err != nil {
				return err
			}
			app.Progress("Project %s deleted.", p.Name)
			return nil
		},
	}
	del.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	cmd.AddCommand(del)
	return cmd
}

func runtimeLabel(p *runr.Project) string {
	if p.ServiceType == runr.TypeCaaS {
		return output.Truncate(output.Dash(p.Config.Image), 40)
	}
	return output.Dash(strings.TrimSpace(p.Runtime + " " + p.RuntimeVersion))
}

func projectDetail(t *output.TableWriter, p *runr.Project) {
	t.KV("Name", p.Name)
	t.KV("ID", p.ID)
	t.KV("Type", p.ServiceType)
	if p.ServiceType == runr.TypeCaaS {
		t.KV("Image", output.Dash(p.Config.Image))
	} else {
		t.KV("Runtime", runtimeLabel(p))
		t.KV("Handler", output.Dash(p.Config.Handler))
		if p.Config.Filename != "" {
			t.KV("File", p.Config.Filename)
		}
	}
	if p.RuntimeEOL != "" {
		t.KV("Runtime EOL", p.RuntimeEOL)
	}
	t.KV("Region", output.Dash(p.RegionName()))
	t.KV("URL", output.Dash(p.EndpointURL))
	if p.Stopped != nil {
		t.KV("Stopped", stopLabel(p.Stopped))
	}
	t.KV("Trigger", triggerLabel(p.Config))
	t.KV("Resources", resourcesLabel(p.Config))
	t.KV("Scale", scaleLabel(p.Config))
	if p.LatestVersion != nil {
		t.KV("Latest version", p.LatestVersion.Label+" ("+buildLabel(p.LatestVersion)+")")
	}
	if p.LatestDeployment != nil {
		v := ""
		if p.LatestDeployment.Version != nil {
			v = " " + p.LatestDeployment.Version.Label
		}
		t.KV("Deployment", p.LatestDeployment.Status+v)
	}
	if p.Description != "" {
		t.KV("Description", p.Description)
	}
	t.KV("Created", output.Age(p.CreatedAt)+" ago")
}

// stopLabel says who stopped a project, when and why: the platform (its administrator is not
// named) or the organization; it serves nothing and cannot be deployed until it is resumed.
func stopLabel(s *runr.ProjectStop) string {
	who := "by the organization"
	switch {
	case s.ByPlatform():
		who = "by the platform"
	case s.By != nil && s.By.Name != "":
		who = "by " + s.By.Name + " for the organization"
	}
	if s.At != nil {
		who += " " + output.Age(s.At) + " ago"
	}
	if s.Reason != "" {
		who += ": " + s.Reason
	}
	return who
}

func triggerLabel(c runr.ProjectConfig) string {
	switch c.Trigger() {
	case runr.TriggerSchedule:
		return "schedule " + c.Schedule
	case runr.TriggerQueue:
		return "queue " + c.QueueStream
	}
	return c.Trigger()
}

func resourcesLabel(c runr.ProjectConfig) string {
	var parts []string
	if c.CPU != "" {
		parts = append(parts, "cpu "+c.CPU)
	}
	if c.MemoryMB != nil {
		parts = append(parts, strconv.Itoa(*c.MemoryMB)+" MB")
	}
	if c.TimeoutSeconds != nil {
		parts = append(parts, "timeout "+strconv.Itoa(*c.TimeoutSeconds)+"s")
	}
	return output.Dash(strings.Join(parts, ", "))
}

func scaleLabel(c runr.ProjectConfig) string {
	min, max := "0", "-"
	if c.MinScale != nil {
		min = strconv.Itoa(*c.MinScale)
	}
	if c.MaxScale != nil {
		max = strconv.Itoa(*c.MaxScale)
	}
	s := min + ".." + max
	if c.ScaleToZero != nil && *c.ScaleToZero {
		s += ", scales to zero"
	}
	return s
}

func newVersionsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "versions",
		Aliases: []string{"version-list"},
		Short:   "List a project's versions",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List versions, newest first; * marks the deployed one",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			versions, err := c.ListVersions(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			current, _ := c.CurrentDeployment(cmd.Context(), p.ID)
			if versions == nil {
				versions = []runr.Version{}
			}
			return app.Printer().Print(versions, func(t *output.TableWriter) {
				t.Header("", "VERSION", "BUILD", "MESSAGE", "BY", "AGE")
				for _, v := range versions {
					mark := ""
					if current != nil && current.Version != nil && current.Version.ID == v.ID && current.Live() {
						mark = "*"
					}
					by := "-"
					if v.CreatedBy != nil {
						by = v.CreatedBy.Name
					}
					t.Row(mark, v.Label, v.BuildStatus, output.Truncate(output.Dash(v.CommitMessage), 50), by, output.Age(v.CreatedAt))
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	return cmd
}

func newRollbackCmd(app *App) *cobra.Command {
	var noWait bool
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "rollback VERSION",
		Short: "Deploy an earlier version again (v3, 3 or a version id)",
		Long: "Deploy an earlier version. Code projects run that version's code with the current\n" +
			"config (env, resources, scale). caas projects get the version's image, command, args and\n" +
			"ports back as a new version, since the image is part of the config.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			v, err := c.FindVersion(ctx, p.ID, args[0])
			if err != nil {
				return err
			}
			if p.ServiceType == runr.TypeCaaS {
				if v, err = restoreContainerConfig(ctx, app, c, p, v); err != nil {
					return err
				}
			}
			res := &DeployResult{Project: p, Version: v}
			res, err = finishDeploy(ctx, app, c, res, v, noWait, timeout)
			if res != nil && res.Deployment != nil {
				if perr := printDeployResult(app, res); perr != nil && err == nil {
					err = perr
				}
			}
			return err
		},
	}
	cmd.Flags().BoolVar(&noWait, "no-wait", false, "return once the deployment started")
	cmd.Flags().DurationVar(&timeout, "timeout", 10*time.Minute, "how long to wait")
	return cmd
}

// restoreContainerConfig writes a caas version's image settings back (new version) when they
// differ from the current config, and returns the version to deploy.
func restoreContainerConfig(ctx context.Context, app *App, c *runr.Client, p *runr.Project, v *runr.Version) (*runr.Version, error) {
	snap := v.ConfigSnapshot
	img, _ := snap["image"].(string)
	if img == "" {
		return v, nil
	}
	in := runr.ConfigInput{}
	changed := false
	if img != p.Config.Image {
		in.Image = &img
		changed = true
	}
	if cmdList, ok := stringList(snap["command"]); ok && !equalStr(cmdList, p.Config.Command) {
		in.Command = &cmdList
		changed = true
	}
	if argList, ok := stringList(snap["args"]); ok && !equalStr(argList, p.Config.Args) {
		in.Args = &argList
		changed = true
	}
	if !changed {
		return v, nil
	}
	app.Progress("Restoring the container config of %s (image %s)", v.Label, img)
	updated, err := c.UpdateProject(ctx, p.ID, runr.UpdateProjectInput{Config: &in, ConfigMessage: "rollback to " + v.Label})
	if err != nil {
		return nil, err
	}
	*p = *updated
	return c.FindVersion(ctx, p.ID, "latest")
}

func stringList(v any) ([]string, bool) {
	raw, ok := v.([]any)
	if !ok {
		return nil, false
	}
	out := make([]string, 0, len(raw))
	for _, x := range raw {
		out = append(out, fmt.Sprint(x))
	}
	return out, true
}

func equalStr(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

func newStatusCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "status",
		Short: "Show the project, its live deployment and URL",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			d, err := c.CurrentDeployment(cmd.Context(), p.ID)
			if err != nil {
				return err
			}
			out := map[string]any{"project": p, "deployment": d}
			return app.Printer().Print(out, func(t *output.TableWriter) {
				t.KV("Project", p.Name+" ("+p.ServiceType+", "+runtimeLabel(p)+")")
				t.KV("Region", output.Dash(p.RegionName()))
				t.KV("URL", output.Dash(p.EndpointURL))
				if p.Stopped != nil {
					t.KV("Stopped", stopLabel(p.Stopped))
				}
				t.KV("Trigger", triggerLabel(p.Config))
				t.KV("Scale", scaleLabel(p.Config))
				if p.LatestVersion != nil {
					t.KV("Latest version", p.LatestVersion.Label+" ("+buildLabel(p.LatestVersion)+")")
				}
				if d == nil {
					t.KV("Deployment", "none (run `runr deploy`)")
					return
				}
				label := ""
				if d.Version != nil {
					label = d.Version.Name
				}
				t.KV("Deployment", d.Status+" "+label)
				if d.ReadyAt != nil {
					t.KV("Ready since", d.ReadyAt.Local().Format(time.RFC3339))
				}
				if d.KnativeMessage != "" {
					t.KV("Message", d.KnativeMessage)
				}
				t.KV("Deployed by", output.Dash(d.DeployedBy))
			})
		},
	}
}

func newOpenCmd(app *App) *cobra.Command {
	var printOnly bool
	cmd := &cobra.Command{
		Use:   "open",
		Short: "Open the project URL in the browser",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			_, p, err := app.Project(cmd.Context())
			if err != nil {
				return err
			}
			if p.EndpointURL == "" {
				return fmt.Errorf("project %s has no URL (trigger %s)", p.Name, p.Config.Trigger())
			}
			if printOnly || app.OpenURL == nil {
				fmt.Fprintln(app.Out, p.EndpointURL)
				return nil
			}
			app.Progress("Opening %s", p.EndpointURL)
			if err := app.OpenURL(p.EndpointURL); err != nil {
				fmt.Fprintln(app.Out, p.EndpointURL)
				return fmt.Errorf("open the browser: %w", err)
			}
			return nil
		},
	}
	cmd.Flags().BoolVar(&printOnly, "print", false, "print the URL instead of opening it")
	return cmd
}

func newRegionsCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "regions",
		Aliases: []string{"region"},
		Short:   "The clusters projects run in: create, inspect, configure and remove regions and their services",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List regions",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.ListRegions(cmd.Context())
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.Region{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("NAME", "STATUS", "QUEUES", "PROJECTS", "ID")
				for _, r := range list {
					queues := "no"
					if r.HasRedis {
						queues = "yes"
					}
					status := r.Status()
					if r.IsDemo {
						status += " (demo)"
					}
					t.Row(r.Name, status, queues, strconv.Itoa(r.ProjectCount), r.ID)
				}
			})
		},
	}
	cmd.AddCommand(ls)
	cmd.RunE = ls.RunE
	addRegionAdminCommands(app, cmd) // regions_admin.go
	return cmd
}

// newRuntimesCmd: runtimes.go
