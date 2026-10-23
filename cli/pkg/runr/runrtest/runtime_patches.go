package runrtest

import (
	"sort"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Runtime patches (ProjectRuntimePatchController, RuntimePatchController). Patches are seeded
// with SeedRuntimePatch; a project runs the patch its last apply / pin moved it to
// (SeedRunningPatch sets it), else an unknown one deployed before the patches (every patch
// counts as newer). The state follows RuntimePatches::state(): overdue before pinned before
// patch_available before up_to_date.

type patchState struct {
	patches []runr.RuntimePatch
	// running, pins: project id → patch id
	running map[string]string
	pins    map[string]string
	// migrations: project id → newest first
	migrations map[string][]runr.RuntimePatchMigration
	// noAutoMigrate switches the platform's automatic migration at deadlines off
	noAutoMigrate bool
}

const patchKeep = 5

func (s *Server) patchSt() *patchState {
	return featureState(s, "runtime-patches", func() *patchState {
		return &patchState{running: map[string]string{}, pins: map[string]string{}, migrations: map[string][]runr.RuntimePatchMigration{}}
	})
}

// SeedRuntimePatch adds a patch to the feed (in_feed; id and image derived when empty).
func (s *Server) SeedRuntimePatch(p runr.RuntimePatch) runr.RuntimePatch {
	s.mu.Lock()
	defer s.mu.Unlock()
	if p.ID == "" {
		p.ID = p.Runtime + "-" + p.Version + "-" + p.Tag
	}
	if p.Image == "" {
		p.Image = "ghcr.io/brf-tech/runr/exec-" + p.Runtime
	}
	if p.Digest == "" {
		p.Digest = "sha256:" + p.ID
	}
	if p.PublishedAt == "" {
		p.PublishedAt = nowRFC3339()
	}
	if p.Reason == "" && p.Reasons != nil {
		p.Reason = p.Reasons["en"]
	}
	p.InFeed = true
	st := s.patchSt()
	st.patches = append(st.patches, p)
	return p
}

// SeedRunningPatch records that a project's live deployment runs a patch.
func (s *Server) SeedRunningPatch(projectName, patchID string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	s.patchSt().running[p.data.ID] = patchID
}

// PinnedPatch returns the patch id a project is pinned to ("" for none).
func (s *Server) PinnedPatch(projectName string) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		return ""
	}
	return s.patchSt().pins[p.data.ID]
}

// patchesOf returns the in-feed patches of a runtime version, newest first.
func (st *patchState) patchesOf(runtime, version string) []runr.RuntimePatch {
	var out []runr.RuntimePatch
	for _, p := range st.patches {
		if p.Runtime == runtime && p.Version == version && p.InFeed {
			out = append(out, p)
		}
	}
	sort.SliceStable(out, func(i, j int) bool { return out[i].PublishedAt > out[j].PublishedAt })
	return out
}

func patchRank(level string) int {
	for i, l := range []string{runr.PatchRoutine, runr.PatchSecurity, runr.PatchCritical, runr.PatchZeroDay} {
		if l == level {
			return i
		}
	}
	return 0
}

func patchRef(p runr.RuntimePatch) *runr.PatchRef {
	return &runr.PatchRef{ID: p.ID, Tag: p.Tag, Level: p.Level, Digest: p.Digest, PublishedAt: p.PublishedAt}
}

// patchState is ProjectResource.runtime_patch of a project (nil: no patch concerns it).
func (s *Server) patchState(p *project) *runr.RuntimePatchState {
	if p.data.ServiceType == runr.TypeCaaS || p.data.Runtime == "" {
		return nil
	}
	st := s.patchSt()
	patches := st.patchesOf(p.data.Runtime, p.data.RuntimeVersion)
	pinID := st.pins[p.data.ID]
	if len(patches) == 0 && pinID == "" {
		return nil
	}
	deployed := false
	for _, d := range p.deployments {
		deployed = deployed || d.data.Status == runr.DeploymentActive
	}

	out := &runr.RuntimePatchState{Deployed: deployed, State: runr.PatchUpToDate}
	var current *runr.RuntimePatch
	for i := range patches {
		if patches[i].ID == st.running[p.data.ID] {
			current = &patches[i]
			out.Current = patchRef(patches[i])
		}
	}
	if len(patches) > 0 {
		out.Latest = patchRef(patches[0])
	}
	overdue := false
	now := time.Now().UTC()
	for _, x := range patches {
		if !deployed || (current != nil && (x.ID == current.ID || x.PublishedAt <= current.PublishedAt)) {
			continue
		}
		out.Pending = append(out.Pending, x.ID)
		if out.Level == "" || patchRank(x.Level) > patchRank(out.Level) {
			out.Level = x.Level
		}
		if x.DeadlineAt != "" {
			if out.DeadlineAt == "" || x.DeadlineAt < out.DeadlineAt {
				out.DeadlineAt = x.DeadlineAt
			}
			if t, err := time.Parse(time.RFC3339, x.DeadlineAt); err == nil && t.Before(now) {
				overdue = true
			}
		}
	}
	if pinID != "" {
		out.Pin = &runr.PatchPin{ID: pinID, By: string(s.User.ID)}
		for i, x := range patches {
			if x.ID == pinID && i < patchKeep {
				out.Pin.Tag, out.Pin.Digest, out.Pin.Available = x.Tag, x.Digest, true
			}
		}
	}
	switch {
	case overdue:
		out.State = runr.PatchOverdue
	case out.Pin != nil:
		out.State = runr.PatchPinned
	case len(out.Pending) > 0:
		out.State = runr.PatchAvailable
	}
	return out
}

// migrate starts a redeploy of the live version onto a patch (RuntimePatchMigrations::start).
func (s *Server) migrate(p *project, patch runr.RuntimePatch, trigger string) (*runr.RuntimePatchMigration, *apiErr) {
	if p.data.Stopped != nil {
		return nil, &apiErr{status: 409, code: "project_stopped", message: "The project is stopped: resume it first."}
	}
	var live *deployment
	for _, d := range p.deployments {
		if d.data.Status == runr.DeploymentActive {
			live = d
		}
	}
	if live == nil {
		return nil, &apiErr{status: 409, code: "not_deployed", message: "Nothing of the project is live: the next deploy uses the newest patch (or the pin)."}
	}
	st := s.patchSt()
	if st.running[p.data.ID] == patch.ID && trigger != "pin" {
		return nil, &apiErr{status: 409, code: "already_running", message: "The project already runs " + patch.Tag + "."}
	}
	var v *version
	for _, x := range p.versions {
		if live.data.Version != nil && x.data.ID == live.data.Version.ID {
			v = x
		}
	}
	if v == nil {
		return nil, &apiErr{status: 409, code: "not_deployed", message: "The live version is gone."}
	}
	d := s.startDeployment(p, v)
	m := runr.RuntimePatchMigration{ID: s.newID(), PatchID: patch.ID, Trigger: trigger, Status: "started",
		DeploymentID: d.data.ID, PreviousPatchID: st.running[p.data.ID], CreatedAt: nowRFC3339()}
	st.running[p.data.ID] = patch.ID
	st.migrations[p.data.ID] = append([]runr.RuntimePatchMigration{m}, st.migrations[p.data.ID]...)
	return &m, nil
}

func init() {
	handle("GET /runtime-patches", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.patchSt()
		list := []runr.RuntimePatch{}
		for _, p := range st.patches {
			if p.InFeed && (q.query("runtime") == "" || p.Runtime == q.query("runtime")) && (q.query("version") == "" || p.Version == q.query("version")) {
				list = append(list, p)
			}
		}
		sort.SliceStable(list, func(i, j int) bool {
			if list[i].Runtime != list[j].Runtime {
				return list[i].Runtime < list[j].Runtime
			}
			if list[i].Version != list[j].Version {
				return list[i].Version < list[j].Version
			}
			return list[i].PublishedAt > list[j].PublishedAt
		})
		return 200, map[string]any{"data": list, "feed": runr.PatchFeedStatus{Enabled: true, Status: "ok", FetchedAt: nowRFC3339()}, "keep": patchKeep}, nil
	})

	handle("GET /projects/{project}/runtime-patches", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.patchSt()
		patches := []runr.RuntimePatch{}
		if p.data.ServiceType != runr.TypeCaaS {
			patches = st.patchesOf(p.data.Runtime, p.data.RuntimeVersion)
			if len(patches) > patchKeep {
				patches = patches[:patchKeep]
			}
		}
		migrations := st.migrations[p.data.ID]
		if migrations == nil {
			migrations = []runr.RuntimePatchMigration{}
		}
		return 200, map[string]any{"data": map[string]any{"state": s.patchState(p), "patches": patches, "keep": patchKeep,
			"auto_migrate": !st.noAutoMigrate, "migrations": migrations}}, nil
	})

	handle("POST /projects/{project}/runtime-patches/apply", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		patches := s.patchSt().patchesOf(p.data.Runtime, p.data.RuntimeVersion)
		if p.data.ServiceType == runr.TypeCaaS || len(patches) == 0 {
			return 0, nil, &apiErr{status: 422, code: "no_patch", message: "There is no runtime patch for this runtime version."}
		}
		m, err := s.migrate(p, patches[0], "apply")
		if err != nil {
			return 0, nil, err
		}
		delete(s.patchSt().pins, p.data.ID) // applying the newest patch ends a pin
		return 202, map[string]any{"data": m, "message": "Redeploying the live version onto " + patches[0].Tag + "."}, nil
	})

	handle("PUT /projects/{project}/runtime-patches/pin", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		st := s.patchSt()
		patches := st.patchesOf(p.data.Runtime, p.data.RuntimeVersion)
		if len(patches) > patchKeep {
			patches = patches[:patchKeep]
		}
		var patch *runr.RuntimePatch
		for i := range patches {
			if patches[i].ID == q.str("patch_id") {
				patch = &patches[i]
			}
		}
		if patch == nil {
			return 0, nil, &apiErr{status: 422, code: "not_pinnable", message: "A project can be pinned to one of the 5 newest patches of its runtime version only."}
		}
		st.pins[p.data.ID] = patch.ID
		deploy, ok := q.Body["deploy"].(bool)
		if !ok {
			deploy = true
		}
		deployed := false
		for _, d := range p.deployments {
			deployed = deployed || d.data.Status == runr.DeploymentActive
		}
		if !deploy || !deployed || st.running[p.data.ID] == patch.ID {
			return 200, map[string]any{"data": s.patchState(p), "migration": nil}, nil
		}
		m, err := s.migrate(p, *patch, "pin")
		if err != nil {
			return 0, nil, err
		}
		return 202, map[string]any{"data": s.patchState(p), "migration": m}, nil
	})

	handle("DELETE /projects/{project}/runtime-patches/pin", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		delete(s.patchSt().pins, p.data.ID)
		return 200, map[string]any{"data": s.patchState(p)}, nil
	})
}
