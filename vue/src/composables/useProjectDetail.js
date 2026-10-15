import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import { useRuntimesStore } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useProjectEditor } from '@/composables/useProjectEditor'
import { moduleRegistry } from '@/modules/index'

export function useProjectDetail() {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const settingsStore = useSettingsStore()
  const runtimesStore = useRuntimesStore()
  const { confirm } = useConfirm()

  // ── State ──────────────────────────────────────────────────────────────────
  const project   = ref(null)
  const loading   = ref(true)
  const activeTab = ref(null)

  // Versions
  const versions       = ref([])
  const versionsLoaded = ref(false)

  // Deployments
  const deployments       = ref([])
  const deploymentsLoaded = ref(false)
  const deploying         = ref(false)

  // Modals
  const showEdit        = ref(false)
  const showClone       = ref(false)
  const showDeployModal = ref(false)
  // Deploy refused by the supply-chain policy / data residency (422 body + the version)
  const policyBlock     = ref(null)

  // ── Loaders ────────────────────────────────────────────────────────────────
  async function loadProject() {
    loading.value = true
    try {
      const { data } = await projectsApi.get(route.params.id)
      project.value  = data.data ?? data
      const hash = route.hash?.replace('#', '')
      const known = ['versions', 'deployments', 'metrics', 'domains', 'connections', 'models', 'access', ...moduleProjectTabs.value.map(tab => tab.id)]
      activeTab.value = known.includes(hash) ? hash : 'versions'
    } finally {
      loading.value = false
    }
  }

  async function loadVersions() {
    if (versionsLoaded.value) return
    const { data } = await projectsApi.versions(project.value.id)
    versions.value       = data.data ?? data
    versionsLoaded.value = true
  }

  async function loadDeployments() {
    if (deploymentsLoaded.value) return
    const { data } = await projectsApi.deployments(project.value.id)
    deployments.value       = data.data ?? data
    deploymentsLoaded.value = true
  }

  // ── Editor composable ─────────────────────────────────────────────────────
  const editor = useProjectEditor({
    project, versions, versionsLoaded,
    loadVersionsFn: loadVersions,
  })

  // ── Computed ───────────────────────────────────────────────────────────────
  const endpointUrl = computed(() => project.value?.endpoint_url ?? null)

  const regionLabel = computed(() => {
    if (!project.value?.knative_region_id) return null
    return (settingsStore.data.regions ?? []).find(r => r.id === project.value.knative_region_id)?.name ?? null
  })

  const snapshotRegionLabel = computed(() => {
    const id = editor.selectedVersion.value?.config_snapshot?.knative_region_id
    if (!id) return null
    return (settingsStore.data.regions ?? []).find(r => r.id === id)?.name ?? id
  })

  const isAiProject = computed(() =>
    ['aaas', 'agentflow', 'maas'].includes(project.value?.service_type)
  )

  const moduleProjectTabs = computed(() =>
    (moduleRegistry.projectTabs ?? [])
      .filter(tab => !tab.visibleIf || (project.value && tab.visibleIf(project.value)))
      .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  )

  const tabs = computed(() => {
    if (!project.value) return []
    const base = [
      { id: 'versions',    label: t('project.tabs.versions') },
      { id: 'deployments', label: t('project.tabs.deployments') },
      { id: 'metrics',     label: t('metrics.history') },
      { id: 'domains',     label: t('project.tabs.domains') },
    ]
    // Retired AI types: view and delete only, no private connections
    // Project access (docs/permissions.md): who has a role on this project only
    const access = { id: 'access', label: t('rbac.accessTab') }
    if (isAiProject.value) return [...base, access]
    // Tabs contributed by modules (moduleRegistry.projectTabs, e.g. Workflows, Git)
    const extra = moduleProjectTabs.value.map(tab => ({ id: tab.id, label: t(tab.labelKey) }))
    // models: model services bound to the project (docs/huggingface.md)
    return [...base, { id: 'connections', label: t('privateConnections.tab') }, { id: 'models', label: t('ai.project.tab') }, ...extra, access]
  })

  watch(activeTab, (tab) => {
    if (tab === 'deployments') loadDeployments()
    router.replace({ query: route.query, hash: tab === 'versions' ? '' : `#${tab}` })
  })

  // ── Actions ────────────────────────────────────────────────────────────────
  async function openDeployModal() {
    if (!versionsLoaded.value) await loadVersions()
    showDeployModal.value = true
  }

  async function confirmDeploy({ versionId, overrideReason = null }) {
    deploying.value = true
    try {
      const payload = { version_id: versionId }
      // Supply-chain policy override (docs/supply-chain.md)
      if (overrideReason) Object.assign(payload, { override_policy: true, override_reason: overrideReason })
      await projectsApi.deploy(project.value.id, payload)
      // The new deployment must show up: drop the cached list and load it again
      deploymentsLoaded.value = false
      showDeployModal.value   = false
      policyBlock.value       = null
      if (activeTab.value === 'deployments') await loadDeployments()
      else activeTab.value = 'deployments' // the tab watcher loads the list
    } catch (e) {
      const body = e?.response?.data
      if (e?.response?.status === 422 && ['supply_chain_policy', 'supply_chain_scan_pending', 'region_not_allowed'].includes(body?.code)) {
        showDeployModal.value = false
        policyBlock.value     = { ...body, versionId }
        return
      }
      throw e
    } finally {
      deploying.value = false
    }
  }

  async function deleteProject() {
    const ok = await confirm({
      title:       t('project.delete'),
      message:     t('project.deleteConfirm', { name: project.value.name }),
      destructive: true,
    })
    if (!ok) return
    await projectsApi.destroy(project.value.id)
    router.push('/projects')
  }

  async function onProjectUpdated(p) {
    project.value        = p
    showEdit.value       = false
    versionsLoaded.value = false
    editor.selectedVersion.value = null
    await loadVersions()
    if (versions.value.length) {
      editor.loadVersionFiles(versions.value[0])
    } else if (!['caas', 'maas'].includes(project.value?.service_type)) {
      await editor.loadDefaultCode()
    }
  }

  // ── Unsaved changes guard ─────────────────────────────────────────────────
  function handleBeforeUnload(e) {
    if (editor.isDirty.value) {
      e.preventDefault()
      e.returnValue = ''
    }
  }

  onMounted(() => window.addEventListener('beforeunload', handleBeforeUnload))
  onUnmounted(() => window.removeEventListener('beforeunload', handleBeforeUnload))

  onBeforeRouteLeave(async () => {
    if (!editor.isDirty.value) return true
    return await confirm({
      title:        t('project.unsaved.title'),
      message:      t('project.unsaved.message'),
      confirmLabel: t('project.unsaved.leave'),
    })
  })

  // ── Silent refresh + polling ──────────────────────────────────────────────
  async function silentRefresh(tutorialActive) {
    if (!project.value) return
    if (tutorialActive) return

    const [pRes, vRes] = await Promise.all([
      projectsApi.get(route.params.id),
      projectsApi.versions(project.value.id),
      settingsStore.fetch(),
    ])
    project.value  = pRes.data.data ?? pRes.data
    versions.value = vRes.data.data ?? vRes.data

    if (deploymentsLoaded.value) {
      const dRes = await projectsApi.deployments(project.value.id)
      deployments.value = dRes.data.data ?? dRes.data
    }
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  async function init() {
    if (!settingsStore.loaded) settingsStore.fetch()
    // Runtime catalog: handler file, handler check and editor language of the project runtime
    await Promise.all([loadProject(), runtimesStore.fetch()])
    await loadVersions()
    if (versions.value.length) {
      editor.loadVersionFiles(versions.value[0])
    } else if (!['caas', 'maas'].includes(project.value?.service_type)) {
      await editor.loadDefaultCode()
    }
  }

  return {
    // State
    project, loading, activeTab,
    versions, versionsLoaded,
    ...editor,
    deployments, deploymentsLoaded, deploying,
    showEdit, showClone, showDeployModal, policyBlock,

    // Computed
    endpointUrl, regionLabel, snapshotRegionLabel,
    tabs, isAiProject,

    // Methods
    openDeployModal, confirmDeploy, deleteProject, onProjectUpdated,
    silentRefresh, init,
  }
}
