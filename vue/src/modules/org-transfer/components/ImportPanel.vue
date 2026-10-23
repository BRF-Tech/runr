<script setup>
// Import card of Administration → Export / Import (docs/org-export.md): the package goes up in chunks
// (an unfinished upload of the same file resumes from what the API holds), the API checks it
// (manifest, schema version, checksums) and answers with a dry-run plan; ImportPlanView shows the
// plan, takes the region mapping / renames / passphrase and applies it. Progress and the final
// report follow the transfer the manager passes in. Instead of a file the package can come from
// where an export wrote it — an object binding of the organization or an S3 bucket: RunR reads it
// in the background (status fetching) and a read that failed resumes from the bytes it has.
import { ref, computed, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { FileUp, Upload, FileText, Download, RotateCw } from 'lucide-vue-next'
import { orgTransferApi, unwrap, uploadPackage, resumableImport, importSourcePayload, importSourceComplete } from '../api'
import ImportPlanView from './ImportPlanView.vue'
import { sha256Hex, SHA256_MAX_BYTES } from '@/api/ai'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()

const props = defineProps({
  transfer:  { type: Object, default: null },   // the import this card follows
  transfers: { type: Array, default: () => [] },
  regions:   { type: Array, default: () => [] },
  bindings:  { type: Array, default: () => [] },
})
const emit = defineEmits(['changed', 'report'])

// upload (a file) | binding | s3 — where the package comes from
const mode   = ref('upload')
const source = ref({ bindingId: '', key: '', endpoint: '', bucket: '', region: '', pathStyle: true, accessKeyId: '', secretAccessKey: '', sha256: '' })
const objectBindings = computed(() => props.bindings.filter(b => b.type === 'object'))
const starting = ref(false)
const fetching = computed(() => props.transfer?.status === 'fetching')
const fetchFailed = computed(() => props.transfer?.status === 'failed' && props.transfer?.phase === 'fetch' && !!props.transfer?.source)
const fetchProgress = computed(() => {
  const u = props.transfer?.upload ?? {}
  const total = Number(u.size_bytes) || 0
  const done = Number(u.uploaded_bytes) || 0
  return { done, total, percent: total ? Math.floor(done / total * 100) : 0 }
})
const sourceReady = computed(() => importSourceComplete({ type: mode.value, ...source.value }))

async function startFromSource() {
  if (!sourceReady.value || starting.value) return
  starting.value = true
  error.value = null
  try {
    emit('changed', unwrap(await orgTransferApi.startImport(importSourcePayload({ type: mode.value, ...source.value }))))
    source.value.secretAccessKey = ''
  } catch (e) {
    error.value = e?.response?.data?.message ?? e?.message ?? t('orgTransfer.import.uploadFailed')
  } finally {
    starting.value = false
  }
}

async function resumeFetch() {
  if (!props.transfer || starting.value) return
  starting.value = true
  error.value = null
  try {
    const s3 = props.transfer.source?.type === 's3'
    const credentials = s3 ? { access_key_id: source.value.accessKeyId, secret_access_key: source.value.secretAccessKey } : {}
    emit('changed', unwrap(await orgTransferApi.resumeFetch(props.transfer.id, credentials)))
    source.value.secretAccessKey = ''
  } catch (e) {
    error.value = e?.response?.data?.message ?? e?.message ?? t('orgTransfer.import.uploadFailed')
  } finally {
    starting.value = false
  }
}

const file      = ref(null)
const uploading = ref(false)
const progress  = ref({ uploaded: 0, total: 0 })
const error     = ref(null)
let controller  = null

const resume  = computed(() => resumableImport(props.transfers, file.value))
const percent = computed(() => progress.value.total ? Math.floor(progress.value.uploaded / progress.value.total * 100) : 0)
const status  = computed(() => props.transfer?.status ?? null)
const showPlan = computed(() => !!props.transfer?.plan && ['planned', 'failed', 'applying', 'completed'].includes(status.value))

onUnmounted(() => controller?.abort())

function onPick(event) {
  const picked = event.target.files?.[0] ?? null
  event.target.value = ''
  error.value = null
  if (!picked) return
  if (!/\.tar\.zst$/i.test(picked.name)) {
    error.value = t('orgTransfer.import.wrongFile')
    return
  }
  file.value = picked
  progress.value = { uploaded: Number(resume.value?.upload?.uploaded_bytes) || 0, total: picked.size }
}

async function upload() {
  if (!file.value || uploading.value) return
  uploading.value = true
  error.value = null
  controller = new AbortController()
  try {
    let transfer = resume.value
    if (transfer) {
      transfer = unwrap(await orgTransferApi.get(transfer.id))
    } else {
      const sha256 = file.value.size <= SHA256_MAX_BYTES ? await sha256Hex(file.value) : null
      transfer = unwrap(await orgTransferApi.startImport({
        filename: file.value.name,
        size:     file.value.size,
        ...(sha256 ? { sha256 } : {}),
      }))
    }
    emit('changed', transfer)

    await uploadPackage({
      transfer,
      file:       file.value,
      signal:     controller.signal,
      onProgress: (p) => { progress.value = p },
      // the chunks go through the same API object as the other calls of the panel
      api:        orgTransferApi,
    })

    // the API checks the package; the plan arrives with the transfer (event or poll)
    emit('changed', unwrap(await orgTransferApi.complete(transfer.id)))
    file.value = null
  } catch (e) {
    if (e?.name === 'AbortError') return
    error.value = e?.response?.data?.message ?? e?.message ?? t('orgTransfer.import.uploadFailed')
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="card" data-testid="import-panel">
    <div class="flex items-center gap-2 mb-3 pb-3 border-b border-gray-800">
      <FileUp class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('orgTransfer.import.title') }}</h3>
    </div>
    <p class="text-xs text-gray-400 mb-4">{{ t('orgTransfer.import.description') }}</p>

    <!-- Where the package comes from -->
    <div class="flex flex-wrap gap-3 text-xs text-gray-300 mb-3" data-testid="import-mode">
      <label class="flex items-center gap-1"><input v-model="mode" type="radio" value="upload" data-testid="import-mode-upload">{{ t('orgTransfer.import.fromUpload') }}</label>
      <label class="flex items-center gap-1"><input v-model="mode" type="radio" value="binding" data-testid="import-mode-binding">{{ t('orgTransfer.import.fromBinding') }}</label>
      <label class="flex items-center gap-1"><input v-model="mode" type="radio" value="s3" data-testid="import-mode-s3">{{ t('orgTransfer.import.fromS3') }}</label>
    </div>

    <div v-if="mode !== 'upload'" class="space-y-2 mb-3" data-testid="import-source">
      <template v-if="mode === 'binding'">
        <label class="label" for="import-binding">{{ t('orgTransfer.import.binding') }}</label>
        <select id="import-binding" v-model="source.bindingId" class="input" data-testid="import-source-binding">
          <option value="">—</option>
          <option v-for="b in objectBindings" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
        <p v-if="!objectBindings.length" class="text-[11px] text-gray-500">{{ t('orgTransfer.import.noObjectBindings') }}</p>
      </template>
      <template v-else>
        <div class="grid gap-2 sm:grid-cols-2">
          <input v-model="source.endpoint" class="input" :placeholder="t('orgTransfer.import.s3Endpoint')" data-testid="import-source-endpoint">
          <input v-model="source.bucket" class="input" :placeholder="t('orgTransfer.import.s3Bucket')" data-testid="import-source-bucket">
          <input v-model="source.region" class="input" :placeholder="t('orgTransfer.import.s3Region')" data-testid="import-source-region">
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="source.pathStyle" type="checkbox">{{ t('orgTransfer.import.s3PathStyle') }}</label>
          <input v-model="source.accessKeyId" class="input" autocomplete="off" :placeholder="t('orgTransfer.import.s3AccessKey')" data-testid="import-source-access-key">
          <input v-model="source.secretAccessKey" type="password" class="input" autocomplete="off" :placeholder="t('orgTransfer.import.s3SecretKey')" data-testid="import-source-secret-key">
        </div>
        <p class="text-[11px] text-gray-500">{{ t('orgTransfer.import.s3Hint') }}</p>
      </template>
      <input v-model="source.key" class="input font-mono" :placeholder="t('orgTransfer.import.key')" data-testid="import-source-key">
      <input v-model="source.sha256" class="input font-mono" :placeholder="t('orgTransfer.import.sha256')" data-testid="import-source-sha256">
      <div class="flex justify-end">
        <button type="button" class="btn-primary" :disabled="!sourceReady || starting || fetching" data-testid="import-source-start" @click="startFromSource">
          <Download class="w-4 h-4" />{{ t('orgTransfer.import.readAndCheck') }}
        </button>
      </div>
    </div>

    <!-- The package is being read from its source -->
    <div v-if="fetching" class="mb-3 rounded-lg border border-gray-800 p-3 text-xs text-gray-300" data-testid="import-fetching">
      <div class="flex justify-between mb-1">
        <span>{{ fetchProgress.total ? t('orgTransfer.import.fetching', { done: formatBytes(fetchProgress.done), total: formatBytes(fetchProgress.total) }) : t('orgTransfer.import.fetchingStart') }}</span>
        <span v-if="fetchProgress.total">{{ fetchProgress.percent }}%</span>
      </div>
      <div v-if="fetchProgress.total" class="h-2 bg-gray-800 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all duration-300" style="background:rgb(var(--brand-500))" :style="{ width: fetchProgress.percent + '%' }" />
      </div>
      <p v-if="transfer.message" class="text-[11px] text-gray-500 mt-1">{{ transfer.message }}</p>
    </div>
    <div v-if="fetchFailed" class="mb-3 rounded-lg border border-red-900/60 p-3 text-xs" data-testid="import-fetch-failed">
      <p class="text-red-400 mb-2">{{ transfer.message }}</p>
      <p class="text-gray-400 mb-2">{{ t('orgTransfer.import.fetchResumeHint', { done: formatBytes(fetchProgress.done) }) }}</p>
      <div v-if="transfer.source?.type === 's3'" class="grid gap-2 sm:grid-cols-2 mb-2">
        <input v-model="source.accessKeyId" class="input" autocomplete="off" :placeholder="t('orgTransfer.import.s3AccessKey')" data-testid="import-resume-access-key">
        <input v-model="source.secretAccessKey" type="password" class="input" autocomplete="off" :placeholder="t('orgTransfer.import.s3SecretKey')" data-testid="import-resume-secret-key">
      </div>
      <div class="flex justify-end">
        <button type="button" class="btn-secondary" :disabled="starting" data-testid="import-fetch-resume" @click="resumeFetch">
          <RotateCw class="w-4 h-4" />{{ t('orgTransfer.import.fetchResume') }}
        </button>
      </div>
    </div>

    <template v-if="mode === 'upload'">
    <!-- Upload -->
    <label class="label" for="import-file">{{ t('orgTransfer.import.file') }}</label>
    <input id="import-file" type="file" accept=".zst,application/zstd" class="block w-full text-xs text-gray-300 mb-2" :disabled="uploading" data-testid="import-file" @change="onPick">
    <p v-if="file" class="text-xs text-gray-400 font-mono mb-1">{{ file.name }} · {{ formatBytes(file.size) }}</p>
    <p v-if="file && resume" class="text-xs text-amber-300 mb-2" data-testid="import-resume-note">
      {{ t('orgTransfer.import.resuming', { size: formatBytes(resume.upload?.uploaded_bytes) }) }}
    </p>

    <div v-if="uploading || (progress.uploaded > 0 && progress.uploaded < progress.total)" class="mb-3" data-testid="import-upload-progress">
      <div class="flex justify-between text-[11px] text-gray-400 mb-1">
        <span>{{ t('orgTransfer.import.uploading', { uploaded: formatBytes(progress.uploaded), total: formatBytes(progress.total) }) }}</span>
        <span>{{ percent }}%</span>
      </div>
      <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all duration-300" style="background:rgb(var(--brand-500))" :style="{ width: percent + '%' }" />
      </div>
    </div>

    <div class="flex justify-end">
      <button type="button" class="btn-primary" :disabled="!file || uploading" data-testid="import-upload" @click="upload">
        <Upload class="w-4 h-4" />{{ resume ? t('orgTransfer.import.resume') : t('orgTransfer.import.upload') }}
      </button>
    </div>
    </template>
    <p v-if="error" class="text-sm text-red-400 mt-2" data-testid="import-error">{{ error }}</p>

    <!-- The package is being checked -->
    <div v-if="status === 'verifying'" class="mt-4 rounded-lg border border-gray-800 p-3 text-xs text-gray-300" data-testid="import-verifying">
      {{ t('orgTransfer.import.verifying') }}
      <span v-if="transfer.progress" class="text-gray-500"> · {{ transfer.progress }}%</span>
    </div>
    <p v-if="status === 'failed' && !transfer.plan && !fetchFailed" class="mt-4 text-sm text-red-400" data-testid="import-failed">{{ transfer.message }}</p>

    <ImportPlanView
      v-if="showPlan"
      :key="transfer.id"
      class="mt-5"
      :transfer="transfer"
      :regions="regions"
      @changed="emit('changed', $event)"
    />

    <div v-if="status === 'completed' && transfer.report" class="mt-3 flex justify-end">
      <button type="button" class="btn-secondary text-xs" data-testid="import-report-open" @click="emit('report', transfer)">
        <FileText class="w-3.5 h-3.5" />{{ t('orgTransfer.import.report') }}
      </button>
    </div>
  </div>
</template>
