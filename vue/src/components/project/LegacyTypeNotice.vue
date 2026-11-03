<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
</script>

<template>
  <div class="px-3 py-1.5 rounded-md border border-yellow-800/50 bg-yellow-950/20 text-yellow-400 text-[11px] font-medium">
    {{ t('ai.legacyNotice') }}
  </div>
</template>
