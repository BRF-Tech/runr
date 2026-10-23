import client from '@/api/client'

const enc = encodeURIComponent

// The environment of a request (docs/workflows.md#environments): a name, null / undefined the
// project's own workload (production). Reads send it as ?environment=, changes in the body.
const envParams = (environment) => (environment ? { environment } : {})
const envBody = (body, environment) => (environment ? { ...body, environment } : body)

/** Durable workflows API (routes/workflows.php in the API). */
export const workflowsApi = {
  // Definitions + function settings of a project (or of one of its environments)
  list:        (projectId, environment = null) => client.get(`/projects/${projectId}/workflows`, { params: envParams(environment) }),
  show:        (projectId, name, environment = null) => client.get(`/projects/${projectId}/workflows/${enc(name)}`, { params: envParams(environment) }),
  sync:        (projectId, environment = null) => client.post(`/projects/${projectId}/workflows/sync`, envBody({}, environment)),
  declare:     (projectId, workflows, environment = null) => client.put(`/projects/${projectId}/workflows`, envBody({ workflows }, environment)),
  setActive:   (projectId, name, isActive, environment = null) => client.patch(`/projects/${projectId}/workflows/${enc(name)}`, envBody({ is_active: isActive }, environment)),
  app:         (projectId, environment = null) => client.get(`/projects/${projectId}/workflows/app`, { params: envParams(environment) }),
  updateApp:   (projectId, endpointUrl, environment = null) => client.patch(`/projects/${projectId}/workflows/app`, envBody({ endpoint_url: endpointUrl || null }, environment)),
  rotateKey:   (projectId, environment = null) => client.post(`/projects/${projectId}/workflows/app/rotate-key`, envBody({}, environment)),
  signingKey:  (projectId, environment = null) => client.get(`/projects/${projectId}/workflows/app/signing-key`, { params: envParams(environment) }),

  // Runs (a run is addressed by its id whatever its environment)
  runs:        (projectId, params = {}, environment = null) => client.get(`/projects/${projectId}/workflow-runs`, { params: { ...params, ...envParams(environment) } }),
  run:         (projectId, runId) => client.get(`/projects/${projectId}/workflow-runs/${runId}`),
  startRun:    (projectId, name, payload, environment = null) => client.post(`/projects/${projectId}/workflows/${enc(name)}/runs`, envBody(payload, environment)),
  cancelRun:   (projectId, runId) => client.post(`/projects/${projectId}/workflow-runs/${runId}/cancel`),
  retryRun:    (projectId, runId) => client.post(`/projects/${projectId}/workflow-runs/${runId}/retry`),
  replayRun:   (projectId, runId, fromStep = null) => client.post(`/projects/${projectId}/workflow-runs/${runId}/replay`, fromStep ? { from_step: fromStep } : {}),

  // Usage
  projectUsage: (projectId, params = {}) => client.get(`/projects/${projectId}/workflow-usage`, { params }),
  usage:        (params = {}) => client.get('/workflow-usage', { params }),

  // Events (tenant level)
  events:      (params = {}) => client.get('/events', { params }),
  event:       (id) => client.get(`/events/${id}`),
  sendEvent:   (event, { test = false } = {}) => client.post(test ? '/events?test=1' : '/events', event),
}
