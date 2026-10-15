<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { Upload, FilePlus2, Trash2, CheckCircle2, AlertTriangle, Loader2, FileText } from 'lucide-vue-next'
import MonacoEditor from '@/components/MonacoEditor.vue'
import { runtimesApi } from '@/api/runtimes'
import { useSettingsStore } from '@/stores/settings'
import { apiErrorMessage, apiProblems } from '@/composables/useRuntimesManager'
import RuntimeUploadVersion from '@/modules/images/components/RuntimeUploadVersion.vue'
import { appendUploadVersion } from '@/modules/images/manifest'
import TermText from '@/components/TermText.vue'

const props = defineProps({
  runtime:   { type: Object, default: null }, // null = add
  canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'saved'])

const { t } = useI18n()
const settingsStore = useSettingsStore()

const isEdit    = computed(() => !!props.runtime)
const isBuiltin = computed(() => props.runtime?.source === 'builtin')

/** Skeleton of a new runtime (exec mode, the smallest working manifest). */
const STARTER_MANIFEST = `apiVersion: runr.sh/v1
kind: Runtime
id: my-runtime
name: My Runtime
description: A runtime of my own
language: shell
extensions: [sh]
versions:
  - version: "1.0"
    image: ghcr.io/brf-tech/reqs/alpine:3.21
    default: true
handler:
  file: main
template:
  files:
    "{file}": |
      #!/bin/sh
      echo "Hello from my runtime"
run:
  mode: exec
  input: raw
  command: ["sh", "/app/code/{file}"]
`

// ── State ────────────────────────────────────────────────────────────────────
const loadingDetail = ref(false)
const manifestText  = ref(isEdit.value ? '' : STARTER_MANIFEST)
const files         = ref([])        // [{ name, content }]
const existingNames = ref([])        // edit: files the runtime has (contents not sent by the API)
const filesTouched  = ref(false)
const activeFile    = ref(null)
const regionsMode   = ref('all')     // all | selected
const selectedRegions = ref([])
const enabled       = ref(true)

const saving     = ref(false)
const saveError  = ref(null)
const problems   = ref([])           // server problems of the last validate / save
const validating = ref(false)
const validated  = ref(null)         // normalized manifest of the last valid result
let validateSeq   = 0
let validateTimer = null

const regions = computed(() => settingsStore.data.regions ?? [])

// ── Load (edit) ──────────────────────────────────────────────────────────────
onMounted(async () => {
  if (!settingsStore.loaded) settingsStore.fetch()
  if (!isEdit.value) { scheduleValidate(); return }

  regionsMode.value     = Array.isArray(props.runtime.regions) ? 'selected' : 'all'
  selectedRegions.value = Array.isArray(props.runtime.regions) ? [...props.runtime.regions] : []
  enabled.value         = props.runtime.is_enabled !== false

  loadingDetail.value = true
  try {
    const { data } = await runtimesApi.get(props.runtime.id)
    const d = data.data ?? data
    manifestText.value = d.manifest_source || (d.manifest ? JSON.stringify(d.manifest, null, 2) : '')
    if (d.files && !Array.isArray(d.files) && typeof d.files === 'object') {
      files.value = Object.entries(d.files).map(([name, content]) => ({ name, content: String(content ?? '') }))
    } else {
      existingNames.value = Array.isArray(d.files) ? d.files : []
    }
    activeFile.value = files.value[0]?.name ?? null
    if (!isBuiltin.value) scheduleValidate()
  } catch (e) {
    saveError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loadingDetail.value = false
  }
})

onBeforeUnmount(() => clearTimeout(validateTimer))

// ── Live validation ──────────────────────────────────────────────────────────
function scheduleValidate() {
  clearTimeout(validateTimer)
  validateTimer = setTimeout(validate, 600)
}

async function validate() {
  const text = manifestText.value
  if (!text.trim()) { problems.value = []; validated.value = null; return }
  const seq = ++validateSeq
  validating.value = true
  try {
    // The server checks dockerfile versions against the file names (contents are sent on save)
    const names = Object.fromEntries(visibleFileNames.value.map(n => [n, '']))
    const { data } = await runtimesApi.validate(text, Object.keys(names).length ? names : undefined)
    if (seq !== validateSeq) return
    problems.value  = []
    validated.value = data.manifest ?? {}
  } catch (e) {
    if (seq !== validateSeq) return
    validated.value = null
    problems.value  = apiProblems(e)
    if (!problems.value.length) problems.value = [{ path: '', message: apiErrorMessage(e, t('common.error')) }]
  } finally {
    if (seq === validateSeq) validating.value = false
  }
}

watch(manifestText, () => { if (!isBuiltin.value && !loadingDetail.value) scheduleValidate() })

// ── Files (Docker build context of dockerfile versions) ──────────────────────
const MAX_FILES_BYTES = 900 * 1024
const filesBytes = computed(() => files.value.reduce((s, f) => s + new Blob([f.content]).size, 0))
const visibleFileNames = computed(() => filesTouched.value || !existingNames.value.length
  ? files.value.map(f => f.name)
  : existingNames.value)

/** Files the manifest's dockerfile versions reference that are not part of the runtime. */
const unresolvedFiles = computed(() => {
  const versions = validated.value?.versions
  if (!Array.isArray(versions)) return []
  const names = new Set(visibleFileNames.value)
  return versions
    .filter(v => v.dockerfile && !names.has(v.dockerfile))
    .map(v => ({ version: v.version, file: v.dockerfile }))
})

// Adding or removing a file can resolve (or break) a dockerfile version reference
watch(() => visibleFileNames.value.join(','), () => { if (!isBuiltin.value && !loadingDetail.value) scheduleValidate() })

function startReplacingFiles() {
  if (filesTouched.value) return
  filesTouched.value = true
  if (existingNames.value.length) files.value = []
}

const fileError = ref(null)

async function addUploads(event) {
  fileError.value = null
  startReplacingFiles()
  for (const file of Array.from(event.target.files ?? [])) {
    const content = await file.text()
    if (content.includes('\u0000')) { fileError.value = t('runtimes.editor.binaryFile', { name: file.name }); continue }
    upsertFile(file.name, content)
  }
  event.target.value = ''
}

function upsertFile(name, content) {
  const idx = files.value.findIndex(f => f.name === name)
  if (idx === -1) files.value.push({ name, content })
  else files.value[idx] = { name, content }
  activeFile.value = name
}

function newFile() {
  startReplacingFiles()
  let name = files.value.some(f => f.name === 'Dockerfile') ? '' : 'Dockerfile'
  for (let i = 1; !name; i++) if (!files.value.some(f => f.name === `file-${i}.txt`)) name = `file-${i}.txt`
  upsertFile(name, '')
}

function removeFile(name) {
  startReplacingFiles()
  files.value = files.value.filter(f => f.name !== name)
  if (activeFile.value === name) activeFile.value = files.value[0]?.name ?? null
}

function renameFile(oldName, newName) {
  const name = newName.trim()
  if (!name || name === oldName || files.value.some(f => f.name === name)) return
  const f = files.value.find(x => x.name === oldName)
  if (f) { f.name = name; filesTouched.value = true }
  if (activeFile.value === oldName) activeFile.value = name
}

const activeFileContent = computed({
  get: () => files.value.find(f => f.name === activeFile.value)?.content ?? '',
  set: (v) => {
    const f = files.value.find(x => x.name === activeFile.value)
    if (f && f.content !== v) { f.content = v; filesTouched.value = true }
  },
})

/** A version with an uploaded image as its source, added to the manifest text (validated as you type). */
function addUploadVersion(entry) {
  manifestText.value = appendUploadVersion(manifestText.value, entry)
}

async function uploadManifest(event) {
  const file = event.target.files?.[0]
  if (file) manifestText.value = await file.text()
  event.target.value = ''
}

// ── Save ─────────────────────────────────────────────────────────────────────
function toggleRegion(id) {
  const i = selectedRegions.value.indexOf(id)
  if (i === -1) selectedRegions.value.push(id)
  else selectedRegions.value.splice(i, 1)
}

const canSave = computed(() => {
  if (saving.value || loadingDetail.value) return false
  if (regionsMode.value === 'selected' && !selectedRegions.value.length) return false
  if (isBuiltin.value) return true
  return !!manifestText.value.trim() && filesBytes.value <= MAX_FILES_BYTES
})

async function save() {
  saveError.value = null
  saving.value    = true
  const regionsPayload = regionsMode.value === 'all' ? null : [...selectedRegions.value]
  try {
    let res
    if (isBuiltin.value) {
      res = await runtimesApi.update(props.runtime.id, { regions: regionsPayload, is_enabled: enabled.value })
    } else {
      const payload = { manifest: manifestText.value, regions: regionsPayload }
      if (!isEdit.value || filesTouched.value) payload.files = Object.fromEntries(files.value.map(f => [f.name, f.content]))
      if (isEdit.value) {
        payload.is_enabled = enabled.value
        res = await runtimesApi.update(props.runtime.id, payload)
      } else {
        res = await runtimesApi.create(payload)
      }
    }
    emit('saved', { runtime: res.data.data ?? res.data, created: !isEdit.value })
  } catch (e) {
    const list = apiProblems(e)
    if (e?.response?.status === 422 && list.length) {
      problems.value  = list
      validated.value = null
      saveError.value = t('runtimes.editor.saveInvalid')
    } else {
      saveError.value = apiErrorMessage(e, t('common.error'))
    }
  } finally {
    saving.value = false
  }
}

const title = computed(() => {
  if (!isEdit.value) return t('runtimes.editor.titleAdd')
  return t('runtimes.editor.titleEdit', { name: props.runtime.name })
})

const scopeHint = computed(() => props.canManage ? t('runtimes.editor.scopePlatform') : t('runtimes.editor.scopeCustom'))

function fmtBytes(n) { return n >= 1024 ? `${(n / 1024).toFixed(1)} KB` : `${n} B` }
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-5xl max-h-[92vh] overflow-y-auto">
      <div class="card">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4 mb-5">
          <div>
            <h2 class="text-lg font-semibold text-gray-100">{{ title }}</h2>
            <p class="text-xs text-gray-400 mt-0.5">{{ isBuiltin ? t('runtimes.editor.builtinReadonly') : (isEdit ? t('runtimes.editor.editHint') : scopeHint) }}</p>
          </div>
          <button class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
        </div>

        <div v-if="loadingDetail" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

        <div v-else class="grid grid-cols-1 lg:grid-cols-5 gap-5">
          <!-- Manifest -->
          <div class="lg:col-span-3 space-y-3">
            <div class="flex items-center justify-between">
              <p class="section-title mb-0! border-0! pb-0!"><TermText path="runtimes.editor.manifest" /></p>
              <label v-if="!isBuiltin" class="btn-secondary text-xs flex items-center gap-1.5 cursor-pointer">
                <Upload class="w-3.5 h-3.5" />{{ t('runtimes.editor.uploadManifest') }}
                <input type="file" accept=".yaml,.yml,.json" class="hidden" @change="uploadManifest" />
              </label>
            </div>
            <div class="rounded-lg border border-gray-800 overflow-hidden">
              <MonacoEditor v-model="manifestText" filename="runtime.yaml" language="yaml" height="420px" :readonly="isBuiltin" />
            </div>

            <!-- Validation -->
            <div v-if="!isBuiltin" class="rounded-lg border px-3 py-2.5 text-xs"
              :class="problems.length ? 'border-red-800/60 bg-red-950/20' : validated ? 'border-emerald-800/50 bg-emerald-950/20' : 'border-gray-800 bg-gray-900/40'">
              <div v-if="validating" class="flex items-center gap-2 text-gray-400">
                <Loader2 class="w-3.5 h-3.5 animate-spin" />{{ t('runtimes.editor.validating') }}
              </div>
              <template v-else-if="problems.length">
                <p class="flex items-center gap-2 text-red-400 font-medium mb-1.5">
                  <AlertTriangle class="w-3.5 h-3.5" />{{ t('runtimes.editor.problems', { n: problems.length }) }}
                </p>
                <ul class="space-y-1">
                  <li v-for="(p, i) in problems" :key="i" class="flex gap-2">
                    <code class="font-mono text-[11px] text-red-300 shrink-0">{{ p.path || '/' }}</code>
                    <span class="text-gray-300">{{ p.message }}</span>
                  </li>
                </ul>
              </template>
              <p v-else-if="validated" class="flex items-center gap-2 text-emerald-400">
                <CheckCircle2 class="w-3.5 h-3.5" />
                {{ t('runtimes.editor.valid', { name: validated.name ?? '', id: validated.id ?? '', n: validated.versions?.length ?? 0 }) }}
              </p>
              <p v-else class="text-gray-400">{{ t('runtimes.editor.validateHint') }}</p>
              <p v-for="u in unresolvedFiles" :key="u.version" class="mt-1.5 flex items-center gap-2 text-amber-400">
                <AlertTriangle class="w-3.5 h-3.5" />{{ t('runtimes.editor.unresolvedFile', { file: u.file, version: u.version }) }}
              </p>
            </div>
          </div>

          <!-- Side: regions, enabled, files -->
          <div class="lg:col-span-2 space-y-5">
            <section>
              <p class="section-title">{{ t('runtimes.editor.regions') }}</p>
              <div class="flex gap-2 mb-3">
                <button type="button" class="chip" :class="regionsMode === 'all' && 'chip-active'" @click="regionsMode = 'all'">{{ t('runtimes.editor.regionsAll') }}</button>
                <button type="button" class="chip" :class="regionsMode === 'selected' && 'chip-active'" @click="regionsMode = 'selected'">{{ t('runtimes.editor.regionsSelected') }}</button>
              </div>
              <div v-if="regionsMode === 'selected'" class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                <label v-for="r in regions" :key="r.id" class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                  <input type="checkbox" class="accent-brand-500" :checked="selectedRegions.includes(r.id)" @change="toggleRegion(r.id)" />
                  <span class="font-mono text-xs">{{ r.name }}</span>
                  <span v-if="!r.is_active" class="text-[10px] text-gray-500">{{ t('settings.regions.inactive') }}</span>
                </label>
                <p v-if="!regions.length" class="text-xs text-gray-400">{{ t('runtimes.editor.noRegions') }}</p>
                <p v-else-if="!selectedRegions.length" class="text-xs text-amber-400">{{ t('runtimes.editor.pickRegion') }}</p>
              </div>
            </section>

            <!-- source "upload": a version whose image is an uploaded image (docs/images.md) -->
            <RuntimeUploadVersion v-if="!isBuiltin" @add="addUploadVersion" />

            <section v-if="isEdit">
              <p class="section-title">{{ t('runtimes.editor.status') }}</p>
              <label class="flex items-center gap-3 cursor-pointer">
                <button type="button" class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors"
                  :class="enabled ? 'bg-brand-500' : 'bg-gray-700'" @click="enabled = !enabled">
                  <span class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition" :class="enabled ? 'translate-x-4' : 'translate-x-0'" />
                </button>
                <span class="text-sm" :class="enabled ? 'text-brand-500' : 'text-gray-400'">{{ enabled ? t('runtimes.list.enabled') : t('runtimes.list.disabled') }}</span>
              </label>
              <p class="text-[11px] text-gray-400 mt-1.5">{{ t('runtimes.editor.statusHint') }}</p>
            </section>

            <section v-if="!isBuiltin">
              <div class="flex items-center justify-between section-title">
                <span>{{ t('runtimes.editor.files') }}</span>
                <span class="font-mono normal-case text-[10px]" :class="filesBytes > MAX_FILES_BYTES ? 'text-red-400' : 'text-gray-500'">{{ fmtBytes(filesBytes) }}</span>
              </div>
              <p class="text-[11px] text-gray-400 mb-2">{{ t('runtimes.editor.filesHint') }}</p>
              <p v-if="isEdit && existingNames.length && !filesTouched" class="text-[11px] text-amber-400/90 mb-2">{{ t('runtimes.editor.replaceFilesHint') }}</p>
              <p v-if="filesBytes > MAX_FILES_BYTES" class="text-[11px] text-red-400 mb-2">{{ t('runtimes.editor.filesTooLarge') }}</p>
              <p v-if="fileError" class="text-[11px] text-red-400 mb-2">{{ fileError }}</p>

              <div class="flex gap-2 mb-2">
                <label class="btn-secondary text-xs flex items-center gap-1.5 cursor-pointer">
                  <Upload class="w-3.5 h-3.5" />{{ t('runtimes.editor.addFiles') }}
                  <input type="file" multiple class="hidden" @change="addUploads" />
                </label>
                <button type="button" class="btn-secondary text-xs flex items-center gap-1.5" @click="newFile">
                  <FilePlus2 class="w-3.5 h-3.5" />{{ t('runtimes.editor.newFile') }}
                </button>
              </div>

              <!-- Existing files (names only) -->
              <ul v-if="isEdit && existingNames.length && !filesTouched" class="space-y-1">
                <li v-for="name in existingNames" :key="name" class="flex items-center gap-2 text-xs text-gray-300 font-mono">
                  <FileText class="w-3.5 h-3.5 text-gray-500" />{{ name }}
                </li>
              </ul>

              <template v-else-if="files.length">
                <ul class="space-y-1 mb-2">
                  <li v-for="f in files" :key="f.name"
                    class="flex items-center gap-2 rounded-md border px-2 py-1"
                    :class="activeFile === f.name ? 'border-sky-700/60 bg-sky-950/30' : 'border-gray-800 bg-gray-900/40'">
                    <FileText class="w-3.5 h-3.5 text-gray-500 shrink-0" />
                    <input
                      :value="f.name"
                      class="flex-1 min-w-0 bg-transparent text-xs font-mono text-gray-200 focus:outline-hidden"
                      @focus="activeFile = f.name"
                      @change="renameFile(f.name, $event.target.value)"
                    />
                    <button type="button" class="text-gray-500 hover:text-red-400" :title="t('runtimes.editor.removeFile')" @click="removeFile(f.name)">
                      <Trash2 class="w-3.5 h-3.5" />
                    </button>
                  </li>
                </ul>
                <div v-if="activeFile" class="rounded-lg border border-gray-800 overflow-hidden">
                  <MonacoEditor v-model="activeFileContent" :filename="activeFile" height="200px" />
                </div>
              </template>
              <p v-else class="text-xs text-gray-500">{{ t('runtimes.editor.noFiles') }}</p>
            </section>
          </div>
        </div>

        <div class="flex items-center justify-between gap-3 pt-4 mt-5 border-t border-gray-800/60">
          <p class="text-sm text-red-400 min-w-0 truncate" :title="saveError">{{ saveError }}</p>
          <div class="flex gap-2 shrink-0">
            <button class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button class="btn-primary" :disabled="!canSave" @click="save">
              {{ saving ? t('common.saving') : (isEdit ? t('common.save') : t('runtimes.editor.create')) }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@reference "../../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
.chip {
  @apply px-3 py-1.5 rounded-md border text-xs font-medium transition-all bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500;
}
.chip-active {
  @apply text-white border-transparent;
  background-color: rgb(var(--brand-600));
}
</style>
