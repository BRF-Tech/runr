import { ref, computed, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useConfirm } from '@/composables/useConfirm'
import { useInstallPopup } from '@/composables/useInstallPopup'
import { useRegistrySetup } from '@/composables/useRegistrySetup'
import { useRegionStatus } from '@/composables/useRegionStatus'
import { moduleRegistry } from '@/modules/index'

export function useRegionDetail(props, emit) {
  const { t } = useI18n()
  const { confirm } = useConfirm()

  const isEdit = computed(() => !!props.region?.id)
  const wizardStep = ref(1)
  const wizardStepTitles = computed(() => [
    t('settings.regionDetail.wizard.step1Title'),
    t('settings.regionDetail.wizard.step2Title'),
    t('settings.regionDetail.wizard.step3Title'),
    t('settings.regionDetail.wizard.step4Title'),
    t('settings.regionDetail.wizard.step5Title'),
    t('settings.regionDetail.wizard.step6Title'),
    t('settings.regionDetail.wizard.step7Title'),
  ])

  const activeTab = ref('general')
  const editTabs = ['general', 'workers', 'registry', 'operator', 'serving', 'redis', 'data', 'secrets', 'sandbox', 'gpu', 'migration', 'supplyChain']
  // Tabs contributed by modules (moduleRegistry.regionTabs, e.g. Billing)
  const moduleRegionTabs = computed(() =>
    (moduleRegistry.regionTabs ?? [])
      .filter(tab => !tab.visibleIf || tab.visibleIf(props.region))
      .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  )

  // Fields modules add to the wizard's first step (moduleRegistry.regionCreateExtensions): their
  // values live in form.extensions[key] and go to the module's afterCreate once the region exists
  const initialExtensions = () => Object.fromEntries(
    (moduleRegistry.regionCreateExtensions ?? []).map(ext => [ext.key, ext.initial ? ext.initial() : null])
  )

  const createdRegion = ref(null)
  const highestStepReached = ref(1)
  const isStepReadonly = computed(() => wizardStep.value < highestStepReached.value)
  const regionId = computed(() => createdRegion.value?.id ?? props.region?.id)

  const form = ref({ name: '', is_active: true, region_status: 'active', region_mode: 'managed', extensions: initialExtensions() })
  const kubeconfigName  = ref('')
  const kubeconfigMode  = ref('file')
  const kubeconfigPaste = ref('')
  const saving  = ref(false)
  const error   = ref(null)

  const operatorStatus  = ref('not_installed')
  const operatorMessage = ref('')
  const servingStatus   = ref('not_installed')
  const servingMessage  = ref('')

  const nsOperator  = ref('knative-operator')
  const nsServing   = ref('knative-serving')
  const nsCheckResult = ref({})
  const nsChecking    = ref({})
  const servingVersion  = ref(null)

  const projectNamespace = computed(() => {
    if (isEdit.value) return props.region?.project_namespace || props.region?.name || ''
    return createdRegion.value?.project_namespace || form.value.name.trim()
  })

  const nodes         = ref([])
  const nodesLoading  = ref(false)
  const selectedNodes = ref([])
  const workersSaving = ref(false)
  const workersError  = ref(null)
  const storageClasses = ref([])

  const registry = useRegistrySetup()
  const redisMode     = ref(null)
  const redisAddress  = ref('')
  const redisPassword = ref('')
  const redisStatus   = ref(null)

  const popup = useInstallPopup({
    regionId, isEdit, createdRegion, emit,
    operatorStatus, operatorMessage, servingStatus, servingMessage,
    nsOperator, nsServing,
    servingVersion,
    redisStatus, redisAddress, redisMode, redisPassword,
    t, confirm,
  })

  const regionStatus = useRegionStatus(regionId, form, emit, confirm, t)

  // A region whose cluster does not answer opens on its Migration tab (moving it elsewhere is
  // what can be done for it)
  let tabChosen = false
  watch(() => props.region, (r) => {
    if (r && !tabChosen) {
      tabChosen = true
      if (r.id && r.cluster_status === 'unreachable') activeTab.value = 'migration'
    }
    if (r) {
      form.value = { name: r.name || '', is_active: r.is_active ?? true, region_status: r.region_status || 'active', region_mode: r.region_mode || 'managed' }
      operatorStatus.value  = r.knative_operator_status || 'not_installed'
      operatorMessage.value = r.knative_operator_status_message || ''
      servingStatus.value   = r.knative_serving_status || 'not_installed'
      servingMessage.value  = r.knative_serving_status_message || ''
      nsOperator.value      = r.knative_operator_namespace || 'knative-operator'
      nsServing.value       = r.knative_serving_namespace || 'knative-serving'
      servingVersion.value  = r.knative_serving_version || null
      selectedNodes.value   = [...(r.selected_workers || [])]
      redisMode.value       = r.redis_mode || null
      redisAddress.value    = r.redis_address || ''
      redisStatus.value     = r.knative_redis_status || null
    }
  }, { immediate: true })

  onUnmounted(() => { popup.cleanup(); registry.reset() })

  const canSaveGeneral = computed(() => form.value.name.trim().length >= 2)
  const dnsInstruction = computed(() => {
    const r = isEdit.value ? props.region : createdRegion.value
    return r?.dns_instruction ?? null
  })

  async function onKubeconfigChange(e) {
    const file = e.target.files?.[0]
    if (!file) return
    kubeconfigName.value = file.name; form.value.kubeconfig = await file.text()
  }

  function resolveKubeconfig() {
    return kubeconfigMode.value === 'paste' ? kubeconfigPaste.value.trim() : form.value.kubeconfig
  }

  async function saveGeneral() {
    error.value = null
    const kc = resolveKubeconfig()

    if (isEdit.value) {
      if (!kc) return
      saving.value = true
      try {
        const result = await knativeRegionsApi.update(regionId.value, { kubeconfig: kc })
        emit('saved', result.data.data ?? result.data)
      } catch (e) {
        error.value = e.response?.data?.message
          ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
          ?? t('common.error')
      } finally { saving.value = false }
      return
    }

    if (!kc) { error.value = t('settings.regions.kubeconfigRequired'); return }
    saving.value = true
    try {
      const validateRes = await knativeRegionsApi.validateKubeconfig({ kubeconfig: kc })
      if (validateRes.data.kubeconfig) {
        if (kubeconfigMode.value === 'paste') kubeconfigPaste.value = validateRes.data.kubeconfig
        else form.value.kubeconfig = validateRes.data.kubeconfig
      }
      // Region name = project namespace — check if it already exists on cluster
      const nsName = form.value.name.trim()
      const { data } = await knativeRegionsApi.checkNamespacePreview({ kubeconfig: kc, name: nsName })
      if (data.exists) {
        error.value = t('settings.regionDetail.namespaceConflict', { name: nsName })
        return
      }
      wizardStep.value = 2
    } catch (e) {
      error.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
    } finally { saving.value = false }
  }

  async function createRegion() {
    workersSaving.value = true; workersError.value = null
    const kc = resolveKubeconfig()
    await registry.startSetup({ name: form.value.name, kubeconfig: kc, nodes: selectedNodes.value, region_mode: form.value.region_mode })
    if (registry.setupError.value) { workersError.value = registry.setupError.value; workersSaving.value = false }
  }

  watch(() => registry.setupStatus.value, (s) => {
    if (s === 'ready' && registry.setupRegionId.value) {
      knativeRegionsApi.list().then(({ data }) => {
        const found = (data.data ?? data).find(r => r.id === registry.setupRegionId.value)
        if (found) { createdRegion.value = found; wizardStep.value = 3; runCreateExtensions(found) }
      }).finally(() => { workersSaving.value = false })
    }
    if (s === 'error') { workersError.value = registry.setupError.value; workersSaving.value = false }
  })

  /** Hands the new region to the modules' afterCreate (a failure does not stop the wizard). */
  async function runCreateExtensions(region) {
    for (const ext of moduleRegistry.regionCreateExtensions ?? []) {
      if (typeof ext.afterCreate !== 'function') continue
      try {
        await ext.afterCreate(region, form.value.extensions?.[ext.key] ?? null)
      } catch (e) {
        workersError.value = e?.response?.data?.message ?? t('common.error')
      }
    }
  }

  async function checkNs(key, name) {
    if (!name.trim()) return
    nsChecking.value = { ...nsChecking.value, [key]: true }
    nsCheckResult.value = { ...nsCheckResult.value, [key]: null }
    try {
      let data
      if (regionId.value) {
        const res = await knativeRegionsApi.checkNamespace(regionId.value, { name })
        data = res.data
      } else {
        const kc = resolveKubeconfig()
        const res = await knativeRegionsApi.checkNamespacePreview({ kubeconfig: kc, name })
        data = res.data
      }
      nsCheckResult.value = { ...nsCheckResult.value, [key]: data.exists ? 'exists' : 'available' }
    } catch { nsCheckResult.value = { ...nsCheckResult.value, [key]: null } }
    finally { nsChecking.value = { ...nsChecking.value, [key]: false } }
  }

  async function loadNodes() {
    nodesLoading.value = true
    workersError.value = null
    try {
      let data
      if (regionId.value) {
        const res = await knativeRegionsApi.listNodes(regionId.value)
        data = res.data
      } else {
        const kc = resolveKubeconfig()
        const [nodesRes, scRes] = await Promise.all([
          knativeRegionsApi.previewNodes({ kubeconfig: kc }),
          knativeRegionsApi.previewStorageClasses({ kubeconfig: kc }).catch(() => null),
        ])
        data = nodesRes.data
        if (scRes?.data?.storageClasses) {
          storageClasses.value = scRes.data.storageClasses
          const def = scRes.data.storageClasses.find(sc => sc.is_default)
          if (def && !registry.pvcConfig.value.storageClass) {
            registry.pvcConfig.value = { ...registry.pvcConfig.value, storageClass: def.name }
          }
        }
      }
      nodes.value = data.nodes || []
      // Auto-select nodes with existing knative-worker role
      if (!isEdit.value && !selectedNodes.value.length) selectedNodes.value = nodes.value.filter(n => n.is_selected).map(n => n.name)
    } catch (e) {
      workersError.value = e.response?.data?.message ?? t('common.error')
    } finally { nodesLoading.value = false }
  }

  function toggleNode(name) {
    const i = selectedNodes.value.indexOf(name)
    i === -1 ? selectedNodes.value.push(name) : selectedNodes.value.splice(i, 1)
  }

  async function saveWorkers() {
    workersSaving.value = true
    workersError.value  = null
    try {
      const { data } = await knativeRegionsApi.applyWorkers(regionId.value, { nodes: selectedNodes.value })
      const regionData = data.region?.data ?? data.region
      if (!isEdit.value) { createdRegion.value = regionData; wizardStep.value = 3 }
      else emit('saved', regionData)
    } catch (e) {
      workersError.value = e.response?.data?.message ?? t('common.error')
    } finally { workersSaving.value = false }
  }

  async function pollRegionDemoStatus() {
    if (!regionId.value) return
    try { const { data } = await knativeRegionsApi.componentStatus(regionId.value); const f = data.data ?? data; if (f.region_demo_status !== undefined) emit('saved', f) } catch { /* silent */ }
  }

  function finishWizard() { emit('saved', createdRegion.value); emit('close') }

  watch(activeTab, (tab) => {
    if (tab === 'workers' && isEdit.value && nodes.value.length === 0) loadNodes()
    if (tab === 'redis' && isEdit.value) popup.initRedisPopup()
  })
  watch(wizardStep, (step) => {
    if (step > highestStepReached.value) highestStepReached.value = step
    if (step === 2 && !nodes.value.length) loadNodes()
    if (step === 3 && regionId.value) knativeRegionsApi.detectComponents(regionId.value).then(({ data }) => {
      if (data.operator?.adopted) { operatorStatus.value = 'ready'; nsOperator.value = data.operator.namespace }
      if (data.serving?.adopted) { servingStatus.value = 'ready'; nsServing.value = data.serving.namespace }
      const rd = data.region?.data ?? data.region
      if (rd) { servingVersion.value = rd.knative_serving_version || null; if (!isEdit.value) createdRegion.value = rd; emit('saved', rd) }
    }).catch(() => {})
    if (step === 5) popup.initRedisPopup()
  })

  return {
    isEdit, wizardStep, moduleRegionTabs, runCreateExtensions, wizardStepTitles, activeTab, editTabs,
    createdRegion, highestStepReached, isStepReadonly, regionId,
    form, kubeconfigName, kubeconfigMode, kubeconfigPaste,
    saving, error, canSaveGeneral,
    operatorStatus, operatorMessage, servingStatus, servingMessage,
    nsOperator, nsServing, nsCheckResult, nsChecking,
    servingVersion, projectNamespace,
    nodes, nodesLoading, selectedNodes, workersSaving, workersError, storageClasses,
    redisMode, redisAddress, redisPassword, redisStatus, registry, dnsInstruction,
    changeRegionStatus: regionStatus.changeRegionStatus,
    togglingStatus: regionStatus.togglingStatus,
    statusError: regionStatus.statusError,
    onKubeconfigChange, saveGeneral, createRegion,
    checkNs, loadNodes, toggleNode, saveWorkers, finishWizard, confirm, pollRegionDemoStatus,
    ...popup,
  }
}
