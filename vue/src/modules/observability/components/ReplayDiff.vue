<script setup>
import { useI18n } from 'vue-i18n'

/** Difference between the captured response and the replay's (ResponseDiff of the API). */
defineProps({
  diff:   { type: Object, required: true },
  replay: { type: Object, required: true },
})

const { t } = useI18n()
</script>

<template>
  <div class="space-y-3" data-test="replay-diff">
    <div class="flex flex-wrap items-center gap-3 text-xs">
      <span class="text-gray-400">{{ t('observability.replay.diff.status') }}:</span>
      <span class="font-mono px-1.5 rounded bg-red-900/40 text-red-300">{{ diff.status.before }}</span>
      <span class="text-gray-500">→</span>
      <span class="font-mono px-1.5 rounded" :class="diff.status.after >= 500 || diff.status.after === 0 ? 'bg-red-900/40 text-red-300' : 'bg-emerald-900/30 text-emerald-300'" data-test="diff-after">{{ diff.status.after || '—' }}</span>
      <span class="text-gray-500">{{ t('observability.replay.diff.replayedAt') }} {{ new Date(replay.replayed_at).toLocaleString() }}</span>
      <span v-if="replay.version" class="text-gray-500">{{ t('observability.replay.diff.version', { n: replay.version }) }}</span>
      <span v-if="!diff.changed" class="text-gray-400" data-test="diff-unchanged">{{ t('observability.replay.diff.unchanged') }}</span>
    </div>

    <p v-if="replay.error" class="text-xs text-red-400" data-test="diff-error">{{ t('observability.replay.diff.error') }}: {{ replay.error }}</p>

    <div v-if="Object.keys(diff.headers.added).length || Object.keys(diff.headers.removed).length || Object.keys(diff.headers.changed).length">
      <p class="label">{{ t('observability.replay.diff.headers') }}</p>
      <div class="font-mono text-[11px] space-y-0.5">
        <div v-for="(v, k) in diff.headers.removed" :key="'r' + k" class="text-red-300">- {{ k }}: {{ v }}</div>
        <div v-for="(v, k) in diff.headers.added" :key="'a' + k" class="text-emerald-300">+ {{ k }}: {{ v }}</div>
        <div v-for="(pair, k) in diff.headers.changed" :key="'c' + k" class="text-amber-300">~ {{ k }}: {{ pair[0] }} → {{ pair[1] }}</div>
      </div>
    </div>

    <div v-if="diff.body.changed">
      <p class="label">{{ t('observability.replay.diff.body') }}</p>
      <pre class="bg-gray-950 border border-gray-800 rounded-md p-3 max-h-96 overflow-auto text-[11px] leading-relaxed"><div
          v-for="(l, i) in diff.body.lines" :key="i"
          :class="l.op === '+' ? 'text-emerald-300 bg-emerald-900/10' : l.op === '-' ? 'text-red-300 bg-red-900/10' : 'text-gray-400'"
          data-test="diff-line"
        >{{ l.op }} {{ l.text }}</div></pre>
      <p v-if="diff.body.truncated" class="text-[11px] text-gray-500 mt-1">{{ t('observability.replay.diff.truncated') }}</p>
    </div>
  </div>
</template>
