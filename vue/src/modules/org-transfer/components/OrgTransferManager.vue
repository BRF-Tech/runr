<script setup>
// Administration → Export / Import (docs/org-export.md): exports the organization into a package
// (download, an object binding or an external S3 bucket) and imports a package after a dry-run
// plan. Progress comes over the platform channel (org_transfer.progress / .completed / .failed);
// while the socket is down the open transfers are polled.
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArchiveRestore, RefreshCw, Download, X, FileText, Play } from 'lucide-vue-next'
import { orgTransferApi, unwrap, savePackage, ACTIVE_STATUSES } from '../api'
import ExportPanel from './ExportPanel.vue'
import ImportPanel from './ImportPanel.vue'
import TransferReport from './TransferReport.vue'
import { projectsApi } from '@/api/projects'
import { bindingsApi } from '@/api/bindings'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { useJobEvents } from '@/composables/useJobEvents'
import { useFallbackPolling } from '@/composables/useFallbackPolling'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()
const settingsStore = useSettingsStore()
const toast = useToast()
const { confirm } = useConfirm()

const transfers  = ref([])
const projects   = ref([])
const bindings   = ref([])
const loading    = ref(true)
const loadError  = ref(null)
const exportId   = ref(null)   // the export the export card follows
const importId   = ref(null)   // the import the import card follows
const reportOf   = ref(null)   // transfer whose report is open

const regions = computed(() => settingsStore.data.regions ?? [])
const currentExport = computed(() => transfers.value.find(tr => tr.id === exportId.value)
  ?? transfers.value.find(tr => tr.kind === 'export' && ACTIVE_STATUSES.includes(tr.status)) ?? null)
const currentImport = computed(() => transfers.value.find(tr => tr.id === importId.value)
  ?? transfers.value.find(tr => tr.kind === 'import' && ['verifying', 'planned', 'applying'].includes(tr.status)) ?? null)

// A transfer the cards picked up (running when the page opened) stays theirs once it finishes:
// the finished export offers its package, the finished import its report
watch(currentExport, (tr) => { if (tr && !exportId.value) exportId.value = tr.id })
watch(currentImport, (tr) => { if (tr && !importId.value) importId.value = tr.id })

function upsert(transfer) {
  if (!transfer?.id) return
  const i = transfers.value.findIndex(tr => tr.id === transfer.id)
  if (i === -1) transfers.value = [transfer, ...transfers.value]
  else transfers.value.splice(i, 1, { ...transfers.value[i], ...transfer })
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const [list, projectList, bindingList] = await Promise.all([
      orgTransferApi.list(),
      projectsApi.list().catch(() => ({ data: { data: [] } })),
      bindingsApi.list().catch(() => ({ data: { data: [] } })),
    ])
    transfers.value = unwrap(list) ?? []
    projects.value  = projectList.data?.data ?? projectList.data ?? []
    bindings.value  = bindingList.data?.data ?? []
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('orgTransfer.loadFailed')
  } finally {
    loading.value = false
  }
}

async function reload(id) {
  try {
    upsert(unwrap(await orgTransferApi.get(id)))
  } catch { /* the next event or poll catches up */ }
}

// Live progress: the event carries status / phase / progress; the plan, package and report come
// with the transfer itself once its status settles
useJobEvents({
  'org_transfer.progress': (payload) => {
    const id = payload?.transfer_id
    if (!id) return
    const known = transfers.value.find(tr => tr.id === id)
    upsert({ id, kind: payload.kind ?? known?.kind, status: payload.status, phase: payload.phase ?? null, progress: payload.progress ?? 0, message: payload.message ?? null })
    if (!known || known.status !== payload.status) reload(id)
  },
  'org_transfer.completed': (payload) => {
    if (!payload?.transfer_id) return
    reload(payload.transfer_id)
    toast.success(payload.kind === 'import' ? t('orgTransfer.import.applied') : t('orgTransfer.export.completed'))
  },
  'org_transfer.failed': (payload) => {
    if (!payload?.transfer_id) return
    reload(payload.transfer_id)
    const message = payload.error ?? ''
    toast.error(payload.kind === 'import' ? t('orgTransfer.import.applyFailed', { message }) : t('orgTransfer.export.failed', { message }))
  },
})

// Without the socket: the open transfers are polled
useFallbackPolling(async () => {
  for (const tr of transfers.value.filter(x => ACTIVE_STATUSES.includes(x.status))) await reload(tr.id)
}, 5000)

async function download(transfer) {
  try {
    savePackage(await orgTransferApi.download(transfer.id), transfer.package?.filename ?? 'runr-org.tar.zst')
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('orgTransfer.list.downloadFailed'))
  }
}

async function cancel(transfer) {
  const ok = await confirm({
    title:        t('orgTransfer.list.cancelTitle'),
    message:      t('orgTransfer.list.cancelConfirm'),
    confirmLabel: t('orgTransfer.list.cancel'),
    destructive:  true,
  })
  if (!ok) return
  try {
    await orgTransferApi.cancel(transfer.id)
    upsert({ id: transfer.id, status: 'cancelled' })
    toast.success(t('orgTransfer.list.cancelled'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function continueImport(transfer) {
  importId.value = transfer.id
}

function onExportStarted(transfer) {
  upsert(transfer)
  exportId.value = transfer.id
}

function onImportChanged(transfer) {
  upsert(transfer)
  importId.value = transfer.id
}

const canCancel = (tr) => ACTIVE_STATUSES.includes(tr.status) || tr.status === 'planned'
const canContinue = (tr) => tr.kind === 'import' && ['planned', 'failed'].includes(tr.status) && !!tr.plan && tr.id !== currentImport.value?.id

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch()
  load()
})
</script>

<template>
  <div class="space-y-5" data-testid="org-transfer-manager">
    <div class="card">
      <div class="flex items-center gap-2 mb-3 pb-3 border-b border-gray-800">
        <ArchiveRestore class="w-4 h-4" style="color:rgb(var(--brand-500))" />
        <h3 class="text-sm font-semibold text-gray-100">{{ t('orgTransfer.title') }}</h3>
        <button type="button" class="btn-secondary text-xs ml-auto" :title="t('orgTransfer.refresh')" data-testid="org-transfer-refresh" @click="load">
          <RefreshCw class="w-3.5 h-3.5" />
        </button>
      </div>
      <p class="text-xs text-gray-400">{{ t('orgTransfer.description') }}</p>
    </div>

    <p v-if="loadError" class="text-sm text-red-400" data-testid="org-transfer-error">{{ loadError }}</p>

    <div class="grid gap-5 xl:grid-cols-2">
      <ExportPanel
        :transfer="currentExport"
        :projects="projects"
        :bindings="bindings"
        @started="onExportStarted"
        @download="download"
        @report="reportOf = $event"
      />
      <ImportPanel
        :transfer="currentImport"
        :transfers="transfers"
        :regions="regions"
        :bindings="bindings"
        @changed="onImportChanged"
        @report="reportOf = $event"
      />
    </div>

    <div class="card" data-testid="org-transfer-list">
      <h4 class="text-sm font-semibold text-gray-100 mb-3">{{ t('orgTransfer.list.title') }}</h4>
      <div v-if="loading" class="py-6 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
      <p v-else-if="!transfers.length" class="py-6 text-center text-sm text-gray-500" data-testid="org-transfer-empty">{{ t('orgTransfer.list.empty') }}</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-gray-500 border-b border-gray-800">
              <th class="py-2 pr-3 font-medium">{{ t('orgTransfer.list.kind') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('orgTransfer.list.status') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('orgTransfer.list.package') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('orgTransfer.list.created') }}</th>
              <th class="py-2" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="tr in transfers" :key="tr.id" class="border-b border-gray-800/60" :data-transfer="tr.id">
              <td class="py-2 pr-3 text-gray-200">{{ t(`orgTransfer.kind.${tr.kind}`) }}</td>
              <td class="py-2 pr-3" data-testid="transfer-status">
                <span :class="tr.status === 'failed' ? 'text-red-400' : tr.status === 'completed' ? 'text-emerald-400' : 'text-gray-300'">
                  {{ t(`orgTransfer.status.${tr.status}`) }}
                </span>
                <span v-if="ACTIVE_STATUSES.includes(tr.status)" class="text-gray-500"> · {{ tr.progress ?? 0 }}%</span>
                <p v-if="tr.message" class="text-[10px] text-gray-500 max-w-[22rem] truncate" :title="tr.message">{{ tr.message }}</p>
              </td>
              <td class="py-2 pr-3 font-mono text-gray-400">
                <span v-if="tr.package">{{ tr.package.filename }} · {{ formatBytes(tr.package.size_bytes) }}</span>
                <span v-else-if="tr.upload">{{ formatBytes(tr.upload.uploaded_bytes) }} / {{ formatBytes(tr.upload.size_bytes) }}</span>
                <span v-else>—</span>
              </td>
              <td class="py-2 pr-3 text-gray-500">{{ tr.created_at ? new Date(tr.created_at).toLocaleString() : '—' }}</td>
              <td class="py-2 text-right whitespace-nowrap">
                <button v-if="tr.package?.downloadable" type="button" class="p-1 text-gray-400 hover:text-gray-100" :title="t('orgTransfer.list.download')" data-testid="transfer-download" @click="download(tr)"><Download class="w-3.5 h-3.5" /></button>
                <button v-if="canContinue(tr)" type="button" class="p-1 text-gray-400 hover:text-gray-100" :title="t('orgTransfer.list.continue')" data-testid="transfer-continue" @click="continueImport(tr)"><Play class="w-3.5 h-3.5" /></button>
                <button v-if="tr.report" type="button" class="p-1 text-gray-400 hover:text-gray-100" :title="t('orgTransfer.list.report')" data-testid="transfer-report" @click="reportOf = tr"><FileText class="w-3.5 h-3.5" /></button>
                <button v-if="canCancel(tr)" type="button" class="p-1 text-gray-400 hover:text-red-400" :title="t('orgTransfer.list.cancel')" data-testid="transfer-cancel" @click="cancel(tr)"><X class="w-3.5 h-3.5" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <TransferReport v-if="reportOf" :transfer="reportOf" @close="reportOf = null" />
  </div>
</template>
