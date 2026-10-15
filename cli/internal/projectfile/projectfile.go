// Package projectfile reads and writes runr.yaml, the project file of a RunR project
// directory, and turns it into API payloads.
package projectfile

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"

	"github.com/runrsh/core/cli/internal/runtimes"
	"github.com/runrsh/core/cli/pkg/runr"
)

// FileName is the project file name; runr.yml is accepted as well.
const FileName = "runr.yaml"

// File is runr.yaml.
type File struct {
	// Name of the project; its slug names the service and the default URL.
	Name string `yaml:"name"`
	// Type is faas (function, several files), xaas (single-file script) or caas (container).
	Type string `yaml:"type"`
	// Runtime and RuntimeVersion of faas/xaas projects (python, nodejs, go, php, …).
	Runtime        string `yaml:"runtime,omitempty"`
	RuntimeVersion string `yaml:"runtime_version,omitempty"`
	// Region name or id; may be omitted when exactly one region is available.
	Region      string `yaml:"region,omitempty"`
	Description string `yaml:"description,omitempty"`
	// Handler: faas "file.function" (main.handler → main.py, def handler); xaas the function name.
	Handler string `yaml:"handler,omitempty"`
	// File is the script of an xaas project (main.py).
	File string `yaml:"file,omitempty"`
	// Image, Command, Args and Ports of a caas project.
	Image   string   `yaml:"image,omitempty"`
	Command []string `yaml:"command,omitempty"`
	Args    []string `yaml:"args,omitempty"`
	Ports   []int    `yaml:"ports,omitempty"`

	Resources *Resources `yaml:"resources,omitempty"`
	Scale     *Scale     `yaml:"scale,omitempty"`
	Trigger   *Trigger   `yaml:"trigger,omitempty"`
	// Env holds non-secret environment variables. Secrets are set with `runr env set --secret`.
	Env map[string]string `yaml:"env,omitempty"`
	// Ignore lists extra glob patterns of files that are not uploaded.
	Ignore []string `yaml:"ignore,omitempty"`
	// Dev configures `runr dev`.
	Dev *Dev `yaml:"dev,omitempty"`

	path string
}

// Resources are the container limits.
type Resources struct {
	CPU            string `yaml:"cpu,omitempty"`
	MemoryMB       int    `yaml:"memory_mb,omitempty"`
	TimeoutSeconds int    `yaml:"timeout_seconds,omitempty"`
}

// Scale is the autoscaling range.
type Scale struct {
	Min    *int  `yaml:"min,omitempty"`
	Max    *int  `yaml:"max,omitempty"`
	ToZero *bool `yaml:"to_zero,omitempty"`
}

// Trigger says what starts the code: http (default), schedule (cron) or queue (Redis stream).
type Trigger struct {
	Type     string `yaml:"type"`
	Schedule string `yaml:"schedule,omitempty"`
	Queue    string `yaml:"queue,omitempty"`
}

// Dev configures the local runner.
type Dev struct {
	Port int               `yaml:"port,omitempty"`
	Env  map[string]string `yaml:"env,omitempty"`
}

// ErrNotFound means no runr.yaml was found.
var ErrNotFound = errors.New("no runr.yaml found (run `runr init` or pass --project)")

// Find looks for runr.yaml in dir and its parents.
func Find(dir string) (string, error) {
	abs, err := filepath.Abs(dir)
	if err != nil {
		return "", err
	}
	for {
		for _, n := range []string{FileName, "runr.yml"} {
			p := filepath.Join(abs, n)
			if st, err := os.Stat(p); err == nil && !st.IsDir() {
				return p, nil
			}
		}
		parent := filepath.Dir(abs)
		if parent == abs {
			return "", ErrNotFound
		}
		abs = parent
	}
}

// Load reads and validates a project file.
func Load(path string) (*File, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	f, err := Parse(data)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	f.path = path
	return f, nil
}

// Parse decodes and validates runr.yaml content.
func Parse(data []byte) (*File, error) {
	var f File
	dec := yaml.NewDecoder(strings.NewReader(string(data)))
	dec.KnownFields(true)
	if err := dec.Decode(&f); err != nil {
		return nil, &ValidationError{Problems: []string{err.Error()}}
	}
	f.Normalize()
	if err := f.Validate(); err != nil {
		return nil, err
	}
	return &f, nil
}

// Path returns the file the project was loaded from.
func (f *File) Path() string { return f.path }

// Dir returns the project directory.
func (f *File) Dir() string {
	if f.path == "" {
		return "."
	}
	return filepath.Dir(f.path)
}

// Normalize fills defaults that do not change meaning.
func (f *File) Normalize() {
	f.Type = strings.ToLower(strings.TrimSpace(f.Type))
	f.Runtime = strings.ToLower(strings.TrimSpace(f.Runtime))
	if f.Trigger != nil {
		f.Trigger.Type = strings.ToLower(strings.TrimSpace(f.Trigger.Type))
		if f.Trigger.Type == "" {
			f.Trigger.Type = runr.TriggerHTTP
		}
	}
}

// ValidationError lists every problem of a project file.
type ValidationError struct {
	Problems []string
}

func (e *ValidationError) Error() string {
	if len(e.Problems) == 1 {
		return "invalid runr.yaml: " + e.Problems[0]
	}
	return "invalid runr.yaml:\n  - " + strings.Join(e.Problems, "\n  - ")
}

var (
	slugPattern = regexp.MustCompile(`^[a-z]([-a-z0-9]*[a-z0-9])?$`)
	runtimeID   = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,62}$`)
)

// Slug derives the slug the API gives a project name (Laravel Str::slug for ASCII names).
func Slug(name string) string {
	var b strings.Builder
	dash := false
	for _, r := range strings.ToLower(strings.TrimSpace(name)) {
		switch {
		case (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9'):
			b.WriteRune(r)
			dash = false
		case r == '@':
			if b.Len() > 0 && !dash {
				b.WriteByte('-')
			}
			b.WriteString("at-")
			dash = true
		default:
			if b.Len() > 0 && !dash {
				b.WriteByte('-')
				dash = true
			}
		}
	}
	return strings.Trim(b.String(), "-")
}

// ValidateName checks a project name against the API's slug rule.
func ValidateName(name string) error {
	if strings.TrimSpace(name) == "" {
		return errors.New("name is required")
	}
	slug := Slug(name)
	if !slugPattern.MatchString(slug) {
		return fmt.Errorf("name %q gives the slug %q; it must start with a letter and contain letters, digits and dashes", name, slug)
	}
	if len(slug) > 44 {
		return fmt.Errorf("name %q gives a slug of %d characters; the limit is 44", name, len(slug))
	}
	return nil
}

// Validate checks the file without contacting the API.
func (f *File) Validate() error {
	var p []string
	if err := ValidateName(f.Name); err != nil {
		p = append(p, err.Error())
	}
	switch f.Type {
	case runr.TypeFaaS, runr.TypeXaaS:
		if f.Runtime == "" {
			p = append(p, "runtime is required for type "+f.Type)
		} else if !runtimeID.MatchString(f.Runtime) {
			// Built-in runtimes and runtime plugins; the platform checks availability.
			p = append(p, fmt.Sprintf("runtime %q is not a runtime id (lower-case letters, digits, - and _)", f.Runtime))
		}
		if f.Image != "" || len(f.Command) > 0 || len(f.Args) > 0 || len(f.Ports) > 0 {
			p = append(p, "image, command, args and ports apply to type caas only")
		}
		if f.Type == runr.TypeFaaS && f.Handler != "" {
			if base, fn, ok := strings.Cut(f.Handler, "."); !ok || base == "" || fn == "" {
				p = append(p, fmt.Sprintf("handler %q must be file.function, e.g. main.handler", f.Handler))
			}
		}
		if f.Type == runr.TypeFaaS && f.File != "" {
			p = append(p, "file applies to type xaas only (faas uses handler: file.function)")
		}
	case runr.TypeCaaS:
		if f.Runtime != "" || f.Handler != "" || f.File != "" {
			p = append(p, "runtime, handler and file do not apply to type caas")
		}
		for _, port := range f.Ports {
			if port < 1 || port > 65535 {
				p = append(p, fmt.Sprintf("port %d is out of range", port))
			}
		}
	case "":
		p = append(p, "type is required (faas, xaas or caas)")
	default:
		p = append(p, fmt.Sprintf("unknown type %q (faas, xaas or caas)", f.Type))
	}
	if r := f.Resources; r != nil {
		if r.MemoryMB != 0 && r.MemoryMB < 64 {
			p = append(p, "resources.memory_mb must be at least 64")
		}
		if r.TimeoutSeconds < 0 {
			p = append(p, "resources.timeout_seconds must be positive")
		}
		if r.CPU != "" && !regexp.MustCompile(`^([0-9]+m|[0-9]+(\.[0-9]+)?)$`).MatchString(r.CPU) {
			p = append(p, fmt.Sprintf("resources.cpu %q must be a Kubernetes CPU quantity (250m, 0.5, 1)", r.CPU))
		}
	}
	if s := f.Scale; s != nil {
		if s.Min != nil && (*s.Min < 0 || *s.Min > 10) {
			p = append(p, "scale.min must be between 0 and 10")
		}
		if s.Max != nil && (*s.Max < 1 || *s.Max > 1000) {
			p = append(p, "scale.max must be between 1 and 1000")
		}
		if s.Min != nil && s.Max != nil && *s.Min > *s.Max {
			p = append(p, "scale.min must not be greater than scale.max")
		}
	}
	if t := f.Trigger; t != nil {
		switch t.Type {
		case runr.TriggerHTTP:
			if t.Schedule != "" || t.Queue != "" {
				p = append(p, "trigger.schedule and trigger.queue need trigger.type schedule or queue")
			}
		case runr.TriggerSchedule:
			if len(strings.Fields(t.Schedule)) != 5 {
				p = append(p, "trigger.schedule must be a cron expression with five fields, e.g. \"*/5 * * * *\"")
			}
		case runr.TriggerQueue:
			if !regexp.MustCompile(`^[a-zA-Z0-9._-]{1,255}$`).MatchString(t.Queue) {
				p = append(p, "trigger.queue must name a Redis stream (letters, digits, . _ -)")
			}
		default:
			p = append(p, fmt.Sprintf("unknown trigger.type %q (http, schedule or queue)", t.Type))
		}
	}
	for _, k := range sortedKeys(f.Env) {
		if err := runr.ValidateEnvName(k); err != nil {
			p = append(p, "env: "+err.Error())
		}
	}
	if len(p) > 0 {
		return &ValidationError{Problems: p}
	}
	return nil
}

// MainFile is the file that holds the handler: faas "main.handler" → main.py, xaas File.
func (f *File) MainFile() string {
	ext := runtimes.Extension(f.Runtime)
	if f.Type == runr.TypeXaaS {
		if f.File != "" {
			return f.File
		}
		return "main." + ext
	}
	h := f.Handler
	if h == "" {
		h = "main.handler"
	}
	base, _, _ := strings.Cut(h, ".")
	if base == "" {
		base = "main"
	}
	return base + "." + ext
}

// HandlerName is the function the wrapper calls.
func (f *File) HandlerName() string {
	if f.Type == runr.TypeXaaS {
		if f.Handler != "" {
			return f.Handler
		}
		return "handler"
	}
	h := f.Handler
	if h == "" {
		h = "main.handler"
	}
	if _, fn, ok := strings.Cut(h, "."); ok && fn != "" {
		return fn
	}
	return h
}

// TriggerType returns the trigger type, http by default.
func (f *File) TriggerType() string {
	if f.Trigger == nil || f.Trigger.Type == "" {
		return runr.TriggerHTTP
	}
	return f.Trigger.Type
}

// Config returns the config the file asks for. Unset fields stay nil (server defaults).
func (f *File) Config() runr.ConfigInput {
	var c runr.ConfigInput
	switch f.Type {
	case runr.TypeFaaS:
		if f.Handler != "" {
			c.Handler = ptr(f.Handler)
		}
	case runr.TypeXaaS:
		if f.Handler != "" {
			c.Handler = ptr(f.Handler)
		}
		if f.File != "" {
			c.Filename = ptr(f.File)
		}
	case runr.TypeCaaS:
		if f.Image != "" {
			c.Image = ptr(f.Image)
		}
		if len(f.Command) > 0 {
			c.Command = ptr(append([]string(nil), f.Command...))
		}
		if len(f.Args) > 0 {
			c.Args = ptr(append([]string(nil), f.Args...))
		}
		if len(f.Ports) > 0 {
			c.Ports = ptr(append([]int(nil), f.Ports...))
		}
	}
	if r := f.Resources; r != nil {
		if r.CPU != "" {
			c.CPU = ptr(r.CPU)
		}
		if r.MemoryMB > 0 {
			c.MemoryMB = ptr(r.MemoryMB)
		}
		if r.TimeoutSeconds > 0 {
			c.TimeoutSeconds = ptr(r.TimeoutSeconds)
		}
	}
	if s := f.Scale; s != nil {
		c.MinScale = s.Min
		c.MaxScale = s.Max
		c.ScaleToZero = s.ToZero
	}
	if t := f.Trigger; t != nil {
		c.TriggerType = ptr(t.Type)
		if t.Schedule != "" {
			c.Schedule = ptr(t.Schedule)
		}
		if t.Queue != "" {
			c.QueueStream = ptr(t.Queue)
		}
	}
	if len(f.Env) > 0 {
		env := map[string]string{}
		for k, v := range f.Env {
			env[k] = v
		}
		c.EnvVars = &env
	}
	return c
}

// Diff returns the part of want that differs from the server config, and the names of the
// changed fields. Environment variables merge: keys in want replace, other server keys stay.
func Diff(want runr.ConfigInput, have runr.ProjectConfig) (runr.ConfigInput, []string) {
	var out runr.ConfigInput
	var changed []string
	str := func(name string, w *string, h string, set func(*string)) {
		if w != nil && *w != h {
			set(w)
			changed = append(changed, name)
		}
	}
	num := func(name string, w *int, h *int, set func(*int)) {
		if w != nil && (h == nil || *w != *h) {
			set(w)
			changed = append(changed, name)
		}
	}
	str("handler", want.Handler, have.Handler, func(v *string) { out.Handler = v })
	str("filename", want.Filename, have.Filename, func(v *string) { out.Filename = v })
	str("image", want.Image, have.Image, func(v *string) { out.Image = v })
	str("cpu", want.CPU, have.CPU, func(v *string) { out.CPU = v })
	str("trigger_type", want.TriggerType, have.Trigger(), func(v *string) { out.TriggerType = v })
	str("schedule", want.Schedule, have.Schedule, func(v *string) { out.Schedule = v })
	str("queue_stream", want.QueueStream, have.QueueStream, func(v *string) { out.QueueStream = v })
	num("memory_mb", want.MemoryMB, have.MemoryMB, func(v *int) { out.MemoryMB = v })
	num("timeout_seconds", want.TimeoutSeconds, have.TimeoutSeconds, func(v *int) { out.TimeoutSeconds = v })
	num("min_scale", want.MinScale, have.MinScale, func(v *int) { out.MinScale = v })
	num("max_scale", want.MaxScale, have.MaxScale, func(v *int) { out.MaxScale = v })
	if want.ScaleToZero != nil && (have.ScaleToZero == nil || *want.ScaleToZero != *have.ScaleToZero) {
		out.ScaleToZero = want.ScaleToZero
		changed = append(changed, "scale_to_zero")
	}
	if want.Command != nil && !equalStrings(*want.Command, have.Command) {
		out.Command = want.Command
		changed = append(changed, "command")
	}
	if want.Args != nil && !equalStrings(*want.Args, have.Args) {
		out.Args = want.Args
		changed = append(changed, "args")
	}
	if want.Ports != nil && !equalInts(*want.Ports, have.Ports) {
		out.Ports = want.Ports
		changed = append(changed, "ports")
	}
	if want.EnvVars != nil && have.EnvVarsHidden {
		// The variables cannot be read, so they cannot be merged: leave them alone (the
		// caller reports it).
		changed = append(changed, "env (hidden)")
	} else if want.EnvVars != nil {
		merged := map[string]string{}
		for k, v := range have.EnvVars {
			merged[k] = v
		}
		diff := false
		for k, v := range *want.EnvVars {
			if cur, ok := merged[k]; !ok || cur != v {
				diff = true
			}
			merged[k] = v
		}
		if diff {
			out.EnvVars = &merged
			changed = append(changed, "env")
		}
	}
	return out, changed
}

// Marshal renders a project file.
func (f *File) Marshal() ([]byte, error) {
	return yaml.Marshal(f)
}

func ptr[T any](v T) *T { return &v }

func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func equalStrings(a, b []string) bool {
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

func equalInts(a []int, b runr.FlexInts) bool {
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
