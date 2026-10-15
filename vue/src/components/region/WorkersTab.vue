<script setup>
import { useI18n } from 'vue-i18n'
import NodeSelector from './NodeSelector.vue'
import RegistrySection from './RegistrySection.vue'
import MonitoringReuseSection from './MonitoringReuseSection.vue'
import CapacityCard from './CapacityCard.vue'

const { t } = useI18n()

defineProps({
  // Edit mode: the region whose capacity budgets are shown below the nodes
  regionId:         { type: String, default: null },
  isEdit:           { type: Boolean, default: false },
  isWizard:         { type: Boolean, default: false },
  isStepReadonly:   { type: Boolean, default: false },
  isDemo:           { type: Boolean, default: false },
  projectNamespace: { type: String, default: '' },
  nodes:            { type: Array, default: () => [] },
  selectedNodes:    { type: Array, default: () => [] },
  nodesLoading:     { type: Boolean, default: false },
  workersSaving:    { type: Boolean, default: false },
  workersError:     { type: String, default: null },
  // Registry props
  registry:         { type: Object, default: null },
  storageClasses:   { type: Array, default: () => [] },
  hidePlaceholderErrors: { type: Boolean, default: false },
})

defineEmits([
  'save-workers', 'create-region', 'close', 'next', 'back',
  'toggle-node', 'refresh-nodes',
])
</script>

<template>
  <!-- Edit mode -->
  <div v-if="isEdit" class="space-y-4">
    <div v-if="isDemo" class="rounded-md p-3" style="background:rgb(var(--brand-500)/0.08);border:1px solid rgb(var(--brand-500)/0.25)">
      <p class="text-xs" style="color:rgb(var(--brand-500))">{{ t('settings.regions.demoNotice') }}</p>
    </div>
    <div>
      <label class="label">{{ t('settings.regionDetail.projectNamespace.label') }}</label>
      <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded-sm px-3 py-2">{{ projectNamespace }}</p>
      <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.projectNamespace.readonlyHint') }}</p>
    </div>
    <div class="border-t border-gray-800/60 pt-4"></div>
    <NodeSelector :nodes="nodes" :selected-nodes="selectedNodes" :nodes-loading="nodesLoading"
      @toggle="$emit('toggle-node', $event)" @refresh="$emit('refresh-nodes')" />
    <p v-if="workersError" class="text-sm text-red-400">{{ workersError }}</p>
    <div class="flex justify-end gap-3 pt-3 border-t border-gray-800/60">
      <button type="button" class="btn-primary text-xs" :disabled="workersSaving" @click="$emit('save-workers')">
        {{ workersSaving ? t('common.saving') : t('settings.regionDetail.workers.saveWorkers') }}
      </button>
    </div>
    <div v-if="regionId" class="border-t border-gray-800/60 pt-4">
      <CapacityCard :region-id="regionId" />
    </div>
  </div>

  <!-- Wizard step 2: readonly -->
  <div v-else-if="isStepReadonly" class="space-y-4">
    <NodeSelector :nodes="nodes" :selected-nodes="selectedNodes" :readonly="true" />
    <div class="border-t border-gray-800/60 pt-4"></div>
    <RegistrySection v-if="registry" :readonly="true" :setup-status="registry.setupStatus.value" />
    <MonitoringReuseSection v-if="registry && (registry.monitoringConfig.value.prometheusUrl || registry.monitoringConfig.value.lokiUrl)"
      :model-value="registry.monitoringConfig.value" :readonly="true" />
    <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
      <button type="button" class="btn-secondary" @click="$emit('back')">{{ t('common.back') }}</button>
      <button type="button" class="btn-primary" @click="$emit('next')">{{ t('common.next') }}</button>
    </div>
  </div>

  <!-- Wizard step 2: editable -->
  <div v-else class="space-y-4">
    <!-- Skeleton while loading -->
    <template v-if="nodesLoading">
      <div>
        <div class="flex items-center justify-between mb-3">
          <div>
            <div class="h-4 w-32 bg-gray-800 rounded-sm animate-pulse"></div>
            <div class="h-3 w-48 bg-gray-800/60 rounded-sm animate-pulse mt-1.5"></div>
          </div>
          <div class="h-4 w-20 bg-gray-800 rounded-sm animate-pulse"></div>
        </div>
        <div class="space-y-1">
          <div v-for="i in 3" :key="i" class="flex items-center gap-3 rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5">
            <div class="h-4 w-4 bg-gray-800 rounded-sm animate-pulse"></div>
            <div class="flex-1"><div class="h-3.5 bg-gray-800 rounded-sm animate-pulse" :style="{ width: (50 + i * 12) + '%' }"></div></div>
            <div class="h-3 w-12 bg-gray-800 rounded-sm animate-pulse"></div>
            <div class="h-3 w-14 bg-gray-800 rounded-sm animate-pulse"></div>
          </div>
        </div>
      </div>
      <div class="border-t border-gray-800/60 pt-4"></div>
      <div>
        <div class="h-4 w-28 bg-gray-800 rounded-sm animate-pulse mb-3"></div>
        <div class="flex gap-3 mb-3">
          <div class="h-4 w-20 bg-gray-800 rounded-sm animate-pulse"></div>
          <div class="h-4 w-20 bg-gray-800 rounded-sm animate-pulse"></div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div class="h-9 bg-gray-800/60 rounded-sm animate-pulse"></div>
          <div class="h-9 bg-gray-800/60 rounded-sm animate-pulse"></div>
        </div>
      </div>
    </template>

    <!-- Content loaded -->
    <template v-else>
      <NodeSelector :nodes="nodes" :selected-nodes="selectedNodes" :nodes-loading="nodesLoading"
        @toggle="$emit('toggle-node', $event)" @refresh="$emit('refresh-nodes')" />

      <div class="border-t border-gray-800/60 pt-4"></div>

      <!-- Registry Section -->
      <RegistrySection v-if="registry"
        :registry-mode="registry.registryMode.value"
        :storage-type="registry.storageType.value"
        :pvc-config="registry.pvcConfig.value"
        :s3-config="registry.s3Config.value"
        :external-config="registry.externalConfig.value"
        :storage-classes="storageClasses"
        :setup-status="registry.setupStatus.value"
        :setup-phase="registry.setupPhase.value"
        :setup-error="registry.setupError.value"
        :is-setting-up="registry.isSettingUp.value"
        :hide-placeholder-errors="hidePlaceholderErrors"
        @update:registry-mode="registry.registryMode.value = $event"
        @update:storage-type="registry.storageType.value = $event"
        @update:pvc-config="registry.pvcConfig.value = $event"
        @update:s3-config="registry.s3Config.value = $event"
        @update:external-config="registry.externalConfig.value = $event" />

      <!-- Optional: reuse an existing Prometheus / Loki on the cluster -->
      <MonitoringReuseSection v-if="registry"
        :model-value="registry.monitoringConfig.value"
        :readonly="registry.isSettingUp.value"
        @update:model-value="registry.monitoringConfig.value = $event" />
    </template>

    <!-- a registry setup error is already shown in the registry section above -->
    <p v-if="workersError && (nodesLoading || workersError !== registry?.setupError.value)" class="text-sm text-red-400">{{ workersError }}</p>
    <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
      <button type="button" class="btn-secondary" @click="$emit('back')">{{ t('common.back') }}</button>
      <button type="button" class="btn-primary"
        :disabled="nodesLoading || workersSaving || (registry && registry.isSettingUp.value)"
        @click="$emit('create-region')">
        {{ workersSaving ? t('common.saving') : t('settings.regionDetail.wizard.saveAndNext') }}
      </button>
    </div>
  </div>
</template>
