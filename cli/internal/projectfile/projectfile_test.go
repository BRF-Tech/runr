package projectfile

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func TestParseFullFile(t *testing.T) {
	f, err := Parse([]byte(`
name: orders-worker
type: faas
runtime: nodejs
runtime_version: "22"
region: eu-1
description: Consumes orders
handler: worker.handle
resources:
  cpu: 250m
  memory_mb: 256
  timeout_seconds: 60
scale:
  min: 1
  max: 10
  to_zero: false
trigger:
  type: queue
  queue: orders
env:
  LOG_LEVEL: info
  RETRIES: 3
ignore: ["*.md"]
dev:
  port: 9000
`))
	if err != nil {
		t.Fatal(err)
	}
	if f.MainFile() != "worker.js" || f.HandlerName() != "handle" || f.TriggerType() != runr.TriggerQueue {
		t.Fatalf("main %s handler %s trigger %s", f.MainFile(), f.HandlerName(), f.TriggerType())
	}
	if f.Env["RETRIES"] != "3" {
		t.Fatalf("numeric env value = %q", f.Env["RETRIES"])
	}
	c := f.Config()
	if *c.Handler != "worker.handle" || *c.CPU != "250m" || *c.MemoryMB != 256 || *c.TimeoutSeconds != 60 ||
		*c.MinScale != 1 || *c.MaxScale != 10 || *c.ScaleToZero || *c.TriggerType != "queue" || *c.QueueStream != "orders" ||
		(*c.EnvVars)["LOG_LEVEL"] != "info" {
		t.Fatalf("config = %+v", c)
	}
	if c.Image != nil || c.Filename != nil || c.Schedule != nil {
		t.Fatal("unset fields were set")
	}
}

func TestParseRejects(t *testing.T) {
	cases := map[string]string{
		"no name":            "type: faas\nruntime: python\n",
		"bad name":           "name: 9lives\ntype: faas\nruntime: python\n",
		"long name":          "name: " + strings.Repeat("a", 45) + "\ntype: faas\nruntime: python\n",
		"unknown type":       "name: a\ntype: lambda\n",
		"missing type":       "name: a\n",
		"no runtime":         "name: a\ntype: faas\n",
		"invalid runtime id": "name: a\ntype: faas\nruntime: Cobol!\n",
		"image on faas":      "name: a\ntype: faas\nruntime: python\nimage: x\n",
		"runtime on caas":    "name: a\ntype: caas\nruntime: python\nimage: x\n",
		"bad handler":        "name: a\ntype: faas\nruntime: python\nhandler: main\n",
		"file on faas":       "name: a\ntype: faas\nruntime: python\nfile: x.py\n",
		"memory":             "name: a\ntype: faas\nruntime: python\nresources:\n  memory_mb: 32\n",
		"cpu":                "name: a\ntype: faas\nruntime: python\nresources:\n  cpu: lots\n",
		"scale min":          "name: a\ntype: faas\nruntime: python\nscale:\n  min: 11\n",
		"scale max":          "name: a\ntype: faas\nruntime: python\nscale:\n  max: 0\n",
		"scale order":        "name: a\ntype: faas\nruntime: python\nscale:\n  min: 5\n  max: 2\n",
		"schedule":           "name: a\ntype: faas\nruntime: python\ntrigger:\n  type: schedule\n  schedule: daily\n",
		"queue":              "name: a\ntype: faas\nruntime: python\ntrigger:\n  type: queue\n",
		"trigger type":       "name: a\ntype: faas\nruntime: python\ntrigger:\n  type: event\n",
		"schedule on http":   "name: a\ntype: faas\nruntime: python\ntrigger:\n  type: http\n  schedule: '* * * * *'\n",
		"reserved env":       "name: a\ntype: faas\nruntime: python\nenv:\n  PORT: '1'\n",
		"bad env name":       "name: a\ntype: faas\nruntime: python\nenv:\n  1X: y\n",
		"unknown field":      "name: a\ntype: faas\nruntime: python\ntriggers: []\n",
		"port range":         "name: a\ntype: caas\nimage: x\nports: [70000]\n",
		"yaml syntax":        "name: [a\n",
	}
	for name, src := range cases {
		_, err := Parse([]byte(src))
		var ve *ValidationError
		if !errors.As(err, &ve) {
			t.Errorf("%s: err = %v, want a ValidationError", name, err)
		}
	}
}

func TestValidationErrorListsAllProblems(t *testing.T) {
	_, err := Parse([]byte("name: a\ntype: faas\nscale:\n  min: 20\nresources:\n  memory_mb: 1\n"))
	var ve *ValidationError
	if !errors.As(err, &ve) || len(ve.Problems) != 3 {
		t.Fatalf("problems = %v", err)
	}
	if !strings.Contains(err.Error(), "\n  - ") {
		t.Fatalf("message = %q", err.Error())
	}
}

func TestDefaults(t *testing.T) {
	f, err := Parse([]byte("name: a\ntype: faas\nruntime: go\ntrigger: {}\n"))
	if err != nil {
		t.Fatal(err)
	}
	if f.MainFile() != "main.go" || f.HandlerName() != "handler" || f.TriggerType() != runr.TriggerHTTP {
		t.Fatalf("defaults: %s %s %s", f.MainFile(), f.HandlerName(), f.TriggerType())
	}
	x, _ := Parse([]byte("name: a\ntype: xaas\nruntime: php\n"))
	if x.MainFile() != "main.php" || x.HandlerName() != "handler" {
		t.Fatalf("xaas defaults: %s %s", x.MainFile(), x.HandlerName())
	}
	c, _ := Parse([]byte("name: a\ntype: caas\nimage: nginx\ncommand: [nginx, -g]\nargs: [daemon off;]\nports: [8080]\n"))
	cfg := c.Config()
	if *cfg.Image != "nginx" || len(*cfg.Command) != 2 || (*cfg.Ports)[0] != 8080 || cfg.Handler != nil {
		t.Fatalf("caas config = %+v", cfg)
	}
}

func TestSlug(t *testing.T) {
	for in, want := range map[string]string{
		"Hello World": "hello-world", "my_service": "my-service", "API v2!": "api-v2", "--x--": "x", "a@b": "a-at-b",
	} {
		if got := Slug(in); got != want {
			t.Errorf("Slug(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestDiff(t *testing.T) {
	mem, min := 128, 0
	toZero := true
	have := runr.ProjectConfig{Handler: "main.handler", MemoryMB: &mem, MinScale: &min, ScaleToZero: &toZero,
		EnvVars: runr.EnvMap{"A": "1", "SECRETISH": "keep"}, Ports: runr.FlexInts{80}}

	f, _ := Parse([]byte("name: a\ntype: faas\nruntime: python\nhandler: main.handler\nresources:\n  memory_mb: 128\nscale:\n  min: 0\n  to_zero: true\nenv:\n  A: '1'\n"))
	if out, changed := Diff(f.Config(), have); !out.Empty() || len(changed) != 0 {
		t.Fatalf("equal config differs: %v %+v", changed, out)
	}

	f, _ = Parse([]byte("name: a\ntype: faas\nruntime: python\nresources:\n  memory_mb: 256\nscale:\n  to_zero: false\ntrigger:\n  type: schedule\n  schedule: '0 * * * *'\nenv:\n  B: '2'\n"))
	out, changed := Diff(f.Config(), have)
	if strings.Join(changed, ",") != "trigger_type,schedule,memory_mb,scale_to_zero,env" {
		t.Fatalf("changed = %v", changed)
	}
	env := *out.EnvVars
	if env["A"] != "1" || env["B"] != "2" || env["SECRETISH"] != "keep" {
		t.Fatalf("merged env = %v", env)
	}

	c, _ := Parse([]byte("name: a\ntype: caas\nimage: nginx\nports: [80]\n"))
	if out, changed := Diff(c.Config(), runr.ProjectConfig{Image: "nginx", Ports: runr.FlexInts{80}}); !out.Empty() {
		t.Fatalf("caas equal config differs: %v", changed)
	}
}

func TestFindWalksUp(t *testing.T) {
	root := t.TempDir()
	if err := os.WriteFile(filepath.Join(root, "runr.yml"), []byte("name: a\ntype: faas\nruntime: python\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	sub := filepath.Join(root, "src", "deep")
	if err := os.MkdirAll(sub, 0o755); err != nil {
		t.Fatal(err)
	}
	p, err := Find(sub)
	if err != nil || filepath.Base(p) != "runr.yml" {
		t.Fatalf("Find = %s, %v", p, err)
	}
	f, err := Load(p)
	if err != nil || f.Dir() != root {
		t.Fatalf("Load = %+v, %v", f, err)
	}
}

func TestCollectFiles(t *testing.T) {
	dir := t.TempDir()
	files := map[string]string{
		"main.py": "def handler(e, c):\n    return 1\n", "helpers.py": "X = 1\n", "requirements.txt": "requests\n",
		"runr.yaml": "name: a\n", ".env": "SECRET=1\n", "README.md": "# a\n", ".npmrc": "x=1\n",
		"node_modules/x/index.js": "x", "__pycache__/m.pyc": "x", ".git/HEAD": "x", "tests/test_main.py": "x",
	}
	for name, content := range files {
		p := filepath.Join(dir, filepath.FromSlash(name))
		_ = os.MkdirAll(filepath.Dir(p), 0o755)
		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := CollectFiles(dir, nil); err == nil || !strings.Contains(err.Error(), "tests/test_main.py") {
		t.Fatalf("nested file accepted: %v", err)
	}
	got, err := CollectFiles(dir, []string{"tests", "*.md"})
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, f := range got {
		names = append(names, f.Name)
	}
	if strings.Join(names, ",") != ".npmrc,helpers.py,main.py,requirements.txt" {
		t.Fatalf("collected %v", names)
	}

	if err := os.WriteFile(filepath.Join(dir, ".runrignore"), []byte("# comment\nhelpers.py\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	got, _ = CollectFiles(dir, []string{"tests", "*.md"})
	for _, f := range got {
		if f.Name == "helpers.py" {
			t.Fatal(".runrignore was not applied")
		}
	}

	bin := t.TempDir()
	_ = os.WriteFile(filepath.Join(bin, "main.py"), []byte{0xff, 0xfe, 0x00}, 0o644)
	if _, err := CollectFiles(bin, nil); err == nil || !strings.Contains(err.Error(), "UTF-8") {
		t.Fatalf("binary file accepted: %v", err)
	}

	big := t.TempDir()
	_ = os.WriteFile(filepath.Join(big, "main.py"), []byte(strings.Repeat("x", runr.MaxCodeBytes)), 0o644)
	if _, err := CollectFiles(big, nil); err == nil || !strings.Contains(err.Error(), "limit") {
		t.Fatalf("oversized code accepted: %v", err)
	}

	empty := t.TempDir()
	_ = os.WriteFile(filepath.Join(empty, "main.py"), nil, 0o644)
	if _, err := CollectFiles(empty, nil); err == nil || !strings.Contains(err.Error(), "empty") {
		t.Fatalf("empty file accepted: %v", err)
	}
}

func TestIgnored(t *testing.T) {
	cases := []struct {
		path     string
		patterns []string
		want     bool
	}{
		{"node_modules/a/b.js", DefaultIgnore, true},
		{"src/__pycache__/x.pyc", DefaultIgnore, true},
		{".env.local", DefaultIgnore, true},
		{"main.py", DefaultIgnore, false},
		{"docs/a.md", []string{"docs/*.md"}, true},
		{"docs/sub/a.md", []string{"docs"}, true},
		{"docs/sub/a.md", []string{"/docs/"}, true},
		{"x/docs.txt", []string{"docs"}, false},
	}
	for _, c := range cases {
		if got := Ignored(c.path, c.patterns); got != c.want {
			t.Errorf("Ignored(%q, %v) = %v", c.path, c.patterns, got)
		}
	}
}
