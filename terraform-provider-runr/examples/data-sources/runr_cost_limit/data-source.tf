data "runr_cost_limit" "api" {
  project_id = runr_project.api.id
}

output "api_month_so_far" {
  value = "${data.runr_cost_limit.api.month_to_date} of ${data.runr_cost_limit.api.amount} ${data.runr_cost_limit.api.currency} (${data.runr_cost_limit.api.state})"
}
