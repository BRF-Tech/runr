import client from '@/api/client'

const enc = encodeURIComponent

/** Durable workflows API (routes/workflows.php in the API). */
export const workflowsApi = {
  // Definitions + function settings of a project
  list:        (projectId) => client.get(`/projects/${projectId}/workflows`),
  show:        (projectId, name) => client.get(`/projects/${projectId}/workflows/${enc(name)}`),
  sync:        (projectId) => client.post(`/projects/${projectId}/workflows/sync`),
  declare:     (projectId, workflows) => client.put(`/projects/${projectId}/workflows`, { workflows }),
  setActive:   (projectId, name, isActive) => client.patch(`/projects/${projectId}/workflows/${enc(name)}`, { is_active: isActive }),
  app:         (projectId) => client.get(`/projects/${projectId}/workflows/app`),
  updateApp:   (projectId, endpointUrl) => client.patch(`/projects/${projectId}/workflows/app`, { endpoint_url: endpointUrl || null }),
  rotateKey:   (projectId) => client.post(`/projects/${projectId}/workflows/app/rotate-key`),
  signingKey:  (projectId) => client.get(`/projects/${projectId}/workflows/app/signing-key`),

  // Runs
  runs:        (projectId, params = {}) => client.get(`/projects/${projectId}/workflow-runs`, { params }),
  run:         (projectId, runId) => client.get(`/projects/${projectId}/workflow-runs/${runId}`),
  startRun:    (projectId, name, payload) => client.post(`/projects/${projectId}/workflows/${enc(name)}/runs`, payload),
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
