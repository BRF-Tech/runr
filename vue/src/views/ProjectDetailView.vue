<script setup>
import { ref, onMounted, watch, nextTick } from 'vue'
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
import HistoricalMetrics from '@/components/project/HistoricalMetrics.vue'
import LegacyTypeNotice from '@/components/project/LegacyTypeNotice.vue'
import ProjectDomains from '@/components/project/ProjectDomains.vue'
import PrivateConnections from '@/components/project/PrivateConnections.vue'
import ProjectModels from '@/components/project/ProjectModels.vue'
import ProjectAccess from '@/components/rbac/ProjectAccess.vue'
import ProjectStopBanner from '@/components/project/ProjectStopBanner.vue'
import ProjectStopModal from '@/components/project/ProjectStopModal.vue'
import ProjectOwnerModal from '@/components/project/ProjectOwnerModal.vue'
import { useToast } from '@/composables/useToast'
import { moduleRegistry } from '@/modules/index'
import PolicyBlockModal from '@/modules/supply-chain/components/PolicyBlockModal.vue'

const { t } = useI18n()
const router = useRouter()
const tutorialStore = useTutorialStore()
const { start: startVersionsTutorial }    = useDetailVersionsTutorial()
const { start: startDeploymentsTutorial } = useDetailDeploymentsTutorial()
const { start: startMetricsTutorial }     = useDetailMetricsTutorial()

const {
  // State
  project, loading, activeTab,
  versions, versionsLoaded,
  selectedVersion, selectedVersionFiles, selectedVersionActiveFile,
  versionFilesLoading, commitMsg, editSaving,
  deployments, deploying,
  showEdit, showClone, showDeployModal, policyBlock,
  handlerError,
  // Computed
  endpointUrl, regionLabel, snapshotRegionLabel,
  isEditable, isRuntimeEol, mainFile, handlerName, tabs, isAiProject,
  // Methods
  loadVersionFiles, save, addFile, removeFile, uploadFile,
  openDeployModal, confirmDeploy, deleteProject, onProjectUpdated,
  silentRefresh, init,
} = useProjectDetail()

function onEditorCodeUpdate({ file, content }) {
  selectedVersionFiles.value = { ...selectedVersionFiles.value, [file]: content }
}

const selectedDeployment       = ref(null)
const deploymentToDelete       = ref(null)

// Stop / resume and owner transfer (core; the reason of a stop is shown to every member)
const toast          = useToast()
const showStop       = ref(false)
const showOwner      = ref(false)

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

function onDeploymentStatusChanged(newStatus) {
  if (selectedDeployment.value) {
    selectedDeployment.value = { ...selectedDeployment.value, status: newStatus }
  }
  const found = deployments.value.find(d => d.id === selectedDeployment.value?.id)
  if (found) found.status = newStatus
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
usePlatformSync({ project, versions, deployments })

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
      />

      <!-- ── Stopped project: reason for every member, resume for projects.stop ── -->
      <ProjectStopBanner :project="project" @resumed="onResumed" />

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
        <DeploymentTable
          v-if="activeTab === 'deployments'"
          class="-mx-6"
          :deployments="deployments"
          :project="project"
          @view-logs="selectedDeployment = $event"
          @delete="deploymentToDelete = $event"
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

        <!-- Private connections (Managed PostgreSQL via Skupper) ─────────── -->
        <PrivateConnections
          v-if="activeTab === 'connections' && project"
          :project="project"
        />

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
        <template v-for="tab in moduleRegistry.projectTabs" :key="tab.id">
          <component :is="tab.component" v-if="activeTab === tab.id && project" :project="project" />
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
      @updated="onProjectUpdated"
      @close="showEdit = false"
    />

    <!-- Clone modal -->
    <CloneProjectModal
      v-if="showClone && project"
      :project="project"
      @created="(p) => router.push(`/projects/${p.id}`)"
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

    <!-- Deployment log panel -->
    <DeploymentLogPanel
      v-if="selectedDeployment && project"
      :project="project"
      :deployment="selectedDeployment"
      @close="selectedDeployment = null"
      @status-changed="onDeploymentStatusChanged"
    />

    <!-- Deploy refused by the supply-chain policy / data residency -->
    <PolicyBlockModal
      v-if="policyBlock"
      :block="policyBlock"
      :deploying="deploying"
      @override="(reason) => confirmDeploy({ versionId: policyBlock.versionId, overrideReason: reason })"
      @retry="confirmDeploy({ versionId: policyBlock.versionId })"
      @close="policyBlock = null"
    />

    <!-- Deploy modal -->
    <DeployModal
      v-if="showDeployModal"
      :project-name="project?.name"
      :versions="versions"
      :deploying="deploying"
      @deploy="confirmDeploy"
      @cancel="showDeployModal = false"
    />

  </div>
</template>
