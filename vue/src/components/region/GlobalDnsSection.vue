<script setup>
import { ref, computed, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { globalDnsApi } from '@/api/globalProjects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import {
  GEOIP_COPY_COMMAND, GEOIP_SOURCES, apiErrorMessage, geoipStatusColors, serverBusy, serverStatusColors, stateTextClass, unwrap,
} from '@/utils/globalProjects'

// Global DNS server of a region (docs/global-projects.md): a PowerDNS that RunR installs in the
// region, reached on public port 53 through a LoadBalancer. A region takes part in global
// projects only while it is ready; enabling and disabling need regions.manage. Its GeoIP database
// (proximity mode) comes from the platform URL, a URL of the operator's — a mirror the cluster
// reaches, a file in the region's object store — or a file copied onto the server (a cluster
// without internet access); the status says whether the server has it.
const props = defineProps({
  region: { type: Object, required: true },
})

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const canManage = computed(() => perms.can('regions.manage'))

const server    = ref(null)
const loading   = ref(true)
const busy      = ref(false)
const error     = ref(null)

const geoip = computed(() => server.value?.geoip ?? null)
const geoipStatus = computed(() => geoip.value?.status ?? 'unknown')
const geoipColors = computed(() => geoipStatusColors(geoipStatus.value))
const geoipForm = ref({ source: 'default', url: '' })
const geoipBusy = ref(null)   // 'save' | 'reload'
const geoipDirty = computed(() => !!geoip.value && (geoipForm.value.source !== (geoip.value.source ?? 'default')
  || (geoipForm.value.source === 'url' && geoipForm.value.url.trim() !== (geoip.value.url ?? ''))))
const geoipValid = computed(() => geoipForm.value.source !== 'url' || /^https?:\/\//i.test(geoipForm.value.url.trim()))
// {yyyy} / {mm} stay as they are in the texts: the server fills them in when it downloads
const literal = { yyyy: '{yyyy}', mm: '{mm}' }

watch(geoip, (g) => {
  if (!g) return
  geoipForm.value = { source: g.source ?? 'default', url: g.url ?? '' }
}, { immediate: true })

async function saveGeoip() {
  if (geoipBusy.value || !geoipValid.value) return
  geoipBusy.value = 'save'
  error.value = null
  try {
    const payload = { source: geoipForm.value.source }
    if (payload.source === 'url') payload.url = geoipForm.value.url.trim()
    const { data } = await globalDnsApi.setGeoip(props.region.id, payload)
    server.value = { ...server.value, ...unwrap(data) }
    toast.success(t('globalDns.geoip.saved'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    geoipBusy.value = null
  }
}

async function reloadGeoip() {
  if (geoipBusy.value) return
  geoipBusy.value = 'reload'
  error.value = null
  try {
    const { data } = await globalDnsApi.reloadGeoip(props.region.id)
    server.value = { ...server.value, ...unwrap(data) }
    toast.success(t('globalDns.geoip.reloaded'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    geoipBusy.value = null
  }
}

const status   = computed(() => server.value?.status ?? 'disabled')
const colors   = computed(() => serverStatusColors(status.value))
const eligible = computed(() => server.value?.eligible !== false)
const enabled  = computed(() => status.value !== 'disabled')

let timer = null
function schedule() {
  clearTimeout(timer)
  timer = serverBusy(server.value) ? setTimeout(() => load(true), 5000) : null
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const { data } = await globalDnsApi.region(props.region.id)
    server.value = unwrap(data)
    if (!silent) error.value = null
  } catch (e) {
    error.value = apiErrorMessage(e, t('globalDns.loadFailed'))
  } finally {
    loading.value = false
    schedule()
  }
}

watch(() => props.region?.id, (id) => { if (id) load() }, { immediate: true })
onUnmounted(() => clearTimeout(timer))

async function enable() {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    const { data } = await globalDnsApi.enable(props.region.id)
    server.value = { ...server.value, ...unwrap(data) }
    toast.success(t('globalDns.region.enabling'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
    schedule()
  }
}

async function disable() {
  if (busy.value) return
  const ok = await confirm({
    title:        t('globalDns.region.disableTitle'),
    message:      t('globalDns.region.disableConfirm', { region: props.region.name }),
    confirmLabel: t('globalDns.region.disable'),
    destructive:  true,
  })
  if (!ok) return
  busy.value = true
  error.value = null
  try {
    const { data } = await globalDnsApi.disable(props.region.id)
    server.value = { ...server.value, ...unwrap(data) }
    toast.success(t('globalDns.region.disabling'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
    schedule()
  }
}

function fmtDate(iso) {
  if (!iso) return null
  try { return new Date(iso).toLocaleString() } catch { return iso }
}

const reachableLabel = computed(() => {
  const r = server.value?.reachable
  if (r === true) return t('globalDns.reachable.yes')
  if (r === false) return t('globalDns.reachable.no')
  return t('globalDns.reachable.unknown')
})
</script>

<template>
  <div class="mt-6 bg-gray-900/60 border border-gray-800 rounded-xl p-4 space-y-3" data-testid="global-dns-section">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h4 class="text-sm font-medium text-gray-100">{{ t('globalDns.region.title') }}</h4>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('globalDns.region.description') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <span :class="colors.dot" class="w-2 h-2 rounded-full shrink-0" />
        <span :class="colors.text" class="text-xs font-medium" data-testid="global-dns-status">{{ t(`globalDns.status.${status}`, status) }}</span>
      </div>
    </div>

    <div v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</div>

    <template v-else>
      <p v-if="server?.status_message" class="text-xs bg-gray-900 rounded-sm px-3 py-2 font-mono whitespace-pre-wrap wrap-break-word"
        :class="status === 'error' ? 'text-red-400/90' : 'text-gray-400'">{{ server.status_message }}</p>

      <!-- Not eligible: the reason (a tenant's own region, no public :53, Serving not ready, …) -->
      <p v-if="!eligible && !enabled" class="text-xs text-yellow-400 bg-yellow-900/10 border border-yellow-800/40 rounded-md px-3 py-2" data-testid="global-dns-reason">
        {{ server?.reason || t('globalDns.region.notEligible') }}
      </p>

      <dl v-if="enabled" class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
        <dt class="text-gray-400">{{ t('globalDns.field.address') }}</dt>
        <dd class="font-mono text-gray-100 select-all" data-testid="global-dns-address">{{ server?.address ?? '—' }}</dd>
        <dt class="text-gray-400">{{ t('globalDns.field.nsName') }}</dt>
        <dd class="font-mono text-gray-100 select-all break-all">{{ server?.ns_name ?? '—' }}</dd>
        <dt class="text-gray-400">{{ t('globalDns.field.reachable') }}</dt>
        <dd :class="server?.reachable === true ? 'text-emerald-400' : server?.reachable === false ? 'text-red-400' : 'text-gray-400'">{{ reachableLabel }}</dd>
        <dt class="text-gray-400">{{ t('globalDns.field.sync') }}</dt>
        <dd :class="stateTextClass(server?.sync_status)">
          {{ server?.sync_status ? t(`globalDns.sync.${server.sync_status}`, server.sync_status) : '—' }}
          <span v-if="server?.synced_at" class="text-gray-400"> · {{ fmtDate(server.synced_at) }}</span>
        </dd>
        <template v-if="server?.sync_message">
          <dt class="sr-only">{{ t('globalDns.field.sync') }}</dt>
          <dd class="col-span-2 text-[11px] text-gray-400">{{ server.sync_message }}</dd>
        </template>
        <dt class="text-gray-400">{{ t('globalDns.field.tls') }}</dt>
        <dd :class="stateTextClass(server?.tls_status)">
          {{ server?.tls_status ? t(`globalDns.tls.${server.tls_status}`, server.tls_status) : '—' }}
          <span v-if="server?.tls_expires_at" class="text-gray-400"> · {{ t('globalDns.tlsExpires', { date: fmtDate(server.tls_expires_at) }) }}</span>
        </dd>
        <template v-if="server?.tls_message">
          <dt class="sr-only">{{ t('globalDns.field.tls') }}</dt>
          <dd class="col-span-2 text-[11px] text-gray-400">{{ server.tls_message }}</dd>
        </template>
      </dl>

      <!-- GeoIP database of proximity mode -->
      <div v-if="enabled && geoip" class="border-t border-gray-800 pt-3 space-y-2" data-testid="global-dns-geoip">
        <div class="flex items-center justify-between gap-3">
          <p class="text-xs font-medium text-gray-100">{{ t('globalDns.geoip.title') }}</p>
          <span class="inline-flex items-center gap-1.5">
            <span :class="geoipColors.dot" class="w-2 h-2 rounded-full shrink-0" />
            <span :class="geoipColors.text" class="text-xs font-medium" data-testid="global-dns-geoip-status">{{ t(`globalDns.geoip.status.${geoipStatus}`, geoipStatus) }}</span>
          </span>
        </div>
        <p v-if="geoip.message" class="text-[11px] leading-relaxed" :class="geoipStatus === 'missing' ? 'text-red-400' : 'text-gray-400'" data-testid="global-dns-geoip-message">{{ geoip.message }}</p>
        <p v-if="geoip.effective_url" class="text-[11px] text-gray-400 font-mono break-all">{{ geoip.effective_url }}</p>
        <p v-if="geoip.checked_at" class="text-[11px] text-gray-400">{{ t('globalDns.geoip.checkedAt', { time: fmtDate(geoip.checked_at) }) }}</p>

        <div class="space-y-2">
          <label class="label">{{ t('globalDns.geoip.sourceLabel') }}</label>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="src in GEOIP_SOURCES" :key="src"
              type="button"
              class="text-[11px] px-3 py-1 rounded-md border transition-colors"
              :class="geoipForm.source === src ? 'border-sky-500/60 bg-sky-900/30 text-sky-300' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
              :disabled="!canManage || !!geoipBusy"
              :aria-pressed="geoipForm.source === src ? 'true' : 'false'"
              :data-geoip-source="src"
              @click="geoipForm.source = src"
            >{{ t(`globalDns.geoip.source.${src}`) }}</button>
          </div>
          <p class="text-[11px] text-gray-400 leading-relaxed">{{ t(`globalDns.geoip.sourceHint.${geoipForm.source}`, literal) }}</p>
          <input
            v-if="geoipForm.source === 'url'"
            v-model="geoipForm.url"
            class="input font-mono text-xs"
            :placeholder="t('globalDns.geoip.urlPlaceholder', literal)"
            :disabled="!canManage || !!geoipBusy"
            data-testid="global-dns-geoip-url"
          />
          <div v-if="geoipForm.source === 'file'" class="space-y-1" data-testid="global-dns-geoip-file">
            <p class="text-[11px] text-gray-400">{{ t('globalDns.geoip.copyCommand') }}</p>
            <code class="block text-[11px] font-mono text-gray-100 bg-gray-950 border border-gray-800 rounded-sm px-2 py-1.5 select-all break-all">{{ GEOIP_COPY_COMMAND }}</code>
          </div>
          <div v-if="canManage" class="flex justify-end gap-2">
            <button class="btn-secondary text-xs" :disabled="!!geoipBusy || status !== 'ready'" data-testid="global-dns-geoip-reload" @click="reloadGeoip">
              {{ t('globalDns.geoip.reload') }}
            </button>
            <button class="btn-primary text-xs" :disabled="!!geoipBusy || !geoipDirty || !geoipValid" data-testid="global-dns-geoip-save" @click="saveGeoip">
              {{ t('globalDns.geoip.save') }}
            </button>
          </div>
        </div>
      </div>

      <p v-if="error" class="text-xs text-red-400" data-testid="global-dns-error">{{ error }}</p>

      <div v-if="canManage" class="flex justify-end gap-2 pt-1">
        <button
          v-if="!enabled || status === 'error'"
          class="btn-primary text-xs"
          :disabled="busy || !eligible || serverBusy(server)"
          data-testid="global-dns-enable"
          @click="enable"
        >{{ enabled ? t('globalDns.region.retry') : t('globalDns.region.enable') }}</button>
        <button
          v-if="enabled"
          class="btn-secondary text-xs text-red-400 hover:text-red-300"
          :disabled="busy || status === 'uninstalling'"
          data-testid="global-dns-disable"
          @click="disable"
        >{{ t('globalDns.region.disable') }}</button>
      </div>
      <p class="text-[11px] text-gray-400">{{ t('globalDns.region.hint') }}</p>
    </template>
  </div>
</template>
