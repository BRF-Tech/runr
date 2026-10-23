import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { aiApi } from '@/api/ai'
import { createChangeJournal, removeChange } from '@/utils/changeJournal'

/** Has `answer` (a list item read from the API) a newer state than `local` (an action's answer)? */
function newer(answer, local) {
  if (!answer?.updated_at || !local?.updated_at) return false
  return new Date(answer.updated_at) > new Date(local.updated_at)
}

/** Change: inserts `item`, or merges it into the item with its id (unless that one is newer). */
const upsertChange = (item) => (list) => {
  const i = list.findIndex(x => x.id === item.id)
  if (i === -1) return [item, ...list]
  if (newer(list[i], item)) return list
  const next = [...list]
  next[i] = { ...list[i], ...item }
  return next
}

/** Change: puts `snapshot` on the model `modelId` (the model as the list has it now). */
const snapshotChange = (modelId, snapshot) => (list) => list.map(m => (m.id === modelId
  ? { ...m, snapshots: [...(m.snapshots ?? []).filter(s => s.id !== snapshot.id), snapshot] }
  : m))

const snapshotRemoval = (modelId, snapshotId) => (list) => list.map(m => (m.id === modelId
  ? { ...m, snapshots: (m.snapshots ?? []).filter(s => s.id !== snapshotId) }
  : m))

/**
 * Models page state: the engine catalog (GET /models/engines), the model library, the model
 * services and the user's model abilities (view / deploy / manage, answered with both lists).
 */
export const useAiStore = defineStore('ai', () => {
  const engines   = ref({})
  const tasks     = ref([])
  const models    = ref([])
  const services  = ref([])
  const abilities = ref({ view: true, deploy: false, manage: false, hub: true })
  const enginesLoaded  = ref(false)
  const modelsLoaded   = ref(false)
  const servicesLoaded = ref(false)

  // The models page polls both lists every few seconds while something is busy. A change made
  // here (a deploy, a stop, a new offline copy: the action's answer) while such a request is in
  // flight is applied to its answer, which may have been read before it; of two requests the
  // newer one wins.
  const journals = { models: createChangeJournal(), services: createChangeJournal() }
  let modelsRequest = 0
  let servicesRequest = 0

  function changeModels(apply) {
    models.value = apply(models.value)
    journals.models.record(apply)
  }

  function changeServices(apply) {
    services.value = apply(services.value)
    journals.services.record(apply)
  }

  function takeAbilities(data) {
    if (data?.abilities) abilities.value = { view: true, deploy: false, manage: false, hub: true, ...data.abilities }
  }

  async function fetchEngines({ force = false } = {}) {
    if (enginesLoaded.value && !force) return
    try {
      const { data } = await aiApi.engines()
      engines.value = data?.engines ?? {}
      tasks.value   = Array.isArray(data?.tasks) ? data.tasks : []
    } finally {
      enginesLoaded.value = true
    }
  }

  async function fetchModels() {
    const request = ++modelsRequest
    const since = journals.models.mark()
    const { data } = await aiApi.models()
    if (request !== modelsRequest) return
    models.value = journals.models.replay(data?.data ?? [], since)
    takeAbilities(data)
    modelsLoaded.value = true
  }

  async function fetchServices() {
    const request = ++servicesRequest
    const since = journals.services.mark()
    const { data } = await aiApi.services()
    if (request !== servicesRequest) return
    services.value = journals.services.replay(data?.data ?? [], since)
    takeAbilities(data)
    servicesLoaded.value = true
  }

  function upsertService(service) {
    if (!service?.id) return
    changeServices(upsertChange(service))
  }

  function removeService(id) {
    changeServices(removeChange(id))
  }

  function upsertModel(model) {
    if (!model?.id) return
    changeModels(upsertChange(model))
  }

  function removeModel(id) {
    changeModels(removeChange(id))
  }

  /** An offline copy of a model created or changed: set on the model as the list has it now. */
  function upsertSnapshot(modelId, snapshot) {
    if (!modelId || !snapshot?.id) return
    changeModels(snapshotChange(modelId, snapshot))
  }

  function removeSnapshot(modelId, snapshotId) {
    changeModels(snapshotRemoval(modelId, snapshotId))
  }

  /** Engine ids that serve a task (catalog), `custom` always last. */
  function enginesForTask(task) {
    const entry = tasks.value.find(t => t.task === task)
    const list  = entry?.engines ?? Object.entries(engines.value)
      .filter(([, e]) => (e.tasks ?? []).includes(task) || (e.tasks ?? []).includes('*'))
      .map(([id]) => id)
    return [...new Set([...list.filter(e => e !== 'custom'), 'custom'])]
  }

  function engineDevices(engine) {
    return engines.value[engine]?.devices ?? ['cpu', 'gpu']
  }

  const canDeploy = computed(() => !!abilities.value.deploy)
  const canManage = computed(() => !!abilities.value.manage)
  /** The console can browse the Hugging Face Hub (false on air-gapped installs: uploads / imports only). */
  const hubAvailable = computed(() => abilities.value.hub !== false)

  return {
    engines, tasks, models, services, abilities,
    enginesLoaded, modelsLoaded, servicesLoaded,
    canDeploy, canManage, hubAvailable,
    fetchEngines, fetchModels, fetchServices,
    upsertService, removeService, upsertModel, removeModel, upsertSnapshot, removeSnapshot,
    enginesForTask, engineDevices,
  }
})
