<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  region:               { type: Object, default: null },
  migrationStatus:      { type: String, default: null },
  migrationLoading:     { type: Boolean, default: false },
  migrationError:       { type: String, default: null },
  migrationKubeconfigName: { type: String, default: '' },
  migrationDataLocation: { type: String, default: '' },
  // the wizard's new region has no data location yet
  showDataLocation:     { type: Boolean, default: true },
  statusLabel:          { type: String, default: '' },
  // What the move did with each project (GET migration-status → projects)
  migrationProjects:    { type: Array, default: () => [] },
})

defineEmits(['kubeconfig-change', 'update:data-location', 'start', 'complete', 'rollback', 'complete-domain', 'refresh-projects'])

const OUTCOME_CLASS = {
  moved:     'bg-green-900/50 text-green-400',
  deployed:  'bg-green-900/50 text-green-400',
  building:  'bg-yellow-900/50 text-yellow-400',
  not_moved: 'bg-red-900/50 text-red-400',
  cancelled: 'bg-gray-800 text-gray-400',
}
const outcomeClass = (outcome) => OUTCOME_CLASS[outcome] ?? OUTCOME_CLASS.cancelled
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

    <!-- Per project: moved, built again on the new cluster, not moved (with the reason) -->
    <div v-if="migrationProjects.length" class="space-y-2" data-testid="migration-projects">
      <div class="flex items-center justify-between">
        <h4 class="text-xs font-semibold text-gray-100">{{ t('settings.regionDetail.migration.projects') }}</h4>
        <button type="button" class="text-xs text-gray-400 hover:text-gray-200" data-testid="migration-projects-refresh"
          @click="$emit('refresh-projects')">{{ t('settings.regionDetail.migration.refreshProjects') }}</button>
      </div>
      <ul class="divide-y divide-gray-800/60 text-xs">
        <!-- an environment of a project (docs/environments.md) is a row of its own -->
        <li v-for="p in migrationProjects" :key="`${p.organization ?? ''}/${p.project_id}/${p.environment ?? ''}`" class="py-1.5 flex items-start gap-2"
          :data-project="p.project" :data-environment="p.environment ?? undefined">
          <span class="px-1.5 py-0.5 rounded-sm font-medium shrink-0" :class="outcomeClass(p.outcome)">
            {{ t(`settings.regionDetail.migration.outcome.${p.outcome}`) }}
          </span>
          <div class="min-w-0">
            <span class="text-gray-100">{{ p.project }}</span>
            <span v-if="p.environment" class="text-gray-300"> ({{ p.environment }})</span>
            <span v-if="p.version" class="text-gray-400 font-mono"> v{{ p.version }}</span>
            <span v-if="p.organization" class="text-gray-400"> · {{ p.organization }}</span>
            <p v-if="p.message" class="text-gray-400 break-words">{{ p.message }}</p>
          </div>
        </li>
      </ul>
    </div>

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

      <div v-if="showDataLocation">
        <label class="block text-xs text-gray-400" for="migration-data-location">
          {{ t('settings.regionDetail.migration.dataLocation') }}
        </label>
        <input id="migration-data-location" type="text" maxlength="64" class="input text-xs font-mono w-48 mt-1"
          :value="migrationDataLocation" placeholder="EU" data-testid="migration-data-location"
          @input="$emit('update:data-location', $event.target.value)" />
        <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.migration.dataLocationHint') }}</p>
      </div>

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

    <!-- Base-domain migration: the region answers on the new function domain too until its DNS answers -->
    <div v-if="region?.is_domain_migrating" class="rounded-md border border-blue-800/50 bg-blue-900/10 px-3 py-3 space-y-2" data-testid="domain-migration">
      <h4 class="text-xs font-semibold text-gray-100">{{ t('settings.regionDetail.migration.domainMigration') }}</h4>
      <p class="text-xs text-gray-400">
        {{ t('settings.regionDetail.migration.domainMigrationHint', { target: region.domain_migration_target || '—' }) }}
      </p>
      <p v-if="region.domain_migration_target && region.dns_instruction?.target" class="text-xs font-mono text-brand-400 select-all">
        *.{{ region.domain_migration_target }} → {{ region.dns_instruction.target }}
      </p>
      <button type="button" class="px-3 py-1.5 text-xs font-medium rounded-sm bg-green-600 hover:bg-green-500 text-white disabled:opacity-50"
        :disabled="migrationLoading" @click="$emit('complete-domain')">
        {{ t('settings.regionDetail.migration.completeDomainMigration') }}
      </button>
    </div>

    <!-- Error -->
    <p v-if="migrationError" class="text-xs text-red-400">{{ migrationError }}</p>
  </div>
</template>
