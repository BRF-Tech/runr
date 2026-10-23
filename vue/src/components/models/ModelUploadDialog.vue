<script setup>
// Air-gapped regions: upload the files of a model through RunR into the region's model volume.
// The files go up in chunks (resumable per file); the region receives them through its
// Kubernetes API, so the region needs no internet access.
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Upload, FolderUp } from 'lucide-vue-next'
import { aiApi, uploadModelFiles, uploadPath, sha256Hex, SHA256_MAX_BYTES } from '@/api/ai'
import { useSettingsStore } from '@/stores/settings'
import { useAiStore } from '@/stores/ai'
import { useToast } from '@/composables/useToast'
import { isRegionReady } from '@/composables/useProjectForm'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()
const settingsStore = useSettingsStore()
const ai = useAiStore()
const toast = useToast()

const props = defineProps({ model: { type: Object, required: true } })
const emit = defineEmits(['close', 'completed'])

const regionId = ref(null)
const picked   = ref([])            // [{ path, file }]
const phase    = ref('pick')        // pick | preparing | uploading | completing | done | failed
const upload   = ref(null)
const progress = ref({ uploaded: 0, total: 0, path: '' })
const error    = ref(null)
let controller = null
let alive = true

const regions = computed(() => (settingsStore.data.regions ?? []).filter(isRegionReady))
const totalBytes = computed(() => picked.value.reduce((s, f) => s + f.file.size, 0))
const percent = computed(() => progress.value.total ? Math.floor(progress.value.uploaded / progress.value.total * 100) : 0)
const busy = computed(() => ['preparing', 'uploading', 'completing'].includes(phase.value))

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch()
  if (regions.value.length === 1) regionId.value = regions.value[0].id
})
onUnmounted(() => { alive = false; controller?.abort() })

function onPick(event) {
  const files = [...(event.target.files ?? [])]
  const byPath = new Map(picked.value.map(f => [f.path, f]))
  for (const file of files) {
    const path = uploadPath(file)
    if (path && !path.split('/').some(p => p === '..')) byPath.set(path, { path, file })
  }
  picked.value = [...byPath.values()].sort((a, b) => a.path.localeCompare(b.path))
  event.target.value = ''
}

function removeFile(path) {
  picked.value = picked.value.filter(f => f.path !== path)
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms))

async function start() {
  error.value = null
  phase.value = 'preparing'
  const ctrl = new AbortController()
  controller  = ctrl
  // Aborted (or the dialog closed) while a step ran: the steps after it do not run, and what the
  // step created in the meantime is aborted too
  const stopped = () => !alive || ctrl.signal.aborted
  try {
    const files = []
    for (const f of picked.value) {
      const entry = { path: f.path, size: f.file.size }
      if (f.file.size <= SHA256_MAX_BYTES) {
        const sha = await sha256Hex(f.file)
        if (stopped()) return
        if (sha) entry.sha256 = sha
      }
      files.push(entry)
    }
    if (stopped()) return
    const { data } = await aiApi.createUpload(props.model.id, { knative_region_id: regionId.value, files })
    const created = data?.data ?? data
    if (stopped()) {
      // abort() ran before this upload existed: the region's receiver is not left behind
      if (created?.id) aiApi.abortUpload(props.model.id, created.id).catch(() => {})
      return
    }
    upload.value = created

    // The region's receiver starts first
    for (let i = 0; upload.value?.status === 'preparing' && i < 120; i++) {
      await sleep(2000)
      if (stopped()) return
      const res = await aiApi.getUpload(props.model.id, upload.value.id)
      if (stopped()) return
      upload.value = res.data?.data ?? res.data
    }
    if (upload.value?.status !== 'ready') throw new Error(upload.value?.message || t('ai.upload.receiverFailed'))

    phase.value = 'uploading'
    await uploadModelFiles({
      modelId: props.model.id,
      upload:  upload.value,
      files:   picked.value,
      signal:  ctrl.signal,
      onProgress: (p) => { progress.value = p },
    })
    if (stopped()) return

    phase.value = 'completing'
    const done = await aiApi.completeUpload(props.model.id, upload.value.id)
    const snapshot = done.data?.snapshot
    if (snapshot) ai.upsertSnapshot(props.model.id, snapshot)
    phase.value = 'done'
    toast.success(t('ai.upload.completed'))
    emit('completed', snapshot)
  } catch (e) {
    if (e?.name === 'AbortError' || stopped()) return
    phase.value = 'failed'
    error.value = e?.response?.data?.message ?? e?.message ?? t('common.error')
  }
}

async function abort() {
  controller?.abort()
  if (upload.value?.id) {
    try { await aiApi.abortUpload(props.model.id, upload.value.id) } catch { /* already gone */ }
  }
  phase.value = 'pick'
  upload.value = null
  progress.value = { uploaded: 0, total: 0, path: '' }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="upload-dialog">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="!busy && emit('close')" />
    <div class="relative w-full max-w-xl card space-y-4">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-gray-100">{{ t('ai.upload.title') }}</h2>
          <p class="text-xs text-gray-400 font-mono">{{ model.name }}</p>
        </div>
        <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :disabled="busy" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>
      <p class="text-[11px] text-gray-400">{{ t('ai.upload.subtitle') }}</p>

      <div>
        <label class="label" for="upload-region">{{ t('ai.deploy.region') }}</label>
        <select id="upload-region" v-model="regionId" class="input" :disabled="busy" data-testid="upload-region">
          <option :value="null" disabled>{{ t('ai.deploy.selectRegion') }}</option>
          <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
      </div>

      <div v-if="phase === 'pick' || phase === 'failed'" class="flex gap-2">
        <label class="btn-secondary text-xs cursor-pointer">
          <Upload class="w-3.5 h-3.5" />{{ t('ai.upload.pickFiles') }}
          <input type="file" multiple class="hidden" data-testid="upload-files" @change="onPick" />
        </label>
        <label class="btn-secondary text-xs cursor-pointer">
          <FolderUp class="w-3.5 h-3.5" />{{ t('ai.upload.pickFolder') }}
          <input type="file" multiple webkitdirectory class="hidden" @change="onPick" />
        </label>
      </div>

      <div v-if="picked.length" class="rounded-lg border border-gray-800 max-h-48 overflow-y-auto">
        <div v-for="f in picked" :key="f.path" class="flex items-center justify-between px-3 py-1.5 text-[11px] font-mono border-b border-gray-800/60 last:border-0" :data-upload-file="f.path">
          <span class="truncate text-gray-300">{{ f.path }}</span>
          <span class="flex items-center gap-2 shrink-0 ml-3 text-gray-500">
            {{ formatBytes(f.file.size) }}
            <button v-if="!busy" class="text-gray-500 hover:text-red-400" @click="removeFile(f.path)"><X class="w-3 h-3" /></button>
          </span>
        </div>
      </div>
      <p v-if="picked.length" class="text-[11px] text-gray-400">{{ t('ai.upload.summary', { n: picked.length, size: formatBytes(totalBytes) }) }}</p>

      <div v-if="phase !== 'pick'" class="space-y-1.5">
        <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
          <div class="h-full rounded-full transition-all duration-300" style="background:rgb(var(--brand-500))" :style="{ width: (phase === 'done' ? 100 : percent) + '%' }" data-testid="upload-progress" />
        </div>
        <p class="text-[11px] text-gray-400 font-mono">
          {{ t(`ai.upload.phases.${phase}`) }}<template v-if="phase === 'uploading'"> · {{ percent }}% · {{ progress.path }}</template>
        </p>
      </div>

      <p v-if="error" class="text-sm text-red-400" data-testid="upload-error">{{ error }}</p>

      <div class="flex justify-end gap-2">
        <button v-if="busy" class="btn-secondary" @click="abort">{{ t('ai.upload.abort') }}</button>
        <button v-else class="btn-secondary" @click="emit('close')">{{ t('common.close') }}</button>
        <button v-if="phase !== 'done'" class="btn-primary" :disabled="busy || !regionId || !picked.length" data-testid="upload-start" @click="start">
          {{ t('ai.upload.start') }}
        </button>
      </div>
    </div>
  </div>
</template>
