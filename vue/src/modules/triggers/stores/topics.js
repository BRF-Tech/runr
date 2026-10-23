import { defineStore } from 'pinia'
import { ref } from 'vue'
import { topicsApi } from '../api/topics'

/**
 * The organization's topics (GET /topics): the Topics settings tab and the topic select of a
 * project's `topic` trigger share one list. The API enforces the permissions.
 */
export const useTopicsStore = defineStore('triggerTopics', () => {
  const topics  = ref([])
  const loaded  = ref(false)
  const loading = ref(false)
  const error   = ref(null)

  let pending = null

  function sort() {
    topics.value = [...topics.value].sort((a, b) => a.name.localeCompare(b.name))
  }

  function upsert(topic) {
    if (!topic) return
    const i = topics.value.findIndex(x => x.id === topic.id)
    if (i === -1) topics.value = [...topics.value, topic]
    else topics.value = topics.value.map((x, j) => (j === i ? { ...x, ...topic } : x))
    sort()
  }

  /** Loads the list once (force: again); concurrent calls share the request. */
  async function load(force = false) {
    if (loaded.value && !force) return topics.value
    pending ??= (async () => {
      loading.value = true
      try {
        const { data } = await topicsApi.list()
        topics.value = data?.data ?? []
        loaded.value = true
        error.value  = null
      } catch (e) {
        error.value = e
        throw e
      } finally {
        loading.value = false
        pending = null
      }
    })()
    await pending
    return topics.value
  }

  /** Topics a project in the region can subscribe to. */
  function inRegion(regionId) {
    return topics.value.filter(x => x.knative_region_id === regionId)
  }

  /** {name, knative_region_id, description?} → the created topic. */
  async function create(payload) {
    const body = { name: payload.name.trim(), knative_region_id: payload.knative_region_id }
    if (payload.description?.trim()) body.description = payload.description.trim()
    const { data } = await topicsApi.create(body)
    upsert(data.data)
    return data.data
  }

  /** One topic with its subscriptions [{trigger_id, trigger, enabled, project_id, project_name, start_from}]. */
  async function show(topic) {
    const { data } = await topicsApi.get(topic)
    return data.data
  }

  async function updateDescription(topic, description) {
    const { data } = await topicsApi.update(topic.id ?? topic, { description: description?.trim() || null })
    upsert(data.data)
    return data.data
  }

  /**
   * Deletes a topic. A topic triggers still subscribe to is refused (409): answers
   * { ok: false, message, subscriptions } instead of throwing.
   */
  async function remove(topic) {
    try {
      await topicsApi.remove(topic.id ?? topic)
      topics.value = topics.value.filter(x => x.id !== (topic.id ?? topic) && x.name !== (topic.id ?? topic))
      return { ok: true }
    } catch (e) {
      if (e?.response?.status === 409) {
        return { ok: false, message: e.response.data?.message ?? '', subscriptions: e.response.data?.subscriptions ?? [] }
      }
      throw e
    }
  }

  /** Publishes one event to every subscriber → {id, stream_id}. */
  async function publish(topic, { data, type = '', subject = '', id = '', contentType = '' } = {}) {
    const body = { data }
    if (type.trim()) body.type = type.trim()
    if (subject.trim()) body.subject = subject.trim()
    if (id.trim()) body.id = id.trim()
    if (contentType.trim()) body.content_type = contentType.trim()
    const res = await topicsApi.publish(topic.name ?? topic, body)
    return res.data?.data ?? null
  }

  function reset() {
    topics.value = []
    loaded.value = false
    error.value  = null
  }

  return { topics, loaded, loading, error, load, inRegion, create, show, updateDescription, remove, publish, upsert, reset }
})
