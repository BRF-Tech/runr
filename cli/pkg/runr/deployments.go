package runr

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"time"
)

// DeployConfig overrides the project config for one deployment.
type DeployConfig struct {
	CPU            *string `json:"cpu,omitempty"`
	MemoryMB       *int    `json:"memory_mb,omitempty"`
	TimeoutSeconds *int    `json:"timeout_seconds,omitempty"`
	ScaleToZero    *bool   `json:"scale_to_zero,omitempty"`
	MinScale       *int    `json:"min_scale,omitempty"`
	MaxScale       *int    `json:"max_scale,omitempty"`
	// Strategy overrides the project's deploy strategy for this deploy (StrategyDirect,
	// StrategyBlueGreen or StrategyCanary; "" keeps the project's), e.g. direct for an
	// emergency fix. It is sent as the deploy's strategy field, not in deploy_config; the
	// strategy's options always come from the project.
	Strategy string `json:"-"`
}

// configEmpty reports whether no config field is overridden (Strategy aside).
func (d *DeployConfig) configEmpty() bool {
	c := *d
	c.Strategy = ""
	return c == DeployConfig{}
}

// ListDeployments returns a project's deployments, newest first: those of its own workload
// (the primary environment).
func (c *Client) ListDeployments(ctx context.Context, projectID string) ([]Deployment, error) {
	return c.ListEnvironmentDeployments(ctx, projectID, "")
}

// ListEnvironmentDeployments returns the deployments of one environment of the project (name or
// id; "" the project's own workload, "all" every environment's), newest first.
func (c *Client) ListEnvironmentDeployments(ctx context.Context, projectID, environment string) ([]Deployment, error) {
	q := url.Values{}
	if environment != "" {
		q.Set("environment", environment)
	}
	var out struct {
		Data []Deployment `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/deployments", query: q}, &out); err != nil {
		if environment != "" {
			return nil, environmentErr(err, environment)
		}
		return nil, err
	}
	return out.Data, nil
}

// Statuses of a queued deploy.
const (
	QueuedWaiting   = "waiting"
	QueuedStarting  = "starting"
	QueuedDeployed  = "deployed"
	QueuedFailed    = "failed"
	QueuedCancelled = "cancelled"
)

// QueuedDeploy is a deploy that waits for its version's build. The API answers a deploy with
// one when the version's image is not in the region's registry (it was built before the region
// moved to another cluster): the version is built again into the region's registry, and the
// deploy starts when the build is ready, through the deploy checks of every deploy.
// DeploymentID is the deployment it started (status deployed).
type QueuedDeploy struct {
	ID        string `json:"id"`
	ProjectID string `json:"project_id,omitempty"`
	VersionID string `json:"version_id,omitempty"`
	// Version is the version's number (0 when the version is gone).
	Version int `json:"version,omitempty"`
	// Status is waiting, starting, deployed, failed or cancelled.
	Status string `json:"status"`
	// Reason is what queued it, e.g. registry-rebuild.
	Reason       string `json:"reason,omitempty"`
	Message      string `json:"message,omitempty"`
	DeploymentID string `json:"deployment_id,omitempty"`
	CreatedAt    string `json:"created_at,omitempty"`
	UpdatedAt    string `json:"updated_at,omitempty"`
}

// label is "v3", or the id when the version is unknown.
func (q *QueuedDeploy) label() string {
	if q.Version > 0 {
		return "v" + strconv.Itoa(q.Version)
	}
	return q.ID
}

// DeployStart is what a deploy started: the deployment, or — when the version is built again
// for the region's registry first — the queued deploy, with the API's message.
type DeployStart struct {
	Deployment *Deployment
	Queued     *QueuedDeploy
	Message    string
}

// StartDeploy starts a deployment of a version. The API answers 202 with a pending deployment,
// or, when the version's image is not in the region's registry (built before the region moved
// to another cluster), with a queued deploy: the version is built again and deploys when the
// build is ready (WaitQueuedDeploy follows it). A deploy of a project with the blue-green or
// canary strategy while a deployment serves it opens a rollout: the deployment's Rollout is set.
func (c *Client) StartDeploy(ctx context.Context, projectID, versionID string, override *DeployConfig) (*DeployStart, error) {
	return c.StartDeployWith(ctx, projectID, versionID, DeployOptions{Override: override})
}

// DeployOptions are what a deploy may name besides its version.
type DeployOptions struct {
	// Override changes the project config for this deployment only.
	Override *DeployConfig
	// Environment deploys to one of the project's environments (name or id) instead of its
	// own workload (docs/environments.md). An API before environments ignores the field:
	// resolve the environment first (GetEnvironment) to refuse such a deploy.
	Environment string
	// RestoreConfig runs the config an earlier deployment of the version ran with — what a
	// rollback does (docs/versioning.md#rollback) — instead of the current config;
	// RestoreFromDeployment names that deployment (default: the newest of the version in the
	// environment). An API before config snapshots deploys with the current config.
	RestoreConfig         bool
	RestoreFromDeployment string
}

// StartDeployWith is StartDeploy with an environment and a restored config.
func (c *Client) StartDeployWith(ctx context.Context, projectID, versionID string, opts DeployOptions) (*DeployStart, error) {
	body := struct {
		VersionID               string        `json:"version_id"`
		DeployConfig            *DeployConfig `json:"deploy_config,omitempty"`
		Strategy                string        `json:"strategy,omitempty"`
		Environment             string        `json:"environment,omitempty"`
		RestoreConfig           bool          `json:"restore_config,omitempty"`
		RestoreFromDeploymentID string        `json:"restore_from_deployment_id,omitempty"`
	}{VersionID: versionID, Environment: opts.Environment, RestoreConfig: opts.RestoreConfig,
		RestoreFromDeploymentID: opts.RestoreFromDeployment}
	if override := opts.Override; override != nil {
		body.Strategy = override.Strategy
		if !override.configEmpty() {
			body.DeployConfig = override
		}
	}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/deploy", body: body}, &raw); err != nil {
		if opts.Environment != "" {
			return nil, environmentErr(err, opts.Environment)
		}
		return nil, err
	}
	return decodeDeployStart(raw)
}

// RollbackDeployment deploys a deployment's version again in its environment with the config
// it ran with (its recorded snapshot and overrides), or with the current config when
// keepCurrentConfig (docs/versioning.md#rollback). ErrUnsupported on APIs without it.
func (c *Client) RollbackDeployment(ctx context.Context, projectID, deploymentID string, keepCurrentConfig bool) (*DeployStart, error) {
	body := map[string]bool{"keep_current_config": keepCurrentConfig}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/deployments/" + seg(deploymentID) + "/rollback", body: body}, &raw); err != nil {
		if IsNotFound(err) {
			return nil, ErrUnsupported
		}
		return nil, err
	}
	return decodeDeployStart(raw)
}

// decodeDeployStart reads the answer of a deploy: a deployment, or a queued deploy.
func decodeDeployStart(raw []byte) (*DeployStart, error) {
	var queued struct {
		Message string        `json:"message"`
		Queued  *QueuedDeploy `json:"queued_deploy"`
	}
	if err := json.Unmarshal(raw, &queued); err == nil && queued.Queued != nil && queued.Queued.ID != "" {
		msg := queued.Message
		if msg == "" {
			msg = queued.Queued.Message
		}
		return &DeployStart{Queued: queued.Queued, Message: msg}, nil
	}
	var d Deployment
	if err := decodeData(raw, &d); err != nil {
		return nil, fmt.Errorf("decode deployment: %w", err)
	}
	return &DeployStart{Deployment: &d}, nil
}

// Deploy starts a deployment of a version. The API answers 202 with a pending deployment. When
// the version is built again for the region's registry first (StartDeploy's queued deploy),
// Deploy waits — up to the default 10 minutes of WaitOptions — until its deploy has started and
// returns that deployment; a build that fails or a deploy check that refuses it is an error.
func (c *Client) Deploy(ctx context.Context, projectID, versionID string, override *DeployConfig) (*Deployment, error) {
	start, err := c.StartDeploy(ctx, projectID, versionID, override)
	if err != nil {
		return nil, err
	}
	if start.Queued == nil {
		return start.Deployment, nil
	}
	q, err := c.WaitQueuedDeploy(ctx, projectID, start.Queued, WaitOptions{})
	if err != nil {
		return nil, err
	}
	return c.GetDeployment(ctx, projectID, q.DeploymentID)
}

// GetQueuedDeploy returns a deploy that waits for its version's build (projects.view).
func (c *Client) GetQueuedDeploy(ctx context.Context, projectID, queuedID string) (*QueuedDeploy, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/queued-deploys/" + url.PathEscape(queuedID)}, &raw); err != nil {
		return nil, err
	}
	var q QueuedDeploy
	if err := decodeData(raw, &q); err != nil {
		return nil, fmt.Errorf("decode queued deploy: %w", err)
	}
	return &q, nil
}

// WaitQueuedDeploy polls a queued deploy until its deploy has started (status deployed, with
// its DeploymentID). A failed or cancelled one is a *FailedError with the API's message.
func (c *Client) WaitQueuedDeploy(ctx context.Context, projectID string, q *QueuedDeploy, opts WaitOptions) (*QueuedDeploy, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	what := "queued deploy " + q.label()
	last := ""
	cur := q
	for {
		status := cur.Status
		if cur.Message != "" && cur.Status != QueuedDeployed {
			status += ": " + cur.Message
		}
		if status != last {
			last = status
			if opts.Progress != nil {
				opts.Progress(status)
			}
		}
		switch cur.Status {
		case QueuedDeployed:
			if cur.DeploymentID != "" {
				return cur, nil
			}
		case QueuedFailed, QueuedCancelled:
			return cur, &FailedError{What: "queued deploy", Ref: cur.label(), Status: cur.Status, Message: cur.Message}
		}
		select {
		case <-ctx.Done():
			return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
		next, err := c.GetQueuedDeploy(ctx, projectID, cur.ID)
		if err != nil {
			if ctx.Err() != nil {
				return cur, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return cur, err
		}
		cur = next
	}
}

// GetDeployment returns one deployment.
func (c *Client) GetDeployment(ctx context.Context, projectID, deploymentID string) (*Deployment, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/deployments/" + deploymentID}, &raw); err != nil {
		return nil, err
	}
	var d Deployment
	if err := decodeData(raw, &d); err != nil {
		return nil, fmt.Errorf("decode deployment: %w", err)
	}
	return &d, nil
}

// DeleteDeployment removes a deployment; a live one is torn down in the cluster first.
func (c *Client) DeleteDeployment(ctx context.Context, projectID, deploymentID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/projects/" + projectID + "/deployments/" + deploymentID}, nil)
}

// DeploymentLogs returns a deployment's logs. sinceMs > 0 asks for lines after that
// timestamp (Loki); the API returns everything it has otherwise.
func (c *Client) DeploymentLogs(ctx context.Context, projectID, deploymentID string, sinceMs int64) (*Logs, error) {
	q := url.Values{}
	if sinceMs > 0 {
		q.Set("since", strconv.FormatInt(sinceMs, 10))
	}
	var out Logs
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/deployments/" + deploymentID + "/logs", query: q}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// CurrentDeployment returns the deployment that serves the project: the newest active one,
// else the newest live one, else the newest. Nil when there is none.
func (c *Client) CurrentDeployment(ctx context.Context, projectID string) (*Deployment, error) {
	list, err := c.ListDeployments(ctx, projectID)
	if err != nil {
		return nil, err
	}
	return PickCurrentDeployment(list), nil
}

// PickCurrentDeployment applies CurrentDeployment's rule to a newest-first list.
func PickCurrentDeployment(list []Deployment) *Deployment {
	for i := range list {
		if list[i].Status == DeploymentActive {
			return &list[i]
		}
	}
	for i := range list {
		if list[i].Live() {
			return &list[i]
		}
	}
	if len(list) > 0 {
		return &list[0]
	}
	return nil
}

// DeploymentFailedError means the deployment ended in "failed".
type DeploymentFailedError struct {
	Deployment *Deployment
}

func (e *DeploymentFailedError) Error() string {
	msg := e.Deployment.KnativeMessage
	if msg == "" {
		msg = "no reason given"
	}
	return fmt.Sprintf("deployment %s failed: %s", e.Deployment.ID, msg)
}

// WaitDeployment polls a deployment until it is active (ready) or failed. The new deployment
// of a rollout is ready once the rollout is no longer pending: it serves its test URL (and its
// first canary share) while the previous deployment stays active, so WaitDeployment returns it
// then, "running" with its Rollout, instead of waiting for the promotion.
func (c *Client) WaitDeployment(ctx context.Context, projectID, deploymentID string, opts WaitOptions) (*Deployment, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	for {
		d, err := c.GetDeployment(ctx, projectID, deploymentID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: "deployment " + deploymentID, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		status := d.Status
		if d.KnativeMessage != "" && status != DeploymentActive {
			status += ": " + d.KnativeMessage
		}
		if status != last {
			last = status
			if opts.Progress != nil {
				opts.Progress(status)
			}
		}
		switch d.Status {
		case DeploymentActive:
			return d, nil
		case DeploymentFailed, DeploymentShutDown, DeploymentShuttingDown:
			return d, &DeploymentFailedError{Deployment: d}
		}
		if d.Rollout != nil && d.Rollout.Status != RolloutPending {
			if d.Rollout.Ended() {
				return d, rolloutEndedError(d.Rollout)
			}
			return d, nil
		}
		select {
		case <-ctx.Done():
			return d, &TimeoutError{What: "deployment " + deploymentID, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}
