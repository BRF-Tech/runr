package scaffold

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/internal/projectfile"
	"github.com/runrsh/core/cli/internal/runtimes"
)

func TestEveryRuntimeAndTypeGivesAValidProject(t *testing.T) {
	for _, typ := range []string{"faas", "xaas"} {
		for _, rt := range runtimes.IDs() {
			dir := filepath.Join(t.TempDir(), "p")
			written, err := Write(Options{Dir: dir, Name: "demo", Type: typ, Runtime: rt, RuntimeVersion: "1.0", Region: "eu-1"})
			if err != nil {
				t.Fatalf("%s/%s: %v", typ, rt, err)
			}
			pf, err := projectfile.Load(filepath.Join(dir, "runr.yaml"))
			if err != nil {
				t.Fatalf("%s/%s: runr.yaml invalid: %v", typ, rt, err)
			}
			if pf.RuntimeVersion != "1.0" || pf.Region != "eu-1" || pf.Type != typ {
				t.Fatalf("%s/%s: %+v", typ, rt, pf)
			}
			main, err := os.ReadFile(filepath.Join(dir, pf.MainFile()))
			if err != nil || len(main) == 0 {
				t.Fatalf("%s/%s: handler file %s: %v", typ, rt, pf.MainFile(), err)
			}
			if len(written) != 3 {
				t.Fatalf("%s/%s: wrote %v", typ, rt, written)
			}
		}
	}
}

func TestStarterMatchesTheAPIHandlerCheck(t *testing.T) {
	// The API refuses code without the handler (CodeController::validateHandlerExists).
	checks := map[string]string{
		"python": "def handler(",
		"nodejs": "exports.handler",
		"go":     "func Handler(",
		"php":    "function handler(",
	}
	for rt, want := range checks {
		if !strings.Contains(runtimes.Starter(rt), want) {
			t.Errorf("%s starter lacks %q", rt, want)
		}
	}
}

func TestCaasAndDefaults(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "My Web")
	if _, err := Write(Options{Dir: dir, Type: "caas"}); err != nil {
		t.Fatal(err)
	}
	pf, err := projectfile.Load(filepath.Join(dir, "runr.yaml"))
	if err != nil {
		t.Fatal(err)
	}
	if pf.Name != "my-web" || pf.Image != DefaultCaaSImage || len(pf.Ports) != 1 {
		t.Fatalf("caas = %+v", pf)
	}
	if _, err := os.Stat(filepath.Join(dir, "main.py")); err == nil {
		t.Fatal("caas got a handler file")
	}
}

func TestRefusesOverwriteButKeepsGitignore(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, ".gitignore"), []byte("mine\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := Write(Options{Dir: dir, Name: "a"}); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, ".gitignore")); string(b) != "mine\n" {
		t.Fatal("an existing .gitignore was replaced")
	}
	if _, err := Write(Options{Dir: dir, Name: "a"}); err == nil || !strings.Contains(err.Error(), "exists") {
		t.Fatalf("overwrite: %v", err)
	}
	if _, err := Write(Options{Dir: dir, Name: "a", Force: true}); err != nil {
		t.Fatal(err)
	}
	if _, err := Write(Options{Dir: t.TempDir(), Name: "1bad"}); err == nil {
		t.Fatal("invalid name accepted")
	}
}
