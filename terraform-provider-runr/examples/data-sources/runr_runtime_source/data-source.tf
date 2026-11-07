# RunR's own catalog of built-in runtimes.
data "runr_runtime_source" "runr" {
  name = "RunR runtimes"
}

# A connected source and the runtimes it gives, with their lifecycle.
data "runr_runtime_source" "acme" {
  name = "acme-runtimes"
}

output "deprecated_runtimes" {
  value = [for rt in data.runr_runtime_source.acme.runtimes : "${rt.id} (sunset ${coalesce(rt.sunset_at, "-")}, use ${coalesce(rt.replacement, "-")})" if rt.state != "active"]
}

check "builtin_catalog" {
  assert {
    condition     = data.runr_runtime_source.runr.status == "ok"
    error_message = "RunR's catalog was not read: ${coalesce(data.runr_runtime_source.runr.error, data.runr_runtime_source.runr.status)}"
  }
}
