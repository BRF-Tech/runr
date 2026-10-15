import { ref, computed } from 'vue'
import { wasmApi } from '@/api/wasm'
import { useJobEvents } from '@/composables/useJobEvents'

/**
 * WebAssembly support of a region (Sandbox tab → WebAssembly card): status, shims, node
 * selection, install / change / uninstall. Progress comes as install.progress (component "wasm")
 * and wasm.* job events.
 */
export function useWasmSupport(regionId, emit, t, confirm) {
  const status         = ref('not_installed')
  const message        = ref('')
  const installedShims = ref([])
  const availableShims = ref([])
  const selectedShims  = ref(['spin'])
  const nodes          = ref([])          // cluster nodes with eligibility (status endpoint)
  const allNodes       = ref(true)        // true = every eligible node (nodes: null)
  const selectedNodes  = ref([])
  const canManage      = ref(false)
  const clusterError   = ref(null)
  const knativePending = ref([])
  const loading        = ref(false)
  const busy           = ref(false)
  const error          = ref(null)

  const eligibleNodes = computed(() => nodes.value.filter(n => n.eligible))
  const busyStatus    = computed(() => ['installing', 'uninstalling'].includes(status.value))
  const canSubmit     = computed(() =>
    canManage.value && !busy.value && !busyStatus.value
    && selectedShims.value.length > 0
    && eligibleNodes.value.length > 0
    && (allNodes.value || selectedNodes.value.length > 0))

  function initFromRegion(region) {
    if (!region) return
    status.value  = region.sandbox_wasm_status || 'not_installed'
    message.value = region.sandbox_wasm_status_message || ''
    installedShims.value = Array.isArray(region.sandbox_wasm_shims) ? region.sandbox_wasm_shims : []
    if (installedShims.value.length) selectedShims.value = [...installedShims.value]
    if (Array.isArray(region.sandbox_wasm_nodes)) {
      allNodes.value = false
      selectedNodes.value = [...region.sandbox_wasm_nodes]
    }
  }

  async function load({ cluster = true } = {}) {
    if (!regionId.value) return
    loading.value = true
    try {
      const { data } = await wasmApi.status(regionId.value, cluster ? {} : { cluster: 0 })
      status.value         = data.status || 'not_installed'
      message.value        = data.message || ''
      installedShims.value = Array.isArray(data.shims) ? data.shims : []
      availableShims.value = Array.isArray(data.available_shims) ? data.available_shims : []
      canManage.value      = !!data.can_manage
      if (installedShims.value.length) selectedShims.value = [...installedShims.value]
      if (Array.isArray(data.nodes)) {
        allNodes.value = false
        selectedNodes.value = [...data.nodes]
      } else if (data.status === 'ready' || data.status === 'not_installed') {
        allNodes.value = true
      }
      if (data.cluster) {
        nodes.value          = Array.isArray(data.cluster.nodes) ? data.cluster.nodes : []
        knativePending.value = data.cluster.knative_features_to_enable || []
      }
      clusterError.value = data.cluster_error || null
    } catch (e) {
      clusterError.value = e.response?.data?.message || e.message
    } finally {
      loading.value = false
    }
  }

  function toggleShim(id) {
    if (id === 'spin') return // the Wasm runtimes run on it
    selectedShims.value = selectedShims.value.includes(id)
      ? selectedShims.value.filter(s => s !== id)
      : [...selectedShims.value, id]
  }

  function toggleNode(name) {
    selectedNodes.value = selectedNodes.value.includes(name)
      ? selectedNodes.value.filter(n => n !== name)
      : [...selectedNodes.value, name]
  }

  function applyRegion(data) {
    const region = data?.region?.data ?? data?.region
    if (region) {
      initFromRegion(region)
      emit?.('saved', region)
    }
  }

  async function install() {
    const ok = await confirm({
      title:        t(status.value === 'ready' ? 'wasm.card.applyBtn' : 'wasm.card.installBtn'),
      message:      t('wasm.card.installWarning'),
      confirmLabel: t('wasm.card.installConfirm'),
    })
    if (!ok) return

    busy.value = true
    error.value = null
    try {
      const { data } = await wasmApi.install(regionId.value, {
        shims: selectedShims.value,
        nodes: allNodes.value ? null : selectedNodes.value,
      })
      applyRegion(data)
    } catch (e) {
      error.value = e.response?.data?.message || e.message
    } finally {
      busy.value = false
    }
  }

  async function uninstall() {
    const ok = await confirm({
      title:       t('wasm.card.uninstallBtn'),
      message:     t('wasm.card.uninstallConfirm'),
      destructive: true,
    })
    if (!ok) return

    busy.value = true
    error.value = null
    try {
      const { data } = await wasmApi.uninstall(regionId.value)
      applyRegion(data)
    } catch (e) {
      error.value = e.response?.data?.message || e.message
    } finally {
      busy.value = false
    }
  }

  useJobEvents({
    'wasm.*': (payload) => {
      if (payload?.region_id !== regionId.value) return
      load()
    },
    'install.progress': (payload) => {
      if (payload?.region_id !== regionId.value || payload.component !== 'wasm') return
      message.value = payload.message || ''
    },
  })

  return {
    status, message, installedShims, availableShims, selectedShims,
    nodes, eligibleNodes, allNodes, selectedNodes,
    canManage, clusterError, knativePending, loading, busy, busyStatus, error, canSubmit,
    initFromRegion, load, toggleShim, toggleNode, install, uninstall,
  }
}
