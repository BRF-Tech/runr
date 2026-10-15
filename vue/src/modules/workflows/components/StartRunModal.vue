<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Play } from 'lucide-vue-next'
import { workflowsApi } from '../api/workflows'
import { parseJsonObject } from '../utils/format'

const props = defineProps({
  projectId: { type: String, required: true },
  workflow:  { type: String, required: true },
})

const emit = defineEmits(['started', 'close'])
const { t } = useI18n()

const data           = ref('{\n  \n}')
const idempotencyKey = ref('')
const test           = ref(true)
const saving         = ref(false)
const error          = ref(null)

async function submit() {
  const parsed = parseJsonObject(data.value)
  if (parsed === null) {
    error.value = t('workflows.start.invalidJson')
    return
  }
  saving.value = true
  error.value  = null
  try {
    const payload = { data: parsed, test: test.value }
    if (idempotencyKey.value.trim()) payload.idempotency_key = idempotencyKey.value.trim()
    const res = await workflowsApi.startRun(props.projectId, props.workflow, payload)
    emit('started', { run: res.data.data, created: res.data.created })
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !saving.value) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-[2px] p-4" data-test="start-run-modal">
    <form class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-lg" @submit.prevent="submit">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
        <div>
          <h3 class="text-sm font-semibold text-gray-100">{{ t('workflows.start.title') }}</h3>
          <p class="text-xs font-mono text-gray-400 mt-0.5">{{ workflow }}</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-100 text-sm" :disabled="saving" @click="emit('close')">✕</button>
      </div>
      <div class="px-6 py-5 space-y-4">
        <div>
          <label class="label">{{ t('workflows.start.data') }}</label>
          <textarea v-model="data" rows="7" spellcheck="false" class="input font-mono text-xs resize-y" data-test="run-data" />
        </div>
        <div>
          <label class="label">{{ t('workflows.start.idempotencyKey') }}</label>
          <input v-model="idempotencyKey" class="input font-mono text-xs" maxlength="200" />
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-300">
          <input v-model="test" type="checkbox" class="rounded bg-gray-800 border-gray-700" />
          {{ t('workflows.start.test') }}
        </label>
        <p v-if="error" class="text-xs text-red-400" data-test="start-error">{{ error }}</p>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-800">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="saving" @click="emit('close')">{{ t('workflows.close') }}</button>
        <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="start-submit">
          <Play class="w-3.5 h-3.5" />{{ t('workflows.start.submit') }}
        </button>
      </div>
    </form>
  </div>
</template>
