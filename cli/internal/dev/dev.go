// Package dev runs a project locally in Docker on the executor image and wrapper the cluster
// uses: the code is copied to /app/code like the ConfigMap mount, RUNR_MODE / RUNR_HANDLER /
// RUNR_FILENAME are set like the Knative Service, dependencies are installed with the build's
// commands, and the container restarts when a file changes.
package dev

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
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/internal/projectfile"
	"github.com/brf-tech/runr/cli/internal/runtimes"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Docker runs docker commands; tests replace it.
type Docker interface {
	// Run runs `docker args…` to completion.
	Run(ctx context.Context, args []string, stdout, stderr io.Writer) error
	// Start runs `docker args…` in the background; Wait returns when it ends.
	Start(ctx context.Context, args []string, stdout, stderr io.Writer) (Process, error)
}

// Process is a started docker command.
type Process interface {
	Wait() error
}

// ExecDocker runs the docker CLI found on PATH (or Binary).
type ExecDocker struct {
	Binary string
}

func (d ExecDocker) bin() string {
	if d.Binary != "" {
		return d.Binary
	}
	if b := os.Getenv("RUNR_DOCKER"); b != "" {
		return b
	}
	return "docker"
}

// Run implements Docker.
func (d ExecDocker) Run(ctx context.Context, args []string, stdout, stderr io.Writer) error {
	cmd := exec.CommandContext(ctx, d.bin(), args...)
	cmd.Stdout, cmd.Stderr = stdout, stderr
	return cmd.Run()
}

// Start implements Docker.
func (d ExecDocker) Start(ctx context.Context, args []string, stdout, stderr io.Writer) (Process, error) {
	cmd := exec.CommandContext(ctx, d.bin(), args...)
	cmd.Stdout, cmd.Stderr = stdout, stderr
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return cmd, nil
}

// Options of a dev session.
type Options struct {
	Project *projectfile.File
	// Dir is the project directory (absolute).
	Dir string
	// Port on 127.0.0.1 the function is served on.
	Port int
	// Env is added to the container (runr.yaml env, dev.env, --env).
	Env map[string]string
	// Watch restarts the container when a file changes.
	Watch        bool
	PollInterval time.Duration
	Resolver     runtimes.Resolver
	Docker       Docker
	// CacheDir keeps dependencies and the session state; default: the user cache dir.
	CacheDir string
	Out, Err io.Writer
}

// Plan is what a dev session runs, derived from the project file and the runtime spec.
type Plan struct {
	Name      string
	Container string
	Image     string
	// Spec is nil for caas projects.
	Spec        *runtimes.Spec
	Port        int
	TargetPort  int
	Env         map[string]string
	SourceDir   string
	DepsDir     string
	CacheRoot   string
	DepsFile    string
	MainFile    string
	HandlerName string
	Trigger     string
	Command     []string
	Args        []string
	// Plugin is set for projects of runtime plugins (plugin.go).
	Plugin *PluginPlan
}

// State is written while a session runs, so `runr dev invoke` finds it.
type State struct {
	URL       string `json:"url"`
	Port      int    `json:"port"`
	Container string `json:"container"`
	Trigger   string `json:"trigger"`
	Queue     string `json:"queue,omitempty"`
	Dir       string `json:"dir"`
	StartedAt string `json:"started_at"`
	// Runtime is the runtime plugin of the session ("" for built-in runtimes and caas).
	Runtime string `json:"runtime,omitempty"`
	// ScheduleExec: a schedule tick runs the wrapper in the container (`docker exec`);
	// CloudEventSchedule: it is POSTed as the wrapper's schedule CloudEvent.
	ScheduleExec       bool `json:"schedule_exec,omitempty"`
	CloudEventSchedule bool `json:"cloudevent_schedule,omitempty"`
}

// SessionDir returns the cache directory of a project directory.
func SessionDir(cacheDir, projectDir string) (string, error) {
	if cacheDir == "" {
		base, err := os.UserCacheDir()
		if err != nil {
			return "", err
		}
		cacheDir = filepath.Join(base, "runr", "dev")
	}
	abs, err := filepath.Abs(projectDir)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256([]byte(strings.ToLower(abs)))
	return filepath.Join(cacheDir, hex.EncodeToString(sum[:])[:16]), nil
}

// MakePlan resolves the runtime and computes the container setup.
func MakePlan(ctx context.Context, o Options) (*Plan, error) {
	pf := o.Project
	root, err := SessionDir(o.CacheDir, o.Dir)
	if err != nil {
		return nil, err
	}
	port := o.Port
	if port == 0 && pf.Dev != nil && pf.Dev.Port > 0 {
		port = pf.Dev.Port
	}
	if port == 0 {
		port = 8080
	}
	p := &Plan{
		Name:      pf.Name,
		Container: "runr-dev-" + projectfile.Slug(pf.Name) + "-" + filepath.Base(root)[:6],
		Port:      port,
		SourceDir: o.Dir,
		CacheRoot: root,
		DepsDir:   filepath.Join(root, "deps"),
		Trigger:   pf.TriggerType(),
		Env:       map[string]string{},
	}
	for k, v := range pf.Env {
		p.Env[k] = v
	}
	if pf.Dev != nil {
		for k, v := range pf.Dev.Env {
			p.Env[k] = v
		}
	}
	for k, v := range o.Env {
		p.Env[k] = v
	}

	if pf.Type == runr.TypeCaaS {
		if pf.Image == "" {
			return nil, errors.New("runr.yaml has no image for this caas project")
		}
		p.Image = pf.Image
		p.TargetPort = 80
		if len(pf.Ports) > 0 {
			p.TargetPort = pf.Ports[0]
		}
		p.Command = pf.Command
		p.Args = pf.Args
		return p, nil
	}

	spec, err := o.Resolver.Resolve(ctx, pf.Runtime, pf.RuntimeVersion)
	if err != nil {
		return nil, err
	}
	if spec.Plugin != nil {
		return makePluginPlan(p, spec, o)
	}
	p.Spec = spec
	p.Image = spec.Image
	p.TargetPort = spec.Port
	p.MainFile = pf.MainFile()
	p.HandlerName = pf.HandlerName()
	if spec.DependencyFile != "" {
		if _, err := os.Stat(filepath.Join(o.Dir, spec.DependencyFile)); err == nil {
			p.DepsFile = spec.DependencyFile
		}
	}
	if _, err := os.Stat(filepath.Join(o.Dir, p.MainFile)); err != nil {
		return nil, fmt.Errorf("the handler file %s does not exist in %s", p.MainFile, o.Dir)
	}
	return p, nil
}

// copyScript copies the project into the code directory, without dependency folders (they
// are mounted), and starts the wrapper.
func (p *Plan) copyScript() string {
	s := p.Spec
	var b strings.Builder
	b.WriteString("set -e; mkdir -p " + s.CodeDir + "; cd /src; ")
	b.WriteString("for f in * .[!.]* ..?*; do [ -e \"$f\" ] || continue; case \"$f\" in .git|.runr|node_modules|vendor|__pycache__|.venv) continue;; esac; cp -R \"$f\" " + s.CodeDir + "/; done; ")
	b.WriteString("cd " + s.Workdir + "; ")
	if s.PrepareScript != "" {
		b.WriteString(s.PrepareScript + "; ")
	}
	b.WriteString("exec " + s.Entrypoint)
	return b.String()
}

// RunArgs returns the `docker run` arguments of the session container.
func (p *Plan) RunArgs() []string {
	if p.Plugin != nil {
		return p.pluginRunArgs()
	}
	args := []string{"run", "--rm", "--name", p.Container,
		"-p", fmt.Sprintf("127.0.0.1:%d:%d", p.Port, p.TargetPort),
		"--label", "sh.runr.dev=1", "--label", "sh.runr.project=" + p.Name}
	env := map[string]string{}
	for k, v := range p.Env {
		env[k] = v
	}
	if p.Spec != nil {
		// What the Knative Service sets (ContainerManifests::code); the dev server always
		// serves HTTP so schedule and queue functions can be invoked with `runr dev invoke`.
		env["RUNR_MODE"] = "http"
		env["RUNR_HANDLER"] = p.HandlerName
		env["RUNR_FILENAME"] = p.MainFile
		env["PORT"] = strconv.Itoa(p.TargetPort)
		args = append(args, "--mount", "type=bind,source="+p.SourceDir+",target=/src,readonly")
		if p.DepsFile != "" && p.Spec.DepsMount != "" {
			args = append(args, "--mount", "type=bind,source="+p.depsMountSource()+",target="+p.Spec.DepsMount+",readonly")
			for k, v := range p.Spec.DepsEnv {
				env[k] = v
			}
		}
		for _, target := range sortedKeys(p.Spec.Caches) {
			args = append(args, "--mount", "type=bind,source="+filepath.Join(p.CacheRoot, "cache", p.Spec.Caches[target])+",target="+target)
		}
	}
	for _, k := range sortedKeys(env) {
		args = append(args, "-e", k+"="+env[k])
	}
	if p.Spec != nil {
		args = append(args, "--entrypoint", "sh", p.Image, "-c", p.copyScript())
		return args
	}
	if len(p.Command) > 0 {
		args = append(args, "--entrypoint", p.Command[0])
	}
	args = append(args, p.Image)
	if len(p.Command) > 1 {
		args = append(args, p.Command[1:]...)
	}
	return append(args, p.Args...)
}

// depsMountSource is the installed dependency directory mounted at Spec.DepsMount.
func (p *Plan) depsMountSource() string {
	switch p.Spec.DependencyFile {
	case "package.json":
		return filepath.Join(p.DepsDir, "node_modules")
	case "composer.json":
		return filepath.Join(p.DepsDir, "vendor")
	}
	return p.DepsDir
}

// InstallArgs returns the `docker run` arguments that install the dependencies.
func (p *Plan) InstallArgs() []string {
	return []string{"run", "--rm", "--name", p.Container + "-deps",
		"--mount", "type=bind,source=" + p.SourceDir + ",target=/src,readonly",
		"--mount", "type=bind,source=" + p.DepsDir + ",target=/deps",
		"--entrypoint", "sh", p.Spec.BuilderImage, "-c", p.Spec.InstallScript}
}

// depsHash identifies the dependency file content and the image it was installed for.
func (p *Plan) depsHash() (string, error) {
	data, err := os.ReadFile(filepath.Join(p.SourceDir, p.DepsFile))
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(append(data, []byte("\x00"+p.Spec.BuilderImage)...))
	return hex.EncodeToString(sum[:]), nil
}

// EnsureDeps installs the dependencies when the dependency file changed since the last install.
func EnsureDeps(ctx context.Context, d Docker, p *Plan, out, errOut io.Writer) error {
	if p.Spec == nil || p.DepsFile == "" {
		return nil
	}
	hash, err := p.depsHash()
	if err != nil {
		return err
	}
	stamp := filepath.Join(p.CacheRoot, "deps.sha256")
	if prev, err := os.ReadFile(stamp); err == nil && strings.TrimSpace(string(prev)) == hash {
		return nil
	}
	if err := os.RemoveAll(p.DepsDir); err != nil {
		return err
	}
	if err := os.MkdirAll(p.DepsDir, 0o755); err != nil {
		return err
	}
	fmt.Fprintf(errOut, "Installing dependencies from %s in %s\n", p.DepsFile, p.Spec.BuilderImage)
	if err := d.Run(ctx, p.InstallArgs(), out, errOut); err != nil {
		return fmt.Errorf("dependency install failed: %w", err)
	}
	return os.WriteFile(stamp, []byte(hash+"\n"), 0o644)
}

// Snapshot fingerprints the project files (path, size, modification time).
func Snapshot(dir string, ignore []string) (string, error) {
	var lines []string
	err := filepath.WalkDir(dir, func(p string, d os.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		rel, _ := filepath.Rel(dir, p)
		if rel == "." {
			return nil
		}
		if projectfile.Ignored(rel, ignore) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		if d.IsDir() {
			return nil
		}
		info, err := d.Info()
		if err != nil {
			return nil
		}
		lines = append(lines, fmt.Sprintf("%s|%d|%d", filepath.ToSlash(rel), info.Size(), info.ModTime().UnixNano()))
		return nil
	})
	if err != nil {
		return "", err
	}
	sort.Strings(lines)
	sum := sha256.Sum256([]byte(strings.Join(lines, "\n")))
	return hex.EncodeToString(sum[:]), nil
}

// watchIgnore are the paths a change of which does not restart the session.
var watchIgnore = []string{".git", ".runr", "node_modules", "vendor", "__pycache__", "*.pyc", ".venv", ".DS_Store", "*.swp", "*~"}

// Run starts the session and blocks until ctx is cancelled.
func Run(ctx context.Context, o Options) error {
	if o.Docker == nil {
		o.Docker = ExecDocker{}
	}
	if o.PollInterval <= 0 {
		o.PollInterval = 700 * time.Millisecond
	}
	plan, err := MakePlan(ctx, o)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(plan.CacheRoot, 0o755); err != nil {
		return err
	}
	if plan.Spec != nil {
		for _, c := range plan.Spec.Caches {
			if err := os.MkdirAll(filepath.Join(plan.CacheRoot, "cache", c), 0o755); err != nil {
				return err
			}
		}
	}
	url := fmt.Sprintf("http://localhost:%d", plan.Port)
	state := State{URL: url, Port: plan.Port, Container: plan.Container, Trigger: plan.Trigger, Dir: o.Dir,
		StartedAt: time.Now().UTC().Format(time.RFC3339)}
	if o.Project.Trigger != nil {
		state.Queue = o.Project.Trigger.Queue
	}
	if pp := plan.Plugin; pp != nil {
		state.Runtime, state.ScheduleExec, state.CloudEventSchedule = pp.Runtime, pp.ScheduleExec, pp.CloudEventSchedule
	}
	if err := writeState(plan.CacheRoot, state); err != nil {
		return err
	}
	defer os.Remove(filepath.Join(plan.CacheRoot, "state.json"))

	// A container left over from an interrupted session holds the name and the port.
	_ = o.Docker.Run(context.Background(), []string{"rm", "-f", plan.Container}, io.Discard, io.Discard)

	ignore := append(append([]string{}, watchIgnore...), o.Project.Ignore...)
	snap, _ := Snapshot(o.Dir, ignore)
	for {
		if err := Prepare(ctx, o.Docker, plan, o.Out, o.Err); err != nil {
			if ctx.Err() != nil {
				return nil
			}
			fmt.Fprintf(o.Err, "%v\n", err)
			if plan.Plugin != nil {
				// Without its image or code the container cannot start.
				if !o.Watch {
					return err
				}
				fmt.Fprintln(o.Err, "Waiting for a file change to try again")
				if !waitForChange(ctx, o.Dir, ignore, &snap, o.PollInterval) {
					return nil
				}
				if pf, err := projectfile.Load(filepath.Join(o.Dir, projectfile.FileName)); err == nil {
					o.Project = pf
					if np, err := MakePlan(ctx, o); err == nil {
						plan = np
					}
				}
				continue
			}
		}
		fmt.Fprintf(o.Err, "Starting %s on %s (%s)\n", plan.Name, url, plan.Image)
		if plan.Trigger != runr.TriggerHTTP {
			fmt.Fprintf(o.Err, "Trigger %s: invoke it with `runr dev invoke --event '{...}'`\n", plan.Trigger)
		}
		proc, err := o.Docker.Start(ctx, plan.RunArgs(), o.Out, o.Err)
		if err != nil {
			return fmt.Errorf("start docker: %w", err)
		}
		exited := make(chan error, 1)
		go func() { exited <- proc.Wait() }()

		ticker := time.NewTicker(o.PollInterval)
	wait:
		for {
			select {
			case <-ctx.Done():
				ticker.Stop()
				stop(o.Docker, plan.Container)
				<-exited
				return nil
			case err := <-exited:
				ticker.Stop()
				if ctx.Err() != nil {
					return nil
				}
				if !o.Watch {
					if err != nil {
						return fmt.Errorf("the container stopped: %w", err)
					}
					return nil
				}
				fmt.Fprintf(o.Err, "The container stopped (%v); waiting for a file change to restart\n", errOrExit(err))
				if !waitForChange(ctx, o.Dir, ignore, &snap, o.PollInterval) {
					return nil
				}
				break wait
			case <-ticker.C:
				if !o.Watch {
					continue
				}
				cur, err := Snapshot(o.Dir, ignore)
				if err != nil || cur == snap {
					continue
				}
				snap = cur
				ticker.Stop()
				fmt.Fprintln(o.Err, "Change detected, restarting")
				if pf, err := projectfile.Load(filepath.Join(o.Dir, projectfile.FileName)); err == nil {
					o.Project = pf
					if np, err := MakePlan(ctx, o); err == nil {
						plan = np
					} else {
						fmt.Fprintf(o.Err, "%v\n", err)
					}
				}
				stop(o.Docker, plan.Container)
				<-exited
				break wait
			}
		}
	}
}

func errOrExit(err error) string {
	if err == nil {
		return "exit 0"
	}
	return err.Error()
}

func waitForChange(ctx context.Context, dir string, ignore []string, snap *string, every time.Duration) bool {
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return false
		case <-t.C:
			cur, err := Snapshot(dir, ignore)
			if err == nil && cur != *snap {
				*snap = cur
				return true
			}
		}
	}
}

func stop(d Docker, name string) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	_ = d.Run(ctx, []string{"rm", "-f", name}, io.Discard, io.Discard)
}

func writeState(root string, s State) error {
	data, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(root, "state.json"), data, 0o644)
}

// ReadState returns the running session of a project directory.
func ReadState(cacheDir, projectDir string) (*State, error) {
	root, err := SessionDir(cacheDir, projectDir)
	if err != nil {
		return nil, err
	}
	data, err := os.ReadFile(filepath.Join(root, "state.json"))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil, errors.New("no `runr dev` session runs for this project (start one, or pass --url)")
		}
		return nil, err
	}
	var s State
	if err := json.Unmarshal(data, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

// compactJSON validates and compacts an event.
func compactJSON(event string) ([]byte, error) {
	if strings.TrimSpace(event) == "" {
		return nil, nil
	}
	var buf bytes.Buffer
	if err := json.Compact(&buf, []byte(event)); err != nil {
		return nil, fmt.Errorf("--event is not valid JSON: %w", err)
	}
	return buf.Bytes(), nil
}
