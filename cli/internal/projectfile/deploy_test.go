package projectfile

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const shopYAML = "name: shop\ntype: faas\nruntime: python\n"

func TestParseDeploySection(t *testing.T) {
	f, err := Parse([]byte(shopYAML + `
deploy:
  strategy: Blue-Green
  blue_green: { auto_promote: true, health_path: /healthz, health_checks: 3, keep_previous_minutes: 15 }
  canary: { steps: [10, 50, 100], interval_minutes: 5, error_rate_threshold: 2.5, p95_threshold_ms: 800 }
`))
	if err != nil {
		t.Fatal(err)
	}
	if f.Deploy.Strategy != runr.StrategyBlueGreen {
		t.Fatalf("strategy = %q", f.Deploy.Strategy)
	}
	in := f.DeployStrategy()
	if in == nil || in.Strategy != runr.StrategyBlueGreen {
		t.Fatalf("input = %+v", in)
	}
	data, _ := json.Marshal(in)
	want := `{"strategy":"blue_green","blue_green":{"auto_promote":true,"health_path":"/healthz","health_checks":3,"keep_previous_minutes":15},` +
		`"canary":{"steps":[10,50,100],"interval_minutes":5,"error_rate_threshold":2.5,"p95_threshold_ms":800}}`
	if string(data) != want {
		t.Fatalf("body = %s\nwant   %s", data, want)
	}
}

// Only what the file sets is sent; null clears a nullable option.
func TestDeploySectionPartialAndNull(t *testing.T) {
	f, err := Parse([]byte(shopYAML + "deploy:\n  canary:\n    p95_threshold_ms: null\n    interval_minutes: 2\n  blue-green:\n    health_path: null\n"))
	if err != nil {
		t.Fatal(err)
	}
	if f.Deploy.BlueGreen == nil || f.Deploy.BlueGreenAlias != nil {
		t.Fatalf("blue-green: was not moved to blue_green: %+v", f.Deploy)
	}
	data, _ := json.Marshal(f.DeployStrategy())
	if string(data) != `{"blue_green":{"health_path":null},"canary":{"interval_minutes":2,"p95_threshold_ms":null}}` {
		t.Fatalf("body = %s", data)
	}

	f, _ = Parse([]byte(shopYAML + "deploy:\n  blue_green:\n    health_path: \"\"\n  canary:\n    error_rate_threshold: null\n"))
	data, _ = json.Marshal(f.DeployStrategy())
	if string(data) != `{"blue_green":{"health_path":null},"canary":{"error_rate_threshold":null}}` {
		t.Fatalf("empty path / null rate body = %s", data)
	}

	// No section, or a section that sets nothing: nothing to send
	f, _ = Parse([]byte(shopYAML))
	if f.DeployStrategy() != nil {
		t.Fatal("a file without deploy: sent settings")
	}
	f, _ = Parse([]byte(shopYAML + "deploy: {}\n"))
	if f.DeployStrategy() != nil {
		t.Fatal("an empty deploy: sent settings")
	}
}

func TestDeploySectionRejects(t *testing.T) {
	cases := map[string]string{
		"unknown strategy":       "deploy:\n  strategy: rolling\n",
		"strategy on a queue":    "trigger:\n  type: queue\n  queue: jobs\ndeploy:\n  strategy: canary\n",
		"unknown deploy key":     "deploy:\n  strategi: canary\n",
		"unknown blue-green key": "deploy:\n  blue_green:\n    auto: true\n",
		"unknown canary key":     "deploy:\n  canary:\n    step: [10]\n",
		"section not a mapping":  "deploy:\n  canary: fast\n",
		"both spellings":         "deploy:\n  blue_green: {health_checks: 2}\n  blue-green: {health_checks: 3}\n",
		"health path":            "deploy:\n  blue_green:\n    health_path: healthz\n",
		"health path spaces":     "deploy:\n  blue_green:\n    health_path: /health z\n",
		"health checks":          "deploy:\n  blue_green:\n    health_checks: 21\n",
		"keep previous":          "deploy:\n  blue_green:\n    keep_previous_minutes: 1441\n",
		"no steps":               "deploy:\n  canary:\n    steps: []\n",
		"too many steps":         "deploy:\n  canary:\n    steps: [1,2,3,4,5,6,7,8,9,10,11]\n",
		"step range":             "deploy:\n  canary:\n    steps: [10, 150]\n",
		"steps order":            "deploy:\n  canary:\n    steps: [50, 50, 100]\n",
		"interval":               "deploy:\n  canary:\n    interval_minutes: 0\n",
		"error rate":             "deploy:\n  canary:\n    error_rate_threshold: 101\n",
		"p95":                    "deploy:\n  canary:\n    p95_threshold_ms: 600001\n",
	}
	for name, src := range cases {
		_, err := Parse([]byte(shopYAML + src))
		var ve *ValidationError
		if !errors.As(err, &ve) {
			t.Errorf("%s: err = %v, want a ValidationError", name, err)
		}
	}
	_, err := Parse([]byte(shopYAML + "deploy:\n  canary:\n    step: [10]\n"))
	if err == nil || !strings.Contains(err.Error(), "field step not found in deploy.canary") {
		t.Fatalf("unknown key message = %v", err)
	}
	_, err = Parse([]byte(shopYAML + "trigger:\n  type: schedule\n  schedule: '0 * * * *'\ndeploy:\n  strategy: blue-green\n"))
	if err == nil || !strings.Contains(err.Error(), "deploy.strategy blue-green needs an http trigger") {
		t.Fatalf("schedule message = %v", err)
	}
	// direct is fine for every trigger
	if _, err := Parse([]byte(shopYAML + "trigger:\n  type: queue\n  queue: jobs\ndeploy:\n  strategy: direct\n")); err != nil {
		t.Fatalf("direct on a queue project: %v", err)
	}
}

func TestDiffStrategy(t *testing.T) {
	rate, path := 5.0, "/healthz"
	have := runr.DeployStrategySettings{
		Strategy:  runr.StrategyCanary,
		BlueGreen: runr.BlueGreenOptions{HealthPath: &path, HealthChecks: 3, KeepPreviousMinutes: 15},
		Canary:    runr.CanaryOptions{Steps: []int{10, 50, 100}, IntervalMinutes: 5, ErrorRateThreshold: &rate},
	}
	f, _ := Parse([]byte(shopYAML + "deploy:\n  strategy: canary\n  blue_green: {health_path: /healthz, health_checks: 3}\n" +
		"  canary: {steps: [10, 50, 100], error_rate_threshold: 5, p95_threshold_ms: null}\n"))
	if out, changed := DiffStrategy(*f.DeployStrategy(), have); len(changed) != 0 || !out.Empty() {
		t.Fatalf("equal settings differ: %v %+v", changed, out)
	}

	f, _ = Parse([]byte(shopYAML + "deploy:\n  strategy: blue-green\n  blue_green: {health_path: null, health_checks: 3, keep_previous_minutes: 0}\n" +
		"  canary: {steps: [5, 100], p95_threshold_ms: 800}\n"))
	out, changed := DiffStrategy(*f.DeployStrategy(), have)
	if strings.Join(changed, ",") != "strategy,blue_green.health_path,blue_green.keep_previous_minutes,canary.steps,canary.p95_threshold_ms" {
		t.Fatalf("changed = %v", changed)
	}
	data, _ := json.Marshal(out)
	if string(data) != `{"strategy":"blue_green","blue_green":{"health_path":null,"keep_previous_minutes":0},"canary":{"steps":[5,100],"p95_threshold_ms":800}}` {
		t.Fatalf("diff body = %s", data)
	}
}
