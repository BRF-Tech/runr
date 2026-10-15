<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
</script>

<template>
  <div class="px-3 py-1.5 rounded-md border border-amber-800/50 bg-amber-950/20 text-amber-400 text-[11px] font-medium">
    {{ t('ai.legacyNotice') }}
  </div>
</template>
