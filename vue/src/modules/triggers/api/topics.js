import client from '@/api/client'

const enc = encodeURIComponent

/**
 * The organization's fan-out topics (docs/triggers.md#topics). {topic} is the topic's id or name.
 * Reading: projects.view on any project; create / change / delete: topics.manage; publish:
 * events.send. Projects subscribe with a `topic` trigger.
 */
export const topicsApi = {
  list:    () => client.get('/topics'),
  get:     (topic) => client.get(`/topics/${enc(topic)}`),
  // {name, knative_region_id, description?}: 422 when the region has no Redis
  create:  (payload) => client.post('/topics', payload),
  update:  (topic, payload) => client.patch(`/topics/${enc(topic)}`, payload),
  // 409 {message, subscriptions} while triggers subscribe to it
  remove:  (topic) => client.delete(`/topics/${enc(topic)}`),
  // {data, content_type?, type?, subject?, id?} → 202 {data: {id, stream_id}}
  publish: (topic, payload) => client.post(`/topics/${enc(topic)}/publish`, payload),
}
