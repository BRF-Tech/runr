package runrtest

import (
	"encoding/json"
	"fmt"
	"regexp"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The runtime catalog of the fake (api RuntimeController): built-in runtimes from the executor
// images, the plugin q (like examples/runtimes/q) and runtimes registered with POST /runtimes.

// pluginRuntimes are the runtime plugins of the fake catalog (like examples/runtimes/q).
var pluginRuntimes = map[string]runr.CatalogRuntime{
	"q": {ID: "q", Name: "Q", Language: "plaintext", Extensions: []string{"q"}, Category: "general",
		Source: "custom", RunMode: "persistent", Triggers: []string{"http", "schedule", "queue"},
		Sandbox: []string{"runc", "gvisor", "kata"},
		Handler: &runr.RuntimeHandler{File: "main", Function: "handler", Filename: "main.q"}, IsEnabled: true},
}

// qManifest is the normalized manifest of the q plugin.
const qManifest = `apiVersion: runr.sh/v1
kind: Runtime
id: q
name: Q
extensions: [q]
versions:
  - version: "1.0"
    dockerfile: Dockerfile
    default: true
handler:
  file: main
template:
  files:
    "{file}": "fn {handler} event => \"Hello, World!\"\n"
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
  workers: 1
triggers: [http, schedule, queue]
`

// qFiles are the files uploaded with the q plugin.
var qFiles = map[string]string{"Dockerfile": "FROM ghcr.io/brf-tech/reqs/golang:1.23-alpine\nCOPY . /src\n"}

// registeredRuntime is a runtime of POST /runtimes (or a seeded one).
type registeredRuntime struct {
	id       string
	source   string
	text     string
	manifest map[string]any
	files    map[string]string
	regions  []string // nil = every region
	enabled  bool
	tenant   string
	creator  string
	updated  string
}

// runtimeBuild is one region build of a dockerfile / git version.
type runtimeBuild struct {
	data      runr.RuntimeBuild
	pollsLeft int
	fails     bool
}

type runtimesState struct {
	registered map[string]*registeredRuntime
	order      []string
	builds     map[string][]*runtimeBuild
	failBuilds bool
}

func (s *Server) runtimes() *runtimesState {
	return featureState(s, "runtimes", func() *runtimesState {
		return &runtimesState{registered: map[string]*registeredRuntime{}, builds: map[string][]*runtimeBuild{}}
	})
}

// SeedRuntime registers a runtime from a manifest (YAML or JSON) as a platform runtime, the
// way POST /runtimes does, and returns its detail. The test fails on an invalid manifest.
func (s *Server) SeedRuntime(manifest string, files map[string]string) runr.RuntimeDetail {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.bearer = ""
	rt, err := s.registerRuntime("platform", manifest, files, nil)
	if err != nil {
		s.t.Fatalf("runrtest: SeedRuntime: %s %v", err.message, err.errors)
	}
	return s.runtimeDetail(rt.id)
}

// FailRuntimeBuilds makes the next runtime image builds end in "failed".
func (s *Server) FailRuntimeBuilds(fail bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.runtimes().failBuilds = fail
}

// RegisteredRuntime returns the manifest text and files of a registered runtime.
func (s *Server) RegisteredRuntime(id string) (manifest string, files map[string]string, ok bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	rt, ok := s.runtimes().registered[id]
	if !ok {
		return "", nil, false
	}
	out := map[string]string{}
	for k, v := range rt.files {
		out[k] = v
	}
	return rt.text, out, true
}

func init() {
	handle("GET /runtimes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if !s.Catalog {
			return 0, nil, errNotFound()
		}
		all := q.query("all") == "1" || q.query("all") == "true"
		region := q.query("region")
		list := []runr.CatalogRuntime{}
		for _, rt := range s.catalog() {
			if !rt.IsEnabled && !all {
				continue
			}
			if region != "" && len(rt.Regions) > 0 && !rtContains(rt.Regions, region) {
				continue
			}
			list = append(list, rt)
		}
		return 200, map[string]any{"data": list,
			"abilities":      map[string]bool{"manage": s.holds("runtimes.manage"), "use_custom": s.holds("runtimes.use_custom")},
			"custom_enabled": true}, nil
	})
	handle("GET /runtimes/{runtime}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if !s.Catalog || !s.runtimeExists(q.Segs[1]) {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": s.runtimeDetail(q.Segs[1])}, nil
	})
	handle("GET /runtimes/{runtime}/template", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if !s.Catalog {
			return 0, nil, errNotFound()
		}
		files, ok := s.runtimeTemplate(q.Segs[1], q.query("service_type"), q.query("handler"))
		if !ok {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"files": files}, nil
	})
	handle("POST /runtimes/validate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		text := q.str("manifest")
		if text == "" {
			return 0, nil, errValidation("manifest", "The manifest field is required.")
		}
		m, err := parseManifest(text, rtStringMap(q.Body["files"]))
		if err != nil {
			return 0, nil, err
		}
		return 200, map[string]any{"valid": true, "manifest": m}, nil
	})
	handle("POST /runtimes", func(s *Server, q *featureReq) (int, any, *apiErr) {
		text := q.str("manifest")
		if text == "" {
			return 0, nil, errValidation("manifest", "The manifest field is required.")
		}
		source := "custom"
		if s.holds("runtimes.manage") {
			source = "platform"
		}
		var regions []string
		if r, ok := q.Body["regions"].([]any); ok {
			regions = []string{}
			for _, x := range r {
				regions = append(regions, fmt.Sprint(x))
			}
		}
		rt, err := s.registerRuntime(source, text, rtStringMap(q.Body["files"]), regions)
		if err != nil {
			return 0, nil, err
		}
		return 201, map[string]any{"data": s.runtimeDetail(rt.id)}, nil
	})
	handle("PUT /runtimes/{runtime}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		id := q.Segs[1]
		if !s.runtimeExists(id) {
			return 0, nil, errNotFound()
		}
		rt, ok := s.runtimes().registered[id]
		if !ok {
			// Built-in runtimes: regions and is_enabled only.
			if _, has := q.Body["manifest"]; has {
				return 0, nil, errValidation("manifest", "The manifest of a built-in runtime follows the release.")
			}
			return 200, map[string]any{"data": s.runtimeDetail(id)}, nil
		}
		if text, has := q.Body["manifest"].(string); has {
			files := rt.files
			if f, ok := q.Body["files"]; ok {
				files = rtStringMap(f)
			}
			m, err := parseManifest(text, files)
			if err != nil {
				return 0, nil, err
			}
			if m["id"] != id {
				return 0, nil, errValidation("manifest", "The id of a runtime cannot change ("+id+").")
			}
			rt.text, rt.manifest, rt.files = text, m, files
			s.syncRuntimeImages(rt)
			s.startRuntimeBuilds(rt)
		}
		if v, has := q.Body["regions"]; has {
			if list, ok := v.([]any); ok {
				rt.regions = []string{}
				for _, x := range list {
					rt.regions = append(rt.regions, fmt.Sprint(x))
				}
			} else {
				rt.regions = nil
			}
		}
		if v, ok := q.Body["is_enabled"].(bool); ok {
			rt.enabled = v
		}
		rt.updated = nowRFC3339()
		return 200, map[string]any{"data": s.runtimeDetail(id)}, nil
	})
	handle("DELETE /runtimes/{runtime}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		id := q.Segs[1]
		if !s.runtimeExists(id) {
			return 0, nil, errNotFound()
		}
		st := s.runtimes()
		if _, ok := st.registered[id]; !ok {
			return 0, nil, &apiErr{status: 403, message: "Built-in runtimes cannot be deleted."}
		}
		if n := s.projectsOn(id); n > 0 {
			return 0, nil, &apiErr{status: 409, message: fmt.Sprintf("%d projects use this runtime.", n)}
		}
		delete(st.registered, id)
		for i, x := range st.order {
			if x == id {
				st.order = append(st.order[:i], st.order[i+1:]...)
				break
			}
		}
		delete(st.builds, id)
		imgs := s.images[:0]
		for _, img := range s.images {
			if img.Runtime != id {
				imgs = append(imgs, img)
			}
		}
		s.images = imgs
		return 204, nil, nil
	})
	handle("GET /runtimes/{runtime}/builds", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if !s.runtimeExists(q.Segs[1]) {
			return 0, nil, errNotFound()
		}
		return 200, map[string]any{"data": s.runtimeBuilds(q.Segs[1], true)}, nil
	})
	handle("POST /runtimes/{runtime}/builds", func(s *Server, q *featureReq) (int, any, *apiErr) {
		id := q.Segs[1]
		if !s.runtimeExists(id) {
			return 0, nil, errNotFound()
		}
		reg := s.regionByID(q.str("knative_region_id"))
		if reg == nil {
			return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
		}
		rt := s.runtimes().registered[id]
		var versions []string
		if rt != nil {
			for _, v := range manifestVersions(rt.manifest) {
				if v["image"] == nil && (q.str("version") == "" || v["version"] == q.str("version")) {
					versions = append(versions, fmt.Sprint(v["version"]))
				}
			}
		}
		if len(versions) == 0 {
			return 0, nil, errValidation("version", "The runtime has no dockerfile or git versions to build.")
		}
		for _, v := range versions {
			s.queueRuntimeBuild(id, v, reg)
		}
		return 202, map[string]any{"data": s.runtimeBuilds(id, false)}, nil
	})
	handle("POST /runtimes/{runtime}/test-deploy", func(s *Server, q *featureReq) (int, any, *apiErr) {
		id := q.Segs[1]
		if !s.runtimeExists(id) {
			return 0, nil, errNotFound()
		}
		reg := s.regionByID(q.str("knative_region_id"))
		if reg == nil {
			return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
		}
		s.seq++
		p := s.createProject(map[string]any{"name": fmt.Sprintf("rt-%s-t%04d", id, s.seq), "service_type": runr.TypeXaaS,
			"runtime": id, "runtime_version": q.str("version"), "knative_region_id": reg.ID,
			"config": map[string]any{"trigger_type": "http"}})
		files, _ := s.runtimeTemplate(id, runr.TypeXaaS, "")
		v := s.saveVersion(p, files, "runtime test deploy", false)
		d := s.startDeployment(p, v)
		d.pollsLeft = 0
		return 201, map[string]any{"data": s.projectResource(p)}, nil
	})
}

// ---------------------------------------------------------------------------------------
// Catalog

// catalog builds GET /runtimes: built-in runtimes and plugins from the executor images, then
// the registered runtimes.
func (s *Server) catalog() []runr.CatalogRuntime {
	st := s.runtimes()
	byID := map[string]*runr.CatalogRuntime{}
	var ids []string
	for _, img := range s.images {
		if _, reg := st.registered[img.Runtime]; reg {
			continue
		}
		rt, ok := byID[img.Runtime]
		if !ok {
			if p, plugin := pluginRuntimes[img.Runtime]; plugin {
				c := p
				rt = &c
			} else {
				rt = &runr.CatalogRuntime{ID: img.Runtime, Name: img.Runtime, Source: "builtin", RunMode: "http",
					Compiled: img.Runtime == "go", Triggers: []string{"http", "schedule", "queue"}, IsEnabled: true,
					Sandbox: []string{"runc", "gvisor", "kata"}, Extensions: []string{ext(img.Runtime)},
					Handler: &runr.RuntimeHandler{File: "main", Function: "handler", Filename: "main." + ext(img.Runtime)}}
			}
			byID[img.Runtime] = rt
			ids = append(ids, img.Runtime)
		}
		v := runr.RuntimeVersion{Version: img.Version, IsDefault: img.IsDefault, EOLAt: img.EOLAt}
		if _, plugin := pluginRuntimes[img.Runtime]; plugin {
			v.Built = true
		} else {
			v.Image = "ghcr.io/brf-tech/exec-" + img.Runtime + ":" + img.Version
		}
		rt.Versions = append(rt.Versions, v)
	}
	for _, id := range st.order {
		c := s.registeredCatalog(st.registered[id])
		byID[id] = &c
		ids = append(ids, id)
	}
	sort.Strings(ids)
	out := make([]runr.CatalogRuntime, 0, len(ids))
	for _, id := range ids {
		rt := *byID[id]
		rt.ProjectsCount = s.projectsOn(id)
		out = append(out, rt)
	}
	return out
}

func (s *Server) runtimeExists(id string) bool {
	for _, rt := range s.catalog() {
		if rt.ID == id {
			return true
		}
	}
	return false
}

func (s *Server) projectsOn(id string) int {
	n := 0
	for _, p := range s.projects {
		if p.data.Runtime == id {
			n++
		}
	}
	return n
}

// registeredCatalog is the catalog entry of a registered runtime (RuntimeResource).
func (s *Server) registeredCatalog(rt *registeredRuntime) runr.CatalogRuntime {
	m := rt.manifest
	run, _ := m["run"].(map[string]any)
	handler, _ := m["handler"].(map[string]any)
	exts := rtStrs(m["extensions"])
	c := runr.CatalogRuntime{
		ID: rt.id, Name: fmt.Sprint(m["name"]), Language: fmt.Sprint(m["language"]), Extensions: exts,
		Category: fmt.Sprint(m["category"]), Source: rt.source, RunMode: fmt.Sprint(run["mode"]),
		Compiled: m["build"] != nil, Triggers: rtStrs(m["triggers"]), Sandbox: rtStrs(m["sandbox"]),
		Regions: rt.regions, IsEnabled: rt.enabled, TenantID: rt.tenant, CreatedBy: runr.FlexID(rt.creator),
		UpdatedAt: rt.updated,
	}
	if d, ok := m["description"].(string); ok {
		c.Description = d
	}
	if rc, ok := m["runtimeClass"].(string); ok {
		c.RuntimeClass = rc
		// WasmRuntimes::shimOf: the WebAssembly shims' RuntimeClasses
		c.Wasm = map[string]string{"wasmtime-spin-v2": "spin", "wasmtime-v1": "wasmtime"}[rc]
	}
	if deps, ok := m["dependencies"].(map[string]any); ok {
		c.DependencyFile = fmt.Sprint(deps["file"])
	}
	file := fmt.Sprint(handler["file"])
	c.Handler = &runr.RuntimeHandler{File: file, Function: fmt.Sprint(handler["function"]), Filename: file + "." + rtFirst(exts)}
	if p, ok := handler["pattern"].(string); ok {
		c.Handler.Pattern = p
	}
	for _, v := range manifestVersions(m) {
		rv := runr.RuntimeVersion{Version: fmt.Sprint(v["version"]), IsDefault: v["default"] == true}
		if img, ok := v["image"].(string); ok {
			rv.Image = img
		} else {
			rv.Built = true
		}
		if eol, ok := v["eol"].(string); ok {
			rv.EOLAt = eol
		}
		rv.GPURequired = v["gpu"] == true
		c.Versions = append(c.Versions, rv)
	}
	return c
}

// runtimeDetail is GET /runtimes/{id} (RuntimeResource::detail).
func (s *Server) runtimeDetail(id string) runr.RuntimeDetail {
	var d runr.RuntimeDetail
	for _, rt := range s.catalog() {
		if rt.ID == id {
			d.CatalogRuntime = rt
		}
	}
	text, files := "", map[string]string{}
	if rt, ok := s.runtimes().registered[id]; ok {
		text, files = rt.text, rt.files
	} else if id == "q" {
		text, files = qManifest, qFiles
	} else {
		text = fmt.Sprintf("apiVersion: runr.sh/v1\nkind: Runtime\nid: %s\nname: %s\nextensions: [%s]\nrun:\n  mode: http\nversions:\n", id, id, ext(id))
		for _, v := range d.Versions {
			text += fmt.Sprintf("  - version: %q\n    image: %s\n    default: %v\n", v.Version, v.Image, v.IsDefault)
		}
	}
	m, err := parseManifest(text, files)
	if err == nil {
		raw, _ := json.Marshal(m)
		var man runr.RuntimeManifest
		if json.Unmarshal(raw, &man) == nil {
			d.Manifest = &man
		}
	}
	d.ManifestSource = text
	d.Files = files
	return d
}

func (s *Server) runtimeTemplate(id, serviceType, handler string) (map[string]string, bool) {
	if !s.runtimeExists(id) {
		return nil, false
	}
	if rt, ok := s.runtimes().registered[id]; ok {
		h, _ := rt.manifest["handler"].(map[string]any)
		file, fn := fmt.Sprint(h["file"]), fmt.Sprint(h["function"])
		if serviceType == runr.TypeFaaS && handler != "" {
			if b, f, ok := cut(handler); ok {
				file, fn = b, f
			}
		} else if handler != "" {
			fn = handler
		}
		name := file + "." + rtFirst(rtStrs(rt.manifest["extensions"]))
		tpl, _ := rt.manifest["template"].(map[string]any)
		out := map[string]string{}
		for k, v := range rtStringMap(tpl["files"]) {
			r := strings.NewReplacer("{file}", name, "{handler}", fn, "{Handler}", rtUpperFirst(fn))
			out[r.Replace(k)] = r.Replace(v)
		}
		return out, true
	}
	file, fn := "main", "handler"
	if serviceType == runr.TypeFaaS && handler != "" {
		if b, f, ok := cut(handler); ok {
			file, fn = b, f
		}
	}
	name := file + "." + ext(id)
	body := map[string]string{
		"q": "fn " + fn + " event => \"Hello, World!\"\n",
	}[id]
	if body == "" {
		body = "def " + fn + "(event, context):\n    return {\"statusCode\": 200, \"body\": \"Hello, World!\"}\n"
	}
	return map[string]string{name: body}, true
}

func cut(handler string) (string, string, bool) {
	for i := 0; i < len(handler); i++ {
		if handler[i] == '.' {
			return handler[:i], handler[i+1:], i > 0 && i < len(handler)-1
		}
	}
	return handler, "", false
}

// ---------------------------------------------------------------------------------------
// Registration and builds

func (s *Server) registerRuntime(source, text string, files map[string]string, regions []string) (*registeredRuntime, *apiErr) {
	m, err := parseManifest(text, files)
	if err != nil {
		return nil, err
	}
	id := fmt.Sprint(m["id"])
	if s.runtimeExists(id) {
		return nil, manifestInvalid(rtProblem{"/id", "a runtime with the id " + id + " exists already"})
	}
	if files == nil {
		files = map[string]string{}
	}
	rt := &registeredRuntime{id: id, source: source, text: text, manifest: m, files: files, regions: regions,
		enabled: true, creator: s.User.ID.String(), updated: nowRFC3339()}
	st := s.runtimes()
	st.registered[id] = rt
	st.order = append(st.order, id)
	s.syncRuntimeImages(rt)
	s.startRuntimeBuilds(rt)
	return rt, nil
}

// syncRuntimeImages keeps the executor images of a registered runtime in step with its
// versions (RuntimeRegistry::syncVersions), so projects can use it.
func (s *Server) syncRuntimeImages(rt *registeredRuntime) {
	imgs := s.images[:0]
	for _, img := range s.images {
		if img.Runtime != rt.id {
			imgs = append(imgs, img)
		}
	}
	s.images = imgs
	for i, v := range manifestVersions(rt.manifest) {
		img := runr.ExecutorImage{ID: s.newID(), Runtime: rt.id, Version: fmt.Sprint(v["version"]),
			IsDefault: v["default"] == true, SortOrder: i + 1}
		if eol, ok := v["eol"].(string); ok {
			img.EOLAt = eol
		}
		s.images = append(s.images, img)
	}
}

// startRuntimeBuilds queues the image builds of dockerfile / git versions in every active
// region (RuntimeImageBuildService::ensureAll).
func (s *Server) startRuntimeBuilds(rt *registeredRuntime) {
	for _, v := range manifestVersions(rt.manifest) {
		if v["image"] != nil {
			continue
		}
		for _, reg := range s.regions {
			if reg.IsActive {
				s.queueRuntimeBuild(rt.id, fmt.Sprint(v["version"]), reg)
			}
		}
	}
}

func (s *Server) queueRuntimeBuild(id, version string, reg *runr.Region) {
	st := s.runtimes()
	list := st.builds[id]
	b := &runtimeBuild{pollsLeft: s.BuildPolls, fails: st.failBuilds,
		data: runr.RuntimeBuild{Version: version, RegionID: reg.ID, RegionName: reg.Name, Status: runr.RuntimeBuildPending,
			UpdatedAt: nowRFC3339()}}
	for i, x := range list {
		if x.data.Version == version && x.data.RegionID == reg.ID {
			list[i] = b
			return
		}
	}
	st.builds[id] = append(list, b)
}

// runtimeBuilds lists the builds of a runtime; advance moves them on like a poll of the
// region's Kaniko Jobs.
func (s *Server) runtimeBuilds(id string, advance bool) []runr.RuntimeBuild {
	out := []runr.RuntimeBuild{}
	for _, b := range s.runtimes().builds[id] {
		if advance && (b.data.Status == runr.RuntimeBuildPending || b.data.Status == runr.RuntimeBuildBuilding) {
			if b.pollsLeft > 0 {
				b.pollsLeft--
				b.data.Status = runr.RuntimeBuildBuilding
			} else if b.fails {
				b.data.Status = runr.RuntimeBuildFailed
				b.data.Error = "error building image: COPY failed: file not found in build context"
			} else {
				b.data.Status = runr.RuntimeBuildReady
				b.data.Image = "registry.runr.test/runr-runtimes/" + id + ":" + b.data.Version + "-0123abcd"
			}
			b.data.UpdatedAt = nowRFC3339()
		}
		out = append(out, b.data)
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].Version < out[j].Version })
	return out
}

// ---------------------------------------------------------------------------------------
// Manifest validation (the subset of ManifestValidator the fake needs)

type rtProblem struct{ path, message string }

// manifestInvalid is the 422 of InvalidManifestException::render.
func manifestInvalid(problems ...rtProblem) *apiErr {
	msgs := make([]string, len(problems))
	for i, p := range problems {
		msgs[i] = p.path + ": " + p.message
	}
	return &apiErr{status: 422, message: "The runtime manifest is not valid.", errors: map[string][]string{"manifest": msgs}}
}

var (
	runtimeIDPattern = regexp.MustCompile(`^[a-z][a-z0-9-]{0,31}$`)
	reservedRuntimes = map[string]bool{"docker": true, "caas": true, "custom": true, "runtime": true, "runtimes": true, "validate": true}
)

// parseManifest decodes and validates a manifest and returns it normalized.
func parseManifest(text string, files map[string]string) (map[string]any, *apiErr) {
	if strings.TrimSpace(text) == "" {
		return nil, manifestInvalid(rtProblem{"/", "manifest is empty"})
	}
	var raw any
	if err := yaml.Unmarshal([]byte(text), &raw); err != nil {
		return nil, manifestInvalid(rtProblem{"/", "is not valid YAML or JSON: " + err.Error()})
	}
	data, ok := raw.(map[string]any)
	if !ok {
		return nil, manifestInvalid(rtProblem{"/", "must be a mapping (runtime.yaml)"})
	}
	var probs []rtProblem
	add := func(path, msg string) { probs = append(probs, rtProblem{path, msg}) }
	if data["apiVersion"] != "runr.sh/v1" {
		add("/apiVersion", "must be runr.sh/v1")
	}
	if data["kind"] != "Runtime" {
		add("/kind", "must be Runtime")
	}
	id, _ := data["id"].(string)
	switch {
	case id == "":
		add("/id", "is required")
	case !runtimeIDPattern.MatchString(id):
		add("/id", "must match ^[a-z][a-z0-9-]{0,31}$")
	case reservedRuntimes[id]:
		add("/id", id+" is reserved")
	}
	if name, _ := data["name"].(string); name == "" {
		add("/name", "is required")
	}
	if len(rtStrs(data["extensions"])) == 0 {
		add("/extensions", "is required")
	}
	run, _ := data["run"].(map[string]any)
	mode, _ := run["mode"].(string)
	switch mode {
	case "http", "exec", "persistent":
	case "":
		add("/run/mode", "is required")
	default:
		add("/run/mode", "must be http, exec or persistent")
	}
	if (mode == "exec" || mode == "persistent") && len(rtStrs(run["command"])) == 0 {
		add("/run/command", "is required in "+mode+" mode")
	}
	versions, _ := data["versions"].([]any)
	if len(versions) == 0 {
		add("/versions", "is required")
	}
	for i, x := range versions {
		v, _ := x.(map[string]any)
		n := 0
		for _, k := range []string{"image", "dockerfile", "git"} {
			if v[k] != nil {
				n++
			}
		}
		if n != 1 {
			add(fmt.Sprintf("/versions/%d", i), "needs exactly one of image, dockerfile and git")
		}
		if df, ok := v["dockerfile"].(string); ok {
			if _, has := files[df]; !has {
				add(fmt.Sprintf("/versions/%d/dockerfile", i), df+" is not among the uploaded files")
			}
		}
		if _, ok := v["version"]; !ok {
			add(fmt.Sprintf("/versions/%d/version", i), "is required")
		}
	}
	if len(probs) > 0 {
		return nil, manifestInvalid(probs...)
	}
	return normalizeManifest(data), nil
}

// normalizeManifest fills in the defaults like ManifestValidator::normalize.
func normalizeManifest(data map[string]any) map[string]any {
	run, _ := data["run"].(map[string]any)
	mode := fmt.Sprint(run["mode"])
	or := func(v any, def any) any {
		if v == nil {
			return def
		}
		return v
	}
	normRun := map[string]any{"mode": mode, "command": run["command"], "schedule": run["schedule"], "input": run["input"],
		"workers": run["workers"], "env": or(run["env"], map[string]any{})}
	if mode == "http" && normRun["schedule"] == nil {
		normRun["schedule"] = "invoke"
	}
	if mode == "exec" && normRun["input"] == nil {
		normRun["input"] = "json"
	}
	if mode == "persistent" && normRun["workers"] == nil {
		normRun["workers"] = 1
	}
	handler, _ := data["handler"].(map[string]any)
	tpl, _ := data["template"].(map[string]any)
	out := map[string]any{
		"apiVersion": data["apiVersion"], "kind": data["kind"], "id": data["id"], "name": data["name"],
		"description": data["description"], "language": or(data["language"], "plaintext"), "extensions": data["extensions"],
		"category": or(data["category"], "general"),
		"handler":  map[string]any{"file": or(handler["file"], "main"), "function": or(handler["function"], "handler"), "pattern": handler["pattern"]},
		"template": map[string]any{"files": or(tpl["files"], map[string]any{"{file}": ""})},
		"run":      normRun, "triggers": or(data["triggers"], []any{"http", "schedule", "queue"}),
		"sandbox": or(data["sandbox"], []any{"runc", "gvisor", "kata"}), "resources": or(data["resources"], map[string]any{}),
		"runtimeClass": data["runtimeClass"],
	}
	var versions []any
	hasDefault := false
	for _, x := range data["versions"].([]any) {
		v, _ := x.(map[string]any)
		nv := map[string]any{"version": fmt.Sprint(v["version"]), "image": v["image"], "dockerfile": v["dockerfile"],
			"git": nil, "buildArgs": or(v["buildArgs"], map[string]any{}), "default": v["default"] == true,
			"eol": v["eol"], "gpu": v["gpu"] == true}
		if g, ok := v["git"].(map[string]any); ok {
			nv["git"] = map[string]any{"url": g["url"], "ref": g["ref"], "path": or(g["path"], ""), "dockerfile": or(g["dockerfile"], "Dockerfile")}
		}
		hasDefault = hasDefault || nv["default"] == true
		versions = append(versions, nv)
	}
	if !hasDefault && len(versions) > 0 {
		versions[len(versions)-1].(map[string]any)["default"] = true
	}
	out["versions"] = versions
	if deps, ok := data["dependencies"].(map[string]any); ok {
		nd := map[string]any{"template": nil, "image": "{image}", "copy": []any{}, "env": map[string]any{}}
		for k, v := range deps {
			nd[k] = v
		}
		out["dependencies"] = nd
	}
	if build, ok := data["build"].(map[string]any); ok {
		nb := map[string]any{"image": "{image}", "runImage": "{image}", "source": true, "files": map[string]any{}}
		for k, v := range build {
			nb[k] = v
		}
		out["build"] = nb
	}
	return out
}

func manifestVersions(m map[string]any) []map[string]any {
	var out []map[string]any
	list, _ := m["versions"].([]any)
	for _, x := range list {
		if v, ok := x.(map[string]any); ok {
			out = append(out, v)
		}
	}
	return out
}

func rtStrs(v any) []string {
	list, _ := v.([]any)
	out := make([]string, 0, len(list))
	for _, x := range list {
		out = append(out, fmt.Sprint(x))
	}
	return out
}

func rtStringMap(v any) map[string]string {
	m, ok := v.(map[string]any)
	if !ok {
		return nil
	}
	out := make(map[string]string, len(m))
	for k, x := range m {
		out[k] = fmt.Sprint(x)
	}
	return out
}

func rtFirst(s []string) string {
	if len(s) == 0 {
		return ""
	}
	return s[0]
}

func rtContains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

func rtUpperFirst(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
