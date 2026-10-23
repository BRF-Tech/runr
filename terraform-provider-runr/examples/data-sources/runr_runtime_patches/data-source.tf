data "runr_runtime_patches" "api" {
  project_id = runr_project.api.id
}

# Fail the plan while the project runs behind a critical patch past its deadline.
check "runtime_patch" {
  assert {
    condition     = data.runr_runtime_patches.api.state != "overdue"
    error_message = "orders-api runs behind ${data.runr_runtime_patches.api.latest.tag}: run `runr projects patch apply orders-api`."
  }
}
