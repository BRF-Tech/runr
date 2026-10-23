<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useFallbackPolling } from '@/composables/useFallbackPolling'
import { usePlatformSync } from '@/composables/usePlatformSync'
import { useTutorialStore } from '@/stores/tutorial'
import { useDetailVersionsTutorial, useDetailDeploymentsTutorial, useDetailMetricsTutorial } from '@/composables/useTutorial'
import { useProjectDetail } from '@/composables/useProjectDetail'
import AppHeader from '@/components/AppHeader.vue'
import EditProjectModal from '@/components/EditProjectModal.vue'
import CloneProjectModal from '@/components/CloneProjectModal.vue'
import ProjectHeader from '@/components/ProjectHeader.vue'
import VersionList from '@/components/VersionList.vue'
import EditorPanel from '@/components/project/EditorPanel.vue'
import DeploymentTable from '@/components/DeploymentTable.vue'
import DeployModal from '@/components/DeployModal.vue'
import DeploymentLogPanel from '@/components/DeploymentLogPanel.vue'
import DeploymentDeleteModal from '@/components/DeploymentDeleteModal.vue'
import EnvironmentSwitcher from '@/components/project/EnvironmentSwitcher.vue'
import ProjectEnvironments from '@/components/project/ProjectEnvironments.vue'
import RollbackModal from '@/components/project/RollbackModal.vue'
import HistoricalMetrics from '@/components/project/HistoricalMetrics.vue'
import LegacyTypeNotice from '@/components/project/LegacyTypeNotice.vue'
import ProjectDomains from '@/components/project/ProjectDomains.vue'
import ProjectNetwork from '@/components/project/ProjectNetwork.vue'
import GlobalRegions from '@/components/project/GlobalRegions.vue'
import PrivateConnections from '@/components/project/PrivateConnections.vue'
import ProjectModels from '@/components/project/ProjectModels.vue'
import ProjectAccess from '@/components/rbac/ProjectAccess.vue'
import ProjectStopBanner from '@/components/project/ProjectStopBanner.vue'
import RightsizingCard from '@/components/project/RightsizingCard.vue'
import ProjectStopModal from '@/components/project/ProjectStopModal.vue'
import ProjectOwnerModal from '@/components/project/ProjectOwnerModal.vue'
import RuntimePatchDialog from '@/components/project/RuntimePatchDialog.vue'
import RolloutPanel from '@/components/project/RolloutPanel.vue'
import DeployStrategyModal from '@/components/project/DeployStrategyModal.vue'
import { asRollout, holdsTraffic, endedRecently, projectStrategy } from '@/utils/deployStrategy'
import { useToast } from '@/composables/useToast'
import { usePermissionsStore } from '@/stores/permissions'
import { moduleRegistry } from '@/modules/index'
import PolicyBlockModal from '@/modules/supply-chain/components/PolicyBlockModal.vue'

const { t } = useI18n()
const router = useRouter()
const tutorialStore = useTutorialStore()
const perms = usePermissionsStore()
const { start: startVersionsTutorial }    = useDetailVersionsTutorial()
const { start: startDeploymentsTutorial } = useDetailDeploymentsTutorial()
const { start: startMetricsTutorial }     = useDetailMetricsTutorial()

const {
  // State
  project, loading, activeTab,
  versions, versionsLoaded,
  selectedVersion, selectedVersionFiles, selectedVersionActiveFile,
  versionFilesLoading, commitMsg, editSaving,
  deployments, deploying, rollout,
  environments, environment, currentEnvironment,
  showEdit, showClone, showDeployModal, policyBlock,
  handlerError,
  // Computed
  endpointUrl, regionLabel, snapshotRegionLabel,
  isEditable, isRuntimeEol, mainFile, handlerName, tabs, isAiProject,
  // Methods
  loadVersionFiles, save, addFile, removeFile, uploadFile,
  openDeployModal, confirmDeploy, deleteProject, onProjectUpdated, onProjectDeleted,
  reloadDeployments, applyRollout,
  loadEnvironments, selectEnvironment, rollbackTo,
  silentRefresh, init, journals,
} = useProjectDetail()

// Roll back to a deployment, with the config it ran with unless kept (#626)
const deploymentToRollBack = ref(null)
async function confirmRollback({ keepCurrentConfig }) {
  try {
    await rollbackTo(deploymentToRollBack.value, keepCurrentConfig)
    deploymentToRollBack.value = null
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('common.error'))
  }
}

function onEditorCodeUpdate({ file, content }) {
  selectedVersionFiles.value = { ...selectedVersionFiles.value, [file]: content }
}

const selectedDeployment       = ref(null)
const deploymentToDelete       = ref(null)

// Stop / resume and owner transfer (core; the reason of a stop is shown to every member)
const toast          = useToast()
const showStop       = ref(false)
const showOwner      = ref(false)
// Runtime patches (badge in the header): apply / pin / unpin
const showPatches    = ref(false)

function onPatchesChanged(state) {
  if (project.value) project.value = { ...project.value, runtime_patch: state }
}

function onProjectChanged(updated, message = null) {
  if (updated) project.value = { ...project.value, ...updated }
  showStop.value  = false
  showOwner.value = false
  if (message) toast.success(message)
}

// A platform administrator's stop of a project its organization stopped takes the stop over
function onStopped(updated) {
  const takenOver = !!project.value?.stopped
  onProjectChanged(updated, t(takenOver ? 'projectLifecycle.takenOver' : 'projectLifecycle.stopped'))
}

async function onResumed(updated) {
  onProjectChanged(updated)
  if (!updated) await silentRefresh(false)
}

// The clone opens as a page of its own (App.vue keys the view by the route params); the creator
// holds the project Admin role on it, so the permissions load again first
function onCloned(clone) {
  perms.load()
  router.push(`/projects/${clone.id}`)
}

function onDeploymentStatusChanged(newStatus) {
  if (selectedDeployment.value) {
    selectedDeployment.value = { ...selectedDeployment.value, status: newStatus }
  }
  const found = deployments.value.find(d => d.id === selectedDeployment.value?.id)
  if (found) found.status = newStatus
}

// ── Deploy strategy + rollouts (blue-green / canary) ───────────────────────
const showStrategy = ref(false)
// Tracing is turned on in the project's Traces tab (observability module)
const hasTracesTab = computed(() => tabs.value.some(tab => tab.id === 'traces'))

// The project's rollout above the deployments while it holds the traffic, and a while after it
// ended (why it was rolled back, failed, …)
const showCurrentRollout = computed(() => !!rollout.value && (holdsTraffic(rollout.value) || endedRecently(rollout.value)))

// The rollout of the deployment opened in the log panel: its own (the new deployment) or the
// project's current one it serves next to (stable / previous)
const panelRollout = computed(() => {
  const d = selectedDeployment.value
  if (!d) return null
  const live = deployments.value.find(x => x.id === d.id) ?? d
  const own = asRollout(live.rollout)
  const current = rollout.value
  if (own) return current?.id === own.id ? current : own
  return current && live.rollout_role && current.stable_deployment_id === d.id ? current : null
})

async function onRolloutUpdated(next, { action } = {}) {
  applyRollout(next)
  // an action moves the deployments too (statuses, roles): the list from the API
  if (action) {
    try { await reloadDeployments() } catch { /* the deployment.* events bring them */ }
  }
}

function onStrategySaved(settings) {
  if (!settings?.strategy) return
  // An environment's: its entry (the switcher, the table, the deploy modal read it)
  const env = settings.environment && settings.environment !== 'production' ? settings.environment : null
  if (env) {
    environments.value = environments.value.map(e => (!e.is_primary && e.name === env ? { ...e, deploy_strategy: settings.strategy } : e))
    return
  }
  if (project.value) project.value = { ...project.value, deploy_strategy: settings.strategy }
  // the environments that follow the project's settings follow it
  if (environments.value.length) loadEnvironments({ force: true })
}

function openTracing() {
  showStrategy.value = false
  showEdit.value     = false
  activeTab.value    = 'traces'
}

function onDeploymentDeleted(id) {
  deployments.value = deployments.value.filter(d => d.id !== id)
  if (selectedDeployment.value?.id === id) selectedDeployment.value = null
  deploymentToDelete.value = null
}

// ── Tutorial ────────────────────────────────────────────────────────────────
watch(() => tutorialStore.restartCounter, () => {
  const tab = activeTab.value
  if (tab === 'deployments') nextTick(() => startDeploymentsTutorial())
  else if (tab === 'metrics') nextTick(() => startMetricsTutorial())
  else nextTick(() => startVersionsTutorial())
})

// ── WS sync + fallback polling ──────────────────────────────────────────────
useFallbackPolling(() => silentRefresh(tutorialStore.tutorialActive), 90000)
usePlatformSync({ project, versions, deployments, rollout, journals, onProjectDeleted, environment })

// ── Init ────────────────────────────────────────────────────────────────────
onMounted(() => init())
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />

    <div v-if="loading" class="flex-1 flex items-center justify-center text-gray-400 text-sm">
      {{ t('common.loading') }}
    </div>

    <template v-else-if="project">
      <!-- ── Page header ─────────────────────────────────────────────────── -->
      <ProjectHeader
        :project="project"
        :region-label="regionLabel"
        :endpoint-url="endpointUrl"
        :tabs="tabs"
        v-model:active-tab="activeTab"
        :deploying="deploying"
        :has-versions="versions.length > 0"
        :is-runtime-eol="isRuntimeEol"
        @edit="showEdit = true"
        @clone="showClone = true"
        @delete="deleteProject"
        @deploy="openDeployModal"
        @stop="showStop = true"
        @transfer-owner="showOwner = true"
        @patches="showPatches = true"
      />

      <!-- ── Stopped project: reason for every member, resume for projects.stop ── -->
      <ProjectStopBanner :project="project" @resumed="onResumed" />

      <!-- ── Rightsizing: the size the last days call for; applying saves a new version ── -->
      <RightsizingCard v-if="!isAiProject && !project.stopped" :key="project.id" :project="project" @applied="silentRefresh(false)" />

      <!-- ── Retired project type (former AI types): view and delete only ── -->
      <div v-if="isAiProject" class="w-full px-6 pt-2 flex flex-wrap items-center gap-3">
        <LegacyTypeNotice />
      </div>

      <!-- ── Tab content ─────────────────────────────────────────────────── -->
      <div class="flex-1 w-full px-6 py-4">

        <!-- Versions ─────────────────────────────────────────────────────── -->
        <div v-if="activeTab === 'versions'">
          <div
            class="flex gap-4"
            :style="{ height: isAiProject ? 'calc(100vh - 295px)' : 'calc(100vh - 260px)', minHeight: '420px' }"
          >
            <!-- Left: version list -->
            <VersionList
              :versions="versions"
              :versions-loaded="versionsLoaded"
              :selected-version="selectedVersion"
              :config-mode="isAiProject ? (project.config?.config_mode ?? (project.service_type === 'maas' ? 'blueprint' : null)) : null"
              @select="loadVersionFiles"
            />

            <!-- Right: editor / viewer -->
            <EditorPanel
              :project="project"
              :files="selectedVersionFiles"
              :active-file="selectedVersionActiveFile"
              :is-editable="isEditable"
              :is-runtime-eol="isRuntimeEol"
              :saving="editSaving"
              :main-file="mainFile"
              :handler-name="handlerName"
              :handler-error="handlerError"
              :files-loading="versionFilesLoading"
              :commit-msg="commitMsg"
              :selected-version="selectedVersion"
              :snapshot-region-label="snapshotRegionLabel"
              :has-versions="versions.length > 0"
              @save="save"
              @add-file="addFile"
              @remove-file="removeFile"
              @upload-file="uploadFile"
              @update:active-file="selectedVersionActiveFile = $event"
              @update:code="onEditorCodeUpdate"
              @update:commit-msg="commitMsg = $event"
            />
          </div>
        </div>

        <!-- Deployments ─────────────────────────────────────────────────── -->
        <template v-if="activeTab === 'deployments'">
          <!-- The environment the table, the deploy modal and the logs follow (docs/environments.md) -->
          <EnvironmentSwitcher
            class="-mx-6"
            :environments="environments"
            :model-value="environment"
            @update:model-value="selectEnvironment"
          />
          <!-- The blue-green / canary rollout of the environment shown (each rolls out with its own strategy) -->
          <RolloutPanel
            v-if="showCurrentRollout"
            class="mb-5"
            :project="project"
            :rollout="rollout"
            poll
            data-test="current-rollout"
            @updated="onRolloutUpdated"
          />
          <DeploymentTable
            class="-mx-6"
            :deployments="deployments"
            :project="project"
            :current-rollout="rollout"
            :endpoint="environment ? (currentEnvironment?.url ?? null) : null"
            :strategy="environment ? (currentEnvironment?.deploy_strategy ?? null) : null"
            @view-logs="selectedDeployment = $event"
            @delete="deploymentToDelete = $event"
            @configure-strategy="showStrategy = true"
            @rollback="deploymentToRollBack = $event"
          />
        </template>

        <!-- Environments and promotion ──────────────────────────────────── -->
        <ProjectEnvironments
          v-if="activeTab === 'environments' && project"
          :project="project"
          @changed="loadEnvironments({ force: true })"
        />

        <!-- Global project: regions, status, DNS mode ──────────────────── -->
        <GlobalRegions
          v-if="activeTab === 'regions' && project"
          :project="project"
          @updated="silentRefresh(false)"
        />

        <!-- Metrics History ───────────────────────────────────────────── -->
        <HistoricalMetrics
          v-if="activeTab === 'metrics' && project"
          :project-id="project.id"
        />

        <!-- Custom domains ────────────────────────────────────────────── -->
        <ProjectDomains
          v-if="activeTab === 'domains' && project"
          :project-id="project.id"
        />

        <!-- Network: access mode, internal URL, allowed callers ──────────── -->
        <ProjectNetwork
          v-if="activeTab === 'network' && project"
          :project-id="project.id"
        />

        <!-- Private connections (Managed PostgreSQL via Skupper) — the selected environment's ─── -->
        <template v-if="activeTab === 'connections' && project">
          <EnvironmentSwitcher
            class="-mx-6"
            :environments="environments"
            :model-value="environment"
            @update:model-value="selectEnvironment"
          />
          <PrivateConnections
            :project="project"
            :environment="environment"
          />
        </template>

        <!-- Model services bound to the project (RUNR_MODEL_<ALIAS>_*) ───── -->
        <ProjectModels
          v-if="activeTab === 'models' && project"
          :project="project"
        />

        <!-- Project access (roles on this project only) ─────────────── -->
        <ProjectAccess
          v-if="activeTab === 'access' && project"
          :project-id="project.id"
        />

        <!-- Module tabs (moduleRegistry.projectTabs, e.g. Workflows) ─────── -->
        <!-- A tab with `environments: true` follows the environment switcher (docs/environments.md) -->
        <template v-for="tab in moduleRegistry.projectTabs" :key="tab.id">
          <template v-if="activeTab === tab.id && project">
            <EnvironmentSwitcher
              v-if="tab.environments"
              class="-mx-6"
              :environments="environments"
              :model-value="environment"
              @update:model-value="selectEnvironment"
            />
            <component
              :is="tab.component"
              :project="project"
              v-bind="tab.environments ? { environment } : {}"
              @project-changed="onResumed"
            />
          </template>
        </template>

      </div>
    </template>

    <!-- Stop a project (reason required) -->
    <ProjectStopModal
      v-if="showStop && project"
      :project="project"
      @stopped="onStopped"
      @close="showStop = false"
    />

    <!-- Runtime patches: why each came, apply / pin / unpin -->
    <RuntimePatchDialog
      v-if="showPatches && project"
      :project="project"
      @changed="onPatchesChanged"
      @close="showPatches = false"
    />

    <!-- Transfer the project to another owner -->
    <ProjectOwnerModal
      v-if="showOwner && project"
      :project="project"
      @transferred="(p) => onProjectChanged(p, t('projectLifecycle.transferred'))"
      @close="showOwner = false"
    />

    <!-- Edit modal -->
    <EditProjectModal
      v-if="showEdit && project"
      :project="project"
      :tracing-link="hasTracesTab"
      @updated="onProjectUpdated"
      @strategy-saved="onStrategySaved"
      @open-tracing="openTracing"
      @triggers-changed="(triggers) => onProjectChanged({ triggers })"
      @close="showEdit = false"
    />

    <!-- Deploy strategy (direct / blue-green / canary) of the environment shown, from the Deployments tab -->
    <DeployStrategyModal
      v-if="showStrategy && project"
      :project="project"
      :environment="environment"
      :tracing-link="hasTracesTab"
      @saved="onStrategySaved"
      @open-tracing="openTracing"
      @close="showStrategy = false"
    />

    <!-- Clone modal -->
    <CloneProjectModal
      v-if="showClone && project"
      :project="project"
      @created="onCloned"
      @close="showClone = false"
    />

    <!-- Deployment delete modal -->
    <DeploymentDeleteModal
      v-if="deploymentToDelete && project"
      :project="project"
      :deployment="deploymentToDelete"
      @deleted="onDeploymentDeleted"
      @close="deploymentToDelete = null"
    />

    <!-- Roll back to a deployment (keep the current configuration or not) -->
    <RollbackModal
      v-if="deploymentToRollBack && project"
      :deployment="deploymentToRollBack"
      :busy="deploying"
      @confirm="confirmRollback"
      @cancel="deploymentToRollBack = null"
    />

    <!-- Deployment log panel -->
    <DeploymentLogPanel
      v-if="selectedDeployment && project"
      :project="project"
      :deployment="selectedDeployment"
      :rollout="panelRollout"
      @close="selectedDeployment = null"
      @status-changed="onDeploymentStatusChanged"
      @rollout-updated="onRolloutUpdated"
    />

    <!-- Deploy refused by the supply-chain policy / data residency -->
    <PolicyBlockModal
      v-if="policyBlock"
      :block="policyBlock"
      :deploying="deploying"
      @override="(reason) => confirmDeploy({ versionId: policyBlock.versionId, overrideReason: reason, strategy: policyBlock.strategy, environment: policyBlock.environment, restoreConfig: policyBlock.restoreConfig })"
      @retry="confirmDeploy({ versionId: policyBlock.versionId, strategy: policyBlock.strategy, environment: policyBlock.environment, restoreConfig: policyBlock.restoreConfig })"
      @close="policyBlock = null"
    />

    <!-- Deploy modal -->
    <DeployModal
      v-if="showDeployModal"
      :project-name="project?.name"
      :versions="versions"
      :deploying="deploying"
      :strategy="projectStrategy(project)"
      :environments="environments"
      :environment="environment"
      @deploy="confirmDeploy"
      @cancel="showDeployModal = false"
    />

  </div>
</template>
