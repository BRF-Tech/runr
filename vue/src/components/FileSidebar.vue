<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isValidFileName } from '@/composables/projectValidation'

const { t } = useI18n()

defineProps({
  files:      { type: Object, default: () => ({}) },
  activeFile: { type: String, default: null },
  isLatest:   { type: Boolean, default: false },
  mainFile:   { type: String, default: null },
})

const emit = defineEmits(['select', 'add', 'remove', 'upload'])

const newFilename = ref('')
const fileInput = ref(null)
const uploadError = ref('')
let errorTimer = null

const ALLOWED_EXTS = new Set([
  'py', 'js', 'ts', 'go', 'php', 'rb', 'sh',
  'json', 'yaml', 'yml', 'html', 'css', 'md', 'txt',
  'toml', 'env', 'cfg', 'ini', 'xml', 'sql',
  'dockerfile', 'makefile', 'gitignore',
])

const MAX_SIZE = 512 * 1024

function addFile() {
  const name = newFilename.value.trim()
  if (!name) return
  // file names become ConfigMap keys on deploy: flat names only
  if (!isValidFileName(name)) { showError(t('create.validation.fileNameInvalid')); return }
  emit('add', name)
  newFilename.value = ''
}

function triggerUpload() {
  fileInput.value?.click()
}

function showError(msg) {
  uploadError.value = msg
  clearTimeout(errorTimer)
  errorTimer = setTimeout(() => { uploadError.value = '' }, 3000)
}

function getExtension(filename) {
  const lower = filename.toLowerCase()
  // extensionless files like Dockerfile, Makefile, .gitignore
  const base = lower.split('/').pop()
  if (!base.includes('.')) return base  // e.g. 'dockerfile'
  if (base.startsWith('.')) return base.slice(1)  // e.g. '.gitignore' → 'gitignore'
  return base.split('.').pop()
}

function isExtensionAllowed(filename) {
  const ext = getExtension(filename)
  return ALLOWED_EXTS.has(ext)
}

async function handleFiles(event) {
  const files = event.target.files
  if (!files?.length) return

  for (const file of files) {
    // Size check
    if (file.size > MAX_SIZE) {
      showError(t('project.editor.fileTooLarge'))
      continue
    }

    // Extension check
    if (!isExtensionAllowed(file.name)) {
      showError(t('project.editor.fileTypeNotAllowed'))
      continue
    }

    if (!isValidFileName(file.name)) {
      showError(t('create.validation.fileNameInvalid'))
      continue
    }

    // Read as text + binary check
    try {
      const content = await readFileAsText(file)
      if (content.includes('\0')) {
        showError(t('project.editor.fileBinary'))
        continue
      }
      emit('upload', { name: file.name, content })
    } catch {
      showError(t('project.editor.fileBinary'))
    }
  }

  // Reset input so same file can be re-selected
  event.target.value = ''
}

function readFileAsText(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(reader.error)
    reader.readAsText(file)
  })
}
</script>

<template>
  <div class="w-40 shrink-0 flex flex-col gap-1 overflow-y-auto">
    <div class="flex items-center justify-between px-1 mb-1">
      <p class="text-xs text-gray-400 uppercase tracking-wider">
        {{ t('project.tabs.files') }}
      </p>
      <button
        v-if="isLatest"
        class="text-gray-400 hover:text-gray-100 transition-colors"
        :title="t('project.editor.upload')"
        @click="triggerUpload"
      >
        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd" />
        </svg>
      </button>
      <input
        ref="fileInput"
        type="file"
        multiple
        class="hidden"
        @change="handleFiles"
      />
    </div>

    <!-- Upload error -->
    <p v-if="uploadError" class="text-[10px] text-red-400 px-1 transition-opacity">
      {{ uploadError }}
    </p>

    <div
      v-for="name in Object.keys(files)" :key="name"
      class="flex items-center justify-between px-2.5 py-1.5 rounded-md text-sm font-mono
             cursor-pointer transition-colors group"
      :class="activeFile === name ? 'text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-gray-100'"
      :style="activeFile === name ? 'background-color:rgb(var(--brand-600))' : ''"
      @click="$emit('select', name)"
    >
      <span class="truncate">{{ name }}</span>
      <button
        v-if="isLatest && name !== mainFile"
        class="opacity-0 group-hover:opacity-60 hover:!opacity-100 text-xs transition-opacity ml-1 shrink-0"
        @click.stop="$emit('remove', name)"
      >✕</button>
    </div>

    <!-- Yeni dosya (sadece latest) -->
    <div v-if="isLatest" class="flex gap-1 mt-2 px-0.5">
      <input
        v-model="newFilename"
        class="input text-xs py-1 px-2 flex-1 min-w-0 font-mono"
        placeholder="file.py"
        @keyup.enter="addFile"
      />
      <button class="btn-secondary py-1 px-2 text-xs shrink-0" @click="addFile">+</button>
    </div>
  </div>
</template>
