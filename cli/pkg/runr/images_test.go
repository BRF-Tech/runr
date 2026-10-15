package runr_test

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func TestNameFromFilename(t *testing.T) {
	for in, want := range map[string]string{
		"myapp.tar.gz":           "myapp",
		`C:\Users\me\My App.tar`: "my-app",
		"Q Interpreter.wasm":     "q-interpreter",
		".tar":                   "image",
		"web-1.2.oci":            "web-1-2",
	} {
		if got := runr.NameFromFilename(in); got != want {
			t.Errorf("NameFromFilename(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestFindImage(t *testing.T) {
	images := []runr.UploadedImage{
		{ID: "01J0000000000000000000000A", Name: "web:1.0", RegionID: "r1", RegionName: "eu-1"},
		{ID: "01J0000000000000000000000B", Name: "web:1.0", RegionID: "r2", RegionName: "tr-1"},
		{ID: "01J0000000000000000000000C", Name: "api:latest", RegionID: "r1", RegionName: "eu-1"},
	}
	if img, err := runr.FindImage(images, "api", ""); err != nil || img.ID != "01J0000000000000000000000C" {
		t.Fatalf("api: %v %v", img, err)
	}
	if img, err := runr.FindImage(images, "web:1.0", "r2"); err != nil || img.RegionName != "tr-1" {
		t.Fatalf("web in r2: %v %v", img, err)
	}
	if img, err := runr.FindImage(images, "01J0000000000000000000000B", ""); err != nil || img.Name != "web:1.0" {
		t.Fatalf("by id: %v %v", img, err)
	}
	if _, err := runr.FindImage(images, "web:1.0", ""); err == nil || !strings.Contains(err.Error(), "eu-1, tr-1") {
		t.Fatalf("ambiguous: %v", err)
	}
	if _, err := runr.FindImage(images, "nope", ""); err == nil {
		t.Fatal("unknown image found")
	}
}

func TestPushImageAgainstTheFake(t *testing.T) {
	srv := runrtest.New(t)
	srv.Images().PreparePolls = 1
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client()})
	if err != nil {
		t.Fatal(err)
	}
	region, err := c.FindRegion(context.Background(), "eu-1")
	if err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(t.TempDir(), "mod.wasm")
	os.WriteFile(file, []byte("\x00asm\x01\x00\x00\x00body"), 0o600)

	var phases []string
	img, err := c.PushImage(context.Background(), file, runr.PushOptions{
		RegionID: region.ID, Name: "q-interpreter:1.0", PollInterval: 1,
		Progress: func(phase string, uploaded, total int64) { phases = append(phases, phase) },
	})
	if err != nil {
		t.Fatal(err)
	}
	if img.Name != "q-interpreter:1.0" {
		t.Fatalf("image %+v", img)
	}
	start := srv.CallsTo("POST /images/uploads")[0]
	if start.Body["format"] != "wasm" || start.Body["name"] != "q-interpreter:1.0" {
		t.Fatalf("start body %v", start.Body)
	}
	if phases[0] != "preparing" || !contains(phases, "uploading") || phases[len(phases)-1] != "completed" {
		t.Fatalf("phases %v", phases)
	}
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}
