package runr

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"
	"unicode/utf8"
)

// A command over the exec WebSocket of the Sandbox API, the alternative to Server-Sent Events
// for programs that read stdin while they run (docs/sandbox-api.md):
//
//  1. POST /sandboxes/{id}/exec/ws with the body of exec (without stream) answers a one-time
//     token (valid for 60 seconds) and its path /ws/sandbox-exec?token=…;
//  2. the client opens that WebSocket on the console host and exchanges JSON text frames:
//     server → client  {"type":"start","exec_id","tty"}, {"type":"stdout"|"stderr","data":"<base64>"},
//     {"type":"exit","exit_code","timed_out","duration_ms","killed"}, {"type":"error","message"}
//     client → server  {"type":"stdin","data":"<base64>"}, {"type":"stdin_close"}, {"type":"kill"},
//     {"type":"resize","cols","rows"}
//  3. the server closes the connection after the exit frame; a client that goes away stops the
//     command.
//
// With SandboxExecInput.TTY the command runs on a terminal: its output (stdout and stderr merged
// by the terminal) arrives in stdout frames, resize frames change the terminal's size and
// stdin_close sends Ctrl+D. An API that predates terminals answers tty false and runs the
// command without one.

// SandboxExecSession is the answer of POST /sandboxes/{id}/exec/ws.
type SandboxExecSession struct {
	Token string `json:"token"`
	// URL is the WebSocket's path (/ws/sandbox-exec?token=…) or a full ws(s):// URL.
	URL       string `json:"url"`
	ExecID    string `json:"exec_id"`
	ExpiresIn int    `json:"expires_in"`
	// TTY is set when the command runs on a terminal.
	TTY bool `json:"tty"`
	// WebSocketURL is the URL the client connects to (filled by OpenSandboxExecSocket).
	WebSocketURL string `json:"websocket_url"`
}

// OpenSandboxExecSocket prepares a command of a running sandbox for the exec WebSocket and
// returns its one-time session.
func (c *Client) OpenSandboxExecSocket(ctx context.Context, id string, in SandboxExecInput) (*SandboxExecSession, error) {
	in.Stream = false
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/exec/ws", body: in}, &raw); err != nil {
		return nil, err
	}
	var s SandboxExecSession
	if err := decodeData(raw, &s); err != nil {
		return nil, fmt.Errorf("decode the exec session: %w", err)
	}
	if s.URL == "" && s.Token != "" {
		s.URL = "/ws/sandbox-exec?token=" + url.QueryEscape(s.Token)
	}
	if s.URL == "" {
		return nil, errors.New("the API answered no exec WebSocket session")
	}
	s.WebSocketURL = c.webSocketURL(s.URL)
	return &s, nil
}

// SandboxExecFrame is one message of the exec WebSocket.
type SandboxExecFrame struct {
	// Type is start, stdout, stderr, exit or error.
	Type   string
	ExecID string
	// TTY is set on the start frame of a command that runs on a terminal.
	TTY bool
	// Data is the output of a stdout or stderr frame (decoded from base64).
	Data       []byte
	ExitCode   int
	TimedOut   bool
	DurationMS int64
	Killed     bool
	// Message is the text of an error frame.
	Message string
}

// SandboxExecSocket is an open exec WebSocket.
type SandboxExecSocket struct {
	ws *wsConn
}

// DialSandboxExec connects to the exec WebSocket of a session. ctx ends the connection (the
// server then stops the command).
func (c *Client) DialSandboxExec(ctx context.Context, s *SandboxExecSession) (*SandboxExecSocket, error) {
	u := s.WebSocketURL
	if u == "" {
		u = c.webSocketURL(s.URL)
	}
	// The token is in the URL: the trace names the host only.
	c.tracef("> WebSocket %s (exec session)\n", redactQuery(u))
	ws, err := dialWebSocket(ctx, u, c.userAgent, c.ConsoleURL())
	if err != nil {
		return nil, err
	}
	return &SandboxExecSocket{ws: ws}, nil
}

func (s *SandboxExecSocket) send(v any) error {
	msg, _ := json.Marshal(v)
	return s.ws.writeText(msg)
}

// SendStdin sends bytes to the command's stdin.
func (s *SandboxExecSocket) SendStdin(b []byte) error {
	return s.send(map[string]string{"type": "stdin", "data": base64.StdEncoding.EncodeToString(b)})
}

// CloseStdin closes the command's stdin (it reads EOF).
func (s *SandboxExecSocket) CloseStdin() error {
	return s.send(map[string]string{"type": "stdin_close"})
}

// Kill stops the command (SIGKILL); the server answers with the exit frame.
func (s *SandboxExecSocket) Kill() error { return s.send(map[string]string{"type": "kill"}) }

// Resize changes the size of the command's terminal (a command without one ignores it).
func (s *SandboxExecSocket) Resize(cols, rows int) error {
	return s.send(map[string]any{"type": "resize", "cols": cols, "rows": rows})
}

// Next returns the next frame of the server; io.EOF when the server closed the connection.
func (s *SandboxExecSocket) Next() (*SandboxExecFrame, error) {
	for {
		data, err := s.ws.readText()
		if err != nil {
			return nil, err
		}
		var m struct {
			Type       string `json:"type"`
			ExecID     string `json:"exec_id"`
			TTY        bool   `json:"tty"`
			Data       string `json:"data"`
			ExitCode   int    `json:"exit_code"`
			TimedOut   bool   `json:"timed_out"`
			DurationMS int64  `json:"duration_ms"`
			Killed     bool   `json:"killed"`
			Message    string `json:"message"`
		}
		if json.Unmarshal(data, &m) != nil || m.Type == "" {
			continue // not a message of the exec protocol
		}
		f := &SandboxExecFrame{Type: m.Type, ExecID: m.ExecID, TTY: m.TTY, ExitCode: m.ExitCode, TimedOut: m.TimedOut,
			DurationMS: m.DurationMS, Killed: m.Killed, Message: m.Message}
		if m.Type == "stdout" || m.Type == "stderr" {
			out, err := base64.StdEncoding.DecodeString(m.Data)
			if err != nil {
				return nil, fmt.Errorf("%s frame is not base64: %w", m.Type, err)
			}
			f.Data = out
		}
		return f, nil
	}
}

// Close ends the connection (a close frame, then the connection).
func (s *SandboxExecSocket) Close() error { return s.ws.close() }

// execKillGrace is how long a cancelled command may take to answer the kill with its exit frame.
var execKillGrace = 3 * time.Second

// StreamSandboxExecWS runs a command over the exec WebSocket and writes its output to out as it
// arrives. stdin, when not nil, is sent to the command while it runs and closed at its EOF;
// without it the command's stdin is closed at once (in.StdinBase64 is still sent first). With
// in.TTY the command runs on a terminal and out.Resize sizes become resize frames. When ctx
// ends, the command is killed, its exit frame awaited briefly, and ctx's error returned.
func (c *Client) StreamSandboxExecWS(ctx context.Context, id string, in SandboxExecInput, out SandboxStream, stdin io.Reader) (*SandboxStreamResult, error) {
	sess, err := c.OpenSandboxExecSocket(ctx, id, in)
	if err != nil {
		return nil, err
	}
	// The connection outlives ctx so a cancelled command can be killed and its exit read.
	connCtx, closeConn := context.WithCancel(context.WithoutCancel(ctx))
	defer closeConn()
	sock, err := c.DialSandboxExec(connCtx, sess)
	if err != nil {
		return nil, err
	}
	defer sock.Close()

	res := &SandboxStreamResult{ExecID: sess.ExecID, TTY: sess.TTY}
	cancelled := make(chan struct{})
	finished := make(chan struct{})
	defer close(finished)
	go func() {
		select {
		case <-ctx.Done():
			close(cancelled)
			_ = sock.Kill()
			select {
			case <-time.After(execKillGrace):
				closeConn()
			case <-finished:
			}
		case <-finished:
		}
	}()

	path := "/sandboxes/" + seg(id) + "/exec/ws"
	started := false
	for {
		f, err := sock.Next()
		if err != nil {
			select {
			case <-cancelled:
				return res, ctx.Err()
			default:
			}
			if errors.Is(err, io.EOF) {
				return res, fmt.Errorf("%s: the WebSocket closed before the command exited", path)
			}
			return res, &NetworkError{Method: http.MethodGet, URL: redactQuery(sess.WebSocketURL), Err: err}
		}
		switch f.Type {
		case "start":
			if f.ExecID != "" {
				res.ExecID = f.ExecID
			}
			res.TTY = res.TTY || f.TTY
			if out.OnStart != nil {
				out.OnStart(res.ExecID)
			}
			if !started {
				started = true
				if stdin == nil {
					_ = sock.CloseStdin()
				} else {
					go pumpStdin(sock, stdin, finished)
				}
				if out.Resize != nil && res.TTY {
					go pumpResize(sock, out.Resize, finished)
				}
			}
		case "stdout", "stderr":
			w := out.Stdout
			if f.Type == "stderr" {
				w = out.Stderr
			}
			if w != nil {
				if _, err := w.Write(f.Data); err != nil {
					return res, err
				}
			}
		case "exit":
			res.ExitCode, res.TimedOut, res.DurationMS = f.ExitCode, f.TimedOut, f.DurationMS
			select {
			case <-cancelled:
				return res, ctx.Err()
			default:
			}
			return res, nil
		case "error":
			return res, &APIError{StatusCode: http.StatusBadGateway, Method: http.MethodGet, Path: "/ws/sandbox-exec", Message: f.Message}
		}
	}
}

// TextOrBase64Fields returns output as the stdout / stdout_base64 pair of an exec result: text
// when it is valid UTF-8, else base64.
func TextOrBase64Fields(b []byte) (text, b64 string) {
	if utf8.Valid(b) {
		return string(b), ""
	}
	return "", base64.StdEncoding.EncodeToString(b)
}

// pumpResize sends every terminal size of sizes as a resize frame until the command ends.
func pumpResize(sock *SandboxExecSocket, sizes <-chan TerminalSize, done <-chan struct{}) {
	for {
		select {
		case <-done:
			return
		case s, ok := <-sizes:
			if !ok {
				return
			}
			if s.Cols > 0 && s.Rows > 0 && sock.Resize(s.Cols, s.Rows) != nil {
				return
			}
		}
	}
}

// pumpStdin copies r to the command's stdin and closes it at EOF.
func pumpStdin(sock *SandboxExecSocket, r io.Reader, done <-chan struct{}) {
	buf := make([]byte, 32<<10)
	for {
		n, err := r.Read(buf)
		if n > 0 {
			if sock.SendStdin(buf[:n]) != nil {
				return
			}
		}
		select {
		case <-done:
			return
		default:
		}
		if err != nil {
			_ = sock.CloseStdin()
			return
		}
	}
}
