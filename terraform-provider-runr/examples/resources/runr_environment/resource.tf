# staging: pushes to develop deploy it, with its own overrides of the project's configuration.
resource "runr_environment" "staging" {
  project_id = runr_project.api.id
  name       = "staging"
  git_branch = "develop"

  # The complete set of overrides (what is left out follows the project's configuration)
  overrides = {
    env_vars        = { LOG_LEVEL = "debug" }
    remove_env_vars = ["ANALYTICS_KEY"] # the project's variable does not reach staging
    min_scale       = 0
    max_scale       = 2
  }

  # No containers warm in staging; the project's timed warmer stays production's
  scale_schedule = []

  # Canary deploys in staging, whatever the project's strategy
  deploy_strategy = {
    strategy = "canary"
    canary   = { steps = [50, 100], interval_minutes = 2 }
  }
}

# qa in another region, without the project's secret variables.
resource "runr_environment" "qa" {
  project_id      = runr_project.api.id
  name            = "qa"
  region_id       = data.runr_region.us.id
  inherit_secrets = false
  position        = 0 # first in the pipeline
}

# production is the project's own workload: adopted to manage its approval only.
resource "runr_environment" "production" {
  project_id        = runr_project.api.id
  name              = "production"
  requires_approval = true
}

output "staging_url" {
  value = runr_environment.staging.url
}
