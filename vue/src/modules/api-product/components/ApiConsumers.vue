<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ChevronRight, Plus, Trash2 } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { apiProductApi } from '../api/apiProduct'
import ApiKeys from './ApiKeys.vue'

/**
 * Consumers accepted on the gateway: its own and the organization's (those are managed by
 * api_products.manage). A consumer gets a plan and keys; suspended, every key answers 403.
 */
const props = defineProps({ gateway: { type: Object, required: true } })
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const consumers = ref([])
const plans = ref([])
const loading = ref(true)
const open = ref(null)
const creating = ref(false)
const errors = ref({})
const form = reactive({ name: '', email: '', external_id: '', jwt_subject: '', plan_id: '' })

async function load() {
  loading.value = true
  try {
    const [c, p] = await Promise.all([
      apiProductApi.consumers({ gateway_id: props.gateway.id }),
      apiProductApi.plans({ gateway_id: props.gateway.id }),
    ])
    consumers.value = c.data.data ?? []
    plans.value = p.data.data ?? []
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    loading.value = false
  }
}

async function create() {
  creating.value = true
  errors.value = {}
  try {
    const payload = { gateway_id: props.gateway.id, name: form.name }
    for (const k of ['email', 'external_id', 'jwt_subject', 'plan_id']) {
      if (form[k]) payload[k] = form[k]
    }
    const { data } = await apiProductApi.createConsumer(payload)
    consumers.value.unshift(data.data)
    Object.assign(form, { name: '', email: '', external_id: '', jwt_subject: '', plan_id: '' })
    open.value = data.data.id
    toast.success(t('apiProduct.consumers.created', { name: data.data.name }))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    creating.value = false
  }
}

async function update(consumer, patch) {
  try {
    const { data } = await apiProductApi.updateConsumer(consumer.id, patch)
    Object.assign(consumer, data.data)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  }
}

async function remove(consumer) {
  const ok = await confirm({
    title: t('apiProduct.consumers.deleteTitle'),
    message: t('apiProduct.consumers.deleteMessage', { name: consumer.name }),
    destructive: true,
    confirmLabel: t('apiProduct.common.delete'),
  })
  if (!ok) return
  try {
    await apiProductApi.deleteConsumer(consumer.id)
    consumers.value = consumers.value.filter((c) => c.id !== consumer.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  }
}

const err = (k) => (errors.value?.[k] || [])[0]

watch(() => props.gateway.id, load)
onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="api-consumers">
    <form class="grid gap-3 md:grid-cols-5 items-end bg-gray-900 border border-gray-800 rounded-md p-3" data-test="consumer-form" @submit.prevent="create">
      <div>
        <label class="label">{{ t('apiProduct.consumers.name') }}</label>
        <input v-model="form.name" class="input" required maxlength="120" placeholder="Acme Ltd" data-test="consumer-name">
        <p v-if="err('name')" class="text-[11px] text-red-400 mt-1">{{ err('name') }}</p>
      </div>
      <div>
        <label class="label">{{ t('apiProduct.consumers.email') }}</label>
        <input v-model="form.email" type="email" class="input" placeholder="api@acme.example">
      </div>
      <div>
        <label class="label">{{ t('apiProduct.consumers.externalId') }}</label>
        <input v-model="form.external_id" class="input font-mono" placeholder="CUST-1042" data-test="consumer-external-id">
      </div>
      <div>
        <label class="label">{{ t('apiProduct.consumers.plan') }}</label>
        <select v-model="form.plan_id" class="input" data-test="consumer-plan">
          <option value="">{{ t('apiProduct.consumers.noPlan') }}</option>
          <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <p v-if="err('plan_id')" class="text-[11px] text-red-400 mt-1">{{ err('plan_id') }}</p>
      </div>
      <button type="submit" class="btn-primary text-xs px-3 py-2 inline-flex items-center gap-1 justify-center" :disabled="creating || !form.name" data-test="consumer-create">
        <Plus class="w-3.5 h-3.5" />{{ t('apiProduct.consumers.create') }}
      </button>
      <div class="md:col-span-5">
        <label class="label">{{ t('apiProduct.consumers.jwtSubject') }}</label>
        <input v-model="form.jwt_subject" class="input font-mono md:w-1/2!" :placeholder="t('apiProduct.consumers.jwtSubjectHint')">
        <p v-if="err('jwt_subject')" class="text-[11px] text-red-400 mt-1">{{ err('jwt_subject') }}</p>
      </div>
    </form>

    <div v-if="loading" class="flex justify-center py-6"><div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <p v-else-if="!consumers.length" class="text-xs text-gray-500" data-test="consumers-empty">{{ t('apiProduct.consumers.none') }}</p>

    <div v-for="c in consumers" :key="c.id" class="bg-gray-900 border border-gray-800 rounded-md" :data-consumer="c.name">
      <div class="flex flex-wrap items-center gap-3 px-3 py-2">
        <button type="button" class="text-gray-400 hover:text-gray-200" :aria-label="t('apiProduct.consumers.keys')" @click="open = open === c.id ? null : c.id">
          <ChevronDown v-if="open === c.id" class="w-4 h-4" /><ChevronRight v-else class="w-4 h-4" />
        </button>
        <span class="text-sm text-gray-100">{{ c.name }}</span>
        <span v-if="c.external_id" class="text-[11px] font-mono text-gray-500">{{ c.external_id }}</span>
        <span v-if="c.scope === 'organization'" class="text-[10px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">{{ t('apiProduct.consumers.organization') }}</span>
        <span class="text-[11px] text-gray-500">{{ t('apiProduct.consumers.keyCount', { active: c.keys?.active ?? 0, total: c.keys?.total ?? 0 }) }}</span>
        <span class="text-[11px]" :class="c.status === 'suspended' ? 'text-amber-400' : 'text-emerald-400'" data-test="consumer-status">{{ t(`apiProduct.consumers.status.${c.status}`) }}</span>

        <div v-if="c.can_manage" class="ml-auto flex items-center gap-2">
          <select :value="c.plan_id ?? ''" class="input h-8! w-40! text-xs" data-test="consumer-plan-change" @change="update(c, { plan_id: $event.target.value || null })">
            <option value="">{{ t('apiProduct.consumers.noPlan') }}</option>
            <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <button type="button" class="text-xs text-gray-300 hover:text-white" data-test="consumer-suspend" @click="update(c, { status: c.status === 'suspended' ? 'active' : 'suspended' })">
            {{ c.status === 'suspended' ? t('apiProduct.consumers.activate') : t('apiProduct.consumers.suspend') }}
          </button>
          <button type="button" class="text-gray-500 hover:text-red-400" :title="t('apiProduct.common.delete')" data-test="consumer-delete" @click="remove(c)">
            <Trash2 class="w-4 h-4" />
          </button>
        </div>
      </div>
      <ApiKeys v-if="open === c.id" :consumer="c" :gateway="gateway" :plans="plans" class="border-t border-gray-800" />
    </div>
  </div>
</template>
