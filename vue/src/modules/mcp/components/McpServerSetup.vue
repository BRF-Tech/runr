<script setup>
// Turns a project into a hosted MCP server (PUT /api/projects/{id}/mcp-server).
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { mcpApi } from '../api/mcp'
import AccessModePicker from './AccessModePicker.vue'
import { Bot } from 'lucide-vue-next'

const props = defineProps({ project: { type: Object, required: true } })
const emit = defineEmits(['saved'])
const { t } = useI18n()

const accessMode = ref('token')
const path = ref('/mcp')
const saving = ref(false)
const error = ref(null)

const httpTrigger = computed(() => (props.project.config?.trigger_type ?? 'http') === 'http')

async function save() {
  saving.value = true
  error.value = null
  try {
    const { data } = await mcpApi.saveServer(props.project.id, { access_mode: accessMode.value, path: path.value || '/mcp' })
    emit('saved', data.data ?? data)
  } catch (e) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 space-y-4" data-testid="mcp-setup">
    <div class="flex items-start gap-3">
      <Bot class="w-5 h-5 text-sky-400 shrink-0 mt-0.5" />
      <div>
        <p class="text-sm font-semibold text-gray-100">{{ t('mcp.project.setupTitle') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.project.setupHint') }}</p>
      </div>
    </div>

    <p v-if="!httpTrigger" class="text-xs text-amber-300" data-testid="mcp-setup-trigger">{{ t('mcp.project.needsHttp') }}</p>

    <div>
      <p class="label mb-2">{{ t('mcp.project.accessMode') }}</p>
      <AccessModePicker v-model="accessMode" />
    </div>

    <div>
      <label class="label" for="mcp-setup-path">{{ t('mcp.project.path') }}</label>
      <input id="mcp-setup-path" v-model="path" class="input w-56 font-mono" placeholder="/mcp" data-testid="mcp-setup-path" />
      <p class="text-[11px] text-gray-400 mt-1">{{ t('mcp.project.pathHint') }}</p>
    </div>

    <p v-if="error" class="text-xs text-red-400" data-testid="mcp-setup-error">{{ error }}</p>

    <button class="btn-primary text-xs" :disabled="saving || !httpTrigger" data-testid="mcp-setup-save" @click="save">
      {{ saving ? t('common.saving') : t('mcp.project.serve') }}
    </button>
  </section>
</template>
