package main

import (
	"net/http/httptest"
	"testing"
)

func TestParseEnvelopeStringBody(t *testing.T) {
	resp := parseExecOutput([]byte(`{"statusCode": 201, "headers": {"X-A": "b", "X-N": 5, "Set-Cookie": ["a=1", "b=2"]}, "body": "made"}`+"\n"), 0)

	if resp.Status != 201 || string(resp.Body) != "made" {
		t.Fatalf("resp = %d %q", resp.Status, resp.Body)
	}
	if resp.Header.Get("X-A") != "b" || resp.Header.Get("X-N") != "5" || len(resp.Header.Values("Set-Cookie")) != 2 {
		t.Errorf("headers = %v", resp.Header)
	}
	if resp.Header.Get("Content-Type") != contentTypeText {
		t.Errorf("string body defaults to text/plain, got %q", resp.Header.Get("Content-Type"))
	}
}

func TestParseEnvelopeObjectBody(t *testing.T) {
	resp := parseExecOutput([]byte(`{"statusCode":200,"body":{"runtime": "c", "ok": true}}`), 0)

	if string(resp.Body) != `{"runtime":"c","ok":true}` || resp.Header.Get("Content-Type") != contentTypeJSON {
		t.Errorf("resp = %q %v", resp.Body, resp.Header)
	}
}

func TestParseEnvelopeKeepsHandlerContentType(t *testing.T) {
	resp := parseExecOutput([]byte(`{"statusCode":200,"headers":{"content-type":"text/html"},"body":"<b>hi</b>"}`), 0)
	if resp.Header.Get("Content-Type") != "text/html" || string(resp.Body) != "<b>hi</b>" {
		t.Errorf("resp = %q %v", resp.Body, resp.Header)
	}
}

func TestParseEnvelopeBase64Body(t *testing.T) {
	resp := parseExecOutput([]byte(`{"statusCode":200,"isBase64Encoded":true,"body":"//4AYQ=="}`), 0)
	if string(resp.Body) != "\xff\xfe\x00a" || resp.Header.Get("Content-Type") != contentTypeBinary {
		t.Errorf("resp = %q %v", resp.Body, resp.Header)
	}

	bad := parseExecOutput([]byte(`{"statusCode":200,"isBase64Encoded":true,"body":"%%%"}`), 0)
	if bad.Status != 500 {
		t.Errorf("an invalid base64 body is a 500, got %d", bad.Status)
	}
}

func TestParseEnvelopeWithoutBody(t *testing.T) {
	resp := parseExecOutput([]byte(`{"statusCode":204}`), 0)
	if resp.Status != 204 || len(resp.Body) != 0 {
		t.Errorf("resp = %d %q", resp.Status, resp.Body)
	}
}

func TestParseEnvelopeInvalidStatus(t *testing.T) {
	if resp := parseExecOutput([]byte(`{"statusCode":42}`), 0); resp.Status != 500 {
		t.Errorf("status 42 is answered with 500, got %d", resp.Status)
	}
}

func TestNonNumericStatusCodeIsRawJSON(t *testing.T) {
	resp := parseExecOutput([]byte(`{"statusCode":"200","body":"x"}`), 0)
	if resp.Status != 200 || string(resp.Body) != `{"statusCode":"200","body":"x"}` || resp.Header.Get("Content-Type") != contentTypeJSON {
		t.Errorf("resp = %d %q %v", resp.Status, resp.Body, resp.Header)
	}
}

func TestParseRawOutput(t *testing.T) {
	if resp := parseExecOutput([]byte(`{"status":"ok"}`+"\n"), 0); resp.Status != 200 || resp.Header.Get("Content-Type") != contentTypeJSON || string(resp.Body) != "{\"status\":\"ok\"}\n" {
		t.Errorf("raw JSON = %d %q %v", resp.Status, resp.Body, resp.Header)
	}
	if resp := parseExecOutput([]byte("Hello, RunR!\n"), 0); resp.Status != 200 || resp.Header.Get("Content-Type") != contentTypeText || string(resp.Body) != "Hello, RunR!\n" {
		t.Errorf("raw text = %d %q %v", resp.Status, resp.Body, resp.Header)
	}
	if resp := parseExecOutput(nil, 0); resp.Status != 200 || len(resp.Body) != 0 || resp.Header.Get("Content-Type") != contentTypeText {
		t.Errorf("empty = %d %q %v", resp.Status, resp.Body, resp.Header)
	}
}

func TestNonZeroExit(t *testing.T) {
	resp := parseExecOutput([]byte("partial output"), 3)
	if resp.Status != 500 || string(resp.Body) != `{"error":"handler exited with status 3"}` {
		t.Errorf("resp = %d %q", resp.Status, resp.Body)
	}

	// An envelope still answers when the process failed afterwards
	resp = parseExecOutput([]byte(`{"statusCode":422,"body":"invalid"}`), 1)
	if resp.Status != 422 || string(resp.Body) != "invalid" {
		t.Errorf("envelope + exit 1 = %d %q", resp.Status, resp.Body)
	}
}

func TestParseLine(t *testing.T) {
	if resp := parseLine([]byte(`"plain string"`)); string(resp.Body) != "plain string" || resp.Header.Get("Content-Type") != contentTypeText {
		t.Errorf("string line = %q %v", resp.Body, resp.Header)
	}
	if resp := parseLine([]byte(`[1,2]`)); string(resp.Body) != "[1,2]" || resp.Header.Get("Content-Type") != contentTypeJSON {
		t.Errorf("array line = %q %v", resp.Body, resp.Header)
	}
	if resp := parseLine([]byte(`{"statusCode":202,"body":"queued"}`)); resp.Status != 202 || string(resp.Body) != "queued" {
		t.Errorf("envelope line = %d %q", resp.Status, resp.Body)
	}
}

func TestWriteResponseDefaultsCORS(t *testing.T) {
	rec := httptest.NewRecorder()
	writeResponse(rec, parseExecOutput([]byte("x"), 0))
	if rec.Header().Get("Access-Control-Allow-Origin") != "*" {
		t.Error("Access-Control-Allow-Origin defaults to *")
	}

	rec = httptest.NewRecorder()
	writeResponse(rec, parseExecOutput([]byte(`{"statusCode":200,"headers":{"access-control-allow-origin":"https://app.example"}}`), 0))
	if got := rec.Header().Values("Access-Control-Allow-Origin"); len(got) != 1 || got[0] != "https://app.example" {
		t.Errorf("the handler's origin is kept, got %v", got)
	}
}
