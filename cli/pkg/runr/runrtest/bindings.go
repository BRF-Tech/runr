package runrtest

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/runrsh/core/cli/pkg/runr"
)

// Bindings of the fake (api BindingController, ProjectBindingController, BindingManager):
// bindings are provisioned after BindingConfig.ProvisionPolls reads, rotation likewise.

// BindingConfig changes how the fake's bindings behave; get it with Server.Bindings.
type BindingConfig struct {
	// ProvisionPolls is how many reads a new (or rotating) binding stays provisioning. Default 1.
	ProvisionPolls int
	// FailProvision makes new bindings end in status error with FailMessage.
	FailProvision bool
	FailMessage   string
	// DeniedRegions are region names the data residency policy refuses (422 region_not_allowed).
	DeniedRegions []string
	// RetentionDays of a deleted binding. Default 7.
	RetentionDays int
}

type bindingState struct {
	cfg         BindingConfig
	bindings    []*fakeBinding
	attachments []*fakeAttachment
}

type fakeBinding struct {
	data        runr.Binding
	values      map[string]string
	credentials map[string]string
	pollsLeft   int
	fails       bool
	failMsg     string
}

type fakeAttachment struct {
	id        string
	bindingID string
	projectID string
	envPrefix string
	createdAt string
}

func (s *Server) bindSt() *bindingState {
	return featureState(s, "bindings", func() *bindingState {
		return &bindingState{cfg: BindingConfig{ProvisionPolls: 1, FailMessage: "provisioning failed: the data service refused the request", RetentionDays: 7}}
	})
}

// Bindings returns the binding settings of the fake; change them before the requests.
func (s *Server) Bindings() *BindingConfig {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.bindSt().cfg
}

// SeedBinding creates a ready binding in a region ("" for an external binding) and returns it.
func (s *Server) SeedBinding(name, bindingType, regionName string, settings map[string]any, values map[string]string) runr.Binding {
	s.mu.Lock()
	defer s.mu.Unlock()
	rid := ""
	if bindingType != runr.BindingExternal {
		reg := s.regionByName(regionName)
		if reg == nil {
			s.t.Fatalf("runrtest: region %q does not exist", regionName)
		}
		rid = reg.ID
	}
	b := s.newBinding(name, bindingType, "", rid, settings, values)
	b.data.Status = runr.BindingReady
	b.pollsLeft = 0
	b.fails = false
	return s.bindingResource(b, true)
}

// BindingByName returns a binding of the fake with its attachments.
func (s *Server) BindingByName(name string) (runr.Binding, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, b := range s.bindSt().bindings {
		if b.data.Name == name {
			return s.bindingResource(b, true), true
		}
	}
	return runr.Binding{}, false
}

// BindingCredentialsOf returns the credentials the fake keeps for a binding.
func (s *Server) BindingCredentialsOf(name string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, b := range s.bindSt().bindings {
		if b.data.Name == name {
			out := map[string]string{}
			for k, v := range b.credentials {
				out[k] = v
			}
			return out
		}
	}
	return nil
}

// ProjectAttachments returns the bindings attached to a project (by project name).
func (s *Server) ProjectAttachments(projectName string) []runr.BindingAttachment {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return nil
	}
	var out []runr.BindingAttachment
	for _, a := range s.bindSt().attachments {
		if a.projectID == p.data.ID {
			out = append(out, s.bindingAttachmentResource(a, true))
		}
	}
	return out
}

var (
	bindingNamePattern = regexp.MustCompile(`^[a-z]([-a-z0-9]*[a-z0-9])?$`)
	bindingEnvPattern  = regexp.MustCompile(`^[A-Z][A-Z0-9_]*$`)
	bindingPrefixRe    = regexp.MustCompile(`^[A-Z][A-Z0-9_]*_$`)
	bindingTimeoutRe   = regexp.MustCompile(`^\d+(ms|s|min)$`)
)

// bindingEnvKeys are BindingEnv::KEYS.
var bindingEnvKeys = map[string][]string{
	runr.BindingKV:       {"REDIS_URL", "KV_PREFIX"},
	runr.BindingObject:   {"S3_ENDPOINT", "S3_BUCKET", "S3_ACCESS_KEY_ID", "S3_SECRET_ACCESS_KEY", "S3_REGION", "S3_FORCE_PATH_STYLE"},
	runr.BindingPostgres: {"DATABASE_URL", "PGHOST", "PGPORT", "PGDATABASE", "PGUSER", "PGPASSWORD", "PGSSLMODE"},
}

func init() {
	handle("GET /bindings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.bindSt()
		typ, region := q.query("type"), q.query("knative_region_id")
		list := []runr.Binding{}
		for _, b := range s.sortedBindings() {
			if typ != "" && b.data.Type != typ {
				continue
			}
			if region != "" && b.data.RegionID != region && b.data.Type != runr.BindingExternal {
				continue
			}
			s.advanceBinding(st, b)
			list = append(list, s.bindingResource(b, true))
		}
		return 200, map[string]any{"data": list, "abilities": s.bindingAbilities()}, nil
	})
	handle("GET /bindings/types", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, map[string]any{
			"data": map[string]any{
				"kv": map[string]any{"env_keys": bindingEnvKeys[runr.BindingKV], "plans": []string{"shared"},
					"defaults": map[string]any{"memory_mb": 64}, "max": map[string]any{"memory_mb": 1024}},
				"object": map[string]any{"env_keys": bindingEnvKeys[runr.BindingObject], "plans": []string{"shared"},
					"defaults": map[string]any{"quota_gb": 5}, "max": map[string]any{"quota_gb": 100}},
				"postgres": map[string]any{"env_keys": bindingEnvKeys[runr.BindingPostgres], "plans": []string{"shared", "dedicated"},
					"defaults": map[string]any{"storage_gb": 1, "connection_limit": 20, "statement_timeout": "30s", "instances": 1, "dedicated_storage_gb": 5},
					"max":      map[string]any{"storage_gb": 50, "connection_limit": 100, "instances": 3}},
				"external": map[string]any{"env_keys": []string{}, "plans": []string{"external"}, "defaults": []any{},
					"max": map[string]any{"values": 50}},
			},
			"retention_days": s.bindSt().cfg.RetentionDays,
			"abilities":      s.bindingAbilities(),
		}, nil
	})
	handle("GET /bindings/quota", func(s *Server, q *featureReq) (int, any, *apiErr) {
		used := map[string]any{"bindings": len(s.bindSt().bindings), "kv_mb": 0, "object_gb": 0, "postgres_gb": 0, "dedicated_postgres": 0}
		for _, b := range s.bindSt().bindings {
			switch b.data.Type {
			case runr.BindingKV:
				used["kv_mb"] = used["kv_mb"].(int) + bindSettingInt(b.data.Settings, "memory_mb")
			case runr.BindingObject:
				used["object_gb"] = used["object_gb"].(int) + bindSettingInt(b.data.Settings, "quota_gb")
			case runr.BindingPostgres:
				used["postgres_gb"] = used["postgres_gb"].(int) + bindSettingInt(b.data.Settings, "storage_gb")
				if b.data.Plan == runr.BindingPlanDedicated {
					used["dedicated_postgres"] = used["dedicated_postgres"].(int) + 1
				}
			}
		}
		return 200, map[string]any{"data": map[string]any{
			"limits": map[string]any{"max_bindings": 20, "kv_mb": 1024, "object_gb": 100, "postgres_gb": 50, "dedicated_postgres": 2},
			"used":   used, "measured_bytes": map[string]any{"kv": 0, "object": 0, "postgres": 0},
		}}, nil
	})
	handle("POST /bindings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		in, err := s.validateBinding(q.Body, "", "")
		if err != nil {
			return 0, nil, err
		}
		b, err := s.createBinding(in)
		if err != nil {
			return 0, nil, err
		}
		return 201, map[string]any{"data": s.bindingResource(b, true)}, nil
	})
	handle("GET /bindings/{binding}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		s.advanceBinding(s.bindSt(), b)
		return 200, map[string]any{"data": s.bindingResource(b, true), "abilities": s.bindingAbilities()}, nil
	})
	handle("PATCH /bindings/{binding}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		values, hasValues := q.Body["values"].(map[string]any)
		if hasValues && b.data.Type != runr.BindingExternal {
			return 0, nil, errValidation("values", "Only external bindings have values.")
		}
		if b.data.Deleted() {
			return 0, nil, errValidation("settings", "Binding "+b.data.Name+" is being deleted.")
		}
		if settings, ok := q.Body["settings"].(map[string]any); ok {
			if err := validateBindingSettings(settings, "settings."); err != nil {
				return 0, nil, err
			}
			merged := map[string]any{}
			for k, v := range b.data.Settings {
				merged[k] = v
			}
			for k, v := range settings {
				merged[k] = v
			}
			if b.data.Plan == runr.BindingPlanDedicated && bindSettingInt(merged, "storage_gb") < bindSettingInt(b.data.Settings, "storage_gb") {
				return 0, nil, errValidation("settings", "The storage of a dedicated Postgres cluster can only grow.")
			}
			b.data.Settings = normalizeBindingSettings(b.data.Type, b.data.Plan, merged)
		}
		if hasValues {
			vals, err := bindingValues(values, "values")
			if err != nil {
				return 0, nil, err
			}
			b.values = vals
			b.credentials = vals
		}
		b.data.UpdatedAt = nowRFC3339()
		return 200, map[string]any{"data": s.bindingResource(b, true)}, nil
	})
	handle("DELETE /bindings/{binding}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		if q.str("confirm") != b.data.Name {
			return 0, nil, errValidation("confirm", "Type the binding name to confirm the deletion.")
		}
		if b.data.Deleted() {
			return 0, nil, errValidation("binding", "Binding "+b.data.Name+" is being deleted.")
		}
		st := s.bindSt()
		kept := st.attachments[:0]
		for _, a := range st.attachments {
			if a.bindingID != b.data.ID {
				kept = append(kept, a)
			}
		}
		st.attachments = kept
		purge := time.Now().UTC().AddDate(0, 0, st.cfg.RetentionDays)
		b.data.Status = runr.BindingDeleting
		b.data.DeleteRequestedAt = nowRFC3339()
		b.data.PurgeAt = purge.Format(time.RFC3339)
		return 202, map[string]any{
			"message": "Binding detached and scheduled for deletion. Its data is deleted on " + purge.Format("2006-01-02") + " unless you restore it.",
			"data":    s.bindingResource(b, true),
		}, nil
	})
	handle("POST /bindings/{binding}/restore", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		if b.data.Status != runr.BindingDeleting {
			return 0, nil, errValidation("binding", "Binding "+b.data.Name+" is not scheduled for deletion.")
		}
		if err := s.bindingResidency(b.data.RegionID, "knative_region_id"); err != nil {
			return 0, nil, err
		}
		b.data.Status = runr.BindingReady
		b.data.DeleteRequestedAt = ""
		b.data.PurgeAt = ""
		return 200, map[string]any{"message": "Binding restored. Attach it to projects again to use it.", "data": s.bindingResource(b, true)}, nil
	})
	handle("POST /bindings/{binding}/rotate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		if b.data.Status != runr.BindingReady {
			return 0, nil, errValidation("binding", "Binding "+b.data.Name+" is "+b.data.Status+", not ready.")
		}
		b.data.Status = runr.BindingRotating
		b.pollsLeft = s.bindSt().cfg.ProvisionPolls
		b.fails = false
		return 202, map[string]any{"message": "Rotating credentials. Attached functions restart with the new ones.", "data": s.bindingResource(b, true)}, nil
	})
	handle("GET /bindings/{binding}/status", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		s.advanceBinding(s.bindSt(), b)
		health := map[string]any{"state": b.data.Status, "message": bindNilIfEmpty(b.data.StatusMessage), "details": []any{}}
		if b.data.Status == runr.BindingReady || b.data.Status == runr.BindingRotating {
			health = map[string]any{"state": "healthy", "message": nil, "details": bindingHealthDetails(b)}
		}
		return 200, map[string]any{"data": s.bindingResource(b, false), "health": health}, nil
	})
	handle("GET /bindings/{binding}/credentials", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		env := map[string]string{}
		for k, v := range b.credentials {
			env[k] = v
		}
		if len(env) == 0 {
			return 200, map[string]any{"data": map[string]any{"env": []any{}}}, nil
		}
		return 200, map[string]any{"data": map[string]any{"env": env}}, nil
	})
	handle("GET /bindings/{binding}/usage", func(s *Server, q *featureReq) (int, any, *apiErr) {
		b := s.bindingByID(q.Segs[1])
		if b == nil {
			return 0, nil, bindingNotFound()
		}
		day := time.Now().UTC().AddDate(0, 0, -1).Format("2006-01-02")
		return 200, map[string]any{"data": []map[string]any{{
			"record_date": day, "storage_gb_hours": 1.5, "instance_hours": 0.0, "peak_bytes": 1048576,
			"final_cost": 0.01, "currency": "EUR",
		}}}, nil
	})

	handle("GET /projects/{project}/bindings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.bindSt()
		data := []runr.BindingAttachment{}
		attached := map[string]bool{}
		for _, a := range st.attachments {
			if a.projectID == p.data.ID {
				if b := s.bindingByID(a.bindingID); b != nil {
					s.advanceBinding(st, b)
				}
				data = append(data, s.bindingAttachmentResource(a, true))
				attached[a.bindingID] = true
			}
		}
		available := []runr.Binding{}
		if s.holds("bindings.view") {
			for _, b := range s.sortedBindings() {
				if attached[b.data.ID] || b.data.Deleted() {
					continue
				}
				if b.data.RegionID == p.data.RegionID || b.data.Type == runr.BindingExternal {
					available = append(available, s.bindingResource(b, false))
				}
			}
		}
		return 200, map[string]any{"data": data, "available": available, "secret": p.data.Slug + "-bindings",
			"abilities": s.bindingAbilities()}, nil
	})
	handle("POST /projects/{project}/bindings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.needBindingsManage(); err != nil {
			return 0, nil, err
		}
		bindingID := q.str("binding_id")
		nested, hasNested := q.Body["binding"].(map[string]any)
		if bindingID == "" && !hasNested {
			return 0, nil, errValidation("binding_id", "The binding id field is required when binding is not present.")
		}
		prefix := q.str("env_prefix")
		if prefix != "" && (!bindingPrefixRe.MatchString(prefix) || len(prefix) > 32) {
			return 0, nil, errValidation("env_prefix", "The env prefix field format is invalid.")
		}
		var b *fakeBinding
		if bindingID != "" {
			if b = s.bindingByID(bindingID); b == nil {
				return 0, nil, bindingNotFound()
			}
		} else {
			if p.data.RegionID == "" {
				return 0, nil, errValidation("binding", "The project needs a region before a binding can be created for it.")
			}
			in, err := s.validateBinding(nested, "binding.", p.data.RegionID)
			if err != nil {
				return 0, nil, err
			}
			if b, err = s.createBinding(in); err != nil {
				return 0, nil, err
			}
		}
		a, aerr := s.attachBinding(b, p, prefix)
		if aerr != nil {
			return 0, nil, aerr
		}
		return 201, map[string]any{"data": s.bindingAttachmentResource(a, true)}, nil
	})
	handle("PATCH /projects/{project}/bindings/{attachment}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.needBindingsManage(); err != nil {
			return 0, nil, err
		}
		prefix := q.str("env_prefix")
		if prefix != "" && (!bindingPrefixRe.MatchString(prefix) || len(prefix) > 32) {
			return 0, nil, errValidation("env_prefix", "The env prefix field format is invalid.")
		}
		a := s.bindingAttachmentByID(p, q.Segs[3])
		if a == nil {
			return 0, nil, &apiErr{status: 404, message: "No query results for model [App\\Models\\BindingAttachment]."}
		}
		b := s.bindingByID(a.bindingID)
		if b != nil {
			if err := s.bindingEnvConflict(b, p, prefix, a.id); err != nil {
				return 0, nil, errValidation("env_prefix", err.message)
			}
		}
		a.envPrefix = prefix
		return 200, map[string]any{"data": s.bindingAttachmentResource(a, true)}, nil
	})
	handle("DELETE /projects/{project}/bindings/{attachment}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.needBindingsManage(); err != nil {
			return 0, nil, err
		}
		a := s.bindingAttachmentByID(p, q.Segs[3])
		if a == nil {
			return 0, nil, &apiErr{status: 404, message: "No query results for model [App\\Models\\BindingAttachment]."}
		}
		st := s.bindSt()
		for i, x := range st.attachments {
			if x == a {
				st.attachments = append(st.attachments[:i], st.attachments[i+1:]...)
				break
			}
		}
		return 200, map[string]any{"message": "Binding detached from the project."}, nil
	})
}

func bindingNotFound() *apiErr {
	return &apiErr{status: 404, message: "No query results for model [App\\Models\\Binding]."}
}

// needBindingsManage is the controller's Gate::authorize('bindings.manage') of attachment changes.
func (s *Server) needBindingsManage() *apiErr {
	if s.holds("bindings.manage") {
		return nil
	}
	return &apiErr{status: 403, message: "You do not have permission to do this (requires bindings.manage).", permissions: []string{"bindings.manage"}}
}

func (s *Server) bindingAbilities() map[string]bool {
	return map[string]bool{"manage": s.holds("bindings.manage"), "view_credentials": s.holds("bindings.view_credentials")}
}

func (s *Server) sortedBindings() []*fakeBinding {
	list := append([]*fakeBinding(nil), s.bindSt().bindings...)
	sort.SliceStable(list, func(i, j int) bool { return list[i].data.Name < list[j].data.Name })
	return list
}

func (s *Server) bindingByID(id string) *fakeBinding {
	for _, b := range s.bindSt().bindings {
		if b.data.ID == id {
			return b
		}
	}
	return nil
}

func (s *Server) bindingAttachmentByID(p *project, id string) *fakeAttachment {
	for _, a := range s.bindSt().attachments {
		if a.id == id && a.projectID == p.data.ID {
			return a
		}
	}
	return nil
}

// advanceBinding moves a provisioning or rotating binding on, one read at a time.
func (s *Server) advanceBinding(st *bindingState, b *fakeBinding) {
	if b.data.Status != runr.BindingProvisioning && b.data.Status != runr.BindingRotating {
		return
	}
	if b.pollsLeft > 0 {
		b.pollsLeft--
		return
	}
	if b.fails {
		b.data.Status = runr.BindingError
		b.data.StatusMessage = b.failMsg
		return
	}
	if b.data.Status == runr.BindingRotating {
		b.data.RotatedAt = nowRFC3339()
		b.credentials = bindingCredentials(s, b, true)
	}
	b.data.Status = runr.BindingReady
	b.data.StatusMessage = ""
}

// bindingInput is a validated binding of POST /bindings or of a create + attach.
type bindingInput struct {
	name, typ, plan, regionID string
	settings                  map[string]any
	values                    map[string]string
}

// validateBinding is BindingController::validateBinding; field names carry prefix ("binding.").
func (s *Server) validateBinding(body map[string]any, prefix, forcedRegion string) (*bindingInput, *apiErr) {
	in := &bindingInput{}
	in.name, _ = body["name"].(string)
	in.typ, _ = body["type"].(string)
	in.plan, _ = body["plan"].(string)
	in.regionID, _ = body["knative_region_id"].(string)
	switch {
	case in.name == "":
		return nil, errValidation(prefix+"name", "The name field is required.")
	case len(in.name) > 30:
		return nil, errValidation(prefix+"name", "The name field must not be greater than 30 characters.")
	case !bindingNamePattern.MatchString(in.name):
		return nil, errValidation(prefix+"name", "The name field format is invalid.")
	}
	for _, b := range s.bindSt().bindings {
		if b.data.Name == in.name {
			return nil, errValidation(prefix+"name", "The name has already been taken.")
		}
	}
	valid := false
	for _, t := range runr.BindingTypes {
		valid = valid || t == in.typ
	}
	if in.typ == "" {
		return nil, errValidation(prefix+"type", "The type field is required.")
	}
	if !valid {
		return nil, errValidation(prefix+"type", "The selected type is invalid.")
	}
	if in.plan != "" && in.plan != runr.BindingPlanShared && in.plan != runr.BindingPlanDedicated {
		return nil, errValidation(prefix+"plan", "The selected plan is invalid.")
	}
	if forcedRegion != "" && in.typ != runr.BindingExternal {
		in.regionID = forcedRegion
	}
	if in.typ != runr.BindingExternal && in.regionID == "" {
		return nil, errValidation(prefix+"knative_region_id", "The knative region id field is required unless type is in external.")
	}
	if settings, ok := body["settings"].(map[string]any); ok {
		if err := validateBindingSettings(settings, prefix+"settings."); err != nil {
			return nil, err
		}
		in.settings = settings
	}
	rawValues, hasValues := body["values"].(map[string]any)
	if in.typ == runr.BindingExternal {
		if !hasValues || len(rawValues) == 0 {
			return nil, errValidation(prefix+"values", "The values field is required when type is external.")
		}
		vals, err := bindingValues(rawValues, prefix+"values")
		if err != nil {
			return nil, err
		}
		in.values = vals
		in.regionID = ""
	}
	if in.plan == runr.BindingPlanDedicated && in.typ != runr.BindingPostgres {
		return nil, errValidation(prefix+"plan", "Only Postgres bindings have a dedicated plan.")
	}
	return in, nil
}

func validateBindingSettings(settings map[string]any, prefix string) *apiErr {
	ints := map[string][2]int{"memory_mb": {8, 1024}, "quota_gb": {1, 100}, "storage_gb": {1, 50},
		"connection_limit": {1, 100}, "instances": {1, 3}}
	for k, v := range settings {
		if r, ok := ints[k]; ok {
			n, isNum := v.(float64)
			if !isNum || n != float64(int(n)) {
				return errValidation(prefix+k, fmt.Sprintf("The %s field must be an integer.", strings.ReplaceAll(prefix+k, "_", " ")))
			}
			if int(n) < r[0] || int(n) > r[1] {
				return errValidation(prefix+k, fmt.Sprintf("The %s field must be between %d and %d.", strings.ReplaceAll(prefix+k, "_", " "), r[0], r[1]))
			}
		}
		if k == "backups" {
			if _, ok := v.(bool); !ok {
				return errValidation(prefix+k, "The "+prefix+k+" field must be true or false.")
			}
		}
		if k == "statement_timeout" {
			if str, ok := v.(string); !ok || !bindingTimeoutRe.MatchString(str) {
				return errValidation(prefix+k, "The "+strings.ReplaceAll(prefix+k, "_", " ")+" field format is invalid.")
			}
		}
	}
	return nil
}

func bindingValues(raw map[string]any, field string) (map[string]string, *apiErr) {
	out := map[string]string{}
	for k, v := range raw {
		if !bindingEnvPattern.MatchString(k) || len(k) > 64 {
			return nil, errValidation(field, k+" is not a valid environment variable name (A-Z, 0-9, _; starts with a letter).")
		}
		out[k] = fmt.Sprint(v)
	}
	return out, nil
}

// normalizeBindingSettings is BindingManager::normalizeSettings.
func normalizeBindingSettings(typ, plan string, in map[string]any) runr.BindingValues {
	get := func(k string, def any) any {
		if v, ok := in[k]; ok && v != nil {
			return v
		}
		return def
	}
	switch typ {
	case runr.BindingKV:
		return runr.BindingValues{"memory_mb": get("memory_mb", float64(64)), "over_quota": get("over_quota", false)}
	case runr.BindingObject:
		return runr.BindingValues{"quota_gb": get("quota_gb", float64(5))}
	case runr.BindingPostgres:
		storage := float64(1)
		if plan == runr.BindingPlanDedicated {
			storage = 5
		}
		out := runr.BindingValues{"storage_gb": get("storage_gb", storage), "connection_limit": get("connection_limit", float64(20)),
			"statement_timeout": get("statement_timeout", "30s"), "over_quota": get("over_quota", false)}
		if plan == runr.BindingPlanDedicated {
			out["instances"] = get("instances", float64(1))
			out["backups"] = get("backups", false)
		}
		return out
	}
	return runr.BindingValues{}
}

func bindSettingInt(m map[string]any, k string) int {
	switch v := m[k].(type) {
	case float64:
		return int(v)
	case int:
		return v
	}
	return 0
}

// bindingResidency answers 422 region_not_allowed for a region the policy refuses.
func (s *Server) bindingResidency(regionID, field string) *apiErr {
	if regionID == "" {
		return nil
	}
	reg := s.regionByID(regionID)
	if reg == nil {
		return nil
	}
	for _, denied := range s.bindSt().cfg.DeniedRegions {
		if denied == reg.Name {
			msg := "Your organization's data residency does not allow region " + reg.Name + "."
			return &apiErr{status: 422, message: msg, errors: map[string][]string{field: {msg}}}
		}
	}
	return nil
}

func (s *Server) createBinding(in *bindingInput) (*fakeBinding, *apiErr) {
	if in.typ != runr.BindingExternal {
		reg := s.regionByID(in.regionID)
		if reg == nil || !reg.IsActive {
			return nil, errValidation("binding", "Region "+in.regionID+" has no usable cluster (kubeconfig) for bindings.")
		}
		if err := s.bindingResidency(in.regionID, "knative_region_id"); err != nil {
			return nil, err
		}
	}
	if len(s.bindSt().bindings) >= 20 {
		return nil, errValidation("binding", "Binding limit reached (max_bindings: 20 of 20 used, 1 requested).")
	}
	return s.newBinding(in.name, in.typ, in.plan, in.regionID, in.settings, in.values), nil
}

func (s *Server) newBinding(name, typ, plan, regionID string, settings map[string]any, values map[string]string) *fakeBinding {
	st := s.bindSt()
	switch {
	case typ == runr.BindingExternal:
		plan = "external"
	case typ != runr.BindingPostgres || plan == "":
		plan = runr.BindingPlanShared
	}
	if settings == nil {
		settings = map[string]any{}
	}
	normalized := map[string]any{}
	for k, v := range settings {
		switch n := v.(type) {
		case int:
			normalized[k] = float64(n)
		default:
			normalized[k] = v
		}
	}
	id := strings.ToLower(s.newID())
	now := nowRFC3339()
	b := &fakeBinding{
		data: runr.Binding{ID: id, Name: name, ResourceName: name + "-" + id[len(id)-6:], Type: typ, Plan: plan, RegionID: regionID,
			Status: runr.BindingProvisioning, Settings: normalizeBindingSettings(typ, plan, normalized),
			CreatedBy: s.User.ID, CreatedAt: now, UpdatedAt: now},
		values:    values,
		pollsLeft: st.cfg.ProvisionPolls,
		fails:     st.cfg.FailProvision,
		failMsg:   st.cfg.FailMessage,
	}
	b.credentials = bindingCredentials(s, b, false)
	st.bindings = append(st.bindings, b)
	return b
}

// bindingCredentials builds the variables of a binding (rotated: new secrets).
func bindingCredentials(s *Server, b *fakeBinding, rotated bool) map[string]string {
	secret := "pw-" + b.data.ID[len(b.data.ID)-8:]
	if rotated {
		secret = "rotated-" + b.data.ID[len(b.data.ID)-8:]
	}
	host := b.data.Name + ".runr-data.svc"
	switch b.data.Type {
	case runr.BindingKV:
		return map[string]string{"REDIS_URL": "redis://" + b.data.Name + ":" + secret + "@runr-kv.runr-data.svc:6379/0",
			"KV_PREFIX": b.data.Name + ":"}
	case runr.BindingObject:
		return map[string]string{"S3_ENDPOINT": "http://runr-objects.runr-data.svc:3900", "S3_BUCKET": b.data.ResourceName,
			"S3_ACCESS_KEY_ID": "GK" + b.data.ID[len(b.data.ID)-8:], "S3_SECRET_ACCESS_KEY": secret, "S3_REGION": "garage",
			"S3_FORCE_PATH_STYLE": "true"}
	case runr.BindingPostgres:
		db := strings.ReplaceAll(b.data.Name, "-", "_")
		return map[string]string{"DATABASE_URL": "postgres://" + db + ":" + secret + "@" + host + ":5432/" + db + "?sslmode=require",
			"PGHOST": host, "PGPORT": "5432", "PGDATABASE": db, "PGUSER": db, "PGPASSWORD": secret, "PGSSLMODE": "require"}
	}
	out := map[string]string{}
	for k, v := range b.values {
		out[k] = v
	}
	return out
}

func bindingHealthDetails(b *fakeBinding) map[string]any {
	switch b.data.Type {
	case runr.BindingObject:
		return map[string]any{"objects": 0, "bytes": 0, "quota_bytes": bindSettingInt(b.data.Settings, "quota_gb") << 30}
	case runr.BindingPostgres:
		return map[string]any{"ready_instances": 1, "phase": "Cluster in healthy state"}
	case runr.BindingKV:
		return map[string]any{"user": "on"}
	}
	return map[string]any{}
}

func (s *Server) bindingEnvKeysOf(b *fakeBinding) []string {
	if b.data.Type == runr.BindingExternal {
		keys := make([]string, 0, len(b.values))
		for k := range b.values {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		return keys
	}
	return bindingEnvKeys[b.data.Type]
}

// bindingResource is BindingResource; withAttachments loads the attachments like index/show.
func (s *Server) bindingResource(b *fakeBinding, withAttachments bool) runr.Binding {
	out := b.data
	out.Settings = runr.BindingValues{}
	for k, v := range b.data.Settings {
		out.Settings[k] = v
	}
	out.EnvKeys = append([]string{}, s.bindingEnvKeysOf(b)...)
	if reg := s.regionByID(b.data.RegionID); reg != nil {
		out.Region = &runr.Ref{ID: reg.ID, Name: reg.Name}
	}
	switch b.data.Type {
	case runr.BindingKV:
		out.Details = runr.BindingValues{"mode": "acl", "prefix": b.data.Name + ":"}
	case runr.BindingObject:
		out.Details = runr.BindingValues{"bucket": b.data.ResourceName, "scoped_keys": true}
	case runr.BindingPostgres:
		out.Details = runr.BindingValues{"cluster": "runr-pg-shared", "database": strings.ReplaceAll(b.data.Name, "-", "_")}
	default:
		out.Details = runr.BindingValues{}
	}
	out.Attachments = nil
	if withAttachments {
		out.Attachments = []runr.BindingAttachment{}
		for _, a := range s.bindSt().attachments {
			if a.bindingID == b.data.ID {
				out.Attachments = append(out.Attachments, s.bindingAttachmentResource(a, false))
			}
		}
	}
	return out
}

// attachmentResource is BindingResource::attachment.
func (s *Server) bindingAttachmentResource(a *fakeAttachment, withBinding bool) runr.BindingAttachment {
	out := runr.BindingAttachment{ID: a.id, BindingID: a.bindingID, ProjectID: a.projectID, EnvPrefix: a.envPrefix, CreatedAt: a.createdAt,
		EnvNames: []string{}}
	if p := s.projectByID(a.projectID); p != nil {
		out.Project = &struct {
			ID   string `json:"id"`
			Name string `json:"name"`
			Slug string `json:"slug,omitempty"`
		}{ID: p.data.ID, Name: p.data.Name, Slug: p.data.Slug}
	}
	if b := s.bindingByID(a.bindingID); b != nil {
		for _, k := range s.bindingEnvKeysOf(b) {
			out.EnvNames = append(out.EnvNames, a.envPrefix+k)
		}
		if withBinding {
			r := s.bindingResource(b, false)
			out.Binding = &r
		}
	}
	return out
}

// bindingEnvConflict refuses two attachments of a project that produce the same variable.
func (s *Server) bindingEnvConflict(b *fakeBinding, p *project, prefix, exceptID string) *apiErr {
	mine := map[string]bool{}
	for _, k := range s.bindingEnvKeysOf(b) {
		mine[prefix+k] = true
	}
	var clash []string
	for _, a := range s.bindSt().attachments {
		if a.projectID != p.data.ID || a.id == exceptID {
			continue
		}
		other := s.bindingByID(a.bindingID)
		if other == nil {
			continue
		}
		for _, k := range s.bindingEnvKeysOf(other) {
			if mine[a.envPrefix+k] {
				clash = append(clash, a.envPrefix+k)
			}
		}
	}
	if len(clash) == 0 {
		return nil
	}
	sort.Strings(clash)
	return errValidation("binding", "These environment variables are already set by another binding of the project: "+
		strings.Join(clash, ", ")+". Use another prefix.")
}

func (s *Server) attachBinding(b *fakeBinding, p *project, prefix string) (*fakeAttachment, *apiErr) {
	if b.data.Deleted() {
		return nil, errValidation("binding", "Binding "+b.data.Name+" is being deleted.")
	}
	if b.data.Type != runr.BindingExternal && b.data.RegionID != p.data.RegionID {
		return nil, errValidation("binding", "Binding "+b.data.Name+" lives in another region than project "+p.data.Name+".")
	}
	st := s.bindSt()
	for _, a := range st.attachments {
		if a.bindingID == b.data.ID && a.projectID == p.data.ID {
			return nil, errValidation("binding", "Binding "+b.data.Name+" is already attached to this project.")
		}
	}
	if err := s.bindingResidency(b.data.RegionID, "binding_id"); err != nil {
		return nil, err
	}
	if err := s.bindingEnvConflict(b, p, prefix, ""); err != nil {
		return nil, err
	}
	a := &fakeAttachment{id: strings.ToLower(s.newID()), bindingID: b.data.ID, projectID: p.data.ID, envPrefix: prefix, createdAt: nowRFC3339()}
	st.attachments = append(st.attachments, a)
	return a, nil
}

func bindNilIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}
