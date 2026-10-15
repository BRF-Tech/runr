package runrtest

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The fake's models, model services, uploads and project model bindings (api/routes/ai.php):
// a small Hugging Face Hub, the library, snapshots that become ready after ModelFake polls,
// uploads that assemble the chunks they receive, and services that deploy after polls.

// ModelFake tunes the fake's model endpoints; change it through Server.Models before the calls.
type ModelFake struct {
	// ServicePolls is how many GET /model-services/{id} a deploying service stays deploying.
	ServicePolls int
	// FailService makes deploying services end in "failed" with FailMessage.
	FailService bool
	FailMessage string
	// SnapshotPolls is how many reads a mirror stays pending / running.
	SnapshotPolls int
	// FailSnapshot makes mirrors end in "failed".
	FailSnapshot bool
	// UploadPreparePolls is how many GET of an upload it stays "preparing".
	UploadPreparePolls int
	// ChunkSize is the chunk_size of new uploads.
	ChunkSize int64
}

type fakeModelSnapshot struct {
	data      runr.ModelSnapshot
	pollsLeft int
	fails     bool
}

type fakeModel struct {
	data      runr.AIModel
	snapshots []*fakeModelSnapshot
}

type fakeModelService struct {
	data      runr.ModelService
	pollsLeft int
	fails     bool
}

type fakeModelUpload struct {
	data    runr.ModelUpload
	sha     map[string]string
	content map[string][]byte
	polls   int
}

type fakeModelBinding struct {
	data runr.ModelBinding
}

type modelsState struct {
	opts       ModelFake
	hub        []runr.HubModel
	models     []*fakeModel
	services   []*fakeModelService
	uploads    []*fakeModelUpload
	bindings   []*fakeModelBinding
	token      string
	tokenAt    string
	lastInvoke map[string]any
}

func (s *Server) modelState() *modelsState {
	return featureState(s, "models", func() *modelsState {
		return &modelsState{
			opts: ModelFake{ServicePolls: 1, SnapshotPolls: 1, UploadPreparePolls: 1, ChunkSize: 8 << 20,
				FailMessage: "did not become ready within the start-up deadline"},
			hub: []runr.HubModel{
				{ID: "sentence-transformers/all-MiniLM-L6-v2", Author: "sentence-transformers", Task: "sentence-similarity",
					Library: "sentence-transformers", Downloads: 90000000, Likes: 3000, Tags: []string{"sentence-transformers", "pytorch"}},
				{ID: "Qwen/Qwen2.5-0.5B-Instruct", Author: "Qwen", Task: "text-generation", Library: "transformers",
					Downloads: 5000000, Likes: 400, Tags: []string{"transformers", "safetensors", "conversational"}},
				{ID: "meta-llama/Llama-3.1-8B-Instruct", Author: "meta-llama", Task: "text-generation", Library: "transformers",
					Downloads: 7000000, Likes: 4000, Gated: true, Tags: []string{"transformers", "llama"}},
				{ID: "distilbert/distilbert-base-uncased-finetuned-sst-2-english", Author: "distilbert", Task: "text-classification",
					Library: "transformers", Downloads: 4000000, Likes: 700, Tags: []string{"transformers", "pytorch"}},
			},
		}
	})
}

// Models returns the knobs of the fake's model endpoints.
func (s *Server) Models() *ModelFake {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.modelState().opts
}

// SeedHubModel adds a model to the fake Hugging Face Hub.
func (s *Server) SeedHubModel(m runr.HubModel) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.modelState()
	st.hub = append(st.hub, m)
}

// SeedModel adds a Hub model to the library and returns it.
func (s *Server) SeedModel(hubID string) runr.AIModel {
	s.mu.Lock()
	defer s.mu.Unlock()
	m, err := s.addHubModel(hubID, "", "")
	if err != nil {
		s.t.Fatalf("runrtest: seed model %s: %s", hubID, err.message)
	}
	return s.modelResource(m)
}

// SeedModelService creates a model service of a library model (by name) in a region with a
// status (ready, stopped, …) and returns it.
func (s *Server) SeedModelService(name, modelName, regionName, status string) runr.ModelService {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.modelState()
	m := s.modelByRef(modelName)
	if m == nil {
		s.t.Fatalf("runrtest: model %q does not exist", modelName)
	}
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	device := "cpu"
	engine := modelEngineFor(m.data.Task, device)
	svc := &fakeModelService{data: runr.ModelService{ID: s.newID(), Name: name, ServiceName: "m-" + name,
		AIModelID: m.data.ID, RegionID: reg.ID, Engine: engine, Device: device, Mode: "online", CPU: "1",
		MemoryMB: 1024, MaxScale: 1, ScaleToZero: true, TimeoutSeconds: 300, ServedName: m.data.Name,
		Status: status, CreatedAt: nowRFC3339()}}
	if status == runr.ModelServiceReady {
		svc.data.DeployedAt = nowRFC3339()
	}
	st.services = append(st.services, svc)
	return s.modelServiceResource(svc)
}

// ModelService returns a model service by name.
func (s *Server) ModelService(name string) (runr.ModelService, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if svc := s.modelServiceByRef(name); svc != nil {
		return s.modelServiceResource(svc), true
	}
	return runr.ModelService{}, false
}

// AIModel returns a model of the library by name.
func (s *Server) AIModel(name string) (runr.AIModel, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if m := s.modelByRef(name); m != nil {
		return s.modelResource(m), true
	}
	return runr.AIModel{}, false
}

// ModelUploads returns the uploads of a model (by name), oldest first.
func (s *Server) ModelUploads(modelName string) []runr.ModelUpload {
	s.mu.Lock()
	defer s.mu.Unlock()
	m := s.modelByRef(modelName)
	var out []runr.ModelUpload
	for _, u := range s.modelState().uploads {
		if m != nil && u.data.AIModelID == m.data.ID {
			out = append(out, u.data)
		}
	}
	return out
}

// UploadedModelFile returns the bytes the uploads of a model received for path.
func (s *Server) UploadedModelFile(modelName, path string) []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	m := s.modelByRef(modelName)
	var out []byte
	for _, u := range s.modelState().uploads {
		if m != nil && u.data.AIModelID == m.data.ID {
			if b, ok := u.content[path]; ok {
				out = append([]byte(nil), b...)
			}
		}
	}
	return out
}

// ModelBindings returns the model bindings of a project (by name).
func (s *Server) ModelBindings(projectName string) []runr.ModelBinding {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	var out []runr.ModelBinding
	for _, b := range s.modelState().bindings {
		if p != nil && b.data.ProjectID == p.data.ID {
			out = append(out, s.modelBindingResource(b))
		}
	}
	return out
}

// HubTokenValue returns the Hugging Face token the fake stored ("" = none).
func (s *Server) HubTokenValue() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.modelState().token
}

// LastModelInvoke returns the body of the last POST /model-services/{id}/invoke.
func (s *Server) LastModelInvoke() map[string]any {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.modelState().lastInvoke
}

// ---------------------------------------------------------------------------------------
// Handlers

func init() {
	handle("GET /models/hub/search", (*Server).hubSearch)
	handle("GET /models/hub/info", (*Server).hubInfo)
	handle("GET /models/engines", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, modelEngineCatalog(), nil
	})
	handle("GET /models/hub-token", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, s.hubTokenJSON(), nil
	})
	handle("PUT /models/hub-token", (*Server).setHubToken)
	handle("DELETE /models/hub-token", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.modelState()
		st.token, st.tokenAt = "", ""
		return 200, map[string]any{"configured": false, "hint": nil, "updated_at": nil}, nil
	})

	handle("GET /models", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.AIModel{}
		models := append([]*fakeModel(nil), s.modelState().models...)
		sort.Slice(models, func(i, j int) bool { return models[i].data.Name < models[j].data.Name })
		for _, m := range models {
			list = append(list, s.modelResource(m))
		}
		return 200, map[string]any{"data": list, "abilities": modelAbilities()}, nil
	})
	handle("POST /models", (*Server).addLibraryModel)
	handle("GET /models/{model}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		m := s.modelByID(q.Segs[1])
		if m == nil {
			return 0, nil, errNotFound()
		}
		s.advanceModelSnapshots(m)
		return 200, s.modelResource(m), nil
	})
	handle("PATCH /models/{model}", (*Server).updateLibraryModel)
	handle("DELETE /models/{model}", (*Server).deleteLibraryModel)

	handle("GET /models/{model}/snapshots", func(s *Server, q *featureReq) (int, any, *apiErr) {
		m := s.modelByID(q.Segs[1])
		if m == nil {
			return 0, nil, errNotFound()
		}
		s.advanceModelSnapshots(m)
		list := []runr.ModelSnapshot{}
		for _, sn := range m.snapshots {
			list = append(list, s.modelSnapshotResource(sn))
		}
		return 200, map[string]any{"data": list}, nil
	})
	handle("POST /models/{model}/snapshots", (*Server).createModelSnapshot)
	handle("DELETE /models/{model}/snapshots/{snapshot}", (*Server).deleteModelSnapshot)
	handle("GET /models/{model}/snapshots/{snapshot}/logs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		m := s.modelByID(q.Segs[1])
		if m == nil || m.snapshot(q.Segs[3]) == nil {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"logs": "Fetching " + m.data.Name + " …\nsnapshot ready\n"}, nil
	})

	handle("POST /models/{model}/uploads", (*Server).startModelUpload)
	handle("GET /models/{model}/uploads/{upload}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		u := s.modelUploadOf(q)
		if u == nil {
			return 0, nil, errNotFound()
		}
		s.advanceModelUpload(u)
		return 200, u.data, nil
	})
	handle("PUT /models/{model}/uploads/{upload}/chunk", (*Server).modelUploadChunk)
	handle("POST /models/{model}/uploads/{upload}/complete", (*Server).completeModelUpload)
	handle("DELETE /models/{model}/uploads/{upload}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		u := s.modelUploadOf(q)
		if u == nil {
			return 0, nil, errNotFound()
		}
		u.data.Status = runr.UploadAborted
		if m := s.modelByID(u.data.AIModelID); m != nil {
			if sn := m.snapshot(u.data.SnapshotID); sn != nil && sn.data.Status != runr.SnapshotReady {
				sn.data.Status = runr.SnapshotFailed
				sn.data.Message = "The upload was aborted."
			}
		}
		return 204, nil, nil
	})

	handle("GET /model-services", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.ModelService{}
		services := append([]*fakeModelService(nil), s.modelState().services...)
		sort.Slice(services, func(i, j int) bool { return services[i].data.Name < services[j].data.Name })
		for _, svc := range services {
			if rid := q.query("knative_region_id"); rid != "" && svc.data.RegionID != rid {
				continue
			}
			list = append(list, s.modelServiceResource(svc))
		}
		return 200, map[string]any{"data": list, "abilities": modelAbilities()}, nil
	})
	handle("POST /model-services", (*Server).createModelService)
	handle("GET /model-services/{service}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		s.advanceModelService(svc)
		return 200, s.modelServiceResource(svc), nil
	})
	handle("PATCH /model-services/{service}", (*Server).updateModelService)
	handle("DELETE /model-services/{service}", (*Server).deleteModelService)
	handle("POST /model-services/{service}/deploy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		m := s.modelByID(svc.data.AIModelID)
		if svc.data.Mode == "online" && m != nil && m.data.Source != runr.ModelSourceHub {
			return 0, nil, errValidation("mode", "Online mode downloads from the Hugging Face Hub: this model was uploaded or imported, use offline mode.")
		}
		s.startModelService(svc)
		return 202, s.modelServiceResource(svc), nil
	})
	handle("POST /model-services/{service}/stop", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		svc.data.Status = runr.ModelServiceStopped
		svc.data.StatusMessage = ""
		return 202, s.modelServiceResource(svc), nil
	})
	handle("GET /model-services/{service}/logs", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		pods := []runr.ModelServicePod{}
		if svc.data.Running() {
			pods = append(pods, runr.ModelServicePod{Name: svc.data.ServiceName + "-00001-deployment-7d9f", Phase: "Running",
				Ready: svc.data.Status == runr.ModelServiceReady, Logs: "INFO loading " + svc.data.ServedName + "\nINFO ready\n"})
		}
		return 200, map[string]any{"pods": pods}, nil
	})
	handle("POST /model-services/{service}/invoke", (*Server).invokeModelService)
	handle("GET /model-services/{service}/bindings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		svc := s.modelServiceByID(q.Segs[1])
		if svc == nil {
			return 0, nil, errNotFound()
		}
		list := []runr.ModelBinding{}
		for _, b := range s.modelState().bindings {
			if b.data.AIModelServiceID == svc.data.ID {
				out := s.modelBindingResource(b)
				if p := s.projectByID(b.data.ProjectID); p != nil {
					out.Project = &runr.Ref{ID: p.data.ID, Name: p.data.Name}
				}
				list = append(list, out)
			}
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("GET /projects/{project}/models", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		list := []runr.ModelBinding{}
		for _, b := range s.modelState().bindings {
			if b.data.ProjectID == p.data.ID {
				list = append(list, s.modelBindingResource(b))
			}
		}
		avail := []runr.ModelServiceRef{}
		for _, svc := range s.modelState().services {
			if svc.data.RegionID == p.data.RegionID {
				avail = append(avail, runr.ModelServiceRef{ID: svc.data.ID, Name: svc.data.Name, Engine: svc.data.Engine, Status: svc.data.Status})
			}
		}
		return 200, map[string]any{"data": list, "available": avail}, nil
	})
	handle("POST /projects/{project}/models", (*Server).bindProjectModel)
	handle("PATCH /projects/{project}/models/{modelBinding}", (*Server).updateProjectModel)
	handle("DELETE /projects/{project}/models/{modelBinding}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.modelState()
		for i, b := range st.bindings {
			if b.data.ID == q.Segs[3] && b.data.ProjectID == p.data.ID {
				st.bindings = append(st.bindings[:i], st.bindings[i+1:]...)
				return 204, nil, nil
			}
		}
		return 0, nil, errNotFound()
	})
}

func modelAbilities() map[string]bool {
	return map[string]bool{"view": true, "deploy": true, "manage": true, "hub": true}
}

// ---------------------------------------------------------------------------------------
// Hub

func (s *Server) modelHubEntry(id string) *runr.HubModel {
	for i, m := range s.modelState().hub {
		if m.ID == id {
			return &s.modelState().hub[i]
		}
	}
	return nil
}

func (s *Server) hubSearch(q *featureReq) (int, any, *apiErr) {
	search := strings.ToLower(q.query("search"))
	task, library := q.query("task"), q.query("library")
	limit := 20
	if l := q.query("limit"); l != "" {
		n, err := strconv.Atoi(l)
		if err != nil || n < 1 || n > 50 {
			return 0, nil, errValidation("limit", "The limit field must be between 1 and 50.")
		}
		limit = n
	}
	var matches []runr.HubModel
	for _, m := range s.modelState().hub {
		if search != "" && !strings.Contains(strings.ToLower(m.ID), search) {
			continue
		}
		if task != "" && m.Task != task {
			continue
		}
		if library != "" && m.Library != library {
			continue
		}
		matches = append(matches, m)
	}
	switch q.query("sort") {
	case "likes":
		sort.SliceStable(matches, func(i, j int) bool { return matches[i].Likes > matches[j].Likes })
	default:
		sort.SliceStable(matches, func(i, j int) bool { return matches[i].Downloads > matches[j].Downloads })
	}
	offset, _ := strconv.Atoi(q.query("cursor"))
	if offset > len(matches) {
		offset = len(matches)
	}
	page := matches[offset:]
	var next any
	if len(page) > limit {
		page = page[:limit]
		next = strconv.Itoa(offset + limit)
	}
	if page == nil {
		page = []runr.HubModel{}
	}
	return 200, map[string]any{"data": page, "next_cursor": next}, nil
}

func (s *Server) hubInfo(q *featureReq) (int, any, *apiErr) {
	id := q.query("id")
	if id == "" {
		return 0, nil, errValidation("id", "The id field is required.")
	}
	m := s.modelHubEntry(id)
	if m == nil {
		return 0, nil, &apiErr{status: 404, message: "Model " + id + " was not found on the Hugging Face Hub."}
	}
	engine := modelEngineFor(m.Task, "cpu")
	device := "cpu"
	if m.Task == "text-generation" {
		engine, device = "vllm", "gpu"
	}
	engines := []map[string]any{{"engine": engine, "devices": []string{device}, "recommended": true, "api": modelEngineAPI(engine)}}
	if engine != "transformers" {
		engines = append(engines, map[string]any{"engine": "transformers", "devices": []string{"cpu", "gpu"}, "recommended": false, "api": "runr"})
	}
	warnings := []string{}
	if m.Gated {
		if s.modelState().token == "" {
			warnings = append(warnings, "gated_needs_token")
		} else {
			warnings = append(warnings, "gated")
		}
	}
	return 200, map[string]any{
		"id": m.ID, "sha": modelFakeSHA(m.ID), "task": m.Task, "library": m.Library, "license": "apache-2.0", "gated": m.Gated,
		"private": m.Private, "downloads": m.Downloads, "likes": m.Likes, "tags": m.Tags, "size_bytes": 90868376,
		"parameters": 22713728, "files": []map[string]any{{"name": "config.json", "size": 612}, {"name": "model.safetensors", "size": 90868376}},
		"card_summary": "# " + m.ID, "last_modified": "2026-09-01T00:00:00.000Z", "engines": engines,
		"suggested": map[string]any{"engine": engine, "device": device, "cpu": "1", "memory_mb": 1024, "gpu": nil,
			"suggest_min_scale": false},
		"warnings": warnings,
	}, nil
}

func (s *Server) hubTokenJSON() map[string]any {
	st := s.modelState()
	if st.token == "" {
		return map[string]any{"configured": false, "hint": nil, "updated_at": nil}
	}
	return map[string]any{"configured": true, "hint": "hf_…" + st.token[len(st.token)-4:], "updated_at": st.tokenAt}
}

func (s *Server) setHubToken(q *featureReq) (int, any, *apiErr) {
	tok := q.str("token")
	if len(tok) < 8 || strings.ContainsAny(tok, " \t\n") {
		return 0, nil, errValidation("token", "The token field must be at least 8 characters without spaces.")
	}
	if v, _ := q.Body["validate"].(bool); v && strings.HasPrefix(tok, "hf_invalid") {
		return 0, nil, errValidation("token", "The Hugging Face Hub refused the token.")
	}
	st := s.modelState()
	st.token, st.tokenAt = tok, nowRFC3339()
	return 200, s.hubTokenJSON(), nil
}

func modelEngineCatalog() map[string]any {
	def := func(cpu, gpu any) map[string]any { return map[string]any{"cpu": cpu, "gpu": gpu} }
	return map[string]any{
		"engines": map[string]any{
			"vllm":         map[string]any{"label": "vLLM", "devices": []string{"gpu"}, "api": "openai", "tasks": []string{"text-generation"}},
			"tgi":          map[string]any{"label": "Text Generation Inference", "devices": []string{"gpu"}, "api": "openai", "tasks": []string{"text-generation"}},
			"tei":          map[string]any{"label": "Text Embeddings Inference", "devices": []string{"cpu", "gpu"}, "api": "tei", "tasks": []string{"feature-extraction", "sentence-similarity"}},
			"transformers": map[string]any{"label": "Transformers (RunR hf-serve)", "devices": []string{"cpu", "gpu"}, "api": "runr", "tasks": []string{"text-classification", "text-generation"}},
			"diffusers":    map[string]any{"label": "Diffusers (RunR hf-serve)", "devices": []string{"gpu"}, "api": "runr", "tasks": []string{"text-to-image"}},
			"custom":       map[string]any{"label": "Custom image", "devices": []string{"cpu", "gpu"}, "api": "custom", "tasks": []string{"*"}},
		},
		"tasks": []map[string]any{
			{"task": "feature-extraction", "engines": []string{"tei", "transformers", "custom"}, "default": def("tei", "tei")},
			{"task": "text-classification", "engines": []string{"transformers", "custom"}, "default": def("transformers", "transformers")},
			{"task": "text-generation", "engines": []string{"vllm", "tgi", "transformers", "custom"}, "default": def("transformers", "vllm")},
			{"task": "text-to-image", "engines": []string{"diffusers", "custom"}, "default": def(nil, "diffusers")},
		},
	}
}

// modelEngineFor is RunR's pick of an engine for a task on a device.
func modelEngineFor(task, device string) string {
	switch task {
	case "text-generation":
		if device == "gpu" {
			return "vllm"
		}
		return "transformers"
	case "feature-extraction", "sentence-similarity":
		return "tei"
	case "text-to-image":
		return "diffusers"
	}
	return "transformers"
}

func modelEngineAPI(engine string) string {
	switch engine {
	case "vllm", "tgi":
		return "openai"
	case "tei":
		return "tei"
	case "custom":
		return "custom"
	}
	return "runr"
}

var modelEngineDevices = map[string][]string{
	"vllm": {"gpu"}, "tgi": {"gpu"}, "tei": {"cpu", "gpu"}, "transformers": {"cpu", "gpu"},
	"diffusers": {"gpu"}, "custom": {"cpu", "gpu"},
}

var modelInvokePaths = map[string][]string{
	"vllm":         {"/v1/chat/completions", "/v1/completions", "/v1/embeddings", "/v1/models", "/health"},
	"tgi":          {"/v1/chat/completions", "/v1/completions", "/generate", "/info", "/health"},
	"tei":          {"/embed", "/v1/embeddings", "/rerank", "/predict", "/info", "/health"},
	"transformers": {"/predict", "/v1/embeddings", "/v1/chat/completions", "/v1/completions", "/v1/images/generations", "/v1/models", "/info", "/healthz"},
	"diffusers":    {"/predict", "/v1/embeddings", "/v1/chat/completions", "/v1/completions", "/v1/images/generations", "/v1/models", "/info", "/healthz"},
}

func modelFakeSHA(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])[:40]
}

// ---------------------------------------------------------------------------------------
// Library

var (
	modelNamePattern        = regexp.MustCompile(`^[a-z]([-a-z0-9]{0,61}[a-z0-9])?$`)
	modelServiceNamePattern = regexp.MustCompile(`^[a-z]([-a-z0-9]{0,38}[a-z0-9])?$`)
	modelAliasPattern       = regexp.MustCompile(`^[A-Z][A-Z0-9_]{0,31}$`)
	modelNonAlnum           = regexp.MustCompile(`[^A-Za-z0-9]+`)
)

func modelNameFromHub(id string) string {
	base := id[strings.LastIndex(id, "/")+1:]
	base = strings.Trim(strings.ToLower(modelNonAlnum.ReplaceAllString(base, "-")), "-")
	if base == "" || base[0] < 'a' || base[0] > 'z' {
		base = "m-" + base
	}
	if len(base) > 40 {
		base = base[:40]
	}
	return strings.TrimRight(base, "-")
}

func (s *Server) uniqueModelName(base string) string {
	name := base
	for i := 2; s.modelByRef(name) != nil; i++ {
		b := base
		if len(b) > 36 {
			b = b[:36]
		}
		name = strings.TrimRight(b, "-") + "-" + strconv.Itoa(i)
	}
	return name
}

func (s *Server) addHubModel(hubID, revision, name string) (*fakeModel, *apiErr) {
	h := s.modelHubEntry(hubID)
	if h == nil {
		return nil, &apiErr{status: 422, message: "Model " + hubID + " was not found on the Hugging Face Hub.",
			errors: map[string][]string{"hub_id": {"Model " + hubID + " was not found on the Hugging Face Hub."}}}
	}
	if revision == "" {
		revision = "main"
	}
	if name == "" {
		name = s.uniqueModelName(modelNameFromHub(hubID))
	}
	m := &fakeModel{data: runr.AIModel{ID: s.newID(), Name: name, Source: runr.ModelSourceHub, HubID: h.ID, Revision: revision,
		RevisionSHA: modelFakeSHA(h.ID + "@" + revision), Task: h.Task, Library: h.Library, License: "apache-2.0", Gated: h.Gated,
		Private: h.Private, SizeBytes: 90868376, Parameters: 22713728, Tags: h.Tags, CreatedAt: nowRFC3339()}}
	s.modelState().models = append(s.modelState().models, m)
	return m, nil
}

func (s *Server) addLibraryModel(q *featureReq) (int, any, *apiErr) {
	source := q.str("source")
	switch source {
	case runr.ModelSourceHub, runr.ModelSourceUpload, runr.ModelSourcePVC, runr.ModelSourceOCI:
	default:
		return 0, nil, errValidation("source", "The selected source is invalid.")
	}
	name := q.str("name")
	if name != "" {
		if !modelNamePattern.MatchString(name) || len(name) > 40 {
			return 0, nil, errValidation("name", "The name field format is invalid.")
		}
		if s.modelByRef(name) != nil {
			return 0, nil, errValidation("name", "The name has already been taken.")
		}
	}
	if source == runr.ModelSourceHub {
		if q.str("hub_id") == "" {
			return 0, nil, errValidation("hub_id", "The hub id field is required when source is hub.")
		}
		m, err := s.addHubModel(q.str("hub_id"), q.str("revision"), name)
		if err != nil {
			return 0, nil, err
		}
		return 201, s.modelResource(m), nil
	}
	task := q.str("task")
	if task == "" {
		return 0, nil, errValidation("task", "The task field is required unless source is in hub.")
	}
	rid := q.str("knative_region_id")
	switch source {
	case runr.ModelSourcePVC:
		if q.str("path") == "" {
			return 0, nil, errValidation("path", "The path field is required when source is pvc.")
		}
	case runr.ModelSourceOCI:
		if q.str("reference") == "" {
			return 0, nil, errValidation("reference", "The reference field is required when source is oci.")
		}
	}
	if source != runr.ModelSourceUpload && s.regionByID(rid) == nil {
		return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
	}
	if name == "" {
		name = s.uniqueModelName(modelNameFromHub(task))
	}
	m := &fakeModel{data: runr.AIModel{ID: s.newID(), Name: name, Source: source, Revision: "local", Task: task,
		Library: q.str("library"), CreatedAt: nowRFC3339()}}
	if source != runr.ModelSourceUpload {
		m.data.SourceRef = strings.Trim(q.str("path")+q.str("reference"), "/")
		m.snapshots = append(m.snapshots, s.newModelSnapshot(m, rid, source))
	}
	s.modelState().models = append(s.modelState().models, m)
	return 201, s.modelResource(m), nil
}

func (s *Server) updateLibraryModel(q *featureReq) (int, any, *apiErr) {
	m := s.modelByID(q.Segs[1])
	if m == nil {
		return 0, nil, errNotFound()
	}
	if name, ok := q.Body["name"].(string); ok && name != m.data.Name {
		if !modelNamePattern.MatchString(name) {
			return 0, nil, errValidation("name", "The name field format is invalid.")
		}
		if o := s.modelByRef(name); o != nil && o != m {
			return 0, nil, errValidation("name", "The name has already been taken.")
		}
		if len(m.snapshots) > 0 {
			return 0, nil, errValidation("name", "A model with offline copies cannot be renamed: its name is its folder in the regions.")
		}
		m.data.Name = name
	}
	if t, ok := q.Body["task"].(string); ok {
		m.data.Task = t
	}
	if l, ok := q.Body["library"].(string); ok {
		m.data.Library = l
	}
	return 200, s.modelResource(m), nil
}

func (s *Server) deleteLibraryModel(q *featureReq) (int, any, *apiErr) {
	m := s.modelByID(q.Segs[1])
	if m == nil {
		return 0, nil, errNotFound()
	}
	st := s.modelState()
	var users []string
	for _, svc := range st.services {
		if svc.data.AIModelID == m.data.ID {
			users = append(users, svc.data.Name)
		}
	}
	if len(users) > 0 {
		return 0, nil, &apiErr{status: 409, message: "Model services use this model: " + strings.Join(users, ", ") + ". Delete them first."}
	}
	for i, x := range st.models {
		if x == m {
			st.models = append(st.models[:i], st.models[i+1:]...)
			break
		}
	}
	return 204, nil, nil
}

func (s *Server) modelResource(m *fakeModel) runr.AIModel {
	out := m.data
	out.Snapshots = []runr.ModelSnapshot{}
	for _, sn := range m.snapshots {
		out.Snapshots = append(out.Snapshots, s.modelSnapshotResource(sn))
	}
	if out.Tags == nil {
		out.Tags = []string{}
	}
	out.ServicesCount = 0
	for _, svc := range s.modelState().services {
		if svc.data.AIModelID == m.data.ID {
			out.ServicesCount++
		}
	}
	return out
}

func (s *Server) modelByID(id string) *fakeModel {
	for _, m := range s.modelState().models {
		if m.data.ID == id {
			return m
		}
	}
	return nil
}

func (s *Server) modelByRef(ref string) *fakeModel {
	for _, m := range s.modelState().models {
		if m.data.ID == ref || m.data.Name == ref {
			return m
		}
	}
	return nil
}

// ---------------------------------------------------------------------------------------
// Snapshots

func (m *fakeModel) snapshot(id string) *fakeModelSnapshot {
	for _, sn := range m.snapshots {
		if sn.data.ID == id {
			return sn
		}
	}
	return nil
}

func (m *fakeModel) snapshotIn(regionID string) *fakeModelSnapshot {
	for _, sn := range m.snapshots {
		if sn.data.RegionID == regionID {
			return sn
		}
	}
	return nil
}

func (s *Server) newModelSnapshot(m *fakeModel, regionID, source string) *fakeModelSnapshot {
	rev := "local"
	if m.data.Source == runr.ModelSourceHub {
		rev = m.data.RevisionSHA
	}
	return &fakeModelSnapshot{data: runr.ModelSnapshot{ID: s.newID(), AIModelID: m.data.ID, RegionID: regionID, Source: source,
		Revision: rev, Status: runr.SnapshotPending, StartedAt: nowRFC3339()},
		pollsLeft: s.modelState().opts.SnapshotPolls, fails: s.modelState().opts.FailSnapshot}
}

func (s *Server) modelSnapshotResource(sn *fakeModelSnapshot) runr.ModelSnapshot {
	out := sn.data
	if reg := s.regionByID(sn.data.RegionID); reg != nil {
		out.RegionName = reg.Name
	}
	return out
}

func (s *Server) advanceModelSnapshots(m *fakeModel) {
	for _, sn := range m.snapshots {
		s.advanceModelSnapshot(sn)
	}
}

func (s *Server) advanceModelSnapshot(sn *fakeModelSnapshot) {
	if sn.data.Status != runr.SnapshotPending && sn.data.Status != runr.SnapshotRunning {
		return
	}
	if sn.data.Source == runr.ModelSourceUpload {
		return // an upload's snapshot becomes ready on complete
	}
	if sn.pollsLeft > 0 {
		sn.pollsLeft--
		sn.data.Status = runr.SnapshotRunning
		return
	}
	if sn.fails {
		sn.data.Status = runr.SnapshotFailed
		sn.data.Message = "401 Client Error: the model is gated; set the organization's Hugging Face token"
		return
	}
	sn.data.Status = runr.SnapshotReady
	sn.data.SizeBytes = 91226112
	sn.data.FilesCount = 14
	sn.data.CompletedAt = nowRFC3339()
}

func (s *Server) createModelSnapshot(q *featureReq) (int, any, *apiErr) {
	m := s.modelByID(q.Segs[1])
	if m == nil {
		return 0, nil, errNotFound()
	}
	rid := q.str("knative_region_id")
	if s.regionByID(rid) == nil {
		return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
	}
	if m.data.Source == runr.ModelSourceUpload {
		return 0, nil, &apiErr{status: 422, message: "An uploaded model gets its offline copy by an upload into the region."}
	}
	existing := m.snapshotIn(rid)
	if m.data.Source != runr.ModelSourceHub && existing == nil {
		return 0, nil, &apiErr{status: 422, message: "An import reads from the region it was made for; upload or mirror the model to other regions."}
	}
	if existing != nil {
		switch existing.data.Status {
		case runr.SnapshotRunning, runr.SnapshotDeleting:
			return 0, nil, &apiErr{status: 409, message: "The offline copy is being made or removed; wait until it is done."}
		case runr.SnapshotReady:
			return 200, s.modelSnapshotResource(existing), nil
		}
		existing.data.Status = runr.SnapshotPending
		existing.data.Message = ""
		existing.pollsLeft = s.modelState().opts.SnapshotPolls
		existing.fails = s.modelState().opts.FailSnapshot
		return 202, s.modelSnapshotResource(existing), nil
	}
	sn := s.newModelSnapshot(m, rid, m.data.Source)
	m.snapshots = append(m.snapshots, sn)
	return 202, s.modelSnapshotResource(sn), nil
}

func (s *Server) deleteModelSnapshot(q *featureReq) (int, any, *apiErr) {
	m := s.modelByID(q.Segs[1])
	if m == nil {
		return 0, nil, errNotFound()
	}
	for i, sn := range m.snapshots {
		if sn.data.ID != q.Segs[3] {
			continue
		}
		for _, svc := range s.modelState().services {
			if svc.data.AIModelID == m.data.ID && svc.data.Mode == "offline" && svc.data.RegionID == sn.data.RegionID &&
				svc.data.Status != runr.ModelServiceStopped {
				return 0, nil, &apiErr{status: 409, message: "Running model services use this offline copy: " + svc.data.Name + "."}
			}
		}
		m.snapshots = append(m.snapshots[:i], m.snapshots[i+1:]...)
		return 204, nil, nil
	}
	return 0, nil, errNotFound()
}

// ---------------------------------------------------------------------------------------
// Uploads

func (s *Server) modelUploadOf(q *featureReq) *fakeModelUpload {
	for _, u := range s.modelState().uploads {
		if u.data.ID == q.Segs[3] && u.data.AIModelID == q.Segs[1] {
			return u
		}
	}
	return nil
}

func (s *Server) advanceModelUpload(u *fakeModelUpload) {
	if u.data.Status != runr.UploadPreparing {
		return
	}
	if u.polls > 0 {
		u.polls--
		return
	}
	u.data.Status = runr.UploadReady
}

func (s *Server) startModelUpload(q *featureReq) (int, any, *apiErr) {
	m := s.modelByID(q.Segs[1])
	if m == nil {
		return 0, nil, errNotFound()
	}
	rid := q.str("knative_region_id")
	if s.regionByID(rid) == nil {
		return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
	}
	raw, _ := q.Body["files"].([]any)
	if len(raw) == 0 {
		return 0, nil, errValidation("files", "The files field is required.")
	}
	if m.data.Source != runr.ModelSourceUpload {
		return 0, nil, &apiErr{status: 422, message: `Files can be uploaded to uploaded models only (source "upload").`}
	}
	st := s.modelState()
	u := &fakeModelUpload{sha: map[string]string{}, content: map[string][]byte{}, polls: st.opts.UploadPreparePolls}
	seen := map[string]bool{}
	for i, item := range raw {
		f, _ := item.(map[string]any)
		p, _ := f["path"].(string)
		p = strings.TrimLeft(strings.TrimPrefix(p, "./"), "/")
		size, _ := f["size"].(float64)
		if p == "" || strings.Contains(p, "..") {
			return 0, nil, errValidation(fmt.Sprintf("files.%d.path", i), "The path of a file must be relative and stay inside the model folder.")
		}
		if seen[p] {
			return 0, nil, errValidation(fmt.Sprintf("files.%d.path", i), "The files.%d.path field has a duplicate value.")
		}
		if size < 1 {
			return 0, nil, errValidation(fmt.Sprintf("files.%d.size", i), "The size must be at least 1.")
		}
		seen[p] = true
		if h, _ := f["sha256"].(string); h != "" {
			u.sha[p] = strings.ToLower(h)
		}
		u.data.Files = append(u.data.Files, runr.ModelUploadFile{Path: p, Size: int64(size)})
	}
	for _, o := range st.uploads {
		if o.data.AIModelID == m.data.ID && o.data.RegionID == rid &&
			(o.data.Status == runr.UploadPreparing || o.data.Status == runr.UploadReady || o.data.Status == runr.UploadCompleting) {
			return 0, nil, &apiErr{status: 409, message: "An upload of this model into this region is in progress."}
		}
	}
	sn := m.snapshotIn(rid)
	if sn == nil {
		sn = s.newModelSnapshot(m, rid, runr.ModelSourceUpload)
		m.snapshots = append(m.snapshots, sn)
	}
	sn.data.Status = runr.SnapshotRunning
	sn.data.Message = ""
	u.data = runr.ModelUpload{ID: s.newID(), AIModelID: m.data.ID, RegionID: rid, Status: runr.UploadPreparing,
		ChunkSize: st.opts.ChunkSize, Files: u.data.Files, SnapshotID: sn.data.ID, ExpiresAt: nowRFC3339()}
	if u.polls <= 0 {
		u.data.Status = runr.UploadReady
	}
	st.uploads = append(st.uploads, u)
	return 201, u.data, nil
}

func (s *Server) modelUploadChunk(q *featureReq) (int, any, *apiErr) {
	u := s.modelUploadOf(q)
	if u == nil {
		return 0, nil, errNotFound()
	}
	path, offStr := q.query("path"), q.query("offset")
	if path == "" {
		return 0, nil, errValidation("path", "The path field is required.")
	}
	off, err := strconv.ParseInt(offStr, 10, 64)
	if err != nil || off < 0 {
		return 0, nil, errValidation("offset", "The offset field must be an integer of at least 0.")
	}
	if u.data.Status != runr.UploadReady {
		return 0, nil, &apiErr{status: 409, message: "The upload is " + u.data.Status + "."}
	}
	var entry *runr.ModelUploadFile
	for i := range u.data.Files {
		if u.data.Files[i].Path == path {
			entry = &u.data.Files[i]
		}
	}
	if entry == nil {
		return 0, nil, &apiErr{status: 422, message: "The upload has no file " + path + "."}
	}
	n := int64(len(q.Raw))
	if n == 0 || n > u.data.ChunkSize || off+n > entry.Size {
		return 0, nil, &apiErr{status: 422, message: "The chunk of " + path + " is empty, larger than the chunk size or beyond the end of the file."}
	}
	buf := u.content[path]
	if int64(len(buf)) < off+n {
		grown := make([]byte, off+n)
		copy(grown, buf)
		buf = grown
	}
	copy(buf[off:], q.Raw)
	u.content[path] = buf
	if off+n > entry.UploadedBytes {
		entry.UploadedBytes = off + n
	}
	return 200, map[string]any{"path": path, "uploaded_bytes": entry.UploadedBytes}, nil
}

func (s *Server) completeModelUpload(q *featureReq) (int, any, *apiErr) {
	u := s.modelUploadOf(q)
	if u == nil {
		return 0, nil, errNotFound()
	}
	if u.data.Status != runr.UploadReady {
		return 0, nil, &apiErr{status: 409, message: "The upload is " + u.data.Status + "."}
	}
	var missing []string
	var size int64
	for _, f := range u.data.Files {
		size += f.Size
		if f.UploadedBytes < f.Size {
			missing = append(missing, f.Path)
		}
	}
	if len(missing) > 0 {
		return 0, nil, &apiErr{status: 422, message: "Files are not uploaded completely: " + strings.Join(missing, ", ")}
	}
	for p, want := range u.sha {
		sum := sha256.Sum256(u.content[p])
		if hex.EncodeToString(sum[:]) != want {
			return 0, nil, &apiErr{status: 422, message: "The sha256 of " + p + " does not match."}
		}
	}
	u.data.Status = runr.UploadCompleted
	m := s.modelByID(u.data.AIModelID)
	var snap runr.ModelSnapshot
	if m != nil {
		if sn := m.snapshot(u.data.SnapshotID); sn != nil {
			sn.data.Status = runr.SnapshotReady
			sn.data.SizeBytes = runr.FlexInt64(size)
			sn.data.FilesCount = len(u.data.Files)
			sn.data.CompletedAt = nowRFC3339()
			snap = s.modelSnapshotResource(sn)
		}
		m.data.SizeBytes = runr.FlexInt64(size)
	}
	return 200, map[string]any{"upload": u.data, "snapshot": snap}, nil
}

// ---------------------------------------------------------------------------------------
// Model services

func (s *Server) modelServiceByID(id string) *fakeModelService {
	for _, svc := range s.modelState().services {
		if svc.data.ID == id {
			return svc
		}
	}
	return nil
}

func (s *Server) modelServiceByRef(ref string) *fakeModelService {
	for _, svc := range s.modelState().services {
		if svc.data.ID == ref || svc.data.Name == ref {
			return svc
		}
	}
	return nil
}

func (s *Server) modelServiceResource(svc *fakeModelService) runr.ModelService {
	out := svc.data
	if m := s.modelByID(svc.data.AIModelID); m != nil {
		out.Model = &runr.ModelServiceModel{ID: m.data.ID, Name: m.data.Name, HubID: m.data.HubID, Task: m.data.Task,
			Library: m.data.Library, SizeBytes: m.data.SizeBytes, Source: m.data.Source}
		out.Playground = modelPlayground(out.Engine, m.data.Task)
	}
	out.API = modelEngineAPI(out.Engine)
	if reg := s.regionByID(svc.data.RegionID); reg != nil {
		out.RegionName = reg.Name
		out.URL = "http://" + svc.data.ServiceName + "." + reg.ProjectNamespace + ".svc.cluster.local"
		if out.API == "openai" || out.Engine == "tei" {
			out.OpenAIBase = out.URL + "/v1"
		}
	}
	out.BindingsCount = 0
	for _, b := range s.modelState().bindings {
		if b.data.AIModelServiceID == svc.data.ID {
			out.BindingsCount++
		}
	}
	return out
}

func modelPlayground(engine, task string) *runr.Playground {
	switch {
	case engine == "vllm" || engine == "tgi" || task == "text-generation":
		return &runr.Playground{Kind: "chat", Path: "/v1/chat/completions"}
	case engine == "tei" || task == "feature-extraction" || task == "sentence-similarity":
		return &runr.Playground{Kind: "embeddings", Path: "/v1/embeddings"}
	case engine == "custom":
		return &runr.Playground{Kind: "generic", Path: "/"}
	}
	return &runr.Playground{Kind: "classification", Path: "/predict"}
}

func (s *Server) startModelService(svc *fakeModelService) {
	st := s.modelState()
	svc.pollsLeft = st.opts.ServicePolls
	svc.fails = st.opts.FailService
	svc.data.Status = runr.ModelServiceDeploying
	svc.data.StatusMessage = "Deploy queued."
	if svc.data.Mode == "offline" {
		m := s.modelByID(svc.data.AIModelID)
		if m != nil {
			sn := m.snapshotIn(svc.data.RegionID)
			if sn == nil && m.data.Source == runr.ModelSourceHub {
				sn = s.newModelSnapshot(m, svc.data.RegionID, runr.ModelSourceHub)
				m.snapshots = append(m.snapshots, sn)
			}
			if sn != nil && sn.data.Status != runr.SnapshotReady {
				svc.data.Status = runr.ModelServiceWaitingSnapshot
				svc.data.StatusMessage = "Waiting for the offline copy of the model in the region."
			}
		}
	}
}

func (s *Server) advanceModelService(svc *fakeModelService) {
	switch svc.data.Status {
	case runr.ModelServiceWaitingSnapshot:
		m := s.modelByID(svc.data.AIModelID)
		if m == nil {
			return
		}
		sn := m.snapshotIn(svc.data.RegionID)
		if sn == nil {
			return
		}
		s.advanceModelSnapshot(sn)
		switch sn.data.Status {
		case runr.SnapshotReady:
			svc.data.Status = runr.ModelServiceDeploying
			svc.data.StatusMessage = "Deploy queued."
		case runr.SnapshotFailed:
			svc.data.Status = runr.ModelServiceFailed
			svc.data.StatusMessage = "The offline copy failed: " + sn.data.Message
		}
	case runr.ModelServiceDeploying:
		if svc.pollsLeft > 0 {
			svc.pollsLeft--
			return
		}
		if svc.fails {
			svc.data.Status = runr.ModelServiceFailed
			svc.data.StatusMessage = s.modelState().opts.FailMessage
			return
		}
		svc.data.Status = runr.ModelServiceReady
		svc.data.StatusMessage = ""
		svc.data.DeployedAt = nowRFC3339()
	}
}

// applyModelServiceSettings validates and applies settings of POST / PATCH /model-services.
func (s *Server) applyModelServiceSettings(svc *runr.ModelService, body map[string]any, task string) *apiErr {
	str := func(k string) (string, bool) { v, ok := body[k].(string); return v, ok }
	num := func(k string) (int, bool) { v, ok := body[k].(float64); return int(v), ok }
	if v, ok := str("mode"); ok {
		if v != "online" && v != "offline" {
			return errValidation("mode", "The selected mode is invalid.")
		}
		svc.Mode = v
	}
	if v, ok := str("device"); ok {
		if v != "cpu" && v != "gpu" {
			return errValidation("device", "The selected device is invalid.")
		}
		svc.Device = v
	}
	engineSet := false
	if v, ok := str("engine"); ok {
		if _, known := modelEngineDevices[v]; !known && v != "auto" {
			return errValidation("engine", "The selected engine is invalid.")
		}
		if v != "auto" {
			svc.Engine = v
			engineSet = true
		}
	}
	if !engineSet && (svc.Engine == "" || body["device"] != nil) {
		svc.Engine = modelEngineFor(task, svc.Device)
	}
	if devs := modelEngineDevices[svc.Engine]; len(devs) == 1 && devs[0] != svc.Device {
		if _, given := body["device"]; given {
			return errValidation("device", "The engine "+svc.Engine+" runs on "+devs[0]+" only.")
		}
		svc.Device = devs[0]
	}
	if g, ok := body["gpu"].(map[string]any); ok {
		count, _ := g["count"].(float64)
		if count < 1 || count > 8 {
			return errValidation("gpu.count", "The gpu.count field must be between 1 and 8.")
		}
		svc.GPU = &runr.GPURequest{Count: int(count)}
		if t, _ := g["type"].(string); t != "" {
			svc.GPU.Type = t
		}
		if mm, _ := g["memory_min"].(float64); mm > 0 {
			svc.GPU.MemoryMin = int(mm)
		}
	}
	if svc.Device == "gpu" && svc.GPU == nil {
		svc.GPU = &runr.GPURequest{Count: 1}
	}
	if svc.Device == "cpu" {
		svc.GPU = nil
	}
	if v, ok := str("cpu"); ok {
		svc.CPU = v
	}
	if v, ok := num("memory_mb"); ok {
		if v < 256 {
			return errValidation("memory_mb", "The memory mb field must be at least 256.")
		}
		svc.MemoryMB = v
	}
	if v, ok := num("min_scale"); ok {
		svc.MinScale = v
	}
	if v, ok := num("max_scale"); ok {
		if v < 1 {
			return errValidation("max_scale", "The max scale field must be at least 1.")
		}
		svc.MaxScale = v
	}
	if v, ok := body["scale_to_zero"].(bool); ok {
		svc.ScaleToZero = v
	}
	if v, ok := num("timeout_seconds"); ok {
		svc.TimeoutSeconds = v
	}
	if v, ok := str("image"); ok {
		svc.Image = v
	}
	if raw, ok := body["args"].([]any); ok {
		svc.Args = nil
		for _, a := range raw {
			svc.Args = append(svc.Args, fmt.Sprint(a))
		}
	}
	if raw, ok := body["env_vars"].(map[string]any); ok {
		svc.EnvVars = runr.EnvMap{}
		for k, v := range raw {
			if err := runr.ValidateEnvName(k); err != nil {
				return errValidation("env_vars", err.Error())
			}
			svc.EnvVars[k] = fmt.Sprint(v)
		}
	}
	if v, ok := num("port"); ok {
		svc.Port = v
	}
	if v, ok := str("health_path"); ok {
		svc.HealthPath = v
	}
	if v, ok := str("served_name"); ok {
		svc.ServedName = v
	}
	// null resets a setting to the engine's default (ModelServiceSpec: array_key_exists + ?: null)
	for _, k := range runr.ClearableModelSettings {
		if v, sent := body[k]; sent && v == nil {
			switch k {
			case "image":
				svc.Image = ""
			case "args":
				svc.Args = nil
			case "env_vars":
				svc.EnvVars = nil
			case "port":
				svc.Port = 0
			case "health_path":
				svc.HealthPath = ""
			}
		}
	}
	if svc.MinScale > svc.MaxScale {
		return errValidation("min_scale", "The min scale must not be greater than the max scale.")
	}
	return nil
}

func (s *Server) createModelService(q *featureReq) (int, any, *apiErr) {
	st := s.modelState()
	rid := q.str("knative_region_id")
	if rid == "" || s.regionByID(rid) == nil {
		return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
	}
	var m *fakeModel
	switch {
	case q.str("ai_model_id") != "":
		if m = s.modelByID(q.str("ai_model_id")); m == nil {
			return 0, nil, errValidation("ai_model_id", "The selected ai model id is invalid.")
		}
	case q.str("hub_id") != "":
		rev := q.str("revision")
		if rev == "" {
			rev = "main"
		}
		for _, x := range st.models {
			if x.data.Source == runr.ModelSourceHub && x.data.HubID == q.str("hub_id") && x.data.Revision == rev {
				m = x
			}
		}
		if m == nil {
			var err *apiErr
			if m, err = s.addHubModel(q.str("hub_id"), rev, ""); err != nil {
				return 0, nil, err
			}
		}
	default:
		return 0, nil, errValidation("ai_model_id", "The ai model id field is required when hub id is not present.")
	}
	name := q.str("name")
	if name != "" {
		if len(name) > 30 || !modelServiceNamePattern.MatchString(name) {
			return 0, nil, errValidation("name", "The name field format is invalid.")
		}
		if s.modelServiceByRef(name) != nil {
			return 0, nil, errValidation("name", "The name has already been taken.")
		}
	} else {
		base := strings.Trim(strings.ToLower(modelNonAlnum.ReplaceAllString(m.data.Name, "-")), "-")
		if len(base) > 30 {
			base = strings.TrimRight(base[:30], "-")
		}
		name = base
		for i := 2; s.modelServiceByRef(name) != nil; i++ {
			name = base + "-" + strconv.Itoa(i)
		}
	}
	svc := &fakeModelService{data: runr.ModelService{ID: s.newID(), Name: name, ServiceName: "m-" + name, AIModelID: m.data.ID,
		RegionID: rid, Device: "cpu", Mode: "online", CPU: "1", MemoryMB: 1024, MinScale: 0, MaxScale: 1, ScaleToZero: true,
		TimeoutSeconds: 300, ServedName: m.data.Name, Status: runr.ModelServiceStopped, CreatedAt: nowRFC3339()}}
	if m.data.Task == "text-generation" {
		svc.data.Device = "gpu"
	}
	if err := s.applyModelServiceSettings(&svc.data, q.Body, m.data.Task); err != nil {
		return 0, nil, err
	}
	if svc.data.Mode == "online" && m.data.Source != runr.ModelSourceHub {
		return 0, nil, errValidation("mode", "Online mode downloads from the Hugging Face Hub: this model was uploaded or imported, use offline mode.")
	}
	st.services = append(st.services, svc)
	if deploy, ok := q.Body["deploy"].(bool); !ok || deploy {
		s.startModelService(svc)
	}
	return 201, s.modelServiceResource(svc), nil
}

func (s *Server) updateModelService(q *featureReq) (int, any, *apiErr) {
	svc := s.modelServiceByID(q.Segs[1])
	if svc == nil {
		return 0, nil, errNotFound()
	}
	task := ""
	if m := s.modelByID(svc.data.AIModelID); m != nil {
		task = m.data.Task
	}
	next := svc.data
	if err := s.applyModelServiceSettings(&next, q.Body, task); err != nil {
		return 0, nil, err
	}
	running := svc.data.Running() || svc.data.Status == runr.ModelServiceFailed
	svc.data = next
	if running {
		s.startModelService(svc)
	}
	return 200, s.modelServiceResource(svc), nil
}

func (s *Server) deleteModelService(q *featureReq) (int, any, *apiErr) {
	svc := s.modelServiceByID(q.Segs[1])
	if svc == nil {
		return 0, nil, errNotFound()
	}
	st := s.modelState()
	var projects []string
	for _, b := range st.bindings {
		if b.data.AIModelServiceID == svc.data.ID {
			if p := s.projectByID(b.data.ProjectID); p != nil {
				projects = append(projects, p.data.Name)
			}
		}
	}
	if len(projects) > 0 {
		return 0, nil, &apiErr{status: 409, message: "Projects use this model service: " + strings.Join(projects, ", ") + ". Remove the bindings first."}
	}
	for i, x := range st.services {
		if x == svc {
			st.services = append(st.services[:i], st.services[i+1:]...)
			break
		}
	}
	return 204, nil, nil
}

func (s *Server) invokeModelService(q *featureReq) (int, any, *apiErr) {
	svc := s.modelServiceByID(q.Segs[1])
	if svc == nil {
		return 0, nil, errNotFound()
	}
	path := q.str("path")
	if path == "" {
		return 0, nil, errValidation("path", "The path field is required.")
	}
	if method := q.str("method"); method != "" && method != "GET" && method != "POST" {
		return 0, nil, errValidation("method", "The selected method is invalid.")
	}
	allowed := modelInvokePaths[svc.data.Engine]
	ok := allowed == nil
	for _, p := range allowed {
		ok = ok || p == path
	}
	if !ok || !strings.HasPrefix(path, "/") {
		return 0, nil, &apiErr{status: 422, message: "The playground does not call " + path + " on this engine."}
	}
	s.modelState().lastInvoke = q.Body
	if svc.data.Status != runr.ModelServiceReady {
		return 0, nil, &apiErr{status: 502, message: "The model service has no ready revision: wait until its status is ready."}
	}
	var body any
	switch path {
	case "/v1/embeddings", "/embed":
		body = map[string]any{"object": "list", "model": svc.data.ServedName,
			"data": []map[string]any{{"object": "embedding", "index": 0, "embedding": []float64{0.012, -0.034, 0.056}}}}
	case "/v1/chat/completions", "/v1/completions", "/generate":
		body = map[string]any{"object": "chat.completion", "model": svc.data.ServedName,
			"choices": []map[string]any{{"index": 0, "message": map[string]any{"role": "assistant", "content": "Hello from " + svc.data.Name + "."}, "finish_reason": "stop"}}}
	default:
		body = map[string]any{"model": svc.data.ServedName, "task": "text-classification",
			"outputs": []map[string]any{{"label": "POSITIVE", "score": 0.998}}}
	}
	return 200, map[string]any{"status": 200, "duration_ms": 42, "content_type": "application/json", "body": body}, nil
}

// ---------------------------------------------------------------------------------------
// Project model bindings

func modelAliasFor(name string) string {
	alias := strings.Trim(strings.ToUpper(modelNonAlnum.ReplaceAllString(name, "_")), "_")
	if alias == "" || alias[0] < 'A' || alias[0] > 'Z' {
		alias = "M_" + alias
	}
	if len(alias) > 32 {
		alias = alias[:32]
	}
	return strings.TrimRight(alias, "_")
}

func (s *Server) modelBindingResource(b *fakeModelBinding) runr.ModelBinding {
	out := b.data
	out.Env = runr.EnvMap{}
	if svc := s.modelServiceByID(b.data.AIModelServiceID); svc != nil {
		res := s.modelServiceResource(svc)
		out.Service = &res
		a := "RUNR_MODEL_" + b.data.Alias + "_"
		out.Env[a+"URL"] = res.URL
		out.Env[a+"MODEL"] = res.ServedName
		out.Env[a+"API"] = res.API
		if res.Model != nil {
			out.Env[a+"TASK"] = res.Model.Task
		}
		if res.OpenAIBase != "" {
			out.Env[a+"OPENAI_BASE"] = res.OpenAIBase
		}
		if b.data.MountWeights {
			out.Env[a+"PATH"] = "/models/" + strings.ToLower(b.data.Alias)
		}
	}
	out.AppliesOnNextDeploy = true
	return out
}

func (s *Server) modelCheckMountWeights(p *project, svc *fakeModelService) *apiErr {
	m := s.modelByID(svc.data.AIModelID)
	if m == nil {
		return nil
	}
	if sn := m.snapshotIn(p.data.RegionID); sn == nil || sn.data.Status != runr.SnapshotReady {
		return errValidation("mount_weights", "Mounting the weights needs an offline copy of the model in the project's region (Models → Library → Mirror).")
	}
	return nil
}

func (s *Server) bindProjectModel(q *featureReq) (int, any, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return 0, nil, err
	}
	st := s.modelState()
	svc := s.modelServiceByID(q.str("ai_model_service_id"))
	if svc == nil {
		return 0, nil, errValidation("ai_model_service_id", "The selected ai model service id is invalid.")
	}
	for _, b := range st.bindings {
		if b.data.ProjectID == p.data.ID && b.data.AIModelServiceID == svc.data.ID {
			return 0, nil, errValidation("ai_model_service_id", "The ai model service id has already been taken.")
		}
	}
	if svc.data.RegionID != p.data.RegionID {
		return 0, nil, errValidation("ai_model_service_id", "The model service runs in another region; a project can use model services of its own region.")
	}
	mount, _ := q.Body["mount_weights"].(bool)
	if mount {
		if err := s.modelCheckMountWeights(p, svc); err != nil {
			return 0, nil, err
		}
	}
	alias := q.str("alias")
	if alias != "" && !modelAliasPattern.MatchString(alias) {
		return 0, nil, errValidation("alias", "The alias field format is invalid.")
	}
	if alias == "" {
		alias = modelAliasFor(svc.data.Name)
	}
	for _, b := range st.bindings {
		if b.data.ProjectID == p.data.ID && b.data.Alias == alias {
			return 0, nil, errValidation("alias", "The project already uses the alias "+alias+".")
		}
	}
	b := &fakeModelBinding{data: runr.ModelBinding{ID: s.newID(), ProjectID: p.data.ID, AIModelServiceID: svc.data.ID,
		Alias: alias, MountWeights: mount}}
	st.bindings = append(st.bindings, b)
	return 201, s.modelBindingResource(b), nil
}

func (s *Server) updateProjectModel(q *featureReq) (int, any, *apiErr) {
	p, err := s.projectOf(q)
	if err != nil {
		return 0, nil, err
	}
	var b *fakeModelBinding
	for _, x := range s.modelState().bindings {
		if x.data.ID == q.Segs[3] && x.data.ProjectID == p.data.ID {
			b = x
		}
	}
	if b == nil {
		return 0, nil, errNotFound()
	}
	if alias, ok := q.Body["alias"].(string); ok {
		if !modelAliasPattern.MatchString(alias) {
			return 0, nil, errValidation("alias", "The alias field format is invalid.")
		}
		for _, x := range s.modelState().bindings {
			if x != b && x.data.ProjectID == p.data.ID && x.data.Alias == alias {
				return 0, nil, errValidation("alias", "The alias has already been taken.")
			}
		}
		b.data.Alias = alias
	}
	if mount, ok := q.Body["mount_weights"].(bool); ok {
		if mount {
			if svc := s.modelServiceByID(b.data.AIModelServiceID); svc != nil {
				if err := s.modelCheckMountWeights(p, svc); err != nil {
					return 0, nil, err
				}
			}
		}
		b.data.MountWeights = mount
	}
	return 200, s.modelBindingResource(b), nil
}
