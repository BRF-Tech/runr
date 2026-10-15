package cmd

import (
	"archive/tar"
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func TestSandboxCreateLsGetRm(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("sandbox", "create", "--runtime", "python", "--ttl", "30m", "--allow", "pypi.org:443",
		"-e", "API_KEY=s3cret", "--label", "agent=planner", "--class", "kata", "--idle-timeout", "0")
	id := strings.TrimSpace(r.out)
	if !strings.HasPrefix(id, "01j") || strings.Contains(id, "\n") {
		t.Fatalf("create printed %q, want the sandbox id only", r.out)
	}
	contains(t, r.err, "running: kata-fc in eu-1")
	body := h.srv.CallsTo("POST /sandboxes")[0].Body
	if body["ttl_seconds"] != float64(1800) || body["idle_timeout"] != float64(0) || body["sandbox_class"] != "kata" {
		t.Fatalf("create body = %v", body)
	}
	if env, _ := body["env"].(map[string]any); env["API_KEY"] != "s3cret" {
		t.Fatalf("env = %v", body["env"])
	}

	var list []runr.Sandbox
	decodeJSON(t, h.mustRun("sandbox", "ls", "-o", "json").out, &list)
	if len(list) != 1 || list[0].ID != id || list[0].EnvKeys[0] != "API_KEY" {
		t.Fatalf("ls = %+v", list)
	}
	r = h.mustRun("sandbox", "ls", "--region", "eu-1")
	contains(t, r.out, id)
	contains(t, r.out, "python 3.13")

	r = h.mustRun("sandbox", "get", id)
	contains(t, r.out, "pypi.org:443")
	contains(t, r.out, "agent=planner")
	notContains(t, r.out, "s3cret")

	h.expectCode(ExitUsage, "sandbox", "rm", id)
	h.expectCode(ExitUsage, "sandbox", "rm")
	h.mustRun("sandbox", "rm", id, "--yes")
	if s, _ := h.srv.Sandbox(id); s.Status != runr.SandboxTerminated {
		t.Fatalf("after rm: %+v", s)
	}
	h.expectCode(ExitNotFound, "sandbox", "get", "01jnosuchsandbox0000000000")
}

func TestSandboxCreateWaitsAndFails(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetSandboxStart(2, false)
	r := h.mustRun("sandbox", "create")
	contains(t, r.err, "is starting")
	id := strings.TrimSpace(r.out)
	if s, _ := h.srv.Sandbox(id); s.Status != runr.SandboxRunning {
		t.Fatalf("after create: %+v", s)
	}

	r = h.mustRun("sandbox", "create", "--no-wait", "-o", "json")
	var s runr.Sandbox
	decodeJSON(t, r.out, &s)
	if s.Status != runr.SandboxPending {
		t.Fatalf("--no-wait: %+v", s)
	}

	h.srv.SetSandboxStart(0, true)
	r = h.expectCode(ExitFailed, "sandbox", "create", "--image", "ghcr.io/acme/unpullable:1")
	contains(t, r.err, "could not start")
	h.expectCode(ExitValidation, "sandbox", "create", "--runtime", "cobol")
}

func TestSandboxExecStreamsAndPassesTheExitCode(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 3, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("step 1\n")},
			{Stream: "stderr", Data: []byte("careful\n")},
			{Stream: "stdout", Data: []byte("step 2\n")},
		}}
	})
	r := h.expectCode(3, "sandbox", "exec", "--cwd", "/workspace/app", "-e", "DEBUG=1", "--timeout", "90s",
		sb.ID, "--", "python3", "-u", "train.py", "--epochs", "3")
	if r.out != "step 1\nstep 2\n" {
		t.Fatalf("stdout = %q", r.out)
	}
	contains(t, r.err, "careful")
	notContains(t, r.err, "Error:")
	call := h.srv.SandboxCalls()[0]
	if strings.Join(call.Cmd, " ") != "python3 -u train.py --epochs 3" || call.Cwd != "/workspace/app" ||
		call.Env["DEBUG"] != "1" || call.Timeout != 90 || !call.Stream {
		t.Fatalf("call = %+v", call)
	}

	// One argument runs through the shell; flags after the id belong to the command.
	h.srv.SetSandboxExec(nil)
	r = h.mustRun("sandbox", "exec", sb.ID, "ls -la | wc -l")
	contains(t, r.out, "ran /bin/sh -c ls -la | wc -l")
	h.mustRun("sandbox", "exec", sb.ID, "ls", "-la")
	if got := h.srv.SandboxCalls()[2].Cmd; strings.Join(got, " ") != "ls -la" {
		t.Fatalf("argv = %v", got)
	}
	h.expectCode(ExitUsage, "sandbox", "exec", sb.ID)
	h.expectCode(ExitUsage, "sandbox", "exec", sb.ID, "--")
}

func TestSandboxExecTimeoutWarnsAndJSON(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 137, TimedOut: true, Output: []runrtest.SandboxChunk{{Stream: "stdout", Data: []byte("slow\n")}}}
	})
	r := h.expectCode(137, "sandbox", "exec", sb.ID, "--", "sleep", "999")
	contains(t, r.err, "killed at its timeout")

	h.srv.SetSandboxExec(nil)
	r = h.mustRun("sandbox", "exec", "-o", "json", sb.ID, "--", "echo", "hi")
	var res runr.SandboxExecResult
	decodeJSON(t, r.out, &res)
	if res.ExitCode != 0 || res.Stdout != "ran echo hi\n" {
		t.Fatalf("json result = %+v", res)
	}
	if h.srv.SandboxCalls()[1].Stream {
		t.Fatal("-o json used the event stream")
	}
}

func TestSandboxExecStdin(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	r := h.runStdin("a,b\n1,2\n", "sandbox", "exec", "-i", sb.ID, "--", "python3", "load.py")
	if r.code != 0 {
		t.Fatalf("exit %d: %s", r.code, r.err)
	}
	if got := string(h.srv.SandboxCalls()[0].Stdin); got != "a,b\n1,2\n" {
		t.Fatalf("stdin = %q", got)
	}
}

func TestSandboxExecOnRemovedSandbox(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.mustRun("sandbox", "rm", sb.ID, "--yes")
	r := h.expectCode(ExitValidation, "sandbox", "exec", sb.ID, "--", "ls")
	contains(t, r.err, "not running")
}

func TestSandboxRunThrowAway(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{Output: []runrtest.SandboxChunk{{Stream: "stdout", Data: []byte("drew a chart\n")}},
			FilesChanged: []runr.SandboxFileChange{{Path: "/output/chart.png", Size: 4}},
			Outputs:      []runr.SandboxOutput{{Path: "/output/chart.png", Mime: "image/png", Size: 4, DataBase64: "iVBORw=="}}}
	})
	dir := t.TempDir()
	write(t, filepath.Join(dir, "analysis.py"), "import matplotlib\n")
	out := filepath.Join(dir, "charts")
	r := h.mustRun("sandbox", "run", filepath.Join(dir, "analysis.py"), "--outputs", out)
	if r.out != "drew a chart\n" {
		t.Fatalf("stdout = %q", r.out)
	}
	contains(t, r.err, "changed /output/chart.png")
	contains(t, r.err, "Removed sandbox")
	if b, err := os.ReadFile(filepath.Join(out, "chart.png")); err != nil || !bytes.HasPrefix(b, []byte("\x89PNG")) {
		t.Fatalf("saved output = %q, %v", b, err)
	}
	create := h.srv.CallsTo("POST /sandboxes")[0].Body
	if create["runtime"] != "python" {
		t.Fatalf("throw-away sandbox = %v", create)
	}
	call := h.srv.SandboxCalls()[0]
	if call.Language != "python" || call.Code != "import matplotlib\n" {
		t.Fatalf("run call = %+v", call)
	}
	id := h.srv.CallsTo("DELETE /sandboxes/{sandbox}")[0].Path
	if !strings.HasPrefix(id, "/sandboxes/") {
		t.Fatalf("delete path = %q", id)
	}
}

func TestSandboxRunInExistingAndKeep(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("nodejs")
	h.srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 2, Output: []runrtest.SandboxChunk{{Stream: "stderr", Data: []byte("boom\n")}}}
	})
	r := h.runStdin("console.log(1)", "sandbox", "run", "-", "--lang", "javascript", "--sandbox", sb.ID)
	if r.code != 2 {
		t.Fatalf("exit %d, want 2: %s", r.code, r.err)
	}
	contains(t, r.err, "boom")
	if len(h.srv.CallsTo("POST /sandboxes")) != 0 || len(h.srv.CallsTo("DELETE /sandboxes/{sandbox}")) != 0 {
		t.Fatal("run --sandbox created or removed a sandbox")
	}

	h.srv.SetSandboxExec(nil)
	r = h.mustRun("sandbox", "run", "--code", "print(1)", "--keep", "-o", "json")
	var out struct {
		SandboxID string                 `json:"sandbox_id"`
		Kept      bool                   `json:"kept"`
		Result    runr.SandboxExecResult `json:"result"`
	}
	decodeJSON(t, r.out, &out)
	if !out.Kept || out.SandboxID == "" || !strings.Contains(out.Result.Stdout, "ran python code") {
		t.Fatalf("json = %+v", out)
	}
	if s, _ := h.srv.Sandbox(out.SandboxID); s.Status != runr.SandboxRunning {
		t.Fatalf("--keep removed the sandbox: %+v", s)
	}
	h.expectCode(ExitUsage, "sandbox", "run")
	h.expectCode(ExitUsage, "sandbox", "run", "x.py", "--code", "print(1)")
}

func TestSandboxCpUpAndDown(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	dir := t.TempDir()
	write(t, filepath.Join(dir, "src", "main.py"), "print(1)\n")
	write(t, filepath.Join(dir, "src", "pkg", "util.py"), "X = 1\n")
	write(t, filepath.Join(dir, "data.csv"), "a,b\n")

	h.mustRun("sandbox", "cp", filepath.Join(dir, "src"), sb.ID+":/workspace/src")
	if b, ok := h.srv.SandboxFile(sb.ID, "/workspace/src/pkg/util.py"); !ok || string(b) != "X = 1\n" {
		t.Fatalf("uploaded tree: %q %v", b, ok)
	}
	if q := h.srv.CallsTo("PUT /sandboxes/{sandbox}/files/archive")[0].Query; q != "path=%2Fworkspace%2Fsrc" {
		t.Fatalf("archive query = %q", q)
	}
	h.mustRun("sandbox", "cp", filepath.Join(dir, "data.csv"), sb.ID+":")
	if b, ok := h.srv.SandboxFile(sb.ID, "/workspace/data.csv"); !ok || string(b) != "a,b\n" {
		t.Fatalf("ID: did not copy into /workspace: %q %v", b, ok)
	}
	// An existing directory without a trailing slash receives the file too.
	h.mustRun("sandbox", "cp", filepath.Join(dir, "data.csv"), sb.ID+":/workspace/src")
	if _, ok := h.srv.SandboxFile(sb.ID, "/workspace/src/data.csv"); !ok {
		t.Fatal("copy into an existing directory")
	}
	h.mustRun("sandbox", "cp", filepath.Join(dir, "data.csv"), sb.ID+":/workspace/renamed.csv")
	if _, ok := h.srv.SandboxFile(sb.ID, "/workspace/renamed.csv"); !ok {
		t.Fatal("copy to a new file name")
	}

	h.srv.SetSandboxFile(sb.ID, "/output/report.txt", []byte("done\n"))
	h.srv.SetSandboxFile(sb.ID, "/output/img/a.png", []byte("PNG"))
	local := filepath.Join(dir, "results")
	h.mustRun("sandbox", "cp", sb.ID+":/output", local)
	if b, err := os.ReadFile(filepath.Join(local, "img", "a.png")); err != nil || string(b) != "PNG" {
		t.Fatalf("downloaded tree: %q %v", b, err)
	}
	h.mustRun("sandbox", "cp", sb.ID+":/output/report.txt", local)
	if b, err := os.ReadFile(filepath.Join(local, "report.txt")); err != nil || string(b) != "done\n" {
		t.Fatalf("downloaded file: %q %v", b, err)
	}
	r := h.mustRun("sandbox", "cp", sb.ID+":/output/report.txt", "-")
	if r.out != "done\n" {
		t.Fatalf("cp to stdout = %q", r.out)
	}
	r = h.runStdin("from stdin", "sandbox", "cp", "-", sb.ID+":notes.txt")
	if b, _ := h.srv.SandboxFile(sb.ID, "/workspace/notes.txt"); r.code != 0 || string(b) != "from stdin" {
		t.Fatalf("cp from stdin: exit %d, %q", r.code, b)
	}

	h.expectCode(ExitUsage, "sandbox", "cp", "a", "b")
	h.expectCode(ExitUsage, "sandbox", "cp", sb.ID+":/a", sb.ID+":/b")
	h.expectCode(ExitNotFound, "sandbox", "cp", sb.ID+":/workspace/absent.txt", local)
}

func TestSandboxPathParsing(t *testing.T) {
	cases := map[string][2]string{
		"01jb2x7k:/workspace/a": {"01jb2x7k", "/workspace/a"},
		"01jb2x7k:":             {"01jb2x7k", "/workspace/"},
		"01jb2x7k:data/x.csv":   {"01jb2x7k", "/workspace/data/x.csv"},
	}
	for in, want := range cases {
		id, p, ok := sandboxPath(in)
		if !ok || id != want[0] || p != want[1] {
			t.Errorf("sandboxPath(%q) = %q %q %v", in, id, p, ok)
		}
	}
	for _, local := range []string{`C:\Users\ada\x.txt`, "./a:b", "file.txt", "../x:y", "-"} {
		if _, _, ok := sandboxPath(local); ok {
			t.Errorf("sandboxPath(%q) is a sandbox path", local)
		}
	}
}

func TestSandboxTarRoundTripStaysInside(t *testing.T) {
	dir := t.TempDir()
	write(t, filepath.Join(dir, "a", "b.txt"), "B")
	tarball, files, skipped, err := tarDirectory(dir)
	if err != nil || files != 1 || len(skipped) != 0 {
		t.Fatalf("tar: %d files, skipped %v, %v", files, skipped, err)
	}
	out := t.TempDir()
	if n, _, err := untarInto(bytes.NewReader(tarball), out); err != nil || n != 1 {
		t.Fatalf("untar: %d, %v", n, err)
	}
	if b, _ := os.ReadFile(filepath.Join(out, "a", "b.txt")); string(b) != "B" {
		t.Fatalf("content = %q", b)
	}

	var evil bytes.Buffer
	tw := tar.NewWriter(&evil)
	_ = tw.WriteHeader(&tar.Header{Name: "../../escape.txt", Typeflag: tar.TypeReg, Size: 1, Mode: 0o644})
	_, _ = tw.Write([]byte("x"))
	_ = tw.Close()
	// path.Clean("/" + name) keeps it inside: ../../escape.txt lands as escape.txt.
	if _, _, err := untarInto(&evil, out); err != nil {
		t.Fatalf("cleaned entry refused: %v", err)
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(out), "escape.txt")); err == nil {
		t.Fatal("an archive entry left the target directory")
	}
	if b, _ := os.ReadFile(filepath.Join(out, "escape.txt")); string(b) != "x" {
		t.Fatalf("cleaned entry = %q", b)
	}
}

func TestSandboxLogsAndFiles(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxFile(sb.ID, "/workspace/main.py", []byte("print(1)\n"))
	h.mustRun("sandbox", "exec", sb.ID, "--", "python3", "main.py")
	r := h.mustRun("sandbox", "files", sb.ID)
	contains(t, r.out, "main.py")
	r = h.mustRun("sandbox", "logs", sb.ID)
	contains(t, r.out, "exec")
	contains(t, r.out, "python3 main.py")
}
