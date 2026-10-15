package runrtest

import (
	"fmt"
	"path"
	"sort"
	"strings"
)

// File removal and directories (SandboxFilesController::destroy / mkdir, SandboxFiles::remove /
// mkdir), the pod of a sandbox (SandboxesController::logs with pod=1) and the Prometheus
// metrics (SandboxAdminController::prometheus) of the fake.

// sbxWritable reports whether p lies in a place the sandbox user may write.
func sbxWritable(p string) bool {
	for _, root := range []string{"/workspace", "/output", "/tmp", "/home/sandbox"} {
		if p == root || strings.HasPrefix(p, root+"/") {
			return true
		}
	}
	return false
}

// sbxAbsolute normalizes an absolute path like SandboxFiles::normalizePath (422 otherwise).
func sbxAbsolute(p string) (string, *apiErr) {
	if !strings.HasPrefix(p, "/") {
		return "", errValidation("path", fmt.Sprintf("The path %q is not an absolute path.", p))
	}
	return path.Clean(p), nil
}

// SandboxDirs returns the directories made with mkdir in a sandbox.
func (s *Server) SandboxDirs(id string) []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	sb := s.sandboxByID(id)
	if sb == nil {
		return nil
	}
	out := []string{}
	for d := range sb.dirs {
		out = append(out, d)
	}
	sort.Strings(out)
	return out
}

func init() {
	handle("DELETE /sandboxes/{sandbox}/files", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		p, err := sbxAbsolute(q.query("path"))
		if err != nil {
			return 0, nil, err
		}
		if p == "/" {
			return 0, nil, errValidation("path", "The root directory cannot be removed.")
		}
		recursive := q.query("recursive") == "1" || q.query("recursive") == "true"
		if _, isFile := sb.files[p]; isFile {
			delete(sb.files, p)
			delete(sb.modes, p)
		} else {
			entries := sb.children(p)
			if entries == nil || (!sb.dirs[p] && len(entries) == 0) {
				return 0, nil, &apiErr{status: 404, message: "no such file or directory: " + p}
			}
			if len(entries) > 0 && !recursive {
				return 0, nil, &apiErr{status: 422, message: "rmdir: '" + p + "': Directory not empty"}
			}
			for f := range sb.files {
				if strings.HasPrefix(f, p+"/") {
					delete(sb.files, f)
					delete(sb.modes, f)
				}
			}
			for d := range sb.dirs {
				if d == p || strings.HasPrefix(d, p+"/") {
					delete(sb.dirs, d)
				}
			}
		}
		sb.log("file.delete", p)
		return 200, map[string]any{"data": map[string]any{"path": p, "deleted": true}}, nil
	})

	handle("POST /sandboxes/{sandbox}/files/mkdir", func(s *Server, q *featureReq) (int, any, *apiErr) {
		sb, err := s.sbxRunning(q)
		if err != nil {
			return 0, nil, err
		}
		if q.str("path") == "" {
			return 0, nil, errValidation("path", "The path field is required.")
		}
		p, err := sbxAbsolute(q.str("path"))
		if err != nil {
			return 0, nil, err
		}
		if _, isFile := sb.files[p]; isFile {
			return 0, nil, &apiErr{status: 422, message: "mkdir: can't create directory '" + p + "': File exists"}
		}
		if !sbxWritable(p) {
			return 0, nil, &apiErr{status: 422, message: "mkdir: can't create directory '" + p + "': Permission denied"}
		}
		for d := p; d != "/" && sbxWritable(d); d = path.Dir(d) {
			sb.dirs[d] = true
		}
		sb.log("file.upload", p)
		return 200, map[string]any{"data": map[string]any{"path": p}}, nil
	})

	handle("GET /sandbox-metrics/prometheus", func(s *Server, q *featureReq) (int, any, *apiErr) {
		active, warm := 0, 0
		for _, sb := range s.sbx().list {
			if !sb.data.Ended() {
				active++
			}
		}
		for _, p := range s.sbxExtra().pools {
			warm += p.Warm
		}
		text := "# HELP runr_sandboxes_active Sandboxes that are pending, running or paused.\n" +
			"# TYPE runr_sandboxes_active gauge\n" +
			fmt.Sprintf("runr_sandboxes_active %d\n", active) +
			"# HELP runr_sandbox_pool_warm Warm sandboxes of the pools.\n" +
			"# TYPE runr_sandbox_pool_warm gauge\n" +
			fmt.Sprintf("runr_sandbox_pool_warm %d\n", warm)
		return 200, rawAnswer{contentType: "text/plain; version=0.0.4; charset=utf-8", data: []byte(text)}, nil
	})
}

// sbxPod is the pod the fake shows for a running sandbox (pod=1).
func (sb *sbxSandbox) pod() map[string]any {
	return map[string]any{
		"name":      "sbx-" + strings.ToLower(sb.data.ID),
		"namespace": "runr-sandboxes",
		"node":      "worker-1",
		"phase":     "Running",
		"conditions": []map[string]any{
			{"type": "PodScheduled", "status": "True", "reason": nil, "message": nil},
			{"type": "Ready", "status": "True", "reason": nil, "message": nil},
		},
		"events": []map[string]any{
			{"type": "Normal", "reason": "Scheduled", "message": "Successfully assigned the pod to worker-1", "at": sb.data.CreatedAt},
			{"type": "Normal", "reason": "Started", "message": "Started container sandbox", "at": sb.data.StartedAt},
		},
		"log": "runr sandbox ready\n",
	}
}
