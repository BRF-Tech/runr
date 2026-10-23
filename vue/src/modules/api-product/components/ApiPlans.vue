<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { apiProductApi } from '../api/apiProduct'
import { planLimits } from '../utils/format'

/**
 * Plans offered on the gateway: its own and the organization's. Rate limits (per second with
 * burst, per minute) answer 429 rate_limited, quotas (per day, per month, UTC) 429 quota_exceeded.
 */
const props = defineProps({ gateway: { type: Object, required: true } })
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const FIELDS = ['rate_per_second', 'burst', 'rate_per_minute', 'quota_per_day', 'quota_per_month']
const plans = ref([])
const loading = ref(true)
const saving = ref(false)
const errors = ref({})
const form = reactive({ name: '', description: '', rate_per_second: '', burst: '', rate_per_minute: '', quota_per_day: '', quota_per_month: '' })

async function load() {
  loading.value = true
  try {
    const { data } = await apiProductApi.plans({ gateway_id: props.gateway.id })
    plans.value = data.data ?? []
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    loading.value = false
  }
}

async function create() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { gateway_id: props.gateway.id, name: form.name }
    if (form.description) payload.description = form.description
    for (const f of FIELDS) {
      if (form[f] !== '' && form[f] != null) payload[f] = Number(form[f])
    }
    const { data } = await apiProductApi.createPlan(payload)
    plans.value.push(data.data)
    Object.assign(form, { name: '', description: '', rate_per_second: '', burst: '', rate_per_minute: '', quota_per_day: '', quota_per_month: '' })
    toast.success(t('apiProduct.plans.created', { name: data.data.name }))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    saving.value = false
  }
}

async function remove(plan) {
  const ok = await confirm({
    title: t('apiProduct.plans.deleteTitle'),
    message: t('apiProduct.plans.deleteMessage', { name: plan.name }),
    destructive: true,
    confirmLabel: t('apiProduct.common.delete'),
  })
  if (!ok) return
  try {
    await apiProductApi.deletePlan(plan.id)
    plans.value = plans.value.filter((p) => p.id !== plan.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  }
}

const err = (k) => (errors.value?.[k] || [])[0]

watch(() => props.gateway.id, load)
onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="api-plans">
    <div v-if="loading" class="flex justify-center py-6"><div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <p v-else-if="!plans.length" class="text-xs text-gray-500">{{ t('apiProduct.plans.none') }}</p>
    <div v-for="p in plans" :key="p.id" class="flex flex-wrap items-center gap-3 bg-gray-900 border border-gray-800 rounded-md px-3 py-2" :data-plan="p.name">
      <span class="text-sm text-gray-100">{{ p.name }}</span>
      <span v-if="p.scope === 'organization'" class="text-[10px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">{{ t('apiProduct.consumers.organization') }}</span>
      <span class="text-[11px] text-gray-400" data-test="plan-limits">{{ planLimits(p, t) }}</span>
      <span v-if="p.description" class="text-[11px] text-gray-500">{{ p.description }}</span>
      <button v-if="p.scope === 'gateway'" type="button" class="ml-auto text-gray-500 hover:text-red-400" :title="t('apiProduct.common.delete')" data-test="plan-delete" @click="remove(p)">
        <Trash2 class="w-4 h-4" />
      </button>
    </div>

    <form class="space-y-3 bg-gray-900 border border-gray-800 rounded-md p-3" data-test="plan-form" @submit.prevent="create">
      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="label">{{ t('apiProduct.plans.name') }}</label>
          <input v-model="form.name" class="input" required maxlength="100" placeholder="Pro" data-test="plan-name">
          <p v-if="err('name')" class="text-[11px] text-red-400 mt-1">{{ err('name') }}</p>
        </div>
        <div>
          <label class="label">{{ t('apiProduct.plans.description') }}</label>
          <input v-model="form.description" class="input" maxlength="500">
        </div>
      </div>
      <div class="grid gap-3 md:grid-cols-5">
        <div v-for="f in FIELDS" :key="f">
          <label class="label">{{ t(`apiProduct.plans.fields.${f}`) }}</label>
          <input v-model="form[f]" type="number" min="1" class="input" :data-test="`plan-${f}`">
          <p v-if="err(f)" class="text-[11px] text-red-400 mt-1">{{ err(f) }}</p>
        </div>
      </div>
      <p class="text-[11px] text-gray-500">{{ t('apiProduct.plans.hint') }}</p>
      <button type="submit" class="btn-primary text-xs px-3 py-1.5 inline-flex items-center gap-1" :disabled="saving || !form.name" data-test="plan-create">
        <Plus class="w-3.5 h-3.5" />{{ t('apiProduct.plans.create') }}
      </button>
    </form>
  </div>
</template>
