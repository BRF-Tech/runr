package runr

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
)

// The workflow app of a project (GET|PATCH /projects/{project}/workflows/app, rotate-key,
// signing-key; docs/workflows.md): where the engine calls the function and the key it signs
// those calls with. And workflow metering (GET /workflow-usage, /projects/{project}/workflow-usage).

// WorkflowApp returns the workflow app of a project: its endpoint, key fingerprint and sync state.
func (c *Client) WorkflowApp(ctx context.Context, projectID string) (*WorkflowApp, error) {
	var out struct {
		Data WorkflowApp `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: workflowsPath(projectID) + "/app"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// SetWorkflowEndpoint sets the URL the engine calls instead of the project's function (a
// function served outside RunR); "" goes back to the project's function URL.
func (c *Client) SetWorkflowEndpoint(ctx context.Context, projectID, endpoint string) (*WorkflowApp, error) {
	var value any
	if endpoint != "" {
		value = endpoint
	}
	var out struct {
		Data WorkflowApp `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodPatch, path: workflowsPath(projectID) + "/app",
		body: map[string]any{"endpoint_url": value}}, &out)
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// RotateWorkflowKey makes a new signing key; the previous one keeps signing for the grace
// period (WorkflowApp.PreviousKeyExpiresAt). The message names its end.
func (c *Client) RotateWorkflowKey(ctx context.Context, projectID string) (*WorkflowApp, string, error) {
	var out struct {
		Message string      `json:"message"`
		Data    WorkflowApp `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: workflowsPath(projectID) + "/app/rotate-key"}, &out); err != nil {
		return nil, "", err
	}
	return &out.Data, out.Message, nil
}

// WorkflowSigningKey is the key the engine signs its calls to the function with, for a
// function served outside RunR (it reads it from the environment variable Env).
type WorkflowSigningKey struct {
	SigningKey  string `json:"signing_key"`
	Env         string `json:"env"`
	Fingerprint string `json:"fingerprint"`
}

// WorkflowSigningKey returns the signing key of a project's workflow app (workflows.manage).
func (c *Client) WorkflowSigningKey(ctx context.Context, projectID string) (*WorkflowSigningKey, error) {
	var out WorkflowSigningKey
	if err := c.do(ctx, request{method: http.MethodGet, path: workflowsPath(projectID) + "/app/signing-key"}, &out); err != nil {
		return nil, err
	}
	if out.SigningKey == "" {
		return nil, fmt.Errorf("the API answered no signing key")
	}
	return &out, nil
}

// WorkflowUsage is the workflow metering of the organization or a project between two days:
// step executions and started runs, per day and per workflow.
type WorkflowUsage struct {
	From   string `json:"from"`
	To     string `json:"to"`
	Totals struct {
		StepExecutions int `json:"step_executions"`
		RunsStarted    int `json:"runs_started"`
	} `json:"totals"`
	Daily      []WorkflowUsageDay      `json:"daily"`
	ByWorkflow []WorkflowUsageWorkflow `json:"by_workflow"`
}

// WorkflowUsageDay is the usage of one day.
type WorkflowUsageDay struct {
	Date           string `json:"date"`
	StepExecutions int    `json:"step_executions"`
	RunsStarted    int    `json:"runs_started"`
}

// WorkflowUsageWorkflow is the usage of one workflow in the period.
type WorkflowUsageWorkflow struct {
	WorkflowID     string `json:"workflow_id"`
	Workflow       string `json:"workflow,omitempty"`
	ProjectID      string `json:"project_id"`
	StepExecutions int    `json:"step_executions"`
	RunsStarted    int    `json:"runs_started"`
}

// WorkflowUsage returns the workflow usage of a project (projectID) or of the organization
// (""), between from and to (YYYY-MM-DD; default the last 30 days).
func (c *Client) WorkflowUsage(ctx context.Context, projectID, from, to string) (*WorkflowUsage, error) {
	q := url.Values{}
	if from != "" {
		q.Set("from", from)
	}
	if to != "" {
		q.Set("to", to)
	}
	path := "/workflow-usage"
	if projectID != "" {
		path = "/projects/" + seg(projectID) + "/workflow-usage"
	}
	var out WorkflowUsage
	if err := c.do(ctx, request{method: http.MethodGet, path: path, query: q}, &out); err != nil {
		return nil, err
	}
	if out.Daily == nil {
		out.Daily = []WorkflowUsageDay{}
	}
	if out.ByWorkflow == nil {
		out.ByWorkflow = []WorkflowUsageWorkflow{}
	}
	return &out, nil
}
