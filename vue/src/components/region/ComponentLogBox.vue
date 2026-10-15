<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  status:    { type: String, default: null },
  message:   { type: String, default: '' },
  logCopied: { type: Boolean, default: false },
})
defineEmits(['copy'])
</script>

<template>
  <!-- Installing -->
  <div v-if="status === 'installing'" class="relative bg-yellow-900/20 border border-yellow-800/40 rounded-md p-3 space-y-2">
    <button type="button" class="absolute top-2 right-2 text-[10px] font-mono px-1.5 py-0.5 rounded-sm transition-colors"
      :class="logCopied ? 'text-emerald-400 bg-emerald-900/30' : 'text-yellow-500/60 hover:text-yellow-400 bg-yellow-900/30'"
      @click="$emit('copy')">{{ logCopied ? t('common.copied') : t('common.copy') }}</button>
    <div class="flex items-center gap-2">
      <div class="w-3 h-3 border-2 border-yellow-400 border-t-transparent rounded-full animate-spin" />
      <span class="text-xs text-yellow-400">{{ t('settings.regionDetail.knative.installStarted') }}</span>
    </div>
    <pre v-if="message" class="text-[11px] text-yellow-200/70 font-mono whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto mt-1">{{ message }}</pre>
  </div>

  <!-- Error -->
  <div v-else-if="status === 'error' && message" class="relative bg-red-900/20 border border-red-800/40 rounded-md p-3">
    <button type="button" class="absolute top-2 right-2 text-[10px] font-mono px-1.5 py-0.5 rounded-sm transition-colors"
      :class="logCopied ? 'text-emerald-400 bg-emerald-900/30' : 'text-red-400/60 hover:text-red-300 bg-red-900/30'"
      @click="$emit('copy')">{{ logCopied ? t('common.copied') : t('common.copy') }}</button>
    <pre class="text-[11px] text-red-300/80 font-mono whitespace-pre-wrap leading-relaxed max-h-40 overflow-y-auto">{{ message }}</pre>
  </div>

  <!-- Uninstalling -->
  <div v-else-if="status === 'uninstalling'" class="bg-orange-900/20 border border-orange-800/40 rounded-md p-3">
    <div class="flex items-center gap-2">
      <div class="w-3 h-3 border-2 border-orange-400 border-t-transparent rounded-full animate-spin" />
      <span class="text-xs text-orange-400">{{ t('settings.regionDetail.knative.statusUninstalling') }}</span>
    </div>
  </div>
</template>
