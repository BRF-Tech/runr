<script setup>
// Common delivery settings of the triggers the event gateway runs (config.delivery): empty
// fields keep the default, shown as the placeholder (the trigger's effective value when it has one).
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ChevronRight } from 'lucide-vue-next'
import { DELIVERY_FIELDS } from '../utils/triggers'

const props = defineProps({
  delivery:  { type: Object, required: true },   // form.delivery: { concurrency: '', … } (mutated)
  effective: { type: Object, default: null },    // the API's trigger.delivery (defaults filled in)
  errors:    { type: Object, default: () => ({}) },
})

const { t } = useI18n()
const open = ref(DELIVERY_FIELDS.some(([k]) => String(props.delivery[k] ?? '') !== ''))

const placeholder = (key, fallback) => String(props.effective?.[key] ?? fallback)
</script>

<template>
  <section class="rounded-lg border border-gray-800 bg-gray-900/40" data-test="trigger-delivery">
    <button type="button" class="w-full flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-gray-300 hover:text-gray-100" @click="open = !open">
      <component :is="open ? ChevronDown : ChevronRight" class="w-3.5 h-3.5" />{{ t('triggers.delivery.title') }}
    </button>
    <div v-if="open" class="px-3 pb-3 space-y-3">
      <p class="text-[11px] text-gray-400">{{ t('triggers.delivery.hint') }}</p>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div v-for="[key, min, max, fallback] in DELIVERY_FIELDS" :key="key">
          <label class="label">{{ t(`triggers.delivery.${key}`) }}</label>
          <input
            v-model="delivery[key]" type="number" :min="min" :max="max" class="input font-mono text-xs"
            :placeholder="placeholder(key, fallback)" :data-field="`delivery.${key}`"
          />
          <p v-if="errors[`config.delivery.${key}`]" class="text-[11px] text-red-400 mt-1">{{ errors[`config.delivery.${key}`] }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
