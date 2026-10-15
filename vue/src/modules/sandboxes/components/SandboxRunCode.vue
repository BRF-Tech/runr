<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Play } from 'lucide-vue-next'
import { sandboxesApi } from '../api/sandboxes'
import { RUN_LANGUAGES, apiError, defaultLanguage, formatBytes } from '../utils'

const props = defineProps({
  sandbox:  { type: Object, required: true },
  disabled: { type: Boolean, default: false },
})

const { t } = useI18n()

const language = ref(defaultLanguage(props.sandbox))
const code     = ref('')
const running  = ref(false)
const result   = ref(null)
const error    = ref(null)

async function run() {
  if (!code.value.trim() || running.value) return
  running.value = true
  error.value   = null
  try {
    const { data } = await sandboxesApi.run(props.sandbox.id, { language: language.value, code: code.value })
    result.value = data?.data ?? data
  } catch (e) {
    result.value = null
    error.value  = apiError(e, t('sandboxes.run.failed'))
  } finally {
    running.value = false
  }
}

function onKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault()
    run()
  }
}
</script>

<template>
  <div data-run-code>
    <p v-if="disabled" class="text-sm text-gray-400 py-6 text-center">{{ t('sandboxes.detail.notRunning') }}</p>
    <template v-else>
      <div class="flex items-end gap-2 mb-2">
        <div class="w-48">
          <label class="label">{{ t('sandboxes.run.language') }}</label>
          <select v-model="language" class="input" name="language">
            <option v-for="l in RUN_LANGUAGES" :key="l" :value="l">{{ l }}</option>
            <option v-if="sandbox.runtime && !RUN_LANGUAGES.includes(sandbox.runtime)" :value="sandbox.runtime">{{ sandbox.runtime }}</option>
          </select>
        </div>
        <button type="button" class="btn-primary flex items-center gap-1.5 ml-auto" :disabled="running || !code.trim()" data-action="run-code" @click="run">
          <Play class="w-3.5 h-3.5" />{{ running ? t('sandboxes.run.running') : t('sandboxes.actions.run') }}
        </button>
      </div>

      <label class="label">{{ t('sandboxes.run.code') }}</label>
      <textarea
        v-model="code"
        name="code"
        rows="8"
        spellcheck="false"
        class="input font-mono text-xs leading-relaxed"
        :placeholder="t('sandboxes.run.placeholder')"
        @keydown="onKeydown"
      ></textarea>

      <p v-if="error" class="text-sm text-red-400 mt-3">{{ error }}</p>

      <div v-if="result" class="mt-4 space-y-3" data-run-result>
        <div class="flex items-center gap-2 text-xs font-mono">
          <span class="px-2 py-0.5 rounded-md border"
            :class="result.exit_code === 0 ? 'border-emerald-800 bg-emerald-900/30 text-emerald-300' : 'border-red-800 bg-red-900/30 text-red-300'"
            data-exit-code
          >{{ t('sandboxes.run.exitCode', { code: result.exit_code }) }}</span>
          <span class="text-gray-400">{{ t('sandboxes.run.duration', { ms: result.duration_ms ?? 0 }) }}</span>
          <span v-if="result.timed_out" class="text-amber-400">{{ t('sandboxes.run.timedOut') }}</span>
        </div>

        <div v-if="result.stdout">
          <p class="text-[10px] uppercase tracking-wider text-gray-500 mb-1">{{ t('sandboxes.run.stdout') }}</p>
          <pre class="rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-xs font-mono text-gray-200 overflow-auto max-h-64 whitespace-pre-wrap" data-stdout>{{ result.stdout }}</pre>
        </div>
        <div v-if="result.stderr">
          <p class="text-[10px] uppercase tracking-wider text-gray-500 mb-1">{{ t('sandboxes.run.stderr') }}</p>
          <pre class="rounded-lg border border-red-900/40 bg-gray-950 px-3 py-2 text-xs font-mono text-red-300 overflow-auto max-h-64 whitespace-pre-wrap" data-stderr>{{ result.stderr }}</pre>
        </div>
        <p v-if="!result.stdout && !result.stderr" class="text-xs text-gray-500">{{ t('sandboxes.run.noOutput') }}</p>

        <div v-if="result.files_changed?.length">
          <p class="text-[10px] uppercase tracking-wider text-gray-500 mb-1">{{ t('sandboxes.run.filesChanged') }}</p>
          <ul class="text-xs font-mono text-gray-300 space-y-0.5">
            <li v-for="f in result.files_changed" :key="f.path">{{ f.path }} <span class="text-gray-500">({{ formatBytes(f.size) }})</span></li>
          </ul>
        </div>

        <div v-if="result.outputs?.length">
          <p class="text-[10px] uppercase tracking-wider text-gray-500 mb-1">{{ t('sandboxes.run.outputs') }}</p>
          <div class="flex flex-wrap gap-3">
            <figure v-for="o in result.outputs" :key="o.path" class="rounded-lg border border-gray-800 bg-white/5 p-2">
              <img :src="`data:${o.mime};base64,${o.data_base64}`" :alt="o.path" class="max-h-72 max-w-full" data-output-image />
              <figcaption class="text-[10px] font-mono text-gray-500 mt-1">{{ o.path }}</figcaption>
            </figure>
          </div>
        </div>
        <p v-else class="text-[11px] text-gray-500">{{ t('sandboxes.run.outputsHint') }}</p>
      </div>
    </template>
  </div>
</template>
