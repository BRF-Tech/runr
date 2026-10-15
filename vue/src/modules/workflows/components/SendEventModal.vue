<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Send } from 'lucide-vue-next'
import { workflowsApi } from '../api/workflows'
import { parseJsonObject } from '../utils/format'

const emit = defineEmits(['sent', 'close'])
const { t } = useI18n()

const name    = ref('')
const data    = ref('{\n  \n}')
const eventId = ref('')
const saving  = ref(false)
const error   = ref(null)

async function submit() {
  const parsed = parseJsonObject(data.value)
  if (parsed === null) {
    error.value = t('workflows.start.invalidJson')
    return
  }
  if (!name.value.trim()) return
  saving.value = true
  error.value  = null
  try {
    const event = { name: name.value.trim(), data: parsed }
    if (eventId.value.trim()) event.id = eventId.value.trim()
    const res = await workflowsApi.sendEvent(event, { test: true })
    emit('sent', res.data.events?.[0] ?? null)
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
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-[2px] p-4" data-test="send-event-modal">
    <form class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-lg" @submit.prevent="submit">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('workflows.events.sendTitle') }}</h3>
        <button type="button" class="text-gray-400 hover:text-gray-100 text-sm" :disabled="saving" @click="emit('close')">✕</button>
      </div>
      <div class="px-6 py-5 space-y-4">
        <div>
          <label class="label">{{ t('workflows.events.eventName') }}</label>
          <input v-model="name" class="input font-mono text-xs" placeholder="order.created" required data-test="event-name" />
        </div>
        <div>
          <label class="label">{{ t('workflows.events.eventData') }}</label>
          <textarea v-model="data" rows="7" spellcheck="false" class="input font-mono text-xs resize-y" data-test="event-data" />
        </div>
        <div>
          <label class="label">{{ t('workflows.events.eventId') }}</label>
          <input v-model="eventId" class="input font-mono text-xs" maxlength="200" />
        </div>
        <p class="text-xs text-gray-400">{{ t('workflows.events.apiHint') }}</p>
        <p v-if="error" class="text-xs text-red-400" data-test="send-error">{{ error }}</p>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-800">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="saving" @click="emit('close')">{{ t('workflows.close') }}</button>
        <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving || !name.trim()" data-test="send-submit">
          <Send class="w-3.5 h-3.5" />{{ t('workflows.events.send') }}
        </button>
      </div>
    </form>
  </div>
</template>
