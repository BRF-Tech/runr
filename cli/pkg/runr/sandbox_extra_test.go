package runr_test

import (
	"bytes"
	"context"
	"errors"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestSandboxProcessesClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	sb := srv.SeedSandbox("python")
	srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 2, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("serving\n")},
			{Stream: "stderr", Data: []byte("warning\n")},
			{Stream: "stdout", Data: []byte("done\n")},
		}}
	})

	p, err := c.StartSandboxProcess(ctx, sb.ID, runr.StartProcessInput{Cmd: []string{"python3", "-m", "http.server"},
		Cwd: "/workspace/site", Env: map[string]string{"PORT": "8000"}})
	if err != nil {
		t.Fatal(err)
	}
	if p.Status != runr.ProcessRunning || p.PID == nil || p.Command != "python3 -m http.server" || p.Cwd != "/workspace/site" {
		t.Fatalf("started = %+v", p)
	}
	call := srv.SandboxCalls()[0]
	if strings.Join(call.Cmd, " ") != "python3 -m http.server" || call.Env["PORT"] != "8000" {
		t.Fatalf("call = %+v", call)
	}
	if _, err := c.StartSandboxProcess(ctx, sb.ID, runr.StartProcessInput{Cmd: "sleep 100"}); err != nil {
		t.Fatal(err)
	}
	if got := srv.SandboxCalls()[1].Cmd; strings.Join(got, "|") != "/bin/sh|-c|sleep 100" {
		t.Fatalf("string command = %v", got)
	}

	list, err := c.ListSandboxProcesses(ctx, sb.ID)
	if err != nil || len(list) != 2 || list[1].ID != p.ID {
		t.Fatalf("list = %+v, %v (newest first)", list, err)
	}
	got, err := c.GetSandboxProcess(ctx, sb.ID, p.ID)
	if err != nil || got.ID != p.ID {
		t.Fatalf("get = %+v, %v", got, err)
	}

	tail, err := c.SandboxProcessOutput(ctx, sb.ID, p.ID, 5)
	if err != nil {
		t.Fatal(err)
	}
	if string(tail.StdoutBytes()) != "done\n" || !tail.Truncated {
		t.Fatalf("tail = %+v", tail)
	}
	if q := srv.CallsTo("GET /sandboxes/{sandbox}/processes/{process}/logs")[0].Query; q != "tail_bytes=5" {
		t.Fatalf("tail query = %q", q)
	}

	var stdout, stderr bytes.Buffer
	end, err := c.FollowSandboxProcess(ctx, sb.ID, p.ID, &stdout, &stderr)
	if err != nil {
		t.Fatal(err)
	}
	if stdout.String() != "serving\ndone\n" || stderr.String() != "warning\n" {
		t.Fatalf("followed stdout %q, stderr %q", stdout.String(), stderr.String())
	}
	if end.ExitCode == nil || *end.ExitCode != 2 || end.Status != runr.ProcessExited {
		t.Fatalf("end = %+v", end)
	}
	follow := srv.CallsTo("GET /sandboxes/{sandbox}/processes/{process}/logs")[1]
	if follow.Query != "follow=1" || len(follow.Raw) != 0 {
		t.Fatalf("follow request: query %q, body %q (a GET sends no body)", follow.Query, follow.Raw)
	}

	second := list[0]
	killed, err := c.KillSandboxProcess(ctx, sb.ID, second.ID, "INT")
	if err != nil || killed.Status != runr.ProcessKilled {
		t.Fatalf("kill = %+v, %v", killed, err)
	}
	if _, sig := srv.SandboxProcesses(sb.ID); strings.Join(sig[second.ID], ",") != "INT" {
		t.Fatalf("signals = %v", sig)
	}
	if _, err := c.KillSandboxProcess(ctx, sb.ID, second.ID, "BOGUS"); !runr.IsValidation(err) {
		t.Fatalf("unknown signal: %v", err)
	}
	if _, err := c.GetSandboxProcess(ctx, sb.ID, "01JNOSUCHPROCESS000000000"); !runr.IsNotFound(err) {
		t.Fatalf("unknown process: %v", err)
	}
}

func TestSandboxPauseResumeExtend(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	sb := srv.SeedSandbox("")

	s, err := c.PauseSandbox(ctx, sb.ID)
	if err != nil || s.Status != runr.SandboxPaused || s.PausedAt == "" {
		t.Fatalf("pause = %+v, %v", s, err)
	}
	var ae *runr.APIError
	if _, err := c.ExecSandbox(ctx, sb.ID, runr.SandboxExecInput{Cmd: "true"}); !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("exec while paused: %v", err)
	}
	if s, err = c.ResumeSandbox(ctx, sb.ID); err != nil || s.Status != runr.SandboxRunning {
		t.Fatalf("resume = %+v, %v", s, err)
	}
	if _, err := c.ResumeSandbox(ctx, sb.ID); !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("resume of a running sandbox: %v", err)
	}

	s, err = c.ExtendSandbox(ctx, sb.ID, 1800)
	if err != nil {
		t.Fatal(err)
	}
	exp, perr := time.Parse(time.RFC3339, s.ExpiresAt)
	if perr != nil || time.Until(exp) < 29*time.Minute {
		t.Fatalf("expires_at = %q", s.ExpiresAt)
	}
	if body := srv.CallsTo("POST /sandboxes/{sandbox}/timeout")[0].Body; body["ttl_seconds"] != float64(1800) {
		t.Fatalf("timeout body = %v", body)
	}
	if _, err := c.ExtendSandbox(ctx, sb.ID, 5); !runr.IsValidation(err) {
		t.Fatalf("too short a TTL: %v", err)
	}
	if _, err := c.KeepaliveSandbox(ctx, sb.ID); err != nil {
		t.Fatal(err)
	}
}

func TestSandboxTerminalWebSocket(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	sb := srv.SeedSandbox("python")

	sess, err := c.OpenSandboxTerminal(ctx, sb.ID, 120, 40)
	if err != nil {
		t.Fatal(err)
	}
	if sess.SessionID == "" || !strings.HasPrefix(sess.WebSocketURL, "ws://") ||
		!strings.HasSuffix(sess.WebSocketURL, "/ws/shell?token="+sess.SessionID) {
		t.Fatalf("session = %+v", sess)
	}
	term, err := c.DialSandboxTerminal(ctx, sess)
	if err != nil {
		t.Fatal(err)
	}
	defer term.Close()

	f, err := term.Next()
	if err != nil || f.Status != "searching" {
		t.Fatalf("first frame = %+v, %v", f, err)
	}
	// The server's ping comes next: Next answers it and returns the connected frame.
	f, err = term.Next()
	if err != nil || !f.Connected || f.Pod != "sbx-"+sb.ID {
		t.Fatalf("connected frame = %+v, %v", f, err)
	}
	if err := term.Resize(100, 30); err != nil {
		t.Fatal(err)
	}
	if err := term.SendInput([]byte("echo hi\n")); err != nil {
		t.Fatal(err)
	}
	f, err = term.Next()
	if err != nil || string(f.Output) != "echo hi\n" {
		t.Fatalf("echo = %+v, %v", f, err)
	}
	// A long input crosses the 125-byte frame length boundary.
	long := strings.Repeat("x", 300) + "\n"
	if err := term.SendInput([]byte(long)); err != nil {
		t.Fatal(err)
	}
	if f, err = term.Next(); err != nil || string(f.Output) != long {
		t.Fatalf("long echo: %d bytes, %v", len(f.Output), err)
	}
	if err := term.SendInput([]byte("exit\n")); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	for {
		f, err := term.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		out.Write(f.Output)
	}
	if !strings.Contains(out.String(), "[Process exited]") {
		t.Fatalf("output after exit = %q", out.String())
	}

	logs := srv.SandboxTerminals()
	if len(logs) != 1 {
		t.Fatalf("%d terminal sessions", len(logs))
	}
	l := logs[0]
	if l.SandboxID != sb.ID || l.Cols != 120 || l.Rows != 40 || l.Pongs != 1 ||
		len(l.Resizes) != 1 || l.Resizes[0] != [2]int{100, 30} ||
		string(l.Input) != "echo hi\n"+long+"exit\n" {
		t.Fatalf("terminal log = %+v", l)
	}

	// The session is one-time: a second connection gets an error frame and is closed.
	again, err := c.DialSandboxTerminal(ctx, sess)
	if err != nil {
		t.Fatal(err)
	}
	defer again.Close()
	f, err = again.Next()
	if err != nil || !strings.Contains(f.Error, "expired") {
		t.Fatalf("reused session: %+v, %v", f, err)
	}
	if _, err := again.Next(); !errors.Is(err, io.EOF) {
		t.Fatalf("after the error: %v, want EOF", err)
	}

	// A paused sandbox has no terminal (409).
	if _, err := c.PauseSandbox(ctx, sb.ID); err != nil {
		t.Fatal(err)
	}
	var ae *runr.APIError
	if _, err := c.OpenSandboxTerminal(ctx, sb.ID, 80, 24); !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("terminal of a paused sandbox: %v", err)
	}
}

func TestSandboxTerminalTraceHidesTheToken(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, Debug: &trace})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	sb := srv.SeedSandbox("python")
	sess, err := c.OpenSandboxTerminal(ctx, sb.ID, 0, 0)
	if err != nil {
		t.Fatal(err)
	}
	term, err := c.DialSandboxTerminal(ctx, sess)
	if err != nil {
		t.Fatal(err)
	}
	term.Close()
	if !strings.Contains(trace.String(), "/ws/shell?[redacted]") {
		t.Fatalf("trace does not name the WebSocket:\n%s", trace.String())
	}
	if strings.Contains(trace.String(), "token="+sess.SessionID) {
		t.Fatalf("trace contains the session token:\n%s", trace.String())
	}
}

func TestSandboxPoolsAndQuotasClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	p, err := c.CreateSandboxPool(ctx, runr.CreateSandboxPoolInput{Region: "eu-1", Runtime: "python", Size: 3, Memory: "1Gi"})
	if err != nil {
		t.Fatal(err)
	}
	if p.RegionName() != "eu-1" || p.RuntimeVersion != "3.13" || p.MemoryMB != 1024 || !p.IsEnabled || p.Starting != 3 {
		t.Fatalf("pool = %+v", p)
	}
	if _, err := c.CreateSandboxPool(ctx, runr.CreateSandboxPoolInput{Region: "eu-1", Runtime: "python", Size: 99}); !runr.IsValidation(err) {
		t.Fatalf("size 99: %v", err)
	}
	// The quota allows 10 warm sandboxes: 3 + 8 is too many.
	if _, err := c.CreateSandboxPool(ctx, runr.CreateSandboxPoolInput{Region: "eu-1", Runtime: "nodejs", Size: 8}); !runr.IsValidation(err) {
		t.Fatalf("over the pool quota: %v", err)
	}
	list, err := c.ListSandboxPools(ctx)
	if err != nil || len(list) != 1 || list[0].ID != p.ID {
		t.Fatalf("pools = %+v, %v", list, err)
	}
	size, off := 5, false
	p2, err := c.UpdateSandboxPool(ctx, p.ID, runr.UpdateSandboxPoolInput{Size: &size, IsEnabled: &off})
	if err != nil || p2.Size != 5 || p2.IsEnabled {
		t.Fatalf("update = %+v, %v", p2, err)
	}
	if body := srv.CallsTo("PUT /sandbox-pools/{pool}")[0].Body; body["size"] != float64(5) || body["is_enabled"] != false {
		t.Fatalf("update body = %v", body)
	}
	if err := c.DeleteSandboxPool(ctx, p.ID); err != nil {
		t.Fatal(err)
	}
	if err := c.DeleteSandboxPool(ctx, p.ID); !runr.IsNotFound(err) {
		t.Fatalf("second delete: %v", err)
	}

	srv.SeedSandbox("python")
	q, err := c.GetSandboxQuota(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if q.Limits.MaxConcurrent != 10 || q.Usage == nil || q.Usage.Concurrent != 1 || q.Usage.CPUm != 500 {
		t.Fatalf("quota = %+v (usage %+v)", q.Limits, q.Usage)
	}
	q, err = c.SetSandboxQuota(ctx, "default", runr.SandboxQuotaChange{"max_concurrent": 3, "allowed_classes": []string{"gvisor"}})
	if err != nil || q.Limits.MaxConcurrent != 3 || strings.Join(q.Limits.AllowedClasses, ",") != "gvisor" || q.Limits.MaxCPUm != 8000 {
		t.Fatalf("set = %+v, %v", q, err)
	}
	q, err = c.SetSandboxQuota(ctx, "default", runr.SandboxQuotaChange{"max_concurrent": nil})
	if err != nil || q.Limits.MaxConcurrent != 10 || len(q.Limits.AllowedClasses) != 1 {
		t.Fatalf("unset = %+v, %v", q, err)
	}
	if body := srv.CallsTo("PUT /sandbox-quotas/{tenant}")[1].Body; body["max_concurrent"] != nil {
		t.Fatalf("unset body = %v", body)
	} else if _, sent := body["max_concurrent"]; !sent {
		t.Fatalf("unset body lacks max_concurrent: %v", body)
	}
	if _, err := c.SetSandboxQuota(ctx, "default", runr.SandboxQuotaChange{"allowed_classes": []string{"vmware"}}); !runr.IsValidation(err) {
		t.Fatalf("unknown class: %v", err)
	}
	rows, err := c.ListSandboxQuotas(ctx)
	if err != nil || len(rows) != 1 || rows[0].Tenant != nil || !rows[0].Custom {
		t.Fatalf("tenants = %+v, %v", rows, err)
	}
	if _, err := c.SetSandboxQuota(ctx, "01JNOSUCHTENANT0000000000", runr.SandboxQuotaChange{"max_concurrent": 1}); !runr.IsNotFound(err) {
		t.Fatalf("unknown organization: %v", err)
	}
	if err := c.ResetSandboxQuota(ctx, "default"); err != nil {
		t.Fatal(err)
	}
	if l := srv.SandboxQuotaLimits(); l.MaxConcurrent != 10 || l.AllowedClasses != nil {
		t.Fatalf("after reset: %+v", l)
	}
}

func TestSandboxUsageAndMetricsClient(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.SeedSandboxUsage(
		runr.SandboxUsageRecord{RecordDate: "2026-10-01", SandboxCount: 4, SandboxSeconds: 3600, FinalCost: 0.5, Currency: "EUR"},
		runr.SandboxUsageRecord{RecordDate: "2026-10-02", SandboxCount: 2, SandboxSeconds: 600, FinalCost: 0.1, Currency: "EUR"},
	)
	rows, err := c.SandboxUsage(ctx, "2026-10-02", "")
	if err != nil || len(rows) != 1 || rows[0].SandboxCount != 2 {
		t.Fatalf("usage = %+v, %v", rows, err)
	}
	if q := srv.CallsTo("GET /sandbox-usage")[0].Query; q != "from=2026-10-02" {
		t.Fatalf("usage query = %q", q)
	}
	srv.SeedSandbox("python")
	m, err := c.GetSandboxMetrics(ctx, runr.SandboxMetricsOptions{Window: "7d"})
	if err != nil || m.Window != "7d" || m.Active != 1 || m.Pool.HitRate != nil {
		t.Fatalf("metrics = %+v, %v", m, err)
	}
	if _, err := c.GetSandboxMetrics(ctx, runr.SandboxMetricsOptions{Window: "1y"}); !runr.IsValidation(err) {
		t.Fatalf("window 1y: %v", err)
	}
}
