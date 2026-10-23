# 150.00 a month in the billing currency; the project is stopped when this month reaches it
# (the owner is warned at 80 %).
resource "runr_cost_limit" "api" {
  project_id = runr_project.api.id
  amount     = 150
  action     = "stop"
}
