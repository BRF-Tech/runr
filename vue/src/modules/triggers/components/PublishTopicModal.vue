<script setup>
// Publishes a test event to a topic (POST /topics/{topic}/publish, events.send): JSON data,
// optional CloudEvent type and subject. Every subscribed function receives it.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Send } from 'lucide-vue-next'
import { useTopicsStore } from '../stores/topics'
import { errorMessage, parseEventData } from '../utils/triggers'

const props = defineProps({
  topic: { type: Object, required: true },
})
const emit = defineEmits(['published', 'close'])

const { t } = useI18n()
const store = useTopicsStore()

const data    = ref('{\n  "hello": "world"\n}')
const type    = ref('')
const subject = ref('')
const sending = ref(false)
const error   = ref(null)

const parsed = computed(() => parseEventData(data.value))

async function submit() {
  if (!parsed.value.ok) {
    error.value = t('triggers.topics.dataInvalid')
    return
  }
  sending.value = true
  error.value   = null
  try {
    const result = await store.publish(props.topic, { data: parsed.value.value, type: type.value, subject: subject.value })
    emit('published', result)
  } catch (e) {
    error.value = errorMessage(e, t('common.error'))
  } finally {
    sending.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !sending.value) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-[2px] p-4" data-test="publish-topic-modal">
    <form class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-lg" novalidate @submit.prevent="submit">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('triggers.topics.publishTitle', { name: topic.name }) }}</h3>
        <button type="button" class="text-gray-400 hover:text-gray-100 text-sm" :disabled="sending" :aria-label="t('triggers.topics.close')" @click="emit('close')">✕</button>
      </div>
      <div class="px-6 py-5 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="label">{{ t('triggers.topics.type') }}</label>
            <input v-model="type" class="input font-mono text-xs" placeholder="order.created" maxlength="255" data-field="type" />
          </div>
          <div>
            <label class="label">{{ t('triggers.topics.subject') }}</label>
            <input v-model="subject" class="input font-mono text-xs" placeholder="orders/42" maxlength="255" data-field="subject" />
          </div>
        </div>
        <div>
          <label class="label">{{ t('triggers.topics.data') }}</label>
          <textarea v-model="data" rows="8" spellcheck="false" class="input font-mono text-xs resize-y" data-field="data" />
          <p v-if="!parsed.ok" class="text-[11px] text-red-400 mt-1">{{ t('triggers.topics.dataInvalid') }}</p>
        </div>
        <p class="text-xs text-gray-400">{{ t('triggers.topics.publishHint') }}</p>
        <p v-if="error" class="text-xs text-red-400" data-test="publish-error">{{ error }}</p>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-800">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="sending" @click="emit('close')">{{ t('triggers.topics.cancel') }}</button>
        <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="sending || !parsed.ok" data-test="publish-submit">
          <Send class="w-3.5 h-3.5" />{{ t('triggers.topics.send') }}
        </button>
      </div>
    </form>
  </div>
</template>
