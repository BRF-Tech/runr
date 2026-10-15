<script setup>
// Status of a tenant's plan: trialing → active → canceled (the plan ended without a default plan).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({ status: { type: String, default: null } })
const { t } = useI18n()

const CLASSES = {
  trialing: 'bg-sky-900/40 border-sky-800/40 text-sky-400',
  active:   'bg-emerald-900/40 border-emerald-800/40 text-emerald-400',
  canceled: 'bg-gray-800/40 border-gray-700/40 text-gray-400',
}

const cls = computed(() => CLASSES[props.status] ?? CLASSES.canceled)
const label = computed(() => props.status && CLASSES[props.status] ? t(`whiteLabel.status.${props.status}`) : t('whiteLabel.status.none'))
</script>

<template>
  <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border whitespace-nowrap" :class="cls" data-test="subscription-status">{{ label }}</span>
</template>
