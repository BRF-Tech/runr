<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'

// PUT /knative-regions/{id}/settings: request_timeout (Traefik and Knative follow it),
// agent_ws_url (the agent is deployed again), cert_manager_cluster_issuer, ingress_trusted_ips
const { t } = useI18n()

const props = defineProps({
  region: { type: Object, required: true },
})
const emit = defineEmits(['saved'])

const form = ref({ request_timeout: 300, agent_ws_url: '', cert_manager_cluster_issuer: '', ingress_trusted_ips: '' })
const saving = ref(false)
const saved = ref(false)
const error = ref(null)
const fieldErrors = ref({})

function fromRegion(r) {
  const s = r?.knative_settings || {}
  form.value = {
    request_timeout: s.request_timeout ?? 300,
    agent_ws_url: s.agent_ws_url || '',
    cert_manager_cluster_issuer: s.cert_manager_cluster_issuer || '',
    ingress_trusted_ips: (s.ingress_trusted_ips || []).join(', '),
  }
}
watch(() => props.region, fromRegion, { immediate: true })

function payload() {
  const f = form.value
  return {
    request_timeout: Number(f.request_timeout),
    agent_ws_url: f.agent_ws_url.trim() || null,
    cert_manager_cluster_issuer: f.cert_manager_cluster_issuer.trim() || null,
    ingress_trusted_ips: f.ingress_trusted_ips.split(/[\s,]+/).map(s => s.trim()).filter(Boolean),
  }
}

async function save() {
  saving.value = true
  saved.value = false
  error.value = null
  fieldErrors.value = {}
  try {
    const { data } = await knativeRegionsApi.updateSettings(props.region.id, payload())
    saved.value = true
    const region = data.region?.data ?? data.region
    if (region) emit('saved', region)
  } catch (e) {
    fieldErrors.value = e.response?.data?.errors ?? {}
    error.value = e.response?.data?.message ?? t('common.error')
  } finally { saving.value = false }
}

const fieldError = key => (fieldErrors.value[key] ?? Object.entries(fieldErrors.value).find(([k]) => k.startsWith(key + '.'))?.[1])?.[0]
</script>

<template>
  <div class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-3 space-y-3" data-testid="region-settings">
    <div>
      <p class="text-xs text-gray-300 font-medium">{{ t('settings.regionDetail.config.title') }}</p>
      <p class="text-[11px] text-gray-400 mt-0.5">{{ t('settings.regionDetail.config.subtitle') }}</p>
    </div>
    <div>
      <label class="label" for="region-request-timeout">{{ t('settings.regionDetail.config.requestTimeout') }}</label>
      <input id="region-request-timeout" v-model.number="form.request_timeout" type="number" min="1" max="3600" class="input text-xs w-32" />
      <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.config.requestTimeoutHint') }}</p>
      <p v-if="fieldError('request_timeout')" class="text-[11px] text-red-400 mt-1">{{ fieldError('request_timeout') }}</p>
    </div>
    <div>
      <label class="label" for="region-agent-url">{{ t('settings.regionDetail.config.agentWsUrl') }}</label>
      <input id="region-agent-url" v-model="form.agent_ws_url" type="text" maxlength="2048" class="input font-mono text-xs" placeholder="wss://runr.internal.example.com" />
      <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.config.agentWsUrlHint') }}</p>
      <p v-if="fieldError('agent_ws_url')" class="text-[11px] text-red-400 mt-1">{{ fieldError('agent_ws_url') }}</p>
    </div>
    <div>
      <label class="label" for="region-cluster-issuer">{{ t('settings.regionDetail.config.clusterIssuer') }}</label>
      <input id="region-cluster-issuer" v-model="form.cert_manager_cluster_issuer" type="text" maxlength="253" class="input font-mono text-xs" placeholder="letsencrypt-prod" />
      <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.config.clusterIssuerHint') }}</p>
      <p v-if="fieldError('cert_manager_cluster_issuer')" class="text-[11px] text-red-400 mt-1">{{ fieldError('cert_manager_cluster_issuer') }}</p>
    </div>
    <div>
      <label class="label" for="region-trusted-ips">{{ t('settings.regionDetail.config.trustedIps') }}</label>
      <input id="region-trusted-ips" v-model="form.ingress_trusted_ips" type="text" class="input font-mono text-xs" placeholder="10.0.0.0/24, 192.0.2.10" />
      <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.config.trustedIpsHint') }}</p>
      <p v-if="fieldError('ingress_trusted_ips')" class="text-[11px] text-red-400 mt-1">{{ fieldError('ingress_trusted_ips') }}</p>
    </div>
    <div class="flex items-center gap-3">
      <button type="button" class="btn-primary text-xs" :disabled="saving" data-testid="save-region-settings" @click="save">
        {{ saving ? t('common.saving') : t('settings.regionDetail.config.saveSettings') }}
      </button>
      <span v-if="saved" class="text-xs text-emerald-400">{{ t('settings.regionDetail.config.saved') }}</span>
      <span v-else-if="error" class="text-xs text-red-400">{{ error }}</span>
    </div>
  </div>
</template>
