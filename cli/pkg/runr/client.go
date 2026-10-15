// Package runr is the Go client for the RunR API. The runr CLI and the Terraform provider
// share it, so every endpoint either tool calls is declared once, in Contract (contract.go),
// and checked against the API's OpenAPI document by the contract test.
package runr

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime"
	"net"
	"net/http"
	"net/url"
	"runtime"
	"strconv"
	"strings"
	"time"
)

// DefaultUserAgent is sent when Options.UserAgent is empty.
var DefaultUserAgent = "runr-go/" + runtime.GOOS + "-" + runtime.GOARCH

// Options configure a Client.
type Options struct {
	// APIURL is the RunR console URL (https://runr.example.com) or its API root
	// (https://runr.example.com/api). The client appends /api when it is missing.
	APIURL string
	// Token is a RunR API token (Settings → API tokens, or `runr login`).
	Token string
	// HTTPClient overrides the HTTP client (timeouts, proxies, tests).
	HTTPClient *http.Client
	// UserAgent identifies the caller, e.g. "runr-cli/0.5.0".
	UserAgent string
	// Debug receives an HTTP trace of every request and response, with the token and
	// secret fields redacted. Nil disables the trace.
	Debug io.Writer
	// Retries is how often an idempotent request is retried after a network error or a
	// 502/503/504/429 answer. Zero means the default (3); a negative value disables retries.
	Retries int
}

// Client talks to one RunR API.
type Client struct {
	base      *url.URL
	token     string
	http      *http.Client
	userAgent string
	debug     io.Writer
	retries   int
	// sleep is replaced in tests to make retries instant.
	sleep func(time.Duration)
}

// New returns a client for the API at opts.APIURL.
func New(opts Options) (*Client, error) {
	base, err := NormalizeAPIURL(opts.APIURL)
	if err != nil {
		return nil, err
	}
	hc := opts.HTTPClient
	if hc == nil {
		hc = &http.Client{Timeout: 60 * time.Second}
	}
	ua := opts.UserAgent
	if ua == "" {
		ua = DefaultUserAgent
	}
	retries := opts.Retries
	if retries == 0 {
		retries = 3
	} else if retries < 0 {
		retries = 0
	}
	return &Client{
		base:      base,
		token:     opts.Token,
		http:      hc,
		userAgent: ua,
		debug:     opts.Debug,
		retries:   retries,
		sleep:     time.Sleep,
	}, nil
}

// NormalizeAPIURL parses a console or API URL and returns the API root (…/api, no trailing slash).
func NormalizeAPIURL(raw string) (*url.URL, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, errors.New("the RunR API URL is empty")
	}
	if !strings.Contains(raw, "://") {
		raw = "https://" + raw
	}
	u, err := url.Parse(raw)
	if err != nil {
		return nil, fmt.Errorf("invalid RunR API URL %q: %w", raw, err)
	}
	if u.Scheme != "http" && u.Scheme != "https" {
		return nil, fmt.Errorf("invalid RunR API URL %q: scheme must be http or https", raw)
	}
	if u.Host == "" {
		return nil, fmt.Errorf("invalid RunR API URL %q: host is empty", raw)
	}
	u.Path = strings.TrimRight(u.Path, "/")
	if !strings.HasSuffix(u.Path, "/api") {
		u.Path += "/api"
	}
	u.RawQuery = ""
	u.Fragment = ""
	return u, nil
}

// BaseURL returns the API root the client sends requests to.
func (c *Client) BaseURL() string { return c.base.String() }

// ConsoleURL returns the console URL (the API root without /api).
func (c *Client) ConsoleURL() string {
	u := *c.base
	u.Path = strings.TrimSuffix(u.Path, "/api")
	return u.String()
}

// WithToken returns a copy of the client that authenticates with token.
func (c *Client) WithToken(token string) *Client {
	cp := *c
	cp.token = token
	return &cp
}

// APIError is a non-2xx answer of the API.
type APIError struct {
	StatusCode int
	Method     string
	Path       string
	Message    string
	// Errors holds Laravel validation errors (422): field → messages.
	Errors map[string][]string
	// Permissions are the permissions a 403 answer names: the token or the user needs one
	// of them (role-based access control).
	Permissions []string
	Body        string
}

func (e *APIError) Error() string {
	msg := e.Message
	if msg == "" {
		msg = http.StatusText(e.StatusCode)
	}
	var b strings.Builder
	fmt.Fprintf(&b, "%s %s: %d %s", e.Method, e.Path, e.StatusCode, msg)
	if len(e.Errors) > 0 {
		keys := make([]string, 0, len(e.Errors))
		for k := range e.Errors {
			keys = append(keys, k)
		}
		sortStrings(keys)
		for _, k := range keys {
			for _, m := range e.Errors[k] {
				fmt.Fprintf(&b, "\n  %s: %s", k, m)
			}
		}
	}
	return b.String()
}

// IsNotFound reports whether err is a 404 answer.
func IsNotFound(err error) bool { return statusIs(err, http.StatusNotFound) }

// IsUnauthorized reports whether err is a 401 or 403 answer.
func IsUnauthorized(err error) bool {
	return statusIs(err, http.StatusUnauthorized) || statusIs(err, http.StatusForbidden)
}

// IsValidation reports whether err is a 422 answer.
func IsValidation(err error) bool { return statusIs(err, http.StatusUnprocessableEntity) }

func statusIs(err error, code int) bool {
	var ae *APIError
	return errors.As(err, &ae) && ae.StatusCode == code
}

// request describes one API call.
type request struct {
	method string
	path   string
	query  url.Values
	body   any
	// raw is sent as the body instead of JSON (binary uploads), with contentType.
	raw         []byte
	contentType string
	// accept overrides the Accept header (downloads of SBOMs and exports).
	accept string
	// retry marks a request that is safe to repeat although it is not a GET (an upload chunk
	// written at a fixed offset).
	retry bool
	// noAuth sends the request without the Authorization header (login).
	noAuth bool
}

// do sends the request and decodes a JSON answer into out (nil = discard).
func (c *Client) do(ctx context.Context, r request, out any) error {
	var payload []byte
	if r.raw != nil {
		payload = r.raw
	} else if r.body != nil {
		var err error
		payload, err = json.Marshal(r.body)
		if err != nil {
			return fmt.Errorf("encode request body: %w", err)
		}
	}

	u := *c.base
	u.Path = c.base.Path + r.path
	u.RawPath = ""
	if strings.Contains(r.path, "%") {
		// The path holds escaped segments (seg): keep their encoding.
		if p, err := url.PathUnescape(r.path); err == nil {
			u.Path = c.base.Path + p
			u.RawPath = c.base.EscapedPath() + r.path
		}
	}
	if len(r.query) > 0 {
		u.RawQuery = r.query.Encode()
	}

	idempotent := r.method == http.MethodGet || r.method == http.MethodHead || r.retry
	attempts := 1
	if idempotent {
		attempts += c.retries
	}

	var lastErr error
	for attempt := 1; attempt <= attempts; attempt++ {
		var body io.Reader
		if payload != nil {
			body = bytes.NewReader(payload)
		}
		req, err := http.NewRequestWithContext(ctx, r.method, u.String(), body)
		if err != nil {
			return err
		}
		accept := "application/json"
		if r.accept != "" {
			accept = r.accept
		}
		req.Header.Set("Accept", accept)
		req.Header.Set("User-Agent", c.userAgent)
		if payload != nil {
			ct := "application/json"
			if r.raw != nil {
				ct = r.contentType
				if ct == "" {
					ct = "application/octet-stream"
				}
			}
			req.Header.Set("Content-Type", ct)
		}
		if c.token != "" && !r.noAuth {
			req.Header.Set("Authorization", "Bearer "+c.token)
		}

		if r.raw != nil {
			c.traceRequest(req, []byte(fmt.Sprintf("(%d bytes of %s)", len(payload), req.Header.Get("Content-Type"))))
		} else {
			c.traceRequest(req, payload)
		}
		start := time.Now()
		resp, err := c.http.Do(req)
		if err != nil {
			c.tracef("< error after %s: %v\n", time.Since(start).Round(time.Millisecond), err)
			lastErr = &NetworkError{Method: r.method, URL: u.String(), Err: err}
			if ctx.Err() != nil {
				return lastErr
			}
			if attempt < attempts && retryableNetErr(err) {
				c.sleep(backoff(attempt, ""))
				continue
			}
			return lastErr
		}
		if w, ok := out.(*Download); ok && resp.StatusCode >= 200 && resp.StatusCode < 300 {
			// Stream the answer (SBOMs, exports) instead of holding it in memory.
			n, err := io.Copy(w.W, resp.Body)
			resp.Body.Close()
			c.traceResponse(resp, []byte(fmt.Sprintf("(%d bytes streamed)", n)), time.Since(start))
			w.ContentType = resp.Header.Get("Content-Type")
			w.Filename = attachmentName(resp.Header.Get("Content-Disposition"))
			w.Bytes = n
			if err != nil {
				return fmt.Errorf("read %s %s: %w", r.method, r.path, err)
			}
			return nil
		}
		data, readErr := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
		resp.Body.Close()
		c.traceResponse(resp, data, time.Since(start))
		if readErr != nil {
			return fmt.Errorf("read %s %s: %w", r.method, r.path, readErr)
		}

		if resp.StatusCode >= 200 && resp.StatusCode < 300 {
			if out == nil || len(bytes.TrimSpace(data)) == 0 {
				return nil
			}
			if raw, ok := out.(*[]byte); ok {
				*raw = data
				return nil
			}
			if err := json.Unmarshal(data, out); err != nil {
				return fmt.Errorf("decode %s %s: %w", r.method, r.path, err)
			}
			return nil
		}

		apiErr := parseAPIError(resp.StatusCode, r.method, r.path, data)
		if attempt < attempts && retryableStatus(resp.StatusCode) {
			lastErr = apiErr
			c.sleep(backoff(attempt, resp.Header.Get("Retry-After")))
			continue
		}
		return apiErr
	}
	return lastErr
}

// seg escapes one path segment the caller typed (a secret name, a Hugging Face model id).
func seg(s string) string { return url.PathEscape(s) }

// Download receives a streamed answer (an SBOM, an audit export): pass it as the output of a
// request and the body is copied to W; the other fields describe what arrived.
type Download struct {
	W           io.Writer
	ContentType string
	// Filename is the name of the Content-Disposition header, if any.
	Filename string
	Bytes    int64
}

// attachmentName returns the filename of a Content-Disposition header.
func attachmentName(cd string) string {
	if cd == "" {
		return ""
	}
	_, params, err := mime.ParseMediaType(cd)
	if err != nil {
		return ""
	}
	name := params["filename"]
	if i := strings.LastIndexAny(name, `/\`); i >= 0 {
		name = name[i+1:]
	}
	return name
}

// NetworkError means the API could not be reached.
type NetworkError struct {
	Method string
	URL    string
	Err    error
}

func (e *NetworkError) Error() string {
	return fmt.Sprintf("%s %s: %v", e.Method, e.URL, e.Err)
}

func (e *NetworkError) Unwrap() error { return e.Err }

func retryableNetErr(err error) bool {
	var ne net.Error
	if errors.As(err, &ne) {
		return true
	}
	var oe *net.OpError
	return errors.As(err, &oe)
}

func retryableStatus(code int) bool {
	switch code {
	case http.StatusTooManyRequests, http.StatusBadGateway, http.StatusServiceUnavailable, http.StatusGatewayTimeout:
		return true
	}
	return false
}

func backoff(attempt int, retryAfter string) time.Duration {
	if s, err := strconv.Atoi(strings.TrimSpace(retryAfter)); err == nil && s >= 0 && s <= 60 {
		return time.Duration(s) * time.Second
	}
	d := time.Duration(attempt*attempt) * 500 * time.Millisecond
	if d > 10*time.Second {
		d = 10 * time.Second
	}
	return d
}

func parseAPIError(code int, method, path string, data []byte) *APIError {
	e := &APIError{StatusCode: code, Method: method, Path: path}
	var body struct {
		Message     string          `json:"message"`
		Error       string          `json:"error"`
		Errors      json.RawMessage `json:"errors"`
		Permissions []string        `json:"permissions"`
	}
	if json.Unmarshal(data, &body) == nil {
		e.Message = body.Message
		e.Permissions = body.Permissions
		if e.Message == "" {
			e.Message = body.Error
		}
		if len(body.Errors) > 0 {
			var errs map[string][]string
			if json.Unmarshal(body.Errors, &errs) == nil {
				e.Errors = errs
			}
		}
	} else {
		text := strings.TrimSpace(string(data))
		if len(text) > 300 {
			text = text[:300] + "…"
		}
		e.Body = text
		if !strings.HasPrefix(text, "<") {
			e.Message = text
		}
	}
	return e
}

func sortStrings(s []string) {
	for i := 1; i < len(s); i++ {
		for j := i; j > 0 && s[j] < s[j-1]; j-- {
			s[j], s[j-1] = s[j-1], s[j]
		}
	}
}
