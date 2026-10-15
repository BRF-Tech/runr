package cmd

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
)

func writeFile(t *testing.T, name, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), name)
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestImagesPushSendsChunksAndPrintsTheImage(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Images().PushPolls = 1
	file := writeFile(t, "My App.tar", "0123456789")

	r := h.mustRun("images", "push", file, "--region", "eu-1", "-o", "json")

	var img runr.UploadedImage
	if err := json.Unmarshal([]byte(r.out), &img); err != nil {
		t.Fatalf("output %q: %v", r.out, err)
	}
	if img.Name != "my-app:latest" || img.RegionName != "eu-1" || img.Digest == "" {
		t.Fatalf("image %+v", img)
	}

	starts := h.srv.CallsTo("POST /images/uploads")
	if len(starts) != 1 {
		t.Fatalf("%d uploads started", len(starts))
	}
	sum := sha256.Sum256([]byte("0123456789"))
	if starts[0].Body["sha256"] != hex.EncodeToString(sum[:]) || starts[0].Body["filename"] != "My App.tar" || starts[0].Body["size"] != float64(10) {
		t.Fatalf("start body %v", starts[0].Body)
	}

	var offsets []string
	for _, c := range h.srv.CallsTo("PUT /images/uploads/{upload}/chunk") {
		offsets = append(offsets, strings.TrimPrefix(c.Query, "offset="))
	}
	if strings.Join(offsets, ",") != "0,4,8" {
		t.Fatalf("chunk offsets %v", offsets)
	}
	if len(h.srv.CallsTo("POST /images/uploads/{upload}/complete")) != 1 {
		t.Fatal("upload not completed")
	}
	if len(h.srv.UploadedImages()) != 1 {
		t.Fatal("the fake has no uploaded image")
	}
	if !strings.Contains(r.err, "Pushing into the registry of eu-1") {
		t.Fatalf("progress %q", r.err)
	}
}

func TestImagesPushResumesAnUnfinishedUpload(t *testing.T) {
	h := newHarness(t).login()
	content := "0123456789"
	sum := sha256.Sum256([]byte(content))
	up := h.srv.SeedUpload("web:1.0", "eu-1", "web.tar", 10, hex.EncodeToString(sum[:]), []byte("0123"))
	file := writeFile(t, "web.tar", content)

	h.mustRun("images", "push", file, "--region", "eu-1", "--name", "web:1.0")

	if n := len(h.srv.CallsTo("POST /images/uploads")); n != 0 {
		t.Fatalf("%d new uploads, want the resume", n)
	}
	chunks := h.srv.CallsTo("PUT /images/uploads/{upload}/chunk")
	if len(chunks) != 2 || chunks[0].Query != "offset=4" {
		t.Fatalf("chunks %+v", chunks)
	}
	if string(h.srv.ReceivedUpload(up.ID)) != content {
		t.Fatalf("received %q", h.srv.ReceivedUpload(up.ID))
	}
}

func TestImagesPushReportsAFailedPush(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Images().PushError = "the archive holds 2 images and none is named \"latest\""
	file := writeFile(t, "two.tar", "abcdef")

	r := h.run("images", "push", file)
	if r.code == 0 || !strings.Contains(r.err, "none is named") {
		t.Fatalf("exit %d, stderr %q", r.code, r.err)
	}
}

func TestImagesLsAndRm(t *testing.T) {
	h := newHarness(t).login()
	h.srv.AddRegion("tr-1", true)
	h.srv.SeedImage("web:1.0", "eu-1")
	h.srv.SeedImage("web:1.0", "tr-1")
	h.srv.SeedImage("q-interpreter:1.0", "eu-1")

	r := h.mustRun("images", "ls")
	for _, want := range []string{"NAME", "web:1.0", "q-interpreter:1.0", "eu-1", "tr-1", "of 50.0 GiB used"} {
		if !strings.Contains(r.out, want) {
			t.Fatalf("ls output lacks %q:\n%s", want, r.out)
		}
	}
	only := h.mustRun("images", "ls", "--region", "tr-1", "-o", "json")
	var list []runr.UploadedImage
	if err := json.Unmarshal([]byte(only.out), &list); err != nil || len(list) != 1 || list[0].RegionName != "tr-1" {
		t.Fatalf("ls --region: %v %+v", err, list)
	}

	// a name in two regions needs --region
	amb := h.run("images", "rm", "web:1.0", "--yes")
	if amb.code == 0 || !strings.Contains(amb.err, "several regions") {
		t.Fatalf("rm without region: exit %d %q", amb.code, amb.err)
	}
	h.mustRun("images", "rm", "web:1.0", "--region", "tr-1", "--yes")
	if n := len(h.srv.UploadedImages()); n != 2 {
		t.Fatalf("%d images left, want 2", n)
	}
	if code := h.run("images", "rm", "nope", "--yes").code; code != ExitNotFound {
		t.Fatalf("rm of an unknown image: exit %d", code)
	}
}
