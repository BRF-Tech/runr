package runr_test

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/brf-tech/runr/cli/pkg/runr"
	"github.com/brf-tech/runr/cli/pkg/runr/runrtest"
)

// A version built into a registry its region no longer pulls from (before the region moved to
// another cluster) is built again: the deploy answers with a queued deploy, which is followed
// until its deployment starts.
func TestStartDeployQueuesARebuild(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})

	// A version in the region's registry deploys at once
	start, err := c.StartDeploy(ctx, p.ID, v.ID, nil)
	if err != nil || start.Deployment == nil || start.Queued != nil || start.Deployment.Status != runr.DeploymentPending {
		t.Fatalf("plain deploy = %+v, %v", start, err)
	}

	srv.RebuildVersion("svc", 1)
	srv.Queued().Polls = 2
	start, err = c.StartDeploy(ctx, p.ID, v.ID, &runr.DeployConfig{MinScale: intp(1)})
	if err != nil || start.Deployment != nil || start.Queued == nil {
		t.Fatalf("rebuild deploy = %+v, %v", start, err)
	}
	q := start.Queued
	if q.Status != runr.QueuedWaiting || q.Version != 1 || q.VersionID != v.ID || q.ProjectID != p.ID || q.Reason != "registry-rebuild" {
		t.Fatalf("queued deploy = %+v", q)
	}
	if !strings.Contains(start.Message, "v1 is built again for this region's registry") {
		t.Fatalf("message = %q", start.Message)
	}

	got, err := c.GetQueuedDeploy(ctx, p.ID, q.ID)
	if err != nil || got.ID != q.ID || got.Status != runr.QueuedWaiting {
		t.Fatalf("get = %+v, %v", got, err)
	}

	var seen []string
	done, err := c.WaitQueuedDeploy(ctx, p.ID, q, runr.WaitOptions{Interval: time.Millisecond, Progress: func(s string) { seen = append(seen, s) }})
	if err != nil || done.Status != runr.QueuedDeployed || done.DeploymentID == "" {
		t.Fatalf("wait = %+v, %v", done, err)
	}
	if len(seen) != 2 || !strings.HasPrefix(seen[0], "waiting: v1 is built again") || seen[1] != "deployed" {
		t.Fatalf("progress = %v", seen)
	}
	d, err := c.GetDeployment(ctx, p.ID, done.DeploymentID)
	if err != nil || d.Version == nil || d.Version.ID != v.ID || d.DeployConfig["min_scale"] == nil {
		t.Fatalf("deployment = %+v, %v", d, err)
	}

	if _, err := c.GetQueuedDeploy(ctx, p.ID, "nope"); !runr.IsNotFound(err) {
		t.Fatalf("unknown queued deploy: %v", err)
	}
}

// Deploy keeps its signature for a rebuilt version: it waits for the build and returns the
// deployment the queued deploy started.
func TestDeployFollowsAQueuedRebuild(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	srv.RebuildVersion("svc", 1)
	srv.Queued().Polls = 0

	d, err := c.Deploy(ctx, p.ID, v.ID, nil)
	if err != nil || d.ID == "" || d.Version == nil || d.Version.ID != v.ID {
		t.Fatalf("deploy = %+v, %v", d, err)
	}
	queued := srv.QueuedDeploys("svc")
	if len(queued) != 1 || queued[0].DeploymentID != d.ID {
		t.Fatalf("queued deploys = %+v, deployment %s", queued, d.ID)
	}

	// A build that fails ends the deploy with the reason
	srv.RebuildVersion("svc", 1)
	srv.Queued().Fail = true
	_, err = c.Deploy(ctx, p.ID, v.ID, nil)
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.QueuedFailed || !strings.Contains(err.Error(), "queued deploy v1 failed: v1 could not be built: pip") {
		t.Fatalf("failed rebuild: %v", err)
	}
}

func TestWaitQueuedDeployTimeoutAndCancel(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	p := srv.SeedProject("svc", runr.TypeFaaS, "python", "eu-1", nil)
	v := srv.SeedFiles("svc", map[string]string{"main.py": "def handler(e, c):\n    return 1\n"})
	srv.RebuildVersion("svc", 1)
	srv.Queued().Polls = 1000

	start, err := c.StartDeploy(ctx, p.ID, v.ID, nil)
	if err != nil || start.Queued == nil {
		t.Fatalf("start = %+v, %v", start, err)
	}
	_, err = c.WaitQueuedDeploy(ctx, p.ID, start.Queued, runr.WaitOptions{Interval: time.Millisecond, Timeout: 30 * time.Millisecond})
	var te *runr.TimeoutError
	if !errors.As(err, &te) || !strings.Contains(err.Error(), "queued deploy v1") {
		t.Fatalf("timeout: %v", err)
	}

	// A queued deploy a newer deploy replaced ends as cancelled
	cancelled := *start.Queued
	cancelled.Status, cancelled.Message = runr.QueuedCancelled, "Replaced by a newer deploy of the project."
	_, err = c.WaitQueuedDeploy(ctx, p.ID, &cancelled, runr.WaitOptions{Interval: time.Millisecond})
	var fe *runr.FailedError
	if !errors.As(err, &fe) || fe.Status != runr.QueuedCancelled || !strings.Contains(err.Error(), "Replaced by a newer deploy") {
		t.Fatalf("cancelled: %v", err)
	}
}

// GET migration-status lists what the move did with each project while it can be rolled back.
func TestRegionMigrationProjects(t *testing.T) {
	srv := runrtest.New(t)
	c := client(t, srv)
	ctx := context.Background()
	reg, err := c.FindRegion(ctx, "eu-1")
	if err != nil {
		t.Fatal(err)
	}
	rows := []runr.MigrationProject{
		{ProjectID: "p1", Project: "orders", Outcome: runr.MigrationBuilding, Version: 3, Message: "Moving to the new cluster of the region: v3 is built again there; deployed there when it is ready."},
		{Organization: "acme", ProjectID: "p2", Project: "shop", Outcome: runr.MigrationMoved, Version: 1, DeploymentStatus: "active"},
	}
	srv.SetRegionMigrationProjects("eu-1", rows)

	st, err := c.RegionMigrationState(ctx, reg.ID)
	if err != nil || len(st.Projects) != 0 {
		t.Fatalf("no move: %+v, %v", st, err)
	}

	srv.RegionAdmin().InstallPolls = 0
	if _, err := c.StartRegionMigration(ctx, reg.ID, "apiVersion: v1\nkind: Config\nclusters: []\n", ""); err != nil {
		t.Fatal(err)
	}
	st, err = c.WaitRegionMigration(ctx, reg.ID, reg.Name, runr.WaitOptions{Interval: time.Millisecond})
	if err != nil || st.Status != runr.MigrationDNSCheck {
		t.Fatalf("migration = %+v, %v", st, err)
	}
	if len(st.Projects) != 2 || st.Projects[0] != rows[0] || st.Projects[1] != rows[1] {
		t.Fatalf("projects = %+v", st.Projects)
	}
}
