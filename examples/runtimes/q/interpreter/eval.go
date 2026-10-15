package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"strconv"
	"strings"
)

// envelope is RunR's request envelope (runtime contract v1).
type envelope struct {
	Trigger         string            `json:"trigger"`
	ID              string            `json:"id"`
	Method          string            `json:"method"`
	Path            string            `json:"path"`
	Query           map[string]string `json:"query"`
	Headers         map[string]string `json:"headers"`
	Body            string            `json:"body"`
	IsBase64Encoded bool              `json:"isBase64Encoded"`
	Event           *cloudEvent       `json:"event"`
}

type cloudEvent struct {
	SpecVersion string          `json:"specversion"`
	ID          string          `json:"id"`
	Type        string          `json:"type"`
	Source      string          `json:"source"`
	Time        string          `json:"time"`
	Data        json.RawMessage `json:"data"`
}

// response is the reply RunR's wrapper reads back (an object with a numeric statusCode).
type response struct {
	StatusCode int               `json:"statusCode"`
	Headers    map[string]string `json:"headers,omitempty"`
	Body       string            `json:"body"`
}

// Values are strings; numbers and booleans are strings that look like them.
type expr interface {
	eval(*state) (string, error)
}

type litExpr struct{ v string }
type strExpr struct{ parts []strPart }
type refExpr struct{ path []string }
type notExpr struct{ x expr }
type binExpr struct {
	op   string
	l, r expr
}

type state struct {
	req     envelope
	body    string // request body, base64-decoded when needed
	data    any    // event data, else the JSON request body (nil when neither)
	vars    map[string]string
	out     strings.Builder
	status  int
	headers map[string]string
	log     io.Writer
	done    bool
}

func truthy(v string) bool { return v != "" && v != "false" }

func boolString(b bool) string {
	if b {
		return "true"
	}
	return "false"
}

func number(v string) (float64, bool) {
	if v == "" {
		return 0, false
	}
	f, err := strconv.ParseFloat(v, 64)
	return f, err == nil
}

func (e litExpr) eval(*state) (string, error) { return e.v, nil }

func (e strExpr) eval(s *state) (string, error) {
	var b strings.Builder
	for _, part := range e.parts {
		if part.ref == nil {
			b.WriteString(part.text)
			continue
		}
		v, err := s.lookup(part.ref)
		if err != nil {
			return "", err
		}
		b.WriteString(v)
	}
	return b.String(), nil
}

func (e refExpr) eval(s *state) (string, error) { return s.lookup(e.path) }

func (e notExpr) eval(s *state) (string, error) {
	v, err := e.x.eval(s)
	return boolString(!truthy(v)), err
}

func (e binExpr) eval(s *state) (string, error) {
	l, err := e.l.eval(s)
	if err != nil {
		return "", err
	}
	switch e.op {
	case "or":
		if truthy(l) {
			return l, nil
		}
		return e.r.eval(s)
	case "and":
		if !truthy(l) {
			return l, nil
		}
		return e.r.eval(s)
	}
	r, err := e.r.eval(s)
	if err != nil {
		return "", err
	}
	ln, lok := number(l)
	rn, rok := number(r)
	switch e.op {
	case "+":
		if lok && rok {
			return strconv.FormatFloat(ln+rn, 'f', -1, 64), nil
		}
		return l + r, nil
	case "==":
		if lok && rok {
			return boolString(ln == rn), nil
		}
		return boolString(l == r), nil
	default: // "!="
		if lok && rok {
			return boolString(ln != rn), nil
		}
		return boolString(l != r), nil
	}
}

// lookup resolves a variable or a request accessor.
func (s *state) lookup(path []string) (string, error) {
	switch path[0] {
	case "method":
		return s.req.Method, nil
	case "path":
		return s.req.Path, nil
	case "body":
		return s.body, nil
	case "trigger":
		return s.req.Trigger, nil
	case "id":
		return s.req.ID, nil
	case "query":
		return s.req.Query[path[1]], nil
	case "header":
		return s.req.Headers[strings.ToLower(path[1])], nil
	case "event":
		return s.eventField(path[1]), nil
	case "data":
		return dataValue(s.data, path[1:]), nil
	}
	v, ok := s.vars[path[0]]
	if !ok {
		return "", fmt.Errorf("unknown variable %q", path[0])
	}
	return v, nil
}

func (s *state) eventField(field string) string {
	ev := s.req.Event
	if ev == nil {
		return ""
	}
	switch field {
	case "type":
		return ev.Type
	case "id":
		return ev.ID
	case "source":
		return ev.Source
	case "time":
		return ev.Time
	case "specversion":
		return ev.SpecVersion
	}
	return ""
}

// dataValue walks objects by key and arrays by index; leaves become strings, nested values JSON.
func dataValue(v any, path []string) string {
	for _, key := range path {
		switch node := v.(type) {
		case map[string]any:
			v = node[key]
		case []any:
			i, err := strconv.Atoi(key)
			if err != nil || i < 0 || i >= len(node) {
				return ""
			}
			v = node[i]
		default:
			return ""
		}
	}
	switch leaf := v.(type) {
	case nil:
		return ""
	case string:
		return leaf
	case json.Number:
		return leaf.String()
	case bool:
		return boolString(leaf)
	default:
		b, _ := json.Marshal(leaf)
		return string(b)
	}
}

func decodeJSON(b []byte) (any, bool) {
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.UseNumber()
	var v any
	if err := dec.Decode(&v); err != nil || dec.More() {
		return nil, false
	}
	return v, true
}

func newState(req envelope, log io.Writer) *state {
	s := &state{req: req, body: req.Body, vars: map[string]string{}, status: 200, headers: map[string]string{}, log: log}
	if req.IsBase64Encoded {
		if b, err := base64.StdEncoding.DecodeString(req.Body); err == nil {
			s.body = string(b)
		}
	}
	if req.Event != nil && len(req.Event.Data) > 0 {
		s.data, _ = decodeJSON(req.Event.Data)
	} else if v, ok := decodeJSON([]byte(s.body)); ok {
		s.data = v
	}
	return s
}

func (s *state) exec(st *stmt) error {
	switch st.kind {
	case stmtIf:
		c, err := st.expr.eval(s)
		if err != nil {
			return err
		}
		if truthy(c) {
			return s.exec(st.then)
		}
		if st.els != nil {
			return s.exec(st.els)
		}
		return nil
	case stmtReturn:
		s.done = true
		if st.expr == nil {
			return nil
		}
	}

	v, err := st.expr.eval(s)
	if err != nil {
		return err
	}
	switch st.kind {
	case stmtSet:
		s.vars[st.name] = v
	case stmtPrint, stmtReturn:
		s.out.WriteString(v)
	case stmtHeader:
		s.headers[st.name] = v
	case stmtLog:
		fmt.Fprintln(s.log, v)
	case stmtStatus:
		code, err := strconv.Atoi(v)
		if err != nil || code < 100 || code > 599 {
			return fmt.Errorf("status %q is not an HTTP status code", v)
		}
		s.status = code
	}
	return nil
}

// run executes the program for one request. A runtime error becomes a 500 response.
func run(prog *program, req envelope, log io.Writer) response {
	s := newState(req, log)
	for _, st := range prog.stmts {
		if err := s.exec(st); err != nil {
			msg := fmt.Sprintf("line %d: %v", st.line, err)
			fmt.Fprintln(log, "q:", msg)
			body, _ := json.Marshal(map[string]string{"error": msg})
			return response{StatusCode: 500, Headers: map[string]string{"Content-Type": "application/json"}, Body: string(body)}
		}
		if s.done {
			break
		}
	}
	resp := response{StatusCode: s.status, Body: s.out.String()}
	if len(s.headers) > 0 {
		resp.Headers = s.headers
	}
	return resp
}
