package runrtest

import (
	"bufio"
	"bytes"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The web shell's WebSocket (/ws/shell?token=…, docs/web-shell.md) for sandbox terminals: a
// one-time session from POST /sandboxes/{id}/terminal, then JSON text frames. The fake's shell
// echoes every input back as output; an input holding "exit" or Ctrl+D ends it with
// "[Process exited]" and a close frame. Before the shell starts it sends a ping, which the
// client must answer.

func init() {
	rawRoutes["/ws/shell"] = (*Server).sbxShell
}

// upgradeWS answers the WebSocket handshake of r; nil when it was refused (the answer is
// written). The caller closes the connection.
func upgradeWS(w http.ResponseWriter, r *http.Request) *sbxWS {
	if !strings.EqualFold(r.Header.Get("Upgrade"), "websocket") || r.Header.Get("Sec-WebSocket-Version") != "13" {
		http.Error(w, "WebSocket upgrade required", http.StatusBadRequest)
		return nil
	}
	key := r.Header.Get("Sec-WebSocket-Key")
	if key == "" {
		http.Error(w, "Sec-WebSocket-Key is required", http.StatusBadRequest)
		return nil
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "no hijacking", http.StatusInternalServerError)
		return nil
	}
	conn, rw, err := hj.Hijack()
	if err != nil {
		return nil
	}
	_, _ = rw.WriteString("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n" +
		"Sec-WebSocket-Accept: " + runr.WebSocketAccept(key) + "\r\n\r\n")
	_ = rw.Flush()
	return &sbxWS{conn: conn, r: rw.Reader}
}

func (s *Server) sbxShell(w http.ResponseWriter, r *http.Request) {
	ws := upgradeWS(w, r)
	if ws == nil {
		return
	}
	defer ws.conn.Close()

	token := r.URL.Query().Get("token")
	s.mu.Lock()
	ex := s.sbxExtra()
	sess := ex.sessions[token]
	delete(ex.sessions, token) // one-time
	var log *SandboxTerminalLog
	if sess != nil {
		log = &SandboxTerminalLog{SandboxID: sess.sandboxID, Cols: sess.cols, Rows: sess.rows}
		ex.terminals = append(ex.terminals, log)
	}
	s.mu.Unlock()
	if sess == nil {
		ws.sendJSON(map[string]any{"error": "The terminal session has expired. Open the terminal again."})
		ws.closeHandshake()
		return
	}

	ws.sendJSON(map[string]any{"status": "searching"})
	ws.write(0x9, []byte("are-you-there"))
	pod := "sbx-" + sess.sandboxID
	if id, ok := strings.CutPrefix(sess.sandboxID, "deployment:"); ok {
		pod = "fn-" + strings.ToLower(id) + "-deployment" // a deployment's shell (shell.go)
	}
	ws.sendJSON(map[string]any{"connected": true, "pod": pod})
	var fragment []byte
	for {
		fin, op, payload, err := ws.readFrame()
		if err != nil {
			return
		}
		switch op {
		case 0xA:
			s.mu.Lock()
			log.Pongs++
			s.mu.Unlock()
			continue
		case 0x9:
			ws.write(0xA, payload)
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
			Input  *string `json:"input"`
			Resize *struct {
				Cols int `json:"cols"`
				Rows int `json:"rows"`
			} `json:"resize"`
		}
		if json.Unmarshal(msg, &m) != nil {
			continue
		}
		if m.Resize != nil {
			s.mu.Lock()
			log.Resizes = append(log.Resizes, [2]int{m.Resize.Cols, m.Resize.Rows})
			s.mu.Unlock()
		}
		if m.Input == nil {
			continue
		}
		in, err := base64.StdEncoding.DecodeString(*m.Input)
		if err != nil {
			continue
		}
		s.mu.Lock()
		log.Input = append(log.Input, in...)
		s.mu.Unlock()
		ws.sendJSON(map[string]any{"output": base64.StdEncoding.EncodeToString(in)})
		if bytes.Contains(in, []byte("exit")) || bytes.IndexByte(in, 0x04) >= 0 {
			ws.sendJSON(map[string]any{"output": base64.StdEncoding.EncodeToString([]byte("\r\n[Process exited]\r\n"))})
			ws.closeHandshake()
			return
		}
	}
}

// sbxWS is the server side of a WebSocket: unmasked frames out, masked frames in.
type sbxWS struct {
	conn net.Conn
	r    *bufio.Reader
}

func (w *sbxWS) write(opcode byte, payload []byte) {
	header := []byte{0x80 | opcode}
	n := len(payload)
	switch {
	case n <= 125:
		header = append(header, byte(n))
	case n <= 0xFFFF:
		header = append(header, 126, byte(n>>8), byte(n))
	default:
		header = append(header, 127)
		var l [8]byte
		binary.BigEndian.PutUint64(l[:], uint64(n))
		header = append(header, l[:]...)
	}
	_, _ = w.conn.Write(append(header, payload...))
}

// closeHandshake sends a close frame and reads (discarding) until the client's close frame, the
// end of the connection or two seconds: closing the TCP connection while frames of the client
// are still unread makes some TCP stacks (Windows) reset it, and the client then loses what it
// had not read yet.
func (w *sbxWS) closeHandshake() {
	w.write(0x8, []byte{0x03, 0xE8})
	_ = w.conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	for {
		_, op, _, err := w.readFrame()
		if err != nil || op == 0x8 {
			return
		}
	}
}

func (w *sbxWS) sendJSON(v any) {
	data, _ := json.Marshal(v)
	w.write(0x1, data)
}

func (w *sbxWS) readFrame() (bool, byte, []byte, error) {
	var h [2]byte
	if _, err := io.ReadFull(w.r, h[:]); err != nil {
		return false, 0, nil, err
	}
	fin, op := h[0]&0x80 != 0, h[0]&0x0F
	if h[1]&0x80 == 0 {
		return false, 0, nil, errors.New("client frames must be masked")
	}
	n := uint64(h[1] & 0x7F)
	switch n {
	case 126:
		var l [2]byte
		if _, err := io.ReadFull(w.r, l[:]); err != nil {
			return false, 0, nil, err
		}
		n = uint64(binary.BigEndian.Uint16(l[:]))
	case 127:
		var l [8]byte
		if _, err := io.ReadFull(w.r, l[:]); err != nil {
			return false, 0, nil, err
		}
		n = binary.BigEndian.Uint64(l[:])
	}
	if n > 1<<20 {
		return false, 0, nil, errors.New("frame too large")
	}
	var mask [4]byte
	if _, err := io.ReadFull(w.r, mask[:]); err != nil {
		return false, 0, nil, err
	}
	payload := make([]byte, n)
	if _, err := io.ReadFull(w.r, payload); err != nil {
		return false, 0, nil, err
	}
	for i := range payload {
		payload[i] ^= mask[i%4]
	}
	return fin, op, payload, nil
}
