package cmd

import (
	"context"
	"io"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// runr sandbox exec -t: the exec WebSocket with a terminal, stdin while it runs, the window's
// size as resize frames, the command's exit code, and the flags it does not combine with.
func TestSandboxExecTTYCmd(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")
	h.srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 5, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte(">>> " + string(call.Stdin))},
			{Stream: "stderr", Data: []byte("Traceback\r\n")},
		}}
	})

	// The window's size changes while the command runs: one resize frame.
	watchTerminalSize = func(ctx context.Context, _ *os.File, sizes chan runr.TerminalSize) func() {
		sendLatestSize(sizes, runr.TerminalSize{Cols: 120, Rows: 40})
		return func() {}
	}
	t.Cleanup(func() { watchTerminalSize = watchTerminalSizeOS })

	r := h.runStdin("1/0\n", "sandbox", "exec", "-it", "-e", "LANG=C.UTF-8", sb.ID, "--", "python3", "-q")
	if r.code != 5 {
		t.Fatalf("exit %d, want 5\nstdout: %s\nstderr: %s", r.code, r.out, r.err)
	}
	// The terminal merges the streams: everything arrives on stdout.
	if r.out != ">>> 1/0\nTraceback\r\n" {
		t.Fatalf("stdout = %q", r.out)
	}
	body := h.srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")[0].Body
	if body["tty"] != true {
		t.Fatalf("exec/ws body = %v", body)
	}
	// Not a terminal here: the API's default size, and no stdin up front.
	for _, k := range []string{"cols", "rows", "stdin_base64"} {
		if _, ok := body[k]; ok {
			t.Fatalf("exec/ws body has %s: %v", k, body)
		}
	}
	call := h.srv.SandboxCalls()[0]
	if !call.TTY || call.Env["LANG"] != "C.UTF-8" || strings.Join(call.Cmd, " ") != "python3 -q" || string(call.Stdin) != "1/0\n" {
		t.Fatalf("call = %+v", call)
	}
	socks := h.srv.SandboxExecSockets()
	if len(socks) != 1 || !socks[0].TTY || !socks[0].StdinClosed {
		t.Fatalf("sockets = %+v", socks)
	}

	// -t without -i: the command's input ends at once.
	h.srv.SetSandboxExec(nil)
	r = h.mustRun("sandbox", "exec", "-t", sb.ID, "--", "top", "-b", "-n", "1")
	if r.out != "ran top -b -n 1\n" {
		t.Fatalf("-t stdout = %q", r.out)
	}
	if socks := h.srv.SandboxExecSockets(); len(socks) != 2 || !socks[1].TTY || len(socks[1].Stdin) != 0 || !socks[1].StdinClosed {
		t.Fatalf("-t sockets = %+v", socks)
	}

	// A terminal streams: no -o json, no Server-Sent Events.
	r = h.expectCode(ExitUsage, "sandbox", "exec", "-it", "-o", "json", sb.ID, "--", "sh")
	contains(t, r.err, "-o json")
	r = h.expectCode(ExitUsage, "sandbox", "exec", "-t", "--ws=false", sb.ID, "--", "sh")
	contains(t, r.err, "--ws=false")
	if n := len(h.srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")); n != 2 {
		t.Fatalf("%d exec/ws calls, want 2", n)
	}
}

// The resize frames of a session: the size the watcher reports while the command runs.
func TestSandboxExecTTYResize(t *testing.T) {
	h := newHarness(t).login()
	sb := h.srv.SeedSandbox("python")

	sent := make(chan struct{})
	watchTerminalSize = func(ctx context.Context, _ *os.File, sizes chan runr.TerminalSize) func() {
		go func() {
			sendLatestSize(sizes, runr.TerminalSize{Cols: 90, Rows: 20})
			close(sent)
		}()
		return func() {}
	}
	t.Cleanup(func() { watchTerminalSize = watchTerminalSizeOS })

	// stdin stays open until the fake recorded the resize frame.
	in := &gatedReader{data: "exit\n", ready: func() bool {
		socks := h.srv.SandboxExecSockets()
		return len(socks) == 1 && len(socks[0].Resizes) == 1
	}}
	var out, errOut strings.Builder
	app := h.app("", &out, &errOut)
	app.In = in
	if code := Execute(app, []string{"sandbox", "exec", "-it", sb.ID, "--", "sh"}); code != 0 {
		t.Fatalf("exit %d\nstdout: %s\nstderr: %s", code, out.String(), errOut.String())
	}
	<-sent
	socks := h.srv.SandboxExecSockets()
	if got := socks[0].Resizes; len(got) != 1 || got[0] != (runr.TerminalSize{Cols: 90, Rows: 20}) {
		t.Fatalf("resizes = %+v", got)
	}
	if string(socks[0].Stdin) != "exit\n" {
		t.Fatalf("stdin = %q", socks[0].Stdin)
	}
}

// gatedReader returns data once ready reports true (polling), then EOF.
type gatedReader struct {
	data  string
	ready func() bool
	done  bool
}

func (g *gatedReader) Read(p []byte) (int, error) {
	if g.done {
		return 0, io.EOF
	}
	for deadline := time.Now().Add(5 * time.Second); !g.ready() && time.Now().Before(deadline); {
		time.Sleep(5 * time.Millisecond)
	}
	g.done = true
	return copy(p, g.data), nil
}
