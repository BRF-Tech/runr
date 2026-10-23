<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { saveBlob } from '@/modules/supply-chain/api'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { useJobEvents } from '@/composables/useJobEvents'

// cert-manager of a region and the ClusterIssuer of its project custom domains
// (GET / POST / DELETE /knative-regions/{id}/cert-manager, docs/regions.md#cert-manager).
// The issuer mode and the ACME e-mail are region settings (PUT …/settings).
const props = defineProps({
  regionId: { type: String, required: true },
})
const emit = defineEmits(['saved'])

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const state   = ref(null)
const loading = ref(false)
const busy    = ref(false)
const saving  = ref(false)
const error   = ref(null)
const form    = ref({ issuer_mode: 'auto', acme_email: '' })

const status     = computed(() => state.value?.status ?? 'not_installed')
const busyStatus = computed(() => ['installing', 'uninstalling'].includes(status.value))
const canManage  = computed(() => !!state.value?.can_manage)
const issuer     = computed(() => state.value?.issuer ?? {})
const cluster    = computed(() => state.value?.cluster ?? null)
const domains    = computed(() => state.value?.domains ?? null)
const caDownload = computed(() => issuer.value.kind === 'ca' && (cluster.value ? cluster.value.ca_available : !!domains.value?.ca))

const statusTone = {
  not_installed: ['bg-gray-500', 'text-gray-400'],
  installing:    ['bg-yellow-400 animate-pulse', 'text-yellow-400'],
  uninstalling:  ['bg-yellow-400 animate-pulse', 'text-yellow-400'],
  ready:         ['bg-emerald-400', 'text-emerald-400'],
  error:         ['bg-red-400', 'text-red-400'],
}

async function load({ withCluster = true } = {}) {
  loading.value = true
  try {
    const { data } = await knativeRegionsApi.certManager(props.regionId, withCluster ? {} : { cluster: 0 })
    state.value = data
    if (!saving.value) {
      form.value = { issuer_mode: data.issuer_mode ?? 'auto', acme_email: data.acme?.email_source === 'region' ? (data.acme.email ?? '') : '' }
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? e.message
  } finally {
    loading.value = false
  }
}

// While cert-manager installs or goes, the status read also finishes an install that waits for
// cert-manager to start (the server checks it again in the background)
let timer = null
function schedulePoll() {
  clearTimeout(timer)
  if (busyStatus.value) timer = setTimeout(async () => { await load({ withCluster: false }); schedulePoll() }, 5000)
}
watch(status, (now, before) => {
  schedulePoll()
  if (before && ['installing', 'uninstalling'].includes(before) && !busyStatus.value) load()
})
onUnmounted(() => clearTimeout(timer))
watch(() => props.regionId, (id) => { if (id) load() }, { immediate: true })

useJobEvents({
  'cert_manager.*': (payload) => { if (payload?.region_id === props.regionId) load() },
  'install.progress': (payload) => {
    if (payload?.region_id !== props.regionId || payload.component !== 'cert-manager' || !state.value) return
    state.value = { ...state.value, message: payload.message ?? '' }
  },
})

function applyRegion(data) {
  const region = data?.region?.data ?? data?.region
  if (region) emit('saved', region)
}

async function install() {
  busy.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.installCertManager(props.regionId)
    applyRegion(data)
    toast.success(data.message ?? t('settings.regionDetail.certManager.installQueued'))
    await load({ withCluster: false })
  } catch (e) {
    error.value = e.response?.data?.message ?? e.message
  } finally {
    busy.value = false
  }
}

async function remove() {
  const ok = await confirm({
    title:       t('settings.regionDetail.certManager.remove'),
    message:     t(state.value?.managed ? 'settings.regionDetail.certManager.removeConfirmRunr' : 'settings.regionDetail.certManager.removeConfirmOwn'),
    destructive: true,
  })
  if (!ok) return
  busy.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.removeCertManager(props.regionId)
    applyRegion(data)
    await load({ withCluster: false })
  } catch (e) {
    error.value = e.response?.data?.message ?? e.message
  } finally {
    busy.value = false
  }
}

async function saveIssuerSettings() {
  saving.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.updateSettings(props.regionId, {
      cert_manager_issuer_mode: form.value.issuer_mode,
      cert_manager_acme_email: form.value.acme_email.trim() || null,
    })
    applyRegion(data)
    toast.success(t('settings.regionDetail.certManager.issuerSaved'))
  } catch (e) {
    const errors = e.response?.data?.errors
    error.value = errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message ?? e.message)
  } finally {
    saving.value = false
  }
  await load()
}

async function downloadCa() {
  error.value = null
  try {
    const response = await knativeRegionsApi.certManagerCa(props.regionId)
    saveBlob(response, 'runr-region-ca.crt')
  } catch (e) {
    error.value = e.response?.status === 404 ? t('settings.regionDetail.certManager.caMissing') : (e.response?.data?.message ?? e.message)
  }
}
</script>

<template>
  <div class="mt-6" data-testid="cert-manager">
    <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3">
      {{ t('settings.regionDetail.certManager.section') }}
    </p>
    <div class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-3 space-y-3">
      <div class="flex items-start justify-between gap-3">
        <p class="text-[11px] text-gray-400">{{ t('settings.regionDetail.certManager.subtitle') }}</p>
        <span class="flex items-center gap-1.5 shrink-0">
          <span class="w-2 h-2 rounded-full" :class="(statusTone[status] ?? statusTone.not_installed)[0]" />
          <span class="text-xs font-medium" :class="(statusTone[status] ?? statusTone.not_installed)[1]" data-testid="cert-manager-status">
            {{ t(`settings.regionDetail.certManager.status_${status}`) }}
          </span>
        </span>
      </div>

      <p v-if="loading && !state" class="text-[11px] text-gray-500">{{ t('settings.regionDetail.certManager.loading') }}</p>

      <template v-if="state">
        <!-- what runs and the issuer -->
        <dl v-if="status !== 'not_installed'" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
          <dt class="text-gray-500">{{ t('settings.regionDetail.certManager.installedBy') }}</dt>
          <dd class="text-gray-300" data-testid="cert-manager-owner">
            {{ state.managed === false ? t('settings.regionDetail.certManager.ownCertManager') : t('settings.regionDetail.certManager.runrCertManager') }}
            <span v-if="state.version" class="font-mono text-gray-400">· {{ state.version }}</span>
            <span v-if="state.namespace" class="font-mono text-gray-500">· {{ state.namespace }}</span>
          </dd>
          <template v-if="issuer.name">
            <dt class="text-gray-500">{{ t('settings.regionDetail.certManager.issuer') }}</dt>
            <dd class="text-gray-300" data-testid="cert-manager-issuer">
              <span class="font-mono">{{ issuer.name }}</span>
              <span class="text-gray-400"> · {{ t(`settings.regionDetail.certManager.kind_${issuer.kind ?? 'own'}`) }}</span>
              <span v-if="cluster && cluster.issuer_ready === false" class="text-amber-400"> · {{ t('settings.regionDetail.certManager.issuerNotReady') }}</span>
            </dd>
          </template>
        </dl>
        <p v-if="issuer.source === 'runr' && state.plan?.reason" class="text-[11px] text-gray-500" data-testid="cert-manager-why">
          {{ t('settings.regionDetail.certManager.why', { reason: state.plan.reason }) }}
        </p>
        <p v-if="cluster?.issuer_message" class="text-[11px] text-amber-300/90">{{ cluster.issuer_message }}</p>

        <!-- the region CA: clients trust its certificates once it is installed -->
        <div v-if="issuer.kind === 'ca'" class="flex items-start justify-between gap-3 rounded-sm bg-sky-900/15 border border-sky-800/40 px-3 py-2">
          <p class="text-[11px] text-sky-200/90 leading-relaxed">{{ t('settings.regionDetail.certManager.caHint') }}</p>
          <button v-if="caDownload" type="button" class="btn-secondary text-xs shrink-0" data-testid="cert-manager-ca" @click="downloadCa">
            {{ t('settings.regionDetail.certManager.downloadCa') }}
          </button>
        </div>

        <!-- custom domains cannot use it: why and what to do -->
        <p v-if="domains && !domains.available && status !== 'not_installed'" class="text-[11px] text-amber-300/90 bg-amber-900/20 rounded-sm px-3 py-2" data-testid="cert-manager-domains">
          {{ domains.reason }}
        </p>
        <p v-if="status === 'not_installed'" class="text-[11px] text-gray-400" data-testid="cert-manager-not-installed">
          {{ state.install_enabled ? t('settings.regionDetail.certManager.notInstalled') : t('settings.regionDetail.certManager.installDisabled') }}
        </p>

        <!-- step log -->
        <p v-if="status === 'error' && state.message" class="text-xs text-red-400/80 bg-red-900/20 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap wrap-break-word max-h-40 overflow-y-auto" data-testid="cert-manager-log">{{ state.message }}</p>
        <p v-else-if="busyStatus && state.message" class="text-xs text-gray-400 bg-gray-900 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap max-h-32 overflow-y-auto" data-testid="cert-manager-log">{{ state.message }}</p>
        <details v-else-if="state.message" class="text-xs text-gray-400">
          <summary class="cursor-pointer select-none hover:text-gray-300">{{ t('settings.regionDetail.certManager.log') }}</summary>
          <p class="mt-2 bg-gray-900 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap wrap-break-word max-h-40 overflow-y-auto">{{ state.message }}</p>
        </details>
        <p v-if="state.cluster_error" class="text-[11px] text-amber-300/90">{{ t('settings.regionDetail.certManager.clusterError', { error: state.cluster_error }) }}</p>

        <!-- RunR's default issuer: mode and ACME e-mail (region settings) -->
        <div v-if="canManage" class="space-y-2 border-t border-gray-800/60 pt-3">
          <p class="text-xs text-gray-300 font-medium">{{ t('settings.regionDetail.certManager.defaultIssuer') }}</p>
          <div class="grid sm:grid-cols-2 gap-2">
            <div>
              <label class="text-[11px] text-gray-400" for="cert-manager-mode">{{ t('settings.regionDetail.certManager.mode') }}</label>
              <select id="cert-manager-mode" v-model="form.issuer_mode" class="input text-xs" data-testid="cert-manager-mode">
                <option v-for="m in ['auto', 'acme', 'ca']" :key="m" :value="m">{{ t(`settings.regionDetail.certManager.mode_${m}`) }}</option>
              </select>
            </div>
            <div>
              <label class="text-[11px] text-gray-400" for="cert-manager-email">{{ t('settings.regionDetail.certManager.acmeEmail') }}</label>
              <input id="cert-manager-email" v-model="form.acme_email" type="email" maxlength="254" class="input text-xs"
                :placeholder="state.acme?.email_source === 'platform' ? state.acme.email : 'ops@example.com'" data-testid="cert-manager-email" />
            </div>
          </div>
          <p class="text-[10px] text-gray-500">{{ t('settings.regionDetail.certManager.modeHint') }}</p>
          <p class="text-[10px] text-gray-500">
            {{ state.acme?.public ? t('settings.regionDetail.certManager.reachable') : t('settings.regionDetail.certManager.notReachable', { reason: state.acme?.public_reason ?? '-' }) }}
          </p>
          <button type="button" class="btn-secondary text-xs" :disabled="saving" data-testid="cert-manager-save-issuer" @click="saveIssuerSettings">
            {{ saving ? t('common.saving') : t('settings.regionDetail.certManager.saveIssuer') }}
          </button>
        </div>

        <p v-if="error" class="text-xs text-red-400" data-testid="cert-manager-error">{{ error }}</p>

        <div v-if="canManage" class="flex justify-end gap-2">
          <button v-if="['ready', 'error'].includes(status)" type="button" class="btn-secondary text-xs text-red-400! hover:text-red-300!"
            :disabled="busy" data-testid="cert-manager-remove" @click="remove">
            {{ t('settings.regionDetail.certManager.remove') }}
          </button>
          <button v-if="status !== 'uninstalling'" type="button" class="btn-primary text-xs" :disabled="busy" data-testid="cert-manager-install" @click="install">
            {{ t(status === 'not_installed' ? 'settings.regionDetail.certManager.install' : 'settings.regionDetail.certManager.reinstall') }}
          </button>
        </div>
        <p v-else class="text-[11px] text-gray-400 italic">{{ t('settings.regionDetail.certManager.readOnly') }}</p>
      </template>
    </div>
  </div>
</template>
