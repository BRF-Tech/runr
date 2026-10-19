import { ref, computed, watch, onUnmounted } from 'vue'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useJobEvents } from '@/composables/useJobEvents'

export function useInstallPopup(deps) {
  const {
    regionId,
    operatorStatus, operatorMessage,
    servingStatus, servingMessage,
    nsOperator, nsServing,
    servingVersion,
    redisStatus, redisAddress, redisMode, redisPassword,
    t, confirm,
  } = deps

  const installing = ref({})
  const compatibleVersions = ref([])
  const versionsLoading    = ref(false)
  const redisSaving = ref(false)
  const redisError  = ref(null)
  const installPopupOpen      = ref(false)
  const installPopupComponent = ref(null)
  const installPopupMode      = ref('install')
  const popupNamespace        = ref('')
  const popupNsCheckResult    = ref(null)
  const popupNsChecking       = ref(false)
  const popupVersion          = ref(null)
  const popupRedisMode        = ref(null)
  const popupRedisAddress     = ref('')
  const popupRedisPassword    = ref('')
  const popupRedisTestResult  = ref(null)
  const popupRedisTesting     = ref(false)
  const popupError            = ref(null)
  const popupSubmitting       = ref(false)
  const operatorReady  = computed(() => operatorStatus.value === 'ready')
  const servingReady   = computed(() => servingStatus.value === 'ready')
  const logCopied = ref({})

  // Fallback polling during install/uninstall
  let pollTimer = null

  function copyLogs(component, message) {
    if (!message) return
    navigator.clipboard.writeText(message).then(() => {
      logCopied.value = { ...logCopied.value, [component]: true }
      setTimeout(() => { logCopied.value = { ...logCopied.value, [component]: false } }, 2000)
    })
  }
  function capitalize(s) {
    if (!s) return ''
    return s.replace(/(^|_)(\w)/g, (_, _p, c) => c.toUpperCase())
  }
  function statusLabel(status) {
    return t(`settings.regionDetail.knative.status${capitalize(status)}`)
  }
  function redisStatusLabel(status) {
    if (!status) return t('settings.regionDetail.redis.statusNone')
    return t(`settings.regionDetail.redis.status${status.charAt(0).toUpperCase() + status.slice(1)}`)
  }

  // --- Fallback polling: poll component status every 5s during install ---
  const isActive = computed(() =>
    ['installing', 'uninstalling'].includes(operatorStatus.value) ||
    ['installing', 'uninstalling'].includes(servingStatus.value) ||
    ['installing', 'uninstalling'].includes(redisStatus.value)
  )

  function startPoll() {
    stopPoll()
    pollTimer = setInterval(async () => {
      if (!regionId.value) return
      try {
        const { data } = await knativeRegionsApi.componentStatus(regionId.value)
        if (data.operator) {
          operatorStatus.value = data.operator.status
          operatorMessage.value = data.operator.message || ''
        }
        if (data.serving) {
          servingStatus.value = data.serving.status
          servingMessage.value = data.serving.message || ''
        }
        if (data.redis !== undefined) {
          redisStatus.value = data.redis?.status ?? redisStatus.value
        }
      } catch { /* silent */ }
    }, 5000)
  }

  function stopPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
  }

  watch(isActive, (active) => {
    if (active) startPoll()
    else stopPoll()
  }, { immediate: true })

  // --- WS event handlers ---
  useJobEvents({
    'knative.*': (payload, topic) => {
      if (payload.region_id !== regionId.value) return
      const comp = topic.includes('operator') ? 'operator' : 'serving'
      if (topic.endsWith('_failed')) {
        ;({ operator: operatorStatus, serving: servingStatus })[comp].value = 'error'
        ;({ operator: operatorMessage, serving: servingMessage })[comp].value = payload.error || ''
      } else {
        knativeRegionsApi.componentStatus(regionId.value).then(({ data }) => {
          const c = data[comp]
          ;({ operator: operatorStatus, serving: servingStatus })[comp].value = c.status
          ;({ operator: operatorMessage, serving: servingMessage })[comp].value = c.message || ''
        }).catch(() => {})
      }
      installing.value = { ...installing.value, [comp]: false }
    },
    'redis.*': (payload) => {
      if (payload.region_id !== regionId.value) return
      knativeRegionsApi.redisStatus(regionId.value).then(({ data }) => {
        redisStatus.value = data.knative_redis_status
        redisAddress.value = data.redis_address || ''
        if (!data.knative_redis_status) redisMode.value = data.redis_mode || null
      }).catch(() => {})
    },
    'install.progress': (payload) => {
      if (payload.region_id !== regionId.value) return
      if (payload.component === 'operator') operatorMessage.value = payload.message
      if (payload.component === 'serving') servingMessage.value = payload.message
    },
  })
  async function confirmUninstallComponent(component) {
    const ok = await confirm({
      title:       t(`settings.regionDetail.${component}.uninstallBtn`),
      message:     t(`settings.regionDetail.${component}.uninstallConfirm`),
      destructive: true,
    })
    if (ok) uninstallComponent(component)
  }
  async function uninstallComponent(component) {
    if (!regionId.value) return
    const sRef = { operator: operatorStatus, serving: servingStatus }[component]
    const mRef = { operator: operatorMessage, serving: servingMessage }[component]
    sRef.value = 'uninstalling'
    mRef.value = ''
    try {
      const apiMethod = { operator: 'uninstallOperator', serving: 'uninstallServing' }[component]
      await knativeRegionsApi[apiMethod](regionId.value)
    } catch (e) {
      sRef.value = 'error'
      mRef.value = e.response?.data?.message ?? t('common.error')
    }
  }
  async function saveRedis() {
    const mode = popupRedisMode.value
    if (!regionId.value || !mode) return
    redisSaving.value = true
    redisError.value  = null
    try {
      const payload = {
        mode,
        address: mode !== 'managed' ? popupRedisAddress.value : undefined,
        password: popupRedisPassword.value || undefined,
      }
      const { data } = await knativeRegionsApi.configureRedis(regionId.value, payload)
      const regionData = data.region?.data ?? data.region
      if (regionData) {
        redisStatus.value  = regionData.knative_redis_status
        redisAddress.value = regionData.redis_address || ''
        redisMode.value    = mode
      }
    } catch (e) {
      redisError.value = e.response?.data?.message ?? t('common.error')
    } finally { redisSaving.value = false }
  }
  async function loadVersions() {
    if (!regionId.value) return
    versionsLoading.value = true
    try {
      const { data } = await knativeRegionsApi.compatibleVersions(regionId.value)
      compatibleVersions.value = data.versions || []
      if (installPopupMode.value === 'upgrade') {
        // Knative upgrades one minor version at a time: only the next one the cluster supports
        const next = nextMinor(servingVersion.value)
        compatibleVersions.value = next && compatibleVersions.value.includes(next) ? [next] : []
        popupVersion.value = compatibleVersions.value[0] ?? null
      } else if (compatibleVersions.value.length && !popupVersion.value) {
        popupVersion.value = compatibleVersions.value[0]
      }
    } catch { compatibleVersions.value = [] }
    finally { versionsLoading.value = false }
  }
  /** "1.20.3" → "1.21" */
  function nextMinor(version) {
    const m = /^v?(\d+)\.(\d+)/.exec(version || '')
    return m ? `${m[1]}.${Number(m[2]) + 1}` : null
  }
  function openInstallPopup(component, mode = 'install') {
    installPopupComponent.value = component
    installPopupMode.value = mode
    if (component === 'operator') {
      popupNamespace.value = 'knative-operator'
    } else {
      popupNamespace.value = mode === 'upgrade' ? nsServing.value : 'knative-serving'
      popupVersion.value = mode === 'upgrade' ? servingVersion.value : null
      loadVersions()
    }
    popupNsCheckResult.value = mode === 'upgrade' ? 'available' : null
    popupNsChecking.value = false
    popupError.value = null
    popupSubmitting.value = false
    installPopupOpen.value = true
  }
  async function checkPopupNs() {
    popupNsChecking.value = true
    try {
      const { data } = await knativeRegionsApi.checkNamespace(regionId.value, { name: popupNamespace.value })
      popupNsCheckResult.value = data.exists ? 'exists' : 'available'
    } catch { popupNsCheckResult.value = null }
    finally { popupNsChecking.value = false }
  }
  async function confirmInstallPopup() {
    const component = installPopupComponent.value
    if (!regionId.value) return
    popupError.value = null
    popupSubmitting.value = true
    if (component === 'serving' && installPopupMode.value === 'upgrade') {
      try {
        if (!popupVersion.value) throw new Error(t('settings.regionDetail.installPopup.noUpgrade'))
        await knativeRegionsApi.upgradeServing(regionId.value, { version: popupVersion.value })
        servingStatus.value = 'installing'
        servingMessage.value = ''
        installing.value = { ...installing.value, serving: true }
        installPopupOpen.value = false
      } catch (e) {
        popupError.value = e.response?.data?.message ?? e.message ?? t('common.error')
      } finally { popupSubmitting.value = false }
      return
    }
    try {
      const payload = { namespace: popupNamespace.value }
      if (component === 'serving') {
        payload.version = popupVersion.value || undefined
      }
      const apiMethod = { operator: 'installOperator', serving: 'installServing' }[component]
      await knativeRegionsApi[apiMethod](regionId.value, payload)
      const nsRef = { operator: nsOperator, serving: nsServing }[component]
      const sRef = { operator: operatorStatus, serving: servingStatus }[component]
      const mRef = { operator: operatorMessage, serving: servingMessage }[component]
      nsRef.value = popupNamespace.value
      if (component === 'serving') {
        servingVersion.value = popupVersion.value
      }
      sRef.value = 'installing'
      mRef.value = ''
      installing.value = { ...installing.value, [component]: true }
      installPopupOpen.value = false
    } catch (e) {
      popupError.value = e.response?.data?.message ?? t('common.error')
    } finally { popupSubmitting.value = false }
  }
  async function testPopupRedis() {
    popupRedisTesting.value = true
    popupRedisTestResult.value = null
    try {
      const { data } = await knativeRegionsApi.testRedis(regionId.value, {
        address: popupRedisAddress.value,
        password: popupRedisPassword.value || undefined,
      })
      popupRedisTestResult.value = data.reachable ? 'ok' : 'fail'
    } catch { popupRedisTestResult.value = 'fail' }
    finally { popupRedisTesting.value = false }
  }
  function onPopupRedisModeChange(mode) {
    popupRedisMode.value = mode
    popupRedisTestResult.value = null
  }
  async function removeRedis() {
    const ok = await confirm({
      title:       t('settings.regionDetail.redis.removeRedis'),
      message:     t('settings.regionDetail.redis.removeConfirm'),
      destructive: true,
    })
    if (!ok || !regionId.value) return
    redisSaving.value = true
    redisError.value  = null
    try {
      await knativeRegionsApi.removeRedis(regionId.value)
      redisStatus.value = 'uninstalling'
    } catch (e) {
      redisError.value = e.response?.data?.message ?? t('common.error')
    } finally { redisSaving.value = false }
  }
  function initRedisPopup() {
    popupRedisMode.value     = redisMode.value || null
    popupRedisAddress.value  = redisAddress.value || ''
    popupRedisPassword.value = redisPassword.value || ''
    popupRedisTestResult.value = null
  }
  function cleanup() {
    stopPoll()
  }

  onUnmounted(cleanup)

  return {
    installing, compatibleVersions, versionsLoading,
    redisSaving, redisError,
    operatorReady, servingReady,
    logCopied, copyLogs, statusLabel, capitalize, redisStatusLabel,
    installPopupOpen, installPopupComponent, installPopupMode,
    popupNamespace, popupNsCheckResult, popupNsChecking,
    popupVersion, popupRedisMode, popupRedisAddress, popupRedisPassword,
    popupRedisTestResult, popupRedisTesting, popupError, popupSubmitting,
    confirmUninstallComponent,
    saveRedis, removeRedis, initRedisPopup, loadVersions,
    openInstallPopup, checkPopupNs, confirmInstallPopup,
    testPopupRedis, onPopupRedisModeChange,
    cleanup,
  }
}
