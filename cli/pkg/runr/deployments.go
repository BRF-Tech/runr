package runr

import (
	"context"
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
}

// ListDeployments returns a project's deployments, newest first.
func (c *Client) ListDeployments(ctx context.Context, projectID string) ([]Deployment, error) {
	var out struct {
		Data []Deployment `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/projects/" + projectID + "/deployments"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// Deploy starts a deployment of a version. The API answers 202 with a pending deployment.
func (c *Client) Deploy(ctx context.Context, projectID, versionID string, override *DeployConfig) (*Deployment, error) {
	body := struct {
		VersionID    string        `json:"version_id"`
		DeployConfig *DeployConfig `json:"deploy_config,omitempty"`
	}{VersionID: versionID, DeployConfig: override}
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodPost, path: "/projects/" + projectID + "/deploy", body: body}, &raw); err != nil {
		return nil, err
	}
	var d Deployment
	if err := decodeData(raw, &d); err != nil {
		return nil, fmt.Errorf("decode deployment: %w", err)
	}
	return &d, nil
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

// WaitDeployment polls a deployment until it is active (ready) or failed.
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
		select {
		case <-ctx.Done():
			return d, &TimeoutError{What: "deployment " + deploymentID, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}
