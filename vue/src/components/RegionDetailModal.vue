<script setup>
import { watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRegionDetail } from '@/composables/useRegionDetail'
import { useTutorialStore } from '@/stores/tutorial'
import { useRegionWizardTutorial } from '@/composables/useRegionWizardTutorial'
import GeneralTab from './region/GeneralTab.vue'
import WorkersTab from './region/WorkersTab.vue'
import ComponentSection from './region/ComponentSection.vue'
import RedisSection from './region/RedisSection.vue'
import InstallPopup from './region/InstallPopup.vue'
import RegistrySection from './region/RegistrySection.vue'
import RegionDemoSection from './region/RegionDemoSection.vue'
import FunctionTlsSection from './region/FunctionTlsSection.vue'
import SandboxTab from './region/SandboxTab.vue'
import GpuTab from './region/GpuTab.vue'
import DataServicesTab from './region/DataServicesTab.vue'
import MigrationTab from './region/MigrationTab.vue'
import RegionSupplyChainTab from '@/modules/supply-chain/components/RegionSupplyChainTab.vue'
import RegionWizard from './region/RegionWizard.vue'
import { useMigration } from '@/composables/useMigration'

const { t } = useI18n()
const props  = defineProps({
  region:      { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const {
  isEdit, wizardStep, wizardStepTitles, activeTab, editTabs,
  createdRegion, isStepReadonly, regionId,
  form, kubeconfigName, kubeconfigMode, kubeconfigPaste,
  saving, error, canSaveGeneral,
  operatorStatus, operatorMessage, servingStatus, servingMessage,
  nsOperator, nsServing,
  installing,
  servingVersion, compatibleVersions, versionsLoading,
  projectNamespace,
  nodes, nodesLoading, selectedNodes, workersSaving, workersError, storageClasses,
  redisMode, redisAddress, redisError, redisStatus, registry,
  installPopupOpen, installPopupComponent, installPopupMode,
  popupNamespace, popupNsCheckResult, popupNsChecking,
  popupVersion, popupRedisMode, popupRedisAddress, popupRedisPassword,
  popupRedisTestResult, popupRedisTesting, popupError, popupSubmitting,
  operatorReady, servingReady, dnsInstruction,
  logCopied, copyLogs,
  changeRegionStatus, togglingStatus,
  onKubeconfigChange, saveGeneral, createRegion,
  confirmUninstallComponent,
  loadNodes, toggleNode, saveWorkers,
  openInstallPopup, checkPopupNs, confirmInstallPopup,
  testPopupRedis, onPopupRedisModeChange,
  saveRedis, removeRedis, redisSaving,
  finishWizard, confirm,
} = useRegionDetail(props, emit)

const migration = useMigration(regionId, emit)
watch(() => props.region, (r) => { if (r) migration.initMigrationState(r) }, { immediate: true })

const tutorialStore = useTutorialStore()
const { start: startRegionTutorial } = useRegionWizardTutorial(wizardStep, {
  onComplete: () => { if (!isEdit.value) emit('close') },
})
watch(() => tutorialStore.restartCounter, () => { if (!isEdit.value) nextTick(() => startRegionTutorial()) })
defineExpose({
  startTutorial: () => { error.value = null; workersError.value = null; startRegionTutorial() },
})
function updateFormName(val) { form.value.name = val }
function updateFormRegionMode(val) { form.value.region_mode = val }

function handleConfirmSkip({ step, warning }) {
  const key = `settings.regionDetail.wizard.${warning}`
  confirm({ title: t('settings.regionDetail.wizard.skip'), message: t(key) })
    .then(ok => ok && (wizardStep.value = step))
}
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-xs" @click="isEdit && emit('close')" />
    <div class="relative bg-gray-950 border border-gray-800 rounded-2xl w-full max-w-4xl flex flex-col overflow-hidden" style="max-height: 88vh">
      <!-- Header bar -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800 shrink-0" style="background:linear-gradient(135deg,rgb(var(--brand-900)/0.3),rgb(var(--brand-700)/0.15))">
        <div class="flex items-center gap-3">
          <div class="w-1 h-5 rounded-full shrink-0" style="background:linear-gradient(to bottom,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <div>
            <h3 class="font-semibold text-gray-100">
              {{ isEdit ? t('settings.regions.editTitle') : t('settings.regions.addTitle') }}
            </h3>
            <p v-if="!isEdit" class="text-[11px] text-gray-400 mt-0.5">
              {{ t('settings.regionDetail.wizard.stepOf', { step: wizardStep, total: 7 }) }} — {{ wizardStepTitles[wizardStep - 1] }}
            </p>
          </div>
          <span v-if="(region?.is_demo || createdRegion?.is_demo)" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))">
            {{ t('settings.regions.demoBadge') }}
          </span>
        </div>
        <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors text-sm" @click="emit('close')">✕</button>
      </div>

      <!-- Wizard step dots -->
      <div v-if="!isEdit" class="flex items-center gap-1.5 px-6 pt-4 shrink-0">
        <span v-for="n in 7" :key="n"
          class="h-1.5 rounded-full transition-all duration-300"
          :class="[n < wizardStep ? 'w-4' : n === wizardStep ? 'w-6' : 'w-1.5']"
          :style="n <= wizardStep ? 'background-color:rgb(var(--brand-600))' : 'background-color:#374151'" />
      </div>

      <!-- ═══ EDIT MODE ═══ -->
      <template v-if="isEdit">
        <!-- Synced (assigned) regions: only demo seed/delete -->
        <template v-if="region?.is_synced">
          <div class="flex-1 overflow-y-auto px-6 py-5">
            <RegionDemoSection :region-id="region.id" :status="region?.region_demo_status" :can-seed="true" />
          </div>
        </template>
        <!-- Own regions: full tab interface -->
        <template v-else>
          <div class="flex gap-0 shrink-0 border-b border-gray-800 px-6">
            <button v-for="tab in editTabs" :key="tab" class="tab-line text-xs font-medium -mb-px"
              :class="activeTab === tab ? 'is-active' : ''" @click="activeTab = tab">{{ t(`settings.regionDetail.tabs.${tab}`) }}</button>
          </div>
          <div class="flex-1 overflow-y-auto px-6 py-5">
            <div v-if="activeTab === 'general'">
              <GeneralTab :is-edit="true" :form="form" :region="region"
                :kubeconfig-mode="kubeconfigMode" :kubeconfig-name="kubeconfigName" :kubeconfig-paste="kubeconfigPaste"
                :saving="saving" :error="error" :can-save-general="canSaveGeneral"
                :dns-instruction="dnsInstruction" :dns-resolved="region?.dns_resolved" :toggling-status="togglingStatus"
                @save="saveGeneral" @close="emit('close')" @change-status="changeRegionStatus"
                @update:kubeconfig-mode="kubeconfigMode = $event" @update:kubeconfig-paste="kubeconfigPaste = $event"
                @kubeconfig-file-change="onKubeconfigChange" />
              <RegionDemoSection v-if="!region?.is_demo" :region-id="region.id" :status="region?.region_demo_status"
                :can-seed="servingStatus === 'ready' && redisMode != null && redisStatus === 'ready' && (region?.has_registry ?? false)" />
              <FunctionTlsSection v-if="!region?.is_demo && region?.has_serving" :region-id="region.id"
                :mode="region?.function_tls_mode" :expires-at="region?.function_tls_expires_at" />
            </div>
            <WorkersTab v-if="activeTab === 'workers'" :is-edit="true" :is-demo="region?.is_demo" :region-id="region?.id"
              :project-namespace="projectNamespace" :nodes="nodes" :selected-nodes="selectedNodes" :nodes-loading="nodesLoading"
              :workers-saving="workersSaving" :workers-error="workersError"
              @save-workers="saveWorkers" @toggle-node="toggleNode" @refresh-nodes="loadNodes" />
            <div v-if="activeTab === 'registry'" class="space-y-4">
              <RegistrySection :readonly="true" :registry-mode="region?.registry_mode" :setup-status="region?.registry_status" />
              <p v-if="region?.registry_address" class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded-sm px-3 py-2">{{ region.registry_address }}</p>
            </div>
            <div v-if="activeTab === 'operator'">
              <ComponentSection component="operator" :status="operatorStatus" :message="operatorMessage" :namespace="nsOperator"
                :is-installing="installing.operator" :operator-ready="operatorReady" :serving-ready="servingReady" :log-copied="logCopied.operator"
                @install="openInstallPopup('operator', 'install')" @uninstall="confirmUninstallComponent('operator')" @copy-logs="copyLogs('operator', operatorMessage)" />
            </div>
            <div v-if="activeTab === 'redis'" class="space-y-5">
              <RedisSection :redis-status="redisStatus" :redis-address="redisAddress" :redis-mode="redisMode" :redis-error="redisError"
                :popup-redis-mode="popupRedisMode" :popup-redis-address="popupRedisAddress" :popup-redis-password="popupRedisPassword"
                :popup-redis-test-result="popupRedisTestResult" :popup-redis-testing="popupRedisTesting" :saving="redisSaving"
                @update:popup-redis-mode="onPopupRedisModeChange" @update:popup-redis-address="popupRedisAddress = $event"
                @update:popup-redis-password="popupRedisPassword = $event" @configure="saveRedis" @remove="removeRedis" @test="testPopupRedis" />
            </div>
            <div v-if="activeTab === 'serving'">
              <ComponentSection component="serving" :status="servingStatus" :message="servingMessage" :namespace="nsServing"
                :version="servingVersion" :is-installing="installing.serving" :operator-ready="operatorReady" :serving-ready="servingReady" :log-copied="logCopied.serving"
                @install="openInstallPopup('serving', 'install')" @upgrade="openInstallPopup('serving', 'upgrade')"
                @uninstall="confirmUninstallComponent('serving')" @copy-logs="copyLogs('serving', servingMessage)" />
            </div>
            <DataServicesTab v-if="activeTab === 'data' && region?.id" :region-id="region.id" :region="region" />
            <SandboxTab v-if="activeTab === 'sandbox'" :region-id="region?.id" :region="region" @saved="(r) => emit('saved', r)" />
            <GpuTab v-if="activeTab === 'gpu'" :region-id="region?.id" :region="region" />
            <MigrationTab v-if="activeTab === 'migration'" :region="region"
              :migration-status="migration.migrationStatus.value" :migration-loading="migration.migrationLoading.value"
              :migration-error="migration.migrationError.value" :migration-kubeconfig-name="migration.migrationKubeconfigName.value"
              :status-label="migration.statusLabel.value" @kubeconfig-change="migration.onMigrationKubeconfigChange"
              @start="migration.startMigration" @complete="migration.completeMigration" @rollback="migration.rollbackMigration" />
            <RegionSupplyChainTab v-if="activeTab === 'supplyChain' && region?.id" :region="region" />
          </div>
        </template>
      </template>

      <!-- ═══ NEW MODE: wizard ═══ -->
      <RegionWizard v-else
        :wizard-step="wizardStep" :is-step-readonly="isStepReadonly" :tutorial-active="tutorialStore.tutorialActive"
        :form="form" :kubeconfig-mode="kubeconfigMode" :kubeconfig-name="kubeconfigName" :kubeconfig-paste="kubeconfigPaste"
        :saving="saving" :error="error" :can-save-general="canSaveGeneral"
        :operator-status="operatorStatus" :operator-message="operatorMessage" :serving-status="servingStatus" :serving-message="servingMessage"
        :ns-operator="nsOperator" :ns-serving="nsServing" :installing="installing"
        :serving-version="servingVersion" :operator-ready="operatorReady" :serving-ready="servingReady" :log-copied="logCopied"
        :nodes="nodes" :selected-nodes="selectedNodes" :nodes-loading="nodesLoading"
        :workers-saving="workersSaving" :workers-error="workersError" :registry="registry" :storage-classes="storageClasses"
        :redis-status="redisStatus" :redis-address="redisAddress" :redis-mode="redisMode" :redis-error="redisError"
        :popup-redis-mode="popupRedisMode" :popup-redis-address="popupRedisAddress" :popup-redis-password="popupRedisPassword"
        :popup-redis-test-result="popupRedisTestResult" :popup-redis-testing="popupRedisTesting" :redis-saving="redisSaving"
        :region-id="regionId" :region="region" :created-region="createdRegion"
        :migration-status="migration.migrationStatus.value" :migration-loading="migration.migrationLoading.value"
        :migration-error="migration.migrationError.value" :migration-kubeconfig-name="migration.migrationKubeconfigName.value"
        :status-label="migration.statusLabel.value"
        @update:wizard-step="wizardStep = $event" @close="emit('close')" @saved="(r) => emit('saved', r)"
        @save-general="saveGeneral" @create-region="createRegion" @toggle-node="toggleNode" @refresh-nodes="loadNodes"
        @update:form-name="updateFormName" @update:form-region-mode="updateFormRegionMode"
        @update:kubeconfig-mode="kubeconfigMode = $event" @update:kubeconfig-paste="kubeconfigPaste = $event"
        @kubeconfig-file-change="onKubeconfigChange"
        @install="(c) => openInstallPopup(c, 'install')" @upgrade="(c) => openInstallPopup(c, 'upgrade')"
        @uninstall="confirmUninstallComponent" @copy-logs="(c) => copyLogs(c, c === 'operator' ? operatorMessage : servingMessage)"
        @update:popup-redis-mode="onPopupRedisModeChange" @update:popup-redis-address="popupRedisAddress = $event"
        @update:popup-redis-password="popupRedisPassword = $event"
        @configure-redis="saveRedis" @remove-redis="removeRedis" @test-redis="testPopupRedis"
        @finish-wizard="finishWizard" @confirm-skip="handleConfirmSkip"
        @migration-kubeconfig-change="migration.onMigrationKubeconfigChange"
        @start-migration="migration.startMigration" @complete-migration="migration.completeMigration" @rollback-migration="migration.rollbackMigration" />
    </div>
    <InstallPopup :open="installPopupOpen" :component="installPopupComponent" :mode="installPopupMode"
      :namespace="popupNamespace" :ns-check-result="popupNsCheckResult" :ns-checking="popupNsChecking"
      :version="popupVersion" :compatible-versions="compatibleVersions" :versions-loading="versionsLoading"
      :error="popupError" :submitting="popupSubmitting" @close="installPopupOpen = false"
      @update:namespace="popupNamespace = $event" @update:version="popupVersion = $event"
      @check-ns="checkPopupNs" @confirm="confirmInstallPopup" />
  </div>
</template>
