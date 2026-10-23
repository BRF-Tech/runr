package main

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"sync"
	"sync/atomic"
	"time"
)

// The metering proxy (PROXY_LISTEN, Service runr-gateway-auth-proxy): the upstream of every key /
// JWT route whose IngressRoute meters its calls (App\Services\Concerns\GatewayManifestTrait). The
// forwardAuth check stays where it was — before the proxy, in the same Traefik router, so a route
// still fails closed (503 auth_unavailable) while gateway-auth cannot check calls — and hands the
// proxy a ticket of what it decided (ticket.go). The proxy hands the call on to the region Traefik
// (UPSTREAM_URL, its web entry point) unchanged — Host, path, headers and body as the gateway's
// middlewares left them — and records what the function answered under the ticket's consumer, key
// and route: status class, latency until the answer started, bytes in and out, and calls that got
// no answer at all (Recorder.Observe). Request and answer are streamed (long calls, server-sent
// events and WebSockets included). An upgraded connection (WebSocket) counts as a 2xx answer whose
// latency is its handshake; the bytes it carries either way are added when it closes.
//
//	403  no valid ticket: the call did not come through a gateway route's check
//	502  the region Traefik did not answer (upstream_unavailable)
//	504  it did not answer in time (upstream_timeout)

// Meter is the metering proxy.
type Meter struct {
	Tickets *Tickets
	Usage   *Recorder
	Logger  *slog.Logger
	Now     func() time.Time

	proxy *httputil.ReverseProxy
}

// meterState is what one call's proxying learns, read back once it is done.
type meterState struct {
	start   time.Time
	latency time.Duration
	status  int
	failed  bool // no answer: upstream error
	usage   UsageKey
}

type meterStateKey struct{}

// NewMeter proxies to upstream (http://traefik.<serving namespace>.svc.cluster.local).
func NewMeter(upstream *url.URL, tickets *Tickets, usage *Recorder, logger *slog.Logger) *Meter {
	m := &Meter{Tickets: tickets, Usage: usage, Logger: logger}
	transport := &http.Transport{
		DialContext:           (&net.Dialer{Timeout: 5 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		MaxIdleConns:          1024,
		MaxIdleConnsPerHost:   512,
		IdleConnTimeout:       90 * time.Second,
		ExpectContinueTimeout: time.Second,
		// the answer as the function sent it: no Accept-Encoding of our own, nothing decompressed
		DisableCompression: true,
	}
	m.proxy = &httputil.ReverseProxy{
		Rewrite: func(pr *httputil.ProxyRequest) {
			pr.SetURL(upstream)
			// the Host the gateway's Host rewrite set (the function's public host)
			pr.Out.Host = pr.In.Host
			// the first pass' X-Forwarded-* as they are: the client is not this pod
			for _, h := range []string{"X-Forwarded-For", "X-Forwarded-Host", "X-Forwarded-Proto"} {
				if v, ok := pr.In.Header[h]; ok {
					pr.Out.Header[h] = v
				}
			}
			pr.Out.Header.Del(TicketHeader)
		},
		Transport:     transport,
		FlushInterval: -1,
		ModifyResponse: func(resp *http.Response) error {
			if s, ok := resp.Request.Context().Value(meterStateKey{}).(*meterState); ok {
				// an upgraded connection's latency is its handshake
				s.latency = m.now().Sub(s.start)
				s.status = resp.StatusCode
				// an upgraded connection (WebSocket …): ReverseProxy tunnels through the body,
				// which is the connection to the function; its bytes count when it closes
				if resp.StatusCode == http.StatusSwitchingProtocols {
					if backend, ok := resp.Body.(io.ReadWriteCloser); ok {
						resp.Body = &countingTunnel{ReadWriteCloser: backend, done: func(in, out int64) { m.Usage.AddBytes(s.usage, in, out) }}
					}
				}
			}
			return nil
		},
		ErrorHandler: m.upstreamError,
	}
	if logger != nil {
		m.proxy.ErrorLog = slog.NewLogLogger(logger.Handler(), slog.LevelDebug)
	}
	return m
}

func (m *Meter) now() time.Time {
	if m.Now != nil {
		return m.Now()
	}
	return time.Now()
}

func (m *Meter) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	start := m.now()
	ticket, err := m.Tickets.Verify(r.Header.Get(TicketHeader), start)
	if err != nil {
		if m.Logger != nil {
			m.Logger.Debug("call without a valid ticket refused", "reason", err.Error(), "remote", r.RemoteAddr)
		}
		writeJSON(w, http.StatusForbidden, map[string]string{"error": "not_through_gateway", "message": "This API is called through its gateway only."})
		return
	}

	state := &meterState{start: start, usage: ticket.Usage()}
	out := &countingWriter{ResponseWriter: w}
	var in *countingBody
	if r.Body != nil && r.Body != http.NoBody {
		in = &countingBody{ReadCloser: r.Body}
		r.Body = in
	}
	r = r.WithContext(context.WithValue(r.Context(), meterStateKey{}, state))

	// also when the answer breaks off half way (ReverseProxy aborts the handler)
	defer func() {
		if state.status == 0 && !state.failed {
			return // the caller went away before an answer: nothing to say about the function
		}
		resp := Response{Status: state.status, Latency: state.latency, BytesOut: out.n}
		if state.failed {
			resp.Status = 0
		}
		if in != nil {
			resp.BytesIn = in.n
		}
		m.Usage.Observe(state.usage, resp)
	}()
	m.proxy.ServeHTTP(out, r)
}

// upstreamError: the region Traefik gave no answer. A caller that went away is not the function's
// failure.
func (m *Meter) upstreamError(w http.ResponseWriter, r *http.Request, err error) {
	s, _ := r.Context().Value(meterStateKey{}).(*meterState)
	if errors.Is(r.Context().Err(), context.Canceled) {
		w.WriteHeader(499)
		return
	}
	if s != nil && s.status == 0 {
		s.failed = true
	}
	status, code := http.StatusBadGateway, "upstream_unavailable"
	var netErr net.Error
	if errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &netErr) && netErr.Timeout()) {
		status, code = http.StatusGatewayTimeout, "upstream_timeout"
	}
	if m.Logger != nil {
		m.Logger.Warn("upstream call failed", "error", err.Error(), "host", r.Host)
	}
	writeJSON(w, status, map[string]string{"error": code, "message": "The API's function did not answer."})
}

// countingWriter counts the answer's body bytes; Unwrap lets ReverseProxy flush and hijack
// (WebSocket upgrades) the connection underneath.
type countingWriter struct {
	http.ResponseWriter
	n int64
}

func (c *countingWriter) Write(b []byte) (int, error) {
	n, err := c.ResponseWriter.Write(b)
	c.n += int64(n)
	return n, err
}

func (c *countingWriter) Unwrap() http.ResponseWriter { return c.ResponseWriter }

// countingTunnel is the connection to the function of an upgraded call: what the caller sends is
// written to it (bytes in), what the function sends is read from it (bytes out). ReverseProxy
// closes it when the tunnel ends; once the copies under way have returned, done gets the counts.
type countingTunnel struct {
	io.ReadWriteCloser
	in, out atomic.Int64
	active  atomic.Int64 // reads and writes under way
	once    sync.Once
	done    func(in, out int64)
}

func (c *countingTunnel) Read(b []byte) (int, error) {
	c.active.Add(1)
	defer c.active.Add(-1)
	n, err := c.ReadWriteCloser.Read(b)
	c.out.Add(int64(n))
	return n, err
}

func (c *countingTunnel) Write(b []byte) (int, error) {
	c.active.Add(1)
	defer c.active.Add(-1)
	n, err := c.ReadWriteCloser.Write(b)
	c.in.Add(int64(n))
	return n, err
}

func (c *countingTunnel) Close() error {
	err := c.ReadWriteCloser.Close()
	c.once.Do(func() {
		// a read or write blocked on the connection returns now that it is closed
		for deadline := time.Now().Add(2 * time.Second); c.active.Load() > 0 && time.Now().Before(deadline); {
			time.Sleep(5 * time.Millisecond)
		}
		if c.done != nil {
			c.done(c.in.Load(), c.out.Load())
		}
	})
	return err
}

// countingBody counts the request body as it is read.
type countingBody struct {
	io.ReadCloser
	n int64
}

func (c *countingBody) Read(b []byte) (int, error) {
	n, err := c.ReadCloser.Read(b)
	c.n += int64(n)
	return n, err
}
