package runr

import (
	"bufio"
	"context"
	"crypto/rand"
	"crypto/sha1"
	"crypto/tls"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// The terminal of a sandbox runs through RunR's web shell (docs/web-shell.md):
//
//  1. POST /sandboxes/{id}/terminal {cols, rows} answers a one-time session id (valid for 120
//     seconds) and its path /ws/shell?token=…;
//  2. the client opens that WebSocket on the console host (the gateway routes /ws/* to the
//     shell server) and exchanges JSON text frames:
//     client → server  {"input": "<base64>"}, {"resize": {"cols": N, "rows": N}}
//     server → client  {"status": "searching"|…}, {"connected": true, "pod": "…"},
//     {"output": "<base64>"}, {"error": "…"}
//  3. the server closes the connection when the shell exits or after five idle minutes.
//
// The WebSocket client below implements what that needs of RFC 6455: the opening handshake,
// masked client frames, text frames (fragmented or not), ping / pong and close.

// SandboxTerminalSession is the answer of POST /sandboxes/{id}/terminal.
type SandboxTerminalSession struct {
	SessionID string `json:"session_id"`
	// URL is the shell WebSocket's path (/ws/shell?token=…) or a full ws(s):// URL.
	URL string `json:"url"`
	// WebSocketURL is the URL the client connects to (filled by OpenSandboxTerminal).
	WebSocketURL string `json:"websocket_url"`
}

// OpenSandboxTerminal starts a terminal session of a running sandbox with the given size.
func (c *Client) OpenSandboxTerminal(ctx context.Context, id string, cols, rows int) (*SandboxTerminalSession, error) {
	body := map[string]any{}
	if cols > 0 {
		body["cols"] = cols
	}
	if rows > 0 {
		body["rows"] = rows
	}
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/terminal", body: body}, &raw); err != nil {
		return nil, err
	}
	var s SandboxTerminalSession
	if err := decodeData(raw, &s); err != nil {
		return nil, fmt.Errorf("decode the terminal session: %w", err)
	}
	if s.URL == "" && s.SessionID != "" {
		s.URL = "/ws/shell?token=" + url.QueryEscape(s.SessionID)
	}
	s.WebSocketURL = c.webSocketURL(s.URL)
	return &s, nil
}

// webSocketURL resolves a WebSocket path against the console host (ws:// for http, wss:// for
// https).
func (c *Client) webSocketURL(p string) string {
	if strings.HasPrefix(p, "ws://") || strings.HasPrefix(p, "wss://") {
		return p
	}
	scheme := "wss"
	if c.base.Scheme == "http" {
		scheme = "ws"
	}
	if !strings.HasPrefix(p, "/") {
		p = "/" + p
	}
	return scheme + "://" + c.base.Host + p
}

// TerminalFrame is one message of the shell server.
type TerminalFrame struct {
	// Output is terminal output (decoded from base64).
	Output []byte
	// Status is a progress state before the shell starts (searching, …).
	Status string
	// Connected is set when the shell runs; Pod names the pod.
	Connected bool
	Pod       string
	// Error is a message the server sends before it closes the connection.
	Error string
}

// SandboxTerminal is an open terminal session.
type SandboxTerminal struct {
	ws *wsConn
}

// DialSandboxTerminal connects to the WebSocket of a terminal session.
func (c *Client) DialSandboxTerminal(ctx context.Context, s *SandboxTerminalSession) (*SandboxTerminal, error) {
	u := s.WebSocketURL
	if u == "" {
		u = c.webSocketURL(s.URL)
	}
	// The session token is in the URL: the trace names the host only.
	c.tracef("> WebSocket %s (terminal session)\n", redactQuery(u))
	ws, err := dialWebSocket(ctx, u, c.userAgent, c.ConsoleURL())
	if err != nil {
		return nil, err
	}
	return &SandboxTerminal{ws: ws}, nil
}

func redactQuery(u string) string {
	if i := strings.IndexByte(u, '?'); i >= 0 {
		return u[:i] + "?[redacted]"
	}
	return u
}

// SendInput sends keyboard input to the shell.
func (t *SandboxTerminal) SendInput(b []byte) error {
	msg, _ := json.Marshal(map[string]string{"input": base64.StdEncoding.EncodeToString(b)})
	return t.ws.writeText(msg)
}

// Resize tells the shell the terminal's size.
func (t *SandboxTerminal) Resize(cols, rows int) error {
	msg, _ := json.Marshal(map[string]any{"resize": map[string]int{"cols": cols, "rows": rows}})
	return t.ws.writeText(msg)
}

// Next returns the next message of the server; io.EOF when the server closed the session.
func (t *SandboxTerminal) Next() (*TerminalFrame, error) {
	for {
		data, err := t.ws.readText()
		if err != nil {
			return nil, err
		}
		var m struct {
			Output    *string `json:"output"`
			Status    string  `json:"status"`
			Connected bool    `json:"connected"`
			Pod       string  `json:"pod"`
			Error     string  `json:"error"`
		}
		if json.Unmarshal(data, &m) != nil {
			continue // not a message of the shell protocol
		}
		f := &TerminalFrame{Status: m.Status, Connected: m.Connected, Pod: m.Pod, Error: m.Error}
		if m.Output != nil {
			out, err := base64.StdEncoding.DecodeString(*m.Output)
			if err != nil {
				return nil, fmt.Errorf("terminal output is not base64: %w", err)
			}
			f.Output = out
		}
		return f, nil
	}
}

// Close ends the session (a close frame, then the connection).
func (t *SandboxTerminal) Close() error { return t.ws.close() }

// ---------------------------------------------------------------------------------------
// A minimal WebSocket client (RFC 6455)

const wsGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

// wsMaxMessage bounds one message of the server.
const wsMaxMessage = 16 << 20

type wsConn struct {
	conn net.Conn
	r    *bufio.Reader
	wmu  sync.Mutex
	// closed is set once a close frame was sent.
	closed bool
}

// WebSocketAccept returns the Sec-WebSocket-Accept value of a Sec-WebSocket-Key.
func WebSocketAccept(key string) string {
	h := sha1.New()
	h.Write([]byte(key + wsGUID))
	return base64.StdEncoding.EncodeToString(h.Sum(nil))
}

func dialWebSocket(ctx context.Context, rawURL, userAgent, origin string) (*wsConn, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, fmt.Errorf("invalid WebSocket URL: %w", err)
	}
	host := u.Host
	secure := u.Scheme == "wss"
	if u.Scheme != "ws" && !secure {
		return nil, fmt.Errorf("invalid WebSocket URL %s: scheme must be ws or wss", redactQuery(rawURL))
	}
	if u.Port() == "" {
		if secure {
			host = net.JoinHostPort(u.Hostname(), "443")
		} else {
			host = net.JoinHostPort(u.Hostname(), "80")
		}
	}
	dialer := &net.Dialer{Timeout: 30 * time.Second, KeepAlive: 30 * time.Second}
	var conn net.Conn
	if secure {
		td := &tls.Dialer{NetDialer: dialer, Config: &tls.Config{ServerName: u.Hostname(), MinVersion: tls.VersionTLS12}}
		conn, err = td.DialContext(ctx, "tcp", host)
	} else {
		conn, err = dialer.DialContext(ctx, "tcp", host)
	}
	if err != nil {
		return nil, &NetworkError{Method: http.MethodGet, URL: redactQuery(rawURL), Err: err}
	}

	keyBytes := make([]byte, 16)
	if _, err := rand.Read(keyBytes); err != nil {
		conn.Close()
		return nil, err
	}
	key := base64.StdEncoding.EncodeToString(keyBytes)
	req := &http.Request{
		Method: http.MethodGet,
		URL:    &url.URL{Path: u.Path, RawQuery: u.RawQuery},
		Host:   u.Host,
		Header: http.Header{},
		Proto:  "HTTP/1.1", ProtoMajor: 1, ProtoMinor: 1,
	}
	if req.URL.Path == "" {
		req.URL.Path = "/"
	}
	req.Header.Set("Upgrade", "websocket")
	req.Header.Set("Connection", "Upgrade")
	req.Header.Set("Sec-WebSocket-Key", key)
	req.Header.Set("Sec-WebSocket-Version", "13")
	if userAgent != "" {
		req.Header.Set("User-Agent", userAgent)
	}
	if origin != "" {
		req.Header.Set("Origin", origin)
	}

	// The handshake must finish within the context's deadline (or 30 seconds).
	deadline := time.Now().Add(30 * time.Second)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	_ = conn.SetDeadline(deadline)
	if err := req.Write(conn); err != nil {
		conn.Close()
		return nil, &NetworkError{Method: http.MethodGet, URL: redactQuery(rawURL), Err: err}
	}
	br := bufio.NewReader(conn)
	resp, err := http.ReadResponse(br, req)
	if err != nil {
		conn.Close()
		return nil, &NetworkError{Method: http.MethodGet, URL: redactQuery(rawURL), Err: err}
	}
	if resp.StatusCode != http.StatusSwitchingProtocols {
		data, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		resp.Body.Close()
		conn.Close()
		return nil, parseAPIError(resp.StatusCode, http.MethodGet, u.Path, data)
	}
	if !strings.EqualFold(resp.Header.Get("Upgrade"), "websocket") || resp.Header.Get("Sec-WebSocket-Accept") != WebSocketAccept(key) {
		conn.Close()
		return nil, errors.New("the server did not accept the WebSocket handshake")
	}
	_ = conn.SetDeadline(time.Time{})
	ws := &wsConn{conn: conn, r: br}
	go func() {
		// The context ends the session (Ctrl+C).
		<-ctx.Done()
		_ = ws.close()
	}()
	return ws, nil
}

// writeFrame writes one masked frame.
func (w *wsConn) writeFrame(opcode byte, payload []byte) error {
	w.wmu.Lock()
	defer w.wmu.Unlock()
	if w.closed && opcode != 0x8 {
		return net.ErrClosed
	}
	header := []byte{0x80 | opcode}
	n := len(payload)
	switch {
	case n <= 125:
		header = append(header, 0x80|byte(n))
	case n <= 0xFFFF:
		header = append(header, 0x80|126, byte(n>>8), byte(n))
	default:
		header = append(header, 0x80|127)
		var l [8]byte
		binary.BigEndian.PutUint64(l[:], uint64(n))
		header = append(header, l[:]...)
	}
	var mask [4]byte
	if _, err := rand.Read(mask[:]); err != nil {
		return err
	}
	header = append(header, mask[:]...)
	masked := make([]byte, n)
	for i := range payload {
		masked[i] = payload[i] ^ mask[i%4]
	}
	if _, err := w.conn.Write(append(header, masked...)); err != nil {
		return err
	}
	return nil
}

func (w *wsConn) writeText(b []byte) error { return w.writeFrame(0x1, b) }

// readFrame reads one frame (unmasking it if the server masked it).
func (w *wsConn) readFrame() (fin bool, opcode byte, payload []byte, err error) {
	var h [2]byte
	if _, err = io.ReadFull(w.r, h[:]); err != nil {
		return
	}
	fin = h[0]&0x80 != 0
	opcode = h[0] & 0x0F
	masked := h[1]&0x80 != 0
	n := uint64(h[1] & 0x7F)
	switch n {
	case 126:
		var l [2]byte
		if _, err = io.ReadFull(w.r, l[:]); err != nil {
			return
		}
		n = uint64(binary.BigEndian.Uint16(l[:]))
	case 127:
		var l [8]byte
		if _, err = io.ReadFull(w.r, l[:]); err != nil {
			return
		}
		n = binary.BigEndian.Uint64(l[:])
	}
	if n > wsMaxMessage {
		err = fmt.Errorf("WebSocket frame of %d bytes exceeds the limit", n)
		return
	}
	var mask [4]byte
	if masked {
		if _, err = io.ReadFull(w.r, mask[:]); err != nil {
			return
		}
	}
	payload = make([]byte, n)
	if _, err = io.ReadFull(w.r, payload); err != nil {
		return
	}
	if masked {
		for i := range payload {
			payload[i] ^= mask[i%4]
		}
	}
	return
}

// readText returns the next text or binary message; it answers pings, skips pongs and returns
// io.EOF after a close frame.
func (w *wsConn) readText() ([]byte, error) {
	var msg []byte
	inMessage := false
	for {
		fin, op, payload, err := w.readFrame()
		if err != nil {
			if errors.Is(err, net.ErrClosed) || errors.Is(err, io.ErrUnexpectedEOF) {
				return nil, io.EOF
			}
			return nil, err
		}
		switch op {
		case 0x9: // ping
			if err := w.writeFrame(0xA, payload); err != nil && !errors.Is(err, net.ErrClosed) {
				return nil, err
			}
			continue
		case 0xA: // pong
			continue
		case 0x8: // close
			_ = w.close()
			return nil, io.EOF
		case 0x0: // continuation
			if !inMessage {
				return nil, errors.New("WebSocket continuation frame without a message")
			}
		case 0x1, 0x2:
			if inMessage {
				return nil, errors.New("WebSocket message started inside another message")
			}
			inMessage = true
		default:
			return nil, fmt.Errorf("unknown WebSocket opcode %d", op)
		}
		msg = append(msg, payload...)
		if len(msg) > wsMaxMessage {
			return nil, errors.New("WebSocket message exceeds the limit")
		}
		if fin {
			return msg, nil
		}
	}
}

// close sends a close frame (once) and closes the connection.
func (w *wsConn) close() error {
	w.wmu.Lock()
	already := w.closed
	w.closed = true
	w.wmu.Unlock()
	if already {
		return nil
	}
	_ = w.writeFrame(0x8, []byte{0x03, 0xE8}) // 1000 normal closure
	return w.conn.Close()
}
