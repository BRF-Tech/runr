<script setup>
// A new price version (POST /billing/prices): every price of a region (or the organization's
// default prices) from an effective date on — today or later; the version before stays in effect
// until then. A missing price is never zero: every field is required (0 = explicitly free).
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import { billingApi } from '../api/billing'
import { PRICE_GROUPS, apiMessage, invalidPriceFields, priceForm, pricePayload, today } from '../utils/billing'

const props = defineProps({
  regionId:   { type: String, default: null },
  regionName: { type: String, default: null },
  // prices the form starts from (the version in effect)
  initial:    { type: Object, default: () => ({}) },
  currency:   { type: String, default: 'USD' },
})
const emit = defineEmits(['saved', 'close'])
const { t } = useI18n()

const form = ref({ ...priceForm(props.initial), region_id: props.regionId })
const saving = ref(false)
const error = ref(null)
const errors = ref({})
const minDate = today()

const invalid = computed(() => invalidPriceFields(form.value))
const canSave = computed(() => !saving.value && invalid.value.length === 0 && form.value.effective_from >= minDate)

async function save() {
  if (!canSave.value) {
    error.value = t('billing.prices.invalid')
    return
  }
  saving.value = true
  error.value = null
  errors.value = {}
  try {
    const { data } = await billingApi.createPrice(pricePayload(form.value))
    emit('saved', data?.data ?? data)
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    error.value = apiMessage(e, t('common.error'))
  } finally {
    saving.value = false
  }
}

defineExpose({ form, save, canSave })
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60" data-test="price-version-form">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <form class="relative card w-full max-w-3xl max-h-[90vh] overflow-y-auto space-y-5" @submit.prevent="save">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-base font-semibold text-gray-100">
            {{ regionId ? t('billing.prices.newRegionVersion', { region: regionName || regionId }) : t('billing.prices.newDefaultVersion') }}
          </h2>
          <p class="text-xs text-gray-400 mt-0.5">{{ t('billing.prices.versionHint', { currency }) }}</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-100" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <div class="max-w-xs">
        <label class="label">{{ t('billing.prices.effectiveFrom') }}</label>
        <input v-model="form.effective_from" type="date" :min="minDate" required class="input" data-test="price-effective-from" />
        <p v-if="errors.effective_from" class="text-xs text-red-400 mt-1">{{ errors.effective_from[0] }}</p>
      </div>

      <div v-for="group in PRICE_GROUPS" :key="group.key" class="space-y-2">
        <h3 class="text-xs font-semibold text-gray-300">{{ t(`billing.prices.groups.${group.key}`) }}</h3>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="field in group.fields" :key="field">
            <label class="label">{{ t(`billing.prices.fields.${field}`) }}</label>
            <input v-model="form[field]" type="number" min="0" step="any" required class="input font-mono"
              :class="invalid.includes(field) && 'border-red-700'" :data-test="`price-${field}`" />
            <p v-if="errors[field]" class="text-xs text-red-400 mt-1">{{ errors[field][0] }}</p>
          </div>
        </div>
      </div>

      <p v-if="error" class="text-sm text-red-400" data-test="price-error">{{ error }}</p>
      <div class="flex justify-end gap-2 pt-2 border-t border-gray-800">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving" data-test="price-save">{{ saving ? t('common.saving') : t('billing.prices.saveVersion') }}</button>
      </div>
    </form>
  </div>
</template>
