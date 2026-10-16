package runr

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Streamed commands of the Sandbox API: POST /sandboxes/{id}/exec and /run answer with
// Server-Sent Events when asked (Accept: text/event-stream, stream: true):
//
//	event: start   {"exec_id", "sandbox_id"}
//	event: stdout  {"data": "<text>"} or {"data_base64": "<bytes>"}
//	event: stderr  (the same)
//	event: exit    {"exit_code", "timed_out", "duration_ms", "truncated"}
//	event: result  the structured result of a code run (without stdout / stderr)
//	event: error   {"message", "reason", "status"}
//
// plus ": ping" comment lines while the command is quiet. The field names are those of
// SandboxExecController and SseStream (api/app/Http/Controllers/Api/Sandboxes).

// SSEEvent is one dispatched Server-Sent Event.
type SSEEvent struct {
	Event string
	Data  string
	ID    string
}

// SSEDecoder turns a byte stream into events. Chunks may end anywhere — in the middle of a
// line, of a CRLF pair or of a UTF-8 character (lines are split on ASCII bytes, so a partial
// character waits in the buffer); lines end with \r\n, \n or \r; comment lines are skipped and
// several data lines of one event are joined with \n.
type SSEDecoder struct {
	buf     []byte
	event   string
	data    []string
	hasData bool
	id      string
	first   bool
	// cr is set when the last byte fed was a \r that may be followed by \n.
	cr bool
}

// Feed adds a chunk and returns the events it completed.
func (d *SSEDecoder) Feed(chunk []byte) []SSEEvent {
	if !d.first {
		d.first = true
		chunk = bytes.TrimPrefix(chunk, []byte("\xef\xbb\xbf"))
	}
	if d.cr && len(chunk) > 0 && chunk[0] == '\n' {
		chunk = chunk[1:]
	}
	d.cr = false
	d.buf = append(d.buf, chunk...)
	var out []SSEEvent
	for {
		i := bytes.IndexAny(d.buf, "\r\n")
		if i < 0 {
			break
		}
		line := string(d.buf[:i])
		skip := 1
		if d.buf[i] == '\r' {
			if i+1 < len(d.buf) {
				if d.buf[i+1] == '\n' {
					skip = 2
				}
			} else {
				d.cr = true
			}
		}
		d.buf = d.buf[i+skip:]
		if ev, ok := d.line(line); ok {
			out = append(out, ev)
		}
	}
	return out
}

// Flush ends the stream: a last event without its closing blank line is still dispatched.
func (d *SSEDecoder) Flush() []SSEEvent {
	var out []SSEEvent
	if len(d.buf) > 0 {
		line := string(d.buf)
		d.buf = nil
		if ev, ok := d.line(line); ok {
			out = append(out, ev)
		}
	}
	if ev, ok := d.dispatch(); ok {
		out = append(out, ev)
	}
	return out
}

func (d *SSEDecoder) line(line string) (SSEEvent, bool) {
	if line == "" {
		return d.dispatch()
	}
	if strings.HasPrefix(line, ":") {
		return SSEEvent{}, false
	}
	field, value, _ := strings.Cut(line, ":")
	value = strings.TrimPrefix(value, " ")
	switch field {
	case "event":
		d.event = value
	case "data":
		d.data = append(d.data, value)
		d.hasData = true
	case "id":
		d.id = value
	}
	return SSEEvent{}, false
}

func (d *SSEDecoder) dispatch() (SSEEvent, bool) {
	if !d.hasData && d.event == "" {
		return SSEEvent{}, false
	}
	ev := SSEEvent{Event: d.event, Data: strings.Join(d.data, "\n"), ID: d.id}
	if ev.Event == "" {
		ev.Event = "message"
	}
	d.event, d.data, d.hasData = "", nil, false
	return ev, true
}

// SandboxStream receives the output of a streamed command as it arrives.
type SandboxStream struct {
	Stdout io.Writer
	Stderr io.Writer
	// OnStart is called with the exec id when the command started (may be nil).
	OnStart func(execID string)
	// Resize delivers new terminal sizes while a command with a terminal runs (the exec
	// WebSocket with SandboxExecInput.TTY; nil: the size stays).
	Resize <-chan TerminalSize
}

// TerminalSize is the size of a terminal in columns and rows.
type TerminalSize struct {
	Cols int `json:"cols"`
	Rows int `json:"rows"`
}

// SandboxStreamResult is how a streamed command ended.
type SandboxStreamResult struct {
	ExecID     string `json:"exec_id,omitempty"`
	ExitCode   int    `json:"exit_code"`
	TimedOut   bool   `json:"timed_out"`
	DurationMS int64  `json:"duration_ms"`
	Truncated  bool   `json:"truncated"`
	// TTY is set when the command ran on a terminal (the exec WebSocket).
	TTY bool `json:"tty,omitempty"`
	// Result is the structured result of a code run (the result event), nil for exec.
	Result *SandboxExecResult `json:"result,omitempty"`
}

// StreamSandboxExec runs a command and writes its output to out as it arrives.
func (c *Client) StreamSandboxExec(ctx context.Context, id string, in SandboxExecInput, out SandboxStream) (*SandboxStreamResult, error) {
	in.Stream = true
	return c.streamSandbox(ctx, "/sandboxes/"+seg(id)+"/exec", in, out)
}

// StreamSandboxRun runs code with the language's interpreter and writes its output to out as
// it arrives; the result event (files changed, rich outputs) is in Result.
func (c *Client) StreamSandboxRun(ctx context.Context, id string, in SandboxRunInput, out SandboxStream) (*SandboxStreamResult, error) {
	in.Stream = true
	return c.streamSandbox(ctx, "/sandboxes/"+seg(id)+"/run", in, out)
}

func (c *Client) streamSandbox(ctx context.Context, path string, body any, out SandboxStream) (*SandboxStreamResult, error) {
	res := &SandboxStreamResult{}
	gotExit := false
	err := c.streamSSE(ctx, request{method: http.MethodPost, path: path, body: body}, func(ev SSEEvent) error {
		switch ev.Event {
		case "start":
			var s struct {
				ExecID string `json:"exec_id"`
			}
			_ = json.Unmarshal([]byte(ev.Data), &s)
			res.ExecID = s.ExecID
			if out.OnStart != nil {
				out.OnStart(s.ExecID)
			}
		case "stdout", "stderr":
			var chunk struct {
				Data       string `json:"data"`
				DataBase64 string `json:"data_base64"`
			}
			if err := json.Unmarshal([]byte(ev.Data), &chunk); err != nil {
				return fmt.Errorf("decode %s event: %w", ev.Event, err)
			}
			w := out.Stdout
			if ev.Event == "stderr" {
				w = out.Stderr
			}
			if w != nil {
				if _, err := w.Write(textOrBase64(chunk.Data, chunk.DataBase64)); err != nil {
					return err
				}
			}
		case "exit":
			var e struct {
				ExitCode   int   `json:"exit_code"`
				TimedOut   bool  `json:"timed_out"`
				DurationMS int64 `json:"duration_ms"`
				Truncated  bool  `json:"truncated"`
			}
			if err := json.Unmarshal([]byte(ev.Data), &e); err != nil {
				return fmt.Errorf("decode exit event: %w", err)
			}
			res.ExitCode, res.TimedOut, res.DurationMS, res.Truncated = e.ExitCode, e.TimedOut, e.DurationMS, e.Truncated
			gotExit = true
		case "result":
			var r SandboxExecResult
			if err := json.Unmarshal([]byte(ev.Data), &r); err != nil {
				return fmt.Errorf("decode result event: %w", err)
			}
			res.Result = &r
		case "error":
			var e struct {
				Message string `json:"message"`
				Reason  string `json:"reason"`
				Status  int    `json:"status"`
			}
			_ = json.Unmarshal([]byte(ev.Data), &e)
			status := e.Status
			if status == 0 {
				status = http.StatusBadGateway
			}
			msg := e.Message
			if e.Reason != "" {
				msg += " (" + e.Reason + ")"
			}
			return &APIError{StatusCode: status, Method: http.MethodPost, Path: path, Message: msg}
		}
		return nil
	})
	if err != nil {
		return res, err
	}
	if !gotExit {
		return res, fmt.Errorf("POST %s: the event stream ended before the command exited", path)
	}
	return res, nil
}

// streamSSE sends a JSON request that answers with Server-Sent Events and calls onEvent for
// each event as it arrives. A non-2xx answer is an APIError like the answers of do. The
// request has no overall timeout; ctx ends it.
func (c *Client) streamSSE(ctx context.Context, r request, onEvent func(SSEEvent) error) error {
	var payload []byte
	var body io.Reader
	if r.body != nil {
		var err error
		payload, err = json.Marshal(r.body)
		if err != nil {
			return fmt.Errorf("encode request body: %w", err)
		}
		body = bytes.NewReader(payload)
	}
	u := *c.base
	u.Path = c.base.Path + r.path
	u.RawPath = ""
	if strings.Contains(r.path, "%") {
		// The path holds escaped segments (seg): keep their encoding.
		if p, err := url.PathUnescape(r.path); err == nil {
			u.Path = c.base.Path + p
			u.RawPath = c.base.EscapedPath() + r.path
		}
	}
	if len(r.query) > 0 {
		u.RawQuery = r.query.Encode()
	}
	req, err := http.NewRequestWithContext(ctx, r.method, u.String(), body)
	if err != nil {
		return err
	}
	// application/json too, so errors before the stream starts come as JSON.
	req.Header.Set("Accept", "text/event-stream, application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("User-Agent", c.userAgent)
	if c.token != "" {
		req.Header.Set("Authorization", "Bearer "+c.token)
	}
	c.traceRequest(req, payload)
	start := time.Now()
	resp, err := c.long().http.Do(req)
	if err != nil {
		c.tracef("< error after %s: %v\n", time.Since(start).Round(time.Millisecond), err)
		return &NetworkError{Method: r.method, URL: u.String(), Err: err}
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		data, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
		c.traceResponse(resp, data, time.Since(start))
		return parseAPIError(resp.StatusCode, r.method, r.path, data)
	}
	if ct := resp.Header.Get("Content-Type"); !strings.HasPrefix(ct, "text/event-stream") {
		data, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
		c.traceResponse(resp, data, time.Since(start))
		return fmt.Errorf("%s %s: expected an event stream, got %s", r.method, r.path, ct)
	}
	c.traceResponse(resp, []byte("(event stream)"), time.Since(start))

	dec := &SSEDecoder{}
	buf := make([]byte, 32<<10)
	emit := func(events []SSEEvent) error {
		for _, ev := range events {
			// Output may be secret: the trace names the event and its size only.
			c.tracef("< event: %s (%d bytes)\n", ev.Event, len(ev.Data))
			if err := onEvent(ev); err != nil {
				return err
			}
		}
		return nil
	}
	for {
		n, readErr := resp.Body.Read(buf)
		if n > 0 {
			if err := emit(dec.Feed(buf[:n])); err != nil {
				return err
			}
		}
		if readErr == io.EOF {
			return emit(dec.Flush())
		}
		if readErr != nil {
			if ctx.Err() != nil {
				return ctx.Err()
			}
			return &NetworkError{Method: r.method, URL: u.String(), Err: readErr}
		}
	}
}

// EncodeBase64 returns b as standard base64 (stdin_base64, content_base64).
func EncodeBase64(b []byte) string { return base64.StdEncoding.EncodeToString(b) }
