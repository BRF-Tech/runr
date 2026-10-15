# Members get the group's organization-wide role and the project access granted to the group.
resource "runr_group" "platform" {
  name        = "platform"
  description = "Platform team"
  role        = "viewer"
  members     = ["grace@example.com", "alan"] # user ids, e-mail addresses or usernames
}

# Deploy rights on one project for the whole group.
resource "runr_project_access" "platform_orders" {
  project_id = runr_project.orders.id
  group_id   = runr_group.platform.id
  role       = runr_role.release_manager.key
}
