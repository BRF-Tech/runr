package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
)

const (
	contentTypeText   = "text/plain; charset=utf-8"
	contentTypeJSON   = "application/json"
	contentTypeBinary = "application/octet-stream"
)

// Response is what the wrapper answers for one invocation.
type Response struct {
	Status int
	Header http.Header
	Body   []byte
}

// errorResponse is a wrapper-generated JSON error: {"error": msg}.
func errorResponse(status int, msg string) *Response {
	body, _ := json.Marshal(map[string]string{"error": msg})
	h := http.Header{}
	h.Set("Content-Type", contentTypeJSON)
	return &Response{Status: status, Header: h, Body: body}
}

// parseExecOutput turns the stdout of an exec-mode process into a response: a JSON object with a
// numeric statusCode is a response envelope (also when the process failed); otherwise a non-zero
// exit is a 500 and any other output is the raw body.
func parseExecOutput(stdout []byte, exitCode int) *Response {
	trimmed := bytes.TrimSpace(stdout)

	if len(trimmed) > 0 && trimmed[0] == '{' {
		if resp, ok := parseEnvelope(trimmed); ok {
			return resp
		}
	}

	if exitCode != 0 {
		return errorResponse(http.StatusInternalServerError, fmt.Sprintf("handler exited with status %d", exitCode))
	}

	h := http.Header{}
	if len(trimmed) > 0 && json.Valid(trimmed) {
		h.Set("Content-Type", contentTypeJSON)
	} else {
		h.Set("Content-Type", contentTypeText)
	}
	return &Response{Status: http.StatusOK, Header: h, Body: stdout}
}

// parseLine turns one JSON response line of a persistent worker into a response: an object with
// a numeric statusCode is an envelope, a string is a text body, any other value a JSON body.
func parseLine(line []byte) *Response {
	trimmed := bytes.TrimSpace(line)

	if len(trimmed) > 0 && trimmed[0] == '{' {
		if resp, ok := parseEnvelope(trimmed); ok {
			return resp
		}
	}

	h := http.Header{}
	var s string
	if err := json.Unmarshal(trimmed, &s); err == nil {
		h.Set("Content-Type", contentTypeText)
		return &Response{Status: http.StatusOK, Header: h, Body: []byte(s)}
	}

	h.Set("Content-Type", contentTypeJSON)
	return &Response{Status: http.StatusOK, Header: h, Body: trimmed}
}

// parseEnvelope reads a response envelope {statusCode, headers, body, isBase64Encoded}. ok is
// false when raw is not a JSON object with a numeric statusCode.
func parseEnvelope(raw []byte) (*Response, bool) {
	var obj map[string]json.RawMessage
	if err := json.Unmarshal(raw, &obj); err != nil {
		return nil, false
	}

	scRaw, ok := obj["statusCode"]
	if !ok {
		return nil, false
	}
	var sc float64
	if err := json.Unmarshal(scRaw, &sc); err != nil {
		return nil, false
	}

	status := int(sc)
	if status < 100 || status > 999 {
		logger.Printf("handler returned invalid statusCode %v, answering 500", sc)
		return errorResponse(http.StatusInternalServerError, fmt.Sprintf("handler returned invalid statusCode %v", sc)), true
	}

	resp := &Response{Status: status, Header: envelopeHeaders(obj["headers"])}

	var isBase64 bool
	if b, ok := obj["isBase64Encoded"]; ok {
		_ = json.Unmarshal(b, &isBase64)
	}

	bodyRaw := bytes.TrimSpace(obj["body"])
	switch {
	case len(bodyRaw) == 0 || string(bodyRaw) == "null":
		// no body
	case bodyRaw[0] == '"':
		var s string
		if err := json.Unmarshal(bodyRaw, &s); err != nil {
			return errorResponse(http.StatusInternalServerError, "handler returned an invalid body string"), true
		}
		if isBase64 {
			decoded, err := base64.StdEncoding.DecodeString(s)
			if err != nil {
				return errorResponse(http.StatusInternalServerError, "handler returned an invalid base64 body"), true
			}
			resp.Body = decoded
			setDefault(resp.Header, "Content-Type", contentTypeBinary)
		} else {
			resp.Body = []byte(s)
			setDefault(resp.Header, "Content-Type", contentTypeText)
		}
	default:
		var buf bytes.Buffer
		if err := json.Compact(&buf, bodyRaw); err != nil {
			buf.Reset()
			buf.Write(bodyRaw)
		}
		resp.Body = buf.Bytes()
		setDefault(resp.Header, "Content-Type", contentTypeJSON)
	}

	return resp, true
}

// envelopeHeaders reads the headers object of an envelope: string values as they are, arrays as
// repeated headers, other values JSON-encoded (numbers, booleans).
func envelopeHeaders(raw json.RawMessage) http.Header {
	h := http.Header{}
	if len(raw) == 0 {
		return h
	}

	var m map[string]any
	if err := json.Unmarshal(raw, &m); err != nil {
		return h
	}

	for k, v := range m {
		switch val := v.(type) {
		case nil:
		case string:
			h.Add(k, val)
		case []any:
			for _, item := range val {
				h.Add(k, headerValue(item))
			}
		default:
			h.Add(k, headerValue(val))
		}
	}
	return h
}

func headerValue(v any) string {
	switch val := v.(type) {
	case string:
		return val
	case float64:
		return strconv.FormatFloat(val, 'f', -1, 64)
	default:
		b, _ := json.Marshal(val)
		return string(b)
	}
}

func setDefault(h http.Header, key, value string) {
	if h.Get(key) == "" {
		h.Set(key, value)
	}
}

// writeResponse writes a response; Access-Control-Allow-Origin defaults to "*" (like the
// built-in runtimes).
func writeResponse(w http.ResponseWriter, resp *Response) {
	dst := w.Header()
	for k, vs := range resp.Header {
		dst[http.CanonicalHeaderKey(k)] = append([]string(nil), vs...)
	}
	setDefault(dst, "Access-Control-Allow-Origin", "*")

	w.WriteHeader(resp.Status)
	_, _ = w.Write(resp.Body)
}
