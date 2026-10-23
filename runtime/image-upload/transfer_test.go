package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func transferTestConfig(t *testing.T, mode string, files ...TransferFile) TransferConfig {
	t.Helper()
	return TransferConfig{Port: 8080, Dir: t.TempDir(), Mode: mode, Files: files, MaxChunk: 4,
		PullTimeout: time.Minute, PlainHTTP: true, Poll: 5 * time.Millisecond}
}

type transferClient struct {
	t   *testing.T
	srv *httptest.Server
}

// startAgent runs the agent's marker watch and serves its handler.
func startAgent(t *testing.T, cfg TransferConfig, pull Puller) (*Agent, *transferClient) {
	t.Helper()
	a, err := NewAgent(cfg, pull)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	a.Start(ctx)
	srv := httptest.NewServer(a.Handler())
	t.Cleanup(func() { cancel(); srv.Close(); a.Close() })
	return a, &transferClient{t: t, srv: srv}
}

func (c *transferClient) do(method, path string, body []byte, header map[string]string) (*http.Response, []byte) {
	c.t.Helper()
	req, err := http.NewRequest(method, c.srv.URL+path, bytes.NewReader(body))
	if err != nil {
		c.t.Fatal(err)
	}
	for k, v := range header {
		req.Header.Set(k, v)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		c.t.Fatal(err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return resp, b
}

func (c *transferClient) json(method, path string, body []byte) (int, map[string]any) {
	c.t.Helper()
	resp, b := c.do(method, path, body, map[string]string{"Content-Type": "application/octet-stream"})
	var out map[string]any
	_ = json.Unmarshal(b, &out)
	return resp.StatusCode, out
}

func waitTransferState(t *testing.T, a *Agent, want string) TransferStatus {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if st := a.Status(); st.State == want {
			return st
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatalf("state %q (%s), want %q", a.Status().State, a.Status().Message, want)
	return TransferStatus{}
}

func freePort() (int, error) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 0, err
	}
	defer l.Close()
	return l.Addr().(*net.TCPAddr).Port, nil
}

func itoa(n int) string { return strconv.Itoa(n) }

func writeFile(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestSendServesTheToolsFilesOnceDone(t *testing.T) {
	cfg := transferTestConfig(t, ModeSend)
	a, c := startAgent(t, cfg, nil)

	if st := a.Status(); st.State != TransferWorking || st.Mode != ModeSend || len(st.Files) != 0 {
		t.Fatalf("status %+v", st)
	}
	if code, _ := c.json("GET", "/files/data.dump", nil); code != http.StatusConflict {
		t.Fatalf("download before done: %d", code)
	}

	writeFile(t, filepath.Join(cfg.Dir, "out", "data.dump"), "0123456789")
	writeFile(t, filepath.Join(cfg.Dir, "out", ".partial"), "not served")
	writeFile(t, filepath.Join(cfg.Dir, "secret"), "outside out/")
	writeFile(t, filepath.Join(cfg.Dir, markerDone), `{"rows": 3}`)

	st := waitTransferState(t, a, TransferDone)
	if len(st.Files) != 1 || st.Files[0].Name != "data.dump" || st.Files[0].Size != 10 || st.Files[0].SHA256 != sha([]byte("0123456789")) || st.Files[0].Received != 10 {
		t.Fatalf("files %+v", st.Files)
	}
	if string(st.Result) != `{"rows": 3}` {
		t.Fatalf("result %s", st.Result)
	}

	resp, body := c.do("GET", "/files/data.dump", nil, nil)
	if resp.StatusCode != http.StatusOK || string(body) != "0123456789" || resp.Header.Get("X-Content-SHA256") != sha([]byte("0123456789")) {
		t.Fatalf("full download %d %q", resp.StatusCode, body)
	}

	resp, body = c.do("GET", "/files/data.dump", nil, map[string]string{"Range": "bytes=2-5"})
	if resp.StatusCode != http.StatusPartialContent || string(body) != "2345" || resp.Header.Get("Content-Range") != "bytes 2-5/10" || resp.Header.Get("Content-Length") != "4" {
		t.Fatalf("range 2-5: %d %q %q", resp.StatusCode, body, resp.Header.Get("Content-Range"))
	}

	resp, body = c.do("GET", "/files/data.dump", nil, map[string]string{"Range": "bytes=7-"})
	if resp.StatusCode != http.StatusPartialContent || string(body) != "789" {
		t.Fatalf("range 7-: %d %q", resp.StatusCode, body)
	}

	resp, _ = c.do("GET", "/files/data.dump", nil, map[string]string{"Range": "bytes=20-"})
	if resp.StatusCode != http.StatusRequestedRangeNotSatisfiable {
		t.Fatalf("range past the end: %d", resp.StatusCode)
	}

	for _, path := range []string{"/files/.partial", "/files/..%2Fsecret", "/files/secret", "/files/missing", "/files/..%2F.done"} {
		if resp, _ := c.do("GET", path, nil, nil); resp.StatusCode != http.StatusNotFound {
			t.Errorf("GET %s: %d, want 404", path, resp.StatusCode)
		}
	}
	if code, _ := c.json("PUT", "/files/data.dump?offset=0", []byte("x")); code != http.StatusConflict {
		t.Fatalf("chunk to a sender: %d", code)
	}
}

func TestSendReportsTheToolsFailure(t *testing.T) {
	cfg := transferTestConfig(t, ModeSend)
	a, c := startAgent(t, cfg, nil)

	writeFile(t, filepath.Join(cfg.Dir, "out", "data.dump"), "half")
	writeFile(t, filepath.Join(cfg.Dir, markerFailed), "pg_dump: connection refused\n")

	st := waitTransferState(t, a, TransferFailed)
	if st.Message != "pg_dump: connection refused" || len(st.Files) != 0 {
		t.Fatalf("status %+v", st)
	}
	if code, _ := c.json("GET", "/files/data.dump", nil); code != http.StatusConflict {
		t.Fatalf("download after a failure: %d", code)
	}
	// a later .done changes nothing: the state is final
	writeFile(t, filepath.Join(cfg.Dir, markerDone), "{}")
	time.Sleep(30 * time.Millisecond)
	if a.Status().State != TransferFailed {
		t.Fatalf("state %s", a.Status().State)
	}
}

func TestSendPullsTheImageItself(t *testing.T) {
	cfg := transferTestConfig(t, ModeSend)
	cfg.Pull = "registry.test/runr-builds/api:v3"
	var pulled string
	pull := func(_ context.Context, ref, out string, plain bool) (*PullResult, error) {
		pulled = ref
		if !plain || filepath.Base(out) != "image.tar" {
			t.Errorf("pull to %s (plain %v)", out, plain)
		}
		return &PullResult{Reference: ref + "@sha256:abc", Digest: "sha256:abc", Platforms: []string{"linux/amd64"}, SizeBytes: 5},
			os.WriteFile(out, []byte("image"), 0o644)
	}
	a, c := startAgent(t, cfg, pull)

	st := waitTransferState(t, a, TransferDone)
	if pulled != cfg.Pull || len(st.Files) != 1 || st.Files[0].Name != "image.tar" || st.Files[0].Size != 5 {
		t.Fatalf("pulled %q, status %+v", pulled, st)
	}
	var res PullResult
	if err := json.Unmarshal(st.Result, &res); err != nil || res.Digest != "sha256:abc" {
		t.Fatalf("result %s (%v)", st.Result, err)
	}
	if resp, body := c.do("GET", "/files/image.tar", nil, nil); resp.StatusCode != http.StatusOK || string(body) != "image" {
		t.Fatalf("download %d %q", resp.StatusCode, body)
	}
}

func TestSendReportsAFailedPull(t *testing.T) {
	cfg := transferTestConfig(t, ModeSend)
	cfg.Pull = "registry.test/runr-builds/api:v3"
	a, _ := startAgent(t, cfg, func(context.Context, string, string, bool) (*PullResult, error) {
		return nil, errors.New("reading registry.test/runr-builds/api:v3: MANIFEST_UNKNOWN")
	})

	if st := waitTransferState(t, a, TransferFailed); !strings.Contains(st.Message, "MANIFEST_UNKNOWN") {
		t.Fatalf("message %q", st.Message)
	}
}

func TestReceiveResumesVerifiesAndHandsTheFilesToTheTool(t *testing.T) {
	data := []byte("abcdefghij")
	cfg := transferTestConfig(t, ModeReceive,
		TransferFile{Name: "data.dump", Size: 10, SHA256: sha(data)},
		TransferFile{Name: "empty.kv", Size: 0, SHA256: sha(nil)},
	)
	a, c := startAgent(t, cfg, nil)

	if st := a.Status(); st.State != TransferReceiving || len(st.Files) != 2 || st.Files[0].Received != 0 {
		t.Fatalf("status %+v", st)
	}

	steps := []struct {
		offset   string
		chunk    string
		received float64
	}{
		{"0", "abcd", 4},
		{"8", "ij", 4}, // after a gap: the contiguous prefix does not move
		{"4", "efgh", 10},
		{"4", "efgh", 10}, // a repeated chunk (a retry) changes nothing
	}
	for _, s := range steps {
		code, out := c.json("PUT", "/files/data.dump?offset="+s.offset, []byte(s.chunk))
		if code != http.StatusOK || out["received"] != s.received || out["size"] != float64(10) {
			t.Fatalf("chunk at %s: %d %v", s.offset, code, out)
		}
	}

	code, out := c.json("POST", "/files/data.dump/complete", nil)
	if code != http.StatusOK || out["complete"] != true || out["ready"] != false || out["sha256"] != sha(data) {
		t.Fatalf("complete data.dump: %d %v", code, out)
	}
	if a.Status().State != TransferReceiving {
		t.Fatalf("ready before every file: %s", a.Status().State)
	}
	if _, err := os.Stat(filepath.Join(cfg.Dir, "in", markerReady)); err == nil {
		t.Fatal("in/.ready written before every file was verified")
	}
	// completing again answers the same (a retried request)
	if code, out := c.json("POST", "/files/data.dump/complete", nil); code != http.StatusOK || out["complete"] != true {
		t.Fatalf("second complete: %d %v", code, out)
	}

	code, out = c.json("POST", "/files/empty.kv/complete", nil)
	if code != http.StatusOK || out["ready"] != true {
		t.Fatalf("complete empty.kv: %d %v", code, out)
	}
	st := waitTransferState(t, a, TransferReady)
	if st.Files[0].Received != 10 || st.Files[0].SHA256 != sha(data) {
		t.Fatalf("files %+v", st.Files)
	}
	if _, err := os.Stat(filepath.Join(cfg.Dir, "in", markerReady)); err != nil {
		t.Fatalf("in/.ready: %v", err)
	}
	if got, _ := os.ReadFile(filepath.Join(cfg.Dir, "in", "data.dump")); string(got) != string(data) {
		t.Fatalf("in/data.dump %q", got)
	}
	if code, _ := c.json("PUT", "/files/data.dump?offset=0", []byte("zz")); code != http.StatusConflict {
		t.Fatalf("chunk after ready: %d", code)
	}

	writeFile(t, filepath.Join(cfg.Dir, markerDone), `{"restored": 2}`)
	st = waitTransferState(t, a, TransferDone)
	if string(st.Result) != `{"restored": 2}` {
		t.Fatalf("result %s", st.Result)
	}
	if code, _ := c.json("GET", "/files/data.dump", nil); code != http.StatusConflict {
		t.Fatalf("download from a receiver: %d", code)
	}
}

func TestReceiveEmptiesAFileWhoseSHA256DoesNotMatch(t *testing.T) {
	data := []byte("abcdefgh")
	cfg := transferTestConfig(t, ModeReceive, TransferFile{Name: "objects.tar", Size: 8, SHA256: sha(data)})
	a, c := startAgent(t, cfg, nil)

	c.json("PUT", "/files/objects.tar?offset=0", []byte("abcd"))
	c.json("PUT", "/files/objects.tar?offset=4", []byte("XXXX"))
	code, out := c.json("POST", "/files/objects.tar/complete", nil)
	if code != http.StatusUnprocessableEntity || out["received"] != float64(0) || !strings.Contains(out["error"].(string), "send the file again") {
		t.Fatalf("mismatch: %d %v", code, out)
	}
	if st := a.Status(); st.State != TransferReceiving || st.Files[0].Received != 0 {
		t.Fatalf("status after the mismatch %+v", st)
	}

	c.json("PUT", "/files/objects.tar?offset=0", []byte("abcd"))
	c.json("PUT", "/files/objects.tar?offset=4", []byte("efgh"))
	if code, out := c.json("POST", "/files/objects.tar/complete", nil); code != http.StatusOK || out["ready"] != true {
		t.Fatalf("second try: %d %v", code, out)
	}
	waitTransferState(t, a, TransferReady)
}

func TestReceiveRefusesBadChunks(t *testing.T) {
	cfg := transferTestConfig(t, ModeReceive,
		TransferFile{Name: "data.dump", Size: 10},
		TransferFile{Name: "empty.kv", Size: 0},
	)
	_, c := startAgent(t, cfg, nil)

	for _, tc := range []struct {
		path  string
		chunk string
		code  int
	}{
		{"/files/data.dump?offset=10", "a", http.StatusUnprocessableEntity},    // past the end
		{"/files/data.dump?offset=-1", "a", http.StatusUnprocessableEntity},    // negative
		{"/files/data.dump?offset=x", "a", http.StatusUnprocessableEntity},     // not a number
		{"/files/data.dump?offset=0", "abcde", http.StatusUnprocessableEntity}, // larger than a chunk
		{"/files/data.dump?offset=8", "abc", http.StatusUnprocessableEntity},   // larger than the rest
		{"/files/data.dump?offset=0", "", http.StatusUnprocessableEntity},      // empty
		{"/files/empty.kv?offset=0", "a", http.StatusUnprocessableEntity},      // an empty file takes none
		{"/files/other.bin?offset=0", "a", http.StatusNotFound},                // not expected
		{"/files/..%2Fdata.dump?offset=0", "a", http.StatusNotFound},           // no paths
	} {
		if code, out := c.json("PUT", tc.path, []byte(tc.chunk)); code != tc.code {
			t.Errorf("PUT %s %q: %d %v, want %d", tc.path, tc.chunk, code, out, tc.code)
		}
	}

	c.json("PUT", "/files/data.dump?offset=0", []byte("abcd"))
	code, out := c.json("POST", "/files/data.dump/complete", nil)
	if code != http.StatusConflict || out["received"] != float64(4) {
		t.Fatalf("complete with bytes missing: %d %v", code, out)
	}
	if code, _ := c.json("POST", "/files/other.bin/complete", nil); code != http.StatusNotFound {
		t.Fatalf("complete of an unexpected file: %d", code)
	}

	c.json("PUT", "/files/data.dump?offset=4", []byte("efgh"))
	c.json("PUT", "/files/data.dump?offset=8", []byte("ij"))
	if code, _ := c.json("POST", "/files/data.dump/complete", nil); code != http.StatusOK {
		t.Fatalf("complete without a declared SHA-256: %d", code)
	}
	if code, _ := c.json("PUT", "/files/data.dump?offset=0", []byte("abcd")); code != http.StatusConflict {
		t.Fatalf("chunk to a complete file: %d", code)
	}
}

func TestReceiveIgnoresDoneBeforeReadyButNotAFailure(t *testing.T) {
	cfg := transferTestConfig(t, ModeReceive, TransferFile{Name: "data.dump", Size: 4})
	a, c := startAgent(t, cfg, nil)

	writeFile(t, filepath.Join(cfg.Dir, markerDone), "{}")
	time.Sleep(30 * time.Millisecond)
	if st := a.Status(); st.State != TransferReceiving {
		t.Fatalf("done before ready: %s", st.State)
	}

	writeFile(t, filepath.Join(cfg.Dir, markerFailed), "psql: password authentication failed")
	if st := waitTransferState(t, a, TransferFailed); st.Message != "psql: password authentication failed" {
		t.Fatalf("message %q", st.Message)
	}
	if code, _ := c.json("PUT", "/files/data.dump?offset=0", []byte("abcd")); code != http.StatusConflict {
		t.Fatalf("chunk after a failure: %d", code)
	}
}

func TestStopEndsTheAgent(t *testing.T) {
	a, c := startAgent(t, transferTestConfig(t, ModeSend), nil)

	if code, out := c.json("POST", "/stop", nil); code != http.StatusOK || out["stopping"] != true {
		t.Fatalf("stop: %d %v", code, out)
	}
	select {
	case <-a.Stopped():
	case <-time.After(time.Second):
		t.Fatal("not stopped")
	}
	// a second stop is harmless
	if code, _ := c.json("POST", "/stop", nil); code != http.StatusOK {
		t.Fatalf("second stop: %d", code)
	}
}

func TestServeTransferExitsOnStop(t *testing.T) {
	cfg := transferTestConfig(t, ModeSend)
	l, err := freePort()
	if err != nil {
		t.Fatal(err)
	}
	cfg.Port = l

	errc := make(chan error, 1)
	go func() { errc <- serveTransfer(context.Background(), cfg) }()

	url := "http://127.0.0.1:" + itoa(cfg.Port)
	deadline := time.Now().Add(5 * time.Second)
	for {
		resp, err := http.Post(url+"/stop", "application/json", nil)
		if err == nil {
			resp.Body.Close()
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("agent not listening: %v", err)
		}
		time.Sleep(10 * time.Millisecond)
	}
	select {
	case err := <-errc:
		if err != nil {
			t.Fatalf("serveTransfer: %v", err)
		}
	case <-time.After(10 * time.Second):
		t.Fatal("serveTransfer did not return after /stop")
	}
}

func TestTransferConfigFromEnv(t *testing.T) {
	env := map[string]string{
		"RUNR_TRANSFER_MODE":      "receive",
		"RUNR_TRANSFER_FILES":     `[{"name":"data.dump","size":10,"sha256":"` + strings.ToUpper(strings.Repeat("ab", 32)) + `"},{"name":"kv.jsonl","size":0}]`,
		"RUNR_TRANSFER_DIR":       "/data/work",
		"RUNR_TRANSFER_MAX_CHUNK": "1048576",
		"PORT":                    "9090",
	}
	cfg, err := transferConfigFromEnv(func(k string) string { return env[k] })
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Mode != ModeReceive || cfg.Dir != "/data/work" || cfg.Port != 9090 || cfg.MaxChunk != 1<<20 || len(cfg.Files) != 2 ||
		cfg.Files[0].SHA256 != strings.Repeat("ab", 32) || !cfg.PlainHTTP || cfg.Poll != time.Second {
		t.Fatalf("config %+v", cfg)
	}

	send, err := transferConfigFromEnv(func(k string) string {
		return map[string]string{"RUNR_TRANSFER_MODE": "send", "RUNR_TRANSFER_PULL": "zot:5000/runr-builds/api:v3", "RUNR_REGISTRY_PLAIN_HTTP": "false"}[k]
	})
	if err != nil || send.Pull != "zot:5000/runr-builds/api:v3" || send.PlainHTTP || send.Dir != "/work" || send.PullTimeout != time.Hour {
		t.Fatalf("send config %+v (%v)", send, err)
	}

	for key, bad := range map[string]string{
		"RUNR_TRANSFER_MODE":      "copy",
		"RUNR_TRANSFER_FILES":     `[{"name":"../etc/passwd","size":1}]`,
		"RUNR_TRANSFER_MAX_CHUNK": "0",
		"RUNR_TRANSFER_PULL":      "zot:5000/app:1", // pulling is a sender's
		"PORT":                    "0",
	} {
		e := map[string]string{}
		for k, v := range env {
			e[k] = v
		}
		e[key] = bad
		if _, err := transferConfigFromEnv(func(k string) string { return e[k] }); err == nil {
			t.Errorf("%s=%q accepted", key, bad)
		}
	}

	for _, files := range []string{
		``, `[]`, `{"name":"a"}`,
		`[{"name":".ready","size":1}]`,
		`[{"name":"in/a","size":1}]`,
		`[{"name":"a","size":-1}]`,
		`[{"name":"a","size":1},{"name":"a","size":2}]`,
		`[{"name":"a","size":1,"sha256":"xyz"}]`,
	} {
		e := map[string]string{"RUNR_TRANSFER_MODE": "receive", "RUNR_TRANSFER_FILES": files}
		if _, err := transferConfigFromEnv(func(k string) string { return e[k] }); err == nil {
			t.Errorf("RUNR_TRANSFER_FILES=%s accepted", files)
		}
	}
}

func ExampleTransferStatus() {
	b, _ := json.Marshal(TransferStatus{Mode: ModeReceive, State: TransferReceiving, Files: []TransferFileStatus{{Name: "data.dump", Size: 10, Received: 4}}, Result: json.RawMessage("{}")})
	os.Stdout.Write(append(b, '\n'))
	// Output: {"mode":"receive","state":"receiving","message":"","files":[{"name":"data.dump","size":10,"received":4}],"result":{}}
}

func TestTransferTokenGuardsEveryRouteButHealthz(t *testing.T) {
	cfg := transferTestConfig(t, ModeReceive, TransferFile{Name: "data.dump", Size: 4})
	cfg.Token = "s3cret-token"
	_, c := startAgent(t, cfg, nil)

	if resp, _ := c.do(http.MethodGet, "/healthz", nil, nil); resp.StatusCode != http.StatusOK {
		t.Fatalf("healthz without token: %d", resp.StatusCode)
	}
	if resp, _ := c.do(http.MethodGet, "/status", nil, nil); resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("status without token: %d, want 401", resp.StatusCode)
	}
	if resp, _ := c.do(http.MethodPut, "/files/data.dump?offset=0", []byte("abcd"), map[string]string{TransferTokenHeader: "wrong"}); resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("chunk with a wrong token: %d, want 401", resp.StatusCode)
	}
	if resp, _ := c.do(http.MethodGet, "/status", nil, map[string]string{TransferTokenHeader: "s3cret-token"}); resp.StatusCode != http.StatusOK {
		t.Fatalf("status with the token: %d", resp.StatusCode)
	}
}

func TestTransferTokenFromEnv(t *testing.T) {
	env := map[string]string{"RUNR_TRANSFER_MODE": "send", "RUNR_TRANSFER_TOKEN": " tok "}
	cfg, err := transferConfigFromEnv(func(k string) string { return env[k] })
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Token != "tok" {
		t.Fatalf("token %q", cfg.Token)
	}
}
