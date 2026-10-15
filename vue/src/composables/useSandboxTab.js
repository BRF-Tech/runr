import { ref, computed, onUnmounted } from 'vue'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useJobEvents } from '@/composables/useJobEvents'

export function useSandboxTab(regionId, emit, t, confirm) {
  const kataStatus = ref('not_installed')
  const kataMessage = ref('')
  const kataHypervisor = ref(null)
  const gvisorStatus = ref('not_installed')
  const gvisorMessage = ref('')

  const mapping = ref({ xaas: 'runc', faas: 'runc', caas: 'runc', build: 'runc' })
  const mappingSaving = ref(false)
  const mappingError = ref(null)
  const mappingSuccess = ref(false)

  const installing = ref({})

  // Kata shim detection
  const shims = ref(null)
  const shimsLoading = ref(false)
  const kvm = ref(null)
  // No node can run VMs: Kata (any hypervisor) is not offered; runc and gVisor are unaffected
  const kataBlocked = computed(() => kvm.value?.state === 'no')
  const selectedShims = ref(['clh'])
  // Firecracker: size of the devmapper thin-pool RunR sets up (limits from the API)
  const devmapperLimits = ref({ default_pool_gb: 20, min_pool_gb: 10, max_pool_gb: 1024 })
  const devmapperPoolGb = ref(20)
  const fcSelected = computed(() => selectedShims.value.includes('fc'))

  function initFromRegion(region) {
    if (!region) return
    kataStatus.value   = region.sandbox_kata_status || 'not_installed'
    kataMessage.value  = region.sandbox_kata_status_message || ''
    kataHypervisor.value = region.sandbox_kata_hypervisor || null
    gvisorStatus.value = region.sandbox_gvisor_status || 'not_installed'
    gvisorMessage.value = region.sandbox_gvisor_status_message || ''
    const hvArr = region.sandbox_kata_hypervisor
      ? (Array.isArray(region.sandbox_kata_hypervisor) ? region.sandbox_kata_hypervisor : [region.sandbox_kata_hypervisor])
      : []
    const migrateLegacy = (v) => v === 'kata' && hvArr.length ? `kata-${hvArr[0]}` : v
    mapping.value = {
      xaas:  migrateLegacy(region.sandbox_xaas_runtime || 'runc'),
      faas:  migrateLegacy(region.sandbox_faas_runtime || 'runc'),
      caas:  migrateLegacy(region.sandbox_caas_runtime || 'runc'),
      build: migrateLegacy(region.sandbox_build_runtime || 'runc'),
    }
    if (region.sandbox_kata_hypervisor) {
      const hv = region.sandbox_kata_hypervisor
      selectedShims.value = Array.isArray(hv) ? hv : [hv]
    }
  }

  async function loadShims() {
    if (!regionId.value || shimsLoading.value) return
    shimsLoading.value = true
    try {
      const { data } = await knativeRegionsApi.kataShims(regionId.value)
      shims.value = data.shims
      // /dev/kvm on the Kata target nodes: yes | mixed | no | unknown (probing: a check is running)
      kvm.value = data.kvm || null
      if (data.devmapper) {
        devmapperLimits.value = data.devmapper
        devmapperPoolGb.value = data.devmapper.default_pool_gb
      }
    } catch { /* silent */ }
    finally { shimsLoading.value = false }
  }

  async function installRuntime(runtime) {
    if (runtime === 'kata') {
      // kata-deploy restarts the container runtime on every node — ask first
      const ok = await confirm({
        title: t('settings.regionDetail.sandbox.kata.installBtn'),
        message: [
          t('settings.regionDetail.sandbox.kata.installWarning'),
          fcSelected.value ? t('settings.regionDetail.sandbox.kata.fcHint') : '',
        ].filter(Boolean).join('\n\n'),
        confirmLabel: t('settings.regionDetail.sandbox.kata.installConfirm'),
      })
      if (!ok) return
    }

    installing.value = { ...installing.value, [runtime]: true }
    try {
      const payload = { runtime }
      if (runtime === 'kata') {
        payload.hypervisors = selectedShims.value
        if (fcSelected.value) payload.devmapper_pool_gb = Number(devmapperPoolGb.value)
      }
      const { data } = await knativeRegionsApi.installSandbox(regionId.value, payload)
      const region = data.region?.data ?? data.region
      if (region) {
        initFromRegion(region)
        emit('saved', region)
      }
    } catch (e) {
      const field = runtime === 'kata' ? kataMessage : gvisorMessage
      field.value = e.response?.data?.message || e.message
    } finally {
      installing.value = { ...installing.value, [runtime]: false }
    }
  }

  async function confirmUninstall(runtime) {
    const key = runtime === 'kata' ? 'kata' : 'gvisor'
    const ok = await confirm({
      title: t(`settings.regionDetail.sandbox.${key}.uninstallBtn`),
      message: t(`settings.regionDetail.sandbox.${key}.uninstallConfirm`),
      destructive: true,
    })
    if (!ok) return

    installing.value = { ...installing.value, [runtime]: true }
    try {
      const { data } = await knativeRegionsApi.uninstallSandbox(regionId.value, { runtime })
      const region = data.region?.data ?? data.region
      if (region) {
        initFromRegion(region)
        emit('saved', region)
      }
    } catch (e) {
      const field = runtime === 'kata' ? kataMessage : gvisorMessage
      field.value = e.response?.data?.message || e.message
    } finally {
      installing.value = { ...installing.value, [runtime]: false }
    }
  }

  const hypervisorLabels = { clh: 'Kata CLH', qemu: 'Kata QEMU', dragonball: 'Kata Dragonball', fc: 'Kata Firecracker' }

  const runtimeOptions = computed(() => {
    const opts = [{ value: 'runc', label: t('settings.regionDetail.sandbox.runtimeRunc') }]
    // Kata without /dev/kvm on any node: its pods would never start — not offered as a mapping
    if (kataStatus.value === 'ready' && !kataBlocked.value) {
      const hvs = Array.isArray(kataHypervisor.value) ? kataHypervisor.value : (kataHypervisor.value ? [kataHypervisor.value] : [])
      if (hvs.length) {
        hvs.forEach(hv => opts.push({ value: `kata-${hv}`, label: hypervisorLabels[hv] || `Kata ${hv}` }))
      } else {
        opts.push({ value: 'kata', label: t('settings.regionDetail.sandbox.runtimeKata') })
      }
    }
    if (gvisorStatus.value === 'ready') {
      opts.push({ value: 'gvisor', label: t('settings.regionDetail.sandbox.runtimeGvisor') })
    }
    return opts
  })

  async function saveMapping() {
    mappingSaving.value = true
    mappingError.value = null
    mappingSuccess.value = false
    try {
      const payload = {
        sandbox_xaas_runtime:  mapping.value.xaas,
        sandbox_faas_runtime:  mapping.value.faas,
        sandbox_caas_runtime:  mapping.value.caas,
        sandbox_build_runtime: mapping.value.build,
      }
      const { data } = await knativeRegionsApi.updateSandboxMapping(regionId.value, payload)
      const region = data.region?.data ?? data.region
      if (region) {
        initFromRegion(region)
        emit('saved', region)
      }
      mappingSuccess.value = true
      setTimeout(() => { mappingSuccess.value = false }, 3000)
    } catch (e) {
      mappingError.value = e.response?.data?.message || e.message
    } finally {
      mappingSaving.value = false
    }
  }

  function sandboxStatusLabel(status) {
    const map = {
      not_installed: t('settings.regionDetail.sandbox.statusNotInstalled'),
      installing:    t('settings.regionDetail.sandbox.statusInstalling'),
      uninstalling:  t('settings.regionDetail.sandbox.statusUninstalling'),
      ready:         t('settings.regionDetail.sandbox.statusReady'),
      error:         t('settings.regionDetail.sandbox.statusError'),
    }
    return map[status] || status
  }

  // --- WS event handlers ---
  useJobEvents({
    'sandbox.*': (payload, topic) => {
      if (payload.region_id !== regionId.value) return
      // the background /dev/kvm check finished: reload the hypervisor list
      if (topic === 'sandbox.kvm.probed') {
        loadShims()
        return
      }
      const runtime = topic.includes('kata') ? 'kata' : 'gvisor'
      const statusRef = runtime === 'kata' ? kataStatus : gvisorStatus
      const msgRef = runtime === 'kata' ? kataMessage : gvisorMessage
      if (topic.endsWith('_failed')) {
        statusRef.value = 'error'
        // The region keeps the step log with the error as its last line (loaded below)
        if (!msgRef.value) msgRef.value = payload.error || ''
      }
      knativeRegionsApi.sandboxStatus(regionId.value).then(({ data }) => {
        const st = data[runtime]
        statusRef.value = st.status
        msgRef.value = st.message || ''
        if (st.hypervisor) kataHypervisor.value = Array.isArray(st.hypervisor) ? st.hypervisor : [st.hypervisor]
        if (data.mapping) mapping.value = data.mapping
      }).catch(() => {})
      installing.value = { ...installing.value, [runtime]: false }
    },
    'install.progress': (payload) => {
      if (payload.region_id !== regionId.value) return
      if (payload.component === 'kata') kataMessage.value = payload.message
      if (payload.component === 'gvisor') gvisorMessage.value = payload.message
    },
  })

  function cleanup() {}

  onUnmounted(cleanup)

  return {
    kataStatus, kataMessage, kataHypervisor,
    gvisorStatus, gvisorMessage,
    mapping, mappingSaving, mappingError, mappingSuccess,
    installing, runtimeOptions,
    shims, shimsLoading, selectedShims, kvm, kataBlocked,
    fcSelected, devmapperLimits, devmapperPoolGb,
    initFromRegion, installRuntime, confirmUninstall,
    saveMapping, sandboxStatusLabel, loadShims, cleanup,
  }
}
