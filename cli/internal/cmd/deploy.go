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
	// strategy overrides the project's deploy strategy for this deploy (--strategy).
	strategy string
	// waitRollout follows a rollout the deploy opened until it ends (--wait-rollout).
	waitRollout    bool
	rolloutTimeout time.Duration
	// env deploys to an environment of the project instead of its own workload.
	env string
	// globalRegions / globalMode make a new project global (over runr.yaml's global:) and change
	// the regions or the mode of an existing global project
	globalRegions []string
	globalMode    string
}

// wait returns how finishDeploy deploys and waits.
func (o deployOptions) wait() deployWait {
	return deployWait{noWait: o.noWait, timeout: o.timeout, strategy: o.strategy, rollout: o.waitRollout,
		rolloutTimeout: o.rolloutTimeout}
}

// DeployResult is the outcome of runr deploy / rollback.
type DeployResult struct {
	Project    *runr.Project    `json:"project"`
	Version    *runr.Version    `json:"version"`
	Deployment *runr.Deployment `json:"deployment"`
	// Environment is the environment deployed to (empty: the project's own workload).
	Environment string `json:"environment,omitempty"`
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
			"changes of runr.yaml (resources, scale, trigger, env; a triggers: list replaces the\n" +
			"project's trigger list), save the code as a new version\n" +
			"(unchanged code reuses the latest version), wait for its build when the runtime\n" +
			"compiles or installs dependencies, deploy it and wait until it serves.\n\n" +
			"caas projects deploy an image: --image overrides runr.yaml's image.\n\n" +
			"A project with the blue-green or canary strategy (runr strategy, or deploy: in runr.yaml)\n" +
			"that is served opens a rollout instead: the deploy waits until the new revision is ready,\n" +
			"prints the rollout (traffic split, test URL) and returns; `runr rollout promote` or\n" +
			"`runr rollout abort` decide it, --wait-rollout follows it to its end. --strategy picks\n" +
			"another strategy for this deploy only, e.g. direct for an urgent fix.\n\n" +
			"--env deploys to an environment of the project (runr environments) instead of its own\n" +
			"workload: the code becomes a version as usual, runr.yaml's settings are not applied (the\n" +
			"environment runs the project's config with its own overrides), and only that\n" +
			"environment's live deployment is replaced — with the environment's deploy strategy, the\n" +
			"project's unless it sets its own (runr strategy --env).\n\n" +
			"A global project runs in several regions behind one global host name: global.regions\n" +
			"of runr.yaml (or --global-regions) lists them, region (or --region) is the primary. A\n" +
			"later deploy applies changed regions, primary or global.mode (runr projects global set).",
		Example: "  runr deploy\n" +
			"  runr deploy ./api --message \"fix: timeout\"\n" +
			"  runr deploy --project web --image ghcr.io/acme/web:1.4.2\n" +
			"  runr deploy --version v3            # deploy a saved version again\n" +
			"  runr deploy --strategy direct       # skip the project's rollout this once\n" +
			"  runr deploy --wait-rollout          # wait until the canary is promoted or rolled back\n" +
			"  runr deploy --env staging           # to the staging environment\n" +
			"  runr deploy --global-regions eu-1,us-1 --region eu-1 --global-mode ha\n" +
			"  runr deploy --output-env \"$GITHUB_OUTPUT\"",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				app.Dir = args[0]
			}
			var err error
			if o.strategy, err = strategyFlag(o.strategy); err != nil {
				return err
			}
			if o.waitRollout && o.noWait {
				return usageErrorf("--wait-rollout and --no-wait exclude each other")
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
	f.StringVar(&o.strategy, "strategy", "", "deploy strategy of this deploy: direct, blue-green or canary (default: the one of the project, or of the --env environment)")
	f.BoolVar(&o.waitRollout, "wait-rollout", false, "follow a blue-green or canary rollout until it is promoted (exit code 6 when it is aborted, rolled back or fails)")
	f.DurationVar(&o.rolloutTimeout, "rollout-timeout", 2*time.Hour, "how long --wait-rollout waits")
	f.StringVar(&o.env, "env", "", "deploy to this environment of the project (runr environments) instead of its own workload")
	f.StringSliceVar(&o.globalRegions, "global-regions", nil, "regions of a global project, comma-separated (over runr.yaml's global.regions; --region is the primary)")
	f.StringVar(&o.globalMode, "global-mode", "", "mode of a global project: ha (default) or proximity (over runr.yaml's global.mode)")
	return cmd
}

func loadDeployProjectFile(app *App, file string) (*projectfile.File, error) {
	if file != "" {
		return app.LoadProjectFile(file)
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
	if o.env != "" {
		return runEnvironmentDeploy(ctx, app, c, name, pf, o)
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
	if pf != nil && len(pf.Triggers) > 0 {
		if project, err = applyTriggers(ctx, app, c, project, pf); err != nil {
			return nil, err
		}
	}
	res.Project = project
	if err := syncDeployStrategy(ctx, app, c, project, pf); err != nil {
		return res, err
	}

	// runr.yaml's network section: access mode and allowed internal callers (docs/internal-network.md)
	if err := syncNetwork(ctx, app, c, project, pf); err != nil {
		return nil, err
	}

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
	return finishDeploy(ctx, app, c, res, version, o.wait())
}

// deployWait says how finishDeploy deploys and how long it follows the deployment.
type deployWait struct {
	noWait  bool
	timeout time.Duration
	// strategy overrides the project's deploy strategy for this deploy ("" keeps it).
	strategy string
	// rollout follows a rollout the deploy opened until it ends, for up to rolloutTimeout.
	rollout        bool
	rolloutTimeout time.Duration
}

// runEnvironmentDeploy deploys to an environment of an existing project: the code becomes a
// version (a caas project deploys its latest version, or --version), runr.yaml's settings are
// not applied — the environment runs the project's config with its own overrides.
func runEnvironmentDeploy(ctx context.Context, app *App, c *runr.Client, name string, pf *projectfile.File, o deployOptions) (*DeployResult, error) {
	if o.image != "" {
		return nil, usageErrorf("--image changes the project's config; with --env deploy a saved version (--version) or promote one (runr promote)")
	}
	if o.region != "" {
		return nil, usageErrorf("--region applies to the project; an environment's region is a setting of it (runr environments update --region)")
	}
	project, err := c.FindProject(ctx, name)
	if err != nil {
		return nil, err
	}
	env, err := resolveEnvironment(ctx, c, project, o.env)
	if err != nil {
		return nil, err
	}
	if pf != nil {
		if cfg, ch := projectfile.Diff(pf.Config(), project.Config); !cfg.Empty() {
			app.Warn("runr.yaml settings (%s) are not applied to a deploy to %s: it runs the project's config with its own overrides (runr environments update)", strings.Join(ch, ", "), env.Name)
		}
	}
	res := &DeployResult{Project: project, Environment: env.Name}
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
	return finishDeployWith(ctx, app, c, res, version, runr.DeployOptions{Environment: env.Name}, o.wait())
}

// finishDeploy starts a deployment of version and waits for it. A version whose image is not in
// the region's registry (built before the region moved to another cluster) is built again
// first: the API queues its deploy, which is followed until the deployment starts. A deploy
// that opens a blue-green or canary rollout is waited for until its new revision is ready (the
// rollout is no longer pending), not until the promotion, unless w.rollout follows the rollout.
func finishDeploy(ctx context.Context, app *App, c *runr.Client, res *DeployResult, version *runr.Version, w deployWait) (*DeployResult, error) {
	return finishDeployWith(ctx, app, c, res, version, runr.DeployOptions{}, w)
}

// finishDeployWith is finishDeploy into an environment (opts.Environment) and / or with a
// restored config (opts.RestoreConfig, a rollback).
func finishDeployWith(ctx context.Context, app *App, c *runr.Client, res *DeployResult, version *runr.Version, opts runr.DeployOptions, w deployWait) (*DeployResult, error) {
	project := res.Project
	where := project.Name
	if opts.Environment != "" {
		where += " (" + opts.Environment + ")"
		res.Environment = opts.Environment
	}
	if w.strategy != "" {
		override := runr.DeployConfig{}
		if opts.Override != nil {
			override = *opts.Override
		}
		override.Strategy = w.strategy
		opts.Override = &override
		app.Progress("Deploying %s of %s (strategy %s for this deploy)", version.Label, where, runr.StrategyLabel(w.strategy))
	} else {
		app.Progress("Deploying %s of %s", version.Label, where)
	}
	started, err := c.StartDeployWith(ctx, project.ID, version.ID, opts)
	if err != nil {
		return res, environmentsErr(err)
	}
	res.URL = project.EndpointURL
	if opts.Environment != "" {
		res.URL = ""
	}
	start := time.Now()
	d := started.Deployment
	if started.Queued != nil {
		res.Queued = started.Queued
		app.Progress("%s", started.Message)
		if w.noWait {
			return res, nil
		}
		q, err := c.WaitQueuedDeploy(ctx, project.ID, started.Queued, app.waitOptions(w.timeout, "Building "+version.Label))
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
	if r := d.Rollout; r != nil {
		how := "the live revision keeps the traffic until the promotion"
		if r.Strategy == runr.StrategyCanary {
			how = "the traffic moves to it step by step"
		}
		app.Progress("Rolling out %s (%s): %s", version.Label, runr.StrategyLabel(r.Strategy), how)
	}
	if w.noWait {
		return res, nil
	}
	d, err = c.WaitDeployment(ctx, project.ID, d.ID, app.waitOptions(w.timeout, "Deployment"))
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
	if opts.Environment != "" {
		res.URL = ""
		if e, err := c.GetEnvironment(ctx, project.ID, opts.Environment); err == nil {
			res.URL = e.URL
		}
	}
	r := res.Deployment.Rollout
	if r == nil || !r.Open {
		app.Progress("%s of %s is live (%s)", version.Label, where, output.Duration(time.Since(start)))
		return res, nil
	}
	app.Progress("%s of %s is ready (%s): %s rollout %s", version.Label, where, output.Duration(time.Since(start)),
		runr.StrategyLabel(r.Strategy), runr.RolloutStatusLine(r))
	if !w.rollout {
		return res, nil
	}
	r, err = c.WaitRollout(ctx, project.ID, res.Deployment.ID, app.waitOptions(w.rolloutTimeout, "Rollout"))
	if r != nil {
		res.Deployment.Rollout = r
	}
	if nd, gerr := c.GetDeployment(ctx, project.ID, res.Deployment.ID); gerr == nil {
		if nd.Rollout == nil {
			nd.Rollout = r
		}
		res.Deployment = nd
	}
	if err != nil {
		return res, err
	}
	app.Progress("%s of %s is live (%s)", version.Label, where, output.Duration(time.Since(start)))
	return res, nil
}

// syncDeployStrategy applies the deploy section of runr.yaml before the deploy: the strategy
// settings the file sets that differ from the project's are sent (PUT deploy-strategy).
func syncDeployStrategy(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File) error {
	if pf == nil {
		return nil
	}
	want := pf.DeployStrategy()
	if want == nil {
		return nil
	}
	have, err := c.GetDeployStrategy(ctx, p.ID)
	if errors.Is(err, runr.ErrUnsupported) {
		app.Warn("deploy of runr.yaml is not applied: this RunR API has no deploy strategies")
		return nil
	}
	if err != nil {
		return err
	}
	in, changed := projectfile.DiffStrategy(*want, have.DeployStrategySettings)
	if len(changed) == 0 {
		return nil
	}
	app.Progress("Updating the deploy strategy of %s: %s", p.Name, strings.Join(changed, ", "))
	updated, err := c.UpdateDeployStrategy(ctx, p.ID, in)
	if err != nil {
		return err
	}
	p.DeployStrategy = updated.Strategy
	return nil
}

func createProject(ctx context.Context, app *App, c *runr.Client, pf *projectfile.File, o deployOptions) (*runr.Project, error) {
	global, err := wantedGlobal(pf, o)
	if err != nil {
		return nil, err
	}
	regionRef := pf.Region
	if o.region != "" {
		regionRef = o.region
	}
	if global != nil && regionRef == "" {
		regionRef = global.Regions[0]
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
	if global != nil {
		regions, err := resolveGlobalRegions(ctx, c, global.Regions)
		if err != nil {
			return nil, err
		}
		names := make([]string, 0, len(regions))
		primary := false
		for _, r := range regions {
			in.RegionIDs = append(in.RegionIDs, r.ID)
			if r.ID == region.ID {
				primary = true
				names = append(names, r.Name+" (primary)")
			} else {
				names = append(names, r.Name)
			}
		}
		if !primary {
			return nil, usageErrorf("the primary region %s is not one of the global regions (%s)", region.Name, strings.Join(global.Regions, ", "))
		}
		in.Placement, in.GlobalMode = runr.PlacementGlobal, global.Mode
		mode := global.Mode
		if mode == "" {
			mode = runr.GlobalModeHA
		}
		app.Progress("Creating global project %s (%s, mode %s) in %s", pf.Name, desc, mode, strings.Join(names, ", "))
		return c.CreateProject(ctx, in)
	}
	app.Progress("Creating project %s (%s) in %s", pf.Name, desc, region.Name)
	return c.CreateProject(ctx, in)
}

// wantedGlobal is the global block a deploy asks for: --global-regions / --global-mode over
// runr.yaml's global:, nil for a regional project.
func wantedGlobal(pf *projectfile.File, o deployOptions) (*projectfile.Global, error) {
	var g *projectfile.Global
	if pf != nil && pf.Global != nil {
		cp := *pf.Global
		cp.Regions = append([]string{}, pf.Global.Regions...)
		g = &cp
	}
	if len(o.globalRegions) > 0 {
		if g == nil {
			g = &projectfile.Global{}
		}
		g.Regions = nil
		for _, r := range o.globalRegions {
			if r = strings.TrimSpace(r); r != "" {
				g.Regions = append(g.Regions, r)
			}
		}
	}
	if o.globalMode != "" {
		if g == nil {
			return nil, usageErrorf("--global-mode needs a global project: list its regions with --global-regions or global.regions in runr.yaml")
		}
		g.Mode = strings.ToLower(strings.TrimSpace(o.globalMode))
	}
	if g == nil {
		return nil, nil
	}
	if g.Mode != "" && g.Mode != runr.GlobalModeHA && g.Mode != runr.GlobalModeProximity {
		return nil, usageErrorf("unknown global mode %q (ha or proximity)", g.Mode)
	}
	if len(g.Regions) < 2 {
		return nil, usageErrorf("a global project runs in at least 2 regions (--global-regions eu-1,us-1 or global.regions in runr.yaml)")
	}
	if len(g.Regions) > runr.MaxGlobalRegions {
		return nil, usageErrorf("a global project runs in at most %d regions", runr.MaxGlobalRegions)
	}
	return g, nil
}

// resolveGlobalRegions resolves region names or ids in their order; a region listed twice is a
// usage error.
func resolveGlobalRegions(ctx context.Context, c *runr.Client, refs []string) ([]*runr.Region, error) {
	all, err := c.ListRegions(ctx)
	if err != nil {
		return nil, err
	}
	var out []*runr.Region
	seen := map[string]bool{}
	for _, ref := range refs {
		r, err := runr.PickRegion(all, ref)
		if err != nil {
			return nil, err
		}
		if seen[r.ID] {
			return nil, usageErrorf("region %s is listed twice", r.Name)
		}
		seen[r.ID] = true
		out = append(out, r)
	}
	return out, nil
}

// syncGlobal applies a changed region list, primary region or mode (runr.yaml, the flags) to a
// global project through PUT /projects/{project}/global; it reports whether it changed anything.
func syncGlobal(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File, o deployOptions) (bool, error) {
	want, err := wantedGlobal(pf, o)
	if err != nil {
		return false, err
	}
	if !p.IsGlobal() {
		if want == nil {
			return false, nil
		}
		if p.Placement == "" {
			return false, fmt.Errorf("this RunR API has no global projects: remove global from runr.yaml (and --global-regions)")
		}
		return false, fmt.Errorf("project %s is a regional project; it cannot become global (create a new project with global: in runr.yaml)", p.Name)
	}
	primaryRef := o.region
	if primaryRef == "" && pf != nil {
		primaryRef = pf.Region
	}
	if want == nil && primaryRef == "" {
		return false, nil
	}
	cur := p.Global
	if cur == nil {
		if cur, err = c.ProjectGlobal(ctx, p.ID); err != nil {
			return false, err
		}
	}
	var in runr.GlobalUpdateInput
	var changed []string
	if want != nil {
		if want.Mode != "" && want.Mode != cur.Mode {
			in.Mode = &want.Mode
			changed = append(changed, "mode "+want.Mode)
		}
		regions, err := resolveGlobalRegions(ctx, c, want.Regions)
		if err != nil {
			return false, err
		}
		ids := make([]string, 0, len(regions))
		names := make([]string, 0, len(regions))
		for _, r := range regions {
			ids = append(ids, r.ID)
			names = append(names, r.Name)
		}
		curIDs := make([]string, 0, len(cur.Regions))
		for _, r := range cur.Regions {
			curIDs = append(curIDs, r.RegionID)
		}
		if !equalStr(ids, curIDs) {
			in.RegionIDs = ids
			changed = append(changed, "regions "+strings.Join(names, ", "))
		}
	}
	if primaryRef != "" {
		region, err := c.FindRegion(ctx, primaryRef)
		if err != nil {
			return false, err
		}
		if region.ID != cur.PrimaryRegionID {
			in.PrimaryRegionID = &region.ID
			changed = append(changed, "primary "+region.Name)
		}
	}
	if len(changed) == 0 {
		return false, nil
	}
	app.Progress("Updating global project %s: %s", p.Name, strings.Join(changed, "; "))
	if _, err := c.UpdateProjectGlobal(ctx, p.ID, in); err != nil {
		return false, err
	}
	return true, nil
}

func syncProject(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File, o deployOptions) (*runr.Project, error) {
	var update runr.UpdateProjectInput
	var changed []string
	// A global project changes its regions, primary region and mode through its own endpoint
	globalChanged, err := syncGlobal(ctx, app, c, p, pf, o)
	if err != nil {
		return nil, err
	}
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
	if regionRef != "" && !p.IsGlobal() {
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
		if globalChanged {
			return c.GetProject(ctx, p.ID)
		}
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

// applyTriggers sets the project's trigger list to `triggers:` of runr.yaml (PUT
// /projects/{project}/triggers) before the deploy that applies it. An entry whose name and type
// match an existing trigger keeps its secrets, inbox URL and stored events; the others are
// created or removed.
func applyTriggers(ctx context.Context, app *App, c *runr.Client, p *runr.Project, pf *projectfile.File) (*runr.Project, error) {
	specs := pf.TriggerSpecs()
	names := make([]string, 0, len(specs))
	for _, s := range specs {
		n := s.Name
		if n == "" {
			n = "(" + s.Type + ")"
		} else {
			n += " (" + s.Type + ")"
		}
		names = append(names, n)
	}
	app.Progress("Applying the triggers of runr.yaml: %s", strings.Join(names, ", "))
	list, err := c.ReplaceTriggers(ctx, p.ID, specs)
	if errors.Is(err, runr.ErrUnsupported) {
		return nil, errNoTriggers
	}
	var ae *runr.APIError
	if errors.As(err, &ae) {
		for k := range ae.Errors {
			if strings.HasSuffix(k, "secrets.signing_secret") {
				return nil, fmt.Errorf("%w\nA webhook that verifies its sender needs its signing secret in RunR first: "+
					"runr triggers add --type webhook --name NAME --set verify=PRESET --secret-stdin, then run runr deploy again", err)
			}
		}
	}
	if err != nil {
		return nil, err
	}
	for _, t := range list.Triggers {
		if t.URL != "" {
			app.Progress("Webhook %s: %s", t.Name, t.URL)
		}
	}
	printTriggerCleanup(app, list.Cleanup)
	// The primary trigger is mirrored into the project's config (trigger_type)
	return c.GetProject(ctx, p.ID)
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
		if r := res.Deployment.Rollout; r != nil && r.Status != runr.RolloutPromoted {
			deployRolloutDetail(t, res, r)
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
		// A global project is live in its primary region; the other regions follow
		if res.Project != nil && res.Project.IsGlobal() && res.Deployment.Status == runr.DeploymentActive {
			t.Note("The other regions follow: runr projects global %s shows them.", res.Project.Name)
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
	if res.Environment != "" {
		add("environment", res.Environment)
	}
	if res.Queued != nil {
		add("queued_deploy_id", res.Queued.ID)
	}
	if res.Deployment != nil {
		add("deployment_id", res.Deployment.ID)
		add("status", res.Deployment.Status)
		if r := res.Deployment.Rollout; r != nil {
			add("rollout_id", r.ID)
			add("rollout_status", r.Status)
			add("test_url", r.TestURL)
		}
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
