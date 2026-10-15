<script setup>
// Upload a container image (docker save / OCI layout tar) or a .wasm module into a region's
// registry (docs/images.md). Chunks go through RunR to the receiver in the region; an upload that
// stopped (reload, network) resumes from what the region already has — pass it as `resume`.
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Upload } from 'lucide-vue-next'
import { imagesApi, uploadImageFile, fileSha256, nameFromFilename, validName } from '../api'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { isRegionReady } from '@/composables/useProjectForm'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()
const settingsStore = useSettingsStore()
const toast = useToast()

const props = defineProps({
  // an open upload to resume (GET /images/uploads entry), null for a new one
  resume:   { type: Object, default: null },
  regionId: { type: String, default: null },
})
const emit = defineEmits(['close', 'completed'])

const regionId = ref(props.resume?.knative_region_id ?? props.regionId ?? null)
const file     = ref(null)
const name     = ref(props.resume?.name ?? '')
const format   = ref(props.resume?.format ?? 'auto')
const phase    = ref('pick')     // pick | preparing | uploading | verifying | pushing | done | failed
const upload   = ref(props.resume ?? null)
const progress = ref({ uploaded: Number(props.resume?.uploaded_bytes) || 0, total: Number(props.resume?.size_bytes) || 0 })
const error    = ref(null)
let controller = null
let alive = true

const regions = computed(() => (settingsStore.data.regions ?? []).filter(r => isRegionReady(r) && (r.is_demo || r.has_registry !== false)))
const percent = computed(() => progress.value.total ? Math.floor(progress.value.uploaded / progress.value.total * 100) : 0)
const busy    = computed(() => ['preparing', 'uploading', 'verifying', 'pushing'].includes(phase.value))
const nameOk  = computed(() => validName(name.value))
const fileMatchesResume = computed(() => !props.resume || (file.value && file.value.size === Number(props.resume.size_bytes)))
const canStart = computed(() => !busy.value && !!regionId.value && !!file.value && nameOk.value && fileMatchesResume.value)

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch()
  if (!regionId.value && regions.value.length === 1) regionId.value = regions.value[0].id
})
onUnmounted(() => { alive = false; controller?.abort() })

function onPick(event) {
  const picked = event.target.files?.[0] ?? null
  event.target.value = ''
  if (!picked) return
  file.value = picked
  if (!props.resume && !name.value) name.value = `${nameFromFilename(picked.name)}:latest`
  if (!props.resume && picked.name.toLowerCase().endsWith('.wasm')) format.value = 'wasm'
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms))

async function refresh() {
  const res = await imagesApi.getUpload(upload.value.id)
  upload.value = res.data?.data ?? res.data
  return upload.value
}

async function start() {
  error.value = null
  controller  = new AbortController()
  try {
    if (!upload.value) {
      phase.value = 'preparing'
      const sha256 = await fileSha256(file.value)
      if (!alive) return   // closed while hashing: no upload is created
      const { data } = await imagesApi.createUpload({
        knative_region_id: regionId.value,
        filename:          file.value.name,
        size:              file.value.size,
        name:              name.value.trim() || undefined,
        format:            format.value,
        ...(sha256 ? { sha256 } : {}),
      })
      upload.value = data?.data ?? data
    } else {
      phase.value = 'preparing'
      await refresh()
    }

    // the region's receiver starts first
    for (let i = 0; alive && upload.value?.status === 'preparing' && i < 150; i++) {
      await sleep(2000)
      await refresh()
    }
    if (!alive) return
    if (upload.value?.status !== 'ready') throw new Error(upload.value?.message || t('images.dialog.receiverFailed'))

    phase.value = 'uploading'
    await uploadImageFile({
      upload:     upload.value,
      file:       file.value,
      signal:     controller.signal,
      onProgress: (p) => { progress.value = p },
    })

    await imagesApi.completeUpload(upload.value.id)
    phase.value = 'verifying'
    for (;;) {
      if (!alive) return
      await sleep(2000)
      const u = await refresh()
      if (u.status === 'verifying' || u.status === 'pushing') { phase.value = u.status; continue }
      if (u.status === 'completed') break
      if (u.status === 'ready') {
        // the region found the file damaged: it takes the file again from the start
        progress.value = { uploaded: Number(u.uploaded_bytes) || 0, total: file.value.size }
        throw new Error(u.message || t('images.dialog.phases.failed'))
      }
      throw new Error(u.message || t('images.dialog.phases.failed'))
    }

    phase.value = 'done'
    toast.success(t('images.dialog.completed', { name: upload.value.name }))
    emit('completed', upload.value.image)
  } catch (e) {
    if (e?.name === 'AbortError') return
    phase.value = 'failed'
    error.value = e?.response?.data?.message ?? e?.message ?? t('common.error')
  }
}

async function abort() {
  controller?.abort()
  if (upload.value?.id) {
    try { await imagesApi.abortUpload(upload.value.id) } catch { /* already gone */ }
  }
  upload.value = null
  phase.value = 'pick'
  progress.value = { uploaded: 0, total: 0 }
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="image-upload-dialog">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="!busy && emit('close')" />
    <div class="relative w-full max-w-xl card space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-100">{{ t('images.dialog.title') }}</h2>
        <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :disabled="busy" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>
      <p class="text-[11px] text-gray-400">{{ t('images.dialog.subtitle') }}</p>
      <p class="text-[11px] text-gray-500 font-mono">{{ t('images.dialog.help') }}</p>

      <p v-if="resume" class="text-[11px] text-amber-400" data-testid="image-upload-resume">
        {{ t('images.dialog.resumeHint', { name: resume.name, percent, size: formatBytes(resume.size_bytes) }) }}
      </p>

      <div>
        <label class="label" for="image-upload-region">{{ t('images.dialog.region') }}</label>
        <select id="image-upload-region" v-model="regionId" class="input" :disabled="busy || !!resume" data-testid="image-upload-region">
          <option :value="null" disabled>{{ t('images.dialog.selectRegion') }}</option>
          <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
      </div>

      <div>
        <label class="label">{{ t('images.dialog.file') }}</label>
        <div class="flex items-center gap-3">
          <label class="btn-secondary text-xs cursor-pointer" :class="busy && 'opacity-50 pointer-events-none'">
            <Upload class="w-3.5 h-3.5" />{{ t('images.dialog.pickFile') }}
            <input type="file" accept=".tar,.tgz,.gz,.oci,.wasm,application/x-tar,application/gzip,application/wasm" class="hidden" data-testid="image-upload-file" @change="onPick" />
          </label>
          <span v-if="file" class="text-[11px] font-mono text-gray-300 truncate" data-testid="image-upload-file-name">{{ file.name }} · {{ formatBytes(file.size) }}</span>
        </div>
        <p v-if="resume && file && !fileMatchesResume" class="text-[11px] text-red-400 mt-1">
          {{ t('images.dialog.sameFile', { name: resume.filename, size: formatBytes(resume.size_bytes) }) }}
        </p>
      </div>

      <div class="flex gap-3">
        <div class="flex-1">
          <label class="label" for="image-upload-name">{{ t('images.dialog.name') }}</label>
          <input id="image-upload-name" v-model="name" class="input font-mono" :placeholder="t('images.dialog.namePlaceholder')" :disabled="busy || !!resume" data-testid="image-upload-name" />
          <p v-if="!nameOk" class="text-[11px] text-red-400 mt-1">{{ t('images.dialog.nameInvalid') }}</p>
        </div>
        <div class="w-48">
          <label class="label" for="image-upload-format">{{ t('images.dialog.format') }}</label>
          <select id="image-upload-format" v-model="format" class="input" :disabled="busy || !!resume" data-testid="image-upload-format">
            <option v-for="f in ['auto', 'docker', 'oci', 'wasm']" :key="f" :value="f">{{ t(`images.dialog.formats.${f}`) }}</option>
          </select>
        </div>
      </div>

      <div v-if="phase !== 'pick'" class="space-y-1.5">
        <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
          <div class="h-full rounded-full transition-all duration-300" style="background:rgb(var(--brand-500))" :style="{ width: (phase === 'done' ? 100 : percent) + '%' }" data-testid="image-upload-progress" />
        </div>
        <p class="text-[11px] text-gray-400 font-mono" data-testid="image-upload-phase">
          {{ t(`images.dialog.phases.${phase}`) }}<template v-if="phase === 'uploading'"> · {{ percent }}% · {{ formatBytes(progress.uploaded) }} / {{ formatBytes(progress.total) }}</template>
        </p>
      </div>

      <p v-if="error" class="text-sm text-red-400" data-testid="image-upload-error">{{ error }}</p>

      <div class="flex justify-end gap-2">
        <button v-if="busy || (upload && phase !== 'done')" class="btn-secondary" data-testid="image-upload-abort" @click="abort">{{ t('images.dialog.abort') }}</button>
        <button v-else class="btn-secondary" @click="emit('close')">{{ t('common.close') }}</button>
        <button v-if="phase !== 'done'" class="btn-primary" :disabled="!canStart" data-testid="image-upload-start" @click="start">
          {{ t('images.dialog.start') }}
        </button>
      </div>
    </div>
  </div>
</template>
