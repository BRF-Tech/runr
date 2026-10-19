package runr

// Sandbox API (docs/sandbox-api.md; routes/sandboxes.php). The controllers build their answers
// by hand (SandboxResource resolved into arrays, service result arrays), so the fields are
// checked against these sources where the generator leaves a schema untyped. The streamed
// variants of exec and run (Server-Sent Events, sandbox_stream.go) use the same endpoints; the
// event fields are those of SandboxExecController and SseStream.

var sandboxFields = []string{
	"id", "status", "region.id", "region.name", "runtime", "runtime_version", "image",
	"sandbox_class", "isolation", "resources.cpu", "resources.cpu_m", "resources.memory_mb",
	"resources.disk_mb", "resources.pids_limit", "network.mode", "network.egress_allow",
	"read_only_root", "env_keys", "metadata", "ttl_seconds", "idle_timeout_seconds", "expires_at",
	"idle_expires_at", "last_activity_at", "created_at", "started_at", "paused_at", "ended_at",
	"end_reason", "from_pool", "start_ms", "error", "created_by", "tenant_id",
}

var sandboxSources = []string{
	"app/Http/Resources/SandboxResource.php",
	"app/Http/Controllers/Api/Sandboxes/SandboxesController.php",
}

var sandboxExecSources = []string{
	"app/Http/Controllers/Api/Sandboxes/SandboxExecController.php",
	"app/Services/Sandboxes/Exec/ExecResult.php",
	"app/Services/Sandboxes/CodeRunner.php",
}

var sandboxFileSources = []string{
	"app/Http/Controllers/Api/Sandboxes/SandboxFilesController.php",
	"app/Services/Sandboxes/SandboxFiles.php",
}

var sandboxExecResultFields = []string{
	"exit_code", "stdout", "stderr", "timed_out", "duration_ms", "truncated",
}

func init() {
	Contract = append(Contract,
		// The API answers 201 running, 202 still starting (poll GET) or 502 could not start; the
		// document types the success answer as 200 only, so the entry checks that one.
		Endpoint{Method: "POST", Path: "/sandboxes", Status: 200, Permission: "sandboxes.create",
			Request: []string{"runtime", "version", "image", "cpu", "memory", "disk", "ttl_seconds", "idle_timeout",
				"network", "env", "files[].path", "files[].content_base64", "files[].mode", "region", "sandbox_class",
				"read_only_root", "pids_limit", "metadata", "wait", "start_timeout"},
			FreeForm: []string{"network", "env", "metadata"},
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},
		Endpoint{Method: "GET", Path: "/sandboxes", Status: 200, Permission: "sandboxes.view",
			Query:    []string{"status", "region", "runtime", "page", "per_page"},
			Response: concat(prefix("data[].", sandboxFields), []string{"meta.total", "meta.page", "meta.per_page"}),
			Sources:  sandboxSources},
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}", Status: 200, Permission: "sandboxes.view",
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},
		Endpoint{Method: "DELETE", Path: "/sandboxes/{sandbox}", Status: 200, Permission: "sandboxes.create",
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},
		// pod=1 adds the pod of a running sandbox (phase, conditions, events, log).
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/logs", Status: 200, Permission: "sandboxes.view",
			Query: []string{"pod"},
			Response: concat(prefix("data.events[].", []string{"id", "type", "message", "data", "user_id", "created_at"}),
				prefix("data.pod.", []string{"name", "namespace", "node", "phase", "conditions[].type", "conditions[].status",
					"conditions[].reason", "conditions[].message", "events[].type", "events[].reason", "events[].message",
					"events[].at", "log"})),
			Sources: sandboxSources},

		// JSON result, or Server-Sent Events with stream / Accept: text/event-stream.
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/exec", Status: 200, Permission: "sandboxes.exec",
			Request:      []string{"cmd", "stdin_base64", "timeout", "env", "cwd", "stream"},
			FreeForm:     []string{"env"},
			Response:     prefix("data.", sandboxExecResultFields),
			Capabilities: []string{"data.stdout_base64", "data.stderr_base64"},
			Sources:      sandboxExecSources},
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/run", Status: 200, Permission: "sandboxes.exec",
			Request:  []string{"code", "language", "timeout", "env", "cwd", "stream"},
			FreeForm: []string{"env"},
			Response: prefix("data.", concat([]string{"language"}, sandboxExecResultFields, []string{
				"files_changed[].path", "files_changed[].size", "outputs[].path", "outputs[].mime",
				"outputs[].size", "outputs[].data_base64"})),
			Sources: sandboxExecSources},

		// Files
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/files", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"path"},
			Response: concat(prefix("data.", []string{"path", "is_dir", "truncated"}),
				prefix("data.entries[].", []string{"name", "path", "type", "size", "mode", "modified_at"})),
			Sources: sandboxFileSources},
		// The file's bytes (application/octet-stream).
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/files/content", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"path"}, Sources: sandboxFileSources},
		Endpoint{Method: "PUT", Path: "/sandboxes/{sandbox}/files/content", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"path", "mode"}, RawBody: true,
			Response: []string{"data.path", "data.size"}, Sources: sandboxFileSources},
		// A tar of the directory's contents (application/x-tar).
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/files/archive", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"path"}, Sources: sandboxFileSources},
		Endpoint{Method: "PUT", Path: "/sandboxes/{sandbox}/files/archive", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"path"}, RawBody: true,
			Response: []string{"data.path", "data.bytes"}, Sources: sandboxFileSources},
		Endpoint{Method: "DELETE", Path: "/sandboxes/{sandbox}/files", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"path", "recursive"}, Response: []string{"data.path", "data.deleted"}, Sources: sandboxFileSources},
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/files/mkdir", Status: 200, Permission: "sandboxes.exec",
			Request: []string{"path"}, Response: []string{"data.path"}, Sources: sandboxFileSources},
	)
}

// Background processes, lifetime, terminal, warm pools, quotas, usage and metrics.

var sandboxProcessFields = []string{
	"id", "sandbox_id", "pid", "command", "cwd", "status", "exit_code", "started_at", "ended_at",
}

var sandboxProcessSources = []string{
	"app/Http/Controllers/Api/Sandboxes/SandboxProcessesController.php",
	"app/Services/Sandboxes/SandboxProcesses.php",
}

var sandboxPoolFields = []string{
	"id", "region.id", "region.name", "runtime", "runtime_version", "sandbox_class", "cpu_m", "memory_mb",
	"disk_mb", "size", "is_enabled", "warm", "starting", "hits", "misses", "last_error", "tenant_id", "created_at",
}

var sandboxAdminSources = []string{
	"app/Http/Controllers/Api/Sandboxes/SandboxAdminController.php",
	"app/Services/Sandboxes/SandboxPoolService.php",
	"app/Services/Sandboxes/SandboxQuotaService.php",
	"app/Models/SandboxQuota.php",
}

var sandboxQuotaFields = concat(
	prefix("limits.", []string{"max_concurrent", "max_cpu_m", "max_memory_mb", "max_sandbox_seconds_month",
		"max_pool_size", "allowed_classes"}),
	prefix("usage.", []string{"concurrent", "cpu_m", "memory_mb", "sandbox_seconds_month", "pool_size"}),
)

var sandboxRegionFields = []string{
	"region.id", "region.name", "enabled", "installed_classes", "allowed_classes", "effective_classes",
	"default_class", "effective_default_class", "allow_runc", "default_ttl_seconds", "max_ttl_seconds",
	"max_cpu_m", "max_memory_mb", "max_disk_mb", "can_manage",
}

var sandboxRegionSources = []string{
	"app/Http/Controllers/Api/Sandboxes/SandboxAdminController.php",
	"app/Services/Sandboxes/SandboxRegions.php",
}

var sandboxQuotaRequest = []string{"max_concurrent", "max_cpu_m", "max_memory_mb", "max_sandbox_seconds_month",
	"max_pool_size", "allowed_classes"}

func init() {
	Contract = append(Contract,
		// Background processes
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/processes", Status: 200, Permission: "sandboxes.exec",
			Response: prefix("data[].", sandboxProcessFields), Sources: sandboxProcessSources},
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/processes", Status: 201, Permission: "sandboxes.exec",
			Request: []string{"cmd", "env", "cwd"}, FreeForm: []string{"env"},
			Response: prefix("data.", sandboxProcessFields), Sources: sandboxProcessSources},
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/processes/{process}", Status: 200, Permission: "sandboxes.exec",
			Response: prefix("data.", sandboxProcessFields), Sources: sandboxProcessSources},
		// JSON tail, or Server-Sent Events with follow=1 (start, stdout, stderr, exit {exit_code, status}).
		Endpoint{Method: "GET", Path: "/sandboxes/{sandbox}/processes/{process}/logs", Status: 200, Permission: "sandboxes.exec",
			Query:        []string{"tail_bytes", "follow"},
			Response:     []string{"data.stdout", "data.stderr", "data.truncated"},
			Capabilities: []string{"data.stdout_base64", "data.stderr_base64"},
			Sources:      concat(sandboxProcessSources, []string{"app/Services/Sandboxes/Exec/ExecResult.php"})},
		Endpoint{Method: "DELETE", Path: "/sandboxes/{sandbox}/processes/{process}", Status: 200, Permission: "sandboxes.exec",
			Query: []string{"signal"}, Response: prefix("data.", sandboxProcessFields), Sources: sandboxProcessSources},

		// Lifetime
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/pause", Status: 200, Permission: "sandboxes.exec",
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/resume", Status: 200, Permission: "sandboxes.exec",
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/timeout", Status: 200, Permission: "sandboxes.create",
			Request: []string{"ttl_seconds"}, Response: prefix("data.", sandboxFields), Sources: sandboxSources},
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/keepalive", Status: 200, Permission: "sandboxes.exec",
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},

		// The network of a running sandbox: "none" or {"egress_allow": [...]}.
		Endpoint{Method: "PUT", Path: "/sandboxes/{sandbox}/network", Status: 200, Permission: "sandboxes.create",
			Request: []string{"network"}, FreeForm: []string{"network"},
			Response: prefix("data.", sandboxFields), Sources: sandboxSources},

		// The exec WebSocket: a one-time token for /ws/sandbox-exec?token=… (outside /api, served
		// by SandboxExecSocket; sandbox_exec_ws.go). tty, cols and rows: a terminal for the command.
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/exec/ws", Status: 200, Permission: "sandboxes.exec",
			Request: []string{"cmd", "stdin_base64", "timeout", "env", "cwd", "tty", "cols", "rows"}, FreeForm: []string{"env"},
			Response: []string{"data.token", "data.url", "data.exec_id", "data.expires_in"}, Sources: sandboxExecSources,
			Capabilities: []string{"data.tty"}},

		// The terminal: a one-time session of the web shell's WebSocket (/ws/shell?token=…,
		// outside /api; sandbox_terminal.go).
		Endpoint{Method: "POST", Path: "/sandboxes/{sandbox}/terminal", Status: 200, Permission: "sandboxes.exec",
			Request:  []string{"cols", "rows"},
			Response: []string{"data.session_id", "data.url", "session_id"}, Sources: sandboxExecSources},

		// Sandbox settings of the regions
		Endpoint{Method: "GET", Path: "/sandbox-regions", Status: 200, Permission: "sandboxes.view",
			Response: prefix("data[].", sandboxRegionFields), Sources: sandboxRegionSources},
		Endpoint{Method: "PUT", Path: "/sandbox-regions/{knativeRegion}", Status: 200, Permission: "sandboxes.manage",
			Request: []string{"enabled", "allowed_classes", "default_class", "allow_runc", "default_ttl_seconds",
				"max_ttl_seconds", "max_cpu_m", "max_memory_mb", "max_disk_mb"},
			Response: prefix("data.", sandboxRegionFields), Sources: sandboxRegionSources},

		// Warm pools
		Endpoint{Method: "GET", Path: "/sandbox-pools", Status: 200, Permission: "sandboxes.view",
			Response: prefix("data[].", sandboxPoolFields), Sources: sandboxAdminSources},
		Endpoint{Method: "POST", Path: "/sandbox-pools", Status: 201, Permission: "sandboxes.manage",
			Request:  []string{"region", "runtime", "version", "size", "cpu", "memory", "disk", "sandbox_class"},
			Response: prefix("data.", sandboxPoolFields), Sources: sandboxAdminSources},
		Endpoint{Method: "PUT", Path: "/sandbox-pools/{pool}", Status: 200, Permission: "sandboxes.manage",
			Request: []string{"size", "is_enabled"}, Response: prefix("data.", sandboxPoolFields), Sources: sandboxAdminSources},
		Endpoint{Method: "DELETE", Path: "/sandbox-pools/{pool}", Status: 204, Permission: "sandboxes.manage"},

		// Quotas: the current organization; every organization and the defaults row ("default")
		// for platform administrators (every user with sandboxes.manage on a single-tenant install).
		Endpoint{Method: "GET", Path: "/sandbox-quotas", Status: 200, Permission: "sandboxes.view",
			Response: concat([]string{"data.tenant_id"}, prefix("data.", sandboxQuotaFields)), Sources: sandboxAdminSources},
		Endpoint{Method: "GET", Path: "/sandbox-quotas/tenants", Status: 200, Permission: "sandboxes.manage",
			Response: concat(prefix("data[].", []string{"tenant.id", "tenant.name", "tenant.slug", "custom"}),
				prefix("data[].", sandboxQuotaFields)),
			Sources: sandboxAdminSources},
		Endpoint{Method: "PUT", Path: "/sandbox-quotas/{tenant}", Status: 200, Permission: "sandboxes.manage",
			Request:  sandboxQuotaRequest,
			Response: concat([]string{"data.tenant_id"}, prefix("data.", sandboxQuotaFields)), Sources: sandboxAdminSources},
		Endpoint{Method: "DELETE", Path: "/sandbox-quotas/{tenant}", Status: 204, Permission: "sandboxes.manage"},

		// Usage and metrics
		Endpoint{Method: "GET", Path: "/sandbox-usage", Status: 200, Permission: "sandboxes.view",
			Query: []string{"from", "to"},
			Response: prefix("data[].", []string{"record_date", "region_id", "sandbox_count", "sandbox_seconds", "cpu_mc_ms",
				"memory_mib_ms"}),
			Sources: sandboxAdminSources},
		Endpoint{Method: "GET", Path: "/sandbox-metrics", Status: 200, Permission: "sandboxes.view",
			Query: []string{"window", "region", "all_tenants"},
			Response: prefix("data.", []string{"window", "active", "warm", "pool.hits", "pool.misses", "pool.hit_rate",
				"starts.pool_hit", "starts.cold", "start_time.pool_hit.count", "start_time.pool_hit.sum_ms",
				"start_time.cold.count", "start_time.cold.sum_ms"}),
			Sources: []string{"app/Services/Sandboxes/SandboxMetrics.php"}},
		// The Prometheus text exposition of every sandbox (platform administrators).
		Endpoint{Method: "GET", Path: "/sandbox-metrics/prometheus", Status: 200, Permission: "sandboxes.manage"},
	)
}
