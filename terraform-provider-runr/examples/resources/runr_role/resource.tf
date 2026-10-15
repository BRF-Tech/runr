# A custom role: deploy and read logs and metrics, nothing else.
resource "runr_role" "release_manager" {
  name        = "Release manager"
  description = "Deploys releases and watches them"
  permissions = ["projects.deploy", "logs.view", "metrics.view"]
}

# effective_permissions lists them with the ones they imply (projects.view).
output "release_manager_permissions" {
  value = runr_role.release_manager.effective_permissions
}
