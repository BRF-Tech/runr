package runrtest

import (
	"fmt"

	"github.com/runrsh/core/cli/pkg/runr"
)

// The web shell of a deployment (ExecController::start) of the fake: a one-time session of the
// shell WebSocket the sandbox terminal uses too (sandbox_ws.go); its log names the deployment as
// "deployment:<id>".

func init() {
	handle("POST /projects/{project}/deployments/{deployment}/exec", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		d := s.deploymentByID(p, q.Segs[3])
		if d == nil {
			return 0, nil, errNotFound()
		}
		if d.data.Status != runr.DeploymentActive && d.data.Status != "running" {
			return 0, nil, &apiErr{status: 422, message: "The shell needs an active deployment."}
		}
		ex := s.sbxExtra()
		ex.seq++
		id := fmt.Sprintf("6d1e0b20-0000-4000-8000-%012d", ex.seq)
		cols, rows := 80, 24
		if v, ok := q.Body["cols"].(float64); ok && v >= 1 {
			cols = int(v)
		}
		if v, ok := q.Body["rows"].(float64); ok && v >= 1 {
			rows = int(v)
		}
		ex.sessions[id] = &sbxTerminalSession{sandboxID: "deployment:" + d.data.ID, cols: cols, rows: rows}
		return 200, map[string]any{"session_id": id}, nil
	})
}
