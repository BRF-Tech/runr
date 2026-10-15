package runr_test

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
	"github.com/runrsh/core/cli/pkg/runr/runrtest"
)

func modelWait() runr.WaitOptions {
	return runr.WaitOptions{Timeout: 5 * time.Second, Interval: time.Millisecond}
}

func modelRegionID(t *testing.T, srv *runrtest.Server) string {
	t.Helper()
	r, err := client(t, srv).FindRegion(context.Background(), "eu-1")
	if err != nil {
		t.Fatal(err)
	}
	return r.ID
}

// modelDir writes files (relative path → content) into a new directory.
func modelDir(t *testing.T, files map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	for name, content := range files {
		p := filepath.Join(dir, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return dir
}

func TestScanModelDir(t *testing.T) {
	dir := modelDir(t, map[string]string{
		"config.json":            `{"hidden_size": 384}`,
		"1_Pooling/config.json":  `{"pooling_mode_mean_tokens": true}`,
		"model.safetensors":      strings.Repeat("w", 100),
		".git/HEAD":              "ref: refs/heads/main\n",
		".cache/huggingface/x":   "cached",
		"empty.txt":              "",
		"tokenizer/vocab.txt":    "[PAD]\n[UNK]\n",
		"tokenizer/.gitkeep.txt": "k",
	})
	files, skipped, err := runr.ScanModelDir(dir, true)
	if err != nil {
		t.Fatal(err)
	}
	var paths []string
	for _, f := range files {
		paths = append(paths, f.Path)
	}
	want := "1_Pooling/config.json,config.json,model.safetensors,tokenizer/.gitkeep.txt,tokenizer/vocab.txt"
	if strings.Join(paths, ",") != want {
		t.Fatalf("paths = %v, want %s", paths, want)
	}
	if len(skipped) != 1 || skipped[0] != "empty.txt" {
		t.Fatalf("skipped = %v", skipped)
	}
	sum := sha256.Sum256([]byte(strings.Repeat("w", 100)))
	for _, f := range files {
		if f.Path == "model.safetensors" && (f.Size != 100 || f.SHA256 != hex.EncodeToString(sum[:])) {
			t.Fatalf("model.safetensors = %+v", f)
		}
	}
	files, _, _ = runr.ScanModelDir(dir, false)
	if files[0].SHA256 != "" {
		t.Fatal("sha256 computed without withSHA")
	}
	if _, _, err := runr.ScanModelDir(filepath.Join(dir, "config.json"), false); err == nil {
		t.Fatal("a file was accepted as a model directory")
	}
}

func TestModelLibrary(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()

	hits, next, err := c.SearchHub(ctx, runr.HubSearch{Search: "minilm"})
	if err != nil || len(hits) != 1 || hits[0].ID != "sentence-transformers/all-MiniLM-L6-v2" || next != "" {
		t.Fatalf("search = %+v %q %v", hits, next, err)
	}
	page, next, err := c.SearchHub(ctx, runr.HubSearch{Task: "text-generation", Limit: 1})
	if err != nil || len(page) != 1 || next != "1" {
		t.Fatalf("first page = %+v %q %v", page, next, err)
	}
	page2, next2, err := c.SearchHub(ctx, runr.HubSearch{Task: "text-generation", Limit: 1, Cursor: next})
	if err != nil || len(page2) != 1 || page2[0].ID == page[0].ID || next2 != "" {
		t.Fatalf("second page = %+v %q %v", page2, next2, err)
	}

	info, err := c.HubInfo(ctx, "meta-llama/Llama-3.1-8B-Instruct", "", modelRegionID(t, srv))
	if err != nil || !info.Gated || len(info.Engines) == 0 || len(info.Warnings) != 1 || info.Warnings[0] != "gated_needs_token" {
		t.Fatalf("info = %+v %v", info, err)
	}
	if q := srv.CallsTo("GET /models/hub/info")[0].Query; !strings.Contains(q, "knative_region_id=") || strings.Contains(q, "revision=") {
		t.Fatalf("info query = %s", q)
	}
	if _, err := c.HubInfo(ctx, "nobody/nothing", "", ""); !runr.IsNotFound(err) {
		t.Fatalf("unknown hub model: %v", err)
	}

	m, err := c.AddModel(ctx, runr.AddModelInput{HubID: "sentence-transformers/all-MiniLM-L6-v2"})
	if err != nil {
		t.Fatal(err)
	}
	if m.Name != "all-minilm-l6-v2" || m.Source != runr.ModelSourceHub || m.Revision != "main" || m.RevisionSHA == "" {
		t.Fatalf("added = %+v", m)
	}
	if body := srv.CallsTo("POST /models")[0].Body; body["source"] != "hub" || body["hub_id"] != "sentence-transformers/all-MiniLM-L6-v2" {
		t.Fatalf("add body = %v", body)
	}
	for _, ref := range []string{m.ID, m.Name, m.HubID} {
		got, err := c.FindModel(ctx, ref)
		if err != nil || got.ID != m.ID {
			t.Fatalf("FindModel(%s) = %+v %v", ref, got, err)
		}
	}
	var nf *runr.NotFoundError
	if _, err := c.FindModel(ctx, "ghost"); !errors.As(err, &nf) {
		t.Fatalf("ghost: %v", err)
	}
	if _, err := c.AddModel(ctx, runr.AddModelInput{HubID: "nobody/nothing"}); !runr.IsValidation(err) {
		t.Fatalf("unknown hub id: %v", err)
	}
	if _, err := c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: "bare"}); !runr.IsValidation(err) {
		t.Fatalf("upload without task: %v", err)
	}

	renamed := "minilm"
	m2, err := c.UpdateModel(ctx, m.ID, runr.UpdateModelInput{Name: &renamed})
	if err != nil || m2.Name != "minilm" {
		t.Fatalf("rename = %+v %v", m2, err)
	}
	if err := c.DeleteModel(ctx, m.ID); err != nil {
		t.Fatal(err)
	}
	if list, err := c.ListModels(ctx); err != nil || len(list) != 0 {
		t.Fatalf("models after delete = %+v %v", list, err)
	}
}

func TestModelSnapshots(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	m := srv.SeedModel("sentence-transformers/all-MiniLM-L6-v2")
	rid := modelRegionID(t, srv)
	srv.Models().SnapshotPolls = 2

	s, err := c.CreateSnapshot(ctx, m.ID, rid)
	if err != nil || s.Status != runr.SnapshotPending || s.RegionName != "eu-1" {
		t.Fatalf("snapshot = %+v %v", s, err)
	}
	var seen []string
	opts := modelWait()
	opts.Progress = func(st string) { seen = append(seen, st) }
	ready, err := c.WaitSnapshot(ctx, m.ID, s.ID, opts)
	if err != nil || ready.Status != runr.SnapshotReady || ready.FilesCount == 0 {
		t.Fatalf("wait = %+v %v", ready, err)
	}
	if strings.Join(seen, ",") != "running,ready" {
		t.Fatalf("progress = %v", seen)
	}
	again, err := c.CreateSnapshot(ctx, m.ID, rid)
	if err != nil || again.ID != s.ID || again.Status != runr.SnapshotReady {
		t.Fatalf("snapshot of a ready copy = %+v %v", again, err)
	}
	if logs, err := c.SnapshotLogs(ctx, m.ID, s.ID); err != nil || logs == "" {
		t.Fatalf("logs = %q %v", logs, err)
	}
	if err := c.DeleteSnapshot(ctx, m.ID, s.ID); err != nil {
		t.Fatal(err)
	}

	srv.Models().FailSnapshot = true
	s, _ = c.CreateSnapshot(ctx, m.ID, rid)
	_, err = c.WaitSnapshot(ctx, m.ID, s.ID, modelWait())
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.SnapshotFailed || !strings.Contains(err.Error(), "gated") {
		t.Fatalf("failed mirror: %v", err)
	}
}

func TestModelUploadInChunks(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.Models().ChunkSize = 16
	srv.Models().UploadPreparePolls = 2
	content := map[string]string{
		"config.json":           `{"architectures": ["BertModel"], "hidden_size": 384}`,
		"model.safetensors":     strings.Repeat("0123456789", 7),
		"1_Pooling/config.json": `{"word_embedding_dimension": 384}`,
	}
	files, _, err := runr.ScanModelDir(modelDir(t, content), true)
	if err != nil {
		t.Fatal(err)
	}
	m, err := c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: "minilm", Task: "feature-extraction"})
	if err != nil {
		t.Fatal(err)
	}
	var progress []runr.UploadProgress
	var started string
	up, snap, err := c.UploadModel(ctx, m.ID, modelRegionID(t, srv), files, runr.UploadOptions{
		Ready:    modelWait(),
		Started:  func(u *runr.ModelUpload) { started = u.ID },
		Progress: func(p runr.UploadProgress) { progress = append(progress, p) },
	})
	if err != nil {
		t.Fatal(err)
	}
	if up.Status != runr.UploadCompleted || up.ID != started || snap.Status != runr.SnapshotReady || snap.FilesCount != 3 {
		t.Fatalf("upload = %+v snapshot = %+v", up, snap)
	}
	for p, want := range content {
		if got := string(srv.UploadedModelFile("minilm", p)); got != want {
			t.Fatalf("%s arrived as %q, want %q", p, got, want)
		}
	}
	start := srv.CallsTo("POST /models/{model}/uploads")[0].Body
	list, _ := start["files"].([]any)
	if len(list) != 3 {
		t.Fatalf("files sent = %v", start["files"])
	}
	for _, f := range list {
		e := f.(map[string]any)
		if e["sha256"] == "" || e["sha256"] == nil {
			t.Fatalf("file without sha256: %v", e)
		}
	}
	chunks := srv.CallsTo("PUT /models/{model}/uploads/{upload}/chunk")
	wantChunks := 0
	for _, s := range content {
		wantChunks += (len(s) + 15) / 16
	}
	if len(chunks) != wantChunks {
		t.Fatalf("%d chunks, want %d", len(chunks), wantChunks)
	}
	for _, ch := range chunks {
		if len(ch.Raw) == 0 || len(ch.Raw) > 16 || ch.Body != nil || !strings.Contains(ch.Query, "offset=") {
			t.Fatalf("chunk = %+v", ch)
		}
	}
	last := progress[len(progress)-1]
	if last.Done != last.Total || last.Files != 3 {
		t.Fatalf("last progress = %+v", last)
	}
	if got := len(srv.CallsTo("GET /models/{model}/uploads/{upload}")); got != 3 {
		t.Fatalf("%d readiness polls, want 3 (preparing, preparing, ready)", got)
	}
}

func TestModelUploadResumeAndAbort(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.Models().ChunkSize = 10
	srv.Models().UploadPreparePolls = 0
	weights := strings.Repeat("abcdefghij", 5)
	dir := modelDir(t, map[string]string{"model.safetensors": weights, "config.json": `{"a": 1}`})
	files, _, _ := runr.ScanModelDir(dir, true)
	m, _ := c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: "resume", Task: "feature-extraction"})
	rid := modelRegionID(t, srv)

	// The first run stops after two chunks (Ctrl+C): the upload stays open.
	cctx, cancel := context.WithCancel(ctx)
	n := 0
	up, _, err := c.UploadModel(cctx, m.ID, rid, files, runr.UploadOptions{Ready: modelWait(), Progress: func(runr.UploadProgress) {
		if n++; n == 2 {
			cancel()
		}
	}})
	if !errors.Is(err, context.Canceled) || up == nil {
		t.Fatalf("interrupted upload: %v %+v", err, up)
	}
	srv.ResetCalls()

	// The resumed run sends what is left only.
	up2, snap, err := c.UploadModel(ctx, m.ID, rid, files, runr.UploadOptions{ResumeID: up.ID, Ready: modelWait()})
	if err != nil || up2.ID != up.ID || snap.Status != runr.SnapshotReady {
		t.Fatalf("resume = %+v %+v %v", up2, snap, err)
	}
	if got := string(srv.UploadedModelFile("resume", "model.safetensors")); got != weights {
		t.Fatalf("weights = %q", got)
	}
	if got := len(srv.CallsTo("PUT /models/{model}/uploads/{upload}/chunk")); got != 4 {
		t.Fatalf("%d chunks after resume, want 4 (config.json and the first chunk of the weights arrived before)", got)
	}
	if len(srv.CallsTo("POST /models/{model}/uploads")) != 0 {
		t.Fatal("resume started a new upload")
	}

	// Resuming with other files is refused; an abort ends an open upload.
	other, _, _ := runr.ScanModelDir(modelDir(t, map[string]string{"other.bin": "x"}), false)
	if _, _, err := c.UploadModel(ctx, m.ID, rid, other, runr.UploadOptions{ResumeID: up.ID}); err == nil {
		t.Fatal("resume with other files was accepted")
	}
	m2, _ := c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: "aborted", Task: "feature-extraction"})
	open, err := c.StartUpload(ctx, m2.ID, rid, files)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.StartUpload(ctx, m2.ID, rid, files); !modelStatusIs(err, 409) {
		t.Fatalf("second open upload: %v", err)
	}
	if err := c.AbortUpload(ctx, m2.ID, open.ID); err != nil {
		t.Fatal(err)
	}
	if u, _ := c.GetUpload(ctx, m2.ID, open.ID); u.Status != runr.UploadAborted {
		t.Fatalf("aborted upload = %+v", u)
	}
}

func TestModelUploadChecksAndCompleteness(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.Models().UploadPreparePolls = 0
	rid := modelRegionID(t, srv)
	m, _ := c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: "check", Task: "feature-extraction"})
	files := []runr.LocalModelFile{{Path: "a.bin", Size: 4, SHA256: strings.Repeat("0", 64)}}
	up, err := c.StartUpload(ctx, m.ID, rid, files)
	if err != nil {
		t.Fatal(err)
	}
	if _, _, err := c.CompleteUpload(ctx, m.ID, up.ID); !runr.IsValidation(err) || !strings.Contains(err.Error(), "a.bin") {
		t.Fatalf("complete of an incomplete upload: %v", err)
	}
	if n, err := c.UploadChunk(ctx, m.ID, up.ID, "a.bin", 0, []byte("abcd")); err != nil || n != 4 {
		t.Fatalf("chunk = %d %v", n, err)
	}
	if _, err := c.UploadChunk(ctx, m.ID, up.ID, "a.bin", 2, []byte("abcd")); !runr.IsValidation(err) {
		t.Fatalf("chunk beyond the end: %v", err)
	}
	if _, _, err := c.CompleteUpload(ctx, m.ID, up.ID); !runr.IsValidation(err) || !strings.Contains(err.Error(), "sha256") {
		t.Fatalf("wrong checksum: %v", err)
	}
	hub := srv.SeedModel("sentence-transformers/all-MiniLM-L6-v2")
	if _, err := c.StartUpload(ctx, hub.ID, rid, files); !runr.IsValidation(err) {
		t.Fatalf("upload into a hub model: %v", err)
	}
}

func TestModelServiceLifecycle(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	rid := modelRegionID(t, srv)
	srv.Models().ServicePolls = 2

	min := 1
	env := map[string]string{"LOG_LEVEL": "debug"}
	svc, err := c.CreateModelService(ctx, runr.CreateModelServiceInput{HubID: "sentence-transformers/all-MiniLM-L6-v2", RegionID: rid,
		ModelServiceSettings: runr.ModelServiceSettings{MinScale: &min, EnvVars: &env}})
	if err != nil {
		t.Fatal(err)
	}
	if svc.Status != runr.ModelServiceDeploying || svc.Engine != "tei" || svc.Device != "cpu" || svc.MinScale != 1 ||
		svc.Model == nil || svc.Model.HubID != "sentence-transformers/all-MiniLM-L6-v2" || svc.EnvVars["LOG_LEVEL"] != "debug" {
		t.Fatalf("created = %+v", svc)
	}
	body := srv.CallsTo("POST /model-services")[0].Body
	if body["hub_id"] != "sentence-transformers/all-MiniLM-L6-v2" || body["min_scale"] != float64(1) || body["deploy"] != nil || body["ai_model_id"] != nil {
		t.Fatalf("create body = %v", body)
	}
	ready, err := c.WaitModelService(ctx, svc.ID, modelWait())
	if err != nil || ready.Status != runr.ModelServiceReady || ready.URL == "" || ready.OpenAIBase == "" || ready.Playground == nil {
		t.Fatalf("ready = %+v %v", ready, err)
	}
	if found, err := c.FindModelService(ctx, svc.Name); err != nil || found.ID != svc.ID {
		t.Fatalf("FindModelService = %+v %v", found, err)
	}
	if list, err := c.ListModelServices(ctx, rid); err != nil || len(list) != 1 {
		t.Fatalf("list = %+v %v", list, err)
	}
	lists := srv.CallsTo("GET /model-services")
	if q := lists[len(lists)-1].Query; q != "knative_region_id="+rid {
		t.Fatalf("list query = %s", q)
	}

	res, err := c.InvokeModelService(ctx, svc.ID, runr.InvokeInput{Path: "/v1/embeddings", Body: json.RawMessage(`{"input":["hello"]}`)})
	if err != nil || res.Status != 200 || !strings.Contains(res.BodyText(), "embedding") {
		t.Fatalf("invoke = %+v %v", res, err)
	}
	if in := srv.LastModelInvoke(); in["path"] != "/v1/embeddings" {
		t.Fatalf("invoke body = %v", in)
	}
	if _, err := c.InvokeModelService(ctx, svc.ID, runr.InvokeInput{Path: "/admin"}); !runr.IsValidation(err) {
		t.Fatalf("invoke of a path the engine does not serve: %v", err)
	}
	pods, err := c.ModelServiceLogs(ctx, svc.ID, 50)
	if err != nil || len(pods) != 1 || !pods[0].Ready {
		t.Fatalf("logs = %+v %v", pods, err)
	}
	if q := srv.CallsTo("GET /model-services/{service}/logs")[0].Query; q != "tail=50" {
		t.Fatalf("logs query = %s", q)
	}

	max := 3
	upd, err := c.UpdateModelService(ctx, svc.ID, runr.ModelServiceSettings{MaxScale: &max})
	if err != nil || upd.MaxScale != 3 || upd.Status != runr.ModelServiceDeploying {
		t.Fatalf("update = %+v %v", upd, err)
	}
	if b := srv.CallsTo("PATCH /model-services/{service}")[0].Body; len(b) != 1 || b["max_scale"] != float64(3) {
		t.Fatalf("update body = %v", b)
	}
	stopped, err := c.StopModelService(ctx, svc.ID)
	if err != nil || stopped.Status != runr.ModelServiceStopped {
		t.Fatalf("stop = %+v %v", stopped, err)
	}
	if _, err := c.InvokeModelService(ctx, svc.ID, runr.InvokeInput{Path: "/v1/embeddings"}); !modelStatusIs(err, 502) {
		t.Fatalf("invoke of a stopped service: %v", err)
	}
	started, err := c.DeployModelService(ctx, svc.ID)
	if err != nil || started.Status != runr.ModelServiceDeploying {
		t.Fatalf("deploy = %+v %v", started, err)
	}
	if err := c.DeleteModelService(ctx, svc.ID); err != nil {
		t.Fatal(err)
	}
	if _, err := c.GetModelService(ctx, svc.ID); !runr.IsNotFound(err) {
		t.Fatalf("deleted service: %v", err)
	}
}

func TestModelServiceFailsAndTimesOut(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	rid := modelRegionID(t, srv)
	srv.Models().FailService = true
	svc, err := c.CreateModelService(ctx, runr.CreateModelServiceInput{HubID: "Qwen/Qwen2.5-0.5B-Instruct", RegionID: rid})
	if err != nil {
		t.Fatal(err)
	}
	if svc.Device != "gpu" || svc.Engine != "vllm" || svc.GPU == nil || svc.GPU.Count != 1 || svc.API != "openai" {
		t.Fatalf("text generation = %+v", svc)
	}
	_, err = c.WaitModelService(ctx, svc.ID, modelWait())
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.ModelServiceFailed || !strings.Contains(err.Error(), "deadline") {
		t.Fatalf("failed service: %v", err)
	}

	srv.Models().FailService = false
	srv.Models().ServicePolls = 1 << 20
	svc2, _ := c.CreateModelService(ctx, runr.CreateModelServiceInput{HubID: "Qwen/Qwen2.5-0.5B-Instruct", RegionID: rid, Name: "slow"})
	_, err = c.WaitModelService(ctx, svc2.ID, runr.WaitOptions{Timeout: 20 * time.Millisecond, Interval: time.Millisecond})
	var te *runr.TimeoutError
	if !errors.As(err, &te) {
		t.Fatalf("slow service: %v", err)
	}

	cpu := "cpu"
	if _, err := c.CreateModelService(ctx, runr.CreateModelServiceInput{HubID: "Qwen/Qwen2.5-0.5B-Instruct", RegionID: rid,
		Name: "bad", ModelServiceSettings: runr.ModelServiceSettings{Engine: modelStrp("vllm"), Device: &cpu}}); !runr.IsValidation(err) {
		t.Fatalf("vllm on cpu: %v", err)
	}
	upload, _ := c.AddModel(ctx, runr.AddModelInput{Source: runr.ModelSourceUpload, Name: "local", Task: "feature-extraction"})
	if _, err := c.CreateModelService(ctx, runr.CreateModelServiceInput{AIModelID: upload.ID, RegionID: rid}); !runr.IsValidation(err) {
		t.Fatalf("online mode of an uploaded model: %v", err)
	}
}

func TestModelServiceOfflineWaitsForSnapshot(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	m := srv.SeedModel("sentence-transformers/all-MiniLM-L6-v2")
	offline := "offline"
	svc, err := c.CreateModelService(ctx, runr.CreateModelServiceInput{AIModelID: m.ID, RegionID: modelRegionID(t, srv),
		ModelServiceSettings: runr.ModelServiceSettings{Mode: &offline}})
	if err != nil || svc.Status != runr.ModelServiceWaitingSnapshot {
		t.Fatalf("offline service = %+v %v", svc, err)
	}
	var seen []string
	opts := modelWait()
	opts.Progress = func(s string) { seen = append(seen, strings.SplitN(s, ":", 2)[0]) }
	ready, err := c.WaitModelService(ctx, svc.ID, opts)
	if err != nil || ready.Status != runr.ModelServiceReady {
		t.Fatalf("offline ready = %+v %v", ready, err)
	}
	if seen[0] != runr.ModelServiceWaitingSnapshot || seen[len(seen)-1] != runr.ModelServiceReady {
		t.Fatalf("progress = %v", seen)
	}
	if got, _ := c.GetModel(ctx, m.ID); got.SnapshotIn(svc.RegionID) == nil || got.SnapshotIn(svc.RegionID).Status != runr.SnapshotReady {
		t.Fatalf("snapshot of the offline service = %+v", got.Snapshots)
	}
}

func TestProjectModelBindings(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	srv.AddRegion("us-1", true)
	p := srv.SeedProject("app", runr.TypeFaaS, "python", "eu-1", nil)
	srv.SeedModel("sentence-transformers/all-MiniLM-L6-v2")
	svc := srv.SeedModelService("minilm", "all-minilm-l6-v2", "eu-1", runr.ModelServiceReady)
	far := srv.SeedModelService("far", "all-minilm-l6-v2", "us-1", runr.ModelServiceReady)

	list, avail, err := c.ProjectModels(ctx, p.ID)
	if err != nil || len(list) != 0 || len(avail) != 1 || avail[0].ID != svc.ID {
		t.Fatalf("project models = %+v %+v %v", list, avail, err)
	}
	b, err := c.BindModel(ctx, p.ID, runr.BindModelInput{ServiceID: svc.ID})
	if err != nil || b.Alias != "MINILM" || b.Env["RUNR_MODEL_MINILM_URL"] == "" || b.Env["RUNR_MODEL_MINILM_API"] != "tei" || !b.AppliesOnNextDeploy {
		t.Fatalf("binding = %+v %v", b, err)
	}
	if _, err := c.BindModel(ctx, p.ID, runr.BindModelInput{ServiceID: far.ID}); !runr.IsValidation(err) {
		t.Fatalf("binding a service of another region: %v", err)
	}
	if _, err := c.BindModel(ctx, p.ID, runr.BindModelInput{ServiceID: svc.ID, Alias: "OTHER"}); !runr.IsValidation(err) {
		t.Fatalf("binding a service twice: %v", err)
	}
	mount := true
	if _, err := c.UpdateModelBinding(ctx, p.ID, b.ID, nil, &mount); !runr.IsValidation(err) {
		t.Fatalf("mount weights without an offline copy: %v", err)
	}
	alias := "EMBED"
	b2, err := c.UpdateModelBinding(ctx, p.ID, b.ID, &alias, nil)
	if err != nil || b2.Alias != "EMBED" || b2.Env["RUNR_MODEL_EMBED_URL"] == "" {
		t.Fatalf("renamed binding = %+v %v", b2, err)
	}
	if body := srv.CallsTo("PATCH /projects/{project}/models/{modelBinding}")[1].Body; len(body) != 1 || body["alias"] != "EMBED" {
		t.Fatalf("update body = %v", body)
	}
	if used, err := c.ModelServiceBindings(ctx, svc.ID); err != nil || len(used) != 1 || used[0].Project == nil || used[0].Project.Name != "app" {
		t.Fatalf("service bindings = %+v %v", used, err)
	}
	if err := c.DeleteModelService(ctx, svc.ID); !modelStatusIs(err, 409) {
		t.Fatalf("delete of a bound service: %v", err)
	}
	list, _, _ = c.ProjectModels(ctx, p.ID)
	for _, ref := range []string{"EMBED", "embed", "minilm", svc.ID, b.ID} {
		if got, err := runr.FindModelBinding(list, ref); err != nil || got.ID != b.ID {
			t.Fatalf("FindModelBinding(%s) = %+v %v", ref, got, err)
		}
	}
	if err := c.UnbindModel(ctx, p.ID, b.ID); err != nil {
		t.Fatal(err)
	}
	if got := srv.ModelBindings("app"); len(got) != 0 {
		t.Fatalf("bindings after unbind = %+v", got)
	}
}

func TestHubTokenAndEngines(t *testing.T) {
	srv := runrtest.New(t)
	ctx := context.Background()
	var trace bytes.Buffer
	c, err := runr.New(runr.Options{APIURL: srv.URL, Token: runrtest.DefaultToken, HTTPClient: srv.Client(), Debug: &trace})
	if err != nil {
		t.Fatal(err)
	}
	st, err := c.HubToken(ctx)
	if err != nil || st.Configured {
		t.Fatalf("token = %+v %v", st, err)
	}
	const secret = "hf_SuperSecretValue1234"
	st, err = c.SetHubToken(ctx, secret, true)
	if err != nil || !st.Configured || st.Hint != "hf_…1234" || srv.HubTokenValue() != secret {
		t.Fatalf("set = %+v %v", st, err)
	}
	if strings.Contains(trace.String(), secret) {
		t.Fatal("the Hugging Face token reached the debug trace")
	}
	if _, err := c.SetHubToken(ctx, "hf_invalid_token", true); !runr.IsValidation(err) {
		t.Fatalf("rejected token: %v", err)
	}
	if err := c.DeleteHubToken(ctx); err != nil || srv.HubTokenValue() != "" {
		t.Fatalf("delete: %v", err)
	}
	cat, err := c.ModelEngines(ctx)
	if err != nil || cat.Engines["vllm"].API != "openai" || len(cat.Tasks) == 0 {
		t.Fatalf("engines = %+v %v", cat, err)
	}
	for _, task := range cat.Tasks {
		if task.Task == "text-to-image" && (task.Default["cpu"] != "" || task.Default["gpu"] != "diffusers") {
			t.Fatalf("text-to-image defaults = %v", task.Default)
		}
	}
}

func TestModelPermissions(t *testing.T) {
	srv := runrtest.New(t)
	viewer, err := runr.New(runr.Options{APIURL: srv.URL, Token: srv.ScopedToken("viewer", "models.view"), HTTPClient: srv.Client(), Retries: -1})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	if _, err := viewer.ListModels(ctx); err != nil {
		t.Fatalf("models.view lists models: %v", err)
	}
	_, err = viewer.CreateModelService(ctx, runr.CreateModelServiceInput{HubID: "Qwen/Qwen2.5-0.5B-Instruct", RegionID: modelRegionID(t, srv)})
	if perms := runr.MissingPermissions(err); len(perms) != 1 || perms[0] != "models.deploy" {
		t.Fatalf("deploy without models.deploy: %v (%v)", err, perms)
	}
	if err := viewer.DeleteHubToken(ctx); len(runr.MissingPermissions(err)) == 0 {
		t.Fatalf("hub token without models.manage: %v", err)
	}
}

func TestFlexInt64AndModelDecoding(t *testing.T) {
	var m runr.AIModel
	raw := `{"id":"01J","name":"x","source":"hub","size_bytes":"90868376","parameters":null,"gated":false,"private":false,
		"tags":[],"snapshots":[],"services_count":0}`
	if err := json.Unmarshal([]byte(raw), &m); err != nil || m.SizeBytes != 90868376 || m.Parameters != 0 {
		t.Fatalf("decode = %+v %v", m, err)
	}
	var s runr.ModelService
	raw = `{"id":"01J","name":"x","ai_model_id":"01K","knative_region_id":"r","engine":"tei","device":"cpu","mode":"online",
		"gpu":null,"args":null,"env_vars":[],"port":null,"min_scale":0,"max_scale":1,"scale_to_zero":true,"status":"ready",
		"bindings_count":0,"playground":{"kind":"embeddings","path":"/v1/embeddings"}}`
	if err := json.Unmarshal([]byte(raw), &s); err != nil || s.GPU != nil || len(s.EnvVars) != 0 || s.Playground.Path != "/v1/embeddings" {
		t.Fatalf("decode service = %+v %v", s, err)
	}
	var r runr.InvokeResult
	if err := json.Unmarshal([]byte(`{"status":200,"duration_ms":3,"content_type":"text/plain","body":"hi"}`), &r); err != nil || r.BodyText() != "hi" {
		t.Fatalf("text body = %q %v", r.BodyText(), err)
	}
}

func modelStrp(s string) *string { return &s }

func modelStatusIs(err error, code int) bool {
	var ae *runr.APIError
	return errors.As(err, &ae) && ae.StatusCode == code
}
