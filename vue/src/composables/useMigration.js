import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useConfirm } from '@/composables/useConfirm'

export function useMigration(regionId, emit) {
  const { t } = useI18n()
  const { confirm } = useConfirm()

  const migrationKubeconfig = ref('')
  const migrationKubeconfigName = ref('')
  // The new cluster's data location: a region with one moves only within it (data residency)
  const migrationDataLocation = ref('')
  const migrationLoading = ref(false)
  const migrationError = ref(null)
  const migrationStatus = ref(null)
  // What the move did with each project of the region (GET migration-status → projects), while
  // it can be rolled back: moved, building, deployed, not_moved, cancelled
  const migrationProjects = ref([])

  const statusLabel = computed(() => {
    if (!migrationStatus.value) return ''   // no migration: no label (and no lookup of a missing key)
    const key = `settings.regionDetail.migration.status${capitalize(migrationStatus.value || '')}`
    return t(key)
  })

  async function onMigrationKubeconfigChange(e) {
    const file = e.target.files?.[0]
    if (!file) return
    migrationKubeconfigName.value = file.name
    migrationKubeconfig.value = await file.text()
  }

  async function startMigration() {
    const ok = await confirm({
      title: t('settings.regionDetail.migration.clusterMigration'),
      message: t('settings.regionDetail.migration.confirmStart'),
      destructive: true,
    })
    if (!ok) return

    migrationLoading.value = true
    migrationError.value = null
    try {
      const payload = { kubeconfig: migrationKubeconfig.value }
      if (migrationDataLocation.value.trim()) payload.data_location = migrationDataLocation.value.trim()
      await knativeRegionsApi.startMigration(regionId.value, payload)
      migrationStatus.value = 'pending'
      emit('saved')
    } catch (e) {
      migrationError.value = e.response?.data?.message ?? t('common.error')
    } finally {
      migrationLoading.value = false
    }
  }

  async function completeMigration() {
    const ok = await confirm({
      title: t('settings.regionDetail.migration.completeMigration'),
      message: t('settings.regionDetail.migration.confirmComplete'),
    })
    if (!ok) return

    migrationLoading.value = true
    try {
      await knativeRegionsApi.completeMigration(regionId.value)
      migrationStatus.value = 'completed'
      migrationProjects.value = []
      emit('saved')
    } catch (e) {
      migrationError.value = e.response?.data?.message ?? t('common.error')
    } finally {
      migrationLoading.value = false
    }
  }

  async function rollbackMigration() {
    const ok = await confirm({
      title: t('settings.regionDetail.migration.rollbackMigration'),
      message: t('settings.regionDetail.migration.confirmRollback'),
      destructive: true,
    })
    if (!ok) return

    migrationLoading.value = true
    try {
      await knativeRegionsApi.rollbackMigration(regionId.value)
      migrationStatus.value = null
      migrationProjects.value = []
      emit('saved')
    } catch (e) {
      migrationError.value = e.response?.data?.message ?? t('common.error')
    } finally {
      migrationLoading.value = false
    }
  }

  // A base-domain migration completed without waiting for RunR to see its DNS record
  async function completeDomainMigration() {
    const ok = await confirm({
      title: t('settings.regionDetail.migration.completeDomainMigration'),
      message: t('settings.regionDetail.migration.confirmCompleteDomain'),
    })
    if (!ok) return

    migrationLoading.value = true
    migrationError.value = null
    try {
      const { data } = await knativeRegionsApi.completeDomainMigration(regionId.value)
      emit('saved', data.region?.data ?? data.region)
    } catch (e) {
      migrationError.value = e.response?.data?.message ?? t('common.error')
    } finally {
      migrationLoading.value = false
    }
  }

  async function loadMigrationReport() {
    if (!['pending', 'in_progress', 'dns_check', 'failed'].includes(migrationStatus.value)) {
      migrationProjects.value = []
      return
    }
    try {
      const { data } = await knativeRegionsApi.migrationStatus(regionId.value)
      migrationProjects.value = Array.isArray(data?.projects) ? data.projects : []
    } catch {
      // informational: the tab works without it
    }
  }

  function initMigrationState(region) {
    if (region) {
      migrationStatus.value = region.migration_status || null
      loadMigrationReport()
    }
  }

  function capitalize(s) {
    if (!s) return ''
    return s.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')
  }

  return {
    migrationKubeconfig, migrationKubeconfigName, migrationDataLocation,
    migrationLoading, migrationError, migrationStatus, statusLabel, migrationProjects,
    onMigrationKubeconfigChange, startMigration, completeMigration,
    rollbackMigration, initMigrationState, completeDomainMigration, loadMigrationReport,
  }
}
