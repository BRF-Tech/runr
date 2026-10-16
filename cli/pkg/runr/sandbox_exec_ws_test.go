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

func TestSandboxExecWebSocket(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	sb := srv.SeedSandbox("python")
	srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 4, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("got " + string(call.Stdin))},
			{Stream: "stderr", Data: []byte{0xff, 0xfe}},
			{Stream: "stdout", Data: []byte("bye\n")},
		}}
	})

	var stdout, stderr bytes.Buffer
	var started string
	res, err := c.StreamSandboxExecWS(ctx, sb.ID, runr.SandboxExecInput{Cmd: []string{"python3", "ask.py"}, Cwd: "/workspace",
		Env: map[string]string{"MODE": "ws"}, Timeout: 30, StdinBase64: runr.EncodeBase64([]byte("a,"))},
		runr.SandboxStream{Stdout: &stdout, Stderr: &stderr, OnStart: func(id string) { started = id }},
		strings.NewReader("b,c\n"))
	if err != nil {
		t.Fatal(err)
	}
	if res.ExitCode != 4 || res.ExecID == "" || started != res.ExecID {
		t.Fatalf("result = %+v, started %q", res, started)
	}
	if stdout.String() != "got a,b,c\nbye\n" || !bytes.Equal(stderr.Bytes(), []byte{0xff, 0xfe}) {
		t.Fatalf("stdout = %q, stderr = %q", stdout.String(), stderr.Bytes())
	}
	call := srv.SandboxCalls()[0]
	if !call.WebSocket || call.Stream || strings.Join(call.Cmd, " ") != "python3 ask.py" || call.Cwd != "/workspace" ||
		call.Env["MODE"] != "ws" || call.Timeout != 30 || string(call.Stdin) != "a,b,c\n" {
		t.Fatalf("call = %+v", call)
	}
	if body := srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")[0].Body; body["stream"] != nil {
		t.Fatalf("exec/ws body = %v", body)
	}
	socks := srv.SandboxExecSockets()
	if len(socks) != 1 || string(socks[0].Stdin) != "b,c\n" || !socks[0].StdinClosed || socks[0].Killed {
		t.Fatalf("sockets = %+v", socks)
	}

	// Without a stdin reader the command's stdin is closed at once.
	srv.SetSandboxExec(nil)
	stdout.Reset()
	if res, err = c.StreamSandboxExecWS(ctx, sb.ID, runr.SandboxExecInput{Cmd: "ls"}, runr.SandboxStream{Stdout: &stdout}, nil); err != nil || res.ExitCode != 0 {
		t.Fatalf("ls = %+v, %v", res, err)
	}
	if stdout.String() != "ran /bin/sh -c ls\n" {
		t.Fatalf("ls stdout = %q", stdout.String())
	}

	// stdin reaches an interactive command while it runs: cat echoes each frame before stdin
	// is closed.
	pr, pw := io.Pipe()
	echoed := make(chan string, 1)
	var seen bytes.Buffer
	done := make(chan error, 1)
	go func() {
		_, err := c.StreamSandboxExecWS(ctx, sb.ID, runr.SandboxExecInput{Cmd: []string{"cat"}},
			runr.SandboxStream{Stdout: writerFunc(func(b []byte) (int, error) {
				seen.Write(b)
				if strings.Contains(seen.String(), "line 1\n") {
					select {
					case echoed <- seen.String():
					default:
					}
				}
				return len(b), nil
			})}, pr)
		done <- err
	}()
	if _, err := pw.Write([]byte("line 1\n")); err != nil {
		t.Fatal(err)
	}
	select {
	case got := <-echoed:
		if got != "line 1\n" {
			t.Fatalf("echo = %q", got)
		}
	case <-ctx.Done():
		t.Fatal("cat did not echo before stdin was closed")
	}
	pw.Close()
	if err := <-done; err != nil {
		t.Fatal(err)
	}

	// A cancelled command is killed in the sandbox.
	pr2, pw2 := io.Pipe()
	defer pw2.Close()
	cctx, ccancel := context.WithCancel(ctx)
	go func() {
		for len(srv.SandboxExecSockets()) < 4 {
			time.Sleep(5 * time.Millisecond)
		}
		ccancel()
	}()
	res, err = c.StreamSandboxExecWS(cctx, sb.ID, runr.SandboxExecInput{Cmd: []string{"python3", "-i"}}, runr.SandboxStream{}, pr2)
	if !errors.Is(err, context.Canceled) || res.ExitCode != 137 {
		t.Fatalf("cancelled = %+v, %v", res, err)
	}
	if socks := srv.SandboxExecSockets(); !socks[3].Killed {
		t.Fatalf("the cancelled command was not killed: %+v", socks[3])
	}

	// A paused sandbox runs no commands (409).
	if _, err := c.PauseSandbox(ctx, sb.ID); err != nil {
		t.Fatal(err)
	}
	var ae *runr.APIError
	if _, err := c.StreamSandboxExecWS(ctx, sb.ID, runr.SandboxExecInput{Cmd: "ls"}, runr.SandboxStream{}, nil); !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("exec in a paused sandbox: %v", err)
	}
}

func TestSandboxExecSocketIsOneTime(t *testing.T) {
	srv := runrtest.New(t)
	var trace bytes.Buffer
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, Debug: &trace})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	sb := srv.SeedSandbox("python")

	sess, err := c.OpenSandboxExecSocket(ctx, sb.ID, runr.SandboxExecInput{Cmd: "env", Env: map[string]string{"API_KEY": "s3cret"}})
	if err != nil {
		t.Fatal(err)
	}
	if sess.Token == "" || sess.ExecID == "" || sess.ExpiresIn != 60 ||
		!strings.HasSuffix(sess.WebSocketURL, "/ws/sandbox-exec?token="+sess.Token) || !strings.HasPrefix(sess.WebSocketURL, "ws://") {
		t.Fatalf("session = %+v", sess)
	}
	sock, err := c.DialSandboxExec(ctx, sess)
	if err != nil {
		t.Fatal(err)
	}
	if f, err := sock.Next(); err != nil || f.Type != "start" || f.ExecID != sess.ExecID {
		t.Fatalf("first frame = %+v, %v", f, err)
	}
	if err := sock.CloseStdin(); err != nil {
		t.Fatal(err)
	}
	var frames []string
	for {
		f, err := sock.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		frames = append(frames, f.Type)
	}
	sock.Close()
	if strings.Join(frames, ",") != "stdout,exit" {
		t.Fatalf("frames = %v", frames)
	}

	// The token is one-time: a second connection gets an error frame.
	again, err := c.DialSandboxExec(ctx, sess)
	if err != nil {
		t.Fatal(err)
	}
	defer again.Close()
	if f, err := again.Next(); err != nil || f.Type != "error" || !strings.Contains(f.Message, "expired") {
		t.Fatalf("reused token: %+v, %v", f, err)
	}

	// Neither the token nor the environment reaches the trace.
	if !strings.Contains(trace.String(), "/ws/sandbox-exec?[redacted]") {
		t.Fatalf("trace does not name the WebSocket:\n%s", trace.String())
	}
	for _, secret := range []string{sess.Token, "s3cret"} {
		if strings.Contains(trace.String(), secret) {
			t.Fatalf("trace contains %q:\n%s", secret, trace.String())
		}
	}
}

// A command on a terminal: tty, cols and rows in the request, tty on the session and the start
// frame, sizes of SandboxStream.Resize as resize frames, the output in stdout frames.
func TestSandboxExecWebSocketTTY(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	sb := srv.SeedSandbox("python")
	srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 2, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("$ " + string(call.Stdin))},
			{Stream: "stderr", Data: []byte("sh: oops\r\n")},
		}}
	})

	sess, err := c.OpenSandboxExecSocket(ctx, sb.ID, runr.SandboxExecInput{Cmd: []string{"sh"}, TTY: true, Cols: 132, Rows: 43})
	if err != nil {
		t.Fatal(err)
	}
	if !sess.TTY {
		t.Fatalf("session = %+v", sess)
	}
	body := srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")[0].Body
	if body["tty"] != true || body["cols"] != float64(132) || body["rows"] != float64(43) {
		t.Fatalf("exec/ws body = %v", body)
	}
	sock, err := c.DialSandboxExec(ctx, sess)
	if err != nil {
		t.Fatal(err)
	}
	if f, err := sock.Next(); err != nil || f.Type != "start" || !f.TTY {
		t.Fatalf("start = %+v, %v", f, err)
	}
	sock.Close()

	// The stream: resize frames while it runs; Ctrl+D ends the input; the terminal merges stderr.
	pr, pw := io.Pipe()
	sizes := make(chan runr.TerminalSize, 2)
	var out, errOut bytes.Buffer
	done := make(chan struct{})
	var res *runr.SandboxStreamResult
	go func() {
		defer close(done)
		res, err = c.StreamSandboxExecWS(ctx, sb.ID, runr.SandboxExecInput{Cmd: []string{"sh"}, TTY: true},
			runr.SandboxStream{Stdout: &out, Stderr: &errOut, Resize: sizes}, pr)
	}()
	sizes <- runr.TerminalSize{Cols: 100, Rows: 30}
	sizes <- runr.TerminalSize{Cols: 0, Rows: 30} // not a size: not sent
	for deadline := time.Now().Add(5 * time.Second); ; time.Sleep(5 * time.Millisecond) {
		socks := srv.SandboxExecSockets()
		if len(socks) == 2 && len(socks[1].Resizes) == 1 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("no resize frame: %+v", socks)
		}
	}
	if _, err := pw.Write([]byte("ls\r\x04")); err != nil {
		t.Fatal(err)
	}
	<-done
	if err != nil {
		t.Fatal(err)
	}
	if res.ExitCode != 2 || !res.TTY || out.String() != "$ ls\rsh: oops\r\n" || errOut.Len() != 0 {
		t.Fatalf("result = %+v, stdout %q, stderr %q", res, out.String(), errOut.String())
	}
	sock2 := srv.SandboxExecSockets()[1]
	if !sock2.TTY || sock2.Resizes[0] != (runr.TerminalSize{Cols: 100, Rows: 30}) || !sock2.StdinClosed {
		t.Fatalf("socket = %+v", sock2)
	}
	if call := srv.SandboxCalls()[0]; !call.TTY || call.Cols != 0 || call.Rows != 0 {
		t.Fatalf("call = %+v", call)
	}

	// Without tty the request carries none of the terminal fields.
	if _, err := c.OpenSandboxExecSocket(ctx, sb.ID, runr.SandboxExecInput{Cmd: "ls"}); err != nil {
		t.Fatal(err)
	}
	last := srv.CallsTo("POST /sandboxes/{sandbox}/exec/ws")
	for _, k := range []string{"tty", "cols", "rows"} {
		if _, ok := last[len(last)-1].Body[k]; ok {
			t.Fatalf("exec/ws without a terminal sent %s: %v", k, last[len(last)-1].Body)
		}
	}
}

type writerFunc func([]byte) (int, error)

func (f writerFunc) Write(b []byte) (int, error) { return f(b) }
