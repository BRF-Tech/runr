<script setup>
import { useI18n } from 'vue-i18n'
import GeneralTab from './GeneralTab.vue'
import WorkersTab from './WorkersTab.vue'
import ComponentSection from './ComponentSection.vue'
import RedisSection from './RedisSection.vue'
import SandboxTab from './SandboxTab.vue'
import MigrationTab from './MigrationTab.vue'

const { t } = useI18n()

// Every prop is bound by RegionDetailModal; the defaults are the ones of the step components
// the values are handed to, so an unset value reaches them as their own default.
defineProps({
  wizardStep:      { type: Number, default: 1 },
  isStepReadonly:  { type: Boolean, default: false },
  tutorialActive:  { type: Boolean, default: false },
  // General
  form:            { type: Object, required: true },
  kubeconfigMode:  { type: String, default: 'file' },
  kubeconfigName:  { type: String, default: '' },
  kubeconfigPaste: { type: String, default: '' },
  saving:          { type: Boolean, default: false },
  error:           { type: [String, null], default: null },
  canSaveGeneral:  { type: Boolean, default: false },
  // Knative Operator / Serving
  operatorStatus:  { type: String, default: 'not_installed' },
  operatorMessage: { type: String, default: '' },
  servingStatus:   { type: String, default: 'not_installed' },
  servingMessage:  { type: String, default: '' },
  nsOperator:      { type: String, default: '' },
  nsServing:       { type: String, default: '' },
  installing:      { type: Object, default: () => ({}) },
  servingVersion:  { type: String, default: null },
  operatorReady:   { type: Boolean, default: false },
  servingReady:    { type: Boolean, default: false },
  logCopied:       { type: Object, default: () => ({}) },
  // Workers + registry
  nodes:           { type: Array, default: () => [] },
  selectedNodes:   { type: Array, default: () => [] },
  nodesLoading:    { type: Boolean, default: false },
  workersSaving:   { type: Boolean, default: false },
  workersError:    { type: [String, null], default: null },
  registry:        { type: Object, default: null },
  storageClasses:  { type: Array, default: () => [] },
  // Redis
  redisStatus:          { type: String, default: null },
  redisAddress:         { type: String, default: '' },
  redisMode:            { type: String, default: null },
  redisError:           { type: String, default: null },
  popupRedisMode:       { type: String, default: null },
  popupRedisAddress:    { type: String, default: '' },
  popupRedisPassword:   { type: String, default: '' },
  popupRedisTestResult: { type: [String, null], default: null },
  popupRedisTesting:    { type: Boolean, default: false },
  redisSaving:          { type: Boolean, default: false },
  // Sandbox + migration
  regionId:                { type: String, default: null },
  region:                  { type: Object, default: null },
  createdRegion:           { type: Object, default: null },
  migrationStatus:         { type: String, default: null },
  migrationLoading:        { type: Boolean, default: false },
  migrationError:          { type: [String, null], default: null },
  migrationKubeconfigName: { type: String, default: '' },
  statusLabel:             { type: String, default: '' },
})
const emit = defineEmits([
  'update:wizard-step', 'close', 'saved',
  'save-general', 'create-region', 'toggle-node', 'refresh-nodes',
  'update:form-name', 'update:form-region-mode',
  'update:kubeconfig-mode', 'update:kubeconfig-paste', 'kubeconfig-file-change',
  'install', 'uninstall', 'copy-logs', 'upgrade',
  'update:popup-redis-mode', 'update:popup-redis-address', 'update:popup-redis-password',
  'configure-redis', 'remove-redis', 'test-redis',
  'finish-wizard', 'confirm-skip',
  'migration-kubeconfig-change', 'start-migration', 'complete-migration', 'rollback-migration',
])

function setStep(n) { emit('update:wizard-step', n) }
</script>

<template>
  <div class="flex-1 overflow-y-auto px-6 py-5">
    <!-- Step 1: General -->
    <div v-if="wizardStep === 1" data-tour="region-general">
      <GeneralTab
        :is-wizard="true" :is-step-readonly="isStepReadonly"
        :form="form" :kubeconfig-mode="kubeconfigMode" :kubeconfig-name="kubeconfigName" :kubeconfig-paste="kubeconfigPaste"
        :saving="saving" :error="tutorialActive ? null : error" :can-save-general="canSaveGeneral"
        @save="emit('save-general')" @close="emit('close')" @next="setStep(2)"
        @update:form-name="emit('update:form-name', $event)" @update:form-region-mode="emit('update:form-region-mode', $event)"
        @update:kubeconfig-mode="emit('update:kubeconfig-mode', $event)" @update:kubeconfig-paste="emit('update:kubeconfig-paste', $event)"
        @kubeconfig-file-change="emit('kubeconfig-file-change', $event)" />
    </div>
    <!-- Step 2: Workers + Registry -->
    <div v-if="wizardStep === 2" data-tour="region-workers">
      <WorkersTab
        :is-wizard="true" :is-step-readonly="isStepReadonly"
        :nodes="nodes" :selected-nodes="selectedNodes" :nodes-loading="nodesLoading"
        :workers-saving="workersSaving" :workers-error="tutorialActive ? null : workersError"
        :registry="registry" :storage-classes="storageClasses"
        :hide-placeholder-errors="tutorialActive"
        @create-region="emit('create-region')" @back="setStep(1)" @next="setStep(3)"
        @toggle-node="emit('toggle-node', $event)" @refresh-nodes="emit('refresh-nodes')" />
    </div>
    <!-- Step 3: Knative Operator -->
    <div v-if="wizardStep === 3" class="space-y-4" data-tour="region-operator">
      <ComponentSection component="operator"
        :status="operatorStatus" :message="isStepReadonly ? undefined : operatorMessage" :namespace="nsOperator"
        :readonly="isStepReadonly" :is-installing="installing.operator" :operator-ready="operatorReady"
        :serving-ready="servingReady" :log-copied="logCopied.operator"
        @install="emit('install', 'operator')" @uninstall="emit('uninstall', 'operator')"
        @copy-logs="emit('copy-logs', 'operator')" />
      <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
        <button type="button" class="btn-secondary" @click="setStep(2)">{{ t('common.back') }}</button>
        <div class="flex gap-3">
          <button v-if="!isStepReadonly" type="button" class="text-xs text-gray-400 hover:text-gray-100 underline underline-offset-2"
            @click="emit('confirm-skip', { step: 4, warning: 'skipOperatorWarning' })">
            {{ t('settings.regionDetail.wizard.skip') }}
          </button>
          <button type="button" class="btn-primary" :disabled="!isStepReadonly && !operatorReady" @click="setStep(4)">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>
    <!-- Step 4: Knative Serving -->
    <div v-if="wizardStep === 4" class="space-y-4" data-tour="region-serving">
      <div v-if="!operatorReady" class="bg-gray-900/40 border border-gray-800 rounded-md p-4 text-center">
        <p class="text-xs text-gray-400">{{ t('settings.regionDetail.wizard.operatorRequired') }}</p>
      </div>
      <template v-if="operatorReady">
        <ComponentSection component="serving"
          :status="servingStatus" :message="servingMessage" :namespace="nsServing"
          :version="servingVersion" :is-installing="installing.serving"
          :operator-ready="operatorReady" :serving-ready="servingReady" :log-copied="logCopied.serving"
          @install="emit('install', 'serving')" @upgrade="emit('upgrade', 'serving')"
          @uninstall="emit('uninstall', 'serving')" @copy-logs="emit('copy-logs', 'serving')" />
      </template>
      <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
        <button type="button" class="btn-secondary" @click="setStep(3)">{{ t('common.back') }}</button>
        <div class="flex gap-3">
          <button v-if="!servingReady" type="button" class="text-xs text-gray-400 hover:text-gray-100 underline underline-offset-2"
            @click="emit('confirm-skip', { step: 5, warning: 'skipServingWarning' })">
            {{ t('settings.regionDetail.wizard.skip') }}
          </button>
          <button type="button" class="btn-primary" :disabled="!servingReady" @click="setStep(5)">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>
    <!-- Step 5: Redis -->
    <div v-if="wizardStep === 5" class="space-y-4" data-tour="region-redis">
      <RedisSection :redis-status="redisStatus" :redis-address="redisAddress"
        :redis-mode="redisMode" :redis-error="redisError"
        :popup-redis-mode="popupRedisMode" :popup-redis-address="popupRedisAddress"
        :popup-redis-password="popupRedisPassword" :popup-redis-test-result="popupRedisTestResult"
        :popup-redis-testing="popupRedisTesting" :saving="redisSaving"
        @update:popup-redis-mode="emit('update:popup-redis-mode', $event)"
        @update:popup-redis-address="emit('update:popup-redis-address', $event)"
        @update:popup-redis-password="emit('update:popup-redis-password', $event)"
        @configure="emit('configure-redis')" @remove="emit('remove-redis')" @test="emit('test-redis')" />
      <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
        <button type="button" class="btn-secondary" @click="setStep(4)">{{ t('common.back') }}</button>
        <div class="flex gap-3">
          <button v-if="!redisStatus || redisStatus === 'none'" type="button" class="text-xs text-gray-400 hover:text-gray-100 underline underline-offset-2"
            @click="emit('confirm-skip', { step: 6, warning: 'skipRedisWarning' })">
            {{ t('settings.regionDetail.wizard.skip') }}
          </button>
          <button type="button" class="btn-primary" :disabled="redisStatus !== 'ready'" @click="setStep(6)">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>
    <!-- Step 6: Sandbox -->
    <div v-if="wizardStep === 6" class="space-y-4" data-tour="region-sandbox">
      <SandboxTab :region-id="regionId" :region="createdRegion ?? region" @saved="(r) => emit('saved', r)" />
      <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
        <button type="button" class="btn-secondary" @click="setStep(5)">{{ t('common.back') }}</button>
        <button type="button" class="btn-primary" @click="setStep(7)">{{ t('common.next') }}</button>
      </div>
    </div>
    <!-- Step 7: Migration -->
    <div v-if="wizardStep === 7" class="space-y-4" data-tour="region-migration">
      <MigrationTab :region="createdRegion ?? region" :show-data-location="false" :migration-status="migrationStatus"
        :migration-loading="migrationLoading" :migration-error="migrationError"
        :migration-kubeconfig-name="migrationKubeconfigName" :status-label="statusLabel"
        @kubeconfig-change="emit('migration-kubeconfig-change', $event)" @start="emit('start-migration')"
        @complete="emit('complete-migration')" @rollback="emit('rollback-migration')" />
      <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
        <button type="button" class="btn-secondary" @click="setStep(6)">{{ t('common.back') }}</button>
        <button type="button" class="btn-primary" @click="emit('finish-wizard')">{{ t('settings.regionDetail.wizard.finish') }}</button>
      </div>
    </div>
  </div>
</template>
