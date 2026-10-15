<script setup>
// Region → Supply chain (docs/supply-chain.md): SBOM / scan, vulnerability DB (online, mirror,
// offline), policy gate, signing key, admission policy (sigstore policy-controller), CaaS image
// policy and the region's data location. The private key is never sent to the browser.
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { KeyRound, RefreshCw, Download, ShieldCheck } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { supplyChainApi, saveBlob } from '../api'
import SeverityBadges from './SeverityBadges.vue'
import StepChip from './StepChip.vue'
import TermText from '@/components/TermText.vue'

const props = defineProps({ region: { type: Object, required: true } })

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const settings  = ref(null)
const loading   = ref(false)
const saving    = ref(false)
const errors    = ref({})
const preview   = ref(null)
const runtimes  = ref([])
let timer = null

const FIELDS = [
  'sbom_enabled', 'scan_enabled', 'scan_external_images', 'rescan_interval_hours',
  'scanner_db_mode', 'scanner_db_source',
  'gate_mode', 'gate_max_critical', 'gate_max_high', 'gate_only_fixable', 'gate_require_scan',
  'signing_enabled', 'verify_on_deploy', 'attest_enabled',
  'admission_mode', 'admission_require_sbom', 'admission_exempt_images',
  'caas_policy', 'caas_allowed_registries', 'caas_trusted_keys', 'data_location',
]
const form = reactive({})

const canManage = computed(() => !!settings.value?.can_manage)
const busyState = computed(() => settings.value && (
  settings.value.signing_status === 'generating'
  || settings.value.scanner_db_status === 'refreshing'
  || ['installing', 'removing'].includes(settings.value.admission_status)))

const lines = (text) => String(text ?? '').split('\n').map(s => s.trim()).filter(Boolean)
const pemBlocks = (text) => (String(text ?? '').match(/-----BEGIN PUBLIC KEY-----[\s\S]+?-----END PUBLIC KEY-----/g) ?? []).map(s => s.trim())

function fillForm(s) {
  for (const f of FIELDS) {
    if (['admission_exempt_images', 'caas_allowed_registries'].includes(f)) form[f] = (s[f] ?? []).join('\n')
    else if (f === 'caas_trusted_keys') form[f] = (s[f] ?? []).join('\n')
    else form[f] = s[f] ?? (['gate_max_critical', 'gate_max_high', 'scanner_db_source', 'data_location'].includes(f) ? null : form[f])
  }
}

function payloadValue(f) {
  if (['admission_exempt_images', 'caas_allowed_registries'].includes(f)) return lines(form[f])
  if (f === 'caas_trusted_keys') return pemBlocks(form[f])
  if (['gate_max_critical', 'gate_max_high'].includes(f)) return form[f] === '' || form[f] === null ? null : Number(form[f])
  if (f === 'rescan_interval_hours') return Number(form[f] ?? 0)
  if (['scanner_db_source', 'data_location'].includes(f)) return form[f] === '' ? null : form[f]
  return form[f]
}

/** Only what changed is sent. */
const changes = computed(() => {
  if (!settings.value) return {}
  const out = {}
  for (const f of FIELDS) {
    const value = payloadValue(f)
    const before = settings.value[f] ?? (Array.isArray(value) ? [] : null)
    if (JSON.stringify(value) !== JSON.stringify(before)) out[f] = value
  }
  return out
})

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const { data } = await supplyChainApi.region(props.region.id)
    settings.value = data?.data ?? null
    if (settings.value && !silent) fillForm(settings.value)
  } finally {
    loading.value = false
  }
  clearTimeout(timer)
  if (busyState.value) timer = setTimeout(() => load(true), 4000)
}

async function loadRuntimeImages() {
  try {
    const { data } = await supplyChainApi.runtimeImages(props.region.id)
    runtimes.value = data?.data ?? []
  } catch { runtimes.value = [] }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await supplyChainApi.updateRegion(props.region.id, changes.value)
    settings.value = data?.data ?? settings.value
    fillForm(settings.value)
    toast.success(t('supplyChain.saved'))
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    toast.error(e?.response?.data?.message ?? t('supplyChain.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function action(fn, confirmOptions = null) {
  if (confirmOptions && !(await confirm(confirmOptions))) return
  try {
    const { data } = await fn()
    if (data?.data) settings.value = data.data
    load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('supplyChain.saveFailed'))
  }
}

const generateKey = () => action(() => supplyChainApi.generateKey(props.region.id),
  settings.value?.signing_status === 'ready'
    ? { title: t('supplyChain.region.rotateKey'), message: t('supplyChain.region.rotateConfirm', { region: props.region.name }) }
    : null)
const forgetRetired = () => action(() => supplyChainApi.forgetRetiredKeys(props.region.id),
  { title: t('supplyChain.region.forgetRetired'), message: t('supplyChain.region.forgetConfirm'), destructive: true })
const refreshDb = () => action(() => supplyChainApi.refreshScannerDb(props.region.id))
const installAdmission = () => action(() => supplyChainApi.installAdmission(props.region.id, form.admission_mode === 'warn' ? 'warn' : 'enforce'))
const removeAdmission = () => action(() => supplyChainApi.removeAdmission(props.region.id),
  { title: t('supplyChain.region.remove'), message: t('supplyChain.region.removeConfirm', { region: props.region.name }), destructive: true })

async function togglePreview() {
  if (preview.value) { preview.value = null; return }
  try {
    const { data } = await supplyChainApi.admissionPreview(props.region.id)
    preview.value = data?.data ?? null
  } catch { preview.value = null }
}

async function downloadPublicKey() {
  try {
    saveBlob(await supplyChainApi.publicKey(props.region.id), `runr-${props.region.name}-cosign.pub`)
  } catch { /* 404: no key */ }
}

async function downloadRuntimeSbom(row) {
  try {
    saveBlob(await supplyChainApi.downloadRuntimeSbom(props.region.id, row.report.id, 'spdx-json'), `runtime-${row.runtime}-${row.version}.spdx.json`)
  } catch { /* no SBOM */ }
}

function fmt(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}

const fieldError = (key) => errors.value?.[key]?.[0] ?? Object.entries(errors.value ?? {}).find(([k]) => k.startsWith(`${key}.`))?.[1]?.[0]

const keyReady = computed(() => settings.value?.signing_status === 'ready')
const admissionInstalled = computed(() => settings.value?.admission_status === 'ready')

onMounted(() => { load(); loadRuntimeImages() })
onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <div class="space-y-5" data-region-supply-chain>
    <p class="text-xs text-gray-400">{{ t('supplyChain.region.intro') }}</p>

    <div v-if="loading && !settings" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <template v-else-if="settings">
      <p v-if="!canManage" class="text-xs text-amber-400" data-read-only>{{ t('supplyChain.region.readOnly') }}</p>

      <fieldset :disabled="!canManage" class="space-y-5">
        <!-- Pipeline -->
        <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-2">
          <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.region.pipeline') }}</h4>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.sbom_enabled" type="checkbox" data-field="sbom_enabled" /> {{ t('supplyChain.region.sbom') }}</label>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.scan_enabled" type="checkbox" data-field="scan_enabled" /> {{ t('supplyChain.region.scan') }}</label>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.scan_external_images" type="checkbox" data-field="scan_external_images" /> {{ t('supplyChain.region.scanExternal') }}</label>
          <div class="flex items-center gap-2 text-xs text-gray-300">
            <span>{{ t('supplyChain.region.rescanHours') }}</span>
            <input v-model="form.rescan_interval_hours" type="number" min="0" max="720" class="input text-xs w-20 py-1" data-field="rescan_interval_hours" />
          </div>
        </section>

        <!-- Vulnerability DB -->
        <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-2">
          <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.region.scannerDb') }}</h4>
          <label v-for="mode in ['online', 'mirror', 'offline']" :key="mode" class="flex items-center gap-2 text-xs text-gray-300">
            <input v-model="form.scanner_db_mode" type="radio" :value="mode" :data-db-mode="mode" /> {{ t(`supplyChain.region.dbMode.${mode}`) }}
          </label>
          <p v-if="fieldError('scanner_db_mode')" class="text-xs text-red-400">{{ fieldError('scanner_db_mode') }}</p>
          <template v-if="form.scanner_db_mode !== 'online'">
            <div v-if="form.scanner_db_mode === 'mirror'">
              <label class="label">{{ t('supplyChain.region.dbSource') }}</label>
              <input v-model="form.scanner_db_source" class="input text-xs font-mono" :placeholder="settings.scanner_db_default_source" data-field="scanner_db_source" />
            </div>
            <p v-if="settings.scanner_db_repository" class="text-[11px] font-mono text-gray-500">{{ t('supplyChain.region.dbRepository', { repo: settings.scanner_db_repository }) }}</p>
            <div class="flex flex-wrap items-center gap-3 text-[11px] text-gray-400">
              <span>{{ t('supplyChain.region.dbStatus', { status: settings.scanner_db_status }) }}</span>
              <span v-if="settings.scanner_db_updated_at">{{ t('supplyChain.region.dbUpdated', { date: fmt(settings.scanner_db_updated_at) }) }}</span>
              <span v-if="settings.scanner_db_refreshed_at">{{ t('supplyChain.region.dbRefreshed', { date: fmt(settings.scanner_db_refreshed_at) }) }}</span>
              <button type="button" class="btn btn-secondary text-[11px] py-1" :disabled="settings.scanner_db_status === 'refreshing'" data-db-refresh @click="refreshDb">
                <RefreshCw class="w-3 h-3" :class="settings.scanner_db_status === 'refreshing' ? 'animate-spin' : ''" />
                {{ settings.scanner_db_status === 'refreshing' ? t('supplyChain.region.dbRefreshing') : t('supplyChain.region.dbRefresh') }}
              </button>
            </div>
            <p v-if="settings.scanner_db_message" class="text-[11px] text-amber-400 whitespace-pre-line">{{ settings.scanner_db_message }}</p>
          </template>
        </section>

        <!-- Policy gate -->
        <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-2">
          <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.region.gate') }}</h4>
          <select v-model="form.gate_mode" class="input text-xs w-auto" data-field="gate_mode">
            <option v-for="mode in ['off', 'warn', 'enforce']" :key="mode" :value="mode">{{ t(`supplyChain.region.gateMode.${mode}`) }}</option>
          </select>
          <div v-if="form.gate_mode !== 'off'" class="space-y-2">
            <div class="flex flex-wrap items-end gap-3">
              <div>
                <label class="label">{{ t('supplyChain.region.maxCritical') }}</label>
                <input v-model="form.gate_max_critical" type="number" min="0" class="input text-xs w-24" :placeholder="t('supplyChain.region.limitHint')" data-field="gate_max_critical" />
              </div>
              <div>
                <label class="label">{{ t('supplyChain.region.maxHigh') }}</label>
                <input v-model="form.gate_max_high" type="number" min="0" class="input text-xs w-24" :placeholder="t('supplyChain.region.limitHint')" data-field="gate_max_high" />
              </div>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.gate_only_fixable" type="checkbox" /> {{ t('supplyChain.region.onlyFixable') }}</label>
            <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.gate_require_scan" type="checkbox" /> {{ t('supplyChain.region.requireScan') }}</label>
          </div>
        </section>

        <!-- Signing -->
        <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-2" data-signing>
          <h4 class="text-xs font-semibold text-gray-200 flex items-center gap-1.5"><KeyRound class="w-3.5 h-3.5" /> {{ t('supplyChain.region.signing') }}</h4>
          <div class="flex flex-wrap items-center gap-3 text-xs">
            <StepChip :status="keyReady ? 'ok' : settings.signing_status === 'failed' ? 'failed' : settings.signing_status === 'generating' ? 'running' : 'skipped'"
              :label="t(`supplyChain.region.keyStatus.${settings.signing_status}`)" />
            <span v-if="settings.signing_key_fingerprint" class="font-mono text-[11px] text-gray-400 break-all" data-fingerprint>
              {{ t('supplyChain.region.keyFingerprint') }}: {{ settings.signing_key_fingerprint }}
            </span>
            <span v-if="settings.signing_key_created_at" class="text-[11px] text-gray-500">{{ t('supplyChain.region.keyCreated', { date: fmt(settings.signing_key_created_at) }) }}</span>
          </div>
          <p v-if="settings.signing_message" class="text-[11px] text-red-400">{{ settings.signing_message }}</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary text-[11px] py-1" :disabled="settings.signing_status === 'generating'" data-generate-key @click="generateKey">
              {{ keyReady ? t('supplyChain.region.rotateKey') : t('supplyChain.region.generateKey') }}
            </button>
            <button v-if="keyReady" type="button" class="btn btn-secondary text-[11px] py-1" @click="downloadPublicKey">
              <Download class="w-3 h-3" /> {{ t('supplyChain.region.publicKey') }}
            </button>
          </div>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.signing_enabled" type="checkbox" data-field="signing_enabled" /> {{ t('supplyChain.region.signingEnabled') }}</label>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.verify_on_deploy" type="checkbox" /> {{ t('supplyChain.region.verifyOnDeploy') }}</label>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.attest_enabled" type="checkbox" /> {{ t('supplyChain.region.attest') }}</label>
          <div v-if="settings.signing_retired_keys?.length" class="pt-1 space-y-1">
            <p class="text-[11px] text-gray-400">{{ t('supplyChain.region.retiredKeys') }}</p>
            <p v-for="k in settings.signing_retired_keys" :key="k.fingerprint" class="text-[10px] font-mono text-gray-500 break-all">{{ k.fingerprint }} · {{ fmt(k.retired_at) }}</p>
            <button type="button" class="btn btn-secondary text-[11px] py-1" data-forget-retired @click="forgetRetired">{{ t('supplyChain.region.forgetRetired') }}</button>
          </div>
        </section>

        <!-- Admission -->
        <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-2" data-admission>
          <h4 class="text-xs font-semibold text-gray-200 flex items-center gap-1.5"><ShieldCheck class="w-3.5 h-3.5" /> {{ t('supplyChain.region.admission') }}</h4>
          <p class="text-[11px] text-gray-400">{{ t('supplyChain.region.admissionIntro') }}</p>
          <div class="flex flex-wrap items-center gap-3 text-xs">
            <StepChip :status="admissionInstalled ? 'ok' : settings.admission_status === 'failed' ? 'failed' : ['installing', 'removing'].includes(settings.admission_status) ? 'running' : 'skipped'"
              :label="t(`supplyChain.region.admissionStatus.${settings.admission_status}`)" />
            <select v-model="form.admission_mode" class="input text-xs w-auto py-1" data-field="admission_mode">
              <option v-if="admissionInstalled" value="off">{{ t('supplyChain.region.gateMode.off') }}</option>
              <option value="enforce">{{ t('supplyChain.region.admissionMode.enforce') }}</option>
              <option value="warn">{{ t('supplyChain.region.admissionMode.warn') }}</option>
            </select>
          </div>
          <p v-if="settings.admission_message" class="text-[11px] text-red-400">{{ settings.admission_message }}</p>
          <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.admission_require_sbom" type="checkbox" /> {{ t('supplyChain.region.requireSbom') }}</label>
          <div>
            <label class="label">{{ t('supplyChain.region.exemptions') }}</label>
            <textarea v-model="form.admission_exempt_images" class="input h-16 text-xs font-mono resize-y" placeholder="registry.example.com/tools/**" data-field="admission_exempt_images" />
            <p v-if="fieldError('admission_exempt_images')" class="text-xs text-red-400">{{ fieldError('admission_exempt_images') }}</p>
          </div>
          <div>
            <label class="label">{{ t('supplyChain.region.caasPolicy') }}</label>
            <select v-model="form.caas_policy" class="input text-xs w-auto" data-field="caas_policy">
              <option v-for="p in ['any', 'allowlist', 'signed']" :key="p" :value="p">{{ t(`supplyChain.region.caasMode.${p}`) }}</option>
            </select>
          </div>
          <div v-if="form.caas_policy !== 'any'">
            <label class="label"><TermText path="supplyChain.region.caasRegistries" /></label>
            <textarea v-model="form.caas_allowed_registries" class="input h-16 text-xs font-mono resize-y" placeholder="ghcr.io/acme" data-field="caas_allowed_registries" />
            <p v-if="fieldError('caas_allowed_registries')" class="text-xs text-red-400">{{ fieldError('caas_allowed_registries') }}</p>
          </div>
          <div v-if="form.caas_policy === 'signed'">
            <label class="label">{{ t('supplyChain.region.caasKeys') }}</label>
            <textarea v-model="form.caas_trusted_keys" class="input h-24 text-xs font-mono resize-y" placeholder="-----BEGIN PUBLIC KEY-----" data-field="caas_trusted_keys" />
            <p v-if="fieldError('caas_trusted_keys')" class="text-xs text-red-400">{{ fieldError('caas_trusted_keys') }}</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary text-[11px] py-1" :disabled="!keyReady || ['installing', 'removing'].includes(settings.admission_status)"
              :title="keyReady ? '' : t('supplyChain.region.keyRequired')" data-install-admission @click="installAdmission">
              {{ admissionInstalled ? t('supplyChain.region.update') : t('supplyChain.region.install') }}
            </button>
            <button v-if="admissionInstalled" type="button" class="btn btn-secondary text-[11px] py-1" data-remove-admission @click="removeAdmission">{{ t('supplyChain.region.remove') }}</button>
            <button type="button" class="btn btn-secondary text-[11px] py-1" data-preview @click="togglePreview">{{ preview ? t('supplyChain.region.hidePreview') : t('supplyChain.region.preview') }}</button>
          </div>
          <p v-if="!keyReady" class="text-[11px] text-gray-500">{{ t('supplyChain.region.keyRequired') }}</p>
          <pre v-if="preview" class="text-[10px] font-mono text-gray-400 bg-gray-950 rounded p-2 max-h-64 overflow-auto" data-preview-body>{{ JSON.stringify(preview.policies, null, 2) }}</pre>
        </section>

        <!-- Data residency -->
        <section class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-2">
          <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.region.residency') }}</h4>
          <label class="label">{{ t('supplyChain.region.dataLocation') }}</label>
          <input v-model="form.data_location" class="input text-xs w-48 font-mono" placeholder="TR" data-field="data_location" />
          <p v-if="fieldError('data_location')" class="text-xs text-red-400">{{ fieldError('data_location') }}</p>
          <p class="text-[11px] text-gray-500">{{ t('supplyChain.region.dataLocationHint') }}</p>
        </section>

        <div class="flex justify-end">
          <button type="button" class="btn btn-primary text-xs" :disabled="saving || !Object.keys(changes).length" data-save @click="save">{{ t('supplyChain.save') }}</button>
        </div>
      </fieldset>

      <!-- Runtime images -->
      <section class="space-y-1.5">
        <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.region.runtimeImages') }}</h4>
        <p v-if="!runtimes.length" class="text-xs text-gray-500">{{ t('supplyChain.region.noRuntimeImages') }}</p>
        <div v-for="row in runtimes" :key="row.build_id" class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-800 bg-gray-900/40 px-3 py-2 text-xs" :data-runtime-image="`${row.runtime}:${row.version}`">
          <span class="font-mono text-gray-200">{{ row.runtime }} {{ row.version }}</span>
          <span class="font-mono text-[10px] text-gray-500 truncate max-w-xs" :title="row.image">{{ row.image }}</span>
          <SeverityBadges v-if="row.report?.scan_status === 'ok'" :counts="row.report.counts" compact />
          <StepChip v-if="row.report" :status="row.report.signature_status" />
          <button v-if="row.report?.sbom_status === 'ok'" type="button" class="text-gray-400 hover:text-gray-100" @click="downloadRuntimeSbom(row)"><Download class="w-3.5 h-3.5" /></button>
        </div>
      </section>
    </template>
  </div>
</template>
