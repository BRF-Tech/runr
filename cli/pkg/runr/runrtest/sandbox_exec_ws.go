package runrtest

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"path"
	"strings"
)

// The exec WebSocket of the fake (SandboxExecController::execWs, SandboxExecSocket):
// POST /sandboxes/{id}/exec/ws stores the prepared command under a one-time token, and
// /ws/sandbox-exec?token=… runs it. The fake's command reads its stdin until stdin_close and
// then answers with SandboxExecFunc (stdout, stderr, exit); `cat` echoes every stdin frame at
// once instead, and a kill frame ends any command with exit code 137.

// SandboxExecSocketLog is what an exec WebSocket of the fake received.
type SandboxExecSocketLog struct {
	SandboxID string
	Cmd       []string
	// Stdin is every byte of the stdin frames (without the session's initial stdin).
	Stdin []byte
	// StdinClosed is set by a stdin_close frame, Killed by a kill frame.
	StdinClosed bool
	Killed      bool
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
			"token": token, "url": "/ws/sandbox-exec?token=" + token, "exec_id": execID, "expires_in": 60,
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
		log = &SandboxExecSocketLog{SandboxID: sess.call.SandboxID, Cmd: sess.call.Cmd}
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
	ws.sendJSON(map[string]any{"type": "start", "exec_id": sess.execID})
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
		}
		if json.Unmarshal(msg, &m) != nil {
			continue
		}
		switch m.Type {
		case "stdin":
			in, err := base64.StdEncoding.DecodeString(m.Data)
			if err != nil {
				continue
			}
			s.mu.Lock()
			closed := log.StdinClosed
			if !closed {
				log.Stdin = append(log.Stdin, in...)
			}
			s.mu.Unlock()
			if isCat && !closed {
				ws.sendJSON(map[string]any{"type": "stdout", "data": base64.StdEncoding.EncodeToString(in)})
			}
		case "stdin_close":
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
				ws.sendJSON(map[string]any{"type": ch.Stream, "data": base64.StdEncoding.EncodeToString(ch.Data)})
			}
			exit(reply.ExitCode, reply.TimedOut, false)
			return
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
