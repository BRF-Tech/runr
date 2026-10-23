import client from '@/api/client'

const enc = encodeURIComponent

/**
 * A project's triggers and the stored events of its webhook / platform_event triggers
 * (api/routes/triggers.php, docs/triggers.md). {trigger} is the trigger's id or name. Every change
 * answers `requires_deploy: true`: it reaches the cluster with the project's next deploy.
 */
export const triggersApi = {
  // Types with their metadata, the platform events and the webhook verification presets
  types:   () => client.get('/trigger-types'),

  list:    (projectId) => client.get(`/projects/${projectId}/triggers`),
  create:  (projectId, payload) => client.post(`/projects/${projectId}/triggers`, payload),
  replace: (projectId, triggers) => client.put(`/projects/${projectId}/triggers`, { triggers }),
  // {name?, enabled?, position?, config?, secrets?}: config replaced, secrets merged, position 0 = primary
  update:  (projectId, trigger, payload) => client.patch(`/projects/${projectId}/triggers/${enc(trigger)}`, payload),
  remove:  (projectId, trigger) => client.delete(`/projects/${projectId}/triggers/${enc(trigger)}`),

  // Inbox (replay.manage): stored events, one with its headers and body, replay
  events:       (projectId, trigger, params = {}) => client.get(`/projects/${projectId}/triggers/${enc(trigger)}/events`, { params }),
  event:        (projectId, trigger, eventId) => client.get(`/projects/${projectId}/triggers/${enc(trigger)}/events/${eventId}`),
  replayEvent:  (projectId, trigger, eventId) => client.post(`/projects/${projectId}/triggers/${enc(trigger)}/events/${eventId}/replay`),
  // {status: failed | delivered | all (default failed), ids?: [...]} → {replayed}
  replayEvents: (projectId, trigger, payload = { status: 'failed' }) => client.post(`/projects/${projectId}/triggers/${enc(trigger)}/events/replay`, payload),
}
