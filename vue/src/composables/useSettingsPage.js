import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi } from '@/api/settings'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useSettingsStore } from '@/stores/settings'
import { useRuntimesStore } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { moduleRegistry } from '@/modules/index'

export function useSettingsPage() {
  const { t }       = useI18n()
  const settings    = useSettingsStore()
  const runtimes    = useRuntimesStore()
  const { confirm } = useConfirm()

  // ── State ──
  const baseDomain           = ref('')
  const registrationEnabled  = ref(true)
  const gpuDemoEnabled       = ref(false)
  const customRuntimesEnabled = ref(false)
  const totalCpuM            = ref(0)
  const totalMemoryMb        = ref(0)
  const regions              = ref([])
  const demoStatus           = ref('idle')

  const loading = ref(false)
  const saving  = ref(false)
  const error   = ref(null)
  const saved   = ref(false)

  // ── Load ──
  onMounted(async () => {
    loading.value = true
    try {
      const { data } = await settingsApi.get()
      baseDomain.value          = data.base_domain          ?? ''
      registrationEnabled.value = data.registration_enabled ?? true
      gpuDemoEnabled.value      = data.gpu_demo_enabled     ?? false
      customRuntimesEnabled.value = data.custom_runtimes_enabled ?? false
      totalCpuM.value           = data.total_cpu_m          ?? 0
      totalMemoryMb.value       = data.total_memory_mb      ?? 0
      regions.value             = data.regions              ?? []
      demoStatus.value          = data.demo_status          ?? 'idle'
      settings.update(data)
    } finally {
      loading.value = false
    }
  })

  // ── Computed ──
  const managedRegions = computed(() => regions.value.filter(r => !r.unlimited && r.region_mode !== 'infinite'))
  const usedCpuM     = computed(() => managedRegions.value.reduce((s, r) => s + (r.used_cpu_m ?? 0), 0))
  const usedMemoryMb = computed(() => managedRegions.value.reduce((s, r) => s + (r.used_memory_mb ?? 0), 0))

  // ── Save ──
  async function submit() {
    error.value = null; saved.value = false; saving.value = true
    try {
      const payload = { base_domain: baseDomain.value.trim() || null }
      if (!moduleRegistry.hideRegistrationToggleFn?.()) {
        payload.registration_enabled = registrationEnabled.value
      }
      payload.gpu_demo_enabled = gpuDemoEnabled.value
      // Allowing custom runtimes is a runtime-management decision (runtimes.manage)
      if (runtimes.canManage) payload.custom_runtimes_enabled = customRuntimesEnabled.value
      const { data } = await settingsApi.update(payload)
      baseDomain.value          = data.base_domain          ?? ''
      registrationEnabled.value = data.registration_enabled ?? true
      gpuDemoEnabled.value      = data.gpu_demo_enabled     ?? false
      customRuntimesEnabled.value = data.custom_runtimes_enabled ?? false
      totalCpuM.value           = data.total_cpu_m          ?? 0
      totalMemoryMb.value       = data.total_memory_mb      ?? 0
      regions.value             = data.regions              ?? []
      syncStore()
      // runtimes.use_custom follows the setting: refresh the catalog abilities
      if (runtimes.canManage) runtimes.refresh()
      saved.value = true
      setTimeout(() => { saved.value = false }, 2000)
    } catch (e) {
      error.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
    } finally {
      saving.value = false
    }
  }

  // ── Region detail modal ──
  const regionDetailOpen   = ref(false)
  const regionDetailTarget = ref(null)

  function openAdd()  { regionDetailTarget.value = null; regionDetailOpen.value = true }
  function openEdit(r) { regionDetailTarget.value = r;    regionDetailOpen.value = true }
  function closeRegionDetail() {
    regionDetailOpen.value = false; regionDetailTarget.value = null; refreshRegions()
  }
  function onRegionSaved(regionData) {
    if (regionData) {
      const idx = regions.value.findIndex(r => r.id === regionData.id)
      if (idx !== -1) regions.value[idx] = regionData
      else regions.value.push(regionData)
      recomputeTotals(); syncStore()
    }
  }

  // ── Region CRUD ──
  async function deleteRegion(r) {
    const ok = await confirm({
      title: t('project.delete'),
      message: t('settings.regions.deleteConfirm', { name: r.name }),
      destructive: true,
    })
    if (!ok) return
    await knativeRegionsApi.destroy(r.id)
    regions.value = regions.value.filter(x => x.id !== r.id)
    recomputeTotals(); syncStore()
  }

  async function downloadKubeconfig(r) {
    try {
      const { data } = await knativeRegionsApi.downloadKubeconfig(r.id)
      const url = URL.createObjectURL(data)
      const a = document.createElement('a')
      a.href = url; a.download = `${r.name}-kubeconfig.yaml`; a.click()
      URL.revokeObjectURL(url)
    } catch { /* ignore */ }
  }

  async function refreshRegions() {
    try {
      const { data } = await settingsApi.get()
      regions.value       = data.regions         ?? []
      totalCpuM.value     = data.total_cpu_m     ?? 0
      totalMemoryMb.value = data.total_memory_mb ?? 0
      demoStatus.value    = data.demo_status     ?? 'idle'
      syncStore()
    } catch { /* silent */ }
  }

  // ── Helpers ──
  function recomputeTotals() {
    const managed = regions.value.filter(r => r.is_active && !r.unlimited && r.region_mode !== 'infinite')
    totalCpuM.value     = managed.reduce((s, r) => s + r.total_cpu_m, 0)
    totalMemoryMb.value = managed.reduce((s, r) => s + r.total_memory_mb, 0)
  }
  function syncStore() {
    settings.update({
      base_domain: baseDomain.value, total_cpu_m: totalCpuM.value,
      total_memory_mb: totalMemoryMb.value, regions: regions.value,
      gpu_demo_enabled: gpuDemoEnabled.value,
      custom_runtimes_enabled: customRuntimesEnabled.value,
    })
  }
  function fmtCpu(m) { return !m ? '0m' : m >= 1000 ? (m / 1000).toFixed(1) + ' core' : m + 'm' }
  function fmtMem(mb) { return !mb ? '0 MB' : mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : mb + ' MB' }

  return {
    baseDomain, registrationEnabled, gpuDemoEnabled, customRuntimesEnabled, totalCpuM, totalMemoryMb, regions, demoStatus,
    loading, saving, error, saved, usedCpuM, usedMemoryMb,
    submit, openAdd, openEdit, closeRegionDetail, onRegionSaved,
    deleteRegion, downloadKubeconfig, refreshRegions,
    regionDetailOpen, regionDetailTarget, fmtCpu, fmtMem,
  }
}
