import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useConfirm } from '@/composables/useConfirm'

export function useMigration(regionId, emit) {
  const { t } = useI18n()
  const { confirm } = useConfirm()

  const migrationKubeconfig = ref('')
  const migrationKubeconfigName = ref('')
  const migrationLoading = ref(false)
  const migrationError = ref(null)
  const migrationStatus = ref(null)

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
      await knativeRegionsApi.startMigration(regionId.value, { kubeconfig: migrationKubeconfig.value })
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
      emit('saved')
    } catch (e) {
      migrationError.value = e.response?.data?.message ?? t('common.error')
    } finally {
      migrationLoading.value = false
    }
  }

  function initMigrationState(region) {
    if (region) {
      migrationStatus.value = region.migration_status || null
    }
  }

  function capitalize(s) {
    if (!s) return ''
    return s.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')
  }

  return {
    migrationKubeconfig, migrationKubeconfigName,
    migrationLoading, migrationError, migrationStatus, statusLabel,
    onMigrationKubeconfigChange, startMigration, completeMigration,
    rollbackMigration, initMigrationState,
  }
}
