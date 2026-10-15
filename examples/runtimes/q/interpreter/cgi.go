package main

// CGI mode (q -cgi script.q): one request from the CGI environment and stdin, the response as CGI
// on stdout — what Spin's WAGI executor speaks (examples/wasm/q-exec runs the interpreter compiled
// with TinyGo to WASI this way). The request becomes the same envelope persistent mode reads, so a
// script behaves the same on both: queue messages and schedule ticks are recognised by their
// CloudEvent headers (HTTP_CE_TYPE …).

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/url"
	"sort"
	"strings"
	"unicode/utf8"
)

// cgiEnvelope builds the request envelope of one CGI request: REQUEST_METHOD, PATH_INFO,
// QUERY_STRING, CONTENT_TYPE and the HTTP_<NAME> variables of environ, the body from stdin.
func cgiEnvelope(environ []string, body []byte) envelope {
	env := map[string]string{}
	headers := map[string]string{}
	for _, kv := range environ {
		k, v, ok := strings.Cut(kv, "=")
		if !ok {
			continue
		}
		env[k] = v
		if name, isHeader := strings.CutPrefix(k, "HTTP_"); isHeader {
			headers[strings.ToLower(strings.ReplaceAll(name, "_", "-"))] = v
		}
	}
	if ct := env["CONTENT_TYPE"]; ct != "" {
		headers["content-type"] = ct
	}

	req := envelope{
		Method:  env["REQUEST_METHOD"],
		Path:    env["PATH_INFO"],
		Query:   map[string]string{},
		Headers: headers,
	}
	if req.Method == "" {
		req.Method = "GET"
	}
	if req.Path == "" {
		req.Path = "/"
	}
	if values, err := url.ParseQuery(env["QUERY_STRING"]); err == nil {
		for k, v := range values {
			if len(v) > 0 {
				req.Query[k] = v[0]
			}
		}
	}

	if utf8.Valid(body) {
		req.Body = string(body)
	} else {
		req.Body = base64.StdEncoding.EncodeToString(body)
		req.IsBase64Encoded = true
	}

	switch headers["ce-type"] {
	case "dev.runr.queue.message":
		req.Trigger = "queue"
	case "dev.runr.schedule.tick":
		req.Trigger = "schedule"
	default:
		req.Trigger = "http"
	}
	if headers["ce-specversion"] != "" {
		ev := &cloudEvent{
			SpecVersion: headers["ce-specversion"],
			ID:          headers["ce-id"],
			Type:        headers["ce-type"],
			Source:      headers["ce-source"],
			Time:        headers["ce-time"],
		}
		if json.Valid(body) {
			ev.Data = json.RawMessage(body)
		}
		req.Event = ev
	}
	req.ID = headers["x-request-id"]
	if req.ID == "" {
		req.ID = headers["ce-id"]
	}
	return req
}

// writeCGI writes a response as CGI: Status, Content-Type (text/plain unless the script set one),
// the other headers, a blank line, the body.
func writeCGI(w io.Writer, resp response) error {
	contentType := "text/plain; charset=utf-8"
	var names []string
	for name, value := range resp.Headers {
		if strings.EqualFold(name, "Content-Type") {
			contentType = value
			continue
		}
		names = append(names, name)
	}
	sort.Strings(names)

	var b strings.Builder
	fmt.Fprintf(&b, "Status: %d\n", resp.StatusCode)
	fmt.Fprintf(&b, "Content-Type: %s\n", oneLine(contentType))
	for _, name := range names {
		if name = oneLine(name); name != "" && !strings.ContainsAny(name, ": ") {
			fmt.Fprintf(&b, "%s: %s\n", name, oneLine(resp.Headers[name]))
		}
	}
	b.WriteString("\n")
	b.WriteString(resp.Body)
	_, err := io.WriteString(w, b.String())
	return err
}

func oneLine(s string) string {
	return strings.TrimSpace(strings.NewReplacer("\r", " ", "\n", " ").Replace(s))
}
