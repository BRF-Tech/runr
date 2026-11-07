package runrtest

import (
	"fmt"
	"sort"
	"strings"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Runtime sources of the fake (RuntimeSourceController, RuntimeCatalogController,
// RuntimeSourceSync). What a source's URL holds is set with SetRemoteRuntimeSource: a read
// validates every runtime.yaml there, keeps the valid runtimes (registered like POST /runtimes,
// managed by the source) and lists the problems of the others; a runtime that left the source,
// or whose source was removed, stays orphaned while projects use it. RunR's catalog (the default
// source) gives the built-in runtimes and takes is_enabled and interval_minutes only.

// RemoteRuntimeSource is what the fake reads at a source's URL (an OCI catalog or a Git
// repository).
type RemoteRuntimeSource struct {
	// Fail makes every read fail with this status (unreachable, not_found, auth_failed,
	// invalid_signature, invalid_catalog) and Error.
	Fail  string
	Error string
	// Revision of the catalog / commit ("" derives one).
	Revision string
	// Runtimes: directory → runtime.yaml (YAML or JSON).
	Runtimes map[string]string
	// Private: a read needs credentials — a Git token or deploy key (git), registry credentials
	// (oci-catalog).
	Private bool
	// Signed: the catalog's signature verifies (oci-catalog with a public key).
	Signed bool
}

type sourceState struct {
	data runr.RuntimeSource
	// creds: API field (git_token, …) → value
	creds map[string]string
}

// runtimeOrigin is where a runtime comes from (Runtime source_id, source_path, orphaned_at).
type runtimeOrigin struct {
	sourceID   string
	sourcePath string
	orphanedAt string
}

type runtimeSourcesState struct {
	sources []*sourceState
	remote  map[string]RemoteRuntimeSource
	busy    map[string]bool
	origins map[string]*runtimeOrigin
	// offer is the Built-in runtimes setting (nil: every runtime of the catalog)
	offer      []string
	offerSaved bool
	revisions  int
}

// DefaultRuntimeSourceID is the id of RunR's catalog in the fake.
const DefaultRuntimeSourceID = "01JRTSRCDEFA00000000000000"

// orphanGraceDays is runtime_sources.orphan_grace_days.
const orphanGraceDays = 90

func (s *Server) rtSources() *runtimeSourcesState {
	return featureState(s, "runtime-sources", func() *runtimeSourcesState {
		st := &runtimeSourcesState{remote: map[string]RemoteRuntimeSource{}, busy: map[string]bool{}, origins: map[string]*runtimeOrigin{}}
		now := nowRFC3339()
		st.sources = append(st.sources, &sourceState{creds: map[string]string{}, data: runr.RuntimeSource{
			ID: DefaultRuntimeSourceID, Name: "RunR runtimes", Kind: runr.RuntimeSourceOCI, Scope: "platform", IsDefault: true,
			URL: "ghcr.io/brf-tech/runr/runtimes/catalog:1", PublicKey: "-----BEGIN PUBLIC KEY-----\nMFkw\n-----END PUBLIC KEY-----",
			IsEnabled: true, IntervalMinutes: 15, Status: runr.RuntimeSourceOK, Problems: []runr.RuntimeSourceProblem{},
			Revision: "sha256:0c0ffee", SignatureVerified: true, CheckedAt: now, SyncedAt: now, CreatedAt: now, UpdatedAt: now,
		}})
		return st
	})
}

// runtimeSourcesOn reports whether the fake has runtime sources (Without takes them away).
func (s *Server) runtimeSourcesOn() bool { return !s.withoutState()["GET /runtime-sources"] }

// SetRemoteRuntimeSource sets what a read of the source at url finds.
func (s *Server) SetRemoteRuntimeSource(url string, r RemoteRuntimeSource) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.rtSources().remote[url] = r
}

// SetRuntimeSourceBusy makes a sync of the source (name) answer 409, as while another read runs.
func (s *Server) SetRuntimeSourceBusy(name string, busy bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	src := s.sourceByName(name)
	if src == nil {
		s.t.Fatalf("runrtest: no runtime source %q", name)
	}
	s.rtSources().busy[src.data.ID] = busy
}

// RuntimeSourceCredentials returns the credentials the fake stores for a source (API field →
// value), nil when there is no such source.
func (s *Server) RuntimeSourceCredentials(name string) map[string]string {
	s.mu.Lock()
	defer s.mu.Unlock()
	src := s.sourceByName(name)
	if src == nil {
		return nil
	}
	out := map[string]string{}
	for k, v := range src.creds {
		out[k] = v
	}
	return out
}

// RuntimeSource returns a source by name as the API shows it.
func (s *Server) RuntimeSource(name string) (runr.RuntimeSource, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	src := s.sourceByName(name)
	if src == nil {
		return runr.RuntimeSource{}, false
	}
	return s.sourceAPI(src), true
}

// BuiltinRuntimesSetting returns the Built-in runtimes setting (nil: every runtime) and whether it
// was saved.
func (s *Server) BuiltinRuntimesSetting() ([]string, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st := s.rtSources()
	return append([]string(nil), st.offer...), st.offerSaved
}

func (s *Server) sourceByName(name string) *sourceState {
	for _, src := range s.rtSources().sources {
		if src.data.Name == name {
			return src
		}
	}
	return nil
}

func (s *Server) sourceByID(id string) *sourceState {
	for _, src := range s.rtSources().sources {
		if src.data.ID == id {
			return src
		}
	}
	return nil
}

func (s *Server) defaultSource() *sourceState { return s.rtSources().sources[0] }

// sourceAPI is RuntimeSource::toApi(): which credentials are set, never their value.
func (s *Server) sourceAPI(src *sourceState) runr.RuntimeSource {
	d := src.data
	c := src.creds
	d.Credentials = runr.RuntimeSourceCredentials{
		GitUsername: c["git_username"], SSHKeySet: c["ssh_private_key"] != "", KnownHostsSet: c["ssh_known_hosts"] != "",
		RegistryUsername: c["registry_username"], RegistryPasswordSet: c["registry_password"] != "",
	}
	switch {
	case c["git_token"] != "":
		d.Credentials.Git = "token"
		d.Credentials.GitTokenHint = "••••••"
		if t := c["git_token"]; len(t) > 8 {
			d.Credentials.GitTokenHint += t[len(t)-4:]
		}
	case c["ssh_private_key"] != "":
		d.Credentials.Git = "ssh"
	}
	if d.Problems == nil {
		d.Problems = []runr.RuntimeSourceProblem{}
	}
	if d.IsDefault {
		d.RuntimesCount = len(s.builtinIDs())
	} else {
		n := 0
		for _, o := range s.rtSources().origins {
			if o.sourceID == d.ID {
				n++
			}
		}
		if d.Status != runr.RuntimeSourceNever && !runr.RuntimeSourceFailed(d.Status) {
			d.RuntimesCount = n
		}
	}
	if d.IsEnabled {
		if t, err := time.Parse(time.RFC3339, d.CheckedAt); err == nil {
			d.NextCheckAt = t.Add(time.Duration(d.IntervalMinutes) * time.Minute).UTC().Format(time.RFC3339)
		}
	}
	return d
}

// builtinIDs are the built-in runtimes of the fake's catalog (the executor images that are no
// plugin and no registered runtime).
func (s *Server) builtinIDs() []string {
	seen := map[string]bool{}
	var out []string
	for _, img := range s.images {
		if _, plugin := pluginRuntimes[img.Runtime]; plugin || seen[img.Runtime] {
			continue
		}
		if _, reg := s.runtimes().registered[img.Runtime]; reg {
			continue
		}
		seen[img.Runtime] = true
		out = append(out, img.Runtime)
	}
	sort.Strings(out)
	return out
}

// sourceScope is the scope the caller connects sources in (RuntimeSources::scopeForUser).
func (s *Server) sourceScope() string {
	if s.holds("runtimes.manage") {
		return "platform"
	}
	return "organization"
}

// canSeeSource is RuntimeSources::canView.
func (s *Server) canSeeSource(src *sourceState) bool {
	return s.holds("runtimes.manage") || (src.data.Scope == "organization" && src.data.TenantID == s.rbac().tenantID)
}

func (s *Server) sourceOf(q *featureReq) (*sourceState, *apiErr) {
	src := s.sourceByID(q.Segs[1])
	if src == nil || !s.canSeeSource(src) {
		return nil, errNotFound()
	}
	if src.data.Scope == "platform" && !s.holds("runtimes.manage") {
		return nil, &apiErr{status: 403, message: "Runtime sources need runtimes.manage or runtimes.use_custom."}
	}
	return src, nil
}

var sourceFields = []string{"name", "kind", "url", "ref", "path", "public_key", "patch_feed", "registry_host", "interval_minutes", "is_enabled"}

var credentialFields = []string{"git_token", "git_username", "ssh_private_key", "ssh_known_hosts", "registry_username", "registry_password"}

// fillSource applies a request's fields to a source like RuntimeSources::fill and ::check.
func (s *Server) fillSource(src *sourceState, body map[string]any, creating bool) *apiErr {
	d := &src.data
	if d.IsDefault {
		for k := range body {
			if k != "is_enabled" && k != "interval_minutes" && k != "source" {
				return errValidation(k, "RunR's catalog takes is_enabled and interval_minutes only.")
			}
		}
	}
	str := func(k string) (string, bool) {
		v, ok := body[k]
		if !ok {
			return "", false
		}
		x, _ := v.(string)
		return strings.TrimSpace(strings.ReplaceAll(x, "\r\n", "\n")), true
	}
	for _, k := range sourceFields {
		switch k {
		case "interval_minutes":
			if v, ok := body[k].(float64); ok {
				if v < 5 || v > 10080 {
					return errValidation(k, "The interval minutes field must be between 5 and 10080.")
				}
				d.IntervalMinutes = int(v)
			}
		case "is_enabled":
			if v, ok := body[k].(bool); ok {
				d.IsEnabled = v
			}
		default:
			v, ok := str(k)
			if !ok {
				continue
			}
			switch k {
			case "name":
				d.Name = v
			case "kind":
				d.Kind = v
			case "url":
				d.URL = v
			case "ref":
				d.Ref = v
			case "path":
				d.Path = v
			case "public_key":
				d.PublicKey = v
			case "patch_feed":
				d.PatchFeed = v
			case "registry_host":
				d.RegistryHost = v
			}
		}
	}
	if d.IsDefault {
		return nil
	}
	// SourceCredentials::merge: omitted keeps, null / "" removes; a token replaces a deploy key
	for _, k := range credentialFields {
		if v, ok := str(k); ok {
			if v == "" {
				delete(src.creds, k)
			} else {
				src.creds[k] = v
			}
		}
	}
	if v, _ := str("git_token"); v != "" {
		delete(src.creds, "ssh_private_key")
		delete(src.creds, "ssh_known_hosts")
	}
	if v, _ := str("ssh_private_key"); v != "" {
		delete(src.creds, "git_token")
		delete(src.creds, "git_username")
	}

	switch {
	case creating && d.Name == "":
		return errValidation("name", "The name field is required.")
	case d.Kind != runr.RuntimeSourceOCI && d.Kind != runr.RuntimeSourceGit:
		return errValidation("kind", "must be oci-catalog or git")
	case d.URL == "":
		return errValidation("url", "The url field is required.")
	}
	ssh := strings.HasPrefix(d.URL, "git@") || strings.HasPrefix(d.URL, "ssh://")
	if d.Kind == runr.RuntimeSourceGit {
		if (ssh && src.creds["git_token"] != "") || (!ssh && src.creds["ssh_private_key"] != "") {
			return errValidation("credentials", "An SSH URL takes a deploy key, an HTTPS URL a token.")
		}
	} else {
		d.Ref, d.Path = "", ""
		if src.creds["git_token"] != "" || src.creds["ssh_private_key"] != "" {
			return errValidation("credentials", "Git credentials only apply to a git source")
		}
	}
	if d.PatchFeed != "" && d.PublicKey == "" {
		return errValidation("patch_feed", "needs the public key it is signed with")
	}
	if (src.creds["registry_username"] == "") != (src.creds["registry_password"] == "") {
		field := "registry_password"
		if src.creds["registry_username"] == "" {
			field = "registry_username"
		}
		return errValidation(field, "is required with the other registry credential")
	}
	return nil
}

// nameTaken is RuntimeSources::checkName: names are unique per scope (and organization).
func (s *Server) nameTaken(src *sourceState) *apiErr {
	d := src.data
	for _, o := range s.rtSources().sources {
		if o.data.ID != d.ID && o.data.Name == d.Name && o.data.Scope == d.Scope && o.data.TenantID == d.TenantID {
			return errValidation("name", "A runtime source named "+d.Name+" exists already.")
		}
	}
	return nil
}

// sourceRead is what a read of a source finds (RuntimeSourceSync::read and validateEntry).
type sourceRead struct {
	status, error, revision string
	signed                  bool
	valid                   map[string]map[string]any // directory → normalized manifest
	texts                   map[string]string
	entries                 []runr.RuntimeSourceEntry
	problems                []runr.RuntimeSourceProblem
}

func (s *Server) readRemote(src *sourceState) sourceRead {
	st := s.rtSources()
	d := src.data
	remote, ok := st.remote[d.URL]
	switch {
	case !ok:
		return sourceRead{status: runr.RuntimeSourceUnreachable, error: "could not reach " + d.URL}
	case remote.Fail != "":
		msg := remote.Error
		if msg == "" {
			msg = remote.Fail
		}
		return sourceRead{status: remote.Fail, error: msg}
	case remote.Private && src.creds["git_token"] == "" && src.creds["ssh_private_key"] == "" && src.creds["registry_password"] == "":
		// as the API: a private source read without credentials looks missing (not_found)
		return sourceRead{status: runr.RuntimeSourceNotFound, error: d.URL + " does not exist or is not public: it needs credentials"}
	case d.Kind == runr.RuntimeSourceOCI && d.PublicKey != "" && !remote.Signed:
		return sourceRead{status: runr.RuntimeSourceInvalidSignature, error: "the catalog's signature does not verify with the public key"}
	}
	r := sourceRead{revision: remote.Revision, signed: remote.Signed && d.PublicKey != "", valid: map[string]map[string]any{}, texts: map[string]string{}}
	if r.revision == "" {
		st.revisions++
		r.revision = fmt.Sprintf("%040x", st.revisions)
	}
	dirs := make([]string, 0, len(remote.Runtimes))
	for dir := range remote.Runtimes {
		dirs = append(dirs, dir)
	}
	sort.Strings(dirs)
	seen := map[string]string{}
	for _, dir := range dirs {
		file := dir
		if d.Kind == runr.RuntimeSourceGit {
			root := strings.Trim(d.Path, "/")
			if root == "" {
				root = "runtimes" // SourceLocation::DEFAULT_PATH
			}
			file = root + "/" + dir + "/runtime.yaml"
		}
		text := remote.Runtimes[dir]
		m, err := parseManifest(text, nil)
		var probs []runr.RuntimeSourceProblem
		id := dir
		if err != nil {
			for _, msg := range err.errors["manifest"] {
				p, m, _ := strings.Cut(msg, ": ")
				probs = append(probs, runr.RuntimeSourceProblem{File: file, Runtime: dir, Path: p, Message: m})
			}
		} else {
			id = fmt.Sprint(m["id"])
			if other, dup := seen[id]; dup {
				probs = append(probs, runr.RuntimeSourceProblem{File: file, Runtime: id, Path: "/id", Message: id + " is defined in " + other + " too"})
			} else if clash := s.runtimeClaimedElsewhere(src, id); clash != "" {
				probs = append(probs, runr.RuntimeSourceProblem{File: file, Runtime: id, Path: "/id", Message: clash})
			}
			seen[id] = dir
		}
		r.entries = append(r.entries, runr.RuntimeSourceEntry{ID: id, Path: dir, Valid: len(probs) == 0})
		if len(probs) == 0 {
			r.valid[dir], r.texts[dir] = m, text
		}
		r.problems = append(r.problems, probs...)
	}
	r.status = runr.RuntimeSourceOK
	if len(r.problems) > 0 {
		r.status = runr.RuntimeSourcePartial
	}
	return r
}

// runtimeClaimedElsewhere says why a source cannot have the runtime id ("" when it can).
func (s *Server) runtimeClaimedElsewhere(src *sourceState, id string) string {
	o := s.rtSources().origins[id]
	switch {
	case o != nil && o.sourceID == src.data.ID:
		return ""
	case o != nil && o.sourceID != "":
		return "the runtime " + id + " comes from another source"
	case o != nil:
		return "" // orphaned: a source may take it back
	case s.runtimeExists(id):
		return "a runtime with the id " + id + " exists already"
	}
	return ""
}

// syncSource reads a source and applies the read (RuntimeSourceSync::sync): a failed read
// leaves the runtimes as they were.
func (s *Server) syncSource(src *sourceState) {
	now := nowRFC3339()
	d := &src.data
	d.CheckedAt, d.UpdatedAt = now, now
	if d.IsDefault {
		d.Status, d.Error, d.SyncedAt = runr.RuntimeSourceOK, "", now
		return
	}
	r := s.readRemote(src)
	d.Status, d.Error = r.status, r.error
	if runr.RuntimeSourceFailed(r.status) {
		return
	}
	d.Revision, d.SignatureVerified, d.Problems, d.SyncedAt = r.revision, r.signed, r.problems, now
	st := s.rtSources()
	source := "platform"
	if d.Scope == "organization" {
		source = "custom"
	}
	present := map[string]bool{}
	for dir, m := range r.valid {
		id := fmt.Sprint(m["id"])
		present[id] = true
		if rt, ok := s.runtimes().registered[id]; ok {
			rt.text, rt.manifest, rt.updated = r.texts[dir], m, now
			rt.source = source
			s.syncRuntimeImages(rt)
		} else if rt, err := s.registerRuntime(source, r.texts[dir], nil, nil); err == nil && d.Scope == "organization" {
			rt.tenant = d.TenantID
		}
		st.origins[id] = &runtimeOrigin{sourceID: d.ID, sourcePath: dir}
	}
	// Runtimes that left the source: orphaned while projects use them, else removed
	for id, o := range st.origins {
		if o.sourceID != d.ID || present[id] {
			continue
		}
		// an invalid manifest of a runtime still in the source does not orphan it
		stillThere := false
		for _, e := range r.entries {
			stillThere = stillThere || e.ID == id
		}
		if !stillThere {
			s.orphanRuntime(id)
		}
	}
}

// orphanRuntime orphans a runtime that left its source (projects use it) or removes it.
func (s *Server) orphanRuntime(id string) {
	st := s.rtSources()
	if s.projectsOn(id) > 0 {
		o := st.origins[id]
		o.sourceID, o.orphanedAt = "", time.Now().UTC().Format(time.RFC3339)
		return
	}
	delete(st.origins, id)
	s.removeRuntime(id)
}

// decorateRuntime adds where a catalog runtime comes from and its lifecycle (RuntimeResource on
// APIs with runtime sources).
func (s *Server) decorateRuntime(rt *runr.CatalogRuntime) {
	if !s.runtimeSourcesOn() {
		return
	}
	st := s.rtSources()
	var manifest map[string]any
	if reg, ok := s.runtimes().registered[rt.ID]; ok {
		manifest = reg.manifest
	}
	o := st.origins[rt.ID]
	switch {
	case o != nil && o.sourceID != "":
		rt.SourceID, rt.SourcePath, rt.Managed = o.sourceID, o.sourcePath, true
		if src := s.sourceByID(o.sourceID); src != nil {
			rt.SourceName = src.data.Name
		}
	case rt.Source == "builtin":
		def := s.defaultSource()
		rt.SourceID, rt.SourceName, rt.SourcePath, rt.Managed = def.data.ID, def.data.Name, rt.ID, true
	}
	orphaned := ""
	if o != nil {
		orphaned = o.orphanedAt
	}
	rt.Lifecycle = runtimeLifecycle(manifest, orphaned)
	for i := range rt.Versions {
		versionLifecycle(&rt.Versions[i], manifest, rt.Lifecycle)
	}
}

// runtimeLifecycle is RuntimeLifecycle::runtime.
func runtimeLifecycle(m map[string]any, orphanedAt string) *runr.RuntimeLifecycle {
	today := time.Now().UTC().Format("2006-01-02")
	lc := &runr.RuntimeLifecycle{State: runr.LifecycleActive}
	sunset, _ := m["sunset"].(string)
	if orphanedAt != "" {
		t, _ := time.Parse(time.RFC3339, orphanedAt)
		grace := t.AddDate(0, 0, orphanGraceDays).Format("2006-01-02")
		if sunset == "" || grace < sunset {
			sunset = grace
		}
		lc.OrphanedAt = orphanedAt
	}
	deprecated := ""
	switch v := m["deprecated"].(type) {
	case bool:
		if v {
			deprecated = today
		}
	case string:
		deprecated = v
	}
	if orphanedAt != "" && (deprecated == "" || orphanedAt[:10] < deprecated) {
		deprecated = orphanedAt[:10]
	}
	lc.DeprecatedAt, lc.SunsetAt = deprecated, sunset
	lc.Replacement, _ = m["replacement"].(string)
	lc.Notice, _ = m["notice"].(string)
	switch {
	case orphanedAt != "":
		lc.State = runr.LifecycleOrphaned
	case sunset != "" && sunset <= today:
		lc.State = runr.LifecycleSunset
	case deprecated != "" && deprecated <= today:
		lc.State = runr.LifecycleDeprecated
	}
	return lc
}

// versionLifecycle is RuntimeLifecycle::version over the dates RuntimeLifecycle::apply writes.
func versionLifecycle(v *runr.RuntimeVersion, m map[string]any, rt *runr.RuntimeLifecycle) {
	today := time.Now().UTC().Format("2006-01-02")
	var mv map[string]any
	for _, x := range manifestVersions(m) {
		if fmt.Sprint(x["version"]) == v.Version {
			mv = x
		}
	}
	earliest := func(dates ...string) string {
		out := ""
		for _, d := range dates {
			if d != "" && (out == "" || d < out) {
				out = d
			}
		}
		return out
	}
	deprecated := ""
	switch d := mv["deprecated"].(type) {
	case bool:
		if d {
			deprecated = today
		}
	case string:
		deprecated = d
	}
	sunset, _ := mv["sunset"].(string)
	v.DeprecatedAt = earliest(deprecated, rt.DeprecatedAt)
	v.SunsetAt = earliest(sunset, rt.SunsetAt)
	v.EndsAt = earliest(v.EOLAt, v.SunsetAt)
	v.Lifecycle = runr.LifecycleActive
	switch {
	case v.EndsAt != "" && v.EndsAt <= today && v.EndsAt == v.SunsetAt && v.SunsetAt != v.EOLAt:
		v.Lifecycle = runr.LifecycleSunset
	case v.EndsAt != "" && v.EndsAt <= today:
		v.Lifecycle = runr.LifecycleEOL
	case v.DeprecatedAt != "" && v.DeprecatedAt <= today:
		v.Lifecycle = runr.LifecycleDeprecated
	}
}

// manifestDate turns a YAML date (decoded as a time) into the manifest's YYYY-MM-DD.
func manifestDate(v any) any {
	if t, ok := v.(time.Time); ok {
		return t.Format("2006-01-02")
	}
	return v
}

func (s *Server) visibleSources() []runr.RuntimeSource {
	out := []runr.RuntimeSource{}
	for _, src := range s.rtSources().sources {
		if s.canSeeSource(src) {
			out = append(out, s.sourceAPI(src))
		}
	}
	return out
}

func (s *Server) builtinCatalogJSON() map[string]any {
	st := s.rtSources()
	ids := s.builtinIDs()
	runtimes := []map[string]any{}
	for _, id := range ids {
		offered := st.offer == nil
		for _, x := range st.offer {
			offered = offered || x == id
		}
		runtimes = append(runtimes, map[string]any{"id": id, "name": id, "offered": offered, "in_bundle": true})
	}
	var offer any
	if st.offer != nil {
		offer = st.offer
	}
	return map[string]any{"mode": "source", "source": s.sourceAPI(s.defaultSource()), "bundle": ids,
		"builtin_runtimes": offer, "saved": st.offerSaved, "runtimes": runtimes}
}

func init() {
	handle("GET /runtime-sources", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, map[string]any{"data": s.visibleSources(),
			"catalog":        map[string]any{"mode": "source", "runtimes": len(s.builtinIDs())},
			"abilities":      map[string]bool{"manage": s.holds("runtimes.manage"), "organization": s.holds("runtimes.use_custom")},
			"custom_enabled": true}, nil
	})
	handle("POST /runtime-sources", func(s *Server, q *featureReq) (int, any, *apiErr) {
		now := nowRFC3339()
		src := &sourceState{creds: map[string]string{}, data: runr.RuntimeSource{
			ID: s.newID(), Scope: s.sourceScope(), IsEnabled: true, IntervalMinutes: 60, Status: runr.RuntimeSourceNever,
			CreatedBy: s.User.ID, CreatedAt: now, UpdatedAt: now}}
		if src.data.Scope == "organization" {
			src.data.TenantID = s.rbac().tenantID
		}
		if err := s.fillSource(src, q.Body, true); err != nil {
			return 0, nil, err
		}
		if err := s.nameTaken(src); err != nil {
			return 0, nil, err
		}
		st := s.rtSources()
		st.sources = append(st.sources, src)
		s.syncSource(src)
		return 201, map[string]any{"data": s.sourceAPI(src)}, nil
	})
	handle("POST /runtime-sources/test", func(s *Server, q *featureReq) (int, any, *apiErr) {
		draft := &sourceState{creds: map[string]string{}, data: runr.RuntimeSource{Scope: s.sourceScope(), IsEnabled: true, IntervalMinutes: 60, Name: "test"}}
		if id := q.str("source"); id != "" {
			base := s.sourceByID(id)
			if base == nil || !s.canSeeSource(base) {
				return 0, nil, errNotFound()
			}
			draft.data = base.data
			for k, v := range base.creds {
				draft.creds[k] = v
			}
		} else if q.str("kind") == "" || q.str("url") == "" {
			return 0, nil, errValidation("url", "The kind and url fields are required.")
		}
		body := map[string]any{}
		for k, v := range q.Body {
			if k != "source" {
				body[k] = v
			}
		}
		if err := s.fillSource(draft, body, false); err != nil {
			return 0, nil, err
		}
		if draft.data.IsDefault {
			return 200, map[string]any{"data": map[string]any{"ok": true, "status": runr.RuntimeSourceOK, "error": nil,
				"revision": draft.data.Revision, "signature_verified": true, "runtimes": []any{}, "problems": []any{}}}, nil
		}
		r := s.readRemote(draft)
		ok := !runr.RuntimeSourceFailed(r.status)
		entries, problems := r.entries, r.problems
		if entries == nil {
			entries = []runr.RuntimeSourceEntry{}
		}
		if problems == nil {
			problems = []runr.RuntimeSourceProblem{}
		}
		var errMsg, revision any
		if r.error != "" {
			errMsg = r.error
		}
		if r.revision != "" {
			revision = r.revision
		}
		return 200, map[string]any{"data": map[string]any{"ok": ok, "status": r.status, "error": errMsg, "revision": revision,
			"signature_verified": r.signed, "runtimes": entries, "problems": problems}}, nil
	})
	handle("GET /runtime-sources/{source}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		src, err := s.sourceOf(q)
		if err != nil {
			return 0, nil, err
		}
		runtimes := []runr.CatalogRuntime{}
		for _, rt := range s.catalog() {
			if rt.SourceID == src.data.ID {
				runtimes = append(runtimes, rt)
			}
		}
		patches := []runr.RuntimePatch{}
		var feed any
		if src.data.PatchFeed != "" {
			feed = map[string]any{"reference": src.data.PatchFeed, "enabled": true, "status": "ok", "error": nil,
				"signature_verified": true, "generated_at": src.data.SyncedAt, "checked_at": src.data.CheckedAt, "fetched_at": src.data.SyncedAt}
		}
		if src.data.IsDefault {
			for _, p := range s.patchSt().patches {
				if p.InFeed {
					patches = append(patches, p)
				}
			}
		}
		return 200, map[string]any{"data": map[string]any{"source": s.sourceAPI(src), "runtimes": runtimes, "patches": patches, "patch_feed": feed}}, nil
	})
	handle("PUT /runtime-sources/{source}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		src, err := s.sourceOf(q)
		if err != nil {
			return 0, nil, err
		}
		saved := *src
		saved.creds = map[string]string{}
		for k, v := range src.creds {
			saved.creds[k] = v
		}
		ferr := s.fillSource(src, q.Body, false)
		if ferr == nil {
			ferr = s.nameTaken(src)
		}
		if ferr != nil {
			*src = saved
			return 0, nil, ferr
		}
		s.syncSource(src)
		return 200, map[string]any{"data": s.sourceAPI(src)}, nil
	})
	handle("DELETE /runtime-sources/{source}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		src, err := s.sourceOf(q)
		if err != nil {
			return 0, nil, err
		}
		if src.data.IsDefault {
			return 0, nil, errValidation("source", "RunR's catalog cannot be removed: turn it off instead.")
		}
		st := s.rtSources()
		for id, o := range st.origins {
			if o.sourceID == src.data.ID {
				s.orphanRuntime(id)
			}
		}
		for i, x := range st.sources {
			if x == src {
				st.sources = append(st.sources[:i], st.sources[i+1:]...)
				break
			}
		}
		return 204, nil, nil
	})
	handle("POST /runtime-sources/{source}/sync", func(s *Server, q *featureReq) (int, any, *apiErr) {
		src, err := s.sourceOf(q)
		if err != nil {
			return 0, nil, err
		}
		if s.rtSources().busy[src.data.ID] {
			return 0, nil, &apiErr{status: 409, message: "A read of " + src.data.Name + " is running; try again in a moment."}
		}
		s.syncSource(src)
		status := 200
		if runr.RuntimeSourceFailed(src.data.Status) {
			status = 502
		}
		return status, map[string]any{"data": s.sourceAPI(src)}, nil
	})

	handle("GET /runtime-catalog", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, map[string]any{"data": s.builtinCatalogJSON()}, nil
	})
	handle("PUT /runtime-catalog", func(s *Server, q *featureReq) (int, any, *apiErr) {
		v, present := q.Body["builtin_runtimes"]
		if !present {
			return 0, nil, errValidation("builtin_runtimes", "The builtin runtimes field must be present.")
		}
		st := s.rtSources()
		if v == nil {
			st.offer = nil
		} else {
			list, ok := v.([]any)
			if !ok {
				return 0, nil, errValidation("builtin_runtimes", "The builtin runtimes field must be an array.")
			}
			known := map[string]bool{}
			for _, id := range s.builtinIDs() {
				known[id] = true
			}
			offer := []string{}
			for _, x := range list {
				id := fmt.Sprint(x)
				if !known[id] {
					return 0, nil, errValidation("builtin_runtimes", "There is no built-in runtime "+id+".")
				}
				offer = append(offer, id)
			}
			st.offer = offer
		}
		st.offerSaved = true
		return 200, map[string]any{"data": s.builtinCatalogJSON()}, nil
	})
}
