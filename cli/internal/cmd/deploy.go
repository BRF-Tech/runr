package cmd

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/internal/runtimes"
	"github.com/brf-tech/runr/cli/internal/scaffold"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func newInitCmd(app *App) *cobra.Command {
	var o scaffold.Options
	cmd := &cobra.Command{
		Use:   "init [dir]",
		Short: "Create a project directory with runr.yaml and a starter handler",
		Example: "  runr init hello --runtime python\n" +
			"  runr init api --type faas --runtime nodejs --region eu-1\n" +
			"  runr init web --type caas --image nginx:1.27-alpine",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			o.Dir = "."
			if len(args) == 1 {
				o.Dir = args[0]
			}
			if o.Type != runr.TypeCaaS && o.Runtime != "" && !runtimes.Known(o.Runtime) {
				if err := pluginTemplate(cmd.Context(), app, &o); err != nil {
					return err
				}
			}
			written, err := scaffold.Write(o)
			if err != nil {
				var ve *projectfile.ValidationError
				if errors.As(err, &ve) {
					return err
				}
				return usageErrorf("%v", err)
			}
			return app.Printer().Print(map[string]any{"files": written}, func(t *output.TableWriter) {
				for _, w := range written {
					t.Note("created %s", w)
				}
				t.Note("")
				t.Note("Next: `runr dev` runs it locally, `runr deploy` ships it.")
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&o.Name, "name", "", "project name (default: the directory name)")
	f.StringVar(&o.Type, "type", "faas", "project type: faas, xaas or caas")
	f.StringVar(&o.Runtime, "runtime", "", "runtime of faas/xaas: python, nodejs, go, php or a runtime plugin (default: python)")
	f.StringVar(&o.RuntimeVersion, "runtime-version", "", "runtime version (default: the platform's default)")
	f.StringVar(&o.Region, "region", "", "region name")
	f.StringVar(&o.Image, "image", "", "container image of a caas project")
	f.BoolVar(&o.Force, "force", false, "overwrite existing files")
	return cmd
}

// pluginTemplate fetches the starter files of a runtime plugin from the platform's catalog.
func pluginTemplate(ctx context.Context, app *App, o *scaffold.Options) error {
	c, err := app.Client()
	if err != nil {
		return usageErrorf("%q is not a built-in runtime (%s); log in (runr login) to use the runtime plugins of the platform",
			o.Runtime, strings.Join(runtimes.IDs(), ", "))
	}
	rt, err := (&runtimes.Catalog{Client: c}).Find(ctx, o.Runtime)
	if errors.Is(err, runr.ErrUnsupported) {
		return usageErrorf("%q is not a built-in runtime (%s) and this RunR API has no runtime catalog", o.Runtime, strings.Join(runtimes.IDs(), ", "))
	}
	var ae *runr.APIError
	var ne *runr.NetworkError
	if errors.As(err, &ae) || errors.As(err, &ne) {
		return err
	}
	if err != nil {
		return usageErrorf("%v", err)
	}
	handler := ""
	if o.Type == runr.TypeFaaS {
		// The runtime's default entry point (lib.handle for wasm-rust), like a new project in the console.
		handler = "main.handler"
		if rt.Handler != nil && rt.Handler.File != "" && rt.Handler.Function != "" {
			handler = rt.Handler.File + "." + rt.Handler.Function
		}
		o.Handler = handler
	}
	files, err := c.RuntimeTemplate(ctx, rt.ID, o.Type, handler)
	if err != nil {
		return err
	}
	o.Template = files
	if rt.Handler != nil {
		o.Filename = rt.Handler.Filename
	}
	return nil
}

type deployOptions struct {
	image     string
	region    string
	message   string
	version   string
	noWait    bool
	timeout   time.Duration
	outputEnv string
	file      string
}

// DeployResult is the outcome of runr deploy / rollback.
type DeployResult struct {
	Project    *runr.Project    `json:"project"`
	Version    *runr.Version    `json:"version"`
	Deployment *runr.Deployment `json:"deployment"`
	// Queued is the deploy that waited for the version to be built again for the region's
	// registry (its image was in a registry the region no longer pulls from).
	Queued  *runr.QueuedDeploy `json:"queued_deploy,omitempty"`
	URL     string             `json:"url,omitempty"`
	Created bool               `json:"created"`
	Waited  bool               `json:"waited"`
}

func newDeployCmd(app *App) *cobra.Command {
	var o deployOptions
	cmd := &cobra.Command{
		Use:   "deploy [path]",
		Short: "Upload the code as a new version, deploy it and wait until it is ready",
		Long: "Deploy the project in path (default: the directory of runr.yaml).\n\n" +
			"The first deploy creates the project from runr.yaml. Later deploys apply config\n" +
			"changes of runr.yaml (resources, scale, trigger, env), save the code as a new version\n" +
			"(unchanged code reuses the latest version), wait for its build when the runtime\n" +
			"compiles or installs dependencies, deploy it and wait until it serves.\n\n" +
			"caas projects deploy an image: --image overrides runr.yaml's image.",
		Example: "  runr deploy\n" +
			"  runr deploy ./api --message \"fix: timeout\"\n" +
			"  runr deploy --project web --image ghcr.io/acme/web:1.4.2\n" +
			"  runr deploy --version v3            # deploy a saved version again\n" +
			"  runr deploy --output-env \"$GITHUB_OUTPUT\"",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.Dir = args[0]
			}
			res, err := runDeploy(cmd.Context(), app, o)
			if res != nil {
				if werr := writeOutputEnv(o.outputEnv, res); werr != nil && err == nil {
					err = werr
				}
				if perr := printDeployResult(app, res); perr != nil && err == nil {
					err = perr
				}
			}
			return err
		},
	}
	f := cmd.Flags()
	f.StringVar(&o.image, "image", "", "container image to deploy (caas)")
	f.StringVar(&o.region, "region", "", "region of a new project (overrides runr.yaml)")
	f.StringVarP(&o.message, "message", "m", "", "version message")
	f.StringVar(&o.version, "version", "", "deploy an existing version (v3, 3, latest or an id) instead of uploading")
	f.BoolVar(&o.noWait, "no-wait", false, "return once the deployment started")
	f.DurationVar(&o.timeout, "timeout", 10*time.Minute, "how long to wait for the build and the deployment")
	f.StringVar(&o.outputEnv, "output-env", "", "append url=, version=, deployment_id=, … lines to this file ($GITHUB_OUTPUT, a GitLab dotenv report)")
	f.StringVarP(&o.file, "file", "f", "", "project file (default: runr.yaml in the project directory)")
	return cmd
}

func loadDeployProjectFile(app *App, file string) (*projectfile.File, error) {
	if file != "" {
		return projectfile.Load(file)
	}
	pf, err := app.ProjectFile()
	if errors.Is(err, projectfile.ErrNotFound) {
		return nil, nil
	}
	return pf, err
}

func runDeploy(ctx context.Context, app *App, o deployOptions) (*DeployResult, error) {
	pf, err := loadDeployProjectFile(app, o.file)
	if err != nil {
		return nil, err
	}
	name := app.ProjectRef
	if name == "" {
		name = os.Getenv(EnvProject)
	}
	if name == "" && pf != nil {
		name = pf.Name
	}
	if name == "" {
		return nil, usageErrorf("no project: run in a directory with runr.yaml (runr init) or pass --project")
	}
	c, err := app.Client()
	if err != nil {
		return nil, err
	}

	res := &DeployResult{}
	project, err := c.FindProject(ctx, name)
	var nf *runr.NotFoundError
	switch {
	case errors.As(err, &nf) || runr.IsNotFound(err):
		if pf == nil {
			return nil, err
		}
		if pf.Name != name {
			return nil, fmt.Errorf("project %q does not exist; runr.yaml describes %q", name, pf.Name)
		}
		project, err = createProject(ctx, app, c, pf, o)
		if err != nil {
			return nil, err
		}
		res.Created = true
	case err != nil:
		return nil, err
	default:
		if project, err = syncProject(ctx, app, c, project, pf, o); err != nil {
			return nil, err
		}
	}
	res.Project = project

	version, err := deployVersion(ctx, app, c, project, pf, o)
	if err != nil {
		return nil, err
	}
	if version.BuildStatus != runr.BuildReady && version.BuildStatus != "" {
		version, err = c.WaitVersion(ctx, project.ID, version, app.waitOptions(o.timeout, "Building "+version.Label))
		if err != nil {
			res.Version = version
			return res, err
		}
	}
	res.Version = version
	return finishDeploy(ctx, app, c, res, version, o.noWait, o.timeout)
}

// finishDeploy starts a deployment of version and waits for it. A version whose image is not in
// the region's registry (built before the region moved to another cluster) is built again
// first: the API queues its deploy, which is followed until the deployment starts.
func finishDeploy(ctx context.Context, app *App, c *runr.Client, res *DeployResult, version *runr.Version, noWait bool, timeout time.Duration) (*DeployResult, error) {
	project := res.Project
	app.Progress("Deploying %s of %s", version.Label, project.Name)
	started, err := c.StartDeploy(ctx, project.ID, version.ID, nil)
	if err != nil {
		return res, err
	}
	res.URL = project.EndpointURL
	start := time.Now()
	d := started.Deployment
	if started.Queued != nil {
		res.Queued = started.Queued
		app.Progress("%s", started.Message)
		if noWait {
			return res, nil
		}
		q, err := c.WaitQueuedDeploy(ctx, project.ID, started.Queued, app.waitOptions(timeout, "Building "+version.Label))
		if q != nil {
			res.Queued = q
		}
		if err != nil {
			return res, err
		}
		if d, err = c.GetDeployment(ctx, project.ID, q.DeploymentID); err != nil {
			return res, err
		}
	}
	res.Deployment = d
	if noWait {
		return res, nil
	}
	d, err = c.WaitDeployment(ctx, project.ID, d.ID, app.waitOptions(timeout, "Deployment"))
	if d != nil {
		res.Deployment = d
	}
	res.Waited = true
	if err != nil {
		return res, err
	}
	if p, err := c.GetProject(ctx, project.ID); err == nil {
		res.Project = p
		res.URL = p.EndpointURL
	}
	app.Progress("%s of %s is live (%s)", version.Label, project.Name, output.Duration(time.Since(start)))
	return res, nil
}

func createProject(ctx context.Context, app *App, c *runr.Client, pf *projectfile.File, o deployOptions) (*runr.Project, error) {
	regionRef := pf.Region
	if o.region != "" {
		regionRef = o.region
	}
	region, err := c.FindRegion(ctx, regionRef)
	if err != nil {
		return nil, err
	}
	cfg := pf.Config()
	if pf.Type == runr.TypeCaaS {
		if o.image != "" {
			cfg.Image = &o.image
		}
		if cfg.Image == nil {
			return nil, usageErrorf("a caas project needs an image: set image in runr.yaml or pass --image")
		}
	} else if o.image != "" {
		return nil, usageErrorf("--image applies to caas projects; %s is %s", pf.Name, pf.Type)
	}
	in := runr.CreateProjectInput{
		Name: pf.Name, ServiceType: pf.Type, Runtime: pf.Runtime, RuntimeVersion: pf.RuntimeVersion,
		Description: pf.Description, RegionID: region.ID, Config: cfg,
	}
	desc := pf.Type
	if pf.Runtime != "" {
		desc += ", " + strings.TrimSpace(pf.Runtime+" "+pf.RuntimeVersion)
	}
	app.Progress("Creating project %s (%s) in %s", pf.Name, desc, region.Name)
	return c.CreateProject(ctx, in)
}

func syncProject(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File, o deployOptions) (*runr.Project, error) {
	var update runr.UpdateProjectInput
	var changed []string
	if pf != nil {
		if pf.Type != p.ServiceType {
			return nil, fmt.Errorf("project %s is %s on the server and %s in runr.yaml; the type cannot change", p.Name, p.ServiceType, pf.Type)
		}
		if pf.Runtime != "" && pf.Runtime != p.Runtime {
			return nil, fmt.Errorf("project %s runs %s on the server and %s in runr.yaml; the runtime cannot change (create a new project)", p.Name, p.Runtime, pf.Runtime)
		}
		cfg, ch := projectfile.Diff(pf.Config(), p.Config)
		for i, c := range ch {
			if c == "env (hidden)" {
				app.Warn("env of runr.yaml is not applied: the token cannot read the project's variables (needs env.edit, which includes env.view)")
				ch = append(ch[:i], ch[i+1:]...)
				break
			}
		}
		if !cfg.Empty() {
			update.Config = &cfg
			changed = append(changed, ch...)
		}
		if pf.Description != "" && pf.Description != p.Description {
			update.Description = &pf.Description
			changed = append(changed, "description")
		}
	}
	regionRef := o.region
	if regionRef == "" && pf != nil {
		regionRef = pf.Region
	}
	if regionRef != "" {
		region, err := c.FindRegion(ctx, regionRef)
		if err != nil {
			return nil, err
		}
		if region.ID != p.RegionID {
			update.RegionID = &region.ID
			changed = append(changed, "region")
			app.Progress("Moving %s to region %s", p.Name, region.Name)
		}
	}
	if o.image != "" {
		if p.ServiceType != runr.TypeCaaS {
			return nil, usageErrorf("--image applies to caas projects; %s is %s", p.Name, p.ServiceType)
		}
		if o.image != p.Config.Image {
			if update.Config == nil {
				update.Config = &runr.ConfigInput{}
			}
			update.Config.Image = &o.image
			changed = append(changed, "image")
		}
	}
	if len(changed) == 0 {
		return p, nil
	}
	sort.Strings(changed)
	changed = uniq(changed)
	msg := o.message
	if msg == "" {
		msg = "runr deploy: " + strings.Join(changed, ", ")
	}
	update.ConfigMessage = msg
	app.Progress("Updating %s: %s", p.Name, strings.Join(changed, ", "))
	return c.UpdateProject(ctx, p.ID, update)
}

// deployVersion saves the code and returns the version to deploy.
func deployVersion(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File, o deployOptions) (*runr.Version, error) {
	if o.version != "" {
		v, err := c.FindVersion(ctx, p.ID, o.version)
		if err != nil {
			return nil, err
		}
		return v, nil
	}
	dir, err := app.projectDir(pf)
	if err != nil {
		return nil, err
	}
	var ignore []string
	if pf != nil {
		ignore = pf.Ignore
	}
	switch p.ServiceType {
	case runr.TypeFaaS:
		files, err := projectfile.CollectFiles(dir, ignore)
		if err != nil {
			return nil, err
		}
		// Built-in runtimes: check the handler file here; the API checks runtime plugins.
		if runtimes.Known(p.Runtime) {
			main := mainFileOf(p, pf)
			found := false
			for _, f := range files {
				found = found || f.Name == main
			}
			if !found {
				return nil, fmt.Errorf("the handler file %s is not in %s (handler %q)", main, dir, p.Config.Handler)
			}
		}
		app.Progress("Saving %d file(s) from %s", len(files), dir)
		v, err := c.SaveFiles(ctx, p.ID, files, o.message)
		if err != nil {
			return nil, err
		}
		app.Progress("Version %s (%s)", v.Label, buildLabel(v))
		return v, nil
	case runr.TypeXaaS:
		main := mainFileOf(p, pf)
		data, err := os.ReadFile(filepath.Join(dir, main))
		if err != nil {
			return nil, fmt.Errorf("read the script: %w", err)
		}
		app.Progress("Saving %s", main)
		v, err := c.SaveCode(ctx, p.ID, string(data), o.message)
		if err != nil {
			return nil, err
		}
		app.Progress("Version %s (%s)", v.Label, buildLabel(v))
		return v, nil
	case runr.TypeCaaS:
		// The image is part of the config: a changed image saved a new version already.
		versions, err := c.ListVersions(ctx, p.ID)
		if err != nil {
			return nil, err
		}
		v, err := runr.PickVersion(versions, "latest")
		if err != nil {
			return nil, fmt.Errorf("project %s has no version to deploy", p.Name)
		}
		return v, nil
	}
	return nil, fmt.Errorf("project %s has type %s, which cannot be deployed", p.Name, p.ServiceType)
}

func buildLabel(v *runr.Version) string {
	if v.BuildStatus == runr.BuildReady || v.BuildStatus == "" {
		return "ready"
	}
	return "build " + v.BuildStatus
}

// mainFileOf returns the handler file of a project, from the server config (authoritative)
// or runr.yaml.
func mainFileOf(p *runr.Project, pf *projectfile.File) string {
	tmp := projectfile.File{Type: p.ServiceType, Runtime: p.Runtime, Handler: p.Config.Handler, File: p.Config.Filename}
	if tmp.Handler == "" && tmp.File == "" && pf != nil {
		tmp.Handler, tmp.File = pf.Handler, pf.File
	}
	return tmp.MainFile()
}

func printDeployResult(app *App, res *DeployResult) error {
	return app.Printer().Print(res, func(t *output.TableWriter) {
		if res.Deployment == nil {
			if q := res.Queued; q != nil {
				t.Note("Queued deploy %s (%s): %s", q.ID, q.Status, q.Message)
				t.Note("`runr versions` shows the build; `runr status` the deployment once it has started.")
			}
			return
		}
		switch {
		case res.URL != "" && res.Waited && res.Deployment.Status == runr.DeploymentActive:
			t.Note("%s", res.URL)
		case !res.Waited:
			t.Note("Deployment %s started (%s). Follow it with `runr status`.", res.Deployment.ID, res.Deployment.Status)
		case res.URL == "" && res.Deployment.Status == runr.DeploymentActive:
			trigger := res.Project.Config.Trigger()
			t.Note("Deployed (%s trigger: no public URL).", trigger)
		}
	})
}

// writeOutputEnv appends KEY=value lines for CI ($GITHUB_OUTPUT, GitLab dotenv).
func writeOutputEnv(path string, res *DeployResult) error {
	if path == "" || res == nil {
		return nil
	}
	lines := []string{}
	add := func(k, v string) { lines = append(lines, k+"="+strings.ReplaceAll(v, "\n", " ")) }
	if res.Project != nil {
		add("project", res.Project.Name)
		add("project_id", res.Project.ID)
	}
	if res.Version != nil {
		add("version", res.Version.Label)
		add("version_id", res.Version.ID)
	}
	if res.Queued != nil {
		add("queued_deploy_id", res.Queued.ID)
	}
	if res.Deployment != nil {
		add("deployment_id", res.Deployment.ID)
		add("status", res.Deployment.Status)
	} else if res.Queued != nil {
		add("status", "building")
	}
	add("url", res.URL)
	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return fmt.Errorf("--output-env: %w", err)
	}
	defer f.Close()
	_, err = f.WriteString(strings.Join(lines, "\n") + "\n")
	return err
}

func uniq(s []string) []string {
	out := s[:0]
	for i, v := range s {
		if i == 0 || v != s[i-1] {
			out = append(out, v)
		}
	}
	return out
}
