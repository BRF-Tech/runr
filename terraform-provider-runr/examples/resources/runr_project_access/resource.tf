# A role on one project only, for a user ...
resource "runr_project_access" "grace" {
  project_id = runr_project.orders.id
  user       = "grace@example.com" # user id, e-mail address or username
  role       = "developer"
}

# ... or for a group.
resource "runr_project_access" "auditors" {
  project_id = runr_project.orders.id
  group_id   = runr_group.auditors.id
  role       = "viewer"
}
