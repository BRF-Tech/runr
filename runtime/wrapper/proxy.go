package main

// Observed http mode: with tracing or replay capture on, the wrapper does not exec an http-mode
// runtime's server (execServer) but starts it on an internal port (RUNR_UPSTREAM_PORT, default
// $PORT+1) and fronts it as a reverse proxy on $PORT. Every request then gets the wrapper's
// SERVER span (the runtime's own instrumentation continues the trace from the traceparent the
// proxy sends it) and failed requests are captured — for built-in and custom runtimes alike.

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// upstreamPort of the runtime's server behind the proxy.
func upstreamPort(cfg config, getenv func(string) string) string {
	if p := strings.TrimSpace(getenv("RUNR_UPSTREAM_PORT")); p != "" {
		return p
	}
	if n, err := strconv.Atoi(cfg.Port); err == nil && n > 0 && n < 65535 {
		return strconv.Itoa(n + 1)
	}
	return "8081"
}

// runObservedProxy starts the runtime's server, waits until it listens and proxies to it until
// SIGTERM (drain, then stop the server) or until the server exits (exit 1: Knative restarts it).
func runObservedProxy(cfg config, o *observability) int {
	port := upstreamPort(cfg, os.Getenv)

	cmd := command(nil, cfg, "PORT="+port)
	cmd.Stdout = cfg.Stderr
	cmd.Stderr = cfg.Stderr
	if cfg.Stdout != nil {
		cmd.Stdout = cfg.Stdout
	}

	proc, err := startWaiter(cmd)
	if err != nil {
		logger.Printf("server command failed to start: %v", err)
		return 127
	}
	defer proc.stop(stopGrace)

	addr := net.JoinHostPort("127.0.0.1", port)
	if err := waitForPort(addr, startupTimeout, proc.done); err != nil {
		logger.Printf("%v", err)
		return 1
	}

	srv := &http.Server{
		Addr:              ":" + cfg.Port,
		Handler:           newProxyHandler(cfg, o, "http://"+addr),
		ReadHeaderTimeout: 10 * time.Second,
	}

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	defer signal.Stop(sig)

	errCh := make(chan error, 1)
	go func() { errCh <- srv.ListenAndServe() }()
	logger.Printf("runr-wrapper %s observing on :%s → %s (tracing=%t replay=%t command=%q)", version, cfg.Port, addr, o.exp != nil, o.replay != nil, cfg.Command)

	select {
	case err := <-errCh:
		if err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Printf("server: %v", err)
			return 1
		}
	case <-proc.done:
		logger.Printf("runtime server exited (%s)", describeExit(proc))
		_ = srv.Close()
		return 1
	case s := <-sig:
		logger.Printf("%s received, draining in-flight requests", s)
		ctx, cancel := context.WithTimeout(context.Background(), drainTimeout)
		defer cancel()
		if err := srv.Shutdown(ctx); err != nil {
			logger.Printf("shutdown: %v", err)
		}
	}
	return 0
}

// newProxyHandler forwards each request to upstream (the runtime's server) inside a traced /
// captured invocation. Knative probes are answered here, like newHandler does.
func newProxyHandler(cfg config, o *observability, upstream string) http.Handler {
	target, _ := url.Parse(upstream)
	client := &http.Client{
		Transport: &http.Transport{
			Proxy:               nil,
			DialContext:         (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
			MaxIdleConnsPerHost: 64,
			IdleConnTimeout:     90 * time.Second,
			DisableCompression:  true,
		},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("K-Network-Probe") != "" || r.Header.Get("K-Kubelet-Probe") != "" {
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte("ok"))
			return
		}

		body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, cfg.MaxBody))
		if err != nil {
			var tooLarge *http.MaxBytesError
			if errors.As(err, &tooLarge) {
				writeResponse(w, errorResponse(http.StatusRequestEntityTooLarge, fmt.Sprintf("request body exceeds %d bytes", cfg.MaxBody)))
				return
			}
			writeResponse(w, errorResponse(http.StatusBadRequest, "could not read the request body"))
			return
		}

		inv := o.begin(request{
			Trigger:  cfg.Trigger,
			ID:       firstNonEmpty(r.Header.Get("X-Request-Id"), r.Header.Get("Ce-Id")),
			Method:   r.Method,
			Path:     firstNonEmpty(r.URL.Path, "/"),
			RawQuery: r.URL.RawQuery,
			Host:     r.Host,
			Header:   r.Header.Clone(),
			Body:     body,
		})

		ctx, cancel := context.WithTimeout(r.Context(), cfg.Timeout)
		defer cancel()

		out, err := http.NewRequestWithContext(ctx, r.Method, target.ResolveReference(&url.URL{Path: r.URL.Path, RawPath: r.URL.RawPath, RawQuery: r.URL.RawQuery}).String(), bytes.NewReader(body))
		if err != nil {
			resp := errorResponse(http.StatusBadGateway, "could not forward the request")
			writeResponse(w, resp)
			inv.finish(resp.Status, resp.Header, resp.Body, false)
			return
		}
		copyHeaders(out.Header, r.Header)
		out.Host = r.Host
		out.ContentLength = int64(len(body))
		if inv.tracing() {
			out.Header.Set("Traceparent", inv.traceparent())
		}

		upstreamResp, err := client.Do(out)
		if err != nil {
			timedOut := errors.Is(ctx.Err(), context.DeadlineExceeded)
			resp := errorResponse(http.StatusBadGateway, "handler unavailable")
			if timedOut {
				logger.Printf("request %s %s: handler timed out after %s", r.Method, r.URL.Path, seconds(cfg.Timeout))
				resp = errorResponse(http.StatusGatewayTimeout, "handler timed out after "+seconds(cfg.Timeout))
			} else if r.Context().Err() != nil {
				resp = errorResponse(http.StatusServiceUnavailable, "request cancelled")
			}
			if inv.tracing() {
				resp.Header.Set("Traceresponse", inv.traceparent())
			}
			writeResponse(w, resp)
			inv.finish(resp.Status, resp.Header, resp.Body, timedOut)
			return
		}
		defer upstreamResp.Body.Close()

		copyHeaders(w.Header(), upstreamResp.Header)
		if inv.tracing() {
			w.Header().Set("Traceresponse", inv.traceparent())
		}
		w.WriteHeader(upstreamResp.StatusCode)

		// Keep the start of a failed response for the capture (one byte over the limit, so the
		// capture knows it was cut); stream everything to the client
		var kept *capWriter
		var dst io.Writer = flushWriter{w}
		if o.replay != nil && upstreamResp.StatusCode >= 500 {
			keep := o.replay.cfg.MaxBody + 1
			kept = &capWriter{max: int64(keep)}
			dst = io.MultiWriter(dst, &prefixWriter{w: kept, max: keep})
		}
		_, copyErr := io.Copy(dst, upstreamResp.Body)
		timedOut := copyErr != nil && errors.Is(ctx.Err(), context.DeadlineExceeded)

		var keptBody []byte
		if kept != nil {
			keptBody = kept.buf.Bytes()
		}
		inv.finish(upstreamResp.StatusCode, upstreamResp.Header, keptBody, timedOut)
	})
}

// copyHeaders copies end-to-end headers (no hop-by-hop headers, as a proxy must).
func copyHeaders(dst, src http.Header) {
	hop := map[string]bool{}
	for _, h := range hopByHopHeaders {
		hop[http.CanonicalHeaderKey(h)] = true
	}
	for _, f := range src.Values("Connection") {
		for _, name := range strings.Split(f, ",") {
			hop[http.CanonicalHeaderKey(strings.TrimSpace(name))] = true
		}
	}
	for k, vs := range src {
		if hop[http.CanonicalHeaderKey(k)] {
			continue
		}
		dst[k] = append([]string(nil), vs...)
	}
}

// flushWriter flushes after every write so streamed responses (SSE) pass through.
type flushWriter struct{ w http.ResponseWriter }

func (f flushWriter) Write(p []byte) (int, error) {
	n, err := f.w.Write(p)
	if fl, ok := f.w.(http.Flusher); ok {
		fl.Flush()
	}
	return n, err
}

// prefixWriter keeps the first max bytes in w and accepts (discards) the rest.
type prefixWriter struct {
	w   *capWriter
	max int
	n   int
}

func (p *prefixWriter) Write(b []byte) (int, error) {
	if room := p.max - p.n; room > 0 {
		chunk := b
		if len(chunk) > room {
			chunk = chunk[:room]
		}
		_, _ = p.w.Write(chunk)
		p.n += len(chunk)
	}
	return len(b), nil
}
