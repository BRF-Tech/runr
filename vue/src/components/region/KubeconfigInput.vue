<script setup>
import { useI18n } from 'vue-i18n'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

defineProps({
  mode:           { type: String, default: 'file' },
  fileName:       { type: String, default: '' },
  pasteValue:     { type: String, default: '' },
  hasKubeconfig:  { type: Boolean, default: false },
  isEdit:         { type: Boolean, default: false },
})
defineEmits(['update:mode', 'update:pasteValue', 'file-change'])
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-1">
      <label class="label mb-0"><TermText path="settings.regions.kubeconfig" /></label>
      <div v-if="isEdit && hasKubeconfig && mode === 'file' && !fileName" class="text-xs text-emerald-500 font-mono">
        {{ t('settings.regions.kubeconfigSet') }}
      </div>
    </div>
    <div class="flex gap-2 mb-2">
      <button type="button" class="px-2 py-1 rounded text-[10px] transition-colors"
        :class="mode === 'file' ? 'border' : 'text-gray-400 hover:text-gray-100 border border-transparent'"
        :style="mode === 'file' ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
        @click="$emit('update:mode', 'file')">{{ t('settings.regions.kubeconfigModeFile') }}</button>
      <button type="button" class="px-2 py-1 rounded text-[10px] transition-colors"
        :class="mode === 'paste' ? 'border' : 'text-gray-400 hover:text-gray-100 border border-transparent'"
        :style="mode === 'paste' ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
        @click="$emit('update:mode', 'paste')">{{ t('settings.regions.kubeconfigModePaste') }}</button>
    </div>
    <div v-if="mode === 'file'" class="flex items-center gap-3">
      <label class="cursor-pointer">
        <span class="text-xs transition-colors underline underline-offset-2" style="color:rgb(var(--brand-500))">
          {{ isEdit && hasKubeconfig ? t('settings.regions.kubeconfigChange') : t('settings.regions.kubeconfigUpload') }}
        </span>
        <input type="file" accept=".yaml,.yml,.conf,.txt" class="hidden" @change="$emit('file-change', $event)" />
      </label>
      <span v-if="fileName" class="text-xs text-gray-400 font-mono truncate">{{ fileName }}</span>
    </div>
    <textarea v-if="mode === 'paste'" :value="pasteValue"
      class="input font-mono text-xs w-full" rows="8"
      :placeholder="t('settings.regions.kubeconfigPastePlaceholder')" spellcheck="false"
      @input="$emit('update:pasteValue', $event.target.value)" />
  </div>
</template>
