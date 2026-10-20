package runrtest

import (
	"fmt"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// Deploys that wait for their version's build (QueuedDeploys in the API). A version marked with
// RebuildVersion was built into a registry its region no longer pulls from (before the region
// moved to another cluster): POST /deploy builds it again and answers with a queued deploy,
// which GET /projects/{project}/queued-deploys/{queuedDeploy} follows — waiting for
// QueuedConfig.Polls reads, then its deployment starts (status deployed), or it fails with
// QueuedConfig.FailMessage when QueuedConfig.Fail is set.

// QueuedConfig controls the queued deploys of the fake.
type QueuedConfig struct {
	// Polls is how many reads a queued deploy keeps waiting for the build.
	Polls int
	// Fail ends queued deploys in "failed" with FailMessage (the build failed).
	Fail        bool
	FailMessage string
}

type queuedState struct {
	cfg     QueuedConfig
	rebuild map[string]bool // version ids built for another registry
	items   []*fakeQueued
}

type fakeQueued struct {
	data         runr.QueuedDeploy
	project      *project
	version      *version
	pollsLeft    int
	fails        bool
	failMessage  string
	deployConfig map[string]any
}

func (s *Server) queuedSt() *queuedState {
	return featureState(s, "queued-deploys", func() *queuedState {
		return &queuedState{
			cfg:     QueuedConfig{Polls: 1, FailMessage: "pip: No matching distribution found for left-pad==9"},
			rebuild: map[string]bool{},
		}
	})
}

// Queued returns the settings of queued deploys; change them before the deploy.
func (s *Server) Queued() *QueuedConfig {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.queuedSt().cfg
}

// RebuildVersion marks version number of a project as built into a registry its region no
// longer pulls from: its next deploy is answered with a queued deploy.
func (s *Server) RebuildVersion(projectName string, number int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	p := s.projectByName(projectName)
	if p == nil {
		s.t.Fatalf("runrtest: project %q does not exist", projectName)
	}
	for _, v := range p.versions {
		if v.data.Number == number {
			s.queuedSt().rebuild[v.data.ID] = true
			return
		}
	}
	s.t.Fatalf("runrtest: project %q has no version %d", projectName, number)
}

// QueuedDeploys returns the queued deploys of a project, oldest first.
func (s *Server) QueuedDeploys(projectName string) []runr.QueuedDeploy {
	s.mu.Lock()
	defer s.mu.Unlock()
	var out []runr.QueuedDeploy
	for _, q := range s.queuedSt().items {
		if q.project.data.Name == projectName {
			out = append(out, q.data)
		}
	}
	return out
}

// queueRebuild answers a deploy of a version marked with RebuildVersion: the version builds
// again, the deploy waits for it.
func (s *Server) queueRebuild(p *project, v *version, body map[string]any) (int, any, *apiErr) {
	st := s.queuedSt()
	delete(st.rebuild, v.data.ID)
	v.data.BuildStatus = runr.BuildPending
	v.pollsLeft = 1 << 30 // ready when the queued deploy goes on
	msg := fmt.Sprintf("%s is built again for this region's registry: its image is in a registry the region's cluster does not pull from (built before the region moved to another cluster). It deploys when the build is ready.", v.data.Label)
	q := &fakeQueued{project: p, version: v, pollsLeft: st.cfg.Polls, fails: st.cfg.Fail, failMessage: st.cfg.FailMessage}
	if dc, ok := body["deploy_config"].(map[string]any); ok {
		q.deployConfig = dc
	}
	now := nowRFC3339()
	q.data = runr.QueuedDeploy{ID: s.newID(), ProjectID: p.data.ID, VersionID: v.data.ID, Version: v.data.Number,
		Status: runr.QueuedWaiting, Reason: "registry-rebuild", Message: msg, CreatedAt: now, UpdatedAt: now}
	st.items = append(st.items, q)
	return 202, map[string]any{"message": msg, "queued_deploy": q.data}, nil
}

// advanceQueued takes a queued deploy one read further.
func (s *Server) advanceQueued(q *fakeQueued) {
	if q.data.Status != runr.QueuedWaiting {
		return
	}
	if q.pollsLeft > 0 {
		q.pollsLeft--
		return
	}
	q.data.UpdatedAt = nowRFC3339()
	if q.fails {
		q.version.data.BuildStatus = runr.BuildFailed
		q.version.data.BuildError = q.failMessage
		q.data.Status = runr.QueuedFailed
		q.data.Message = q.version.data.Label + " could not be built: " + q.failMessage
		return
	}
	q.version.data.BuildStatus = runr.BuildReady
	q.version.pollsLeft = 0
	d := s.startDeployment(q.project, q.version)
	if q.deployConfig != nil {
		d.data.DeployConfig = q.deployConfig
	}
	q.data.Status = runr.QueuedDeployed
	q.data.DeploymentID = d.data.ID
	q.data.Message = q.version.data.Label + " was built and its deploy started."
}

func init() {
	handle("GET /projects/{project}/queued-deploys/{queuedDeploy}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		for _, item := range s.queuedSt().items {
			if item.project == p && item.data.ID == q.Segs[3] {
				s.advanceQueued(item)
				return 200, map[string]any{"data": item.data}, nil
			}
		}
		return 0, nil, errNotFound()
	})
}
