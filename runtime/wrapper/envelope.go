package main

import (
	"bytes"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
	"unicode/utf8"
)

const (
	protocolVersion = 1

	// Schedule ticks are delivered as CloudEvents of this type and source.
	scheduleEventType   = "dev.runr.schedule.tick"
	scheduleEventSource = "/runr/schedule"
)

// Envelope is the request as the runtime command sees it: JSON on stdin (exec mode) or one line
// per invocation (persistent mode).
type Envelope struct {
	Runr            int               `json:"runr"`
	Trigger         string            `json:"trigger"`
	ID              string            `json:"id"`
	Method          string            `json:"method"`
	Path            string            `json:"path"`
	Query           map[string]string `json:"query"`
	RawQuery        string            `json:"rawQuery"`
	Headers         map[string]string `json:"headers"`
	Body            string            `json:"body"`
	IsBase64Encoded bool              `json:"isBase64Encoded"`
	Event           *CloudEvent       `json:"event,omitempty"`
}

// CloudEvent of a queue delivery or a schedule tick (CloudEvents 1.0 attributes).
type CloudEvent struct {
	SpecVersion     string `json:"specversion"`
	ID              string `json:"id"`
	Type            string `json:"type"`
	Source          string `json:"source"`
	Subject         string `json:"subject,omitempty"`
	Time            string `json:"time,omitempty"`
	DataContentType string `json:"datacontenttype,omitempty"`
	// Data is the parsed JSON payload (json.RawMessage), else the payload string; nil when empty.
	Data any `json:"data"`
}

// buildEnvelope builds the envelope of an HTTP request (http and queue triggers).
func buildEnvelope(r *http.Request, body []byte, trigger string) *Envelope {
	env := &Envelope{
		Runr:     protocolVersion,
		Trigger:  trigger,
		Method:   r.Method,
		Path:     r.URL.Path,
		Query:    firstValues(r.URL.Query()),
		RawQuery: r.URL.RawQuery,
		Headers:  flattenHeaders(r.Header),
	}
	if env.Path == "" {
		env.Path = "/"
	}
	if _, ok := env.Headers["host"]; !ok && r.Host != "" {
		env.Headers["host"] = r.Host
	}

	env.Body, env.IsBase64Encoded = encodeBody(body)
	env.Event = cloudEventFrom(r.Header, body)

	eventID := ""
	if env.Event != nil {
		eventID = env.Event.ID
	}
	env.ID = firstNonEmpty(r.Header.Get("X-Request-Id"), r.Header.Get("Ce-Id"), eventID, newID())

	return env
}

// scheduleEvent is one schedule tick as an HTTP request in CloudEvents binary mode: its id,
// headers (lower-case) and body.
func scheduleEvent(now time.Time) (string, map[string]string, []byte) {
	id := newID()
	data := []byte(`{"trigger":"schedule"}`)
	headers := map[string]string{
		"content-type":   "application/json",
		"ce-specversion": "1.0",
		"ce-id":          id,
		"ce-type":        scheduleEventType,
		"ce-source":      scheduleEventSource,
		"ce-time":        now.UTC().Format(time.RFC3339),
	}
	return id, headers, data
}

// scheduleEnvelope is the envelope of a schedule tick: the same request an http-mode runtime
// receives for it (POST / with the schedule CloudEvent).
func scheduleEnvelope(now time.Time) *Envelope {
	id, headers, data := scheduleEvent(now)

	return &Envelope{
		Runr:     protocolVersion,
		Trigger:  triggerSchedule,
		ID:       id,
		Method:   http.MethodPost,
		Path:     "/",
		Query:    map[string]string{},
		RawQuery: "",
		Headers:  headers,
		Body:     string(data),
		Event: &CloudEvent{
			SpecVersion:     "1.0",
			ID:              id,
			Type:            scheduleEventType,
			Source:          scheduleEventSource,
			Time:            headers["ce-time"],
			DataContentType: "application/json",
			Data:            json.RawMessage(data),
		},
	}
}

// cloudEventFrom reads a CloudEvent from a request: binary mode (ce-* headers, the body is the
// data) or structured mode (Content-Type application/cloudevents+json). nil when it is none.
func cloudEventFrom(h http.Header, body []byte) *CloudEvent {
	contentType := h.Get("Content-Type")

	if strings.HasPrefix(strings.ToLower(contentType), "application/cloudevents+json") {
		return structuredCloudEvent(body)
	}

	spec := h.Get("Ce-Specversion")
	if spec == "" {
		return nil
	}

	return &CloudEvent{
		SpecVersion:     spec,
		ID:              h.Get("Ce-Id"),
		Type:            h.Get("Ce-Type"),
		Source:          h.Get("Ce-Source"),
		Subject:         h.Get("Ce-Subject"),
		Time:            h.Get("Ce-Time"),
		DataContentType: contentType,
		Data:            eventData(body),
	}
}

func structuredCloudEvent(body []byte) *CloudEvent {
	var raw struct {
		SpecVersion     string          `json:"specversion"`
		ID              string          `json:"id"`
		Type            string          `json:"type"`
		Source          string          `json:"source"`
		Subject         string          `json:"subject"`
		Time            string          `json:"time"`
		DataContentType string          `json:"datacontenttype"`
		Data            json.RawMessage `json:"data"`
		DataBase64      string          `json:"data_base64"`
	}
	if err := json.Unmarshal(body, &raw); err != nil || raw.SpecVersion == "" {
		return nil
	}

	ev := &CloudEvent{
		SpecVersion:     raw.SpecVersion,
		ID:              raw.ID,
		Type:            raw.Type,
		Source:          raw.Source,
		Subject:         raw.Subject,
		Time:            raw.Time,
		DataContentType: raw.DataContentType,
	}
	switch {
	case len(raw.Data) > 0 && string(raw.Data) != "null":
		ev.Data = raw.Data
	case raw.DataBase64 != "":
		ev.Data = raw.DataBase64
	}
	return ev
}

// eventData is the CloudEvent data of a body: parsed JSON, else the string (base64 when it is
// not UTF-8); nil for an empty body.
func eventData(body []byte) any {
	trimmed := bytes.TrimSpace(body)
	if len(trimmed) == 0 {
		return nil
	}
	if json.Valid(trimmed) {
		return json.RawMessage(trimmed)
	}
	s, _ := encodeBody(body)
	return s
}

// invocationEnv is the per-invocation environment of an exec-mode process.
func invocationEnv(env *Envelope) []string {
	vars := []string{
		"RUNR_TRIGGER=" + env.Trigger,
		"RUNR_REQUEST_ID=" + env.ID,
		"RUNR_METHOD=" + env.Method,
		"RUNR_PATH=" + env.Path,
		"RUNR_QUERY=" + env.RawQuery,
		"RUNR_CONTENT_TYPE=" + env.Headers["content-type"],
	}

	names := make([]string, 0, len(env.Headers))
	for name := range env.Headers {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		vars = append(vars, "RUNR_HEADER_"+envName(name)+"="+env.Headers[name])
	}

	if env.Event != nil {
		vars = append(vars,
			"RUNR_EVENT_ID="+env.Event.ID,
			"RUNR_EVENT_TYPE="+env.Event.Type,
			"RUNR_EVENT_SOURCE="+env.Event.Source,
		)
	}

	return vars
}

// encodeEnvelope is the envelope as one JSON line (HTML characters unescaped), newline-terminated.
func encodeEnvelope(env *Envelope) []byte {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(env) // only marshalable fields
	return buf.Bytes()
}

// envName turns a header name into an env var suffix: upper-case, [^A-Z0-9] -> _.
func envName(header string) string {
	var b strings.Builder
	for _, r := range strings.ToUpper(header) {
		if (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		} else {
			b.WriteByte('_')
		}
	}
	return b.String()
}

// encodeBody returns the body as a string: as it is when it is UTF-8, base64 otherwise.
func encodeBody(body []byte) (string, bool) {
	if utf8.Valid(body) {
		return string(body), false
	}
	return base64.StdEncoding.EncodeToString(body), true
}

func firstValues(values url.Values) map[string]string {
	out := make(map[string]string, len(values))
	for k, vs := range values {
		if len(vs) > 0 {
			out[k] = vs[0]
		}
	}
	return out
}

func flattenHeaders(h http.Header) map[string]string {
	out := make(map[string]string, len(h))
	for k, vs := range h {
		out[strings.ToLower(k)] = strings.Join(vs, ", ")
	}
	return out
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}

// newID returns a random UUID (version 4).
func newID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return hex.EncodeToString([]byte(time.Now().Format(time.RFC3339Nano)))
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	h := hex.EncodeToString(b[:])
	return h[0:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:32]
}
