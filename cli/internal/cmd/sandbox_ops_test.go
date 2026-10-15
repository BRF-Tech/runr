package cmd

import (
	"net/url"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

func TestSandboxFilesMkdirRm(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxFile(sb.ID, "/workspace/data/raw/a.csv", []byte("a,b\n"))
	h.srv.SetSandboxFile(sb.ID, "/workspace/notes.txt", []byte("hi"))

	// mkdir: relative paths are under /workspace, parents are created.
	r := h.mustRun("sandbox", "files", "mkdir", sb.ID, "data/clean/2026", "/output/charts")
	contains(t, r.err, "Created "+sb.ID+":/workspace/data/clean/2026.")
	contains(t, r.err, "Created "+sb.ID+":/output/charts.")
	if dirs := strings.Join(h.srv.SandboxDirs(sb.ID), ","); !strings.Contains(dirs, "/workspace/data/clean/2026") ||
		!strings.Contains(dirs, "/workspace/data/clean") || !strings.Contains(dirs, "/output/charts") {
		t.Fatalf("dirs = %s", dirs)
	}
	if body := h.srv.CallsTo("POST /sandboxes/{sandbox}/files/mkdir")[0].Body; body["path"] != "/workspace/data/clean/2026" {
		t.Fatalf("mkdir body = %v", body)
	}
	contains(t, h.mustRun("sandbox", "files", sb.ID, "data").out, "clean/")

	var made []runr.SandboxPathResult
	decodeJSON(t, h.mustRun("sandbox", "files", "mkdir", sb.ID, "tmp1", "-o", "json").out, &made)
	if len(made) != 1 || made[0].Path != "/workspace/tmp1" {
		t.Fatalf("mkdir json = %+v", made)
	}
	h.expectCode(ExitValidation, "sandbox", "files", "mkdir", sb.ID, "/etc/runr")
	h.expectCode(ExitUsage, "sandbox", "files", "mkdir", sb.ID)

	// rm: a file, then a directory that needs -r.
	r = h.mustRun("sandbox", "files", "rm", sb.ID, "notes.txt")
	contains(t, r.err, "Removed "+sb.ID+":/workspace/notes.txt.")
	if _, ok := h.srv.SandboxFile(sb.ID, "/workspace/notes.txt"); ok {
		t.Fatal("notes.txt is still there")
	}
	r = h.expectCode(ExitValidation, "sandbox", "files", "rm", sb.ID, "data")
	contains(t, r.err, "Directory not empty")
	var removed []runr.SandboxPathResult
	decodeJSON(t, h.mustRun("sandbox", "files", "rm", "-r", sb.ID, "data", "-o", "json").out, &removed)
	if len(removed) != 1 || removed[0].Path != "/workspace/data" || !removed[0].Deleted {
		t.Fatalf("rm json = %+v", removed)
	}
	if _, ok := h.srv.SandboxFile(sb.ID, "/workspace/data/raw/a.csv"); ok {
		t.Fatal("rm -r left a file")
	}
	calls := h.srv.CallsTo("DELETE /sandboxes/{sandbox}/files")
	q, _ := url.ParseQuery(calls[len(calls)-1].Query)
	if q.Get("path") != "/workspace/data" || q.Get("recursive") != "1" {
		t.Fatalf("rm -r query = %q", calls[len(calls)-1].Query)
	}
	q, _ = url.ParseQuery(calls[0].Query)
	if q.Has("recursive") {
		t.Fatalf("rm without -r sent recursive: %q", calls[0].Query)
	}

	// A missing path: exit code 4, or nothing with -f; a missing sandbox stays an error with -f.
	h.expectCode(ExitNotFound, "sandbox", "files", "rm", sb.ID, "gone.txt")
	r = h.mustRun("sandbox", "files", "rm", "-f", sb.ID, "gone.txt")
	contains(t, r.err, "does not exist")
	h.expectCode(ExitNotFound, "sandbox", "files", "rm", "-f", "01jnosuchsandbox0000000000", "gone.txt")
	h.expectCode(ExitValidation, "sandbox", "files", "rm", "-r", sb.ID, "/")

	// The listing still works with a sandbox id as its first argument.
	contains(t, h.mustRun("sandbox", "files", sb.ID).out, "tmp1/")
}

func TestSandboxLogsPod(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")

	r := h.mustRun("sandbox", "logs", sb.ID, "--pod")
	contains(t, r.out, "worker-1")
	contains(t, r.out, "Running")
	contains(t, r.out, "Ready=True")
	contains(t, r.out, "Scheduled")
	contains(t, r.out, "runr sandbox ready")
	call := h.srv.CallsTo("GET /sandboxes/{sandbox}/logs")[0]
	if q, _ := url.ParseQuery(call.Query); q.Get("pod") != "1" {
		t.Fatalf("query = %q", call.Query)
	}

	var out struct {
		Events []runr.SandboxEvent `json:"events"`
		Pod    *runr.SandboxPod    `json:"pod"`
	}
	decodeJSON(t, h.mustRun("sandbox", "logs", sb.ID, "--pod", "-o", "json").out, &out)
	if out.Pod == nil || out.Pod.Phase != "Running" || len(out.Pod.Conditions) != 2 || len(out.Events) == 0 {
		t.Fatalf("json = %+v", out)
	}

	// Without --pod the log is asked for without the pod.
	h.mustRun("sandbox", "logs", sb.ID)
	calls := h.srv.CallsTo("GET /sandboxes/{sandbox}/logs")
	if calls[len(calls)-1].Query != "" {
		t.Fatalf("logs without --pod sent %q", calls[len(calls)-1].Query)
	}

	// An ended sandbox has no pod.
	h.mustRun("sandbox", "rm", sb.ID, "--yes")
	contains(t, h.mustRun("sandbox", "logs", sb.ID, "--pod").out, "the sandbox has ended")

	h.expectCode(ExitUsage, "sandbox", "logs", sb.ID, "01jprocess", "--pod")
}

func TestSandboxMetricsPrometheus(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedSandbox("python")

	r := h.mustRun("sandbox", "metrics", "--prometheus")
	contains(t, r.out, "# TYPE runr_sandboxes_active gauge")
	contains(t, r.out, "runr_sandboxes_active 1")

	h.expectCode(ExitUsage, "sandbox", "metrics", "--prometheus", "-o", "json")
	h.expectCode(ExitUsage, "sandbox", "metrics", "--prometheus", "--window", "7d")

	h.loginWith(h.srv.ScopedToken("viewer", "sandboxes.view"))
	h.mustRun("sandbox", "metrics")
	r = h.expectCode(ExitAuth, "sandbox", "metrics", "--prometheus")
	contains(t, r.err, "sandboxes.manage")
}
