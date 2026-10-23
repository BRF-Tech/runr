<script setup>
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Folder, File, FileSymlink, ArrowUp, RefreshCw, Upload, FolderPlus, Download, Archive, Trash2, X } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'
import { sandboxesApi } from '../api/sandboxes'
import {
  apiError, base64ToBytes, breadcrumbs, bytesToText, fileToBase64, formatBytes, formatDateTime,
  joinPath, parentPath, saveBlob,
} from '../utils'

const props = defineProps({
  sandboxId: { type: String, required: true },
  disabled:  { type: Boolean, default: false },
  root:      { type: String, default: '/workspace' },
})

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const path      = ref(props.root)
const entries   = ref([])
const truncated = ref(false)
const loading   = ref(false)
const error     = ref(null)
const preview   = ref(null) // { path, text, size, binary }
const newFolder = ref(null) // null = closed, string = name being typed
const uploading = ref(false)
const fileInput = ref(null)

// The folder and the file opened last: a slower answer for one opened before is dropped
const latestList = useLatest()
const latestFile = useLatest()

async function load(target = path.value) {
  if (props.disabled) return
  const current = latestList.begin()
  loading.value = true
  error.value   = null
  try {
    const { data } = await sandboxesApi.files.list(props.sandboxId, target)
    if (!current()) return
    const payload = data?.data ?? {}
    if (payload.is_dir === false) {
      // A file path: show its folder and preview it
      path.value = parentPath(payload.path ?? target)
      await load(path.value)
      return openFile({ path: payload.path ?? target, size: payload.entries?.[0]?.size ?? 0 })
    }
    path.value      = payload.path ?? target
    entries.value   = sortEntries(payload.entries ?? [])
    truncated.value = !!payload.truncated
  } catch (e) {
    if (current()) error.value = apiError(e, t('sandboxes.files.loadFailed'))
  } finally {
    if (current()) loading.value = false
  }
}

function sortEntries(list) {
  return [...list].sort((a, b) => (a.type === 'dir' ? 0 : 1) - (b.type === 'dir' ? 0 : 1) || String(a.name).localeCompare(String(b.name)))
}

function entryPath(entry) {
  return entry.path ?? joinPath(path.value, entry.name)
}

function open(entry) {
  if (entry.type === 'dir') {
    latestFile.cancel()
    preview.value = null
    load(entryPath(entry))
  } else {
    openFile({ path: entryPath(entry), size: entry.size })
  }
}

async function openFile({ path: filePath, size }) {
  const current = latestFile.begin()
  try {
    const { data } = await sandboxesApi.files.read(props.sandboxId, filePath)
    if (!current()) return
    const payload = data?.data ?? {}
    const bytes   = base64ToBytes(payload.content_base64 ?? '')
    const text    = bytesToText(bytes)
    preview.value = { path: filePath, size: payload.size ?? size ?? bytes.length, text, binary: text === null }
  } catch (e) {
    if (current()) toast.error(apiError(e, t('sandboxes.files.readFailed')))
  }
}

async function remove(entry) {
  const target = entryPath(entry)
  const isDir  = entry.type === 'dir'
  const ok = await confirm({
    title:        t('sandboxes.files.deleteTitle'),
    message:      isDir ? t('sandboxes.files.deleteDirConfirm', { path: target }) : t('sandboxes.files.deleteConfirm', { path: target }),
    destructive:  true,
    confirmLabel: t('sandboxes.actions.delete'),
  })
  if (!ok) return
  try {
    await sandboxesApi.files.remove(props.sandboxId, target, isDir)
    toast.success(t('sandboxes.files.deleted', { path: target }))
    if (preview.value?.path === target) preview.value = null
    await load()
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.list.actionFailed')))
  }
}

async function download(entry) {
  const target = entryPath(entry)
  try {
    if (entry.type === 'dir') {
      const { data } = await sandboxesApi.files.archive(props.sandboxId, target)
      saveBlob(data, `${entry.name || 'archive'}.tar`)
    } else {
      const { data } = await sandboxesApi.files.download(props.sandboxId, target)
      saveBlob(data, entry.name)
    }
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.files.readFailed')))
  }
}

async function downloadFolder() {
  await download({ type: 'dir', name: path.value.split('/').filter(Boolean).pop() || 'root', path: path.value })
}

function pickFiles() {
  fileInput.value?.click()
}

async function onFiles(event) {
  const files = [...(event.target?.files ?? [])]
  if (!files.length) return
  uploading.value = true
  let done = 0
  try {
    for (const file of files) {
      const b64 = await fileToBase64(file)
      await sandboxesApi.files.write(props.sandboxId, joinPath(path.value, file.name), b64)
      done++
    }
    toast.success(t('sandboxes.files.uploaded', { n: done }))
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.files.uploadFailed')))
  } finally {
    uploading.value = false
    if (event.target) event.target.value = ''
    await load()
  }
}

async function createFolder() {
  const name = String(newFolder.value ?? '').trim().replace(/^\/+|\/+$/g, '')
  if (!name) { newFolder.value = null; return }
  const target = joinPath(path.value, name)
  try {
    await sandboxesApi.files.mkdir(props.sandboxId, target)
    toast.success(t('sandboxes.files.folderCreated', { path: target }))
    newFolder.value = null
    await load()
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.list.actionFailed')))
  }
}

const typeIcon = (type) => type === 'dir' ? Folder : type === 'symlink' ? FileSymlink : File

watch(() => props.sandboxId, () => { path.value = props.root; preview.value = null; load() })
watch(() => props.disabled, (d) => { if (!d) load() })
onMounted(() => load())
</script>

<template>
  <div data-file-browser>
    <p v-if="disabled" class="text-sm text-gray-400 py-6 text-center">{{ t('sandboxes.detail.notRunning') }}</p>

    <template v-else>
      <!-- Toolbar -->
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <nav class="flex flex-wrap items-center gap-0.5 text-xs font-mono min-w-0" data-breadcrumbs>
          <template v-for="(crumb, i) in breadcrumbs(path)" :key="crumb.path">
            <span v-if="i > 1" class="text-gray-600">/</span>
            <button
              type="button"
              class="px-1 py-0.5 rounded-sm hover:bg-gray-800"
              :class="i === breadcrumbs(path).length - 1 ? 'text-gray-100' : 'text-gray-400 hover:text-gray-100'"
              :data-crumb="crumb.path"
              @click="preview = null; load(crumb.path)"
            >{{ crumb.name }}</button>
          </template>
        </nav>
        <div class="flex items-center gap-1">
          <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 disabled:opacity-40"
            :disabled="path === '/'" :title="t('sandboxes.actions.up')" @click="preview = null; load(parentPath(path))">
            <ArrowUp class="w-3.5 h-3.5" />
          </button>
          <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800"
            :title="t('sandboxes.actions.refresh')" @click="load()">
            <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
          </button>
          <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800"
            :title="t('sandboxes.actions.newFolder')" data-action="new-folder" @click="newFolder = ''">
            <FolderPlus class="w-3.5 h-3.5" />
          </button>
          <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800"
            :title="t('sandboxes.actions.downloadTar')" @click="downloadFolder">
            <Archive class="w-3.5 h-3.5" />
          </button>
          <button type="button" class="btn-secondary px-2.5! py-1! text-xs flex items-center gap-1" :disabled="uploading" data-action="upload" @click="pickFiles">
            <Upload class="w-3.5 h-3.5" />{{ t('sandboxes.actions.upload') }}
          </button>
          <input ref="fileInput" type="file" multiple class="hidden" data-upload-input @change="onFiles" />
        </div>
      </div>

      <!-- New folder -->
      <form v-if="newFolder !== null" class="flex items-center gap-2 mb-3" data-new-folder @submit.prevent="createFolder">
        <input v-model="newFolder" class="input font-mono text-xs" :placeholder="t('sandboxes.files.newFolderName')" autofocus />
        <button type="submit" class="btn-primary px-3! py-1.5! text-xs">{{ t('sandboxes.actions.add') }}</button>
        <button type="button" class="btn-secondary px-3! py-1.5! text-xs" @click="newFolder = null">{{ t('sandboxes.actions.cancel') }}</button>
      </form>

      <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>

      <!-- Entries -->
      <div class="rounded-lg border border-gray-800 overflow-hidden">
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800 bg-gray-900/60">
              <th class="px-3 py-2 font-medium">{{ t('sandboxes.files.name') }}</th>
              <th class="px-3 py-2 font-medium w-24">{{ t('sandboxes.files.size') }}</th>
              <th class="px-3 py-2 font-medium w-44">{{ t('sandboxes.files.modified') }}</th>
              <th class="px-3 py-2 w-20" />
            </tr>
          </thead>
          <tbody>
            <tr v-if="!entries.length && !loading">
              <td colspan="4" class="px-3 py-6 text-center text-gray-500">{{ t('sandboxes.files.empty') }}</td>
            </tr>
            <tr
              v-for="entry in entries" :key="entry.name"
              :data-file-row="entry.name"
              class="border-b border-gray-800/60 last:border-0 hover:bg-gray-800/30"
            >
              <td class="px-3 py-1.5">
                <button type="button" class="flex items-center gap-2 font-mono text-gray-200 hover:text-brand-500 text-left" @click="open(entry)">
                  <component :is="typeIcon(entry.type)" class="w-3.5 h-3.5 shrink-0" :class="entry.type === 'dir' ? 'text-sky-400' : 'text-gray-500'" />
                  <span class="truncate">{{ entry.name }}</span>
                </button>
              </td>
              <td class="px-3 py-1.5 font-mono text-gray-400">{{ entry.type === 'dir' ? '—' : formatBytes(entry.size) }}</td>
              <td class="px-3 py-1.5 font-mono text-gray-500">{{ formatDateTime(entry.modified_at) }}</td>
              <td class="px-3 py-1.5">
                <div class="flex items-center justify-end gap-1">
                  <button type="button" class="text-gray-500 hover:text-gray-100" :title="entry.type === 'dir' ? t('sandboxes.actions.downloadTar') : t('sandboxes.actions.download')" @click="download(entry)">
                    <Download class="w-3.5 h-3.5" />
                  </button>
                  <button type="button" class="text-gray-500 hover:text-red-400" :title="t('sandboxes.actions.delete')" @click="remove(entry)">
                    <Trash2 class="w-3.5 h-3.5" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="truncated" class="text-[11px] text-amber-400 mt-2">{{ t('sandboxes.files.truncated') }}</p>

      <!-- Preview -->
      <div v-if="preview" class="mt-4 rounded-lg border border-gray-800 bg-gray-950" data-file-preview>
        <div class="flex items-center justify-between px-3 py-2 border-b border-gray-800">
          <span class="text-xs font-mono text-gray-300 truncate">{{ preview.path }} · {{ formatBytes(preview.size) }}</span>
          <button type="button" class="text-gray-500 hover:text-gray-100" :title="t('sandboxes.actions.close')" @click="preview = null"><X class="w-3.5 h-3.5" /></button>
        </div>
        <p v-if="preview.binary" class="px-3 py-4 text-xs text-gray-400">{{ t('sandboxes.files.binary', { size: formatBytes(preview.size) }) }}</p>
        <pre v-else class="px-3 py-2 text-xs font-mono text-gray-200 overflow-auto max-h-80 whitespace-pre">{{ preview.text }}</pre>
      </div>
    </template>
  </div>
</template>
