package cmd

import (
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func TestSandboxExecWebSocketCmd(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 3, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("read " + string(call.Stdin))},
			{Stream: "stderr", Data: []byte("careful\n")},
		}}
	})

	// -i streams runr's stdin as frames, not as stdin_base64 up front.
	r := h.runStdin("1\n2\n", "sandbox", "exec", "--ws", "-i", "--cwd", "/workspace/app", "-e", "DEBUG=1", "--timeout", "90s",
		sb.ID, "--", "python3", "sum.py")
	if r.code != 3 {
		t.Fatalf("exit %d, want 3\nstdout: %s\nstderr: %s", r.code, r.out, r.err)
	}
	if r.out != "read 1\n2\n" {
		t.Fatalf("stdout = %q", r.out)
	}
	contains(t, r.err, "careful")
	body := h.srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")[0].Body
	if _, ok := body["stdin_base64"]; ok {
		t.Fatalf("--ws -i sent stdin up front: %v", body)
	}
	if len(h.srv.CallsTo("POST /sandboxes/{sandbox}/exec")) != 0 {
		t.Fatal("--ws used the event stream")
	}
	call := h.srv.SandboxCalls()[0]
	if !call.WebSocket || strings.Join(call.Cmd, " ") != "python3 sum.py" || call.Cwd != "/workspace/app" ||
		call.Env["DEBUG"] != "1" || call.Timeout != 90 || string(call.Stdin) != "1\n2\n" {
		t.Fatalf("call = %+v", call)
	}
	if socks := h.srv.SandboxExecSockets(); len(socks) != 1 || string(socks[0].Stdin) != "1\n2\n" || !socks[0].StdinClosed {
		t.Fatalf("sockets = %+v", socks)
	}

	// -o json collects the output into one result.
	h.srv.SetSandboxExec(nil)
	var res runr.SandboxExecResult
	decodeJSON(t, h.mustRun("sandbox", "exec", "--ws", "-o", "json", sb.ID, "--", "echo", "hi").out, &res)
	if res.ExitCode != 0 || res.Stdout != "ran echo hi\n" {
		t.Fatalf("json result = %+v", res)
	}

	// Without --ws, exec keeps using Server-Sent Events.
	h.mustRun("sandbox", "exec", sb.ID, "--", "true")
	if n := len(h.srv.CallsTo("POST /sandboxes/{sandbox}/exec")); n != 1 {
		t.Fatalf("%d SSE exec calls, want 1", n)
	}

	h.mustRun("sandbox", "pause", sb.ID)
	r = h.expectCode(ExitValidation, "sandbox", "exec", "--ws", sb.ID, "--", "ls")
	contains(t, r.err, "not running")
}

// -i on a terminal picks the exec WebSocket (typed lines reach the command as they are
// written); piped stdin, no -i and --ws=false keep Server-Sent Events.
func TestSandboxExecTransportDefault(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	wsCalls := func() int { return len(h.srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")) }
	sseCalls := func() int { return len(h.srv.CallsTo("POST /sandboxes/{sandbox}/exec")) }

	h.terminal = true
	r := h.runStdin("print(6 * 7)\n", "sandbox", "exec", "-i", sb.ID, "--", "python3", "-i")
	if r.code != 0 {
		t.Fatalf("exit %d\nstdout: %s\nstderr: %s", r.code, r.out, r.err)
	}
	if wsCalls() != 1 || sseCalls() != 0 {
		t.Fatalf("-i on a terminal: %d WebSocket and %d SSE calls, want 1 and 0", wsCalls(), sseCalls())
	}
	if socks := h.srv.SandboxExecSockets(); len(socks) != 1 || string(socks[0].Stdin) != "print(6 * 7)\n" {
		t.Fatalf("sockets = %+v", socks)
	}

	// --ws=false: Server-Sent Events with stdin up front.
	h.runStdin("x\n", "sandbox", "exec", "-i", "--ws=false", sb.ID, "--", "cat")
	if wsCalls() != 1 || sseCalls() != 1 {
		t.Fatalf("--ws=false: %d WebSocket and %d SSE calls", wsCalls(), sseCalls())
	}
	if _, ok := h.srv.CallsTo("POST /sandboxes/{sandbox}/exec")[0].Body["stdin_base64"]; !ok {
		t.Fatal("--ws=false did not send stdin up front")
	}

	// A terminal without -i: the command gets no stdin, Server-Sent Events.
	h.mustRun("sandbox", "exec", sb.ID, "--", "true")
	if wsCalls() != 1 || sseCalls() != 2 {
		t.Fatalf("no -i: %d WebSocket and %d SSE calls", wsCalls(), sseCalls())
	}

	// Piped stdin: Server-Sent Events.
	h.terminal = false
	h.runStdin("y\n", "sandbox", "exec", "-i", sb.ID, "--", "cat")
	if wsCalls() != 1 || sseCalls() != 3 {
		t.Fatalf("piped -i: %d WebSocket and %d SSE calls", wsCalls(), sseCalls())
	}
}
