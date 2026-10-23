<script setup>
// Model library: the organization's models (Hub repositories pinned to a revision, uploads,
// imports) and their offline copies per region (snapshots) for offline model services and
// for functions that load the weights themselves.
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Rocket, Trash2, Upload, Download, FileText, Lock } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useSettingsStore } from '@/stores/settings'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { isRegionReady } from '@/composables/useProjectForm'
import { AI_STATUS_CLASSES, formatBytes } from '@/utils/ai'
import AddModelDialog from './AddModelDialog.vue'
import ModelUploadDialog from './ModelUploadDialog.vue'

const { t } = useI18n()
const ai = useAiStore()
const settingsStore = useSettingsStore()
const { confirm } = useConfirm()
const toast = useToast()

defineProps({ loading: { type: Boolean, default: false } })
const emit = defineEmits(['deploy'])

const showAdd = ref(false)
const uploadFor = ref(null)
const mirrorRegion = reactive({})        // model id → region id
const logs = reactive({})                // snapshot id → text
const busy = reactive({})

const regions = computed(() => (settingsStore.data.regions ?? []).filter(isRegionReady))
const statusClass = (s) => AI_STATUS_CLASSES[s] ?? 'bg-gray-800 text-gray-400 border-gray-700'

function errMessage(e) {
  return e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function mirror(model) {
  const regionId = mirrorRegion[model.id]
  if (!regionId) return
  busy[model.id] = true
  try {
    const { data } = await aiApi.createSnapshot(model.id, regionId)
    const snapshot = data?.data ?? data
    // On the model as the list has it now: the copy of `model` taken at the click is older than
    // what the page's poll brought meanwhile
    ai.upsertSnapshot(model.id, snapshot)
    toast.success(t(model.source === 'hub' ? 'ai.library.mirrorQueued' : 'ai.library.importQueued'))
  } catch (e) { toast.error(errMessage(e)) } finally { busy[model.id] = false }
}

async function deleteSnapshot(model, snapshot) {
  const ok = await confirm({ title: t('ai.library.deleteSnapshot'), message: t('ai.library.deleteSnapshotConfirm', { region: snapshot.region_name }), destructive: true })
  if (!ok) return
  try {
    await aiApi.deleteSnapshot(model.id, snapshot.id)
    ai.removeSnapshot(model.id, snapshot.id)
    toast.success(t('ai.library.snapshotDeleted'))
  } catch (e) { toast.error(errMessage(e)) }
}

async function toggleLogs(model, snapshot) {
  if (logs[snapshot.id] !== undefined) { delete logs[snapshot.id]; return }
  logs[snapshot.id] = t('common.loading')
  try {
    const { data } = await aiApi.snapshotLogs(model.id, snapshot.id)
    logs[snapshot.id] = data?.logs || '—'
  } catch (e) { logs[snapshot.id] = errMessage(e) }
}

async function deleteModel(model) {
  const ok = await confirm({ title: t('ai.library.delete'), message: t('ai.library.deleteConfirm', { name: model.name }), destructive: true })
  if (!ok) return
  try {
    await aiApi.deleteModel(model.id)
    ai.removeModel(model.id)
    toast.success(t('ai.library.deleted'))
  } catch (e) { toast.error(errMessage(e)) }
}
</script>

<template>
  <div class="space-y-4" data-testid="model-library">
    <div class="flex items-center justify-between">
      <p class="text-xs text-gray-400">{{ t('ai.library.subtitle') }}</p>
      <button v-if="ai.canDeploy" class="btn-primary text-xs" data-testid="library-add" @click="showAdd = true"><Plus class="w-3.5 h-3.5" />{{ t('ai.library.add') }}</button>
    </div>

    <div v-if="loading && !ai.models.length" class="py-10 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>
    <p v-else-if="!ai.models.length" class="py-10 text-center text-xs text-gray-400">{{ t('ai.library.empty') }}</p>

    <div v-for="m in ai.models" :key="m.id" class="rounded-xl border border-gray-800 bg-gray-900/50 p-4 space-y-3" :data-library-model="m.name">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="font-mono text-sm text-gray-100">{{ m.name }}</span>
            <span class="px-1.5 py-0.5 rounded-sm text-[10px] bg-gray-800 text-gray-300">{{ t(`ai.sources.${m.source}`, m.source) }}</span>
            <Lock v-if="m.gated || m.private" class="w-3.5 h-3.5 text-amber-400" />
          </div>
          <p class="text-[11px] text-gray-400 font-mono truncate">
            <template v-if="m.hub_id">{{ m.hub_id }}@{{ (m.revision_sha || m.revision || 'main').slice(0, 12) }}</template>
            <template v-else-if="m.source_ref">{{ m.source_ref }}</template>
            · {{ t(`ai.tasks.${m.task}`, m.task ?? '') }} · {{ formatBytes(m.size_bytes) }}
            <template v-if="m.services_count"> · {{ t('ai.library.servicesCount', { n: m.services_count }) }}</template>
          </p>
        </div>
        <div class="flex items-center gap-1.5 shrink-0">
          <button v-if="ai.canDeploy" class="btn-primary text-xs" :data-testid="`library-deploy-${m.name}`" @click="emit('deploy', m)"><Rocket class="w-3.5 h-3.5" />{{ t('ai.hub.deploy') }}</button>
          <button v-if="ai.canDeploy && m.source === 'upload'" class="btn-secondary text-xs" :data-testid="`library-upload-${m.name}`" @click="uploadFor = m"><Upload class="w-3.5 h-3.5" />{{ t('ai.upload.button') }}</button>
          <button v-if="ai.canManage" class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20" :title="t('ai.library.delete')" @click="deleteModel(m)"><Trash2 class="w-4 h-4" /></button>
        </div>
      </div>

      <!-- Offline copies -->
      <div class="space-y-1.5">
        <p class="text-[10px] text-gray-500 uppercase tracking-wider font-medium">{{ t('ai.library.snapshots') }}</p>
        <p v-if="!(m.snapshots ?? []).length" class="text-[11px] text-gray-400">{{ t('ai.library.noSnapshots') }}</p>
        <div v-for="s in m.snapshots ?? []" :key="s.id" class="rounded-lg border border-gray-800 bg-gray-950/40" :data-snapshot-region="s.region_name">
          <div class="flex items-center gap-3 px-3 py-1.5 text-[11px]">
            <span class="text-gray-200 w-28 truncate">{{ s.region_name }}</span>
            <span class="inline-block px-1.5 py-0.5 rounded-sm border font-mono" :class="statusClass(s.status)">{{ t(`ai.library.snapshotStatuses.${s.status}`, s.status) }}</span>
            <span class="font-mono text-gray-400">{{ formatBytes(s.size_bytes) }}<template v-if="s.files_count"> · {{ t('ai.library.files', { n: s.files_count }) }}</template></span>
            <span v-if="s.message" class="text-gray-400 truncate" :title="s.message" data-testid="snapshot-message">{{ s.message }}</span>
            <span class="flex-1" />
            <button class="text-gray-400 hover:text-gray-100" :title="t('ai.library.logs')" @click="toggleLogs(m, s)"><FileText class="w-3.5 h-3.5" /></button>
            <button v-if="ai.canManage" class="text-gray-400 hover:text-red-400" :title="t('ai.library.deleteSnapshot')" @click="deleteSnapshot(m, s)"><Trash2 class="w-3.5 h-3.5" /></button>
          </div>
          <pre v-if="logs[s.id] !== undefined" class="px-3 py-2 border-t border-gray-800 text-[11px] font-mono text-gray-400 whitespace-pre-wrap max-h-48 overflow-y-auto">{{ logs[s.id] }}</pre>
        </div>

        <!-- Mirror (Hub) / import (PVC path, OCI artifact) into a region -->
        <div v-if="(m.source === 'hub' && ai.canDeploy) || (['pvc', 'oci'].includes(m.source) && ai.canManage)" class="flex items-center gap-2 pt-1">
          <select v-model="mirrorRegion[m.id]" class="input h-8 py-1 text-xs w-48" :data-testid="`library-mirror-region-${m.name}`">
            <option :value="undefined" disabled>{{ t('ai.deploy.selectRegion') }}</option>
            <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
          <button class="btn-secondary text-xs h-8" :disabled="!mirrorRegion[m.id] || busy[m.id]" :data-testid="`library-mirror-${m.name}`" @click="mirror(m)">
            <Download class="w-3.5 h-3.5" />{{ t(m.source === 'hub' ? 'ai.library.mirror' : 'ai.library.import') }}
          </button>
        </div>
      </div>
    </div>

    <AddModelDialog v-if="showAdd" @close="showAdd = false" @created="(m) => { showAdd = false; if (m.source === 'upload') uploadFor = m }" />
    <ModelUploadDialog v-if="uploadFor" :model="uploadFor" @close="uploadFor = null" @completed="uploadFor = null" />
  </div>
</template>
