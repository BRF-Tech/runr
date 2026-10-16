package runr

// Observability (routes/observability.php, docs/observability.md). The controllers build their
// answers as arrays (toApi of the models, the trace normalizer), which the generator leaves
// untyped; the fields are checked against the files that build them.

var obsSettingsSources = []string{
	"app/Http/Controllers/Api/Observability/ProjectObservabilityController.php",
	"app/Models/Observability/ProjectObservabilitySetting.php",
}

var obsTraceSources = []string{
	"app/Http/Controllers/Api/Observability/TraceController.php",
	"app/Services/Observability/Tracing/TraceQueryService.php",
	"app/Services/Observability/Tracing/TraceNormalizer.php",
}

var obsReplaySources = []string{
	"app/Http/Controllers/Api/Observability/ReplayCaptureController.php",
	"app/Models/Observability/ReplayCapture.php",
	"app/Services/Observability/Replay/ReplayService.php",
	"app/Services/Observability/Replay/ResponseDiff.php",
}

var obsUptimeSources = []string{
	"app/Http/Controllers/Api/Observability/UptimeCheckController.php",
	"app/Models/Observability/UptimeCheck.php",
	"app/Models/Observability/UptimeResult.php",
	"app/Models/Observability/UptimeIncident.php",
	"app/Services/Observability/Uptime/UptimeService.php",
}

var obsStatusPageSources = []string{
	"app/Http/Controllers/Api/Observability/StatusPageController.php",
	"app/Models/Observability/StatusPage.php",
}

var obsChannelSources = []string{
	"app/Http/Controllers/Api/Observability/AlertChannelController.php",
	"app/Models/Observability/AlertChannel.php",
}

var obsRuleSources = []string{
	"app/Http/Controllers/Api/Observability/AlertRuleController.php",
	"app/Models/Observability/AlertRule.php",
	"app/Models/Observability/AlertEvent.php",
	"app/Models/Observability/AlertDelivery.php",
}

var obsSettingsFields = []string{
	"project_id", "tracing_enabled", "tracing_sample_ratio", "replay_enabled", "replay_max_body_bytes",
	"replay_retention_days", "replay_redact_headers", "replay_redact_query", "platform_redact_headers",
	"platform_redact_query", "region_tracing.mode", "region_tracing.status", "region_tracing.queryable",
	"region_tracing.accepts_spans", "replay_available", "replay_target",
}

var obsTraceSummaryFields = []string{
	"trace_id", "root_service", "root_name", "start", "start_ms", "duration_ms", "status_code", "path",
	"cold_start", "error", "matched", "spans[].span_id", "spans[].duration_ms", "spans[].attributes",
}

var obsSpanFields = []string{
	"trace_id", "span_id", "parent_span_id", "name", "kind", "service", "scope", "start_ns", "end_ns",
	"status.code", "status.message", "attributes", "events[].name", "events[].time_ns", "events[].offset_ms",
	"events[].attributes", "resource", "start_offset_ms", "duration_ms", "depth", "missing_parent",
}

var obsCaptureFields = []string{
	"id", "captured_at", "expires_at", "trigger", "method", "path", "query", "status", "timed_out",
	"duration_ms", "trace_id", "request_id", "revision", "body_size", "replay_count", "last_replayed_at",
	"last_replay_status",
}

var obsReplayResponseFields = []string{"status", "headers", "body", "body_base64", "truncated"}

var obsCheckFields = []string{
	"id", "project_id", "name", "url", "path", "method", "header_names", "interval_seconds", "timeout_seconds",
	"expected_status", "body_regex", "locations", "region_ids", "failure_threshold", "recovery_threshold",
	"is_enabled", "show_on_status_page", "status_page_name", "status_page_order", "status", "location_state",
	"last_checked_at", "next_check_at", "last_response_ms", "last_status_code", "last_error",
}

var obsCheckRequest = []string{
	"name", "url", "path", "method", "headers", "interval_seconds", "timeout_seconds", "expected_status",
	"body_regex", "locations", "region_ids", "failure_threshold", "recovery_threshold", "is_enabled",
	"show_on_status_page", "status_page_name", "status_page_order",
}

var obsIncidentFields = []string{
	"id", "status", "title", "message", "started_at", "resolved_at", "duration_seconds", "uptime_check_id",
	"project_id", "cause", "failed_checks",
}

var obsStatusPageFields = []string{
	"page.id", "page.slug", "page.title", "page.description", "page.logo_url", "page.custom_domain",
	"page.is_public", "page.show_incident_history", "page.history_days", "page.exists",
	"components[].id", "components[].name", "components[].project_id", "components[].project_name",
	"components[].show_on_status_page", "components[].status_page_name", "components[].status_page_order",
	"components[].status", "preview", "public_path",
}

var obsChannelFields = []string{
	"id", "name", "type", "config", "is_enabled", "last_test_at", "last_test_ok", "last_test_error", "created_at",
}

var obsRuleFields = []string{
	"id", "name", "type", "project_id", "region_id", "params", "severity", "cooldown_minutes", "channel_ids",
	"is_enabled", "state", "last_value", "last_evaluated_at", "last_fired_at",
}

var obsRuleRequest = []string{
	"name", "project_id", "region_id", "params", "severity", "cooldown_minutes", "channel_ids", "is_enabled",
}

var obsEventFields = []string{
	"id", "alert_rule_id", "type", "severity", "status", "title", "message", "data", "project_id", "fired_at",
	"resolved_at",
}

var obsDeliveryFields = []string{
	"id", "alert_event_id", "alert_channel_id", "channel_name", "channel_type", "kind", "status", "attempts",
	"response_code", "last_error", "next_attempt_at", "sent_at", "created_at", "event.title", "event.severity",
	"event.status",
}

var obsPageMeta = []string{"meta.current_page", "meta.last_page", "meta.total"}

func init() {
	Contract = append(Contract,
		// Project switches: tracing (applies with the next deployment) and request replay.
		Endpoint{Method: "GET", Path: "/projects/{project}/observability", Status: 200, Permission: "projects.view",
			Response: prefix("data.", obsSettingsFields), Sources: obsSettingsSources},
		Endpoint{Method: "PUT", Path: "/projects/{project}/observability/tracing", Status: 200, Permission: "projects.update",
			Request:  []string{"enabled", "sample_ratio"},
			Response: concat(prefix("data.", obsSettingsFields), []string{"requires_redeploy"}), Sources: obsSettingsSources},
		Endpoint{Method: "PUT", Path: "/projects/{project}/observability/replay", Status: 200, Permission: "replay.manage",
			Request:  []string{"enabled", "max_body_bytes", "retention_days", "redact_headers", "redact_query"},
			Response: concat(prefix("data.", obsSettingsFields), []string{"requires_redeploy"}), Sources: obsSettingsSources},

		// Traces, read from the region's Tempo (409 when the backend is not queryable).
		Endpoint{Method: "GET", Path: "/projects/{project}/traces", Status: 200, Permission: "traces.view",
			Query:    []string{"status", "min_duration_ms", "path", "from", "to", "limit"},
			Response: concat([]string{"data.traceql", "data.from", "data.to"}, prefix("data.traces[].", obsTraceSummaryFields)),
			Sources:  obsTraceSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/traces/{traceId}", Status: 200, Permission: "traces.view",
			Response: concat(prefix("data.", []string{"trace_id", "start", "start_ms", "end_ms", "duration_ms", "span_count",
				"error_count", "services"}), prefix("data.spans[].", obsSpanFields)),
			Sources: obsTraceSources},
		// The trace's log lines need both permissions.
		Endpoint{Method: "GET", Path: "/projects/{project}/traces/{traceId}/logs", Status: 200, Permission: "traces.view+logs.view",
			Response: prefix("data[].", []string{"ts_ms", "pod", "container", "line"}), Sources: obsTraceSources},

		// Captured failed requests and their replay.
		Endpoint{Method: "GET", Path: "/projects/{project}/replays", Status: 200, Permission: "replay.manage",
			Query:    []string{"status", "path", "page"},
			Response: concat(prefix("data[].", obsCaptureFields), obsPageMeta), Sources: obsReplaySources},
		Endpoint{Method: "GET", Path: "/projects/{project}/replays/{capture}", Status: 200, Permission: "replay.manage",
			Response: concat(prefix("data.", obsCaptureFields), prefix("data.", []string{"headers", "redacted_headers", "body",
				"body_base64", "body_truncated", "response_headers", "response_body", "response_body_base64",
				"response_truncated", "last_replay"})),
			Sources: obsReplaySources},
		Endpoint{Method: "POST", Path: "/projects/{project}/replays/{capture}/replay", Status: 200, Permission: "replay.manage",
			Request: []string{"headers", "query"}, FreeForm: []string{"headers"},
			Response: concat([]string{"data.capture.id", "data.capture.status"},
				prefix("data.original.", obsReplayResponseFields),
				prefix("data.replay.", concat(obsReplayResponseFields, []string{"error"})),
				prefix("data.diff.", []string{"status.before", "status.after", "status.changed", "headers.added",
					"headers.removed", "headers.changed", "body.changed", "body.truncated", "body.lines[].op",
					"body.lines[].text", "changed"})),
			// The document types the replay object from ReplayService's error branch; these keys
			// are added to every result (Replay/ReplayService.php).
			Capabilities: prefix("data.replay.", []string{"duration_ms", "replayed_at", "url", "version"}),
			Sources:      obsReplaySources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/replays/{capture}", Status: 204, Permission: "replay.manage"},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/replays", Status: 200, Permission: "replay.manage",
			Response: []string{"deleted"}, Sources: obsReplaySources},

		// Uptime checks and incidents.
		Endpoint{Method: "GET", Path: "/projects/{project}/uptime-checks", Status: 200, Permission: "metrics.view",
			Response: prefix("data[].", concat(obsCheckFields, []string{"uptime_24h", "uptime_7d", "uptime_30d", "avg_response_ms_24h"})),
			Sources:  obsUptimeSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/uptime-checks", Status: 201, Permission: "uptime.manage",
			Request: obsCheckRequest, FreeForm: []string{"headers"},
			Response: prefix("data.", obsCheckFields), Sources: obsUptimeSources},
		Endpoint{Method: "PUT", Path: "/projects/{project}/uptime-checks/{check}", Status: 200, Permission: "uptime.manage",
			Request: obsCheckRequest, FreeForm: []string{"headers"},
			Response: prefix("data.", obsCheckFields), Sources: obsUptimeSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/uptime-checks/{check}", Status: 204, Permission: "uptime.manage"},
		Endpoint{Method: "GET", Path: "/projects/{project}/uptime-checks/{check}/results", Status: 200, Permission: "metrics.view",
			Query: []string{"hours", "location"},
			Response: concat(prefix("data.check.", obsCheckFields),
				prefix("data.results[].", []string{"location", "ok", "status_code", "response_ms", "error", "checked_at"}),
				[]string{"data.locations", "data.uptime.24h", "data.uptime.7d", "data.uptime.30d", "data.avg_response_ms_24h",
					"data.days[].date", "data.days[].total", "data.days[].up", "data.days[].uptime"},
				prefix("data.incidents[].", obsIncidentFields)),
			Sources: obsUptimeSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/uptime-checks/{check}/run", Status: 200, Permission: "uptime.manage",
			Response: concat([]string{"data.ok", "data.status_code", "data.response_ms", "data.error"}, prefix("data.check.", obsCheckFields)),
			Sources:  obsUptimeSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/uptime-incidents", Status: 200, Permission: "metrics.view",
			Response: prefix("data[].", obsIncidentFields), Sources: obsUptimeSources},
		Endpoint{Method: "PATCH", Path: "/projects/{project}/uptime-incidents/{incident}", Status: 200, Permission: "uptime.manage",
			Request: []string{"title", "message"}, Response: prefix("data.", obsIncidentFields), Sources: obsUptimeSources},

		// Recorded metrics (HistoricalMetricsController). The document leaves out the summary's
		// period parameter; the MCP tool metrics_summary sends it as well.
		Endpoint{Method: "GET", Path: "/projects/{project}/metrics/summary", Query: []string{"period"}, Status: 200,
			Permission: "metrics.view",
			Response: concat(prefix("metrics.", []string{"period_days", "avg_cpu", "max_cpu", "avg_memory", "max_memory", "avg_rps",
				"max_rps", "total_cold_starts", "sample_count"}),
				prefix("sessions.", []string{"total_sessions", "total_duration_ms", "total_cpu_ms", "total_memory_mb_ms",
					"cold_starts", "avg_duration_ms"})),
			Sources: []string{"app/Http/Controllers/Api/HistoricalMetricsController.php",
				"app/Mcp/Tools/Catalog/Operations/MetricsSummary.php"}},
		// Live metrics of a deployment, read from the region's Prometheus (KnativeMetricsService:
		// the answer is built with compact(), which the generator leaves untyped).
		Endpoint{Method: "GET", Path: "/projects/{project}/deployments/{deployment}/metrics", Query: []string{"quick"}, Status: 200,
			Permission: "metrics.view",
			Response:   []string{"cpu", "memory", "rps", "cpuAvg1h", "memAvg1h"},
			Sources:    []string{"app/Services/KnativeMetricsService.php", "app/Http/Controllers/Api/DeploymentController.php"}},
		Endpoint{Method: "GET", Path: "/projects/{project}/metrics/history", Query: []string{"from", "to", "granularity"}, Status: 200,
			Permission: "metrics.view",
			Response: concat([]string{"granularity", "from", "to"}, prefix("data[].", []string{"recorded_at", "cpu_cores", "memory_mb",
				"rps", "concurrency", "pod_count", "cold_starts"})),
			Sources: []string{"app/Services/MetricsPersistenceService.php"}},

		// The organization's status page.
		Endpoint{Method: "GET", Path: "/observability/status-page", Status: 200, Permission: "status_page.manage",
			Response: prefix("data.", obsStatusPageFields), Sources: obsStatusPageSources},
		Endpoint{Method: "PUT", Path: "/observability/status-page", Status: 200, Permission: "status_page.manage",
			Request: []string{"slug", "title", "description", "logo_url", "custom_domain", "is_public", "show_incident_history",
				"history_days", "components[].id", "components[].show_on_status_page", "components[].status_page_name",
				"components[].status_page_order"},
			Response: prefix("data.", obsStatusPageFields), Sources: obsStatusPageSources},

		// Alert channels (secrets in config are write-only), rules, fired alerts, delivery log.
		Endpoint{Method: "GET", Path: "/observability/alert-channels", Status: 200, Permission: "alerts.manage",
			Response: prefix("data[].", obsChannelFields), Sources: obsChannelSources},
		Endpoint{Method: "POST", Path: "/observability/alert-channels", Status: 201, Permission: "alerts.manage",
			Request: []string{"name", "type", "config", "is_enabled"}, FreeForm: []string{"config"},
			Response: prefix("data.", obsChannelFields), Sources: obsChannelSources},
		Endpoint{Method: "PUT", Path: "/observability/alert-channels/{channel}", Status: 200, Permission: "alerts.manage",
			Request: []string{"name", "config", "is_enabled"}, FreeForm: []string{"config"},
			Response: prefix("data.", obsChannelFields), Sources: obsChannelSources},
		Endpoint{Method: "DELETE", Path: "/observability/alert-channels/{channel}", Status: 204, Permission: "alerts.manage"},
		Endpoint{Method: "POST", Path: "/observability/alert-channels/{channel}/test", Status: 200, Permission: "alerts.manage",
			Response: concat([]string{"data.ok", "data.response_code", "data.error"}, prefix("data.channel.", obsChannelFields)),
			Sources:  obsChannelSources},
		Endpoint{Method: "GET", Path: "/observability/alert-rules", Status: 200, Permission: "alerts.manage",
			Response: prefix("data[].", obsRuleFields), Sources: obsRuleSources},
		Endpoint{Method: "POST", Path: "/observability/alert-rules", Status: 201, Permission: "alerts.manage",
			Request: concat([]string{"type"}, obsRuleRequest), FreeForm: []string{"params"},
			Response: prefix("data.", obsRuleFields), Sources: obsRuleSources},
		Endpoint{Method: "PUT", Path: "/observability/alert-rules/{rule}", Status: 200, Permission: "alerts.manage",
			Request: obsRuleRequest, FreeForm: []string{"params"},
			Response: prefix("data.", obsRuleFields), Sources: obsRuleSources},
		Endpoint{Method: "DELETE", Path: "/observability/alert-rules/{rule}", Status: 204, Permission: "alerts.manage"},
		Endpoint{Method: "GET", Path: "/observability/alert-events", Status: 200, Permission: "alerts.manage",
			Query: []string{"status"}, Response: prefix("data[].", obsEventFields), Sources: obsRuleSources},
		Endpoint{Method: "GET", Path: "/observability/alert-deliveries", Status: 200, Permission: "alerts.manage",
			Query:    []string{"channel_id", "status"},
			Response: concat(prefix("data[].", obsDeliveryFields), obsPageMeta), Sources: obsRuleSources},
		Endpoint{Method: "POST", Path: "/observability/alert-deliveries/{delivery}/retry", Status: 202, Permission: "alerts.manage",
			Response: prefix("data.", obsDeliveryFields), Sources: obsRuleSources},
	)
}
