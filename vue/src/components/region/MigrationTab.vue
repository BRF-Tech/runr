<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  region:               { type: Object, default: null },
  migrationStatus:      { type: String, default: null },
  migrationLoading:     { type: Boolean, default: false },
  migrationError:       { type: String, default: null },
  migrationKubeconfigName: { type: String, default: '' },
  statusLabel:          { type: String, default: '' },
})

defineEmits(['kubeconfig-change', 'start', 'complete', 'rollback'])
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-sm font-semibold text-gray-100">
      {{ t('settings.regionDetail.migration.clusterMigration') }}
    </h3>

    <!-- Status badge -->
    <div v-if="migrationStatus" class="flex items-center gap-3">
      <span
        class="px-2 py-0.5 rounded-sm text-xs font-medium"
        :class="{
          'bg-yellow-900/50 text-yellow-400': ['pending', 'in_progress'].includes(migrationStatus),
          'bg-blue-900/50 text-blue-400': migrationStatus === 'dns_check',
          'bg-green-900/50 text-green-400': migrationStatus === 'completed',
          'bg-red-900/50 text-red-400': migrationStatus === 'failed',
        }"
      >
        {{ statusLabel }}
      </span>
    </div>

    <!-- IP display -->
    <div v-if="region?.migration_old_ip" class="grid grid-cols-2 gap-4 text-xs text-gray-400">
      <div>
        <span class="text-gray-400">{{ t('settings.regionDetail.migration.oldIp') }}:</span>
        {{ region.migration_old_ip }}
      </div>
      <div>
        <span class="text-gray-400">{{ t('settings.regionDetail.migration.newIp') }}:</span>
        {{ region?.external_ip || '—' }}
      </div>
    </div>

    <!-- DNS check message -->
    <p v-if="migrationStatus === 'dns_check'" class="text-xs text-amber-400">
      {{ t('settings.regionDetail.migration.dnsCheckPhase') }}
    </p>

    <!-- In progress -->
    <p v-if="migrationStatus === 'in_progress'" class="text-xs text-yellow-400">
      {{ t('settings.regionDetail.migration.migrationInProgress') }}
    </p>

    <!-- Kubeconfig upload (only when idle or completed/failed) -->
    <div v-if="!migrationStatus || ['completed', 'failed'].includes(migrationStatus)" class="space-y-3">
      <label class="block text-xs text-gray-400">
        {{ t('settings.regionDetail.migration.uploadNewKubeconfig') }}
      </label>
      <input
        type="file"
        accept=".yaml,.yml,.conf,*"
        class="block w-full text-xs text-gray-400 file:mr-3 file:py-1 file:px-3 file:rounded-sm file:border-0 file:text-xs file:font-medium file:bg-gray-700 file:text-gray-100 hover:file:bg-gray-600"
        @change="$emit('kubeconfig-change', $event)"
      />
      <p v-if="migrationKubeconfigName" class="text-xs text-gray-400">{{ migrationKubeconfigName }}</p>

      <button
        class="px-3 py-1.5 text-xs font-medium rounded-sm bg-amber-600 hover:bg-amber-500 text-white disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="!migrationKubeconfigName || migrationLoading"
        @click="$emit('start')"
      >
        {{ t('settings.regionDetail.migration.startMigration') }}
      </button>
    </div>

    <!-- Complete / Rollback buttons -->
    <div v-if="migrationStatus === 'dns_check'" class="flex gap-2">
      <button
        class="px-3 py-1.5 text-xs font-medium rounded-sm bg-green-600 hover:bg-green-500 text-white disabled:opacity-50"
        :disabled="migrationLoading"
        @click="$emit('complete')"
      >
        {{ t('settings.regionDetail.migration.completeMigration') }}
      </button>
      <button
        class="px-3 py-1.5 text-xs font-medium rounded-sm bg-red-600 hover:bg-red-500 text-white disabled:opacity-50"
        :disabled="migrationLoading"
        @click="$emit('rollback')"
      >
        {{ t('settings.regionDetail.migration.rollbackMigration') }}
      </button>
    </div>

    <!-- Rollback on failure -->
    <div v-if="migrationStatus === 'failed'" class="flex gap-2">
      <button
        class="px-3 py-1.5 text-xs font-medium rounded-sm bg-red-600 hover:bg-red-500 text-white disabled:opacity-50"
        :disabled="migrationLoading"
        @click="$emit('rollback')"
      >
        {{ t('settings.regionDetail.migration.rollbackMigration') }}
      </button>
    </div>

    <!-- Error -->
    <p v-if="migrationError" class="text-xs text-red-400">{{ migrationError }}</p>
  </div>
</template>
