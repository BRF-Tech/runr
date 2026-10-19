package cmd

import (
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func sbxProcessReply(code int) runrtest.SandboxExecFunc {
	return func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: code, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("listening on :8000\n")},
			{Stream: "stderr", Data: []byte("debug mode\n")},
		}}
	}
}

func TestSandboxStartPsLogsKill(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxExec(sbxProcessReply(4))

	r := h.mustRun("sandbox", "start", "--cwd", "/workspace/site", "-e", "PORT=8000", sb.ID, "--", "python3", "-m", "http.server")
	pid := strings.TrimSpace(r.out)
	if pid == "" || strings.Contains(pid, "\n") {
		t.Fatalf("start printed %q, want the process id only", r.out)
	}
	contains(t, r.err, "Started process "+pid)
	body := h.srv.CallsTo("POST /sandboxes/{sandbox}/processes")[0].Body
	if cmd, _ := body["cmd"].([]any); len(cmd) != 3 || body["cwd"] != "/workspace/site" {
		t.Fatalf("start body = %v", body)
	}
	h.expectCode(ExitUsage, "sandbox", "start", sb.ID)

	r = h.mustRun("sandbox", "ps", sb.ID)
	contains(t, r.out, pid)
	contains(t, r.out, "python3 -m http.server")
	contains(t, r.out, "running")

	r = h.mustRun("sandbox", "logs", sb.ID, pid)
	if r.out != "listening on :8000\n" {
		t.Fatalf("logs stdout = %q", r.out)
	}
	contains(t, r.err, "debug mode")
	var tail runr.SandboxProcessLogs
	decodeJSON(t, h.mustRun("sandbox", "logs", sb.ID, pid, "--tail", "6", "-o", "json").out, &tail)
	if tail.Stdout != ":8000\n" || !tail.Truncated {
		t.Fatalf("tail json = %+v", tail)
	}

	// -f streams until the process ends and exits with its exit code.
	r = h.expectCode(4, "sandbox", "logs", sb.ID, pid, "-f")
	if r.out != "listening on :8000\n" {
		t.Fatalf("followed stdout = %q", r.out)
	}
	contains(t, r.err, "debug mode")
	notContains(t, r.err, "Error:")
	h.expectCode(ExitUsage, "sandbox", "logs", sb.ID, "-f")

	// The activity log still works with the sandbox id alone.
	contains(t, h.mustRun("sandbox", "logs", sb.ID).out, "process.start")

	r = h.mustRun("sandbox", "start", sb.ID, "sleep 600")
	second := strings.TrimSpace(r.out)
	h.expectCode(ExitUsage, "sandbox", "kill", sb.ID, second, "--signal", "BOGUS")
	var killed runr.SandboxProcess
	decodeJSON(t, h.mustRun("sandbox", "kill", sb.ID, second, "--signal", "sigint", "-o", "json").out, &killed)
	if killed.Status != runr.ProcessKilled {
		t.Fatalf("killed = %+v", killed)
	}
	if q := h.srv.CallsTo("DELETE /sandboxes/{sandbox}/processes/{process}")[0].Query; q != "signal=INT" {
		t.Fatalf("kill query = %q", q)
	}
	// Following a killed process ends with 137.
	h.expectCode(137, "sandbox", "logs", sb.ID, second, "-f")
}

func TestSandboxStartFollowAndJSON(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxExec(sbxProcessReply(0))
	r := h.mustRun("sandbox", "start", "-f", sb.ID, "--", "make", "build")
	if r.out != "listening on :8000\n" {
		t.Fatalf("start -f stdout = %q", r.out)
	}
	var out map[string]any
	decodeJSON(t, h.mustRun("sandbox", "start", "-f", "-o", "json", sb.ID, "make").out, &out)
	if out["stdout"] != "listening on :8000\n" || out["stderr"] != "debug mode\n" || out["exit_code"] != float64(0) || out["status"] != "exited" {
		t.Fatalf("start -f -o json = %v", out)
	}
	var p runr.SandboxProcess
	decodeJSON(t, h.mustRun("sandbox", "start", "-o", "json", sb.ID, "make").out, &p)
	if p.ID == "" || p.Status != runr.ProcessRunning {
		t.Fatalf("start -o json = %+v", p)
	}
}

func TestSandboxPauseResumeExtendCmd(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	contains(t, h.mustRun("sandbox", "pause", sb.ID).err, "paused")
	if s, _ := h.srv.Sandbox(sb.ID); s.Status != runr.SandboxPaused {
		t.Fatalf("after pause: %s", s.Status)
	}
	h.expectCode(ExitValidation, "sandbox", "exec", sb.ID, "true") // 409: not running
	h.mustRun("sandbox", "resume", sb.ID)
	h.expectCode(ExitValidation, "sandbox", "resume", sb.ID)

	var s runr.Sandbox
	decodeJSON(t, h.mustRun("sandbox", "extend", sb.ID, "--ttl", "45m", "-o", "json").out, &s)
	if s.TTLSeconds != 2700 || s.ExpiresAt == "" {
		t.Fatalf("extend = %+v", s)
	}
	h.expectCode(ExitUsage, "sandbox", "extend", sb.ID, "--ttl", "2s")
	h.expectCode(ExitUsage, "sandbox", "extend", sb.ID)
	h.mustRun("sandbox", "keepalive", sb.ID)
	h.expectCode(ExitNotFound, "sandbox", "pause", "01jnosuchsandbox0000000000")
}

func TestSandboxTerminalCmd(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")

	r := h.runStdin("echo hi\nexit\n", "sandbox", "terminal", sb.ID)
	if r.code != 0 {
		t.Fatalf("terminal: exit %d\nstdout: %s\nstderr: %s", r.code, r.out, r.err)
	}
	contains(t, r.out, "echo hi")
	contains(t, r.out, "[Process exited]")
	contains(t, r.err, "Connected to sbx-"+sb.ID)
	logs := h.srv.SandboxTerminals()
	if len(logs) != 1 || !strings.HasPrefix(string(logs[0].Input), "echo hi\nexit\n") || logs[0].Cols != 80 || logs[0].Rows != 24 {
		t.Fatalf("terminal sessions = %+v", logs)
	}
	if logs[0].Pongs != 1 {
		t.Fatalf("the client answered %d pings, want 1", logs[0].Pongs)
	}

	// Piped input without exit ends with Ctrl+D.
	h.runStdin("ls\n", "sandbox", "terminal", sb.ID)
	if in := h.srv.SandboxTerminals()[1].Input; string(in) != "ls\n\x04" {
		t.Fatalf("input = %q", in)
	}

	// -o json prints the one-time session instead of attaching.
	var sess runr.SandboxTerminalSession
	decodeJSON(t, h.mustRun("sandbox", "terminal", sb.ID, "-o", "json").out, &sess)
	if sess.SessionID == "" || !strings.HasPrefix(sess.WebSocketURL, "ws://") {
		t.Fatalf("session = %+v", sess)
	}
	if len(h.srv.SandboxTerminals()) != 2 {
		t.Fatal("-o json attached to the terminal")
	}

	h.mustRun("sandbox", "pause", sb.ID)
	h.expectCode(ExitValidation, "sandbox", "terminal", sb.ID)
}

func TestSandboxPoolsCmd(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("sandbox", "pools", "create", "--region", "eu-1", "--runtime", "python", "--size", "2", "--memory", "1Gi")
	contains(t, r.err, "Created pool")
	pools := h.srv.SandboxPools()
	if len(pools) != 1 || pools[0].Size != 2 || pools[0].MemoryMB != 1024 {
		t.Fatalf("pools = %+v", pools)
	}
	id := pools[0].ID
	h.expectCode(ExitUsage, "sandbox", "pools", "create", "--runtime", "python")
	h.expectCode(ExitValidation, "sandbox", "pools", "create", "--region", "eu-1", "--runtime", "python", "--size", "60")

	r = h.mustRun("sandbox", "pools")
	contains(t, r.out, id)
	contains(t, r.out, "python 3.13")

	h.mustRun("sandbox", "pools", "update", id, "--size", "4", "--disable")
	if p := h.srv.SandboxPools()[0]; p.Size != 4 || p.IsEnabled {
		t.Fatalf("after update: %+v", p)
	}
	h.expectCode(ExitUsage, "sandbox", "pools", "update", id)
	h.expectCode(ExitUsage, "sandbox", "pools", "update", id, "--enable", "--disable")
	h.expectCode(ExitUsage, "sandbox", "pools", "rm", id)
	h.mustRun("sandbox", "pools", "rm", id, "--yes")
	if len(h.srv.SandboxPools()) != 0 {
		t.Fatal("the pool was not removed")
	}
	h.expectCode(ExitNotFound, "sandbox", "pools", "rm", id, "--yes")
}

func TestSandboxQuotasCmd(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedSandbox("python")
	r := h.mustRun("sandbox", "quotas")
	contains(t, r.out, "concurrent sandboxes")
	contains(t, r.out, "every class of the region")

	h.mustRun("sandbox", "quotas", "set", "--max-concurrent", "20", "--max-cpu", "16", "--max-memory", "32Gi",
		"--max-time", "200h", "--allowed-class", "kata", "--allowed-class", "gvisor")
	body := h.srv.CallsTo("PUT /sandbox-quotas/{tenant}")[0].Body
	if body["max_concurrent"] != float64(20) || body["max_cpu_m"] != float64(16000) || body["max_memory_mb"] != float64(32768) ||
		body["max_sandbox_seconds_month"] != float64(720000) {
		t.Fatalf("set body = %v", body)
	}
	if l := h.srv.SandboxQuotaLimits(); strings.Join(l.AllowedClasses, ",") != "gvisor,kata" || l.MaxPoolSize != 10 {
		t.Fatalf("limits = %+v", l)
	}
	if !strings.HasSuffix(h.srv.CallsTo("PUT /sandbox-quotas/{tenant}")[0].Path, "/default") {
		t.Fatal("set without an organization changes the defaults row")
	}

	h.mustRun("sandbox", "quotas", "set", "default", "--unset", "concurrent", "--all-classes")
	body = h.srv.CallsTo("PUT /sandbox-quotas/{tenant}")[1].Body
	if v, ok := body["max_concurrent"]; !ok || v != nil {
		t.Fatalf("--unset body = %v", body)
	}
	if v, ok := body["allowed_classes"]; !ok || v != nil {
		t.Fatalf("--all-classes body = %v", body)
	}
	h.expectCode(ExitUsage, "sandbox", "quotas", "set")
	h.expectCode(ExitUsage, "sandbox", "quotas", "set", "--max-cpu", "lots")
	h.expectCode(ExitUsage, "sandbox", "quotas", "set", "--unset", "everything")
	h.expectCode(ExitNotFound, "sandbox", "quotas", "set", "01jnosuchtenant00000000000", "--max-concurrent", "1")

	r = h.mustRun("sandbox", "quotas", "ls")
	contains(t, r.out, "(defaults)")
	contains(t, r.out, "yes")

	h.expectCode(ExitUsage, "sandbox", "quotas", "reset", "default")
	h.mustRun("sandbox", "quotas", "reset", "default", "--yes")
	if l := h.srv.SandboxQuotaLimits(); l.MaxConcurrent != 10 {
		t.Fatalf("after reset: %+v", l)
	}
}

func TestSandboxUsageAndMetricsCmd(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedSandboxUsage(runr.SandboxUsageRecord{RecordDate: "2026-10-01", SandboxCount: 3, SandboxSeconds: 7200,
		CPUmcMS: 3600 * 1000 * 1000, MemoryMiBMS: 1024 * 3600 * 1000})
	r := h.mustRun("sandbox", "usage", "--from", "2026-10-01")
	contains(t, r.out, "2026-10-01")
	contains(t, r.out, "1.00") // one CPU core-hour, one GiB-hour
	contains(t, r.out, "Total: 2h, 1.00 CPU core-hours, 1.00 memory GiB-hours")
	if strings.Contains(r.out, "COST") {
		t.Fatalf("usage shows a cost column: %q", r.out)
	}
	h.expectCode(ExitUsage, "sandbox", "usage", "--from", "yesterday")

	h.srv.SeedSandbox("python")
	r = h.mustRun("sandbox", "metrics", "--window", "1h", "--region", "eu-1")
	contains(t, r.out, "Active sandboxes:")
	q := h.srv.CallsTo("GET /sandbox-metrics")[0].Query
	if !strings.Contains(q, "window=1h") || !strings.Contains(q, "region=") {
		t.Fatalf("metrics query = %q", q)
	}
	h.expectCode(ExitUsage, "sandbox", "metrics", "--window", "1y")
}
