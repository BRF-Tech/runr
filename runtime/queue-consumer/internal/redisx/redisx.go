// Package redisx connects the gateway's Redis users — the redis-stream source, the redis-stream
// dead letter and the topic publisher — the way the queue consumer always has.
package redisx

import (
	"crypto/tls"
	"net"
	"strings"

	"github.com/redis/go-redis/v9"
)

// NewClient connects to address: host:port, redis://host:port or rediss://host:port.
// rediss:// is Redis over TLS: the server certificate is verified against the system roots for
// the address's host name. The password is password; userinfo in the URL is ignored (and never
// reaches Options().Addr, which is therefore safe to log).
func NewClient(address, password string) *redis.Client {
	addr := strings.TrimSpace(address)
	useTLS := strings.HasPrefix(addr, "rediss://")
	addr = strings.TrimPrefix(addr, "redis://")
	addr = strings.TrimPrefix(addr, "rediss://")

	// Strip userinfo (e.g. :password@host:port)
	if idx := strings.LastIndex(addr, "@"); idx != -1 {
		addr = addr[idx+1:]
	}

	opts := &redis.Options{
		Addr:     addr,
		Password: password,
	}
	if useTLS {
		host := addr
		if h, _, err := net.SplitHostPort(addr); err == nil {
			host = h
		}
		opts.TLSConfig = &tls.Config{MinVersion: tls.VersionTLS12, ServerName: host}
	}

	return redis.NewClient(opts)
}

// EntryAck names a stream entry of a consumer group: the redis-stream source hands it to the
// dead letter with the event (source.WithDeadLetterAck), so a dead letter on the same Redis
// writes the record and acknowledges the entry in one MULTI/EXEC — as the queue consumer always
// did — instead of an XADD and a separate XACK.
type EntryAck struct {
	Address string // the source's address (host:port, redis:// or rediss://)
	Stream  string
	Group   string
	ID      string
}

// SameServer reports whether two addresses name the same Redis: the same host:port (userinfo
// and the redis:// prefix ignored) over the same transport (rediss:// = TLS).
func SameServer(a, b string) bool {
	norm := func(addr string) (string, bool) {
		addr = strings.TrimSpace(addr)
		tls := strings.HasPrefix(addr, "rediss://")
		addr = strings.TrimPrefix(strings.TrimPrefix(addr, "redis://"), "rediss://")
		if idx := strings.LastIndex(addr, "@"); idx != -1 {
			addr = addr[idx+1:]
		}
		return strings.ToLower(strings.TrimSuffix(addr, "/")), tls
	}
	ha, ta := norm(a)
	hb, tb := norm(b)
	return ha != "" && ha == hb && ta == tb
}
