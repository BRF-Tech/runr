package main

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCGIEnvelope(t *testing.T) {
	req := cgiEnvelope([]string{
		"GATEWAY_INTERFACE=CGI/1.1",
		"REQUEST_METHOD=POST",
		"PATH_INFO=/orders",
		"QUERY_STRING=name=RunR&x=1&x=2",
		"CONTENT_TYPE=application/json",
		"HTTP_X_REQUEST_ID=r-1",
		"HTTP_USER_AGENT=curl/8",
		"NOT_A_HEADER=x",
		"broken",
	}, []byte(`{"a":1}`))

	if req.Trigger != "http" || req.Method != "POST" || req.Path != "/orders" || req.ID != "r-1" {
		t.Fatalf("envelope %+v", req)
	}
	if req.Query["name"] != "RunR" || req.Query["x"] != "1" {
		t.Fatalf("query %v", req.Query)
	}
	if req.Headers["content-type"] != "application/json" || req.Headers["user-agent"] != "curl/8" || req.Headers["x-request-id"] != "r-1" {
		t.Fatalf("headers %v", req.Headers)
	}
	if _, ok := req.Headers["not-a-header"]; ok {
		t.Fatal("a non-HTTP_ variable became a header")
	}
	if req.Body != `{"a":1}` || req.Event != nil {
		t.Fatalf("body %q event %+v", req.Body, req.Event)
	}
}

func TestCGIEnvelopeOfCloudEvents(t *testing.T) {
	queue := cgiEnvelope([]string{
		"REQUEST_METHOD=POST", "HTTP_CE_SPECVERSION=1.0", "HTTP_CE_TYPE=dev.runr.queue.message",
		"HTTP_CE_ID=1728561234567-0", "HTTP_CE_SOURCE=/redisstream/orders/api",
	}, []byte(`{"msg":"hi"}`))
	if queue.Trigger != "queue" || queue.ID != "1728561234567-0" || queue.Event == nil || string(queue.Event.Data) != `{"msg":"hi"}` {
		t.Fatalf("queue envelope %+v", queue)
	}

	tick := cgiEnvelope([]string{"REQUEST_METHOD=POST", "HTTP_CE_SPECVERSION=1.0", "HTTP_CE_TYPE=dev.runr.schedule.tick"}, []byte(`{"trigger":"schedule"}`))
	if tick.Trigger != "schedule" || tick.Path != "/" {
		t.Fatalf("schedule envelope %+v", tick)
	}

	binary := cgiEnvelope(nil, []byte{0xff, 0xfe})
	if !binary.IsBase64Encoded || binary.Body != "//4=" || binary.Method != "GET" {
		t.Fatalf("binary body %+v", binary)
	}
}

func TestWriteCGI(t *testing.T) {
	var out bytes.Buffer
	err := writeCGI(&out, response{StatusCode: 201, Headers: map[string]string{"content-type": "text/html", "X-B": "2", "X-A": "1\nInjected: x"}, Body: "<b>ok</b>"})
	if err != nil {
		t.Fatal(err)
	}
	want := "Status: 201\nContent-Type: text/html\nX-A: 1 Injected: x\nX-B: 2\n\n<b>ok</b>"
	if out.String() != want {
		t.Fatalf("got %q\nwant %q", out.String(), want)
	}

	out.Reset()
	writeCGI(&out, response{StatusCode: 200, Body: "plain"})
	if out.String() != "Status: 200\nContent-Type: text/plain; charset=utf-8\n\nplain" {
		t.Fatalf("got %q", out.String())
	}
}

// The hello-world example answers the three triggers over CGI as it does in persistent mode.
func TestHelloWorldExampleOverCGI(t *testing.T) {
	src, err := os.ReadFile(filepath.Join("..", "main.q"))
	if err != nil {
		t.Fatal(err)
	}
	prog := mustParse(t, string(src))

	cases := []struct {
		env  []string
		body string
		want string
	}{
		{[]string{"REQUEST_METHOD=GET", "PATH_INFO=/", "QUERY_STRING=name=RunR"}, "", "Hello, RunR! (from Q)"},
		{[]string{"REQUEST_METHOD=POST", "HTTP_CE_SPECVERSION=1.0", "HTTP_CE_TYPE=dev.runr.schedule.tick"}, `{"trigger":"schedule"}`, "tick from Q"},
		{[]string{"REQUEST_METHOD=POST", "HTTP_CE_SPECVERSION=1.0", "HTTP_CE_TYPE=dev.runr.queue.message"}, `{"msg":"hi"}`, "processed hi (from Q)"},
	}
	for _, c := range cases {
		var out bytes.Buffer
		if err := writeCGI(&out, run(prog, cgiEnvelope(c.env, []byte(c.body)), &bytes.Buffer{})); err != nil {
			t.Fatal(err)
		}
		head, body, _ := strings.Cut(out.String(), "\n\n")
		if !strings.HasPrefix(head, "Status: 200\n") || body != c.want {
			t.Errorf("%v: got %q", c.env, out.String())
		}
	}
}
