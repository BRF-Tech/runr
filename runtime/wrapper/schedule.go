package main

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"time"
)

// startupTimeout bounds how long an http-mode server may take to listen on $PORT for a
// schedule tick (a variable so tests can shorten it).
var startupTimeout = 60 * time.Second

// runSchedule invokes the runtime once with a schedule tick (exec and persistent modes), prints
// the response body and returns the exit code: 0 when the status is < 400, else 1.
func runSchedule(cfg config, inv invoker) int {
	env := scheduleEnvelope(time.Now())

	resp := inv.Invoke(context.Background(), env, []byte(env.Body))
	writeScheduleOutput(cfg.Stdout, resp.Body)

	logger.Printf("schedule tick %s: status %d", env.ID, resp.Status)
	return statusExitCode(resp.Status)
}

// runProxySchedule runs a schedule tick against an http-mode runtime: it starts RUNR_COMMAND
// with PORT, waits until 127.0.0.1:$PORT accepts connections, POSTs the schedule CloudEvent
// (binary mode) to /, prints the response body, terminates the server and returns the exit code.
func runProxySchedule(cfg config) int {
	cmd := command(nil, cfg, "PORT="+cfg.Port)
	cmd.Stdout = cfg.Stderr // stdout of the wrapper is the response only
	cmd.Stderr = cfg.Stderr

	proc, err := startWaiter(cmd)
	if err != nil {
		logger.Printf("schedule: server failed to start: %v", err)
		return 1
	}
	defer proc.stop(stopGrace)

	addr := net.JoinHostPort("127.0.0.1", cfg.Port)
	if err := waitForPort(addr, startupTimeout, proc.done); err != nil {
		logger.Printf("schedule: %v", err)
		return 1
	}

	id, headers, data := scheduleEvent(time.Now())
	req, err := http.NewRequest(http.MethodPost, "http://"+addr+"/", bytes.NewReader(data))
	if err != nil {
		logger.Printf("schedule: %v", err)
		return 1
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	req.Header.Set("X-Request-Id", id)
	if tp := envValue(cfg.Env, tickTraceparentEnv); tp != "" {
		req.Header.Set("Traceparent", tp) // traced tick (observability.traceTick)
	}

	resp, err := (&http.Client{Timeout: cfg.Timeout}).Do(req)
	if err != nil {
		logger.Printf("schedule tick %s: %v", id, err)
		return 1
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, cfg.MaxBody))
	writeScheduleOutput(cfg.Stdout, body)

	logger.Printf("schedule tick %s: status %d", id, resp.StatusCode)
	return statusExitCode(resp.StatusCode)
}

// waitForPort polls addr until it accepts a TCP connection, the process exits or timeout passes.
func waitForPort(addr string, timeout time.Duration, exited <-chan struct{}) error {
	deadline := time.Now().Add(timeout)
	for {
		conn, err := net.DialTimeout("tcp", addr, 500*time.Millisecond)
		if err == nil {
			_ = conn.Close()
			return nil
		}

		select {
		case <-exited:
			return fmt.Errorf("server exited before listening on %s", addr)
		default:
		}

		if time.Now().After(deadline) {
			return fmt.Errorf("server did not listen on %s within %s", addr, seconds(timeout))
		}
		time.Sleep(100 * time.Millisecond)
	}
}

func writeScheduleOutput(w io.Writer, body []byte) {
	if len(body) == 0 {
		return
	}
	_, _ = w.Write(body)
	if body[len(body)-1] != '\n' {
		_, _ = w.Write([]byte("\n"))
	}
}

func statusExitCode(status int) int {
	if status < 400 {
		return 0
	}
	return 1
}
