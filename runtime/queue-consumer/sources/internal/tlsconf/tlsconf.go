// Package tlsconf is the "tls" object the broker sources share:
//
//	{"enabled": true, "ca_pem": "-----BEGIN CERTIFICATE-----...", "insecure_skip_verify": false}
//
// ca_pem replaces the system roots when set; insecure_skip_verify turns certificate verification
// off (test brokers only). TLS 1.2 is the minimum version.
package tlsconf

import (
	"crypto/tls"
	"crypto/x509"
	"errors"
)

// Config is a source's "tls" object; null or {"enabled": false} means no TLS.
type Config struct {
	Enabled            bool   `json:"enabled"`
	CAPEM              string `json:"ca_pem"`
	InsecureSkipVerify bool   `json:"insecure_skip_verify"`
}

// Build returns the client TLS configuration, or nil when TLS is off. The library sets the
// server name from the address it dials unless serverName is given.
func (c *Config) Build(serverName string) (*tls.Config, error) {
	if c == nil || !c.Enabled {
		return nil, nil
	}
	tc := &tls.Config{
		MinVersion:         tls.VersionTLS12,
		ServerName:         serverName,
		InsecureSkipVerify: c.InsecureSkipVerify,
	}
	if c.CAPEM != "" {
		pool := x509.NewCertPool()
		if !pool.AppendCertsFromPEM([]byte(c.CAPEM)) {
			return nil, errors.New("tls.ca_pem holds no PEM certificate")
		}
		tc.RootCAs = pool
	}
	return tc, nil
}

// On reports whether c turns TLS on.
func (c *Config) On() bool { return c != nil && c.Enabled }
