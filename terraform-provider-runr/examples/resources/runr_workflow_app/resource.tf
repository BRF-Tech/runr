# The workflows of the staging environment run in a function served outside RunR; it verifies
# the engine's calls with RUNR_WORKFLOW_SIGNING_KEY (`runr workflows app signing-key --env staging`).
resource "runr_workflow_app" "staging" {
  project_id   = runr_project.orders.id
  environment  = "staging"
  endpoint_url = "https://workflows-staging.example.com/runr"
}
