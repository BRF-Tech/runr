package dev

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
)

// Java, .NET, Ruby and Bun: their images run functions as a user other than root, so `runr dev`
// runs its containers as root (it writes /app/code and the dependency directory); each starts
// with its own entrypoint, and the compiled ones build the code at start.
func TestPlanJavaDotnetRubyBun(t *testing.T) {
	cases := []struct {
		yaml  string
		files map[string]string
		want  []string
		entry string
	}{
		{"name: j\ntype: faas\nruntime: java\n", map[string]string{"Main.java": "public class Main {}\n"},
			[]string{"--user 0:0", "-e RUNR_FILENAME=Main.java", "-e RUNR_HANDLER=handler", "ghcr.io/brf-tech/runr/exec-java:25.0"},
			"exec /opt/runr/bin/runr-start"},
		{"name: d\ntype: faas\nruntime: dotnet\n", map[string]string{"Function.cs": "public static class Function {}\n"},
			[]string{"--user 0:0", "-e RUNR_FILENAME=Function.cs", "-e RUNR_HANDLER=Handler", "ghcr.io/brf-tech/runr/exec-dotnet:10.0"},
			"exec /opt/runr/bin/runr-start"},
		{"name: r\ntype: faas\nruntime: ruby\n", map[string]string{"main.rb": "def handler(e, c) = 1\n", "Gemfile": "source \"https://rubygems.org\"\n"},
			[]string{"--user 0:0", "target=/app/gems,readonly", "-e BUNDLE_GEMFILE=/app/gems/Gemfile", "-e BUNDLE_PATH=/app/gems/vendor", "ghcr.io/brf-tech/runr/exec-ruby:3.4"},
			"exec ruby /opt/runr/server.rb"},
		{"name: b\ntype: faas\nruntime: bun\n", map[string]string{"main.ts": "export function handler() { return 1 }\n", "package.json": "{}"},
			[]string{"--user 0:0", "target=/app/code/node_modules,readonly", "-e RUNR_FILENAME=main.ts", "ghcr.io/brf-tech/runr/exec-bun:1.4"},
			"exec bun /opt/runr/server.ts"},
	}
	for _, c := range cases {
		pf, dir := project(t, c.yaml, c.files)
		p, err := MakePlan(context.Background(), opts(pf, dir, t.TempDir()))
		if err != nil {
			t.Fatalf("%s: %v", pf.Runtime, err)
		}
		args := strings.Join(p.RunArgs(), " ")
		for _, want := range c.want {
			if !strings.Contains(args, want) {
				t.Fatalf("%s: run args lack %q:\n%s", pf.Runtime, want, args)
			}
		}
		if script := p.RunArgs()[len(p.RunArgs())-1]; !strings.HasSuffix(script, c.entry) {
			t.Fatalf("%s: start script = %s", pf.Runtime, script)
		}
		if p.DepsFile == "" {
			continue
		}
		inst := strings.Join(p.InstallArgs(), " ")
		if !strings.Contains(inst, "--user 0:0") || !strings.Contains(inst, "/src /deps") {
			t.Fatalf("%s: install args = %s", pf.Runtime, inst)
		}
	}

	// the Bun packages are mounted from node_modules of the install directory
	pf, dir := project(t, "name: b\ntype: faas\nruntime: bun\n", map[string]string{"main.ts": "export const handler = () => 1\n", "package.json": "{}"})
	p, err := MakePlan(context.Background(), opts(pf, dir, t.TempDir()))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(p.RunArgs(), " "), filepath.Join(p.DepsDir, "node_modules")+",target=/app/code/node_modules,readonly") {
		t.Fatalf("bun deps mount: %v", p.RunArgs())
	}
}
