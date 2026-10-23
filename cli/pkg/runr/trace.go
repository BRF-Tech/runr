package runr

import (
	"encoding/json"
	"fmt"
	"net/http"
	"regexp"
	"sort"
	"strings"
	"time"
)

// redactedFields are JSON keys whose values never appear in the debug trace.
var redactedFields = map[string]bool{
	"password":              true,
	"password_confirmation": true,
	"token":                 true,
	"plain_token":           true,
	"two_factor_code":       true,
	"privateKey":            true,
	"private_key":           true,
	"secret_env_vars":       true,
	"kubeconfig":            true,
	"client_secret":         true,
	"access_key":            true,
	"secret_key":            true,
	"credentials":           true,
	// The values of an external binding (POST/PATCH /bindings, binding.values of an attach).
	"values": true,
	// The environment of a sandbox and of its commands (POST /sandboxes, …/exec, …/run).
	"env": true,
	// Alert channel secrets and the headers of uptime checks and request replays.
	"webhook_url": true,
	"bot_token":   true,
	"headers":     true,
	// Provider credentials of git deploy (POST /projects/{project}/git/repos, …/connect).
	"access_token":   true,
	"refresh_token":  true,
	"webhook_secret": true,
	// An external object store's credentials (POST …/data-services/object) and the OTLP export
	// headers of a region's tracing backend.
	"admin_token":       true,
	"access_key_id":     true,
	"secret_access_key": true,
	"export_headers":    true,
	// The Redis password and the registry credentials of a region (POST /knative-regions,
	// /registry-setup/start).
	"redis_password":    true,
	"registry_password": true,
	"external_password": true,
	// The write-only secrets of a trigger (a webhook's signing secret: POST / PATCH of
	// /projects/{project}/triggers).
	"secrets": true,
}

// secretBodyPaths are endpoints whose whole request and answer bodies stay out of the trace:
// they carry a secret under an ordinary field name (a secret variable's "value", binding
// credentials, the Hugging Face token, a workflow signing key).
var secretBodyPaths = []*regexp.Regexp{
	regexp.MustCompile(`/projects/[^/]+/secrets/[^/]+(/value)?$`),
	regexp.MustCompile(`/bindings/[^/]+/credentials$`),
	regexp.MustCompile(`/models/hub-token$`),
	regexp.MustCompile(`/workflows/app/signing-key$`),
	// A webhook channel's URL and signing secret (POST / PUT of alert channels).
	regexp.MustCompile(`/observability/alert-channels(/[^/]+)?$`),
	// The one-time session token of a sandbox terminal, of the exec WebSocket and of a
	// deployment's shell.
	regexp.MustCompile(`/sandboxes/[^/]+/terminal$`),
	regexp.MustCompile(`/sandboxes/[^/]+/exec/ws$`),
	regexp.MustCompile(`/projects/[^/]+/deployments/[^/]+/exec$`),
	// A region's kubeconfig (YAML, not JSON: field redaction does not reach it).
	regexp.MustCompile(`/knative-regions/[^/]+/kubeconfig$`),
}

// secretBody reports whether the bodies of path are withheld from the trace.
func secretBody(path string) bool {
	for _, re := range secretBodyPaths {
		if re.MatchString(path) {
			return true
		}
	}
	return false
}

const traceBodyLimit = 4096

func (c *Client) tracef(format string, args ...any) {
	if c.debug != nil {
		fmt.Fprintf(c.debug, format, args...)
	}
}

func (c *Client) traceRequest(req *http.Request, body []byte) {
	if c.debug == nil {
		return
	}
	c.tracef("> %s %s\n", req.Method, req.URL.String())
	traceHeaders(c, ">", req.Header)
	if len(body) > 0 {
		if secretBody(req.URL.Path) {
			c.tracef("> [redacted: %d bytes]\n", len(body))
			return
		}
		c.tracef("> %s\n", RedactJSON(body))
	}
}

func (c *Client) traceResponse(resp *http.Response, body []byte, took time.Duration) {
	if c.debug == nil {
		return
	}
	c.tracef("< %s (%s)\n", resp.Status, took.Round(time.Millisecond))
	traceHeaders(c, "<", resp.Header)
	if len(body) > 0 {
		if resp.Request != nil && resp.Request.URL != nil && secretBody(resp.Request.URL.Path) {
			c.tracef("< [redacted: %d bytes]\n", len(body))
			return
		}
		c.tracef("< %s\n", RedactJSON(body))
	}
}

func traceHeaders(c *Client, dir string, h http.Header) {
	keys := make([]string, 0, len(h))
	for k := range h {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		v := strings.Join(h[k], ", ")
		switch strings.ToLower(k) {
		case "authorization":
			v = redactAuth(v)
		case "cookie", "set-cookie":
			v = "[redacted]"
		}
		c.tracef("%s %s: %s\n", dir, k, v)
	}
}

func redactAuth(v string) string {
	if i := strings.IndexByte(v, ' '); i > 0 {
		return v[:i] + " [redacted]"
	}
	return "[redacted]"
}

// RedactJSON returns body with the values of secret fields replaced, truncated for the trace.
// Bodies that are not JSON are only truncated.
func RedactJSON(body []byte) string {
	var v any
	if err := json.Unmarshal(body, &v); err != nil {
		return truncate(string(body))
	}
	v = redactValue(v)
	out, err := json.Marshal(v)
	if err != nil {
		return truncate(string(body))
	}
	return truncate(string(out))
}

func redactValue(v any) any {
	switch t := v.(type) {
	case map[string]any:
		for k, val := range t {
			if redactedFields[k] {
				t[k] = "[redacted]"
				continue
			}
			t[k] = redactValue(val)
		}
		return t
	case []any:
		for i := range t {
			t[i] = redactValue(t[i])
		}
		return t
	}
	return v
}

func truncate(s string) string {
	if len(s) <= traceBodyLimit {
		return s
	}
	return s[:traceBodyLimit] + fmt.Sprintf("… (%d bytes)", len(s))
}
