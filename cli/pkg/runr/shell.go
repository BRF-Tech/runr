package runr

import (
	"context"
	"net/http"
	"net/url"
)

// OpenDeploymentShell starts a web shell session (docs/web-shell.md) in a pod of a project's
// deployment, which must be active: POST /projects/{project}/deployments/{deployment}/exec
// answers a one-time session id for /ws/shell?token=…, the WebSocket the sandbox terminal uses
// too (DialSandboxTerminal). The shell server finds the pod (waking a service scaled to zero)
// and keeps it alive while the session is open.
func (c *Client) OpenDeploymentShell(ctx context.Context, projectID, deploymentID string, cols, rows int) (*SandboxTerminalSession, error) {
	body := map[string]any{}
	if cols > 0 {
		body["cols"] = cols
	}
	if rows > 0 {
		body["rows"] = rows
	}
	var s SandboxTerminalSession
	err := c.do(ctx, request{method: http.MethodPost,
		path: "/projects/" + seg(projectID) + "/deployments/" + seg(deploymentID) + "/exec", body: body}, &s)
	if err != nil {
		return nil, err
	}
	if s.URL == "" && s.SessionID != "" {
		s.URL = "/ws/shell?token=" + url.QueryEscape(s.SessionID)
	}
	s.WebSocketURL = c.webSocketURL(s.URL)
	return &s, nil
}
