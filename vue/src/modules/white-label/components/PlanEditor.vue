<script setup>
// Create / edit a plan (POST / PATCH /api/admin/plans). An empty limit means unlimited (null).
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { plansAdminApi } from '../api/whiteLabel'
import { CURRENCIES, FEATURE_FLAGS, LIMIT_KEYS, buildPlanPayload, planToForm } from '../utils/plans'
import { X } from 'lucide-vue-next'

const props = defineProps({
  plan: { type: Object, default: null },
})
const emit = defineEmits(['saved', 'close'])
const { t } = useI18n()

const form = ref(planToForm(props.plan))
const saving = ref(false)
const error = ref(null)
const errors = ref({})

const isNew = computed(() => !props.plan?.id)

function slugify() {
  if (!isNew.value || form.value.slug) return
  form.value.slug = String(form.value.name || '')
    .toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64)
}

async function save() {
  saving.value = true
  error.value = null
  errors.value = {}
  try {
    const body = buildPlanPayload(form.value)
    const { data } = isNew.value ? await plansAdminApi.create(body) : await plansAdminApi.update(props.plan.id, body)
    emit('saved', data)
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

function fieldError(key) {
  return errors.value?.[key]?.[0] ?? null
}

defineExpose({ form, save })
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60" data-test="plan-editor">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')" />
    <form class="relative card w-full max-w-3xl max-h-[90vh] overflow-y-auto space-y-5" @submit.prevent="save">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-100">{{ isNew ? t('whiteLabel.plans.newPlan') : t('whiteLabel.plans.editPlan') }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-100" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="label">{{ t('whiteLabel.plans.name') }}</label>
          <input v-model="form.name" class="input" required maxlength="100" data-test="plan-name" @blur="slugify" />
          <p v-if="fieldError('name')" class="text-xs text-red-400 mt-1">{{ fieldError('name') }}</p>
        </div>
        <div>
          <label class="label">{{ t('whiteLabel.plans.slug') }}</label>
          <input v-model="form.slug" class="input font-mono" required maxlength="64" pattern="[a-z0-9][a-z0-9-]*" data-test="plan-slug" />
          <p v-if="fieldError('slug')" class="text-xs text-red-400 mt-1">{{ fieldError('slug') }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="label">{{ t('whiteLabel.plans.description') }}</label>
          <textarea v-model="form.description" rows="2" class="input" maxlength="1000" />
        </div>
      </div>

      <!-- Price -->
      <div class="grid gap-4 sm:grid-cols-3">
        <div>
          <label class="label">{{ t('whiteLabel.plans.currency') }}</label>
          <input v-model="form.currency" class="input font-mono uppercase" list="wl-currencies" maxlength="3" required data-test="plan-currency" />
          <datalist id="wl-currencies"><option v-for="c in CURRENCIES" :key="c" :value="c" /></datalist>
        </div>
        <div>
          <label class="label">{{ t('whiteLabel.plans.priceMonthly') }}</label>
          <input v-model="form.price_monthly" type="number" min="0" step="0.01" class="input" required data-test="plan-price-monthly" />
          <p v-if="fieldError('price_monthly')" class="text-xs text-red-400 mt-1">{{ fieldError('price_monthly') }}</p>
        </div>
        <div>
          <label class="label">{{ t('whiteLabel.plans.priceYearly') }}</label>
          <input v-model="form.price_yearly" type="number" min="0" step="0.01" class="input" :placeholder="t('whiteLabel.plans.noYearly')" data-test="plan-price-yearly" />
        </div>
      </div>

      <!-- Limits -->
      <div>
        <p class="label">{{ t('whiteLabel.plans.limits') }}</p>
        <p class="text-xs text-gray-400 mb-2">{{ t('whiteLabel.plans.limitsHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-3">
          <div v-for="key in LIMIT_KEYS" :key="key">
            <label class="label">{{ t(`whiteLabel.limits.${key}`) }}</label>
            <input v-model="form.limits[key]" type="number" min="0" class="input" :placeholder="t('whiteLabel.plans.unlimited')" :data-test="`plan-limit-${key}`" />
            <p v-if="fieldError(`limits.${key}`)" class="text-xs text-red-400 mt-1">{{ fieldError(`limits.${key}`) }}</p>
          </div>
        </div>
      </div>

      <!-- Features -->
      <div>
        <p class="label">{{ t('whiteLabel.plans.features') }}</p>
        <div class="grid gap-2 sm:grid-cols-3">
          <label v-for="flag in FEATURE_FLAGS" :key="flag" class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
            <input v-model="form.features[flag]" type="checkbox" :data-test="`plan-feature-${flag}`" />
            {{ t(`whiteLabel.features.${flag}`) }}
          </label>
        </div>
      </div>

      <!-- Visibility -->
      <div class="grid gap-4 sm:grid-cols-4 items-end">
        <div>
          <label class="label">{{ t('whiteLabel.plans.trialDays') }}</label>
          <input v-model="form.trial_days" type="number" min="0" max="365" class="input" />
        </div>
        <div>
          <label class="label">{{ t('whiteLabel.plans.sortOrder') }}</label>
          <input v-model="form.sort_order" type="number" min="0" class="input" />
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer h-9">
          <input v-model="form.is_public" type="checkbox" data-test="plan-public" />{{ t('whiteLabel.plans.public') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer h-9">
          <input v-model="form.is_default" type="checkbox" data-test="plan-default" />{{ t('whiteLabel.plans.default') }}
        </label>
      </div>
      <p class="text-xs text-gray-400">{{ t('whiteLabel.plans.visibilityHint') }}</p>

      <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving" data-test="plan-save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
