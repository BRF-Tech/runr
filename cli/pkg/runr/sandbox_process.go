package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
)

// Background processes, pause / resume and the lifetime of a sandbox
// (SandboxProcessesController, SandboxesController).

// Process states.
const (
	ProcessRunning = "running"
	ProcessExited  = "exited"
	ProcessKilled  = "killed"
)

// SandboxSignals are the signals a background process can be sent.
var SandboxSignals = []string{"TERM", "KILL", "INT", "HUP", "QUIT", "USR1", "USR2", "STOP", "CONT"}

// SandboxProcess is a background process of a sandbox.
type SandboxProcess struct {
	ID        string `json:"id"`
	SandboxID string `json:"sandbox_id"`
	PID       *int   `json:"pid,omitempty"`
	// Command is the command line as the API records it.
	Command   string `json:"command"`
	Cwd       string `json:"cwd,omitempty"`
	Status    string `json:"status"`
	ExitCode  *int   `json:"exit_code,omitempty"`
	StartedAt string `json:"started_at,omitempty"`
	EndedAt   string `json:"ended_at,omitempty"`
}

// StartProcessInput is the body of POST /sandboxes/{id}/processes. Cmd is a string (run by
// /bin/sh -c) or a []string (the argv).
type StartProcessInput struct {
	Cmd any               `json:"cmd"`
	Env map[string]string `json:"env,omitempty"`
	Cwd string            `json:"cwd,omitempty"`
}

// StartSandboxProcess starts a background process and returns it (status running).
func (c *Client) StartSandboxProcess(ctx context.Context, id string, in StartProcessInput) (*SandboxProcess, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/processes", body: in}, &raw); err != nil {
		return nil, err
	}
	var p SandboxProcess
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode the process: %w", err)
	}
	return &p, nil
}

// ListSandboxProcesses returns the processes of a sandbox, newest first, with their state read
// from the sandbox.
func (c *Client) ListSandboxProcesses(ctx context.Context, id string) ([]SandboxProcess, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/processes"}, &raw); err != nil {
		return nil, err
	}
	list := []SandboxProcess{}
	if err := decodeList(raw, &list, nil); err != nil {
		return nil, fmt.Errorf("decode the processes: %w", err)
	}
	return list, nil
}

// GetSandboxProcess returns one process of a sandbox.
func (c *Client) GetSandboxProcess(ctx context.Context, id, process string) (*SandboxProcess, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/processes/" + seg(process)}, &raw); err != nil {
		return nil, err
	}
	var p SandboxProcess
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode the process: %w", err)
	}
	return &p, nil
}

// KillSandboxProcess sends a signal ("" = TERM) to a process and returns its state.
func (c *Client) KillSandboxProcess(ctx context.Context, id, process, signal string) (*SandboxProcess, error) {
	q := url.Values{}
	if signal != "" {
		q.Set("signal", signal)
	}
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodDelete, path: "/sandboxes/" + seg(id) + "/processes/" + seg(process), query: q}, &raw); err != nil {
		return nil, err
	}
	var p SandboxProcess
	if err := decodeData(raw, &p); err != nil {
		return nil, fmt.Errorf("decode the process: %w", err)
	}
	return &p, nil
}

// SandboxProcessLogs is the tail of a process's output.
type SandboxProcessLogs struct {
	Stdout       string `json:"stdout"`
	StdoutBase64 string `json:"stdout_base64,omitempty"`
	Stderr       string `json:"stderr"`
	StderrBase64 string `json:"stderr_base64,omitempty"`
	Truncated    bool   `json:"truncated"`
}

// StdoutBytes returns stdout, decoding stdout_base64 when the output was not UTF-8.
func (l *SandboxProcessLogs) StdoutBytes() []byte { return textOrBase64(l.Stdout, l.StdoutBase64) }

// StderrBytes returns stderr, decoding stderr_base64 when the output was not UTF-8.
func (l *SandboxProcessLogs) StderrBytes() []byte { return textOrBase64(l.Stderr, l.StderrBase64) }

// SandboxProcessOutput returns the last tailBytes (0 = the API's default, 64 KiB) of a process's
// stdout and stderr.
func (c *Client) SandboxProcessOutput(ctx context.Context, id, process string, tailBytes int) (*SandboxProcessLogs, error) {
	q := url.Values{}
	if tailBytes > 0 {
		q.Set("tail_bytes", strconv.Itoa(tailBytes))
	}
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodGet, path: "/sandboxes/" + seg(id) + "/processes/" + seg(process) + "/logs", query: q}, &raw); err != nil {
		return nil, err
	}
	var l SandboxProcessLogs
	if err := decodeData(raw, &l); err != nil {
		return nil, fmt.Errorf("decode the process output: %w", err)
	}
	return &l, nil
}

// SandboxProcessEnd is how a followed process ended (the exit event).
type SandboxProcessEnd struct {
	ExitCode *int   `json:"exit_code"`
	Status   string `json:"status"`
}

// FollowSandboxProcess streams a process's output (Server-Sent Events, follow=1) to stdout and
// stderr as it is written, until the process ends, and returns how it ended.
func (c *Client) FollowSandboxProcess(ctx context.Context, id, process string, stdout, stderr io.Writer) (*SandboxProcessEnd, error) {
	path := "/sandboxes/" + seg(id) + "/processes/" + seg(process) + "/logs"
	end := &SandboxProcessEnd{}
	gotExit := false
	err := c.streamSSE(ctx, request{method: http.MethodGet, path: path, query: url.Values{"follow": {"1"}}}, func(ev SSEEvent) error {
		switch ev.Event {
		case "stdout", "stderr":
			var chunk struct {
				Data       string `json:"data"`
				DataBase64 string `json:"data_base64"`
			}
			if err := json.Unmarshal([]byte(ev.Data), &chunk); err != nil {
				return fmt.Errorf("decode %s event: %w", ev.Event, err)
			}
			w := stdout
			if ev.Event == "stderr" {
				w = stderr
			}
			if w != nil {
				if _, err := w.Write(textOrBase64(chunk.Data, chunk.DataBase64)); err != nil {
					return err
				}
			}
		case "exit":
			if err := json.Unmarshal([]byte(ev.Data), end); err != nil {
				return fmt.Errorf("decode exit event: %w", err)
			}
			gotExit = true
		case "error":
			return sseError(http.MethodGet, path, ev)
		}
		return nil
	})
	if err != nil {
		return end, err
	}
	if !gotExit {
		return end, fmt.Errorf("GET %s: the event stream ended before the process exited", path)
	}
	return end, nil
}

// sseError turns an error event into an APIError.
func sseError(method, path string, ev SSEEvent) error {
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
	return &APIError{StatusCode: status, Method: method, Path: path, Message: msg}
}

// sandboxAction posts to /sandboxes/{id}/{action} and returns the sandbox.
func (c *Client) sandboxAction(ctx context.Context, id, action string, body any) (*Sandbox, error) {
	var raw json.RawMessage
	if err := c.do(ctx, request{method: http.MethodPost, path: "/sandboxes/" + seg(id) + "/" + action, body: body}, &raw); err != nil {
		return nil, err
	}
	var s Sandbox
	if err := decodeData(raw, &s); err != nil {
		return nil, fmt.Errorf("decode the sandbox: %w", err)
	}
	return &s, nil
}

// PauseSandbox freezes a running sandbox (its processes stop; the TTL keeps counting).
func (c *Client) PauseSandbox(ctx context.Context, id string) (*Sandbox, error) {
	return c.sandboxAction(ctx, id, "pause", nil)
}

// ResumeSandbox lets a paused sandbox run again.
func (c *Client) ResumeSandbox(ctx context.Context, id string) (*Sandbox, error) {
	return c.sandboxAction(ctx, id, "resume", nil)
}

// ExtendSandbox sets the sandbox to end ttlSeconds from now (within the region's maximum TTL
// counted from its start).
func (c *Client) ExtendSandbox(ctx context.Context, id string, ttlSeconds int) (*Sandbox, error) {
	return c.sandboxAction(ctx, id, "timeout", map[string]any{"ttl_seconds": ttlSeconds})
}

// KeepaliveSandbox resets the idle timer of a sandbox.
func (c *Client) KeepaliveSandbox(ctx context.Context, id string) (*Sandbox, error) {
	return c.sandboxAction(ctx, id, "keepalive", nil)
}
