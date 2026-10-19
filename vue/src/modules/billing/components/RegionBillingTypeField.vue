<script setup>
// Region wizard, first step (moduleRegistry.regionCreateExtensions): how the new region is billed —
// pay as you go (usage at month end) or fixed resources (projects request resources, the seller
// approves, a monthly price prorated by day). One type per region, chosen when it is opened; saved
// with PUT /billing/regions/{id} once the region exists (the plugin's afterCreate).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { BILLING_TYPES, billingEnabled, can } from '../utils/billing'

const props = defineProps({
  modelValue: { type: Object, default: () => ({ billing_type: 'payg' }) },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const show = computed(() => billingEnabled() && can('billing.manage'))
const selected = computed(() => props.modelValue?.billing_type ?? 'payg')

function choose(type) {
  emit('update:modelValue', { ...(props.modelValue ?? {}), billing_type: type })
}
</script>

<template>
  <div v-if="show" data-test="region-billing-type">
    <label class="label">{{ t('billing.wizard.title') }}</label>
    <div class="grid grid-cols-2 gap-2">
      <button v-for="type in BILLING_TYPES" :key="type" type="button" class="rounded-md border px-3 py-2 text-xs text-left transition-colors"
        :class="selected === type ? 'border-sky-500/60 bg-sky-900/20 text-sky-400' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
        :data-test="`region-billing-type-${type}`" @click="choose(type)">
        <span class="font-medium">{{ t(`billing.type.${type}`) }}</span>
        <p class="text-[10px] mt-0.5 opacity-70">{{ t(`billing.typeHint.${type}`) }}</p>
      </button>
    </div>
    <p class="text-[10px] text-gray-500 mt-1">{{ t('billing.wizard.hint') }}</p>
  </div>
</template>
