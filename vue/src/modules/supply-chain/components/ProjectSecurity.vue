<script setup>
// Project → Security tab (docs/supply-chain.md): SBOM, vulnerability scan and signature of each
// version's image, the deployed versions, the region's policy and the policy overrides.
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldCheck, ShieldAlert, RefreshCw, Download, X } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'
import { supplyChainApi, saveBlob } from '../api'
import SeverityBadges from './SeverityBadges.vue'
import FindingsTable from './FindingsTable.vue'
import StepChip from './StepChip.vue'

const props = defineProps({ project: { type: Object, required: true } })

const { t, locale } = useI18n()
const toast = useToast()

const data          = ref(null)
const loading       = ref(false)
const selectedId    = ref(null)
const detail        = ref(null)
const detailLoading = ref(false)
const busy          = ref({})
let timer = null
// The newest load / detail wins (a rescan, the poll, another version picked); a load that ends
// after the tab is gone schedules no more polls
const latest       = useLatest()
const latestDetail = useLatest()

const versions    = computed(() => data.value?.versions ?? [])
const deployments = computed(() => data.value?.deployments ?? [])
const overrides   = computed(() => data.value?.overrides ?? [])
const policy      = computed(() => data.value?.policy ?? null)
const anyRunning  = computed(() => versions.value.some(v => ['pending', 'running'].includes(v.report?.status)))

async function load(silent = false) {
  const current = latest.begin()
  if (!silent) loading.value = true
  try {
    const { data: res } = await supplyChainApi.projectSecurity(props.project.id)
    if (current()) data.value = res?.data ?? null
  } catch {
    if (current() && !silent) data.value = null
  } finally {
    if (current()) loading.value = false
  }
  if (!current()) return
  if (selectedId.value && silent) loadDetail(selectedId.value, true)
  schedule()
}

// Poll slowly while a run is queued or running
function schedule() {
  clearTimeout(timer)
  if (anyRunning.value) timer = setTimeout(() => load(true), 5000)
}

async function loadDetail(versionId, silent = false) {
  const current = latestDetail.begin()
  selectedId.value = versionId
  if (!silent) {
    detailLoading.value = true
    detail.value = null
  }
  try {
    const { data: res } = await supplyChainApi.versionSecurity(props.project.id, versionId)
    if (current()) detail.value = res?.data ?? null
  } catch {
    if (current() && !silent) detail.value = null
  } finally {
    if (current()) detailLoading.value = false
  }
}

function closeDetail() {
  latestDetail.cancel()
  detailLoading.value = false
  selectedId.value = null
  detail.value = null
}

async function rescan(v) {
  busy.value = { ...busy.value, [v.id]: true }
  try {
    await supplyChainApi.rescan(props.project.id, v.id)
    toast.success(t('supplyChain.project.rescanQueued'))
    await load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('supplyChain.project.rescanFailed'))
  } finally {
    busy.value = { ...busy.value, [v.id]: false }
  }
}

async function download(v, format) {
  try {
    const res = await supplyChainApi.downloadSbom(props.project.id, v.id, format)
    saveBlob(res, `${props.project.slug ?? props.project.name}-${v.label}.${format === 'spdx-json' ? 'spdx' : 'cdx'}.json`)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('supplyChain.project.downloadFailed'))
  }
}

function fmt(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}

function limit(value) {
  return value === null || value === undefined ? t('supplyChain.noLimit') : value
}

function shortImage(image) {
  if (!image) return '—'
  const parts = image.split('/')
  return parts.length > 2 ? `…/${parts.slice(-2).join('/')}` : image
}

const decisionBlocked = computed(() => detail.value?.decision?.blocked)
const decisionWarnings = computed(() => (detail.value?.decision?.violations ?? []).filter(v => !v.enforced))

onMounted(() => load())
onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <div class="py-5 space-y-5" data-project-security>
    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-sm font-semibold text-gray-100 flex items-center gap-2">
          <ShieldCheck class="w-4 h-4 text-sky-400" /> {{ t('supplyChain.project.title') }}
        </h3>
        <p class="text-xs text-gray-400 mt-1 max-w-3xl">{{ t('supplyChain.project.intro') }}</p>
      </div>
      <button type="button" class="btn btn-secondary text-xs shrink-0" :disabled="loading" @click="load()">
        <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" /> {{ t('supplyChain.refresh') }}
      </button>
    </div>

    <div v-if="loading && !data" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <template v-else-if="data">
      <!-- Region policy -->
      <div v-if="data.region" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 text-xs text-gray-300 flex flex-wrap items-center gap-x-4 gap-y-1" data-policy-summary>
        <span class="font-medium text-gray-100">{{ t('supplyChain.project.region', { name: data.region.name }) }}</span>
        <span v-if="data.region.data_location" class="text-gray-400">{{ t('supplyChain.project.location', { location: data.region.data_location }) }}</span>
        <span v-if="policy">
          {{ t('supplyChain.project.policy') }}: <strong>{{ t(`supplyChain.project.policyMode.${policy.mode}`) }}</strong>
          <template v-if="policy.mode !== 'off'">
            · {{ t('supplyChain.project.limits', { critical: limit(policy.max_critical), high: limit(policy.max_high) }) }}
            <template v-if="policy.only_fixable"> · {{ t('supplyChain.project.fixableOnly') }}</template>
            <template v-if="policy.require_scan"> · {{ t('supplyChain.project.requireScan') }}</template>
          </template>
        </span>
        <span v-if="policy?.require_signature" class="text-emerald-400">{{ t('supplyChain.project.signatureRequired') }}</span>
        <span v-if="data.pipeline && data.pipeline.admission !== 'off'" class="text-sky-300">{{ t('supplyChain.project.admission', { mode: data.pipeline.admission }) }}</span>
      </div>

      <!-- Versions -->
      <section>
        <h4 class="text-xs font-semibold text-gray-200 mb-2">{{ t('supplyChain.project.versions') }}</h4>
        <p v-if="!versions.length" class="text-xs text-gray-400">{{ t('supplyChain.project.noVersions') }}</p>
        <div v-else class="overflow-x-auto rounded-xl border border-gray-800">
          <table class="w-full text-xs">
            <thead class="bg-gray-900/80 text-gray-400">
              <tr>
                <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.project.version') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.project.image') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.project.sbom') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.project.scan') }}</th>
                <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.project.signature') }}</th>
                <th class="text-right font-medium px-3 py-2">{{ t('supplyChain.project.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in versions" :key="v.id" class="border-t border-gray-800/80"
                :class="selectedId === v.id ? 'bg-sky-950/30' : ''" :data-version-row="v.label">
                <td class="px-3 py-2 font-mono" style="color:rgb(var(--brand-500))">{{ v.label }}</td>
                <template v-if="!v.applicable">
                  <td colspan="4" class="px-3 py-2 text-gray-500">{{ t('supplyChain.project.notApplicable') }}</td>
                  <td></td>
                </template>
                <template v-else>
                  <td class="px-3 py-2">
                    <span class="font-mono text-gray-300" :title="v.image">{{ shortImage(v.image) }}</span>
                    <span class="block text-[10px] text-gray-500">{{ t(`supplyChain.kind.${v.image_kind}`) }}</span>
                  </td>
                  <template v-if="v.report">
                    <td class="px-3 py-2">
                      <StepChip :status="v.report.sbom_status" />
                      <span v-if="v.report.packages" class="ml-1 text-[10px] text-gray-500">{{ t('supplyChain.project.packages', { count: v.report.packages }) }}</span>
                    </td>
                    <td class="px-3 py-2">
                      <SeverityBadges v-if="v.report.scan_status === 'ok'" :counts="v.report.counts" compact />
                      <StepChip v-else :status="v.report.scan_status" />
                    </td>
                    <td class="px-3 py-2">
                      <StepChip :status="v.report.signature_status"
                        :label="v.report.signature_status === 'ok' ? t('supplyChain.project.signed') : null" />
                    </td>
                  </template>
                  <td v-else colspan="3" class="px-3 py-2 text-gray-500">{{ t('supplyChain.project.noReport') }}</td>
                  <td class="px-3 py-2 text-right whitespace-nowrap">
                    <button type="button" class="text-sky-400 hover:text-sky-300 mr-3" @click="loadDetail(v.id)">{{ t('supplyChain.project.details') }}</button>
                    <button type="button" class="text-gray-400 hover:text-gray-100 mr-3 disabled:opacity-40"
                      :disabled="busy[v.id] || ['pending', 'running'].includes(v.report?.status)" :title="t('supplyChain.project.rescan')"
                      @click="rescan(v)">
                      <RefreshCw class="w-3.5 h-3.5 inline" :class="['pending', 'running'].includes(v.report?.status) ? 'animate-spin' : ''" />
                    </button>
                    <button v-if="v.report?.sbom_status === 'ok'" type="button" class="text-gray-400 hover:text-gray-100"
                      :title="`${t('supplyChain.project.download')} (${t('supplyChain.project.downloadSpdx')})`" @click="download(v, 'spdx-json')">
                      <Download class="w-3.5 h-3.5 inline" />
                    </button>
                  </td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Version detail -->
      <section v-if="selectedId" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-4" data-version-detail>
        <div v-if="detailLoading" class="flex justify-center py-4">
          <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
        </div>
        <template v-else-if="detail">
          <div class="flex items-start justify-between gap-3">
            <div class="space-y-1 min-w-0">
              <h4 class="text-sm font-semibold text-gray-100">{{ detail.version.label }}
                <span v-if="detail.report" class="ml-2 text-xs font-normal text-gray-400">{{ t(`supplyChain.status.${detail.report.status}`) }}</span>
              </h4>
              <p v-if="detail.version.image" class="text-[11px] font-mono text-gray-400 break-all">{{ detail.version.image }}</p>
              <p v-if="detail.report?.image_digest" class="text-[11px] font-mono text-gray-500 break-all">{{ t('supplyChain.project.digest') }}: {{ detail.report.image_digest }}</p>
            </div>
            <button type="button" class="text-gray-400 hover:text-gray-100" :title="t('supplyChain.project.close')" @click="closeDetail"><X class="w-4 h-4" /></button>
          </div>

          <p v-if="!detail.version.applicable" class="text-xs text-gray-400">{{ t('supplyChain.project.notApplicable') }}</p>
          <p v-else-if="!detail.report" class="text-xs text-gray-400">{{ t('supplyChain.project.noReport') }}</p>
          <p v-if="detail.version.image_kind === 'wasm'" class="text-xs text-gray-400" data-wasm-note>{{ t('supplyChain.project.wasmNote') }}</p>

          <template v-if="detail.report">
            <div class="flex flex-wrap items-center gap-2 text-xs">
              <span class="text-gray-400">{{ t('supplyChain.project.sbom') }}</span><StepChip :status="detail.report.sbom_status" />
              <span class="text-gray-400 ml-2">{{ t('supplyChain.project.referrers') }}</span><StepChip :status="detail.report.referrer_status" />
              <span class="text-gray-400 ml-2">{{ t('supplyChain.project.scan') }}</span><StepChip :status="detail.report.scan_status" />
              <span class="text-gray-400 ml-2">{{ t('supplyChain.project.signature') }}</span><StepChip :status="detail.report.signature_status" />
              <span class="text-gray-400 ml-2">{{ t('supplyChain.project.attested') }}</span><StepChip :status="detail.report.attestation_status" />
            </div>
            <p v-if="detail.report.error" class="text-[11px] text-red-400">{{ t('supplyChain.project.error', { error: detail.report.error }) }}</p>

            <!-- Deploy decision -->
            <div v-if="detail.decision?.applicable" class="rounded-lg border px-3 py-2 text-xs"
              :class="decisionBlocked ? 'border-red-800/60 bg-red-950/30 text-red-300' : decisionWarnings.length ? 'border-amber-800/50 bg-amber-950/20 text-amber-300' : 'border-emerald-800/50 bg-emerald-950/20 text-emerald-300'"
              data-decision>
              <p class="font-medium flex items-center gap-1.5">
                <ShieldAlert v-if="decisionBlocked || decisionWarnings.length" class="w-3.5 h-3.5" />
                <ShieldCheck v-else class="w-3.5 h-3.5" />
                {{ t('supplyChain.project.decision') }}:
                {{ decisionBlocked ? t('supplyChain.project.decisionBlocked') : decisionWarnings.length ? t('supplyChain.project.decisionWarn') : t('supplyChain.project.decisionPass') }}
              </p>
              <ul v-if="detail.decision.violations.length" class="list-disc ml-5 mt-1">
                <li v-for="v in detail.decision.violations" :key="v.rule">{{ v.message }}</li>
              </ul>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <!-- Scan -->
              <div class="space-y-1 text-xs">
                <SeverityBadges v-if="detail.report.scan_status === 'ok'" :counts="detail.report.counts" all />
                <p v-if="detail.report.scanned_at" class="text-gray-400">{{ t('supplyChain.project.scannedAt', { date: fmt(detail.report.scanned_at) }) }}</p>
                <p v-if="detail.report.scanner?.version" class="text-gray-500">{{ t('supplyChain.project.scanner', { version: detail.report.scanner.version, date: fmt(detail.report.scanner.db_updated_at) }) }}</p>
              </div>
              <!-- SBOM -->
              <div class="space-y-1 text-xs" data-sbom-summary>
                <p v-if="detail.report.sbom_summary" class="text-gray-300">{{ t('supplyChain.project.packages', { count: detail.report.sbom_summary.packages }) }}</p>
                <p v-if="detail.report.sbom_summary?.generator" class="text-gray-500">{{ t('supplyChain.project.generator', { generator: detail.report.sbom_summary.generator }) }}</p>
                <p v-if="Object.keys(detail.report.sbom_summary?.by_type ?? {}).length" class="text-gray-400">
                  {{ t('supplyChain.project.byType') }}:
                  <span v-for="(n, type) in detail.report.sbom_summary.by_type" :key="type" class="font-mono mr-2">{{ type }} {{ n }}</span>
                </p>
                <p v-if="Object.keys(detail.report.sbom_summary?.licenses ?? {}).length" class="text-gray-400">
                  {{ t('supplyChain.project.licenses') }}:
                  <span v-for="(n, license) in detail.report.sbom_summary.licenses" :key="license" class="font-mono mr-2">{{ license }} ({{ n }})</span>
                </p>
                <div v-if="detail.report.sbom_formats?.length" class="flex gap-2 pt-1">
                  <button v-if="detail.report.sbom_formats.includes('spdx-json')" type="button" class="btn btn-secondary text-[11px] py-1"
                    data-download="spdx-json" @click="download(detail.version, 'spdx-json')">
                    <Download class="w-3 h-3" /> {{ t('supplyChain.project.downloadSpdx') }}
                  </button>
                  <button v-if="detail.report.sbom_formats.includes('cyclonedx-json')" type="button" class="btn btn-secondary text-[11px] py-1"
                    data-download="cyclonedx-json" @click="download(detail.version, 'cyclonedx-json')">
                    <Download class="w-3 h-3" /> {{ t('supplyChain.project.downloadCdx') }}
                  </button>
                </div>
              </div>
              <!-- Signature -->
              <div class="space-y-1 text-xs">
                <p :class="detail.report.signature_status === 'ok' ? 'text-emerald-400' : 'text-gray-400'">
                  {{ detail.report.signature_status === 'ok' ? t('supplyChain.project.signed') : t('supplyChain.project.unsigned') }}
                  <template v-if="detail.report.signed_at"> · {{ fmt(detail.report.signed_at) }}</template>
                </p>
                <p v-if="detail.report.signature_key_fingerprint" class="text-[10px] font-mono text-gray-500 break-all">
                  {{ t('supplyChain.project.fingerprint') }}: {{ detail.report.signature_key_fingerprint }}
                </p>
              </div>
            </div>

            <FindingsTable v-if="detail.report.scan_status === 'ok'" :findings="detail.report.findings ?? []" :total="detail.report.findings_total" />
          </template>
        </template>
      </section>

      <!-- Deployed versions -->
      <section>
        <h4 class="text-xs font-semibold text-gray-200 mb-2">{{ t('supplyChain.project.deployments') }}</h4>
        <p v-if="!deployments.length" class="text-xs text-gray-400">{{ t('supplyChain.project.noDeployments') }}</p>
        <div v-else class="space-y-1.5">
          <div v-for="d in deployments" :key="d.id" class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-800 bg-gray-900/40 px-3 py-2 text-xs" :data-deployment-row="d.id">
            <span class="font-mono" style="color:rgb(var(--brand-500))">{{ d.version_label ?? '—' }}</span>
            <span class="text-gray-400">{{ d.status }}</span>
            <span class="text-gray-500">{{ fmt(d.created_at) }}</span>
            <SeverityBadges v-if="d.report?.scan_status === 'ok'" :counts="d.report.counts" compact />
            <StepChip v-if="d.report" :status="d.report.signature_status" :label="d.report.signature_status === 'ok' ? t('supplyChain.project.signed') : null" />
          </div>
        </div>
      </section>

      <!-- Overrides -->
      <section v-if="overrides.length">
        <h4 class="text-xs font-semibold text-gray-200 mb-2">{{ t('supplyChain.project.overrides') }}</h4>
        <div class="space-y-1.5">
          <div v-for="o in overrides" :key="o.id" class="rounded-lg border border-amber-900/50 bg-amber-950/10 px-3 py-2 text-xs" :data-override="o.id">
            <p class="text-amber-300">{{ o.reason }}</p>
            <p class="text-gray-500 mt-0.5">
              {{ t('supplyChain.project.overrideBy', { user: o.username ?? '—', date: fmt(o.created_at) }) }}
              · {{ (o.violations ?? []).map(v => v.rule).join(', ') }}
            </p>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
