import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { aiApi } from '@/api/ai'

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
    const { data } = await aiApi.models()
    models.value = data?.data ?? []
    takeAbilities(data)
    modelsLoaded.value = true
  }

  async function fetchServices() {
    const { data } = await aiApi.services()
    services.value = data?.data ?? []
    takeAbilities(data)
    servicesLoaded.value = true
  }

  function upsertService(service) {
    if (!service?.id) return
    const i = services.value.findIndex(s => s.id === service.id)
    if (i === -1) services.value = [service, ...services.value]
    else services.value.splice(i, 1, { ...services.value[i], ...service })
  }

  function removeService(id) {
    services.value = services.value.filter(s => s.id !== id)
  }

  function upsertModel(model) {
    if (!model?.id) return
    const i = models.value.findIndex(m => m.id === model.id)
    if (i === -1) models.value = [model, ...models.value]
    else models.value.splice(i, 1, { ...models.value[i], ...model })
  }

  function removeModel(id) {
    models.value = models.value.filter(m => m.id !== id)
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
    upsertService, removeService, upsertModel, removeModel,
    enginesForTask, engineDevices,
  }
})
