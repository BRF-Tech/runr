package cmd

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/runrsh/core/cli/internal/output"
	"github.com/runrsh/core/cli/internal/runtimes"
	"github.com/runrsh/core/cli/pkg/runr"
)

// runr runtimes: the runtime catalog — built-in runtimes, runtime plugins registered from a
// runtime.yaml manifest (platform and custom runtimes) and their region image builds
// (docs/runtimes.md).

func newRuntimesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "runtimes",
		Aliases: []string{"runtime"},
		Short:   "List, inspect and register runtimes (built-in runtimes and runtime plugins)",
		Long: "The runtime catalog of the platform: the built-in runtimes and the runtime plugins registered\n" +
			"from a runtime.yaml manifest. Registering, changing and deleting runtimes needs runtimes.manage\n" +
			"(platform runtimes) or runtimes.use_custom (custom runtimes of your organization).",
	}
	ls := runtimesLsCmd(app)
	cmd.AddCommand(ls, runtimesGetCmd(app), runtimesRegisterCmd(app), runtimesUpdateCmd(app), runtimesRmCmd(app),
		runtimesValidateCmd(app), runtimesTemplateCmd(app), runtimesBuildsCmd(app), runtimesBuildCmd(app),
		runtimesTestDeployCmd(app))
	cmd.RunE = ls.RunE
	cmd.Args = cobra.NoArgs
	cmd.Flags().AddFlagSet(ls.Flags())
	return cmd
}

func runtimesLsCmd(app *App) *cobra.Command {
	var all bool
	var region string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List runtimes and their versions",
		Example: "  runr runtimes ls\n" +
			"  runr runtimes ls --all          # disabled runtimes too (runtimes.manage / runtimes.use_custom)\n" +
			"  runr runtimes ls --region eu-1  # the runtimes offered in a region",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			var list []runtimes.Info
			if all || region != "" {
				c, err := app.Client()
				if err != nil {
					return err
				}
				o := runr.ListRuntimesOptions{All: all}
				if region != "" {
					r, err := c.FindRegion(cmd.Context(), region)
					if err != nil {
						return err
					}
					o.Region = r.ID
				}
				cat, err := c.RuntimeCatalogFiltered(cmd.Context(), o)
				if err != nil {
					return err
				}
				list = runtimes.CatalogInfos(cat.Data)
			} else {
				var err error
				if list, err = app.Resolver().List(cmd.Context()); err != nil {
					return err
				}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) {
				t.Header("RUNTIME", "VERSION", "DEFAULT", "EOL", "KIND", "MODE", "IMAGE")
				for _, r := range list {
					t.Row(r.ID, r.Version, yesNo(r.Default), output.Dash(r.EOL), infoKind(r), output.Dash(r.RunMode), r.Image)
				}
			})
		},
	}
	ls.Flags().BoolVar(&all, "all", false, "include disabled runtimes (runtimes.manage or runtimes.use_custom)")
	ls.Flags().StringVar(&region, "region", "", "only the runtimes offered in this region (name or id)")
	return ls
}

func infoKind(r runtimes.Info) string {
	switch {
	case r.Kind != "":
		return r.Kind
	case r.Plugin:
		return "plugin"
	}
	return "built-in"
}

func runtimesGetCmd(app *App) *cobra.Command {
	var manifest bool
	get := &cobra.Command{
		Use:   "get RUNTIME",
		Short: "Show a runtime: run mode, handler, versions and its manifest",
		Example: "  runr runtimes get q\n" +
			"  runr runtimes get q --manifest > runtime.yaml",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			d, err := c.GetRuntime(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if manifest {
				src := d.ManifestSource
				if !strings.HasSuffix(src, "\n") {
					src += "\n"
				}
				_, err := fmt.Fprint(app.Out, src)
				return err
			}
			return app.Printer().Print(d, func(t *output.TableWriter) { runtimeDetail(t, d) })
		},
	}
	get.Flags().BoolVar(&manifest, "manifest", false, "print the manifest as submitted (YAML or JSON)")
	return get
}

func runtimeDetail(t *output.TableWriter, d *runr.RuntimeDetail) {
	t.KV("Runtime", d.ID)
	t.KV("Name", d.Name)
	if d.Description != "" {
		t.KV("Description", d.Description)
	}
	t.KV("Kind", d.Kind())
	t.KV("Source", output.Dash(d.Source))
	t.KV("Run mode", output.Dash(d.RunMode))
	if d.Wasm != "" {
		t.KV("WebAssembly shim", d.Wasm)
	}
	if d.RuntimeClass != "" {
		t.KV("RuntimeClass", d.RuntimeClass)
	}
	t.KV("Language", output.Dash(d.Language))
	t.KV("Extensions", joinOrDash(d.Extensions))
	if d.Handler != nil {
		t.KV("Handler", d.Handler.Filename+" ("+d.Handler.Function+")")
	}
	if d.DependencyFile != "" {
		t.KV("Dependency file", d.DependencyFile)
	}
	t.KV("Compiled", map[bool]string{true: "yes", false: "no"}[d.Compiled])
	t.KV("Triggers", joinOrDash(d.Triggers))
	if len(d.Sandbox) > 0 {
		t.KV("Sandbox", strings.Join(d.Sandbox, ", "))
	}
	regions := "all"
	if len(d.Regions) > 0 {
		regions = strings.Join(d.Regions, ", ")
	}
	t.KV("Regions", regions)
	t.KV("Enabled", map[bool]string{true: "yes", false: "no"}[d.IsEnabled])
	t.KV("Projects", strconv.Itoa(d.ProjectsCount))
	if d.Manifest != nil && d.Manifest.Run.Mode != "" && len(d.Manifest.Run.Command) > 0 {
		t.KV("Command", shellJoin(d.Manifest.Run.Command))
	}
	if len(d.Files) > 0 {
		names := make([]string, 0, len(d.Files))
		for n := range d.Files {
			names = append(names, n)
		}
		sort.Strings(names)
		t.KV("Files", strings.Join(names, ", "))
	}
	t.Header("VERSION", "DEFAULT", "EOL", "SOURCE", "IMAGE")
	for _, v := range d.Versions {
		source, img := "image", v.Image
		if d.Manifest != nil {
			if mv := d.Manifest.Version(v.Version); mv != nil {
				switch {
				case mv.Dockerfile != "":
					source = "dockerfile " + mv.Dockerfile
				case mv.Git != nil:
					source = "git " + mv.Git.URL
				}
			}
		}
		if img == "" {
			img = "(built in the region)"
		}
		t.Row(v.Version, yesNo(v.IsDefault), output.Dash(v.EOLAt), source, img)
	}
}

// runtimeSource reads a manifest (-f FILE or -f -) and the files uploaded with it (--files DIR:
// every regular file of the directory; RunR stores them flat, like a ConfigMap).
func runtimeSource(app *App, file, filesDir string) (string, map[string]string, error) {
	if file == "" {
		return "", nil, usageErrorf("give the manifest with -f runtime.yaml (or -f - for stdin)")
	}
	data, err := readFileArg(app, file)
	if err != nil {
		return "", nil, err
	}
	if strings.TrimSpace(string(data)) == "" {
		return "", nil, usageErrorf("%s is empty", file)
	}
	if filesDir == "" {
		return string(data), nil, nil
	}
	entries, err := os.ReadDir(filesDir)
	if err != nil {
		return "", nil, err
	}
	files := map[string]string{}
	for _, e := range entries {
		if !e.Type().IsRegular() {
			continue
		}
		b, err := os.ReadFile(filepath.Join(filesDir, e.Name()))
		if err != nil {
			return "", nil, err
		}
		files[e.Name()] = string(b)
	}
	return string(data), files, nil
}

// regionIDs resolves region names or ids.
func regionIDs(ctx context.Context, c *runr.Client, refs []string) ([]string, error) {
	out := []string{}
	for _, ref := range refs {
		for _, part := range strings.Split(ref, ",") {
			part = strings.TrimSpace(part)
			if part == "" {
				continue
			}
			r, err := c.FindRegion(ctx, part)
			if err != nil {
				return nil, err
			}
			out = append(out, r.ID)
		}
	}
	return out, nil
}

func runtimesRegisterCmd(app *App) *cobra.Command {
	var file, filesDir string
	var regions []string
	var wait bool
	var timeout time.Duration
	reg := &cobra.Command{
		Use:     "register -f runtime.yaml",
		Aliases: []string{"create"},
		Short:   "Register a runtime plugin from a runtime.yaml manifest",
		Long: "Register a runtime from its manifest: a platform runtime with runtimes.manage, a custom runtime\n" +
			"of your organization with runtimes.use_custom. --files uploads the files of a directory with\n" +
			"it (the Dockerfile and build context of dockerfile versions). RunR builds the image of dockerfile\n" +
			"and git versions in every region; --wait waits for those builds.",
		Example: "  runr runtimes register -f runtime.yaml\n" +
			"  runr runtimes register -f examples/runtimes/q/runtime.yaml --files examples/runtimes/q/interpreter --wait\n" +
			"  runr runtimes register -f runtime.yaml --region eu-1",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			text, files, err := runtimeSource(app, file, filesDir)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			in := runr.RuntimeInput{Manifest: &text, Files: files}
			if len(regions) > 0 {
				if in.Regions, err = regionIDs(cmd.Context(), c, regions); err != nil {
					return err
				}
			}
			d, err := c.RegisterRuntime(cmd.Context(), in)
			if err != nil {
				return err
			}
			app.Progress("Registered runtime %s (%s).", d.ID, d.Source)
			if err := runtimeBuildWait(cmd.Context(), app, c, d, wait, timeout); err != nil {
				return err
			}
			return app.Printer().Print(d, func(t *output.TableWriter) { runtimeDetail(t, d) })
		},
	}
	f := reg.Flags()
	f.StringVarP(&file, "file", "f", "", "the manifest (runtime.yaml, YAML or JSON; - reads stdin)")
	f.StringVar(&filesDir, "files", "", "directory whose files are uploaded with the runtime (Dockerfile, build context)")
	f.StringArrayVar(&regions, "region", nil, "offer the runtime in these regions only (name or id, repeatable; default all)")
	f.BoolVar(&wait, "wait", false, "wait until the runtime images of dockerfile and git versions are built")
	f.DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return reg
}

// runtimeBuildWait reports the region builds of built versions and waits for them with wait.
func runtimeBuildWait(ctx context.Context, app *App, c *runr.Client, d *runr.RuntimeDetail, wait bool, timeout time.Duration) error {
	built := false
	for _, v := range d.Versions {
		built = built || v.Built
	}
	if !built {
		return nil
	}
	if !wait {
		app.Progress("RunR builds the runtime image of %s in each region; `runr runtimes builds %s` shows the status.", d.ID, d.ID)
		return nil
	}
	_, err := c.WaitRuntimeBuilds(ctx, d.ID, "", "", app.waitOptions(timeout, "runtime image build"))
	return err
}

func runtimesUpdateCmd(app *App) *cobra.Command {
	var file, filesDir string
	var regions []string
	var allRegions, enable, disable, wait bool
	var timeout time.Duration
	up := &cobra.Command{
		Use:   "update RUNTIME",
		Short: "Change a runtime: manifest, files, regions, enabled",
		Long: "Replace the manifest (-f) and the uploaded files (--files) of a registered runtime, set the regions\n" +
			"it is offered in, or enable / disable it. The id in the manifest cannot change. Built-in runtimes\n" +
			"accept --region, --all-regions, --enable and --disable only.",
		Example: "  runr runtimes update q -f runtime.yaml --files interpreter/\n" +
			"  runr runtimes update q --disable\n" +
			"  runr runtimes update python --region eu-1 --region us-1",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if enable && disable {
				return usageErrorf("--enable and --disable exclude each other")
			}
			if allRegions && len(regions) > 0 {
				return usageErrorf("--region and --all-regions exclude each other")
			}
			if file == "" && filesDir == "" && len(regions) == 0 && !allRegions && !enable && !disable {
				return usageErrorf("nothing to change: give -f, --files, --region, --all-regions, --enable or --disable")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			in := runr.RuntimeInput{AllRegions: allRegions}
			if file != "" {
				text, files, err := runtimeSource(app, file, filesDir)
				if err != nil {
					return err
				}
				in.Manifest, in.Files = &text, files
			} else if filesDir != "" {
				return usageErrorf("--files goes with -f (the manifest that uses them)")
			}
			if len(regions) > 0 {
				if in.Regions, err = regionIDs(cmd.Context(), c, regions); err != nil {
					return err
				}
			}
			if enable || disable {
				v := enable
				in.IsEnabled = &v
			}
			d, err := c.UpdateRuntime(cmd.Context(), args[0], in)
			if err != nil {
				return err
			}
			app.Progress("Updated runtime %s.", d.ID)
			if in.Manifest != nil {
				if err := runtimeBuildWait(cmd.Context(), app, c, d, wait, timeout); err != nil {
					return err
				}
			}
			return app.Printer().Print(d, func(t *output.TableWriter) { runtimeDetail(t, d) })
		},
	}
	f := up.Flags()
	f.StringVarP(&file, "file", "f", "", "the new manifest (- reads stdin)")
	f.StringVar(&filesDir, "files", "", "directory whose files replace the uploaded files (with -f)")
	f.StringArrayVar(&regions, "region", nil, "offer the runtime in these regions only (name or id, repeatable)")
	f.BoolVar(&allRegions, "all-regions", false, "offer the runtime in every region")
	f.BoolVar(&enable, "enable", false, "enable the runtime")
	f.BoolVar(&disable, "disable", false, "disable the runtime: new projects cannot pick it, deploys of its projects are refused")
	f.BoolVar(&wait, "wait", false, "wait until the runtime images of dockerfile and git versions are built")
	f.DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return up
}

func runtimesRmCmd(app *App) *cobra.Command {
	var yes bool
	rm := &cobra.Command{
		Use:     "rm RUNTIME",
		Aliases: []string{"remove", "delete"},
		Short:   "Delete a registered runtime (refused while projects use it)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := app.confirm("Delete the runtime "+args[0], yes); err != nil {
				return err
			}
			if err := c.DeleteRuntime(cmd.Context(), args[0]); err != nil {
				return err
			}
			app.Progress("Deleted runtime %s.", args[0])
			return app.Printer().Print(map[string]any{"deleted": args[0]}, func(*output.TableWriter) {})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return rm
}

func runtimesValidateCmd(app *App) *cobra.Command {
	var file, filesDir string
	val := &cobra.Command{
		Use:   "validate -f runtime.yaml",
		Short: "Check a manifest the way registration does",
		Long: "Validate a runtime manifest against the platform's JSON Schema and rules (POST /runtimes/validate)\n" +
			"without registering it. Every problem is listed with its JSON path; an invalid manifest exits\n" +
			"with code 5.",
		Example: "  runr runtimes validate -f runtime.yaml\n" +
			"  runr runtimes validate -f examples/runtimes/q/runtime.yaml --files examples/runtimes/q/interpreter",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			text, files, err := runtimeSource(app, file, filesDir)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			name := file
			if name == "-" {
				name = "manifest"
			}
			v, err := c.ValidateRuntime(cmd.Context(), text, files)
			if problems := runr.ManifestProblems(err); problems != nil {
				perr := app.Printer().Print(map[string]any{"valid": false, "problems": problems}, func(t *output.TableWriter) {
					t.Note("%s: invalid", name)
					t.Header("PATH", "PROBLEM")
					for _, p := range problems {
						t.Row(p.Path, p.Message)
					}
				})
				if perr != nil {
					return perr
				}
				return &runr.APIError{StatusCode: 422, Method: "POST", Path: "/runtimes/validate",
					Message: fmt.Sprintf("%s is not a valid runtime manifest (%s)", name, countLabel(len(problems), "problem"))}
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(v, func(t *output.TableWriter) {
				if v.Manifest == nil {
					t.Note("%s: valid", name)
					return
				}
				t.Note("%s: valid (%s, %s mode, %d version(s))", name, v.Manifest.ID, v.Manifest.Run.Mode, len(v.Manifest.Versions))
			})
		},
	}
	val.Flags().StringVarP(&file, "file", "f", "", "the manifest (- reads stdin)")
	val.Flags().StringVar(&filesDir, "files", "", "directory whose files count as uploaded with the runtime")
	return val
}

func runtimesTemplateCmd(app *App) *cobra.Command {
	var serviceType, handler, outDir string
	var force bool
	tpl := &cobra.Command{
		Use:   "template RUNTIME",
		Short: "Print or write the starter files of a runtime",
		Example: "  runr runtimes template q\n" +
			"  runr runtimes template q --type faas --handler main.handler --out ./qfn",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if serviceType != runr.TypeFaaS && serviceType != runr.TypeXaaS {
				return usageErrorf("--type must be faas or xaas")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			files, err := c.RuntimeTemplate(cmd.Context(), args[0], serviceType, handler)
			if runr.IsNotFound(err) {
				return &runr.NotFoundError{Kind: "runtime", Ref: args[0]}
			}
			if err != nil {
				return err
			}
			names := make([]string, 0, len(files))
			for n := range files {
				names = append(names, n)
			}
			sort.Strings(names)
			if outDir != "" {
				for _, n := range names {
					if strings.ContainsAny(n, `/\`) {
						return fmt.Errorf("the template file name %q is not a plain file name", n)
					}
					p := filepath.Join(outDir, n)
					if _, err := os.Stat(p); err == nil && !force {
						return usageErrorf("%s exists; pass --force to overwrite", p)
					}
				}
				if err := os.MkdirAll(outDir, 0o755); err != nil {
					return err
				}
				var written []string
				for _, n := range names {
					p := filepath.Join(outDir, n)
					if err := os.WriteFile(p, []byte(files[n]), 0o644); err != nil {
						return err
					}
					written = append(written, p)
				}
				return app.Printer().Print(map[string]any{"files": written}, func(t *output.TableWriter) {
					for _, w := range written {
						t.Note("created %s", w)
					}
				})
			}
			return app.Printer().Print(map[string]any{"files": files}, func(t *output.TableWriter) {
				for i, n := range names {
					if i > 0 {
						t.Note("")
					}
					t.Note("==> %s <==", n)
					t.Note("%s", strings.TrimRight(files[n], "\n"))
				}
			})
		},
	}
	f := tpl.Flags()
	f.StringVar(&serviceType, "type", runr.TypeXaaS, "project type: xaas (one script) or faas (a set of files)")
	f.StringVar(&handler, "handler", "", "handler: the function (xaas) or file.function (faas); default the runtime's")
	f.StringVar(&outDir, "out", "", "write the files into this directory instead of printing them")
	f.BoolVar(&force, "force", false, "overwrite existing files with --out")
	return tpl
}

func runtimeBuildsTable(t *output.TableWriter, list []runr.RuntimeBuild) {
	t.Header("VERSION", "REGION", "STATUS", "IMAGE", "ERROR", "UPDATED")
	for _, b := range list {
		region := b.RegionName
		if region == "" {
			region = b.RegionID
		}
		t.Row(b.Version, region, b.Status, output.Dash(b.Image), output.Dash(output.Truncate(b.Error, 60)), output.Dash(b.UpdatedAt))
	}
	if len(list) == 0 {
		t.Note("No region builds: the runtime's versions name their images.")
	}
}

func runtimesBuildsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "builds RUNTIME",
		Short: "List the region image builds of a runtime's dockerfile and git versions",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			list, err := c.RuntimeBuilds(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			if list == nil {
				list = []runr.RuntimeBuild{}
			}
			return app.Printer().Print(list, func(t *output.TableWriter) { runtimeBuildsTable(t, list) })
		},
	}
}

func runtimesBuildCmd(app *App) *cobra.Command {
	var region, version string
	var wait bool
	var timeout time.Duration
	b := &cobra.Command{
		Use:   "build RUNTIME",
		Short: "Rebuild the image of a runtime's dockerfile and git versions in a region",
		Example: "  runr runtimes build q --region eu-1 --wait\n" +
			"  runr runtimes build q --region eu-1 --version 1.0",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.FindRegion(cmd.Context(), region)
			if err != nil {
				return err
			}
			list, err := c.BuildRuntime(cmd.Context(), args[0], r.ID, version)
			if err != nil {
				return err
			}
			app.Progress("Building %s in %s.", args[0], r.Name)
			if wait {
				list, err = c.WaitRuntimeBuilds(cmd.Context(), args[0], r.ID, version, app.waitOptions(timeout, "runtime image build"))
				if list != nil {
					_ = app.Printer().Print(list, func(t *output.TableWriter) { runtimeBuildsTable(t, list) })
				}
				return err
			}
			return app.Printer().Print(list, func(t *output.TableWriter) { runtimeBuildsTable(t, list) })
		},
	}
	f := b.Flags()
	f.StringVar(&region, "region", "", "region name or id (default: the only active region)")
	f.StringVar(&version, "version", "", "only this version (default: every dockerfile and git version)")
	f.BoolVar(&wait, "wait", false, "wait until the builds are ready (a failed build exits with code 6)")
	f.DurationVar(&timeout, "timeout", 15*time.Minute, "how long --wait waits")
	return b
}

func runtimesTestDeployCmd(app *App) *cobra.Command {
	var region, version string
	td := &cobra.Command{
		Use:   "test-deploy RUNTIME",
		Short: "Deploy the runtime's starter template as a test project in a region",
		Long: "Create an xaas project rt-<runtime>-<random> with the HTTP trigger and the runtime's starter\n" +
			"template in a region; RunR builds it when needed and deploys it as soon as its version is ready.",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			r, err := c.FindRegion(cmd.Context(), region)
			if err != nil {
				return err
			}
			p, err := c.TestDeployRuntime(cmd.Context(), args[0], r.ID, version)
			if err != nil {
				return err
			}
			return app.Printer().Print(p, func(t *output.TableWriter) {
				t.KV("Project", p.Name)
				t.KV("Region", r.Name)
				if p.EndpointURL != "" {
					t.KV("URL", p.EndpointURL)
				}
				t.Note("")
				t.Note("Follow it with `runr status -p %s`; remove it with `runr projects delete %s`.", p.Name, p.Name)
			})
		},
	}
	td.Flags().StringVar(&region, "region", "", "region name or id (default: the only active region)")
	td.Flags().StringVar(&version, "version", "", "runtime version (default: the runtime's default)")
	return td
}
