import client from './client'

// Rightsizing recommendations (docs/monitoring.md#rightsizing): sizes the projects' daily peaks
// call for. Reading needs metrics.view, applying and dismissing projects.update.
export const rightsizingApi = {
  // The organization's open recommendations, largest monthly saving first: { data, summary }
  list: () =>
    client.get('/rightsizing'),

  // One project's: { status, reason, data, collecting, pending_deploy }
  forProject: (projectId) =>
    client.get(`/projects/${projectId}/rightsizing`),

  // The recommended size into the config as a new version (the next deploy runs it)
  apply: (projectId) =>
    client.post(`/projects/${projectId}/rightsizing/apply`),

  // Hidden while the recommended size stays the same; snoozeDays: for that many days
  dismiss: (projectId, snoozeDays = null) =>
    client.post(`/projects/${projectId}/rightsizing/dismiss`, snoozeDays ? { snooze_days: snoozeDays } : {}),
}
