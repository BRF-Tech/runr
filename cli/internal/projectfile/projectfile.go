// Package projectfile reads and writes runr.yaml, the project file of a RunR project
// directory, and turns it into API payloads.
package projectfile

import (
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"

	"github.com/brf-tech/runr/cli/internal/runtimes"
	"github.com/brf-tech/runr/cli/pkg/runr"
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
	// Region name or id; may be omitted when exactly one region is available. In a global
	// project it is the primary region (default: the first of global.regions).
	Region string `yaml:"region,omitempty"`
	// Global makes the project a global project: it runs in every region of global.regions
	// behind one global host name (docs/global-projects.md).
	Global      *Global `yaml:"global,omitempty"`
	Description string  `yaml:"description,omitempty"`
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
	// Deploy sets the deploy strategy (direct, blue-green, canary) and its options (deploy.go).
	Deploy *Deploy `yaml:"deploy,omitempty"`
	// Triggers is the project's whole trigger list (docs/triggers.md), applied with PUT
	// /projects/{project}/triggers on deploy; it replaces trigger.
	Triggers []TriggerEntry `yaml:"triggers,omitempty"`
	// Env holds non-secret environment variables. Secrets are set with `runr env set --secret`.
	Env map[string]string `yaml:"env,omitempty"`
	// Network is who reaches the project: its access mode and the projects allowed to call it
	// on the region's internal network (docs/internal-network.md).
	Network *Network `yaml:"network,omitempty"`
	// Ignore lists extra glob patterns of files that are not uploaded.
	Ignore []string `yaml:"ignore,omitempty"`
	// Dev configures `runr dev`.
	Dev *Dev `yaml:"dev,omitempty"`

	path     string
	warnings []string
}

// Warnings are what Normalize left out or the API ignores without refusing the file: trigger
// next to triggers, secrets in a trigger entry, config keys a trigger type does not have.
func (f *File) Warnings() []string { return f.warnings }

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
	// Schedule is the timed warmer: from the moment a row's cron expression fires (in the
	// organization's time zone) at least Min containers stay warm until the next row fires; it
	// replaces scale.min while set. Absent leaves the server's schedule alone, [] turns it off.
	Schedule *[]ScheduleRow `yaml:"schedule,omitempty"`
}

// ScheduleRow is one row of scale.schedule.
type ScheduleRow struct {
	Cron string `yaml:"cron"`
	Min  int    `yaml:"min"`
}

// Trigger says what starts the code: http (default), schedule (cron) or queue (Redis stream).
type Trigger struct {
	Type     string `yaml:"type"`
	Schedule string `yaml:"schedule,omitempty"`
	Queue    string `yaml:"queue,omitempty"`
}

// Network: Access is public (default), allowlist (the public URL answers AllowCIDRs only) or
// internal (no public URL). Callers, when given, is the whole allow list: project names of the
// organization or internal names <project>.<organization>.
type Network struct {
	Access     string   `yaml:"access,omitempty"`
	AllowCIDRs []string `yaml:"allow_cidrs,omitempty"`
	Callers    []string `yaml:"callers,omitempty"`
}

var networkCaller = regexp.MustCompile(`^[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.[a-z0-9]([-a-z0-9]*[a-z0-9])?)?$`)

func (n *Network) problems() []string {
	var p []string
	switch n.Access {
	case "", runr.AccessPublic, runr.AccessInternal:
		if len(n.AllowCIDRs) > 0 {
			p = append(p, "network.allow_cidrs applies to network.access allowlist")
		}
	case runr.AccessAllowlist:
		if len(n.AllowCIDRs) == 0 {
			p = append(p, "network.access allowlist needs network.allow_cidrs")
		}
	default:
		p = append(p, fmt.Sprintf("unknown network.access %q (public, allowlist or internal)", n.Access))
	}
	for _, c := range n.AllowCIDRs {
		if net.ParseIP(c) == nil {
			if _, _, err := net.ParseCIDR(c); err != nil {
				p = append(p, fmt.Sprintf("network.allow_cidrs: %q is no IP address or CIDR range", c))
			}
		}
	}
	for _, c := range n.Callers {
		if !networkCaller.MatchString(strings.ToLower(strings.TrimSpace(c))) {
			p = append(p, fmt.Sprintf("network.callers: %q is no project name or <project>.<organization>", c))
		}
	}
	return p
}

// TriggerEntry is one entry of `triggers:`. Type, name and enabled are fields of the trigger;
// every other key goes into its config (cron, timezone, stream, verify, hmac, topic, events,
// delivery, …), checked against the API's trigger schema (triggers.schema.json). The shorthands
// of `trigger:` work too: schedule → cron (type schedule), queue → stream (type queue). Secrets
// (a webhook's signing secret) are set with `runr triggers update NAME --secret`, never in
// runr.yaml.
type TriggerEntry struct {
	Type    string         `yaml:"type"`
	Name    string         `yaml:"name,omitempty"`
	Enabled *bool          `yaml:"enabled,omitempty"`
	Config  map[string]any `yaml:",inline"`

	// badEnabled is an enabled value the API's boolean rule refuses ("yes", 2, …).
	badEnabled any
}

// UnmarshalYAML reads an entry the way the API reads it: type and name as strings, enabled as a
// boolean (true / false, 1 / 0, "1" / "0"), every other key as config.
func (t *TriggerEntry) UnmarshalYAML(n *yaml.Node) error {
	var m map[string]any
	if err := n.Decode(&m); err != nil {
		return err
	}
	*t = TriggerEntry{Config: map[string]any{}}
	for k, v := range m {
		switch k {
		case "type":
			t.Type = scalarString(v)
		case "name":
			t.Name = scalarString(v)
		case "enabled":
			if v == nil {
				continue
			}
			if b, ok := laravelBool(v); ok {
				t.Enabled = &b
			} else {
				t.badEnabled = v
			}
		default:
			t.Config[k] = v
		}
	}
	return nil
}

// scalarString is a YAML scalar as a string ("" for nil, a list or a mapping).
func scalarString(v any) string {
	switch v.(type) {
	case nil, []any, map[string]any:
		return ""
	}
	s, _ := phpString(v)
	return s
}

// maxTriggers is the most triggers a project has (ProjectTrigger::MAX_PER_PROJECT).
const maxTriggers = 20

// Global lists the regions of a global project and how DNS picks one of them.
type Global struct {
	// Regions are region names or ids, two or more; the primary region (region) among them.
	Regions []string `yaml:"regions"`
	// Mode is ha (default: the primary region, the others when it is unhealthy) or proximity
	// (the healthy region closest to the client).
	Mode string `yaml:"mode,omitempty"`
}

// Primary returns the primary region of a global project: region when set, else the first of
// global.regions.
func (f *File) Primary() string {
	if f.Region != "" || f.Global == nil || len(f.Global.Regions) == 0 {
		return f.Region
	}
	return f.Global.Regions[0]
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
	if f.Network != nil {
		f.Network.Access = strings.ToLower(strings.TrimSpace(f.Network.Access))
	}
	if f.Deploy != nil {
		f.Deploy.normalize()
	}
	f.warnings = nil
	// The API (RunrYaml) uses the list and ignores trigger when both are given
	if f.Trigger != nil && f.Triggers != nil {
		f.warnings = append(f.warnings, "trigger and triggers are both set: triggers (the whole list) is used, trigger is ignored")
		f.Trigger = nil
	}
	schema, _ := triggerSchema()
	for i := range f.Triggers {
		t := &f.Triggers[i]
		t.Type = strings.ToLower(strings.TrimSpace(t.Type))
		t.Name = strings.TrimSpace(t.Name)
		t.Config = plainMap(t.Config)
		at := triggerAt(i, t.Name)
		// The shorthands of `trigger:` (RunrYaml::triggers)
		move := func(from, to string) {
			if v, ok := t.Config[from]; ok {
				if _, set := t.Config[to]; !set {
					t.Config[to] = v
					delete(t.Config, from)
				}
			}
		}
		switch t.Type {
		case runr.TriggerSchedule:
			move("schedule", "cron")
		case runr.TriggerQueue:
			move("queue", "stream")
		}
		// Secrets never come from runr.yaml: the API ignores them, the CLI does not send them
		if _, ok := t.Config["secrets"]; ok {
			delete(t.Config, "secrets")
			f.warnings = append(f.warnings, at+": secrets are ignored in runr.yaml; set them in RunR with `runr triggers add` or `runr triggers update NAME --secret KEY=VALUE`")
		}
		// Keys the type does not have: the API drops them
		if schema != nil {
			if ts := schema.Types[t.Type]; ts != nil {
				for _, k := range ts.unknownKeys("", t.Config) {
					hint := ""
					if k == "delivery" {
						hint = " (delivery applies to the triggers the event gateway runs)"
					}
					f.warnings = append(f.warnings, fmt.Sprintf("%s: %s is not a setting of %s triggers; RunR ignores it%s", at, k, t.Type, hint))
				}
			}
		}
	}
	if f.Global != nil {
		f.Global.Mode = strings.ToLower(strings.TrimSpace(f.Global.Mode))
		for i, r := range f.Global.Regions {
			f.Global.Regions[i] = strings.TrimSpace(r)
		}
	}
}

// plainMap returns m with nested mappings as map[string]any (YAML allows other key types),
// so the config encodes as JSON; nil becomes an empty map.
func plainMap(m map[string]any) map[string]any {
	out := make(map[string]any, len(m))
	for k, v := range m {
		out[k] = plainValue(v)
	}
	return out
}

func plainValue(v any) any {
	switch t := v.(type) {
	case map[string]any:
		return plainMap(t)
	case map[any]any:
		out := make(map[string]any, len(t))
		for k, val := range t {
			out[fmt.Sprint(k)] = plainValue(val)
		}
		return out
	case []any:
		out := make([]any, len(t))
		for i, val := range t {
			out[i] = plainValue(val)
		}
		return out
	}
	return v
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
		if s.Schedule != nil {
			maxScale := 0
			if s.Max != nil {
				maxScale = *s.Max
			}
			if err := runr.ValidateScaleSchedule(scheduleRows(*s.Schedule), maxScale); err != nil {
				p = append(p, "scale.schedule: "+err.Error())
			}
		}
	}
	if t := f.Trigger; t != nil {
		switch t.Type {
		case runr.TriggerHTTP:
			if t.Schedule != "" || t.Queue != "" {
				p = append(p, "trigger.schedule and trigger.queue need trigger.type schedule or queue")
			}
		case runr.TriggerSchedule:
			// the API's rule (App\Rules\CronSchedule): five fields or a macro such as @daily
			if !validCron(t.Schedule) {
				p = append(p, "trigger.schedule must be a cron expression with five fields, e.g. \"*/5 * * * *\", or @hourly, @daily, @weekly, @monthly, @yearly")
			}
		case runr.TriggerQueue:
			if !regexp.MustCompile(`^[a-zA-Z0-9._-]{1,255}$`).MatchString(t.Queue) {
				p = append(p, "trigger.queue must name a Redis stream (letters, digits, . _ -)")
			}
		default:
			p = append(p, fmt.Sprintf("unknown trigger.type %q (http, schedule or queue)", t.Type))
		}
	}
	if f.Deploy != nil {
		p = append(p, f.Deploy.validate(f.TriggerType())...)
	}
	p = append(p, f.validateTriggers()...)
	for _, k := range sortedKeys(f.Env) {
		if err := runr.ValidateEnvName(k); err != nil {
			p = append(p, "env: "+err.Error())
		}
	}
	if n := f.Network; n != nil {
		p = append(p, n.problems()...)
	}
	p = append(p, f.validateGlobal()...)
	if len(p) > 0 {
		return &ValidationError{Problems: p}
	}
	return nil
}

var triggerTypePattern = regexp.MustCompile(`^[a-z][a-z0-9_]{0,62}$`)

// triggerAt names an entry of `triggers:` in messages.
func triggerAt(i int, name string) string {
	at := fmt.Sprintf("triggers[%d]", i)
	if name != "" {
		at += " (" + name + ")"
	}
	return at
}

// triggerHints add what a value is to the schema's messages of the common keys.
var triggerHints = map[string]string{
	"queue.stream":          " (a Redis stream: letters, digits, . _ -; not starting with runr.; or queue:)",
	"topic.topic":           " (a topic of the organization: runr topics list)",
	"webhook.verify":        " (none, stripe, github, slack or hmac)",
	"platform_event.events": " (deployment.finished, deployment.failed, alert.fired, … or \"*\")",
	"schedule.cron":         " (or schedule:)",
}

// validateTriggers checks `triggers:` with the API's rules: each entry against the trigger
// schema the API derives from its trigger types (triggers.schema.json: config keys, their types,
// ranges, patterns, cron and time zone formats), and the list as TriggerService does (at most
// 20 triggers, unique names, one http trigger, a workflow trigger alone). What the schema cannot
// say (a topic that exists, the region's Redis, …) the API checks on deploy. Entries of a type
// this CLI does not know (a newer API) get the common checks only.
func (f *File) validateTriggers() []string {
	if f.Triggers == nil {
		return nil
	}
	var p []string
	if len(f.Triggers) == 0 {
		return append(p, "triggers must list at least one trigger")
	}
	if len(f.Triggers) > maxTriggers {
		p = append(p, fmt.Sprintf("triggers lists %d triggers; a project has at most %d", len(f.Triggers), maxTriggers))
	}
	schema, err := triggerSchema()
	if err != nil {
		return append(p, err.Error())
	}
	names := map[string]bool{}
	http := 0
	for i, t := range f.Triggers {
		at := triggerAt(i, t.Name)
		if t.Type == "" {
			p = append(p, at+": type is required")
			continue
		}
		if !triggerTypePattern.MatchString(t.Type) {
			p = append(p, fmt.Sprintf("%s: type %q is not a trigger type", at, t.Type))
			continue
		}
		if t.Name != "" {
			if len(schema.Entry.Properties["name"].validate("name", t.Name)) > 0 {
				p = append(p, at+": name must be lower-case letters, digits and dashes, starting and ending with a letter or digit (at most 63)")
			} else if names[t.Name] {
				p = append(p, at+": the name is used twice")
			}
			names[t.Name] = true
		}
		if t.badEnabled != nil {
			p = append(p, fmt.Sprintf("%s: enabled must be true or false (got %v)", at, t.badEnabled))
		}
		switch t.Type {
		case runr.TriggerHTTP:
			http++
		case runr.TriggerWorkflow:
			if len(f.Triggers) > 1 {
				p = append(p, at+": a workflow trigger must be the project's only trigger")
			}
		}
		ts := schema.Types[t.Type]
		if ts == nil {
			continue // a type of a newer API: it validates the config itself
		}
		for _, problem := range ts.validate("", t.Config) {
			key, _, _ := strings.Cut(problem, " ")
			if hint := triggerHints[t.Type+"."+key]; hint != "" {
				problem += hint
			}
			p = append(p, at+": "+problem)
		}
	}
	if http > 1 {
		p = append(p, "triggers: a project has at most one http trigger")
	}
	return p
}

// TriggerSpecs returns `triggers:` as the API's list (PUT /projects/{project}/triggers); nil
// when the file has no trigger list.
func (f *File) TriggerSpecs() []runr.TriggerSpec {
	if len(f.Triggers) == 0 {
		return nil
	}
	out := make([]runr.TriggerSpec, 0, len(f.Triggers))
	for _, t := range f.Triggers {
		out = append(out, runr.TriggerSpec{Type: t.Type, Name: t.Name, Enabled: t.Enabled, Config: plainMap(t.Config)})
	}
	return out
}

// enabledTriggers returns the entries of `triggers:` that are not disabled.
func (f *File) enabledTriggers() []TriggerEntry {
	var out []TriggerEntry
	for _, t := range f.Triggers {
		if t.Enabled == nil || *t.Enabled {
			out = append(out, t)
		}
	}
	return out
}

// QueueStream returns the Redis stream of the queue trigger ("" without one).
func (f *File) QueueStream() string {
	if f.Trigger != nil {
		return f.Trigger.Queue
	}
	for _, t := range f.enabledTriggers() {
		if t.Type == runr.TriggerQueue {
			s, _ := t.Config["stream"].(string)
			return s
		}
	}
	return ""
}

func containsString(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

// validateGlobal checks global: 2 to runr.MaxGlobalRegions distinct regions, the primary among
// them and a known mode; any trigger list (the platform checks the regions themselves).
func (f *File) validateGlobal() []string {
	g := f.Global
	if g == nil {
		return nil
	}
	var p []string
	if len(g.Regions) < 2 {
		p = append(p, "global.regions needs at least 2 regions")
	}
	if len(g.Regions) > runr.MaxGlobalRegions {
		p = append(p, fmt.Sprintf("global.regions has %d regions; a global project runs in at most %d", len(g.Regions), runr.MaxGlobalRegions))
	}
	seen := map[string]bool{}
	for _, r := range g.Regions {
		switch {
		case r == "":
			p = append(p, "global.regions has an empty entry")
		case seen[strings.ToLower(r)]:
			p = append(p, fmt.Sprintf("global.regions lists %q twice", r))
		}
		seen[strings.ToLower(r)] = true
	}
	// region (the primary) is checked against global.regions once both are resolved (names or ids)
	if g.Mode != "" && g.Mode != runr.GlobalModeHA && g.Mode != runr.GlobalModeProximity {
		p = append(p, fmt.Sprintf("unknown global.mode %q (ha or proximity)", g.Mode))
	}
	return p
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

// TriggerType returns how the function is invoked: the trigger type, http by default. With a
// trigger list it is http when the list serves HTTP (http, workflow), schedule for a schedule
// alone, and queue otherwise (the event gateway delivers every event as a CloudEvent POST,
// like a queue message).
func (f *File) TriggerType() string {
	if len(f.Triggers) > 0 {
		enabled := f.enabledTriggers()
		for _, t := range enabled {
			if t.Type == runr.TriggerHTTP || t.Type == runr.TriggerWorkflow {
				return runr.TriggerHTTP
			}
		}
		if len(enabled) == 1 && enabled[0].Type == runr.TriggerSchedule {
			return runr.TriggerSchedule
		}
		if len(enabled) == 0 {
			return runr.TriggerHTTP
		}
		return runr.TriggerQueue
	}
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
		if s.Schedule != nil {
			rows := scheduleRows(*s.Schedule)
			c.ScaleSchedule = &rows
		}
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
	if want.ScaleSchedule != nil && !runr.EqualScaleSchedules(*want.ScaleSchedule, have.ScaleSchedule) {
		out.ScaleSchedule = want.ScaleSchedule
		changed = append(changed, "scale_schedule")
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

// scheduleRows turns scale.schedule into the API's rows (expressions single-spaced); an empty
// schedule is an empty, non-nil list (it turns the timed warmer off).
func scheduleRows(rows []ScheduleRow) []runr.ScaleScheduleRow {
	out := make([]runr.ScaleScheduleRow, len(rows))
	for i, r := range rows {
		out[i] = runr.ScaleScheduleRow{Cron: runr.NormalizeCron(r.Cron), MinScale: r.Min}
	}
	return out
}

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
