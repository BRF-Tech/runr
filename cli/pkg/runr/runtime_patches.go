package runr

import (
	"context"
	"net/http"
	"net/url"
)

// Runtime patches (docs/runtimes.md#runtime-patches): runtime images get immutable patch tags
// (exec-php:8.3.36-r1), announced by a signed feed with why each came (level, reason, CVEs) and,
// for critical and zero-day patches, a deadline. A project runs one patch by digest; it can apply
// the newest one, pin one of the newest five or wait — until a critical / zero-day deadline,
// when RunR moves it on.

// Patch levels, from the least to the most urgent.
const (
	PatchRoutine  = "routine"
	PatchSecurity = "security"
	PatchCritical = "critical"
	PatchZeroDay  = "zero-day"
)

// Patch states of a project (RuntimePatchState.State).
const (
	PatchUpToDate  = "up_to_date"
	PatchAvailable = "patch_available"
	PatchPinned    = "pinned"
	PatchOverdue   = "overdue"
)

// RuntimePatch is one patch tag of a runtime version (RuntimePatch::toApi).
type RuntimePatch struct {
	ID      string `json:"id"`
	Runtime string `json:"runtime"`
	Version string `json:"version"`
	Tag     string `json:"tag"`
	Image   string `json:"image,omitempty"`
	Digest  string `json:"digest,omitempty"`
	Level   string `json:"level"`
	// Reason is in the language the client asked for (Accept-Language); Reasons has every one.
	Reason      string            `json:"reason,omitempty"`
	Reasons     map[string]string `json:"reasons,omitempty"`
	CVEs        []string          `json:"cves,omitempty"`
	URL         string            `json:"url,omitempty"`
	Upstream    string            `json:"upstream,omitempty"`
	PublishedAt string            `json:"published_at,omitempty"`
	// DeadlineAt is set for critical and zero-day patches: the deadline that counts (a platform
	// administrator may have extended it, DeadlineExtended).
	DeadlineAt       string `json:"deadline_at,omitempty"`
	DeadlineExtended bool   `json:"deadline_extended,omitempty"`
	InFeed           bool   `json:"in_feed"`
}

// PatchRef names a patch in a project's state (current, latest); ID and Tag are empty for a
// digest that is no feed patch (deployed before patches were known).
type PatchRef struct {
	ID          string `json:"id,omitempty"`
	Tag         string `json:"tag,omitempty"`
	Level       string `json:"level,omitempty"`
	Digest      string `json:"digest,omitempty"`
	PublishedAt string `json:"published_at,omitempty"`
}

// Label is the tag, else the digest.
func (r *PatchRef) Label() string {
	if r == nil {
		return ""
	}
	if r.Tag != "" {
		return r.Tag
	}
	return r.Digest
}

// PatchPin is the patch a project is pinned to. Available is false when the patch is no longer
// one of the newest of its runtime version (the next deploy then uses the newest one); By is
// "runr" for the pin a rolled-back automatic migration set.
type PatchPin struct {
	ID        string `json:"id,omitempty"`
	Tag       string `json:"tag,omitempty"`
	Digest    string `json:"digest,omitempty"`
	Available bool   `json:"available"`
	By        string `json:"by,omitempty"`
}

// Label is the tag, else the id, else the digest.
func (p *PatchPin) Label() string {
	switch {
	case p == nil:
		return ""
	case p.Tag != "":
		return p.Tag
	case p.ID != "":
		return p.ID
	}
	return p.Digest
}

// RuntimePatchState is ProjectResource.runtime_patch: nil when no patch concerns the project
// (CaaS, a runtime version without patches, an API before runtime patches).
type RuntimePatchState struct {
	// State is up_to_date, patch_available, pinned or overdue (a critical / zero-day deadline
	// passed and the project still runs an older patch).
	State string `json:"state"`
	// Level is the most urgent level of the patches the project runs behind.
	Level      string    `json:"level,omitempty"`
	DeadlineAt string    `json:"deadline_at,omitempty"`
	Deployed   bool      `json:"deployed"`
	Current    *PatchRef `json:"current,omitempty"`
	Latest     *PatchRef `json:"latest,omitempty"`
	Pending    []string  `json:"pending,omitempty"`
	Pin        *PatchPin `json:"pin,omitempty"`
}

// RuntimePatchMigration is one redeploy of a project onto a patch: trigger apply, pin, auto (at
// a deadline) or force (a platform administrator); status started, succeeded, failed or
// rolled_back. A prebuilt version is built again first (QueuedDeployID, then DeploymentID).
type RuntimePatchMigration struct {
	ID              string `json:"id"`
	PatchID         string `json:"patch_id,omitempty"`
	Trigger         string `json:"trigger"`
	Status          string `json:"status"`
	DeploymentID    string `json:"deployment_id,omitempty"`
	QueuedDeployID  string `json:"queued_deploy_id,omitempty"`
	PreviousPatchID string `json:"previous_patch_id,omitempty"`
	Message         string `json:"message,omitempty"`
	CreatedAt       string `json:"created_at,omitempty"`
	FinishedAt      string `json:"finished_at,omitempty"`
}

// ProjectRuntimePatches is GET /projects/{project}/runtime-patches: the state, the patches the
// project can be pinned to (newest first, Keep of them), whether RunR migrates at deadlines and
// the last migrations.
type ProjectRuntimePatches struct {
	State       *RuntimePatchState      `json:"state"`
	Patches     []RuntimePatch          `json:"patches"`
	Keep        int                     `json:"keep"`
	AutoMigrate bool                    `json:"auto_migrate"`
	Migrations  []RuntimePatchMigration `json:"migrations"`
}

// Find returns the patch with this id or tag.
func (p *ProjectRuntimePatches) Find(ref string) *RuntimePatch {
	for i := range p.Patches {
		if p.Patches[i].ID == ref || p.Patches[i].Tag == ref {
			return &p.Patches[i]
		}
	}
	return nil
}

// PatchFeedStatus is the feed part of GET /runtime-patches.
type PatchFeedStatus struct {
	Enabled   bool   `json:"enabled"`
	Status    string `json:"status"`
	FetchedAt string `json:"fetched_at,omitempty"`
}

// RuntimePatchList is GET /runtime-patches.
type RuntimePatchList struct {
	Patches []RuntimePatch  `json:"data"`
	Feed    PatchFeedStatus `json:"feed"`
	Keep    int             `json:"keep"`
}

// ProjectRuntimePatches returns the runtime patch state of a project; ErrUnsupported on APIs
// without runtime patches.
func (c *Client) ProjectRuntimePatches(ctx context.Context, projectID string) (*ProjectRuntimePatches, error) {
	var out struct {
		Data ProjectRuntimePatches `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/runtime-patches"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// ApplyRuntimePatch redeploys the live version of a project onto the newest patch of its runtime
// version (a pin is removed). The API answers 202 with the migration and a message; 409 when
// nothing is deployed, the project is stopped, runs the patch already or migrates.
func (c *Client) ApplyRuntimePatch(ctx context.Context, projectID string) (*RuntimePatchMigration, string, error) {
	var out struct {
		Data    RuntimePatchMigration `json:"data"`
		Message string                `json:"message"`
	}
	err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/runtime-patches/apply"}, &out)
	if IsNotFound(err) {
		return nil, "", ErrUnsupported
	}
	if err != nil {
		return nil, "", err
	}
	return &out.Data, out.Message, nil
}

// PinRuntimePatch pins a project to one of the newest patches of its runtime version (by id) and,
// with deploy, redeploys the live version onto it: the migration is nil when nothing was
// redeployed (not deployed, runs it already, or deploy false).
func (c *Client) PinRuntimePatch(ctx context.Context, projectID, patchID string, deploy bool) (*RuntimePatchState, *RuntimePatchMigration, error) {
	body := struct {
		PatchID string `json:"patch_id"`
		Deploy  bool   `json:"deploy"`
	}{PatchID: patchID, Deploy: deploy}
	var out struct {
		Data      *RuntimePatchState     `json:"data"`
		Migration *RuntimePatchMigration `json:"migration"`
	}
	err := c.do(ctx, request{method: http.MethodPut, path: "/projects/" + projectID + "/runtime-patches/pin", body: body}, &out)
	if IsNotFound(err) {
		return nil, nil, ErrUnsupported
	}
	if err != nil {
		return nil, nil, err
	}
	return out.Data, out.Migration, nil
}

// UnpinRuntimePatch removes a project's pin: its next deploy uses the newest patch.
func (c *Client) UnpinRuntimePatch(ctx context.Context, projectID string) (*RuntimePatchState, error) {
	var out struct {
		Data *RuntimePatchState `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/runtime-patches/pin"}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return out.Data, nil
}

// ListRuntimePatches returns the patches of the feed, newest first per runtime version; runtime
// and version filter ("" = all).
func (c *Client) ListRuntimePatches(ctx context.Context, runtime, version string) (*RuntimePatchList, error) {
	q := url.Values{}
	if runtime != "" {
		q.Set("runtime", runtime)
	}
	if version != "" {
		q.Set("version", version)
	}
	var out RuntimePatchList
	err := c.do(ctx, request{method: http.MethodGet, path: "/runtime-patches", query: q}, &out)
	if IsNotFound(err) {
		return nil, ErrUnsupported
	}
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// WaitRuntimePatchMigration follows a migration's redeploy until it is live: a prebuilt version's
// build first (the queued deploy), then the deployment. A failed deployment is a
// *DeploymentFailedError (the previous revision keeps serving).
func (c *Client) WaitRuntimePatchMigration(ctx context.Context, projectID string, m *RuntimePatchMigration, opts WaitOptions) (*Deployment, error) {
	deploymentID := m.DeploymentID
	if deploymentID == "" && m.QueuedDeployID != "" {
		q, err := c.GetQueuedDeploy(ctx, projectID, m.QueuedDeployID)
		if err != nil {
			return nil, err
		}
		if q, err = c.WaitQueuedDeploy(ctx, projectID, q, opts); err != nil {
			return nil, err
		}
		deploymentID = q.DeploymentID
	}
	if deploymentID == "" {
		return nil, nil
	}
	return c.WaitDeployment(ctx, projectID, deploymentID, opts)
}
