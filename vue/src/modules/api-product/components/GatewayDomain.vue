<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ExternalLink } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { apiProductApi } from '../api/apiProduct'

/**
 * The gateway's own domain (served once its TXT record and a CNAME / address are found) and its
 * public developer page.
 */
const props = defineProps({ gateway: { type: Object, required: true } })
const emit = defineEmits(['updated'])
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const domain = ref('')
const busy = ref(false)
const verifyMessage = ref('')
const pageEnabled = ref(false)
const pageMetrics = ref(false)
const description = ref('')

watch(() => props.gateway, (g) => {
  pageEnabled.value = !!g.developer_page
  pageMetrics.value = !!g.developer_page_metrics
  description.value = g.description ?? ''
}, { immediate: true })

const pending = computed(() => props.gateway.custom_domain_pending)
const pageUrl = computed(() => props.gateway.developer_page_key ? `${window.location.origin}/developers/${props.gateway.developer_page_key}` : '')

async function run(call, success) {
  busy.value = true
  try {
    const { data } = await call()
    emit('updated', data.data)
    if (success) toast.success(success)
    return true
  } catch (e) {
    if (e.response?.status === 422 && e.response?.data?.data) {
      emit('updated', e.response.data.data)
      verifyMessage.value = e.response.data.message
    }
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
    return false
  } finally {
    busy.value = false
  }
}

async function request() {
  verifyMessage.value = ''
  if (await run(() => apiProductApi.setDomain(props.gateway.id, domain.value))) domain.value = ''
}

async function verify() {
  verifyMessage.value = ''
  await run(() => apiProductApi.verifyDomain(props.gateway.id), t('apiProduct.domain.verified'))
}

async function remove() {
  const ok = await confirm({
    title: t('apiProduct.domain.removeTitle'),
    message: t('apiProduct.domain.removeMessage', { domain: props.gateway.custom_domain || pending.value?.domain }),
    destructive: true,
    confirmLabel: t('apiProduct.domain.remove'),
  })
  if (ok) await run(() => apiProductApi.removeDomain(props.gateway.id))
}

async function savePage() {
  await run(() => apiProductApi.updateGateway(props.gateway.id, {
    developer_page: pageEnabled.value,
    developer_page_metrics: pageMetrics.value,
    description: description.value || null,
  }), t('apiProduct.page.saved'))
}
</script>

<template>
  <div class="space-y-6" data-test="api-domain">
    <section class="space-y-3">
      <p class="text-sm text-gray-100">{{ t('apiProduct.domain.title') }}</p>
      <p class="text-[11px] text-gray-500">{{ t('apiProduct.domain.hint', { host: gateway.host }) }}</p>

      <div v-if="gateway.custom_domain" class="flex items-center gap-3 text-sm" data-test="domain-active">
        <span class="font-mono text-gray-100">{{ gateway.custom_domain }}</span>
        <span class="text-[11px] text-emerald-400">{{ t('apiProduct.domain.active') }}</span>
        <button type="button" class="text-xs text-gray-400 hover:text-red-400" :disabled="busy" @click="remove">{{ t('apiProduct.domain.remove') }}</button>
      </div>

      <div v-if="pending" class="bg-gray-900 border border-gray-800 rounded-md p-3 space-y-2" data-test="domain-pending">
        <p class="text-xs text-gray-300">{{ t('apiProduct.domain.pending', { domain: pending.domain }) }}</p>
        <table class="w-full text-xs font-mono">
          <tbody>
            <tr>
              <td class="py-1 pr-3 text-gray-400">{{ pending.verification.type }}</td>
              <td class="py-1 pr-3 text-gray-200 break-all">{{ pending.verification.name }}</td>
              <td class="py-1 text-gray-200 break-all" data-test="domain-txt">{{ pending.verification.value }}</td>
            </tr>
            <tr v-for="(d, i) in pending.dns_targets" :key="i">
              <td class="py-1 pr-3 text-gray-400">{{ d.type }}</td>
              <td class="py-1 pr-3 text-gray-200 break-all">{{ d.name }}</td>
              <td class="py-1 text-gray-200 break-all">{{ d.value }}</td>
            </tr>
          </tbody>
        </table>
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.domain.records') }}</p>
        <p v-if="verifyMessage" class="text-[11px] text-amber-400" data-test="domain-verify-message">{{ verifyMessage }}</p>
        <div class="flex gap-2">
          <button type="button" class="btn-primary text-xs px-3 py-1.5" :disabled="busy" data-test="domain-verify" @click="verify">{{ t('apiProduct.domain.verify') }}</button>
          <button type="button" class="text-xs text-gray-400 hover:text-red-400" :disabled="busy" @click="remove">{{ t('apiProduct.domain.cancel') }}</button>
        </div>
      </div>

      <form v-if="!pending" class="flex flex-wrap items-end gap-2" data-test="domain-form" @submit.prevent="request">
        <div>
          <label class="label">{{ t('apiProduct.domain.field') }}</label>
          <input v-model="domain" class="input font-mono w-72!" placeholder="api.example.com" required data-test="domain-input">
        </div>
        <button type="submit" class="btn-primary text-xs px-3 py-2" :disabled="busy || !domain" data-test="domain-request">{{ t('apiProduct.domain.request') }}</button>
      </form>
    </section>

    <section class="space-y-3 border-t border-gray-800 pt-5">
      <p class="text-sm text-gray-100">{{ t('apiProduct.page.title') }}</p>
      <p class="text-[11px] text-gray-500">{{ t('apiProduct.page.hint') }}</p>
      <label class="flex items-center gap-2 text-sm text-gray-200"><input v-model="pageEnabled" type="checkbox" data-test="page-enabled"> {{ t('apiProduct.page.enabled') }}</label>
      <label class="flex items-center gap-2 text-sm text-gray-200"><input v-model="pageMetrics" type="checkbox" :disabled="!pageEnabled" data-test="page-metrics"> {{ t('apiProduct.page.metrics') }}</label>
      <div>
        <label class="label">{{ t('apiProduct.page.description') }}</label>
        <textarea v-model="description" class="input" rows="3" maxlength="5000" data-test="page-description" />
      </div>
      <div class="flex items-center gap-3">
        <button type="button" class="btn-primary text-xs px-3 py-1.5" :disabled="busy" data-test="page-save" @click="savePage">{{ t('apiProduct.common.save') }}</button>
        <a v-if="gateway.developer_page && pageUrl" :href="pageUrl" target="_blank" rel="noopener" class="text-xs text-sky-400 hover:text-sky-300 inline-flex items-center gap-1" data-test="page-link">
          <ExternalLink class="w-3 h-3" />{{ pageUrl }}
        </a>
      </div>
    </section>
  </div>
</template>
