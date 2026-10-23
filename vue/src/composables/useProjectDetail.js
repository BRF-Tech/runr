import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { environmentsApi } from '@/api/environments'
import { useSettingsStore } from '@/stores/settings'
import { useRuntimesStore } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useProjectEditor } from '@/composables/useProjectEditor'
import { useLatest } from '@/composables/useLatest'
import { createChangeJournal } from '@/utils/changeJournal'
import { moduleRegistry } from '@/modules/index'
import { asRollout, applyRolloutUpdate } from '@/utils/deployStrategy'
import { isGlobalProject } from '@/utils/globalProjects'

export function useProjectDetail() {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const settingsStore = useSettingsStore()
  const runtimesStore = useRuntimesStore()
  const { confirm } = useConfirm()
  const toast = useToast()

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
  // The newest blue-green / canary rollout of the selected environment (production: the
  // project's own workload; null: it never had one)
  const rollout           = ref(null)

  // Environments (docs/environments.md): the pipeline with each one's live deployment, and the
  // one the deployments tab, the deploy modal and the logs follow (null: production, the
  // project's own workload)
  const environments          = ref([])
  const environmentsLoaded    = ref(false)
  const environmentAbilities  = ref({ manage: false, promote: false, approve: false })
  const environment           = ref(null)

  // Modals
  const showEdit        = ref(false)
  const showClone       = ref(false)
  const showDeployModal = ref(false)
  // Deploy refused by the supply-chain policy / data residency (422 body + the version)
  const policyBlock     = ref(null)

  // Background refresh (fallback polling): its answers were read before a save, a deploy or a
  // reload that started after it, so they are dropped once one of those starts
  const refreshes = useLatest()

  // Websocket changes to the versions and deployments (usePlatformSync records them): a reload
  // in flight when one arrives applies it to its answer (a deployment's status that changed
  // while the list was reloading after a deploy is not lost)
  const journals = { versions: createChangeJournal(), deployments: createChangeJournal() }

  // ── Loaders ────────────────────────────────────────────────────────────────
  async function loadProject() {
    loading.value = true
    try {
      const { data } = await projectsApi.get(route.params.id)
      project.value  = data.data ?? data
      const hash = route.hash?.replace('#', '')
      const known = [
        'versions', 'deployments', ...(isGlobalProject(project.value) ? ['regions'] : []),
        'environments', 'metrics', 'domains', 'network', 'connections', 'models', 'access', ...moduleProjectTabs.value.map(tab => tab.id),
      ]
      activeTab.value = known.includes(hash) ? hash : 'versions'
    } finally {
      loading.value = false
    }
  }

  async function loadVersions() {
    if (versionsLoaded.value) return
    refreshes.cancel()
    const since = journals.versions.mark()
    const { data } = await projectsApi.versions(project.value.id)
    versions.value       = journals.versions.replay(data.data ?? data, since)
    versionsLoaded.value = true
  }

  // The newest list wins: a list read before a deploy must not land after the one read after it
  const deploymentsRequest = useLatest()
  async function loadDeployments() {
    if (deploymentsLoaded.value) return
    await reloadDeployments()
  }

  // The list of the selected environment with its current rollout (also after a rollout action:
  // the statuses and roles of the deployments changed with it)
  async function reloadDeployments() {
    refreshes.cancel()
    const current = deploymentsRequest.begin()
    const since = journals.deployments.mark()
    const [{ data }] = await Promise.all([listDeployments(project.value.id), loadRollout()])
    if (!current()) return
    deployments.value       = journals.deployments.replay(data.data ?? data, since)
    deploymentsLoaded.value = true
  }

  // The deployments of the selected environment (production: the project's own workload)
  function listDeployments(id) {
    return environment.value ? projectsApi.deployments(id, environment.value) : projectsApi.deployments(id)
  }

  // Each environment rolls out with its own strategy (docs/deploy-strategies.md#environments):
  // the rollout of the environment selected now — an answer for the one left is dropped
  async function loadRollout() {
    if (!project.value) return
    const env = environment.value
    try {
      const { data } = env ? await projectsApi.rollout(project.value.id, env) : await projectsApi.rollout(project.value.id)
      if (environment.value === env) rollout.value = asRollout(data?.data)
    } catch { /* an API without rollouts: none */ }
  }

  // A rollout answer (action, poll): the current rollout and the deployment that carries it
  function applyRollout(next, status = null) {
    applyRolloutUpdate({ rollout, deployments }, next, status)
  }

  /** The project's environments (production included) with their live deployments; quiet on failure. */
  async function loadEnvironments({ force = false } = {}) {
    if (environmentsLoaded.value && !force) return
    try {
      const { data } = await environmentsApi.list(project.value.id)
      environments.value         = data.data ?? []
      environmentAbilities.value = { manage: false, promote: false, approve: false, ...(data.abilities ?? {}) }
      environmentsLoaded.value   = true
      // The selected environment was deleted meanwhile: back to production
      if (environment.value && !environments.value.some(e => e.name === environment.value)) environment.value = null
    } catch {
      environments.value = []
    }
  }

  /** Switch the deployments tab (and the deploy modal) to another environment. */
  async function selectEnvironment(name) {
    const next = !name || name === 'production' ? null : name
    if (next === environment.value) return
    environment.value       = next
    deploymentsLoaded.value = false
    deployments.value       = []
    rollout.value           = null
    // a list of the environment left that is still loading must not land in this one
    deploymentsRequest.cancel()
    if (activeTab.value === 'deployments') await loadDeployments()
  }

  // The selected environment's own entry (its URL, region, live version)
  const currentEnvironment = computed(() =>
    environments.value.find(e => (environment.value ? e.name === environment.value : e.is_primary)) ?? null
  )

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
      // Global project: its regions, their status and the DNS mode (docs/global-projects.md)
      ...(isGlobalProject(project.value) ? [{ id: 'regions', label: t('globalProjects.tab') }] : []),
      { id: 'metrics',     label: t('metrics.history') },
      { id: 'domains',     label: t('project.tabs.domains') },
      // access mode and internal callers (docs/internal-network.md)
      { id: 'network',     label: t('network.tab') },
    ]
    // Environments and promotion (docs/environments.md): projects that deploy
    const envTab = { id: 'environments', label: t('environments.tab') }
    // Retired AI types: view and delete only, no private connections
    // Project access (docs/permissions.md): who has a role on this project only
    const access = { id: 'access', label: t('rbac.accessTab') }
    if (isAiProject.value) return [...base, access]
    // Tabs contributed by modules (moduleRegistry.projectTabs, e.g. Workflows, Git)
    const extra = moduleProjectTabs.value.map(tab => ({ id: tab.id, label: t(tab.labelKey) }))
    // models: model services bound to the project (docs/huggingface.md)
    return [...base.slice(0, 2), envTab, ...base.slice(2), { id: 'connections', label: t('privateConnections.tab') }, { id: 'models', label: t('ai.project.tab') }, ...extra, access]
  })

  watch(activeTab, (tab) => {
    if (tab === 'deployments') {
      loadDeployments()
      loadEnvironments()
    }
    if (tab === 'environments') loadEnvironments({ force: true })
    // private connections and module tabs with `environments` (e.g. Workflows) follow the
    // environment switcher (docs/environments.md)
    if (tab === 'connections' || moduleProjectTabs.value.some(m => m.id === tab && m.environments)) loadEnvironments()
    router.replace({ query: route.query, hash: tab === 'versions' ? '' : `#${tab}` })
  })

  // ── Actions ────────────────────────────────────────────────────────────────
  async function openDeployModal() {
    if (!versionsLoaded.value) await loadVersions()
    await loadEnvironments()
    showDeployModal.value = true
  }

  /**
   * environment: the environment to deploy to (default: the selected one; null / 'production':
   * the project's own workload). restoreConfig: an older version runs with the config it ran
   * with (docs/versioning.md#rollback). strategy: a one-off deploy strategy (the project's own
   * workload; docs/deploy-strategies.md).
   */
  async function confirmDeploy({ versionId, overrideReason = null, strategy = null, environment: target = undefined, restoreConfig = false }) {
    deploying.value = true
    const env = target === undefined ? environment.value : (target === 'production' ? null : target)
    try {
      const payload = { version_id: versionId }
      if (env) payload.environment = env
      if (restoreConfig) payload.restore_config = true
      // Supply-chain policy override (docs/supply-chain.md)
      if (overrideReason) Object.assign(payload, { override_policy: true, override_reason: overrideReason })
      // One-off deploy strategy instead of the project's (e.g. direct for an emergency fix)
      if (strategy) payload.strategy = strategy
      const data = (await projectsApi.deploy(project.value.id, payload))?.data
      // Deployed: the modal closes now; the reloads below are not part of the deploy, a failed
      // one must not keep the modal open next to the success (and invite a second deploy)
      showDeployModal.value   = false
      policyBlock.value       = null
      // A version built before the region moved to another cluster is built again for the
      // region's registry first: no deployment yet, it starts when the build is ready
      if (data?.queued_deploy) {
        const version = data.queued_deploy.version ?? versions.value.find(v => v.id === versionId)?.number ?? '?'
        toast.success(t('project.deployRebuilding', { version }), 8000)
        versionsLoaded.value = false
        try { await loadVersions() } catch { /* the versions refresh with the next event */ }
      }
      // The new deployment must show up: drop the cached list and load it again (the deployments
      // tab follows the environment deployed to)
      if (env !== environment.value) {
        environment.value = env
        deployments.value = []
      }
      deploymentsLoaded.value  = false
      environmentsLoaded.value = false
      deploymentsRequest.cancel()
      if (activeTab.value === 'deployments') {
        try { await Promise.all([loadDeployments(), loadEnvironments()]) } catch { /* the list refreshes with the next event */ }
      } else activeTab.value = 'deployments' // the tab watcher loads the list
    } catch (e) {
      const body = e?.response?.data
      if (e?.response?.status === 422 && ['supply_chain_policy', 'supply_chain_scan_pending', 'region_not_allowed'].includes(body?.code)) {
        showDeployModal.value = false
        policyBlock.value     = { ...body, versionId, strategy, environment: env ?? 'production', restoreConfig }
        return
      }
      throw e
    } finally {
      deploying.value = false
    }
  }

  // This page deletes the project: the project.deleted broadcast (which can arrive before the
  // DELETE answers) leaves the page as it is until the navigation to the projects list
  let deleting = false

  /**
   * Roll back to a deployment: its version in its environment, with the config it ran with
   * unless keepCurrentConfig (#626, docs/versioning.md#rollback).
   */
  async function rollbackTo(deployment, keepCurrentConfig = false) {
    deploying.value = true
    try {
      const data = (await projectsApi.rollback(project.value.id, deployment.id, keepCurrentConfig))?.data
      if (data?.queued_deploy) {
        toast.success(t('project.deployRebuilding', { version: data.queued_deploy.version ?? deployment.version?.label ?? '?' }), 8000)
      } else {
        toast.success(t('environments.rollback.done', { version: deployment.version?.label ?? '?' }))
      }
      deploymentsLoaded.value  = false
      environmentsLoaded.value = false
      await Promise.all([loadDeployments(), loadEnvironments()])
      return data
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
    deleting = true
    try {
      await projectsApi.destroy(project.value.id)
    } catch (e) {
      deleting = false
      throw e
    }
    router.push('/projects')
  }

  /** project.deleted for the project shown: deleted elsewhere (another tab or user) → the list. */
  function onProjectDeleted() {
    if (deleting) return
    toast.show(t('project.deletedElsewhere', { name: project.value?.name ?? '' }), 'warning', 5000)
    router.push('/projects')
  }

  async function onProjectUpdated(p) {
    refreshes.cancel()
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

  async function confirmLeave() {
    if (!editor.isDirty.value) return true
    return await confirm({
      title:        t('project.unsaved.title'),
      message:      t('project.unsaved.message'),
      confirmLabel: t('project.unsaved.leave'),
    })
  }

  onBeforeRouteLeave(confirmLeave)
  // Another project on the same route (a clone, a notification, a link): the page is left too
  onBeforeRouteUpdate((to, from) => (to.params.id === from.params.id ? true : confirmLeave()))

  // ── Silent refresh + polling ──────────────────────────────────────────────
  async function silentRefresh(tutorialActive) {
    if (!project.value) return
    if (tutorialActive) return
    const current = refreshes.begin()
    const id = project.value.id
    const versionsSince = journals.versions.mark()
    const deploymentsSince = journals.deployments.mark()

    const [pRes, vRes] = await Promise.all([
      projectsApi.get(id),
      projectsApi.versions(id),
      settingsStore.fetch(),
    ])
    if (!current() || project.value?.id !== id) return
    project.value  = pRes.data.data ?? pRes.data
    versions.value = journals.versions.replay(vRes.data.data ?? vRes.data, versionsSince)

    if (deploymentsLoaded.value) {
      const [dRes] = await Promise.all([listDeployments(id), loadRollout()])
      if (current() && deploymentsLoaded.value) {
        deployments.value = journals.deployments.replay(dRes.data.data ?? dRes.data, deploymentsSince)
      }
    }
    if (environmentsLoaded.value) await loadEnvironments({ force: true })
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
    deployments, deploymentsLoaded, deploying, rollout,
    environments, environmentsLoaded, environmentAbilities, environment, currentEnvironment,
    showEdit, showClone, showDeployModal, policyBlock,

    // Computed
    endpointUrl, regionLabel, snapshotRegionLabel,
    tabs, isAiProject,

    // Methods
    openDeployModal, confirmDeploy, deleteProject, onProjectUpdated, onProjectDeleted,
    loadRollout, reloadDeployments, applyRollout,
    loadEnvironments, selectEnvironment, rollbackTo,
    silentRefresh, init,

    // usePlatformSync records its websocket changes here
    journals,
  }
}
