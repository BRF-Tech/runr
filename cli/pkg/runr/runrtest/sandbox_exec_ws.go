package runrtest

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"path"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The exec WebSocket of the fake (SandboxExecController::execWs, SandboxExecSocket):
// POST /sandboxes/{id}/exec/ws stores the prepared command under a one-time token, and
// /ws/sandbox-exec?token=… runs it. The fake's command reads its stdin until stdin_close and
// then answers with SandboxExecFunc (stdout, stderr, exit); `cat` echoes every stdin frame at
// once instead, and a kill frame ends any command with exit code 137. With a terminal (tty)
// every output chunk goes out as a stdout frame, as a terminal merges the streams, resize frames
// are recorded and Ctrl+D (0x04) in a stdin frame ends the input like stdin_close.

// SandboxExecSocketLog is what an exec WebSocket of the fake received.
type SandboxExecSocketLog struct {
	SandboxID string
	Cmd       []string
	// Stdin is every byte of the stdin frames (without the session's initial stdin).
	Stdin []byte
	// StdinClosed is set by a stdin_close frame, Killed by a kill frame.
	StdinClosed bool
	Killed      bool
	// TTY is the session's terminal; Resizes are the sizes of its resize frames.
	TTY     bool
	Resizes []runr.TerminalSize
}

type sbxExecSession struct {
	call   SandboxExecCall
	execID string
}

type sbxExecWSState struct {
	sessions map[string]*sbxExecSession
	sockets  []*SandboxExecSocketLog
	seq      int
}

func (s *Server) sbxExecWS() *sbxExecWSState {
	return featureState(s, "sandbox-exec-ws", func() *sbxExecWSState {
		return &sbxExecWSState{sessions: map[string]*sbxExecSession{}}
	})
}

// SandboxExecSockets returns the exec WebSockets the fake served.
func (s *Server) SandboxExecSockets() []SandboxExecSocketLog {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []SandboxExecSocketLog
	for _, l := range s.sbxExecWS().sockets {
		cp := *l
		cp.Stdin = append([]byte(nil), l.Stdin...)
		cp.Cmd = append([]string(nil), l.Cmd...)
		cp.Resizes = append([]runr.TerminalSize(nil), l.Resizes...)
		out = append(out, cp)
	}
	return out
}

func init() {
	handle("POST /sandboxes/{sandbox}/exec/ws", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		if _, ok := q.Body["stream"]; ok {
			return 0, nil, errValidation("stream", "The exec WebSocket takes no stream field.")
		}
		call := SandboxExecCall{SandboxID: sb.data.ID, Cwd: q.str("cwd"), Env: map[string]string{}, WebSocket: true}
		if env, ok := q.Body["env"].(map[string]any); ok {
			for k, v := range env {
				call.Env[k] = fmt.Sprint(v)
			}
		}
		if t, ok := q.Body["timeout"].(float64); ok {
			call.Timeout = int(t)
		}
		if tty, ok := q.Body["tty"]; ok {
			b, isBool := tty.(bool)
			if !isBool {
				return 0, nil, errValidation("tty", "The tty field must be true or false.")
			}
			call.TTY = b
		}
		for _, f := range []struct {
			name string
			dst  *int
		}{{"cols", &call.Cols}, {"rows", &call.Rows}} {
			if v, ok := q.Body[f.name]; ok {
				n, isNum := v.(float64)
				if !isNum || n != float64(int(n)) || n < 1 || n > 1000 {
					return 0, nil, errValidation(f.name, "The "+f.name+" field must be between 1 and 1000.")
				}
				*f.dst = int(n)
			}
		}
		if err := sbxCommand(q, &call); err != nil {
			return 0, nil, err
		}
		st := s.sbxExecWS()
		st.seq++
		token := fmt.Sprintf("5e1d7c3a-0000-4000-8000-%012d", st.seq)
		execID := strings.ToLower(s.newID())
		st.sessions[token] = &sbxExecSession{call: call, execID: execID}
		sb.data.LastActivityAt = nowRFC3339()
		return 200, map[string]any{"data": map[string]any{
			"token": token, "url": "/ws/sandbox-exec?token=" + token, "exec_id": execID, "expires_in": 60, "tty": call.TTY,
		}}, nil
	})
	rawRoutes["/ws/sandbox-exec"] = (*Server).sbxExecSocket
}

func (s *Server) sbxExecSocket(w http.ResponseWriter, r *http.Request) {
	ws := upgradeWS(w, r)
	if ws == nil {
		return
	}
	defer ws.conn.Close()

	token := r.URL.Query().Get("token")
	s.mu.Lock()
	st := s.sbxExecWS()
	sess := st.sessions[token]
	delete(st.sessions, token) // one-time
	var log *SandboxExecSocketLog
	if sess != nil {
		log = &SandboxExecSocketLog{SandboxID: sess.call.SandboxID, Cmd: sess.call.Cmd, TTY: sess.call.TTY}
		st.sockets = append(st.sockets, log)
	}
	s.mu.Unlock()
	if sess == nil {
		ws.sendJSON(map[string]any{"type": "error", "message": "The exec session has expired. Request a new token."})
		ws.closeHandshake()
		return
	}

	call := sess.call
	isCat := len(call.Cmd) > 0 && path.Base(call.Cmd[len(call.Cmd)-1]) == "cat"
	ws.sendJSON(map[string]any{"type": "start", "exec_id": sess.execID, "tty": call.TTY})
	exit := func(code int, timedOut, killed bool) {
		ws.sendJSON(map[string]any{"type": "exit", "exit_code": code, "timed_out": timedOut, "duration_ms": 12, "killed": killed})
		ws.closeHandshake()
	}
	record := func() {
		s.mu.Lock()
		c := call
		c.Stdin = append(append([]byte(nil), call.Stdin...), log.Stdin...)
		s.sbx().calls = append(s.sbx().calls, c)
		if sb := s.sandboxByID(call.SandboxID); sb != nil {
			sb.log("exec", strings.Join(call.Cmd, " "))
		}
		s.mu.Unlock()
	}

	var fragment []byte
	for {
		fin, op, payload, err := ws.readFrame()
		if err != nil {
			return
		}
		switch op {
		case 0x9:
			ws.write(0xA, payload)
			continue
		case 0xA:
			continue
		case 0x8:
			ws.write(0x8, []byte{0x03, 0xE8})
			return
		case 0x0, 0x1, 0x2:
			fragment = append(fragment, payload...)
			if !fin {
				continue
			}
		}
		msg := fragment
		fragment = nil
		var m struct {
			Type string `json:"type"`
			Data string `json:"data"`
			Cols int    `json:"cols"`
			Rows int    `json:"rows"`
		}
		if json.Unmarshal(msg, &m) != nil {
			continue
		}
		// endInput answers the end of the command's input: cat exits, any other command replies.
		endInput := func() {
			s.mu.Lock()
			log.StdinClosed = true
			fn := s.sbx().exec
			s.mu.Unlock()
			record()
			if isCat {
				exit(0, false, false)
				return
			}
			c := call
			c.Stdin = append(append([]byte(nil), call.Stdin...), log.Stdin...)
			reply := sbxDefaultReply(c)
			if fn != nil {
				reply = fn(c)
			}
			for _, ch := range reply.Output {
				stream := ch.Stream
				if call.TTY {
					stream = "stdout" // the terminal merges the streams
				}
				ws.sendJSON(map[string]any{"type": stream, "data": base64.StdEncoding.EncodeToString(ch.Data)})
			}
			exit(reply.ExitCode, reply.TimedOut, false)
		}
		switch m.Type {
		case "stdin":
			in, err := base64.StdEncoding.DecodeString(m.Data)
			if err != nil {
				continue
			}
			eot := -1
			if call.TTY {
				eot = bytes.IndexByte(in, 0x04) // Ctrl+D on a terminal: the end of the input
			}
			if eot >= 0 {
				in = in[:eot]
			}
			s.mu.Lock()
			closed := log.StdinClosed
			if !closed {
				log.Stdin = append(log.Stdin, in...)
			}
			s.mu.Unlock()
			if isCat && !closed && len(in) > 0 {
				ws.sendJSON(map[string]any{"type": "stdout", "data": base64.StdEncoding.EncodeToString(in)})
			}
			if eot >= 0 && !closed {
				endInput()
				return
			}
		case "stdin_close":
			endInput()
			return
		case "resize":
			if call.TTY {
				s.mu.Lock()
				log.Resizes = append(log.Resizes, runr.TerminalSize{Cols: m.Cols, Rows: m.Rows})
				s.mu.Unlock()
			}
		case "kill":
			s.mu.Lock()
			log.Killed = true
			s.mu.Unlock()
			record()
			exit(137, false, true)
			return
		}
	}
}
