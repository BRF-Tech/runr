package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func testConfig(t *testing.T, size int64, sha string) Config {
	t.Helper()
	return Config{Port: 8080, Dir: t.TempDir(), Size: size, SHA256: sha, Target: "registry.test/runr-uploads/platform/app:1.0",
		Format: FormatAuto, MaxChunk: 4, PushTimeout: time.Minute, PlainHTTP: true}
}

func sha(b []byte) string {
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}

type recorder struct {
	t   *testing.T
	srv *httptest.Server
}

func newRecorder(t *testing.T, rec *Receiver) *recorder {
	srv := httptest.NewServer(rec.Handler())
	t.Cleanup(srv.Close)
	t.Cleanup(func() { rec.Close() })
	return &recorder{t: t, srv: srv}
}

func (r *recorder) do(method, path string, body []byte) (int, map[string]any) {
	r.t.Helper()
	req, err := http.NewRequest(method, r.srv.URL+path, bytes.NewReader(body))
	if err != nil {
		r.t.Fatal(err)
	}
	req.Header.Set("Content-Type", "application/octet-stream")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		r.t.Fatal(err)
	}
	defer resp.Body.Close()
	var out map[string]any
	_ = json.NewDecoder(resp.Body).Decode(&out)
	return resp.StatusCode, out
}

func waitState(t *testing.T, rec *Receiver, want ...string) Status {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		st := rec.Status()
		for _, w := range want {
			if st.State == w {
				return st
			}
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatalf("state %q, want one of %v", rec.Status().State, want)
	return Status{}
}

func TestChunksInAnyOrderAndResumeAfterTheContiguousPrefix(t *testing.T) {
	data := []byte("abcdefghij")
	rec, err := NewReceiver(testConfig(t, int64(len(data)), ""), func(context.Context, Config, string, string) (*Result, error) {
		return &Result{Digest: "sha256:" + strings.Repeat("a", 64), Kind: KindImage}, nil
	})
	if err != nil {
		t.Fatal(err)
	}
	r := newRecorder(t, rec)

	// the middle chunk first: nothing contiguous yet
	if code, out := r.do("PUT", "/upload?offset=4", data[4:8]); code != 200 || out["received"].(float64) != 0 {
		t.Fatalf("chunk at 4: %d %v", code, out)
	}
	if code, out := r.do("PUT", "/upload?offset=0", data[0:4]); code != 200 || out["received"].(float64) != 8 {
		t.Fatalf("chunk at 0: %d %v", code, out)
	}
	// a repeated chunk is harmless
	if code, out := r.do("PUT", "/upload?offset=0", data[0:4]); code != 200 || out["received"].(float64) != 8 {
		t.Fatalf("repeated chunk: %d %v", code, out)
	}

	// incomplete: completing is refused with what is there
	if code, out := r.do("POST", "/complete", nil); code != http.StatusConflict || out["received"].(float64) != 8 {
		t.Fatalf("early complete: %d %v", code, out)
	}

	if code, out := r.do("PUT", "/upload?offset=8", data[8:]); code != 200 || out["received"].(float64) != 10 {
		t.Fatalf("last chunk: %d %v", code, out)
	}
	if code, _ := r.do("POST", "/complete", nil); code != http.StatusAccepted {
		t.Fatalf("complete: %d", code)
	}
	st := waitState(t, rec, StatePushed)
	if st.SHA256 != sha(data) || st.Result == nil || st.Result.Kind != KindImage {
		t.Fatalf("status %+v", st)
	}

	// done: no more chunks
	if code, _ := r.do("PUT", "/upload?offset=0", data[0:4]); code != http.StatusConflict {
		t.Fatalf("chunk after push: %d", code)
	}
}

func TestChunksOutsideTheFileOrTooLargeAreRefused(t *testing.T) {
	rec, err := NewReceiver(testConfig(t, 6, ""), nil)
	if err != nil {
		t.Fatal(err)
	}
	r := newRecorder(t, rec)

	cases := []struct {
		path string
		body string
		want int
	}{
		{"/upload?offset=-1", "ab", http.StatusBadRequest},
		{"/upload?offset=6", "ab", http.StatusBadRequest},
		{"/upload?offset=x", "ab", http.StatusBadRequest},
		{"/upload?offset=0", "abcde", http.StatusRequestEntityTooLarge}, // above MaxChunk (4)
		{"/upload?offset=4", "abc", http.StatusRequestEntityTooLarge},   // beyond the declared size
		{"/upload?offset=0", "", http.StatusBadRequest},
	}
	for _, c := range cases {
		if code, out := r.do("PUT", c.path, []byte(c.body)); code != c.want {
			t.Errorf("%s %q: %d %v, want %d", c.path, c.body, code, out, c.want)
		}
	}
}

func TestSHA256MismatchReopensTheUpload(t *testing.T) {
	data := []byte("abcd")
	pushed := false
	rec, err := NewReceiver(testConfig(t, 4, strings.Repeat("0", 64)), func(context.Context, Config, string, string) (*Result, error) {
		pushed = true
		return &Result{}, nil
	})
	if err != nil {
		t.Fatal(err)
	}
	r := newRecorder(t, rec)
	r.do("PUT", "/upload?offset=0", data)
	if code, _ := r.do("POST", "/complete", nil); code != http.StatusAccepted {
		t.Fatalf("complete: %d", code)
	}

	deadline := time.Now().Add(5 * time.Second)
	for rec.Status().Error == "" && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}
	st := rec.Status()
	if st.State != StateReceiving || st.Received != 0 || !strings.Contains(st.Error, sha(data)) {
		t.Fatalf("status after mismatch %+v", st)
	}
	if pushed {
		t.Fatal("a file with the wrong SHA-256 was pushed")
	}

	// the file can be sent again
	if code, out := r.do("PUT", "/upload?offset=0", data); code != 200 || out["received"].(float64) != 4 {
		t.Fatalf("resend: %d %v", code, out)
	}
}

func TestAFailedPushIsFinal(t *testing.T) {
	rec, err := NewReceiver(testConfig(t, 2, ""), func(context.Context, Config, string, string) (*Result, error) {
		return nil, errors.New("pushing the image: registry unreachable")
	})
	if err != nil {
		t.Fatal(err)
	}
	r := newRecorder(t, rec)
	r.do("PUT", "/upload?offset=0", []byte("ab"))
	r.do("POST", "/complete", nil)
	st := waitState(t, rec, StateFailed)
	if st.Error != "pushing the image: registry unreachable" {
		t.Fatalf("status %+v", st)
	}
	select {
	case <-rec.Done():
	case <-time.After(time.Second):
		t.Fatal("Done not closed")
	}
	if code, _ := r.do("POST", "/complete", nil); code != http.StatusConflict {
		t.Fatalf("complete again: %d", code)
	}
}

func TestSpansMerge(t *testing.T) {
	var s []span
	s = addSpan(s, span{4, 8})
	if contiguous(s) != 0 {
		t.Fatal("a span not at 0 is not contiguous")
	}
	s = addSpan(s, span{10, 12})
	s = addSpan(s, span{0, 4})
	if contiguous(s) != 8 || len(s) != 2 {
		t.Fatalf("spans %v", s)
	}
	s = addSpan(s, span{6, 11})
	if contiguous(s) != 12 || len(s) != 1 {
		t.Fatalf("spans %v", s)
	}
}

func TestConfigFromEnv(t *testing.T) {
	env := map[string]string{
		"RUNR_UPLOAD_SIZE":   "1024",
		"RUNR_UPLOAD_TARGET": "10.43.0.5:5000/runr-uploads/platform/app:1.0",
		"RUNR_UPLOAD_SHA256": strings.ToUpper(strings.Repeat("ab", 32)),
		"RUNR_UPLOAD_FORMAT": "docker",
		"PORT":               "9090",
	}
	cfg, err := configFromEnv(func(k string) string { return env[k] })
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Size != 1024 || cfg.Port != 9090 || cfg.Format != FormatDocker || cfg.SHA256 != strings.Repeat("ab", 32) || !cfg.PlainHTTP || cfg.Dir != "/work" {
		t.Fatalf("config %+v", cfg)
	}

	for key, bad := range map[string]string{
		"RUNR_UPLOAD_SIZE":   "0",
		"RUNR_UPLOAD_TARGET": "app",
		"RUNR_UPLOAD_SHA256": "xyz",
		"RUNR_UPLOAD_FORMAT": "zip",
		"PORT":               "70000",
	} {
		e := map[string]string{}
		for k, v := range env {
			e[k] = v
		}
		e[key] = bad
		if _, err := configFromEnv(func(k string) string { return e[k] }); err == nil {
			t.Errorf("%s=%q accepted", key, bad)
		}
	}
}

func ExampleStatus() {
	b, _ := json.Marshal(Status{State: StatePushing, Received: 10, Size: 10, SHA256: "ab"})
	fmt.Println(string(b))
	// Output: {"state":"pushing","received":10,"size":10,"sha256":"ab"}
}
