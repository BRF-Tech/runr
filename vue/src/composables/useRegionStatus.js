import { ref } from 'vue'
import { knativeRegionsApi } from '@/api/knativeRegions'

export function useRegionStatus(regionId, form, emit, confirm, t) {
  const togglingStatus = ref(false)
  const statusError = ref(null)

  async function changeRegionStatus(newStatus) {
    const ok = await confirm({
      title: t(`settings.regions.statusTitle_${newStatus}`),
      message: t(`settings.regions.statusConfirm_${newStatus}`),
      destructive: newStatus !== 'active',
    })
    if (!ok) return

    togglingStatus.value = true
    statusError.value = null
    try {
      const result = await knativeRegionsApi.update(regionId.value, { region_status: newStatus })
      const fresh = result.data.data ?? result.data
      form.value.region_status = fresh.region_status
      form.value.is_active = fresh.is_active
      emit('saved', fresh)
    } catch (e) {
      statusError.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
    } finally {
      togglingStatus.value = false
    }
  }

  return { togglingStatus, statusError, changeRegionStatus }
}
