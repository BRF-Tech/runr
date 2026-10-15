package dev

// Runtime plugins in `runr dev`: the project runs the way the cluster runs it
// (api RuntimeContainerSpec, FunctionBuildService):
//
//   - The container image is the runtime version's image. A version RunR builds per region
//     (dockerfile, git) is built locally from the same source with `docker build`.
//   - exec and persistent runtimes run RunR's universal wrapper as the entrypoint. The cluster
//     copies it into the pod with an init container (`runr-wrapper install`); runr dev runs the
//     same install from the wrapper image into a cache directory and mounts it at /runr.
//   - Code-mounted functions get their files at /app/code (the cluster's ConfigMap): the files
//     `runr deploy` uploads, copied into the cache and mounted read-only.
//   - Compiled runtimes (build) and FaaS projects with the dependency file are built into an
//     image with the two-stage Dockerfile the cluster's Kaniko build uses; the wrapper is baked in.
//   - The environment is the cluster's: RUNR_TRIGGER, RUNR_HANDLER, RUNR_FILENAME, RUNR_CODE_DIR,
//     RUNR_RUNTIME, RUNR_RUNTIME_VERSION, RUNR_TIMEOUT, run.env, the wrapper variables
//     (RUNR_WRAPPER_MODE, RUNR_COMMAND, RUNR_INPUT, RUNR_WORKERS) and the project's variables,
//     which win. Like for built-in runtimes the dev server always serves HTTP.

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/internal/runtimes"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// EnvWrapperImage overrides the image of the universal wrapper (the API's RUNR_WRAPPER_IMAGE).
const EnvWrapperImage = "RUNR_WRAPPER_IMAGE"

// WrapperVersion is the release of the universal wrapper the platform installs by default.
const WrapperVersion = "1.1.0"

// WrapperPath is where the wrapper lives in a function container (RuntimeManifest::WRAPPER_PATH).
const WrapperPath = "/runr/runr-wrapper"

// WrapperImage returns the image of the universal wrapper: RUNR_WRAPPER_IMAGE, else
// <registry>/runr-wrapper:<WrapperVersion> (RUNR_RUNTIME_REGISTRY applies).
func WrapperImage() string {
	if v := strings.TrimSpace(os.Getenv(EnvWrapperImage)); v != "" {
		return v
	}
	return runtimes.Registry() + "/runr-wrapper:" + WrapperVersion
}

// PluginPlan is what a project of a runtime plugin runs.
type PluginPlan struct {
	Runtime string
	Version string
	// Mode is the runtime's run mode: http, exec or persistent.
	Mode string
	// Wrapper is set when the universal wrapper is the container's entrypoint (exec, persistent).
	Wrapper      bool
	WrapperImage string
	// WrapperDir receives the wrapper binary (the init container's emptyDir).
	WrapperDir string
	// Command is the runtime command with the placeholders resolved.
	Command []string
	// RuntimeImage is the version's image, or the tag of its local build (RuntimeBuild).
	RuntimeImage string
	RuntimeBuild *ImageBuild
	// FunctionBuild builds the function into an image (compiled runtimes, dependencies);
	// nil for code-mounted functions. Dockerfile is the file it builds.
	FunctionBuild *ImageBuild
	Dockerfile    string
	// Env is the contract environment of the container (the project's variables are added on
	// top by RunArgs).
	Env map[string]string
	// ScheduleExec: `runr dev invoke --trigger schedule` runs the wrapper's schedule tick in the
	// running container, like the CronJob does. CloudEventSchedule: http runtimes get the tick
	// CloudEvent the wrapper sends a runtime's server.
	ScheduleExec       bool
	CloudEventSchedule bool

	serviceType string
	mainFile    string
	ignore      []string
	usesWrapper bool
	buildFiles  map[string]string
}

// CodeMounted reports whether the code is mounted into the runtime image (no function build).
func (pp *PluginPlan) CodeMounted() bool { return pp.FunctionBuild == nil }

// needsWrapperBinary reports whether the wrapper binary must be in WrapperDir: mounted into a
// code-mounted function, or baked into a function build.
func (pp *PluginPlan) needsWrapperBinary() bool {
	if pp.CodeMounted() {
		return pp.Wrapper
	}
	return pp.usesWrapper
}

// WrapperInstallArgs are the `docker run` arguments that copy the wrapper into WrapperDir — the
// command of the cluster's init container.
func (pp *PluginPlan) WrapperInstallArgs() []string {
	return []string{"run", "--rm", "--mount", "type=bind,source=" + pp.WrapperDir + ",target=/runr",
		pp.WrapperImage, "install", WrapperPath}
}

// ImageBuild is a local `docker build`.
type ImageBuild struct {
	// What is built, for messages ("runtime image q 1.0").
	What string
	Tag  string
	// Context is a directory, or a Git URL (url#ref:path).
	Context    string
	Dockerfile string
	BuildArgs  map[string]string
	// Files are written into the Context directory before the build.
	Files map[string]string
	// Cached reuses an existing image with Tag (content-addressed runtime images).
	Cached bool
}

// Args returns the `docker build` arguments.
func (b *ImageBuild) Args() []string {
	args := []string{"build", "-t", b.Tag, "--label", "sh.runr.dev=1"}
	if b.Dockerfile != "" {
		args = append(args, "-f", b.Dockerfile)
	}
	for _, k := range sortedKeys(b.BuildArgs) {
		args = append(args, "--build-arg", k+"="+b.BuildArgs[k])
	}
	return append(args, b.Context)
}

// pluginEntrypoint returns the handler file and function of a project (api ProjectEntrypoint).
func pluginEntrypoint(pf *projectfile.File, m *runr.RuntimeManifest) (string, string) {
	if pf.Type == runr.TypeXaaS {
		file, fn := pf.File, pf.Handler
		if file == "" {
			file = m.DefaultFilename()
		}
		if fn == "" {
			fn = m.HandlerFunction()
		}
		return file, fn
	}
	h := pf.Handler
	if h == "" {
		file := m.Handler.File
		if file == "" {
			file = "main"
		}
		h = file + "." + m.HandlerFunction()
	}
	parts := strings.Split(h, ".")
	if len(parts) >= 2 && parts[0] != "" {
		fn := parts[1]
		if fn == "" {
			fn = m.HandlerFunction()
		}
		return parts[0] + "." + m.Extension(), fn
	}
	fn := parts[0]
	if fn == "" {
		fn = m.HandlerFunction()
	}
	return m.DefaultFilename(), fn
}

// makePluginPlan completes p for a runtime plugin.
func makePluginPlan(p *Plan, spec *runtimes.Spec, o Options) (*Plan, error) {
	pf := o.Project
	pl := spec.Plugin
	m := pl.Manifest
	p.Spec = spec
	p.TargetPort = spec.Port
	if p.TargetPort == 0 {
		p.TargetPort = 8080
	}
	file, handler := pluginEntrypoint(pf, m)
	p.MainFile, p.HandlerName = file, handler
	if _, err := os.Stat(filepath.Join(o.Dir, file)); err != nil {
		return nil, fmt.Errorf("the handler file %s does not exist in %s", file, o.Dir)
	}
	if len(m.Triggers) > 0 && !containsString(m.Triggers, p.Trigger) {
		return nil, fmt.Errorf("the runtime %s does not support the %s trigger (it supports %s)", m.ID, p.Trigger, strings.Join(m.Triggers, ", "))
	}

	pp := &PluginPlan{Runtime: m.ID, Version: spec.Version, Mode: m.Run.Mode, WrapperImage: WrapperImage(),
		serviceType: pf.Type, mainFile: file, ignore: pf.Ignore, usesWrapper: m.UsesWrapper()}
	pp.Wrapper = m.Run.Mode == "exec" || m.Run.Mode == "persistent"
	pp.WrapperDir = filepath.Join(filepath.Dir(p.CacheRoot), "wrapper", shortHash(pp.WrapperImage))
	pp.ScheduleExec = pp.Wrapper
	pp.CloudEventSchedule = !pp.Wrapper

	// The runtime version's image, or a local build of its source.
	v := pl.Version
	switch {
	case spec.Image != "":
		pp.RuntimeImage = spec.Image
	case v.Dockerfile != "" || v.Git != nil:
		b, err := runtimeImageBuild(filepath.Dir(p.CacheRoot), m.ID, v, pl.Files)
		if err != nil {
			return nil, err
		}
		pp.RuntimeBuild = b
		pp.RuntimeImage = b.Tag
	default:
		return nil, fmt.Errorf("version %s of the runtime %s names no image", v.Version, m.ID)
	}

	values := map[string]string{"version": spec.Version, "file": file, "handler": handler, "mode": "http", "trigger": p.Trigger}
	for _, part := range m.Run.Command {
		pp.Command = append(pp.Command, renderPlaceholders(part, values))
	}

	serverTrigger := p.Trigger
	if pp.Wrapper && serverTrigger != runr.TriggerQueue {
		// The dev server serves HTTP; a schedule tick runs through `runr dev invoke`.
		serverTrigger = runr.TriggerHTTP
	}
	timeout := 30
	if pf.Resources != nil && pf.Resources.TimeoutSeconds > 0 {
		timeout = pf.Resources.TimeoutSeconds
	}
	env := map[string]string{
		"RUNR_MODE": "http", "RUNR_TRIGGER": serverTrigger, "RUNR_HANDLER": handler, "RUNR_FILENAME": file,
		"RUNR_CODE_DIR": "/app/code", "RUNR_RUNTIME": m.ID, "RUNR_RUNTIME_VERSION": spec.Version,
		"RUNR_TIMEOUT": strconv.Itoa(timeout),
	}
	for k, val := range m.Run.Env {
		if _, set := env[k]; !set {
			env[k] = renderPlaceholders(val, values)
		}
	}
	if pp.Wrapper {
		for k, val := range wrapperEnv(m, pp.Command) {
			if _, set := env[k]; !set {
				env[k] = val
			}
		}
	}
	pp.Env = env

	// Compiled runtimes and FaaS projects with the dependency file are built into an image.
	hasDepFile := false
	if m.Dependencies != nil && m.Dependencies.File != "" && pf.Type == runr.TypeFaaS {
		if _, err := os.Stat(filepath.Join(o.Dir, m.Dependencies.File)); err == nil {
			hasDepFile = true
		}
	}
	if m.Build != nil || hasDepFile {
		bv := map[string]string{"version": spec.Version, "image": pp.RuntimeImage, "file": file, "handler": handler,
			"mode": "http", "trigger": p.Trigger}
		pp.Dockerfile = functionDockerfile(m, bv, hasDepFile)
		pp.buildFiles = map[string]string{}
		if m.Build != nil {
			for name, f := range m.Build.Files {
				pp.buildFiles[name] = f.For("http")
			}
		}
		ctxDir := filepath.Join(p.CacheRoot, "build")
		pp.FunctionBuild = &ImageBuild{What: "the function", Tag: p.Container + ":dev", Context: ctxDir,
			Dockerfile: filepath.Join(ctxDir, "Dockerfile")}
		p.Image = pp.FunctionBuild.Tag
	} else {
		p.Image = pp.RuntimeImage
	}
	p.Plugin = pp
	return p, nil
}

// wrapperEnv is RuntimeContainerSpec::wrapperEnv: the wrapper's mode, command and options.
func wrapperEnv(m *runr.RuntimeManifest, command []string) map[string]string {
	if command == nil {
		command = []string{}
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(command)
	env := map[string]string{"RUNR_WRAPPER_MODE": m.Run.Mode, "RUNR_COMMAND": strings.TrimSpace(buf.String())}
	if m.Run.Mode == "exec" {
		input := m.Run.Input
		if input == "" {
			input = "json"
		}
		env["RUNR_INPUT"] = input
	}
	if m.Run.Mode == "persistent" {
		workers := m.Run.Workers
		if workers <= 0 {
			workers = 1
		}
		env["RUNR_WORKERS"] = strconv.Itoa(workers)
	}
	return env
}

// runtimeImageBuild is the local build of a dockerfile or git version (the cluster builds it
// per region with Kaniko). The tag carries a digest of the source, so an unchanged source
// reuses the image.
func runtimeImageBuild(cacheDir, id string, v *runr.ManifestVersion, files map[string]string) (*ImageBuild, error) {
	h := sha256.New()
	spec, _ := json.Marshal(v)
	h.Write(spec)
	for _, name := range sortedKeys(files) {
		fmt.Fprintf(h, "\x00%s\x00%s", name, files[name])
	}
	digest := hex.EncodeToString(h.Sum(nil))[:12]
	b := &ImageBuild{What: "runtime image " + id + " " + v.Version, Tag: "runr-dev-runtime-" + id + ":" + v.Version + "-" + digest,
		BuildArgs: map[string]string(v.BuildArgs), Cached: true}
	if v.Git != nil {
		ctx := v.Git.URL
		frag := v.Git.Ref
		if p := strings.Trim(v.Git.Path, "/"); p != "" {
			frag += ":" + p
		}
		if frag != "" {
			ctx += "#" + frag
		}
		b.Context = ctx
		b.Dockerfile = v.Git.Dockerfile
		if b.Dockerfile == "" {
			b.Dockerfile = "Dockerfile"
		}
		return b, nil
	}
	if _, ok := files[v.Dockerfile]; !ok {
		return nil, fmt.Errorf("version %s of the runtime %s builds from %s, which is not among the runtime's files", v.Version, id, v.Dockerfile)
	}
	b.Context = filepath.Join(cacheDir, "runtimes", id+"-"+v.Version+"-"+digest)
	b.Dockerfile = filepath.Join(b.Context, v.Dockerfile)
	b.Files = files
	return b, nil
}

var plainEnvValue = regexp.MustCompile(`^[A-Za-z0-9_./:,=@+-]+$`)

// dockerfileEnvValue quotes an ENV value like FunctionBuildService::envValue.
func dockerfileEnvValue(v string) string {
	if plainEnvValue.MatchString(v) {
		return v
	}
	return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`, `$`, `\$`).Replace(v) + `"`
}

// functionDockerfile is the two-stage Dockerfile of a function build (FunctionBuildService::dockerfile).
func functionDockerfile(m *runr.RuntimeManifest, values map[string]string, hasDepFile bool) string {
	render := func(s string) string { return renderPlaceholders(s, values) }
	deps, build := m.Dependencies, m.Build
	hasDeps := deps != nil && (build == nil || hasDepFile)

	var steps []string
	if build != nil {
		steps = append(steps, "mkdir -p /build/out")
	}
	if hasDeps {
		steps = append(steps, render(deps.Install))
	}
	if build != nil {
		steps = append(steps, render(build.Command))
	}
	if len(steps) == 0 {
		steps = []string{"true"}
	}
	stage := "{image}"
	switch {
	case build != nil && build.Image != "":
		stage = build.Image
	case build == nil && deps != nil && deps.Image != "":
		stage = deps.Image
	}
	runImage := values["image"]
	if build != nil {
		ri := build.RunImage
		if ri == "" {
			ri = "{image}"
		}
		runImage = render(ri)
	}
	lines := []string{
		"FROM " + render(stage) + " AS build",
		"WORKDIR /build/code",
		"COPY code/ /build/code/",
		"RUN " + strings.Join(steps, " && "),
		"",
		"FROM " + runImage,
		"WORKDIR /app",
	}
	if build == nil || build.CopiesSource() {
		lines = append(lines, "COPY code/ /app/code/")
	}
	if hasDeps {
		for _, c := range deps.Copy {
			lines = append(lines, "COPY --from=build "+c.From+" "+c.To)
		}
	}
	if build != nil {
		lines = append(lines, "COPY --from=build /build/out/ /app/")
	}
	if m.UsesWrapper() {
		lines = append(lines, "COPY .runr/runr-wrapper "+WrapperPath)
	}

	type kv struct{ k, v string }
	var env []kv
	seen := map[string]bool{}
	add := func(k, v string) {
		if !seen[k] {
			seen[k] = true
			env = append(env, kv{k, v})
		}
	}
	add("RUNR_MODE", values["mode"])
	add("RUNR_HANDLER", values["handler"])
	add("RUNR_FILENAME", values["file"])
	if hasDeps {
		for _, k := range sortedKeys(deps.Env) {
			add(k, render(deps.Env[k]))
		}
	}
	for _, k := range sortedKeys(m.Run.Env) {
		add(k, render(m.Run.Env[k]))
	}
	var command []string
	for _, part := range m.Run.Command {
		command = append(command, render(part))
	}
	if m.Run.Mode != "http" {
		we := wrapperEnv(m, command)
		for _, k := range sortedKeys(we) {
			add(k, we[k])
		}
	}
	parts := make([]string, len(env))
	for i, e := range env {
		parts[i] = e.k + "=" + dockerfileEnvValue(e.v)
	}
	lines = append(lines, "ENV "+strings.Join(parts, " "), "EXPOSE 8080")

	entry := command
	if m.Run.Mode != "http" {
		entry = []string{WrapperPath}
	}
	if len(entry) > 0 {
		var buf bytes.Buffer
		enc := json.NewEncoder(&buf)
		enc.SetEscapeHTML(false)
		_ = enc.Encode(entry)
		lines = append(lines, "ENTRYPOINT "+strings.TrimSpace(buf.String()))
	}
	return strings.Join(lines, "\n") + "\n"
}

// pluginRunArgs returns the `docker run` arguments of a plugin project's container.
func (p *Plan) pluginRunArgs() []string {
	pp := p.Plugin
	args := []string{"run", "--rm", "--name", p.Container,
		"-p", fmt.Sprintf("127.0.0.1:%d:%d", p.Port, p.TargetPort),
		"--label", "sh.runr.dev=1", "--label", "sh.runr.project=" + p.Name, "--label", "sh.runr.runtime=" + pp.Runtime}
	if pp.CodeMounted() {
		args = append(args, "--mount", "type=bind,source="+p.codeDir()+",target=/app/code,readonly")
		if pp.Wrapper {
			args = append(args, "--mount", "type=bind,source="+pp.WrapperDir+",target="+filepath.ToSlash(filepath.Dir(WrapperPath))+",readonly")
		}
	}
	// The contract environment first; the project's variables win (RuntimeContainerSpec::mergeEnv).
	env := map[string]string{}
	for k, v := range pp.Env {
		env[k] = v
	}
	for k, v := range p.Env {
		env[k] = v
	}
	env["PORT"] = strconv.Itoa(p.TargetPort)
	for _, k := range sortedKeys(env) {
		args = append(args, "-e", k+"="+env[k])
	}
	switch {
	case pp.Wrapper:
		args = append(args, "--entrypoint", WrapperPath, p.Image)
	case pp.CodeMounted() && len(pp.Command) > 0:
		// http runtime on its own image: the server command (a built image has it as ENTRYPOINT).
		args = append(args, "--entrypoint", pp.Command[0], p.Image)
		args = append(args, pp.Command[1:]...)
	default:
		args = append(args, p.Image)
	}
	return args
}

// codeDir is the cache directory mounted at /app/code (the cluster's ConfigMap).
func (p *Plan) codeDir() string { return filepath.Join(p.CacheRoot, "code") }

// PrepareArgs returns the docker commands that run before the container starts: the wrapper
// install, the runtime image build, the function build.
func (p *Plan) PrepareArgs() [][]string {
	if p.Plugin == nil {
		if p.DepsFile != "" {
			return [][]string{p.InstallArgs()}
		}
		return nil
	}
	pp := p.Plugin
	var out [][]string
	if pp.needsWrapperBinary() {
		out = append(out, pp.WrapperInstallArgs())
	}
	if pp.RuntimeBuild != nil {
		out = append(out, pp.RuntimeBuild.Args())
	}
	if pp.FunctionBuild != nil {
		out = append(out, pp.FunctionBuild.Args())
	}
	return out
}

// Prepare runs what a plan needs before its container starts: the dependency install of a
// built-in runtime, or the wrapper, images and code of a runtime plugin.
func Prepare(ctx context.Context, d Docker, p *Plan, out, errOut io.Writer) error {
	if p.Plugin == nil {
		return EnsureDeps(ctx, d, p, out, errOut)
	}
	pp := p.Plugin
	if pp.needsWrapperBinary() {
		if err := ensureWrapper(ctx, d, pp, out, errOut); err != nil {
			return err
		}
	}
	if pp.RuntimeBuild != nil {
		if err := ensureImage(ctx, d, pp.RuntimeBuild, out, errOut); err != nil {
			return err
		}
	}
	files, err := pluginCode(p)
	if err != nil {
		return err
	}
	if pp.CodeMounted() {
		return writeTree(p.codeDir(), files)
	}
	return buildFunction(ctx, d, p, files, out, errOut)
}

// pluginCode returns the files the cluster gets: what `runr deploy` uploads.
func pluginCode(p *Plan) (map[string]string, error) {
	pp := p.Plugin
	if pp.serviceType == runr.TypeXaaS {
		data, err := os.ReadFile(filepath.Join(p.SourceDir, pp.mainFile))
		if err != nil {
			return nil, err
		}
		return map[string]string{pp.mainFile: string(data)}, nil
	}
	list, err := projectfile.CollectFiles(p.SourceDir, pp.ignore)
	if err != nil {
		return nil, err
	}
	files := make(map[string]string, len(list))
	for _, f := range list {
		files[f.Name] = f.Content
	}
	return files, nil
}

func ensureWrapper(ctx context.Context, d Docker, pp *PluginPlan, out, errOut io.Writer) error {
	if _, err := os.Stat(filepath.Join(pp.WrapperDir, filepath.Base(WrapperPath))); err == nil {
		return nil
	}
	if err := os.MkdirAll(pp.WrapperDir, 0o755); err != nil {
		return err
	}
	fmt.Fprintf(errOut, "Installing the universal wrapper from %s\n", pp.WrapperImage)
	if err := d.Run(ctx, pp.WrapperInstallArgs(), out, errOut); err != nil {
		return fmt.Errorf("installing the wrapper from %s failed: %w", pp.WrapperImage, err)
	}
	return nil
}

func ensureImage(ctx context.Context, d Docker, b *ImageBuild, out, errOut io.Writer) error {
	if b.Cached && d.Run(ctx, []string{"image", "inspect", b.Tag}, io.Discard, io.Discard) == nil {
		return nil
	}
	if b.Files != nil {
		if err := writeTree(b.Context, b.Files); err != nil {
			return err
		}
	}
	fmt.Fprintf(errOut, "Building %s (%s)\n", b.What, b.Tag)
	if err := d.Run(ctx, b.Args(), out, errOut); err != nil {
		return fmt.Errorf("the build of %s failed: %w", b.What, err)
	}
	return nil
}

// buildFunction writes the build context (code/, the wrapper, the Dockerfile) and builds it.
func buildFunction(ctx context.Context, d Docker, p *Plan, files map[string]string, out, errOut io.Writer) error {
	pp := p.Plugin
	b := pp.FunctionBuild
	code := map[string]string{}
	for k, v := range pp.buildFiles {
		code[k] = v
	}
	for k, v := range files {
		code[k] = v
	}
	if err := writeTree(filepath.Join(b.Context, "code"), code); err != nil {
		return err
	}
	if pp.usesWrapper {
		bin, err := os.ReadFile(filepath.Join(pp.WrapperDir, filepath.Base(WrapperPath)))
		if err != nil {
			return fmt.Errorf("the wrapper binary is not in %s: %w", pp.WrapperDir, err)
		}
		if err := os.MkdirAll(filepath.Join(b.Context, ".runr"), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(filepath.Join(b.Context, ".runr", "runr-wrapper"), bin, 0o755); err != nil {
			return err
		}
	}
	if err := os.WriteFile(b.Dockerfile, []byte(pp.Dockerfile), 0o644); err != nil {
		return err
	}
	fmt.Fprintf(errOut, "Building %s (%s)\n", b.What, b.Tag)
	if err := d.Run(ctx, b.Args(), out, errOut); err != nil {
		return fmt.Errorf("the build of %s failed: %w", b.What, err)
	}
	return nil
}

// writeTree replaces dir with files (flat names).
func writeTree(dir string, files map[string]string) error {
	if err := os.RemoveAll(dir); err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	names := make([]string, 0, len(files))
	for n := range files {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		if strings.ContainsAny(n, `/\`) || n == ".." || n == "." {
			return errors.New("file name " + n + " is not a plain file name")
		}
		if err := os.WriteFile(filepath.Join(dir, n), []byte(files[n]), 0o644); err != nil {
			return err
		}
	}
	return nil
}

func shortHash(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])[:12]
}

func containsString(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
