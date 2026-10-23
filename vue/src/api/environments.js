import client from './client'

// Environments of a project (docs/environments.md): dev, staging, … next to the project's own
// workload ("production", the primary). {env} is an environment's name or id. Promotion moves the
// version an environment runs to the next one; into an environment that requires approval it
// waits for someone else with environments.approve (the approvals inbox).
const base = (projectId, env = null) =>
  env == null ? `/projects/${projectId}/environments` : `/projects/${projectId}/environments/${encodeURIComponent(env)}`

export const environmentsApi = {
  list:    (projectId)            => client.get(base(projectId)),
  get:     (projectId, env)       => client.get(base(projectId, env)),
  // { name, knative_region_id?, git_branch?, requires_approval?, auto_deploy?, inherit_secrets?, overrides? }
  create:  (projectId, data)      => client.post(base(projectId), data),
  update:  (projectId, env, data) => client.patch(base(projectId, env), data),
  destroy: (projectId, env)       => client.delete(base(projectId, env)),

  // Secret env vars of an environment (write-only; the list shows names and their scope)
  secrets:      (projectId, env)              => client.get(`${base(projectId, env)}/secrets`),
  setSecret:    (projectId, env, name, value) => client.put(`${base(projectId, env)}/secrets/${encodeURIComponent(name)}`, { value }),
  deleteSecret: (projectId, env, name)        => client.delete(`${base(projectId, env)}/secrets/${encodeURIComponent(name)}`),

  // Promotion: what will change (to: default the next environment), then the request
  promotePreview: (projectId, env, to = null) => client.get(`${base(projectId, env)}/promote`, { params: to ? { to } : {} }),
  promote:        (projectId, env, data = {}) => client.post(`${base(projectId, env)}/promote`, data),

  promotions: (projectId, params = {})           => client.get(`/projects/${projectId}/promotions`, { params }),
  approve:    (projectId, promotionId, comment)  => client.post(`/projects/${projectId}/promotions/${promotionId}/approve`, comment ? { comment } : {}),
  reject:     (projectId, promotionId, comment)  => client.post(`/projects/${projectId}/promotions/${promotionId}/reject`, comment ? { comment } : {}),
  cancel:     (projectId, promotionId)           => client.post(`/projects/${projectId}/promotions/${promotionId}/cancel`),

  // The approvals inbox: promotions of every project the user sees (pending_approval by default)
  inbox: (params = {}) => client.get('/environment-promotions', { params }),
}
