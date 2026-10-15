<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  title:        { type: String, required: true },
  message:      { type: String, required: true },
  loading:      { type: Boolean, default: false },
  destructive:  { type: Boolean, default: false },
  confirmLabel: { type: String, default: null },
})

defineEmits(['confirm', 'cancel'])
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 70">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('cancel')" />
    <div class="relative card w-full max-w-sm">
      <h2 class="text-base font-semibold text-gray-100 mb-2">{{ title }}</h2>
      <p class="text-sm text-gray-400 mb-6 whitespace-pre-line">{{ message }}</p>
      <div class="flex justify-end gap-2">
        <button class="btn-secondary" :disabled="loading" @click="$emit('cancel')">
          {{ t('common.cancel') }}
        </button>
        <button
          :class="destructive
            ? 'btn-secondary text-red-400 hover:text-red-300 border-red-900/50 hover:border-red-700'
            : 'btn-primary'"
          :disabled="loading"
          @click="$emit('confirm')"
        >
          {{ loading ? '…' : (confirmLabel || title) }}
        </button>
      </div>
    </div>
  </div>
</template>
