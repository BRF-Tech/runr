package cmd

import (
	"path/filepath"
	"strings"
	"testing"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

const modelHubMiniLM = "sentence-transformers/all-MiniLM-L6-v2"

// modelHarness returns a logged-in harness with the MiniLM model in the library and a ready
// model service "minilm" of it in eu-1.
func modelHarness(t *testing.T) *harness {
	t.Helper()
	h := newHarness(t).login()
	h.srv.SeedModel(modelHubMiniLM)
	h.srv.SeedModelService("minilm", "all-minilm-l6-v2", "eu-1", runr.ModelServiceReady)
	return h
}

func TestModelsSearchInfoAndEngines(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("models", "search", "--task", "text-generation", "--limit", "1")
	contains(t, r.out, "meta-llama/Llama-3.1-8B-Instruct")
	contains(t, r.out, "--cursor 1")
	if q := h.srv.CallsTo("GET /models/hub/search")[0].Query; !strings.Contains(q, "task=text-generation") || !strings.Contains(q, "limit=1") {
		t.Fatalf("search query = %s", q)
	}
	var page struct {
		Data       []runr.HubModel `json:"data"`
		NextCursor string          `json:"next_cursor"`
	}
	decodeJSON(t, h.mustRun("models", "search", "minilm", "-o", "json").out, &page)
	if len(page.Data) != 1 || page.Data[0].ID != modelHubMiniLM || page.NextCursor != "" {
		t.Fatalf("search json = %+v", page)
	}
	h.expectCode(ExitUsage, "models", "search", "--sort", "stars")

	r = h.mustRun("models", "info", "meta-llama/Llama-3.1-8B-Instruct")
	contains(t, r.out, "hub-token set")
	contains(t, r.out, "vllm")
	h.expectCode(ExitNotFound, "models", "info", "nobody/nothing")

	r = h.mustRun("models", "engines")
	contains(t, r.out, "Text Embeddings Inference")
	var cat runr.EngineCatalog
	decodeJSON(t, h.mustRun("models", "engines", "-o", "json").out, &cat)
	if cat.Engines["tei"].API != "tei" {
		t.Fatalf("engines json = %+v", cat)
	}
}

func TestModelsLibrary(t *testing.T) {
	h := newHarness(t).login()
	var m runr.AIModel
	decodeJSON(t, h.mustRun("models", "add", modelHubMiniLM, "--name", "minilm", "-o", "json").out, &m)
	if m.Name != "minilm" || m.HubID != modelHubMiniLM || m.Revision != "main" {
		t.Fatalf("added = %+v", m)
	}
	r := h.mustRun("models", "ls")
	contains(t, r.out, "minilm")
	contains(t, r.out, modelHubMiniLM)
	r = h.mustRun("models", "get", modelHubMiniLM)
	contains(t, r.out, "Revision:")
	contains(t, r.out, m.RevisionSHA)

	h.expectCode(ExitValidation, "models", "add", modelHubMiniLM, "--name", "minilm")
	h.expectCode(ExitUsage, "models", "add")
	h.expectCode(ExitUsage, "models", "add", "--source", "upload", "--name", "bare")
	h.expectCode(ExitUsage, "models", "add", "--source", "ftp", "--task", "x")
	r = h.mustRun("models", "add", "--source", "upload", "--name", "local", "--task", "feature-extraction", "-o", "json")
	contains(t, r.out, `"source": "upload"`)
	r = h.mustRun("models", "add", "--source", "pvc", "--path", "imports/minilm", "--region", "eu-1", "--name", "imported",
		"--task", "feature-extraction")
	contains(t, r.out, "imports/minilm")
	if body := h.srv.CallsTo("POST /models")[len(h.srv.CallsTo("POST /models"))-1].Body; body["knative_region_id"] == nil || body["path"] != "imports/minilm" {
		t.Fatalf("import body = %v", body)
	}

	h.expectCode(ExitUsage, "models", "rm", "minilm")
	h.mustRun("models", "rm", "minilm", "--yes")
	if _, ok := h.srv.AIModel("minilm"); ok {
		t.Fatal("model still in the library")
	}
	h.expectCode(ExitNotFound, "models", "rm", "minilm", "--yes")

	// A model that services use is not removed.
	h.srv.SeedModel(modelHubMiniLM)
	h.srv.SeedModelService("busy", "all-minilm-l6-v2", "eu-1", runr.ModelServiceReady)
	r = h.expectCode(ExitValidation, "models", "rm", "all-minilm-l6-v2", "--yes")
	contains(t, r.err, "busy")
}

func TestModelsDeployWaitsUntilReady(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Models().ServicePolls = 2
	var svc runr.ModelService
	r := h.mustRun("models", "deploy", modelHubMiniLM, "--min-scale", "1", "--max-scale", "2", "-e", "LOG_LEVEL=debug",
		"--arg=--max-batch-tokens", "--arg=2048", "-o", "json")
	decodeJSON(t, r.out, &svc)
	if svc.Status != runr.ModelServiceReady || svc.Engine != "tei" || svc.MinScale != 1 || svc.MaxScale != 2 {
		t.Fatalf("deployed = %+v", svc)
	}
	contains(t, r.err, "deploying")
	body := h.srv.CallsTo("POST /model-services")[0].Body
	if body["hub_id"] != modelHubMiniLM || body["min_scale"] != float64(1) || body["knative_region_id"] == nil {
		t.Fatalf("create body = %v", body)
	}
	if env, _ := body["env_vars"].(map[string]any); env["LOG_LEVEL"] != "debug" {
		t.Fatalf("env_vars = %v", body["env_vars"])
	}
	if args, _ := body["args"].([]any); len(args) != 2 || args[0] != "--max-batch-tokens" {
		t.Fatalf("args = %v", body["args"])
	}
	for _, k := range []string{"mode", "device", "engine", "gpu", "scale_to_zero", "image", "deploy"} {
		if _, sent := body[k]; sent {
			t.Fatalf("%s was sent without its flag: %v", k, body)
		}
	}

	// A model of the library is deployed by name; --no-wait returns at once.
	h.srv.ResetCalls()
	r = h.mustRun("models", "deploy", "all-minilm-l6-v2", "--name", "minilm-2", "--no-wait", "--mode", "offline", "-o", "json")
	decodeJSON(t, r.out, &svc)
	if svc.Status != runr.ModelServiceWaitingSnapshot || svc.Mode != "offline" {
		t.Fatalf("offline service = %+v", svc)
	}
	if body := h.srv.CallsTo("POST /model-services")[0].Body; body["ai_model_id"] == nil || body["hub_id"] != nil {
		t.Fatalf("library deploy body = %v", body)
	}
	if n := len(h.srv.CallsTo("GET /model-services/{service}")); n != 0 {
		t.Fatalf("--no-wait polled %d times", n)
	}

	// --no-start creates the service stopped.
	r = h.mustRun("models", "deploy", "all-minilm-l6-v2", "--name", "later", "--no-start", "-o", "json")
	decodeJSON(t, r.out, &svc)
	if svc.Status != runr.ModelServiceStopped {
		t.Fatalf("--no-start = %+v", svc)
	}
}

func TestModelsDeployGPUAndErrors(t *testing.T) {
	h := newHarness(t).login()
	var svc runr.ModelService
	decodeJSON(t, h.mustRun("models", "deploy", "Qwen/Qwen2.5-0.5B-Instruct", "--gpu", "2", "--gpu-memory", "16000",
		"--engine", "vllm", "-o", "json").out, &svc)
	if svc.Device != "gpu" || svc.GPU == nil || svc.GPU.Count != 2 || svc.GPU.MemoryMin != 16000 {
		t.Fatalf("gpu service = %+v", svc)
	}
	body := h.srv.CallsTo("POST /model-services")[0].Body
	if gpu, _ := body["gpu"].(map[string]any); gpu["count"] != float64(2) || body["device"] != "gpu" {
		t.Fatalf("gpu body = %v", body)
	}

	h.expectCode(ExitUsage, "models", "deploy", "Qwen/Qwen2.5-0.5B-Instruct", "--gpu", "1", "--device", "cpu")
	h.expectCode(ExitUsage, "models", "deploy", "Qwen/Qwen2.5-0.5B-Instruct", "--mode", "sometimes")
	h.expectCode(ExitUsage, "models", "deploy", "Qwen/Qwen2.5-0.5B-Instruct", "--min-scale", "3", "--max-scale", "1")
	h.expectCode(ExitUsage, "models", "deploy", "Qwen/Qwen2.5-0.5B-Instruct", "-e", "PORT=1")
	h.expectCode(ExitValidation, "models", "deploy", "Qwen/Qwen2.5-0.5B-Instruct", "--engine", "vllm", "--device", "cpu", "--name", "cpu-llm")
	h.expectCode(ExitNotFound, "models", "deploy", "no-such-model")
	h.expectCode(ExitNotFound, "models", "deploy", modelHubMiniLM, "--region", "mars-1")

	h.srv.Models().FailService = true
	r := h.expectCode(ExitFailed, "models", "deploy", modelHubMiniLM, "--name", "broken")
	contains(t, r.err, "start-up deadline")

	h.srv.Models().FailService = false
	h.srv.Models().ServicePolls = 1 << 20
	h.expectCode(ExitTimeout, "models", "deploy", modelHubMiniLM, "--name", "slow", "--timeout", "30ms")
}

func TestModelServicesCommands(t *testing.T) {
	h := modelHarness(t)
	r := h.mustRun("models", "services")
	contains(t, r.out, "minilm")
	contains(t, r.out, "ready")
	var list []runr.ModelService
	decodeJSON(t, h.mustRun("models", "services", "ls", "--region", "eu-1", "-o", "json").out, &list)
	if len(list) != 1 || list[0].Model == nil || list[0].Model.HubID != modelHubMiniLM {
		t.Fatalf("services json = %+v", list)
	}
	r = h.mustRun("models", "services", "get", "minilm")
	contains(t, r.out, "tei on cpu")
	contains(t, r.out, "runr models invoke minilm --path /v1/embeddings")

	r = h.mustRun("models", "services", "stop", "minilm")
	contains(t, r.err, "stopped")
	if s, _ := h.srv.ModelService("minilm"); s.Status != runr.ModelServiceStopped {
		t.Fatalf("after stop = %+v", s)
	}
	h.mustRun("models", "services", "start", "minilm")
	if s, _ := h.srv.ModelService("minilm"); s.Status != runr.ModelServiceReady {
		t.Fatalf("after start = %+v", s)
	}

	h.srv.ResetCalls()
	h.mustRun("models", "services", "update", "minilm", "--max-scale", "3", "--no-wait")
	if b := h.srv.CallsTo("PATCH /model-services/{service}")[0].Body; len(b) != 1 || b["max_scale"] != float64(3) {
		t.Fatalf("update body = %v", b)
	}
	h.expectCode(ExitUsage, "models", "services", "update", "minilm")

	h.mustRun("models", "services", "start", "minilm")
	r = h.mustRun("models", "services", "logs", "minilm", "--tail", "20")
	contains(t, r.out, "INFO ready")
	if q := h.srv.CallsTo("GET /model-services/{service}/logs")[0].Query; q != "tail=20" {
		t.Fatalf("logs query = %s", q)
	}

	h.expectCode(ExitUsage, "models", "services", "rm", "minilm")
	h.mustRun("models", "services", "rm", "minilm", "--yes")
	if _, ok := h.srv.ModelService("minilm"); ok {
		t.Fatal("service still exists")
	}
	h.expectCode(ExitNotFound, "models", "services", "get", "minilm")
}

func TestModelsInvoke(t *testing.T) {
	h := modelHarness(t)
	r := h.mustRun("models", "invoke", "minilm", "--data", `{"model": "minilm", "input": ["hello"]}`)
	contains(t, r.out, "embedding")
	contains(t, r.err, "200 application/json")
	in := h.srv.LastModelInvoke()
	if in["path"] != "/v1/embeddings" || in["method"] != nil {
		t.Fatalf("invoke without --path = %v", in)
	}
	if body, _ := in["body"].(map[string]any); body["model"] != "minilm" {
		t.Fatalf("invoke body = %v", in["body"])
	}

	dir := t.TempDir()
	req := filepath.Join(dir, "req.json")
	write(t, req, `{"input": ["from a file"]}`)
	var res runr.InvokeResult
	decodeJSON(t, h.mustRun("models", "invoke", "minilm", "--path", "/embed", "--data", "@"+req, "-o", "json").out, &res)
	if res.Status != 200 || !strings.Contains(string(res.Body), "embedding") {
		t.Fatalf("invoke json = %+v", res)
	}
	r = h.runStdin(`{"input": ["from stdin"]}`, "models", "invoke", "minilm", "--data", "-")
	if r.code != 0 {
		t.Fatalf("invoke from stdin: %d %s", r.code, r.err)
	}
	if body, _ := h.srv.LastModelInvoke()["body"].(map[string]any); body["input"].([]any)[0] != "from stdin" {
		t.Fatalf("stdin body = %v", h.srv.LastModelInvoke())
	}

	h.expectCode(ExitUsage, "models", "invoke", "minilm", "--data", "{not json")
	h.expectCode(ExitUsage, "models", "invoke", "minilm", "--method", "DELETE")
	h.expectCode(ExitValidation, "models", "invoke", "minilm", "--path", "/admin")
	h.mustRun("models", "services", "stop", "minilm")
	h.expectCode(ExitUnreachable, "models", "invoke", "minilm", "--data", `{}`)
}

func TestModelsBindings(t *testing.T) {
	h := modelHarness(t)
	h.srv.SeedProject("app", runr.TypeFaaS, "python", "eu-1", nil)
	h.srv.AddRegion("us-1", true)
	h.srv.SeedModelService("far", "all-minilm-l6-v2", "us-1", runr.ModelServiceReady)

	r := h.mustRun("models", "bindings", "-p", "app")
	contains(t, r.out, "Model services of the region to bind: minilm")

	var b runr.ModelBinding
	r = h.mustRun("models", "bind", "minilm", "--alias", "embed", "-p", "app", "-o", "json")
	decodeJSON(t, r.out, &b)
	if b.Alias != "EMBED" || b.Env["RUNR_MODEL_EMBED_URL"] == "" || b.MountWeights {
		t.Fatalf("binding = %+v", b)
	}
	if body := h.srv.CallsTo("POST /projects/{project}/models")[0].Body; body["alias"] != "EMBED" || body["mount_weights"] != nil {
		t.Fatalf("bind body = %v", body)
	}
	contains(t, r.err, "next deploy")
	r = h.mustRun("models", "bindings", "-p", "app")
	contains(t, r.out, "EMBED")
	notContains(t, r.out, "to bind")
	r = h.mustRun("models", "services", "bindings", "minilm")
	contains(t, r.out, "app")

	h.expectCode(ExitValidation, "models", "bind", "far", "-p", "app")
	h.expectCode(ExitValidation, "models", "bind", "minilm", "--alias", "OTHER", "-p", "app")
	h.expectCode(ExitValidation, "models", "bind", "minilm", "--mount-weights", "-p", "app", "--alias", "WEIGHTS")
	h.expectCode(ExitValidation, "models", "services", "rm", "minilm", "--yes")

	h.mustRun("models", "unbind", "embed", "-p", "app")
	if got := h.srv.ModelBindings("app"); len(got) != 0 {
		t.Fatalf("bindings after unbind = %+v", got)
	}
	h.expectCode(ExitNotFound, "models", "unbind", "EMBED", "-p", "app")
}

func TestModelsSnapshot(t *testing.T) {
	h := newHarness(t).login()
	h.srv.SeedModel(modelHubMiniLM)
	h.srv.Models().SnapshotPolls = 2
	var s runr.ModelSnapshot
	r := h.mustRun("models", "snapshot", "all-minilm-l6-v2", "-o", "json")
	decodeJSON(t, r.out, &s)
	if s.Status != runr.SnapshotReady || s.RegionName != "eu-1" {
		t.Fatalf("snapshot = %+v", s)
	}
	contains(t, r.err, "running")
	r = h.mustRun("models", "snapshots", "all-minilm-l6-v2")
	contains(t, r.out, "eu-1")
	contains(t, r.out, "ready")

	h.srv.SeedHubModel(runr.HubModel{ID: "acme/gated-model", Task: "text-classification", Gated: true})
	h.mustRun("models", "add", "acme/gated-model")
	h.srv.Models().FailSnapshot = true
	r = h.expectCode(ExitFailed, "models", "snapshot", "gated-model")
	contains(t, r.err, "token")

	h.srv.Models().FailSnapshot = false
	h.mustRun("models", "add", "--source", "upload", "--name", "local", "--task", "feature-extraction")
	h.expectCode(ExitValidation, "models", "snapshot", "local")
}

func TestModelsUploadDirectory(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Models().ChunkSize = 16
	files := map[string]string{
		"config.json":           `{"architectures": ["BertModel"], "hidden_size": 384}`,
		"model.safetensors":     strings.Repeat("weights-", 12),
		"1_Pooling/config.json": `{"word_embedding_dimension": 384}`,
		".git/HEAD":             "ref: refs/heads/main\n",
	}
	dir := t.TempDir()
	for name, content := range files {
		write(t, filepath.Join(dir, filepath.FromSlash(name)), content)
	}
	write(t, filepath.Join(dir, "empty.txt"), "")

	var out struct {
		Upload   runr.ModelUpload   `json:"upload"`
		Snapshot runr.ModelSnapshot `json:"snapshot"`
	}
	r := h.mustRun("models", "upload", dir, "--model", "minilm", "--task", "feature-extraction", "--region", "eu-1", "-o", "json")
	decodeJSON(t, r.out, &out)
	if out.Upload.Status != runr.UploadCompleted || out.Snapshot.Status != runr.SnapshotReady || len(out.Upload.Files) != 3 {
		t.Fatalf("upload = %+v", out)
	}
	contains(t, r.err, "Created the model minilm")
	contains(t, r.err, "empty.txt is empty")
	contains(t, r.err, "model.safetensors")
	for name, content := range files {
		if strings.HasPrefix(name, ".git/") {
			continue
		}
		if got := string(h.srv.UploadedModelFile("minilm", name)); got != content {
			t.Fatalf("%s arrived as %q", name, got)
		}
	}
	if b := h.srv.CallsTo("POST /models")[0].Body; b["source"] != "upload" || b["task"] != "feature-extraction" {
		t.Fatalf("model body = %v", b)
	}
	for _, f := range h.srv.CallsTo("POST /models/{model}/uploads")[0].Body["files"].([]any) {
		if p := f.(map[string]any)["path"].(string); strings.HasPrefix(p, ".git") || p == "empty.txt" {
			t.Fatalf("uploaded %s", p)
		}
	}
	m, _ := h.srv.AIModel("minilm")
	if m.SnapshotIn(out.Snapshot.RegionID) == nil {
		t.Fatalf("model snapshots = %+v", m.Snapshots)
	}

	// The model exists now: a second upload needs no --task; --no-checksum sends no sha256.
	h.srv.ResetCalls()
	h.mustRun("models", "upload", dir, "--model", "minilm", "--no-checksum")
	for _, f := range h.srv.CallsTo("POST /models/{model}/uploads")[0].Body["files"].([]any) {
		if _, has := f.(map[string]any)["sha256"]; has {
			t.Fatalf("sha256 sent with --no-checksum: %v", f)
		}
	}

	h.expectCode(ExitUsage, "models", "upload", dir)
	h.expectCode(ExitUsage, "models", "upload", dir, "--model", "unknown-model")
	h.mustRun("models", "add", modelHubMiniLM)
	h.expectCode(ExitUsage, "models", "upload", dir, "--model", "all-minilm-l6-v2")
	empty := t.TempDir()
	h.expectCode(ExitUsage, "models", "upload", empty, "--model", "minilm")
	h.expectCode(ExitError, "models", "upload", filepath.Join(dir, "missing"), "--model", "minilm")
}

func TestModelsUploadResume(t *testing.T) {
	h := newHarness(t).login()
	h.srv.Models().ChunkSize = 8
	h.srv.Models().UploadPreparePolls = 0
	dir := t.TempDir()
	weights := strings.Repeat("0123456789", 4)
	write(t, filepath.Join(dir, "model.safetensors"), weights)
	h.mustRun("models", "add", "--source", "upload", "--name", "minilm", "--task", "feature-extraction")

	// An upload that received the first chunk, then the connection broke.
	c, err := h.app("", nil, nil).Client()
	if err != nil {
		t.Fatal(err)
	}
	m, _ := h.srv.AIModel("minilm")
	local, _, _ := runr.ScanModelDir(dir, true)
	region, _ := c.FindRegion(t.Context(), "eu-1")
	up, err := c.StartUpload(t.Context(), m.ID, region.ID, local)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := c.UploadChunk(t.Context(), m.ID, up.ID, "model.safetensors", 0, []byte(weights[:8])); err != nil {
		t.Fatal(err)
	}
	h.expectCode(ExitValidation, "models", "upload", dir, "--model", "minilm")

	h.srv.ResetCalls()
	h.mustRun("models", "upload", dir, "--model", "minilm", "--resume", up.ID)
	if got := string(h.srv.UploadedModelFile("minilm", "model.safetensors")); got != weights {
		t.Fatalf("weights = %q", got)
	}
	if n := len(h.srv.CallsTo("PUT /models/{model}/uploads/{upload}/chunk")); n != 4 {
		t.Fatalf("%d chunks after resume, want 4", n)
	}
}

func TestModelsHubToken(t *testing.T) {
	h := newHarness(t).login()
	r := h.mustRun("models", "hub-token")
	contains(t, r.out, "Configured:")
	contains(t, r.out, "no")

	const token = "hf_TopSecretToken9876"
	r = h.runStdin(token+"\n", "--debug", "models", "hub-token", "set", "--token-stdin", "--validate")
	if r.code != 0 {
		t.Fatalf("set: %d %s", r.code, r.err)
	}
	notContains(t, r.out+r.err, token)
	contains(t, r.out, "hf_…9876")
	if h.srv.HubTokenValue() != token {
		t.Fatalf("stored token = %q", h.srv.HubTokenValue())
	}
	h.expectCode(ExitUsage, "models", "hub-token", "set")
	r = h.runStdin("hf_invalid_value\n", "models", "hub-token", "set", "--token-stdin", "--validate")
	if r.code != ExitValidation {
		t.Fatalf("rejected token: exit %d", r.code)
	}

	h.terminal = true
	h.secrets = []string{"hf_FromThePrompt1111"}
	h.mustRun("models", "hub-token", "set")
	if h.srv.HubTokenValue() != "hf_FromThePrompt1111" {
		t.Fatalf("prompted token = %q", h.srv.HubTokenValue())
	}
	h.terminal = false

	h.expectCode(ExitUsage, "models", "hub-token", "rm")
	h.mustRun("models", "hub-token", "rm", "--yes")
	if h.srv.HubTokenValue() != "" {
		t.Fatal("token still stored")
	}
}

func TestModelsPermissions(t *testing.T) {
	h := newHarness(t)
	h.loginWith(h.srv.ScopedToken("viewer", "models.view", "regions.view"))
	h.mustRun("models", "ls")
	r := h.expectCode(ExitAuth, "models", "deploy", modelHubMiniLM)
	contains(t, r.err, "models.deploy")
	h.expectCode(ExitAuth, "models", "hub-token", "rm", "--yes")
}
