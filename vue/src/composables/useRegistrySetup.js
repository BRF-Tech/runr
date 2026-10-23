import { ref, computed } from 'vue'
import i18n from '@/i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useJobEvents } from '@/composables/useJobEvents'

export function useRegistrySetup() {
  // ── Registry config ───────────────────────────────────────────────────────
  const registryMode  = ref('internal')  // 'internal' | 'external'
  const storageType   = ref('pvc')       // 'pvc' | 's3'

  const pvcConfig = ref({ storageClass: '', size: '10Gi' })
  const s3Config  = ref({ endpoint: '', bucket: '', region: '', access_key: '', secret_key: '' })

  const externalConfig = ref({ address: '', username: '', password: '' })

  // Optional: reuse a Prometheus/Loki already running on the cluster (in-cluster Service URLs).
  // A URL makes that component operator-managed — RunR then installs no Prometheus/Loki of its own.
  const monitoringConfig = ref({ prometheusUrl: '', lokiUrl: '', lokiOrgId: '' })

  // ── Setup flow state ──────────────────────────────────────────────────────
  const setupToken   = ref(null)
  // Public id of the running setup: progress events carry it instead of the token
  const setupId      = ref(null)
  const setupStatus  = ref(null)    // 'pending' | 'testing' | 'installing' | 'ready' | 'error'
  const setupPhase   = ref(null)    // 'pvc_test' | 's3_test' | 'registry_test' | 'zot_install' | 'region_create'
  const setupMessage = ref('')
  const setupRegionId = ref(null)
  const setupError   = ref(null)
  const setupLoading = ref(false)

  // ── Computed ──────────────────────────────────────────────────────────────
  const canProceed = computed(() => setupStatus.value === 'ready')
  const isSettingUp = computed(() => ['pending', 'testing', 'installing'].includes(setupStatus.value))

  const hasValidationError = computed(() => {
    if (registryMode.value === 'internal') {
      if (storageType.value === 'pvc') {
        return !pvcConfig.value.storageClass || !pvcConfig.value.size
      }
      if (storageType.value === 's3') {
        const s = s3Config.value
        return !s.endpoint || !s.bucket || !s.region || !s.access_key || !s.secret_key
      }
    }
    if (registryMode.value === 'external') {
      return !externalConfig.value.address
    }
    return false
  })

  // ── Payload builder ───────────────────────────────────────────────────────
  function getRegistryPayload() {
    const base = { registry_mode: registryMode.value }

    if (registryMode.value === 'internal') {
      base.storage_type = storageType.value
      if (storageType.value === 'pvc') base.pvc_config = { ...pvcConfig.value }
      if (storageType.value === 's3') base.s3_config = { ...s3Config.value }
    }

    if (registryMode.value === 'external') {
      base.external_address  = externalConfig.value.address
      base.external_username = externalConfig.value.username || null
      base.external_password = externalConfig.value.password || null
    }

    return base
  }

  function getMonitoringPayload() {
    const m = monitoringConfig.value
    const out = {}
    if (m.prometheusUrl.trim()) out.prometheus_url = m.prometheusUrl.trim()
    if (m.lokiUrl.trim()) out.loki_url = m.lokiUrl.trim()
    if (m.lokiUrl.trim() && m.lokiOrgId.trim()) out.loki_org_id = m.lokiOrgId.trim()
    return out
  }

  // ── Start setup ───────────────────────────────────────────────────────────
  async function startSetup(wizardPayload) {
    setupError.value   = null
    setupLoading.value = true
    setupStatus.value  = null

    const payload = { ...wizardPayload, ...getRegistryPayload(), ...getMonitoringPayload() }

    early.length = 0
    requesting = true
    try {
      const { data } = await knativeRegionsApi.registryStart(payload)
      requesting = false
      setupToken.value  = data.setup_token
      setupId.value     = data.setup_id ?? null
      setupStatus.value = 'pending'
      setupPhase.value  = 'queued'
      replayEarly()
    } catch (e) {
      requesting = false
      setupError.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? i18n.global.t('regionDetail.registry.setupFailed')
      setupLoading.value = false
    }
  }

  // ── Retry setup ───────────────────────────────────────────────────────────
  async function retrySetup(wizardPayload) {
    if (!setupToken.value) return
    setupError.value   = null
    setupLoading.value = true

    const payload = { ...wizardPayload, ...getRegistryPayload(), ...getMonitoringPayload() }

    early.length = 0
    requesting = true
    try {
      const { data } = await knativeRegionsApi.registryRetry(setupToken.value, payload)
      requesting = false
      if (data?.setup_id) setupId.value = data.setup_id
      setupStatus.value = 'pending'
      setupPhase.value  = 'queued'
      replayEarly()
    } catch (e) {
      requesting = false
      setupError.value = e.response?.data?.message ?? i18n.global.t('regionDetail.registry.retryFailed')
      setupLoading.value = false
    }
  }

  // ── WS event handlers ────────────────────────────────────────────────────
  // A setup's events are matched by its setup_id, which the start / retry request answers. A job
  // that fails fast can report before that answer: its events are kept until the id is known.
  const early = []
  let requesting = false

  const handlers = {
    'registry.progress': (payload) => {
      setupStatus.value  = payload.status
      setupPhase.value   = payload.phase
      setupMessage.value = payload.message || ''
    },
    'region.created': (payload) => {
      setupStatus.value   = 'ready'
      setupPhase.value    = 'done'
      setupRegionId.value = payload.region_id || null
      setupLoading.value  = false
    },
    'region.create_failed': (payload) => {
      setupStatus.value  = 'error'
      setupError.value   = payload.error || i18n.global.t('regionDetail.registry.setupFailed')
      setupLoading.value = false
    },
  }

  function onEvent(topic, payload) {
    if (!payload?.setup_id) return
    // The start / retry request runs: the id is not known yet (a retry keeps the previous one)
    if (requesting) {
      early.push([topic, payload])
      return
    }
    if (!setupId.value || payload.setup_id !== setupId.value) return
    handlers[topic](payload)
  }

  function replayEarly() {
    const events = early.splice(0)
    for (const [topic, payload] of events) {
      if (payload.setup_id === setupId.value) handlers[topic](payload)
    }
  }

  useJobEvents(Object.fromEntries(Object.keys(handlers).map(topic => [topic, (payload) => onEvent(topic, payload)])))

  // ── Cleanup ───────────────────────────────────────────────────────────────
  function reset() {
    setupToken.value    = null
    setupId.value       = null
    setupStatus.value   = null
    setupPhase.value    = null
    setupMessage.value  = ''
    setupRegionId.value = null
    setupError.value    = null
    setupLoading.value  = false
  }

  return {
    // config
    registryMode, storageType, pvcConfig, s3Config, externalConfig, monitoringConfig,
    // setup state
    setupToken, setupId, setupStatus, setupPhase, setupMessage, setupRegionId,
    setupError, setupLoading,
    // computed
    canProceed, isSettingUp, hasValidationError,
    // functions
    getRegistryPayload, getMonitoringPayload, startSetup, retrySetup, reset,
  }
}
