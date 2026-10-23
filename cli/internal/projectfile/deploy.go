package projectfile

import (
	"fmt"
	"slices"
	"strings"

	"gopkg.in/yaml.v3"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Deploy is the deploy section of runr.yaml: the project's deploy strategy and its options.
// `runr deploy` applies what it sets before deploying (PUT deploy-strategy, only what differs
// from the project's settings); a git push applies it as well.
//
//	deploy:
//	  strategy: canary                 # direct | blue-green | canary
//	  blue_green: { auto_promote: true, health_path: /healthz, health_checks: 3, keep_previous_minutes: 15 }
//	  canary: { steps: [10, 50, 100], interval_minutes: 5, error_rate_threshold: 5, p95_threshold_ms: 800 }
type Deploy struct {
	// Strategy is direct, blue-green or canary (blue_green and bluegreen are accepted).
	Strategy  string     `yaml:"strategy,omitempty"`
	BlueGreen *BlueGreen `yaml:"blue_green,omitempty"`
	// BlueGreenAlias is the section spelled blue-green; Normalize moves it to BlueGreen.
	BlueGreenAlias *BlueGreen `yaml:"blue-green,omitempty"`
	Canary         *Canary    `yaml:"canary,omitempty"`
}

// BlueGreen are the blue-green options of runr.yaml. Unset fields keep the project's value;
// health_path: null (or "") clears the path, so readiness alone decides.
type BlueGreen struct {
	AutoPromote         *bool   `yaml:"auto_promote,omitempty"`
	HealthPath          *string `yaml:"health_path,omitempty"`
	HealthChecks        *int    `yaml:"health_checks,omitempty"`
	KeepPreviousMinutes *int    `yaml:"keep_previous_minutes,omitempty"`

	// nulls are the keys the file sets to null.
	nulls map[string]bool
}

// Canary are the canary options of runr.yaml. Unset fields keep the project's value; a
// threshold set to null is not checked.
type Canary struct {
	Steps              []int    `yaml:"steps,omitempty"`
	IntervalMinutes    *int     `yaml:"interval_minutes,omitempty"`
	ErrorRateThreshold *float64 `yaml:"error_rate_threshold,omitempty"`
	P95ThresholdMs     *int     `yaml:"p95_threshold_ms,omitempty"`

	nulls map[string]bool
}

var (
	blueGreenKeys = []string{"auto_promote", "health_path", "health_checks", "keep_previous_minutes"}
	canaryKeys    = []string{"steps", "interval_minutes", "error_rate_threshold", "p95_threshold_ms"}
)

// UnmarshalYAML rejects unknown keys and remembers the keys set to null.
func (b *BlueGreen) UnmarshalYAML(n *yaml.Node) error {
	nulls, err := sectionKeys(n, "deploy.blue_green", blueGreenKeys)
	if err != nil {
		return err
	}
	type plain BlueGreen
	var p plain
	if err := n.Decode(&p); err != nil {
		return err
	}
	*b = BlueGreen(p)
	b.nulls = nulls
	return nil
}

// UnmarshalYAML rejects unknown keys and remembers the keys set to null.
func (c *Canary) UnmarshalYAML(n *yaml.Node) error {
	nulls, err := sectionKeys(n, "deploy.canary", canaryKeys)
	if err != nil {
		return err
	}
	type plain Canary
	var p plain
	if err := n.Decode(&p); err != nil {
		return err
	}
	*c = Canary(p)
	c.nulls = nulls
	return nil
}

// sectionKeys checks the keys of an option section (a custom decoder does not inherit the
// file's strict key check) and returns those set to null.
func sectionKeys(n *yaml.Node, section string, known []string) (map[string]bool, error) {
	if n.Kind != yaml.MappingNode {
		return nil, fmt.Errorf("line %d: %s must be a mapping of %s", n.Line, section, strings.Join(known, ", "))
	}
	nulls := map[string]bool{}
	for i := 0; i+1 < len(n.Content); i += 2 {
		k, v := n.Content[i], n.Content[i+1]
		if !slices.Contains(known, k.Value) {
			return nil, fmt.Errorf("line %d: field %s not found in %s (%s)", k.Line, k.Value, section, strings.Join(known, ", "))
		}
		if v.Kind == yaml.ScalarNode && v.ShortTag() == "!!null" {
			nulls[k.Value] = true
		}
	}
	return nulls, nil
}

// normalize maps the strategy to the API's name and the blue-green: spelling to blue_green
// (both spellings at once stay for validate to report).
func (d *Deploy) normalize() {
	d.Strategy = strings.ToLower(strings.TrimSpace(d.Strategy))
	if s, ok := runr.NormalizeStrategy(d.Strategy); ok {
		d.Strategy = s
	}
	if d.BlueGreenAlias != nil && d.BlueGreen == nil {
		d.BlueGreen, d.BlueGreenAlias = d.BlueGreenAlias, nil
	}
}

// validate checks the section like the API does; trigger is the file's trigger type.
func (d *Deploy) validate(trigger string) []string {
	var p []string
	if d.Strategy != "" {
		if s, ok := runr.NormalizeStrategy(d.Strategy); !ok {
			p = append(p, fmt.Sprintf("unknown deploy.strategy %q (direct, blue-green or canary)", d.Strategy))
		} else if s != runr.StrategyDirect && trigger != runr.TriggerHTTP {
			p = append(p, fmt.Sprintf("deploy.strategy %s needs an http trigger; deploys of %s projects are direct",
				runr.StrategyLabel(s), trigger))
		}
	}
	if d.BlueGreenAlias != nil && d.BlueGreen != nil {
		p = append(p, "deploy.blue_green and deploy.blue-green are the same section; keep one")
	}
	if b := d.BlueGreen; b != nil {
		if b.HealthPath != nil && *b.HealthPath != "" {
			if err := ValidateHealthPath(*b.HealthPath); err != nil {
				p = append(p, "deploy.blue_green.health_path "+err.Error())
			}
		}
		if b.HealthChecks != nil && (*b.HealthChecks < 1 || *b.HealthChecks > 20) {
			p = append(p, "deploy.blue_green.health_checks must be between 1 and 20")
		}
		if b.KeepPreviousMinutes != nil && (*b.KeepPreviousMinutes < 0 || *b.KeepPreviousMinutes > 1440) {
			p = append(p, "deploy.blue_green.keep_previous_minutes must be between 0 and 1440")
		}
	}
	if c := d.Canary; c != nil {
		if c.Steps != nil {
			if err := ValidateCanarySteps(c.Steps); err != nil {
				p = append(p, "deploy.canary.steps "+err.Error())
			}
		}
		if c.IntervalMinutes != nil && (*c.IntervalMinutes < 1 || *c.IntervalMinutes > 1440) {
			p = append(p, "deploy.canary.interval_minutes must be between 1 and 1440")
		}
		if c.ErrorRateThreshold != nil && (*c.ErrorRateThreshold < 0 || *c.ErrorRateThreshold > 100) {
			p = append(p, "deploy.canary.error_rate_threshold must be a percentage between 0 and 100")
		}
		if c.P95ThresholdMs != nil && (*c.P95ThresholdMs < 1 || *c.P95ThresholdMs > 600000) {
			p = append(p, "deploy.canary.p95_threshold_ms must be between 1 and 600000")
		}
	}
	return p
}

// ValidateHealthPath checks the path of blue-green health checks: it starts with / and has
// no spaces (at most 255 characters).
func ValidateHealthPath(path string) error {
	switch {
	case !strings.HasPrefix(path, "/"):
		return fmt.Errorf("%q must start with /", path)
	case strings.ContainsAny(path, " \t\r\n"):
		return fmt.Errorf("%q must not contain spaces", path)
	case len(path) > 255:
		return fmt.Errorf("is longer than 255 characters")
	}
	return nil
}

// ValidateCanarySteps checks canary steps: 1 to 10 strictly increasing percentages of 1-100.
func ValidateCanarySteps(steps []int) error {
	if len(steps) < 1 || len(steps) > 10 {
		return fmt.Errorf("needs 1 to 10 steps, has %d", len(steps))
	}
	for i, s := range steps {
		if s < 1 || s > 100 {
			return fmt.Errorf("are percentages between 1 and 100; %d is not", s)
		}
		if i > 0 && s <= steps[i-1] {
			return fmt.Errorf("must increase strictly; %d follows %d", s, steps[i-1])
		}
	}
	return nil
}

// DeployStrategy returns what the deploy section sets as a partial update of the project's
// deploy strategy; nil without a deploy section or when it sets nothing.
func (f *File) DeployStrategy() *runr.DeployStrategyInput {
	d := f.Deploy
	if d == nil {
		return nil
	}
	in := &runr.DeployStrategyInput{}
	if s, ok := runr.NormalizeStrategy(d.Strategy); ok {
		in.Strategy = s
	}
	bg := d.BlueGreen
	if bg == nil {
		bg = d.BlueGreenAlias
	}
	if bg != nil {
		out := &runr.BlueGreenInput{AutoPromote: bg.AutoPromote, HealthChecks: bg.HealthChecks, KeepPreviousMinutes: bg.KeepPreviousMinutes}
		switch {
		case bg.nulls["health_path"] || (bg.HealthPath != nil && *bg.HealthPath == ""):
			out.HealthPath = runr.NullOf[string]()
		case bg.HealthPath != nil:
			out.HealthPath = runr.NullableOf(*bg.HealthPath)
		}
		in.BlueGreen = out
	}
	if c := d.Canary; c != nil {
		out := &runr.CanaryInput{IntervalMinutes: c.IntervalMinutes}
		if c.Steps != nil {
			out.Steps = append([]int(nil), c.Steps...)
		}
		switch {
		case c.nulls["error_rate_threshold"]:
			out.ErrorRateThreshold = runr.NullOf[float64]()
		case c.ErrorRateThreshold != nil:
			out.ErrorRateThreshold = runr.NullableOf(*c.ErrorRateThreshold)
		}
		switch {
		case c.nulls["p95_threshold_ms"]:
			out.P95ThresholdMs = runr.NullOf[int]()
		case c.P95ThresholdMs != nil:
			out.P95ThresholdMs = runr.NullableOf(*c.P95ThresholdMs)
		}
		in.Canary = out
	}
	if in.Empty() {
		return nil
	}
	return in
}

// DiffStrategy returns the part of want that differs from the project's settings, and the
// names of the changed settings (strategy, blue_green.health_path, canary.steps, …).
func DiffStrategy(want runr.DeployStrategyInput, have runr.DeployStrategySettings) (runr.DeployStrategyInput, []string) {
	var out runr.DeployStrategyInput
	var changed []string
	if want.Strategy != "" && want.Strategy != have.Strategy {
		out.Strategy = want.Strategy
		changed = append(changed, "strategy")
	}
	if w := want.BlueGreen; w != nil {
		h := have.BlueGreen
		var bg runr.BlueGreenInput
		if w.AutoPromote != nil && *w.AutoPromote != h.AutoPromote {
			bg.AutoPromote = w.AutoPromote
			changed = append(changed, "blue_green.auto_promote")
		}
		if w.HealthPath != nil && !sameNullable(w.HealthPath, h.HealthPath) {
			bg.HealthPath = w.HealthPath
			changed = append(changed, "blue_green.health_path")
		}
		if w.HealthChecks != nil && *w.HealthChecks != h.HealthChecks {
			bg.HealthChecks = w.HealthChecks
			changed = append(changed, "blue_green.health_checks")
		}
		if w.KeepPreviousMinutes != nil && *w.KeepPreviousMinutes != h.KeepPreviousMinutes {
			bg.KeepPreviousMinutes = w.KeepPreviousMinutes
			changed = append(changed, "blue_green.keep_previous_minutes")
		}
		if bg != (runr.BlueGreenInput{}) {
			out.BlueGreen = &bg
		}
	}
	if w := want.Canary; w != nil {
		h := have.Canary
		var c runr.CanaryInput
		set := false
		if w.Steps != nil && !slices.Equal(w.Steps, h.Steps) {
			c.Steps = w.Steps
			set = true
			changed = append(changed, "canary.steps")
		}
		if w.IntervalMinutes != nil && *w.IntervalMinutes != h.IntervalMinutes {
			c.IntervalMinutes = w.IntervalMinutes
			set = true
			changed = append(changed, "canary.interval_minutes")
		}
		if w.ErrorRateThreshold != nil && !sameNullable(w.ErrorRateThreshold, h.ErrorRateThreshold) {
			c.ErrorRateThreshold = w.ErrorRateThreshold
			set = true
			changed = append(changed, "canary.error_rate_threshold")
		}
		if w.P95ThresholdMs != nil && !sameNullable(w.P95ThresholdMs, h.P95ThresholdMs) {
			c.P95ThresholdMs = w.P95ThresholdMs
			set = true
			changed = append(changed, "canary.p95_threshold_ms")
		}
		if set {
			out.Canary = &c
		}
	}
	return out, changed
}

// sameNullable reports whether a wanted nullable setting equals the project's (nil = null).
func sameNullable[T comparable](want *runr.Nullable[T], have *T) bool {
	if want.Null || have == nil {
		return want.Null && have == nil
	}
	return want.Value == *have
}
