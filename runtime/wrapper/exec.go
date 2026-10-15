package main

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/http"
	"os/exec"
)

// execInvoker runs one process per invocation (exec mode). stdin is the request envelope
// (RUNR_INPUT=json) or the raw body (raw); the request is also in the environment
// (invocationEnv); stdout is the response (parseExecOutput); stderr goes to the logs.
type execInvoker struct {
	cfg config
}

func (e *execInvoker) Invoke(ctx context.Context, env *Envelope, rawBody []byte) *Response {
	ctx, cancel := context.WithTimeout(ctx, e.cfg.Timeout)
	defer cancel()

	cmd := command(ctx, e.cfg, invocationEnv(env)...)
	if e.cfg.Input == inputRaw {
		cmd.Stdin = bytes.NewReader(rawBody)
	} else {
		cmd.Stdin = bytes.NewReader(encodeEnvelope(env))
	}

	out := &capWriter{max: e.cfg.MaxBody}
	cmd.Stdout = out
	cmd.Stderr = e.cfg.Stderr

	err := cmd.Run()

	if ctxErr := ctx.Err(); ctxErr != nil {
		if errors.Is(ctxErr, context.DeadlineExceeded) {
			logger.Printf("request %s: handler timed out after %s, killed", env.ID, seconds(e.cfg.Timeout))
			return errorResponse(http.StatusGatewayTimeout, "handler timed out after "+seconds(e.cfg.Timeout))
		}
		return errorResponse(http.StatusServiceUnavailable, "request cancelled")
	}

	code := 0
	if err != nil {
		var exitErr *exec.ExitError
		if !errors.As(err, &exitErr) {
			if cmd.ProcessState == nil {
				logger.Printf("request %s: handler failed to start: %v", env.ID, err)
				return errorResponse(http.StatusInternalServerError, "handler failed to start")
			}
			// Wait error after the process exited (e.g. output pipes held open past pipeGrace)
			logger.Printf("request %s: %v", env.ID, err)
		}
		code = exitCode(cmd.ProcessState)
		if code != 0 {
			logger.Printf("request %s: handler exited with status %d", env.ID, code)
		}
	}

	if out.overflow {
		return errorResponse(http.StatusBadGateway, fmt.Sprintf("handler output exceeds %d bytes", e.cfg.MaxBody))
	}

	return parseExecOutput(out.buf.Bytes(), code)
}

func (e *execInvoker) Close() {}

// capWriter keeps up to max bytes; beyond that it discards the output and flags the overflow
// (the process is never blocked on a full pipe).
type capWriter struct {
	buf      bytes.Buffer
	max      int64
	overflow bool
}

func (c *capWriter) Write(p []byte) (int, error) {
	if c.overflow {
		return len(p), nil
	}
	if int64(c.buf.Len()+len(p)) > c.max {
		c.overflow = true
		c.buf.Reset()
		return len(p), nil
	}
	return c.buf.Write(p)
}
