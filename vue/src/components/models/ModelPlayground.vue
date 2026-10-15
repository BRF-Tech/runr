<script setup>
// Task-specific test form of a model service: the request goes through the RunR API to the
// cluster-local service (POST /model-services/{id}/invoke).
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Play } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { playgroundKind, playgroundRequest, playgroundResult, fileToBase64, FILE_KINDS } from '@/utils/ai'

const { t } = useI18n()

const props = defineProps({
  service: { type: Object, required: true },
})

const kind = computed(() => playgroundKind(props.service))

const input = reactive({
  text:      '',
  system:    '',
  question:  '',
  context:   '',
  prompt:    '',
  maxTokens: 256,
  fileBase64: '',
  fileName:  '',
  path:      props.service?.playground?.path ?? '/',
  method:    'POST',
  rawBody:   '{\n  "inputs": "Hello"\n}',
})

const running  = ref(false)
const response = ref(null)   // { status, duration_ms, content_type, body }
const error    = ref(null)
const result   = computed(() => response.value ? playgroundResult(kind.value, response.value.body) : null)

const placeholders = {
  chat:                   'ai.playground.placeholders.chat',
  completion:             'ai.playground.placeholders.completion',
  embeddings:             'ai.playground.placeholders.embeddings',
  classification:         'ai.playground.placeholders.classification',
  'token-classification': 'ai.playground.placeholders.ner',
  summarization:          'ai.playground.placeholders.summarization',
  translation:            'ai.playground.placeholders.translation',
  'fill-mask':            'ai.playground.placeholders.fillMask',
}

const ready = computed(() => props.service?.status === 'ready')

const canRun = computed(() => {
  if (running.value) return false
  if (FILE_KINDS.includes(kind.value)) return !!input.fileBase64
  if (kind.value === 'question-answering') return !!input.question.trim() && !!input.context.trim()
  if (kind.value === 'text-to-image') return !!input.prompt.trim()
  if (kind.value === 'generic') return !!input.path
  return !!input.text.trim()
})

async function onFile(event) {
  const file = event.target.files?.[0]
  if (!file) return
  input.fileName   = file.name
  input.fileBase64 = await fileToBase64(file)
}

async function run() {
  running.value = true
  error.value   = null
  response.value = null
  try {
    const { data } = await aiApi.invokeService(props.service.id, playgroundRequest(kind.value, props.service, input))
    response.value = data
  } catch (e) {
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    running.value = false
  }
}
</script>

<template>
  <div class="space-y-3" data-testid="playground" :data-kind="kind">
    <p v-if="!ready" class="text-xs text-amber-300/90 bg-amber-900/20 rounded-sm px-3 py-2">{{ t('ai.playground.notReady') }}</p>
    <p class="text-[11px] text-gray-400">{{ t(`ai.playground.kinds.${kind}`) }}</p>

    <!-- Chat -->
    <template v-if="kind === 'chat'">
      <div>
        <label class="label" for="pg-system">{{ t('ai.playground.system') }}</label>
        <input id="pg-system" v-model="input.system" class="input text-sm" :placeholder="t('ai.playground.systemPlaceholder')" />
      </div>
      <div>
        <label class="label" for="pg-text">{{ t('ai.playground.message') }}</label>
        <textarea id="pg-text" v-model="input.text" rows="4" class="input text-sm" :placeholder="t(placeholders.chat)" data-testid="pg-text" />
      </div>
      <div class="w-40">
        <label class="label" for="pg-max">{{ t('ai.playground.maxTokens') }}</label>
        <input id="pg-max" v-model.number="input.maxTokens" type="number" min="1" max="8192" class="input font-mono" />
      </div>
    </template>

    <!-- Question answering -->
    <template v-else-if="kind === 'question-answering'">
      <div>
        <label class="label" for="pg-question">{{ t('ai.playground.question') }}</label>
        <input id="pg-question" v-model="input.question" class="input text-sm" data-testid="pg-question" />
      </div>
      <div>
        <label class="label" for="pg-context">{{ t('ai.playground.context') }}</label>
        <textarea id="pg-context" v-model="input.context" rows="4" class="input text-sm" data-testid="pg-context" />
      </div>
    </template>

    <!-- Speech / image file -->
    <template v-else-if="kind === 'asr' || kind === 'image-classification'">
      <label class="label" for="pg-file">{{ t(kind === 'asr' ? 'ai.playground.audioFile' : 'ai.playground.imageFile') }}</label>
      <input id="pg-file" type="file" :accept="kind === 'asr' ? 'audio/*' : 'image/*'" class="text-xs text-gray-300" data-testid="pg-file" @change="onFile" />
      <p v-if="input.fileName" class="text-[11px] text-gray-400 font-mono">{{ input.fileName }}</p>
    </template>

    <!-- Text to image -->
    <template v-else-if="kind === 'text-to-image'">
      <label class="label" for="pg-prompt">{{ t('ai.playground.prompt') }}</label>
      <textarea id="pg-prompt" v-model="input.prompt" rows="3" class="input text-sm" :placeholder="t('ai.playground.placeholders.image')" data-testid="pg-prompt" />
    </template>

    <!-- Any other service: path + JSON body -->
    <template v-else-if="kind === 'generic'">
      <div class="grid grid-cols-4 gap-3">
        <div>
          <label class="label" for="pg-method">{{ t('ai.playground.method') }}</label>
          <select id="pg-method" v-model="input.method" class="input"><option>POST</option><option>GET</option></select>
        </div>
        <div class="col-span-3">
          <label class="label" for="pg-path">{{ t('ai.playground.path') }}</label>
          <input id="pg-path" v-model="input.path" class="input font-mono" />
        </div>
      </div>
      <div>
        <label class="label" for="pg-body">{{ t('ai.playground.body') }}</label>
        <textarea id="pg-body" v-model="input.rawBody" rows="5" class="input font-mono text-xs" />
      </div>
    </template>

    <!-- Text tasks: embeddings, classification, NER, summarization, translation, fill-mask, completion -->
    <template v-else>
      <label class="label" for="pg-text">{{ t('ai.playground.input') }}</label>
      <textarea id="pg-text" v-model="input.text" rows="4" class="input text-sm" :placeholder="t(placeholders[kind] ?? 'ai.playground.placeholders.completion')" data-testid="pg-text" />
      <p v-if="kind === 'embeddings'" class="text-[11px] text-gray-400">{{ t('ai.playground.embeddingsHint') }}</p>
      <p v-if="kind === 'fill-mask'" class="text-[11px] text-gray-400">{{ t('ai.playground.fillMaskHint') }}</p>
    </template>

    <button class="btn-primary text-xs" :disabled="!canRun" data-testid="pg-run" @click="run">
      <Play class="w-3.5 h-3.5" />{{ running ? t('ai.playground.running') : t('ai.playground.run') }}
    </button>

    <p v-if="error" class="text-xs text-red-400" data-testid="pg-error">{{ error }}</p>

    <div v-if="response" class="rounded-lg border border-gray-800 bg-gray-900/60 p-3 space-y-2" data-testid="pg-result">
      <p class="text-[11px] font-mono" :class="response.status < 400 ? 'text-emerald-400' : 'text-red-400'">
        HTTP {{ response.status }} · {{ response.duration_ms }} ms
      </p>
      <template v-if="result.type === 'embeddings'">
        <p class="text-xs text-gray-300" data-testid="pg-embeddings">{{ t('ai.playground.embeddingsResult', { count: result.count, dimensions: result.dimensions }) }}</p>
        <p class="text-[11px] text-gray-400 font-mono break-all">[{{ result.preview.join(', ') }}, …]</p>
      </template>
      <img v-else-if="result.type === 'image'" :src="result.value" alt="" class="max-w-full rounded-md border border-gray-800" data-testid="pg-image" />
      <pre v-else class="text-xs text-gray-200 whitespace-pre-wrap wrap-break-word max-h-80 overflow-y-auto font-mono">{{ result.value }}</pre>
    </div>
  </div>
</template>
