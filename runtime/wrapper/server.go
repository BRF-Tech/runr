package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"syscall"
	"time"
)

// drainTimeout bounds the graceful shutdown on SIGTERM (Knative's default termination grace
// period is 30 s).
const drainTimeout = 25 * time.Second

// newHandler serves invocations over HTTP (http and queue triggers).
func newHandler(cfg config, inv invoker) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Knative probes are answered here, the handler is not invoked (like the built-in runtimes)
		if r.Header.Get("K-Network-Probe") != "" || r.Header.Get("K-Kubelet-Probe") != "" {
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte("ok"))
			return
		}

		if r.Method == http.MethodOptions {
			h := w.Header()
			h.Set("Access-Control-Allow-Origin", "*")
			h.Set("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, PATCH, OPTIONS")
			h.Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
			h.Set("Access-Control-Max-Age", "86400")
			w.WriteHeader(http.StatusNoContent)
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

		env := buildEnvelope(r, body, cfg.Trigger)
		writeResponse(w, inv.Invoke(r.Context(), env, body))
	})
}

// serve listens on :PORT until SIGTERM / SIGINT, then drains in-flight requests.
func serve(cfg config, inv invoker) int {
	srv := &http.Server{
		Addr:              ":" + cfg.Port,
		Handler:           newHandler(cfg, inv),
		ReadHeaderTimeout: 10 * time.Second,
	}

	errCh := make(chan error, 1)
	go func() { errCh <- srv.ListenAndServe() }()
	logger.Printf("runr-wrapper %s listening on :%s (mode=%s trigger=%s command=%q)", version, cfg.Port, cfg.Mode, cfg.Trigger, cfg.Command)

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGTERM, syscall.SIGINT)
	defer signal.Stop(sig)

	select {
	case err := <-errCh:
		if err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Printf("server: %v", err)
			return 1
		}
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

// execServer replaces the wrapper with the runtime's own server (http mode, http / queue
// trigger): the wrapper adds nothing on that path.
func execServer(cfg config) int {
	path, err := exec.LookPath(cfg.Command[0])
	if err != nil {
		logger.Printf("server command: %v", err)
		return 127
	}

	if cfg.Dir != "" {
		if err := os.Chdir(cfg.Dir); err != nil {
			logger.Printf("chdir %s: %v", cfg.Dir, err)
		}
	}

	env := append(append(os.Environ(), cfg.Env...), "PORT="+cfg.Port)
	err = syscall.Exec(path, cfg.Command, env)
	logger.Printf("exec %s: %v", path, err)
	return 126
}
