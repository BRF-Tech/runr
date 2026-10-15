package main

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func mustParse(t *testing.T, src string) *program {
	t.Helper()
	prog, err := parse(strings.NewReader(src))
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	return prog
}

func runScript(t *testing.T, src string, req envelope) (response, string) {
	t.Helper()
	var log bytes.Buffer
	return run(mustParse(t, src), req, &log), log.String()
}

func httpReq(query map[string]string) envelope {
	return envelope{Trigger: "http", ID: "r1", Method: "GET", Path: "/hello", Query: query, Headers: map[string]string{}}
}

func TestHelloWorldExample(t *testing.T) {
	src, err := os.ReadFile(filepath.Join("..", "main.q"))
	if err != nil {
		t.Fatal(err)
	}
	if lines := strings.Count(strings.TrimRight(string(src), "\n"), "\n") + 1; lines != 5 {
		t.Fatalf("main.q has %d lines, want 5", lines)
	}
	prog := mustParse(t, string(src))

	cases := []struct {
		name string
		req  envelope
		want string
	}{
		{"http with name", httpReq(map[string]string{"name": "RunR"}), "Hello, RunR! (from Q)"},
		{"http default", httpReq(nil), "Hello, World! (from Q)"},
		{"schedule", envelope{Trigger: "schedule", Event: &cloudEvent{Type: "dev.runr.schedule.tick", Data: json.RawMessage(`{"trigger":"schedule"}`)}}, "tick from Q"},
		{"queue", envelope{Trigger: "queue", Body: `{"msg":"hi"}`, Event: &cloudEvent{Type: "dev.runr.queue.message", Data: json.RawMessage(`{"msg":"hi"}`)}}, "processed hi (from Q)"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			resp := run(prog, c.req, &bytes.Buffer{})
			if resp.StatusCode != 200 || resp.Body != c.want {
				t.Fatalf("got %d %q, want 200 %q", resp.StatusCode, resp.Body, c.want)
			}
		})
	}
}

func TestStatementsAndHeaders(t *testing.T) {
	resp, log := runScript(t, `
# a comment line
set greeting = "Hi"   # trailing comment
header Content-Type = "text/html"
status 201
log "handled {method} {path}"
print "<b>{greeting}</b>"
print "\n"
`, httpReq(nil))
	if resp.StatusCode != 201 || resp.Body != "<b>Hi</b>\n" || resp.Headers["Content-Type"] != "text/html" {
		t.Fatalf("unexpected response %+v", resp)
	}
	if log != "handled GET /hello\n" {
		t.Fatalf("log = %q", log)
	}
}

func TestIfElseAndReturn(t *testing.T) {
	src := `
if query.n == 1 then print "one" else print "other"
if not query.n then return "none"
return
print "unreachable"
`
	for query, want := range map[string]string{"1": "one", "1.0": "one", "2": "other", "": "othernone"} {
		q := map[string]string{}
		if query != "" {
			q["n"] = query
		}
		resp, _ := runScript(t, src, httpReq(q))
		if resp.Body != want {
			t.Errorf("n=%q: body %q, want %q", query, resp.Body, want)
		}
	}
}

func TestExpressions(t *testing.T) {
	cases := map[string]string{
		`print 1 + 2`:                             "3",
		`print 1.5 + 2`:                           "3.5",
		`print "a" + 1`:                           "a1",
		`print "x" + "y" + "z"`:                   "xyz",
		`print query.absent or "dflt"`:            "dflt",
		`print query.a or "dflt"`:                 "A",
		`print query.a and "both"`:                "both",
		`print query.absent and "both"`:           "",
		`print false or "f"`:                      "f",
		`print not true`:                          "false",
		`print "a" == "a"`:                        "true",
		`print "a" != "a"`:                        "false",
		`print (1 + 1) == 2`:                      "true",
		`print "brace \{x\} quote \" \\"`:         `brace {x} quote " \`,
		`print header.X-Request-Id`:               "abc",
		`print "{query.a}-{header.x-request-id}"`: "A-abc",
	}
	req := httpReq(map[string]string{"a": "A"})
	req.Headers = map[string]string{"x-request-id": "abc"}
	for src, want := range cases {
		resp, _ := runScript(t, src, req)
		if resp.StatusCode != 200 || resp.Body != want {
			t.Errorf("%s: got %d %q, want %q", src, resp.StatusCode, resp.Body, want)
		}
	}
}

func TestDataAccessors(t *testing.T) {
	req := envelope{Trigger: "http", Method: "POST", Body: `{"user":{"name":"Ada","tags":["x","y"],"age":36,"admin":true}}`}
	cases := map[string]string{
		`print data.user.name`:   "Ada",
		`print data.user.tags.1`: "y",
		`print data.user.tags`:   `["x","y"]`,
		`print data.user.age`:    "36",
		`print data.user.admin`:  "true",
		`print data.user.none`:   "",
		`print data.user.tags.9`: "",
		`print body`:             req.Body,
	}
	for src, want := range cases {
		if resp, _ := runScript(t, src, req); resp.Body != want {
			t.Errorf("%s: got %q, want %q", src, resp.Body, want)
		}
	}

	// base64 bodies are decoded; event data wins over the body
	b64 := envelope{Body: "eyJtc2ciOiJiNjQifQ==", IsBase64Encoded: true}
	if resp, _ := runScript(t, `print data.msg + " " + body`, b64); resp.Body != `b64 {"msg":"b64"}` {
		t.Errorf("base64 body: %q", resp.Body)
	}
	ev := envelope{Trigger: "queue", Body: `{"msg":"body"}`, Event: &cloudEvent{ID: "1-0", Type: "dev.runr.queue.message", Source: "/redisstream/s/g", Time: "t", SpecVersion: "1.0", Data: json.RawMessage(`{"msg":"event"}`)}}
	if resp, _ := runScript(t, `print "{data.msg} {event.type} {event.id} {event.source} {event.specversion} {trigger}"`, ev); resp.Body != "event dev.runr.queue.message 1-0 /redisstream/s/g 1.0 queue" {
		t.Errorf("event accessors: %q", resp.Body)
	}
	if resp, _ := runScript(t, `print event.type + "|" + data`, httpReq(nil)); resp.Body != "|" {
		t.Errorf("no event: %q", resp.Body)
	}
}

func TestRuntimeErrors(t *testing.T) {
	for src, wantMsg := range map[string]string{
		`print nope`:         `line 1: unknown variable \"nope\"`,
		`status "teapot"`:    `line 1: status \"teapot\" is not an HTTP status code`,
		"print 1\nstatus 99": `line 2: status \"99\" is not an HTTP status code`,
	} {
		resp, log := runScript(t, src, httpReq(nil))
		if resp.StatusCode != 500 || !strings.Contains(resp.Body, wantMsg) || resp.Headers["Content-Type"] != "application/json" {
			t.Errorf("%q: got %d %s", src, resp.StatusCode, resp.Body)
		}
		if !strings.HasPrefix(log, "q: line") {
			t.Errorf("%q: error not logged: %q", src, log)
		}
	}
}

func TestParseErrors(t *testing.T) {
	cases := map[string]string{
		`shout "x"`:             `line 1: unknown statement "shout"`,
		"print 1\nprint \"open": "line 2: unterminated string",
		`set query = 1`:         `"query" can not be a variable name`,
		`set a.b = 1`:           `"a.b" can not be a variable name`,
		`set if = 1`:            "expected a variable name",
		`if 1 print "x"`:        `expected "then"`,
		`print`:                 "expected a value, found end of line",
		`print 1 2`:             "unexpected number 2",
		`print query`:           `"query" needs a name`,
		`print method.x`:        `"method" has no fields`,
		`print foo.bar`:         `unknown accessor "foo"`,
		`print "{}"`:            "invalid reference",
		`print "{a b}"`:         "invalid reference",
		`print "\q"`:            `unknown escape`,
		`print 1 ; 2`:           "unexpected character ';'",
		`print (1 + 2`:          `expected ")"`,
		`print 1.2.3`:           "invalid number 1.2.3",
		`header X.Y = "1"`:      "invalid header name",
		`print "{x"`:            "unclosed {",
		`print a-b`:             `invalid variable name "a-b"`,
		`= 1`:                   "expected a statement",
	}
	for src, want := range cases {
		_, err := parse(strings.NewReader(src))
		if err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%q: error %v, want it to contain %q", src, err, want)
		}
	}
}

func TestServeAnswersEveryLine(t *testing.T) {
	prog := mustParse(t, `print "hello " + (query.name or "?")`)
	in := strings.Join([]string{
		`{"runr":1,"trigger":"http","query":{"name":"a"}}`,
		``,
		`not json`,
		`{"runr":1,"trigger":"http","query":{"name":"b"}}`,
	}, "\n") // last line without a trailing newline
	var out, log bytes.Buffer
	if err := serve(prog, strings.NewReader(in), &out, &log); err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimRight(out.String(), "\n"), "\n")
	if len(lines) != 3 {
		t.Fatalf("got %d response lines: %q", len(lines), out.String())
	}
	want := []response{{StatusCode: 200, Body: "hello a"}, {StatusCode: 400, Body: `{"error":"invalid request envelope"}`}, {StatusCode: 200, Body: "hello b"}}
	for i, line := range lines {
		var got response
		if err := json.Unmarshal([]byte(line), &got); err != nil {
			t.Fatalf("line %d is not JSON: %q", i, line)
		}
		if got.StatusCode != want[i].StatusCode || got.Body != want[i].Body {
			t.Errorf("line %d: got %+v, want %+v", i, got, want[i])
		}
	}
	if !strings.Contains(log.String(), "invalid request envelope") {
		t.Errorf("bad envelope not logged: %q", log.String())
	}
}

func TestLoadReportsFileAndLine(t *testing.T) {
	path := filepath.Join(t.TempDir(), "bad.q")
	if err := os.WriteFile(path, []byte("print 1\nfrobnicate\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := load(path); err == nil || !strings.Contains(err.Error(), "bad.q: line 2: unknown statement") {
		t.Fatalf("load error = %v", err)
	}
	if _, err := load(filepath.Join(t.TempDir(), "absent.q")); err == nil {
		t.Fatal("load of an absent file succeeded")
	}
}

func TestWriteResponseIsOneJSONLine(t *testing.T) {
	var out bytes.Buffer
	if err := writeResponse(&out, response{StatusCode: 200, Body: "multi\nline"}); err != nil {
		t.Fatal(err)
	}
	if strings.Count(out.String(), "\n") != 1 || !strings.HasSuffix(out.String(), "\n") {
		t.Fatalf("not a single line: %q", out.String())
	}
	if !strings.HasPrefix(out.String(), `{"statusCode":200,"body":`) {
		t.Fatalf("unexpected shape: %s", out.String())
	}
}
