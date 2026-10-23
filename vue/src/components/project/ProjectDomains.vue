<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { saveBlob } from '@/modules/supply-chain/api'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import DomainTlsModal from '@/components/project/DomainTlsModal.vue'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'
import {
  Globe, Copy, Check, ExternalLink, Lock, Trash2, RefreshCw,
  ChevronDown, ChevronRight, Plus, AlertTriangle, ShieldCheck,
} from 'lucide-vue-next'

const props = defineProps({
  projectId: { type: [String, Number], required: true },
})
// Adding, verifying, TLS and removing domains: domains.manage on the project
const canManage = computed(() => usePermissionsStore().can('domains.manage', String(props.projectId)))

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const settingsStore = useSettingsStore()

// ── State ────────────────────────────────────────────────────────────────────
const domains    = ref([])
const meta       = ref(null)
const loading    = ref(true)
const refreshing = ref(false)
const loadError  = ref(null)

const newDomain = ref('')
const adding    = ref(false)
const addError  = ref(null)

const busy      = ref({})   // domainId → 'verify' | 'delete'
const notices   = ref({})   // domainId → message from a failed verify/delete
const expanded  = ref({})   // domainId → DNS panel open/closed (explicit toggles only)
const tlsDomain = ref(null)
const copiedKey = ref(null)

const eligible = computed(() => !!meta.value && meta.value.eligible !== false)

// meta.cert_manager: the certificate new domains get (cert-manager where the region issues them),
// or why the region cannot issue them yet and what to do
const certManagerNote = computed(() => {
  const c = meta.value?.cert_manager
  if (!c) return null
  if (!c.available) return c.reason ? t('project.domains.certManagerMissing', { reason: c.reason }) : null
  if (c.default_mode !== 'cert-manager') return null
  const kind = c.kind === 'acme' ? t('project.domains.certManagerKindAcme')
    : c.kind === 'ca' ? t('project.domains.certManagerKindCa')
      : t('project.domains.certManagerKindOwn', { issuer: c.issuer })
  return t('project.domains.certManagerDefault', { kind })
})

async function downloadCa() {
  try {
    saveBlob(await projectsApi.domainsCaCertificate(props.projectId), 'runr-region-ca.crt')
  } catch (e) {
    toast.error(e.response?.status === 404 ? t('project.domains.caMissing') : (e.response?.data?.message ?? e.message))
  }
}

// ── Loading ──────────────────────────────────────────────────────────────────
async function load({ silent = false } = {}) {
  if (silent) refreshing.value = true
  else loading.value = true
  loadError.value = null
  try {
    const { data } = await projectsApi.domains(props.projectId)
    domains.value = data.data ?? []
    meta.value    = data.meta ?? null
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('project.domains.loadFailed')
  } finally {
    loading.value    = false
    refreshing.value = false
  }
}

onMounted(() => load())
watch(() => props.projectId, () => load())

// ── Helpers ──────────────────────────────────────────────────────────────────
function upsert(domain) {
  if (!domain?.id) return
  const idx = domains.value.findIndex(d => d.id === domain.id)
  if (idx === -1) domains.value = [...domains.value, domain]
  else domains.value = domains.value.map(d => (d.id === domain.id ? domain : d))
}

function setBusy(id, value) {
  const next = { ...busy.value }
  if (value) next[id] = value
  else delete next[id]
  busy.value = next
}

function setNotice(id, text) {
  const next = { ...notices.value }
  if (text) next[id] = text
  else delete next[id]
  notices.value = next
}

function isExpanded(d) {
  return expanded.value[d.id] ?? d.status !== 'active'
}

function toggle(d) {
  expanded.value = { ...expanded.value, [d.id]: !isExpanded(d) }
}

function statusClass(status) {
  const base = 'inline-flex items-center px-2 py-0.5 rounded-sm text-[10px] font-medium border'
  switch (status) {
    case 'active':   return `${base} bg-emerald-900/40 text-emerald-400 border-emerald-800/50`
    case 'verified': return `${base} bg-blue-900/40 text-blue-300 border-blue-800/50`
    case 'error':    return `${base} bg-red-900/40 text-red-400 border-red-800/50`
    case 'pending':  return `${base} bg-yellow-900/40 text-yellow-400 border-yellow-800/50`
    default:         return `${base} bg-gray-800 text-gray-400 border-gray-700`
  }
}

function fmtDate(iso) {
  try { return new Date(iso).toLocaleDateString() } catch { return iso }
}

function expiryInfo(d) {
  if (!d.tls_expires_at) return null
  const ts = new Date(d.tls_expires_at).getTime()
  if (Number.isNaN(ts)) return null
  const days = (ts - Date.now()) / 86400000
  const date = fmtDate(d.tls_expires_at)
  if (days < 0)   return { text: t('project.domains.tlsExpired', { date }), cls: 'text-red-400' }
  if (days <= 14) return { text: t('project.domains.tlsExpires', { date }), cls: 'text-amber-400' }
  return { text: t('project.domains.tlsExpires', { date }), cls: 'text-gray-400' }
}

function dnsSections(d) {
  const sections = [
    {
      key:   'txt',
      title: t('project.domains.ownershipTitle'),
      hint:  t('project.domains.ownershipHint'),
      rows:  d.verification ? [d.verification] : [],
    },
    {
      key:   'route',
      title: t('project.domains.routingTitle'),
      hint:  d.regions ? t('project.domains.globalRoutingHint') : t('project.domains.routingHint'),
      rows:  d.dns_targets ?? [],
    },
  ]
  // A global project's domain (docs/global-projects.md, "Custom domains"): every region issues its
  // certificate through the global zone, following this CNAME
  if (d.acme_challenge) {
    sections.push({
      key:   'acme',
      title: t('project.domains.acmeTitle'),
      hint:  t('project.domains.acmeHint'),
      rows:  [d.acme_challenge],
    })
  }
  return sections
}

// A global project's domain in each region: region name, state, why it is not active / issued
function regionStates(d) {
  if (!d.regions || typeof d.regions !== 'object') return []
  const names = Object.fromEntries((settingsStore.data.regions ?? []).map(r => [r.id, r.name]))
  return Object.entries(d.regions).map(([id, state]) => ({
    id,
    name:    names[id] ?? id,
    status:  state?.status ?? 'pending',
    message: state?.message ?? null,
    expires: state?.tls_expires_at ?? null,
  }))
}

async function copy(text, key) {
  if (text == null) return
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(String(text))
    } else {
      // Fallback for non-secure (plain http) origins
      const el = document.createElement('textarea')
      el.value = String(text)
      el.setAttribute('readonly', '')
      el.style.position = 'fixed'
      el.style.opacity  = '0'
      document.body.appendChild(el)
      el.select()
      const ok = document.execCommand('copy')
      document.body.removeChild(el)
      if (!ok) throw new Error('copy failed')
    }
    copiedKey.value = key
    setTimeout(() => { if (copiedKey.value === key) copiedKey.value = null }, 1500)
  } catch {
    toast.error(t('project.domains.copyFailed'))
  }
}

// ── Actions ──────────────────────────────────────────────────────────────────
async function add() {
  const value = newDomain.value.trim()
  if (!value || adding.value) return
  adding.value   = true
  addError.value = null
  try {
    const { data } = await projectsApi.addDomain(props.projectId, value)
    const domain = data.data ?? data
    upsert(domain)
    newDomain.value = ''
    toast.success(t('project.domains.added', { domain: domain.domain ?? value }))
  } catch (e) {
    const body = e.response?.data ?? {}
    addError.value = body.errors?.domain?.[0]
      ?? Object.values(body.errors ?? {}).flat()[0]
      ?? body.message
      ?? t('common.error')
  } finally {
    adding.value = false
  }
}

async function verify(d) {
  if (busy.value[d.id]) return
  setBusy(d.id, 'verify')
  setNotice(d.id, null)
  try {
    const { data } = await projectsApi.verifyDomain(props.projectId, d.id)
    if (data.data) upsert(data.data)
    toast.success(data.message ?? t('project.domains.verified'))
  } catch (e) {
    const body = e.response?.data ?? {}
    if (body.data) upsert(body.data)
    setNotice(d.id, body.message ?? t('common.error'))
  } finally {
    setBusy(d.id, null)
  }
}

async function remove(d) {
  if (busy.value[d.id]) return
  const ok = await confirm({
    title:        t('project.domains.deleteTitle'),
    message:      t('project.domains.deleteConfirm', { domain: d.domain }),
    confirmLabel: t('project.domains.delete'),
    destructive:  true,
  })
  if (!ok) return
  setBusy(d.id, 'delete')
  setNotice(d.id, null)
  try {
    await projectsApi.deleteDomain(props.projectId, d.id)
    domains.value = domains.value.filter(x => x.id !== d.id)
    toast.success(t('project.domains.deleted'))
  } catch (e) {
    setNotice(d.id, e.response?.data?.message ?? t('common.error'))
  } finally {
    setBusy(d.id, null)
  }
}

function onTlsSaved({ domain, message }) {
  if (domain) upsert(domain)
  if (tlsDomain.value) setNotice(tlsDomain.value.id, null)
  tlsDomain.value = null
  toast.success(message ?? t('project.domains.tls.saved'))
}
</script>

<template>
  <div class="space-y-4">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <span class="text-[15px] font-semibold text-gray-100">{{ t('project.domains.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5 ml-[10px]">{{ t('project.domains.intro') }}</p>
        <p v-if="meta?.default_host" class="text-xs text-gray-400 mt-1 ml-[10px]">
          {{ t('project.domains.defaultHost') }}:
          <span class="font-mono text-gray-300">{{ meta.default_host }}</span>
        </p>
      </div>
      <button
        class="btn-secondary text-xs px-3 py-1.5 shrink-0"
        :disabled="loading || refreshing"
        :title="t('project.domains.refresh')"
        @click="load({ silent: true })"
      >
        <RefreshCw class="w-3.5 h-3.5" :class="refreshing ? 'animate-spin' : ''" />
        {{ t('project.domains.refresh') }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <!-- Load error -->
    <div v-else-if="loadError && !meta" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3">
      <p class="text-xs text-red-400">{{ loadError }}</p>
    </div>

    <template v-else>
      <!-- Not eligible -->
      <div v-if="!eligible" class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-4 py-3">
        <AlertTriangle class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
        <p class="text-xs text-yellow-300 leading-relaxed">{{ meta?.reason || t('project.domains.notEligible') }}</p>
      </div>

      <!-- Add form -->
      <form v-else-if="canManage" class="max-w-xl" @submit.prevent="add">
        <div class="flex items-start gap-2">
          <div class="flex-1 min-w-0">
            <input
              v-model="newDomain"
              type="text"
              class="input font-mono"
              :class="addError ? 'border-red-700!' : ''"
              :placeholder="t('project.domains.placeholder')"
              :disabled="adding"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
              @input="addError = null"
            />
            <p v-if="addError" class="text-xs text-red-400 mt-1">{{ addError }}</p>
          </div>
          <button type="submit" class="btn-primary h-9 shrink-0" :disabled="adding || !newDomain.trim()">
            <Plus class="w-3.5 h-3.5" />
            {{ adding ? t('project.domains.adding') : t('project.domains.add') }}
          </button>
        </div>
      </form>

      <!-- Automatic certificates: what new domains get, or why the region cannot issue them -->
      <div v-if="eligible && certManagerNote" class="flex items-center justify-between gap-3 max-w-xl" data-testid="domains-cert-manager">
        <p class="flex items-start gap-1.5 text-xs text-gray-400 leading-relaxed">
          <ShieldCheck class="w-3.5 h-3.5 mt-0.5 shrink-0" :class="meta.cert_manager.available ? 'text-emerald-400' : 'text-gray-500'" />
          <span>{{ certManagerNote }}</span>
        </p>
        <button v-if="meta.cert_manager.ca_download" type="button" class="btn-secondary text-xs shrink-0" data-testid="domains-download-ca" @click="downloadCa">
          {{ t('project.domains.tls.downloadCa') }}
        </button>
      </div>

      <p v-if="loadError" class="text-xs text-red-400">{{ loadError }}</p>

      <!-- Empty -->
      <div v-if="!domains.length" class="text-center text-gray-400 py-8 text-sm">
        {{ t('project.domains.empty') }}
      </div>

      <!-- Domain list -->
      <div v-else class="space-y-3">
        <div
          v-for="d in domains" :key="d.id"
          class="bg-gray-900 border border-gray-800 rounded-md"
        >
          <!-- Row header -->
          <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 px-4 py-3">
            <Globe class="w-4 h-4 text-gray-400 shrink-0" />
            <a
              v-if="d.status === 'active' && d.url"
              :href="d.url" target="_blank" rel="noopener"
              class="inline-flex items-center gap-1 text-sm font-mono text-brand-400 hover:text-brand-300 transition-colors break-all"
            >{{ d.domain }}<ExternalLink class="w-3 h-3 shrink-0" /></a>
            <span v-else class="text-sm font-mono text-gray-100 break-all">{{ d.domain }}</span>
            <span :class="statusClass(d.status)">{{ t(`project.domains.status.${d.status}`, d.status) }}</span>

            <div v-if="canManage" class="ml-auto flex items-center gap-1.5">
              <button
                :class="d.status === 'active' ? 'btn-secondary' : 'btn-primary'"
                class="text-xs px-3 py-1.5"
                :disabled="!!busy[d.id]"
                :title="d.status === 'active' ? t('project.domains.recheckHint') : null"
                @click="verify(d)"
              >
                <ShieldCheck class="w-3.5 h-3.5" />
                {{ busy[d.id] === 'verify'
                  ? t('project.domains.verifying')
                  : (d.status === 'active' ? t('project.domains.recheck') : t('project.domains.verify')) }}
              </button>
              <button
                class="btn-secondary text-xs px-3 py-1.5"
                :disabled="!!busy[d.id]"
                @click="tlsDomain = d"
              >
                <Lock class="w-3.5 h-3.5" />{{ t('project.domains.https') }}
              </button>
              <button
                class="btn-secondary text-xs px-3 py-1.5 text-red-400 hover:text-red-300"
                :disabled="!!busy[d.id]"
                :title="t('project.domains.delete')"
                @click="remove(d)"
              >
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </div>
          </div>

          <!-- Messages -->
          <div class="px-4 pb-3 space-y-1.5 -mt-1">
            <p
              v-if="d.status_message && d.status_message !== notices[d.id]"
              class="text-xs leading-relaxed"
              :class="d.status === 'error' ? 'text-red-400' : 'text-gray-400'"
            >{{ d.status_message }}</p>
            <div v-if="notices[d.id]" class="flex items-start gap-2 bg-red-900/20 border border-red-800/40 rounded-md px-3 py-2">
              <AlertTriangle class="w-3.5 h-3.5 text-red-400 mt-0.5 shrink-0" />
              <p class="text-xs text-red-400 leading-relaxed">{{ notices[d.id] }}</p>
            </div>
            <!-- a global project: the domain in each region -->
            <div v-if="regionStates(d).length" class="flex flex-wrap gap-1.5" data-testid="domain-regions">
              <span
                v-for="r in regionStates(d)" :key="r.id"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm text-[10px] border"
                :class="r.status === 'active' && !r.message ? 'bg-emerald-900/30 border-emerald-800/50 text-emerald-400'
                  : r.status === 'error' ? 'bg-red-900/30 border-red-800/50 text-red-400' : 'bg-gray-800 border-gray-700 text-gray-400'"
                :title="r.message ?? (r.expires ? t('project.domains.tlsExpires', { date: fmtDate(r.expires) }) : '')"
                :data-domain-region="r.name"
              >
                <span class="font-mono">{{ r.name }}</span>
                <span>{{ t(`project.domains.status.${r.status}`, r.status) }}</span>
              </span>
            </div>
            <p class="flex items-start gap-1.5 text-xs text-gray-400 leading-relaxed">
              <Lock class="w-3 h-3 mt-0.5 shrink-0" />
              <span>
                {{ d.tls_note }}
                <span v-if="expiryInfo(d)" :class="expiryInfo(d).cls"> · {{ expiryInfo(d).text }}</span>
              </span>
            </p>
          </div>

          <!-- DNS records -->
          <div class="border-t border-gray-800">
            <button
              type="button"
              class="w-full flex items-center gap-1.5 px-4 py-2 text-xs font-medium text-gray-400 hover:text-brand-500 transition-colors"
              @click="toggle(d)"
            >
              <ChevronDown v-if="isExpanded(d)" class="w-3.5 h-3.5" />
              <ChevronRight v-else class="w-3.5 h-3.5" />
              {{ t('project.domains.dnsRecords') }}
            </button>

            <div v-if="isExpanded(d)" class="px-4 pb-4 space-y-4">
              <div v-for="(s, si) in dnsSections(d)" :key="s.key">
                <p class="text-[11px] uppercase tracking-wider font-medium text-gray-400">{{ si + 1 }}. {{ s.title }}</p>
                <p class="text-xs text-gray-400 mt-0.5 mb-2">{{ s.hint }}</p>

                <p v-if="!s.rows.length" class="text-xs text-yellow-400">{{ t('project.domains.noTargets') }}</p>

                <div v-else class="rounded-md border border-gray-800 bg-gray-950/40 overflow-x-auto">
                  <table class="w-full text-xs">
                    <thead>
                      <tr class="text-left text-gray-400">
                        <th class="font-medium px-3 py-1.5 w-20">{{ t('project.domains.colType') }}</th>
                        <th class="font-medium px-3 py-1.5">{{ t('project.domains.colName') }}</th>
                        <th class="font-medium px-3 py-1.5">{{ t('project.domains.colValue') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="(r, ri) in s.rows" :key="ri" class="border-t border-gray-800">
                        <td class="px-3 py-1.5 align-top font-mono text-gray-100">{{ r.type }}</td>
                        <td v-for="f in ['name', 'value']" :key="f" class="px-3 py-1.5 align-top">
                          <div class="flex items-start gap-1.5">
                            <span class="font-mono text-gray-100 break-all">{{ r[f] }}</span>
                            <button
                              type="button"
                              class="shrink-0 mt-px text-gray-400 hover:text-gray-100 transition-colors"
                              :title="t('project.domains.copy')"
                              @click="copy(r[f], `${d.id}:${s.key}:${ri}:${f}`)"
                            >
                              <Check v-if="copiedKey === `${d.id}:${s.key}:${ri}:${f}`" class="w-3.5 h-3.5 text-green-400" />
                              <Copy v-else class="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <p class="text-[11px] text-gray-400">{{ t('project.domains.zoneHint') }}</p>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- TLS modal -->
    <DomainTlsModal
      v-if="tlsDomain"
      :project-id="projectId"
      :domain="tlsDomain"
      :cert-manager-issuer="meta?.cert_manager_issuer ?? null"
      :cert-manager="meta?.cert_manager ?? null"
      @saved="onTlsSaved"
      @updated="upsert"
      @close="tlsDomain = null"
    />
  </div>
</template>
