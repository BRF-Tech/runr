package runr_test

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

func sbxWait() runr.WaitOptions {
	return runr.WaitOptions{Timeout: 5 * time.Second, Interval: time.Millisecond}
}

func TestSSEDecoderAnyChunking(t *testing.T) {
	stream := ": stream\r\n\r\n" +
		"event: start\r\ndata: {\"exec_id\":\"e1\"}\r\n\r\n" +
		"event: stdout\ndata: {\"data\":\"héllo\"}\n\n" +
		": ping\n\n" +
		"event: note\rdata: line one\rdata: line two\r\r" +
		"event: exit\ndata: {\"exit_code\":0}" // no closing blank line: Flush dispatches it
	want := []runr.SSEEvent{
		{Event: "start", Data: `{"exec_id":"e1"}`},
		{Event: "stdout", Data: `{"data":"héllo"}`},
		{Event: "note", Data: "line one\nline two"},
		{Event: "exit", Data: `{"exit_code":0}`},
	}
	// Every split point, including inside \r\n and inside the two-byte é.
	for size := 1; size <= len(stream); size++ {
		d := &runr.SSEDecoder{}
		var got []runr.SSEEvent
		for i := 0; i < len(stream); i += size {
			end := i + size
			if end > len(stream) {
				end = len(stream)
			}
			got = append(got, d.Feed([]byte(stream[i:end]))...)
		}
		got = append(got, d.Flush()...)
		if len(got) != len(want) {
			t.Fatalf("chunk size %d: %d events %+v, want %d", size, len(got), got, len(want))
		}
		for i := range want {
			if got[i].Event != want[i].Event || got[i].Data != want[i].Data {
				t.Fatalf("chunk size %d: event %d = %+v, want %+v", size, i, got[i], want[i])
			}
		}
	}
}

func TestSandboxLifecycle(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	s, err := c.CreateSandbox(ctx, runr.CreateSandboxInput{Runtime: "python", Memory: "1Gi", TTLSeconds: 600,
		Network: runr.SandboxEgress([]string{"pypi.org:443"}), Env: map[string]string{"API_KEY": "s3cret"},
		Metadata: map[string]string{"agent": "planner"}, SandboxClass: "gvisor"})
	if err != nil {
		t.Fatal(err)
	}
	if s.Status != runr.SandboxRunning || s.SandboxClass != "gvisor" || s.Isolation != "gvisor" || s.RegionName() != "eu-1" {
		t.Fatalf("created = %+v", s)
	}
	if s.Resources.MemoryMB != 1024 || s.Network.Mode != "allowlist" || len(s.EnvKeys) != 1 || s.EnvKeys[0] != "API_KEY" {
		t.Fatalf("created = %+v", s)
	}
	body := srv.CallsTo("POST /sandboxes")[0].Body
	if body["ttl_seconds"] != float64(600) || body["memory"] != "1Gi" {
		t.Fatalf("create body = %v", body)
	}
	if net, _ := body["network"].(map[string]any); net == nil || len(net["egress_allow"].([]any)) != 1 {
		t.Fatalf("network = %v", body["network"])
	}

	got, err := c.GetSandbox(ctx, s.ID)
	if err != nil || got.ID != s.ID {
		t.Fatalf("get = %+v, %v", got, err)
	}
	list, page, err := c.ListSandboxes(ctx, runr.SandboxListOptions{PerPage: 10})
	if err != nil || len(list) != 1 || page.Total != 1 || page.PerPage != 10 {
		t.Fatalf("list = %+v %+v %v", list, page, err)
	}

	gone, err := c.DeleteSandbox(ctx, s.ID)
	if err != nil || gone.Status != runr.SandboxTerminated || gone.EndReason != "deleted" {
		t.Fatalf("delete = %+v, %v", gone, err)
	}
	if list, _, _ := c.ListSandboxes(ctx, runr.SandboxListOptions{}); len(list) != 0 {
		t.Fatalf("active after delete = %+v", list)
	}
	if list, _, _ := c.ListSandboxes(ctx, runr.SandboxListOptions{Status: "ended"}); len(list) != 1 {
		t.Fatalf("ended = %+v", list)
	}
	if _, err := c.GetSandbox(ctx, "nope"); !runr.IsNotFound(err) {
		t.Fatalf("unknown sandbox: %v", err)
	}
	events, err := c.SandboxLogs(ctx, s.ID)
	if err != nil || len(events) < 2 || events[0].Type != "deleted" {
		t.Fatalf("logs = %+v, %v", events, err)
	}
}

func TestSandboxCreateWaitsForAPendingStart(t *testing.T) {
	srv := runrtest.New(t)
	srv.SetSandboxStart(2, false)
	c := client(t, srv)
	ctx := context.Background()
	s, err := c.CreateSandbox(ctx, runr.CreateSandboxInput{})
	if err != nil || s.Status != runr.SandboxPending {
		t.Fatalf("create = %+v, %v", s, err)
	}
	var seen []string
	o := sbxWait()
	o.Progress = func(st string) { seen = append(seen, st) }
	s, err = c.WaitSandbox(ctx, s.ID, o)
	if err != nil || s.Status != runr.SandboxRunning {
		t.Fatalf("wait = %+v, %v", s, err)
	}
	if strings.Join(seen, ",") != "pending,running" {
		t.Fatalf("progress = %v", seen)
	}
}

func TestSandboxStartFailure(t *testing.T) {
	srv := runrtest.New(t)
	srv.SetSandboxStart(0, true)
	_, err := client(t, srv).CreateSandbox(context.Background(), runr.CreateSandboxInput{Image: "ghcr.io/acme/unpullable:1"})
	var fe *runr.FailedError
	if !errors.As(err, &fe) || !strings.Contains(fe.Error(), "could not start") {
		t.Fatalf("err = %v", err)
	}
}

// orderedWriter records which stream wrote what, in order.
type orderedWriter struct {
	mu   *sync.Mutex
	name string
	log  *[]string
}

func (w orderedWriter) Write(p []byte) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	*w.log = append(*w.log, w.name+":"+string(p))
	return len(p), nil
}

func TestSandboxStreamExecKeepsOrderAndExitCode(t *testing.T) {
	srv := runrtest.New(t)
	sb := srv.SeedSandbox("python")
	srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{ExitCode: 3, Output: []runrtest.SandboxChunk{
			{Stream: "stdout", Data: []byte("one\n")},
			{Stream: "stderr", Data: []byte("warn\n")},
			{Stream: "stdout", Data: []byte("two\n")},
			{Stream: "stderr", Data: []byte{0xff, 0xfe}},
		}}
	})
	c := client(t, srv)
	var mu sync.Mutex
	var log []string
	started := ""
	res, err := c.StreamSandboxExec(context.Background(), sb.ID,
		runr.SandboxExecInput{Cmd: []string{"python3", "job.py"}, Env: map[string]string{"EPOCHS": "3"}, Timeout: 30},
		runr.SandboxStream{Stdout: orderedWriter{&mu, "out", &log}, Stderr: orderedWriter{&mu, "err", &log},
			OnStart: func(id string) { started = id }})
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"out:one\n", "err:warn\n", "out:two\n", "err:\xff\xfe"}
	if strings.Join(log, "|") != strings.Join(want, "|") {
		t.Fatalf("output order = %q, want %q", log, want)
	}
	if res.ExitCode != 3 || started == "" || res.ExecID != started {
		t.Fatalf("result = %+v (started %q)", res, started)
	}
	calls := srv.SandboxCalls()
	if len(calls) != 1 || !calls[0].Stream || strings.Join(calls[0].Cmd, " ") != "python3 job.py" || calls[0].Env["EPOCHS"] != "3" {
		t.Fatalf("calls = %+v", calls)
	}
}

func TestSandboxExecJSONAndRun(t *testing.T) {
	srv := runrtest.New(t)
	sb := srv.SeedSandbox("python")
	srv.SetSandboxExec(func(call runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		if call.Code != "" {
			return runrtest.SandboxExecReply{Output: []runrtest.SandboxChunk{{Stream: "stdout", Data: []byte("42\n")}},
				FilesChanged: []runr.SandboxFileChange{{Path: "/output/chart.png", Size: 4}},
				Outputs:      []runr.SandboxOutput{{Path: "/output/chart.png", Mime: "image/png", Size: 4, DataBase64: "iVBORw=="}}}
		}
		return runrtest.SandboxExecReply{Output: []runrtest.SandboxChunk{{Stream: "stdout", Data: append([]byte("bin:"), 0xff)}}}
	})
	c := client(t, srv)
	ctx := context.Background()

	res, err := c.ExecSandbox(ctx, sb.ID, runr.SandboxExecInput{Cmd: "cat blob", StdinBase64: runr.EncodeBase64([]byte("in"))})
	if err != nil {
		t.Fatal(err)
	}
	if res.Stdout != "" || string(res.StdoutBytes()) != "bin:\xff" {
		t.Fatalf("exec = %+v", res)
	}
	call := srv.SandboxCalls()[0]
	if strings.Join(call.Cmd, " ") != "/bin/sh -c cat blob" || string(call.Stdin) != "in" || call.Stream {
		t.Fatalf("call = %+v", call)
	}

	run, err := c.RunSandboxCode(ctx, sb.ID, runr.SandboxRunInput{Code: "print(6*7)", Language: "python"})
	if err != nil || run.Stdout != "42\n" || run.Language != "python" || len(run.Outputs) != 1 || len(run.FilesChanged) != 1 {
		t.Fatalf("run = %+v, %v", run, err)
	}
	streamed, err := c.StreamSandboxRun(ctx, sb.ID, runr.SandboxRunInput{Code: "print(6*7)"}, runr.SandboxStream{Stdout: io.Discard})
	if err != nil || streamed.Result == nil || streamed.Result.Outputs[0].Mime != "image/png" {
		t.Fatalf("streamed run = %+v, %v", streamed, err)
	}
}

func TestSandboxCommandOnStoppedSandbox(t *testing.T) {
	srv := runrtest.New(t)
	sb := srv.SeedSandbox("")
	c := client(t, srv)
	ctx := context.Background()
	if _, err := c.DeleteSandbox(ctx, sb.ID); err != nil {
		t.Fatal(err)
	}
	_, err := c.StreamSandboxExec(ctx, sb.ID, runr.SandboxExecInput{Cmd: "ls"}, runr.SandboxStream{})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 409 {
		t.Fatalf("exec on a removed sandbox: %v", err)
	}
}

func TestSandboxStreamErrorEventAndCutStream(t *testing.T) {
	answer := ""
	hs := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(r.Header.Get("Accept"), "text/event-stream") {
			t.Errorf("Accept = %q", r.Header.Get("Accept"))
		}
		w.Header().Set("Content-Type", "text/event-stream; charset=utf-8")
		_, _ = io.WriteString(w, answer)
	}))
	defer hs.Close()
	c, err := runr.New(runr.Options{APIURL: hs.URL, Token: "t", HTTPClient: hs.Client()})
	if err != nil {
		t.Fatal(err)
	}

	answer = "event: start\ndata: {}\n\nevent: error\ndata: {\"message\":\"the cluster refused the call\",\"reason\":\"cluster_error\",\"status\":502}\n\n"
	_, err = c.StreamSandboxExec(context.Background(), "s1", runr.SandboxExecInput{Cmd: "ls"}, runr.SandboxStream{})
	var ae *runr.APIError
	if !errors.As(err, &ae) || ae.StatusCode != 502 || !strings.Contains(ae.Message, "cluster_error") {
		t.Fatalf("error event: %v", err)
	}

	answer = "event: start\ndata: {}\n\nevent: stdout\ndata: {\"data\":\"partial\"}\n\n"
	_, err = c.StreamSandboxExec(context.Background(), "s1", runr.SandboxExecInput{Cmd: "ls"}, runr.SandboxStream{Stdout: io.Discard})
	if err == nil || !strings.Contains(err.Error(), "ended before the command exited") {
		t.Fatalf("cut stream: %v", err)
	}
}

func TestSandboxTraceHidesEnvAndStreamedOutput(t *testing.T) {
	srv := runrtest.New(t)
	srv.SetSandboxExec(func(runrtest.SandboxExecCall) runrtest.SandboxExecReply {
		return runrtest.SandboxExecReply{Output: []runrtest.SandboxChunk{{Stream: "stdout", Data: []byte("top-secret-output")}}}
	})
	var trace bytes.Buffer
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	s, err := c.CreateSandbox(ctx, runr.CreateSandboxInput{Env: map[string]string{"API_KEY": "sk-live-value"}})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.StreamSandboxExec(ctx, s.ID, runr.SandboxExecInput{Cmd: "env", Env: map[string]string{"PASS": "hunter2"}},
		runr.SandboxStream{Stdout: io.Discard}); err != nil {
		t.Fatal(err)
	}
	for _, secret := range []string{"sk-live-value", "hunter2", "top-secret-output"} {
		if strings.Contains(trace.String(), secret) {
			t.Fatalf("the trace shows %q:\n%s", secret, trace.String())
		}
	}
	if !strings.Contains(trace.String(), "< event: stdout") {
		t.Fatalf("the trace does not name the events:\n%s", trace.String())
	}
}

func TestSandboxFiles(t *testing.T) {
	srv := runrtest.New(t)
	sb := srv.SeedSandbox("python")
	c := client(t, srv)
	ctx := context.Background()

	w, err := c.WriteSandboxFile(ctx, sb.ID, "/workspace/run.sh", []byte("echo hi\n"), "0755")
	if err != nil || w.Path != "/workspace/run.sh" || w.Size != 8 {
		t.Fatalf("write = %+v, %v", w, err)
	}
	if call := srv.CallsTo("PUT /sandboxes/{sandbox}/files/content")[0]; call.Query != "mode=0755&path=%2Fworkspace%2Frun.sh" {
		t.Fatalf("write query = %q", call.Query)
	}
	var buf bytes.Buffer
	if _, err := c.ReadSandboxFile(ctx, sb.ID, "/workspace/run.sh", &buf); err != nil || buf.String() != "echo hi\n" {
		t.Fatalf("read = %q, %v", buf.String(), err)
	}

	var tarball bytes.Buffer
	tw := tar.NewWriter(&tarball)
	for name, content := range map[string]string{"a.txt": "A", "lib/b.txt": "BB"} {
		_ = tw.WriteHeader(&tar.Header{Name: name, Typeflag: tar.TypeReg, Mode: 0o644, Size: int64(len(content))})
		_, _ = tw.Write([]byte(content))
	}
	_ = tw.Close()
	up, err := c.UploadSandboxArchive(ctx, sb.ID, "/workspace/src", tarball.Bytes())
	if err != nil || up.Bytes != int64(tarball.Len()) {
		t.Fatalf("upload = %+v, %v", up, err)
	}
	if b, ok := srv.SandboxFile(sb.ID, "/workspace/src/lib/b.txt"); !ok || string(b) != "BB" {
		t.Fatalf("extracted = %q %v", b, ok)
	}

	l, err := c.SandboxFiles(ctx, sb.ID, "/workspace/src")
	if err != nil || !l.IsDir || len(l.Entries) != 2 || l.Entries[0].Type != "dir" || l.Entries[1].Name != "a.txt" {
		t.Fatalf("listing = %+v, %v", l, err)
	}
	f, err := c.SandboxFiles(ctx, sb.ID, "/workspace/run.sh")
	if err != nil || f.IsDir || f.Entries[0].Mode != "0755" {
		t.Fatalf("file listing = %+v, %v", f, err)
	}

	var down bytes.Buffer
	d, err := c.DownloadSandboxArchive(ctx, sb.ID, "/workspace/src", &down)
	if err != nil || d.ContentType != "application/x-tar" || d.Filename != "src.tar" {
		t.Fatalf("download = %+v, %v", d, err)
	}
	names := map[string]string{}
	tr := tar.NewReader(&down)
	for {
		h, err := tr.Next()
		if err != nil {
			break
		}
		b, _ := io.ReadAll(tr)
		names[h.Name] = string(b)
	}
	if names["./a.txt"] != "A" || names["./lib/b.txt"] != "BB" {
		t.Fatalf("archive = %v", names)
	}
}
