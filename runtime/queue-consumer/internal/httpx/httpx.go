// Package httpx runs the gateway's HTTP servers.
package httpx

import (
	"context"
	"errors"
	"net/http"
	"time"
)

// Serve listens on addr until ctx ends, then shuts the server down gracefully (requests in
// progress get 5 seconds). It returns nil after a shutdown, the listen error otherwise.
func Serve(ctx context.Context, addr string, h http.Handler) error {
	srv := &http.Server{Addr: addr, Handler: h, ReadHeaderTimeout: 10 * time.Second}
	stopped := make(chan struct{})
	go func() {
		select {
		case <-ctx.Done():
			sctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			srv.Shutdown(sctx)
		case <-stopped:
		}
	}()
	err := srv.ListenAndServe()
	close(stopped)
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}
