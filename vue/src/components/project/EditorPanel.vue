<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MonacoEditor from '@/components/MonacoEditor.vue'
import FileSidebar from '@/components/FileSidebar.vue'
import CaasConfigViewer from '@/components/CaasConfigViewer.vue'
import { runtimeHandlerPattern } from '@/utils/runtimes'

const { t } = useI18n()

const props = defineProps({
  project:       { type: Object, required: true },
  files:         { type: Object, required: true },
  activeFile:    { type: String, default: null },
  isEditable:    { type: Boolean, default: false },
  saving:        { type: Boolean, default: false },
  mainFile:      { type: String, default: null },
  handlerError:  { type: Boolean, default: false },
  filesLoading:  { type: Boolean, default: false },
  isRuntimeEol:  { type: Boolean, default: false },
  commitMsg:     { type: String, default: '' },
  selectedVersion: { type: Object, default: null },
  snapshotRegionLabel: { type: String, default: null },
  hasVersions:   { type: Boolean, default: false },
  handlerName:   { type: String, default: 'handler' },
})

// Runtimes with a handler check (built-ins, manifests with handler.pattern) name the handler
// function in the hint; the others (exec / http runtimes) only have an entry file.
const hasHandlerCheck = computed(() => !!runtimeHandlerPattern(props.project?.runtime, props.handlerName))

const emit = defineEmits([
  'save',
  'add-file',
  'remove-file',
  'upload-file',
  'update:activeFile',
  'update:code',
  'update:commitMsg',
])

// CaaS and the retired AI types outside code mode (maas, blueprint graphs): the version's
// config snapshot, read-only
const isConfigViewer = computed(() => {
  const type = props.project?.service_type
  if (type === 'caas' || type === 'maas') return true
  return ['aaas', 'agentflow'].includes(type) && props.project?.config?.config_mode !== 'code'
})

const isCodeEditor = computed(() => {
  const type = props.project?.service_type
  if (['xaas', 'faas'].includes(type)) return true
  if (['aaas', 'agentflow'].includes(type) && props.project?.config?.config_mode === 'code') return true
  return false
})

const showFileSidebar = computed(() => {
  const type = props.project?.service_type
  if (type === 'faas') return true
  if (type === 'agentflow' && props.project?.config?.config_mode === 'code') return true
  return false
})

function onCodeUpdate(val) {
  emit('update:code', { file: props.activeFile, content: val })
}
</script>

<template>
  <div data-tour="editor-area" class="flex-1 flex flex-col gap-3 min-w-0">
    <div class="flex gap-3 flex-1 min-h-0">

      <!-- FaaS / AgentFlow code mode: file sidebar -->
      <FileSidebar
        v-if="showFileSidebar && (selectedVersion || !hasVersions)"
        :files="files"
        :active-file="activeFile"
        :is-latest="isEditable"
        :main-file="mainFile"
        @select="emit('update:activeFile', $event)"
        @add="emit('add-file', $event)"
        @remove="emit('remove-file', $event)"
        @upload="emit('upload-file', $event)"
      />

      <!-- CaaS / retired AI types: config_snapshot viewer -->
      <CaasConfigViewer
        v-if="isConfigViewer"
        :version="selectedVersion"
        :region-label="snapshotRegionLabel"
      />

      <!-- Code mode: Monaco editor + commit bar -->
      <div v-else-if="isCodeEditor" class="flex-1 flex flex-col gap-3 min-h-0">
        <div class="flex-1 card p-0 overflow-hidden flex flex-col">
          <div
            v-if="isRuntimeEol"
            class="text-xs text-red-400 bg-red-950/30 px-3 py-2 border-b border-red-800/50 shrink-0"
          >{{ t('project.eolBlocked') }}</div>
          <div
            v-if="mainFile"
            class="text-[10px] text-gray-400 px-3 py-1.5 border-b border-gray-800 shrink-0"
          >
            {{ hasHandlerCheck ? t('project.editor.handlerHint', { file: mainFile, fn: handlerName }) : t('runtimes.editor.entryHint', { file: mainFile }) }}
          </div>
          <div
            v-if="handlerError"
            class="text-xs text-red-400 bg-red-950/30 px-3 py-2 border-b border-red-800/50 shrink-0"
          >
            {{ t('project.editor.handlerMissing', { file: mainFile }) }}
          </div>

          <div v-if="filesLoading" class="flex-1 flex items-center justify-center text-gray-400 text-sm">
            {{ t('common.loading') }}
          </div>
          <MonacoEditor
            v-else-if="activeFile !== null"
            :model-value="files[activeFile]"
            @update:model-value="onCodeUpdate"
            :filename="activeFile"
            :runtime="project.runtime"
            height="100%"
            :readonly="!isEditable"
          />
          <div v-else class="flex-1 flex items-center justify-center text-gray-400 text-sm">
            {{ selectedVersion ? t('project.editor.selectFile') : t('project.editor.noVersionSelected') }}
          </div>
        </div>

        <div v-if="isEditable" data-tour="commit-bar" class="flex gap-3 shrink-0">
          <input
            :value="commitMsg"
            @input="emit('update:commitMsg', $event.target.value)"
            class="input flex-1"
            :placeholder="t('project.editor.commitMsg')"
          />
          <button class="btn-primary" :disabled="saving" @click="emit('save')">
            {{ saving ? t('project.editor.saving') : t('project.editor.save') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
