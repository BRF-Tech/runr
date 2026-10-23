<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { globalDnsApi } from '@/api/globalProjects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage, geoipStatusColors, serverStatusColors, stateTextClass, unwrap } from '@/utils/globalProjects'
import { Earth, Copy, Check, RefreshCw } from 'lucide-vue-next'

// Administration → Regions & settings (Platform): the global DNS zone of global projects (docs/global-projects.md). The
// operator delegates it once at the parent zone — NS records naming the regions' servers and
// their glue address records — and RunR keeps the zone identical on every server.
const { t } = useI18n()
const toast = useToast()
const perms = usePermissionsStore()
const canManage = computed(() => perms.can('regions.manage'))

const overview = ref(null)
const loading  = ref(true)
const syncing  = ref(false)
const error    = ref(null)
const copiedKey = ref(null)

const records = computed(() => overview.value?.delegation?.records ?? [])
const servers = computed(() => overview.value?.servers ?? [])
const delegationStatus = computed(() => overview.value?.delegation?.status ?? 'unknown')

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await globalDnsApi.overview()
    overview.value = unwrap(data)
  } catch (e) {
    error.value = apiErrorMessage(e, t('globalDns.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function sync() {
  if (syncing.value) return
  syncing.value = true
  error.value = null
  try {
    await globalDnsApi.sync()
    toast.success(t('globalDns.platform.syncQueued'))
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    syncing.value = false
  }
}

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(String(text))
    copiedKey.value = key
    setTimeout(() => { if (copiedKey.value === key) copiedKey.value = null }, 1500)
  } catch {
    toast.error(t('project.domains.copyFailed'))
  }
}

function fmtDate(iso) {
  if (!iso) return '—'
  try { return new Date(iso).toLocaleString() } catch { return iso }
}

function reachableText(v) {
  if (v === true) return t('globalDns.reachable.yes')
  if (v === false) return t('globalDns.reachable.no')
  return t('globalDns.reachable.unknown')
}
</script>

<template>
  <div class="card" data-testid="global-dns-panel">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Earth class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('globalDns.platform.title') }}</h3>
      <div class="ml-auto flex items-center gap-2">
        <button v-if="canManage && overview?.zone" class="btn-secondary text-xs px-3 py-1.5" :disabled="syncing" data-testid="global-dns-sync" @click="sync">
          <RefreshCw class="w-3.5 h-3.5" :class="syncing ? 'animate-spin' : ''" />{{ t('globalDns.platform.sync') }}
        </button>
        <button class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load">{{ t('project.domains.refresh') }}</button>
      </div>
    </div>

    <div v-if="loading" class="py-6 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>
    <p v-else-if="error && !overview" class="text-xs text-red-400">{{ error }}</p>

    <div v-else-if="overview" class="space-y-4">
      <p class="text-xs text-gray-400">{{ t('globalDns.platform.description') }}</p>

      <p v-if="!overview.zone" class="text-xs text-yellow-400 bg-yellow-900/10 border border-yellow-800/40 rounded-md px-3 py-2" data-testid="global-dns-no-zone">
        {{ t('globalDns.platform.noZone') }}
      </p>

      <template v-else>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
          <span class="text-gray-400">{{ t('globalDns.platform.zone') }}: <span class="font-mono text-gray-100" data-testid="global-dns-zone">{{ overview.zone }}</span></span>
          <span class="text-gray-400">{{ t('globalDns.platform.tlsMode') }}: <span class="text-gray-100">{{ t(`globalDns.platform.tlsModes.${overview.tls ?? 'none'}`, overview.tls ?? 'none') }}</span></span>
          <span class="text-gray-400">{{ t('globalDns.platform.lastSync') }}: <span class="text-gray-100">{{ fmtDate(overview.last_sync_at) }}</span></span>
        </div>

        <!-- Delegation at the parent zone -->
        <div>
          <div class="flex items-center gap-2 mb-1">
            <p class="text-[11px] uppercase tracking-wider font-medium text-gray-400">{{ t('globalDns.platform.delegation') }}</p>
            <span class="text-[11px] font-medium" :class="stateTextClass(delegationStatus)" data-testid="global-dns-delegation">{{ t(`globalDns.delegation.${delegationStatus}`, delegationStatus) }}</span>
          </div>
          <p class="text-xs text-gray-400 mb-2">{{ t('globalDns.platform.delegationHint') }}</p>
          <p v-if="overview.delegation?.message" class="text-[11px] text-gray-400 mb-2">{{ overview.delegation.message }}</p>
          <p v-if="!records.length" class="text-xs text-yellow-400">{{ t('globalDns.platform.noServers') }}</p>
          <div v-else class="rounded-md border border-gray-800 bg-gray-950/40 overflow-x-auto">
            <table class="w-full text-xs">
              <thead>
                <tr class="text-left text-gray-400">
                  <th class="font-medium px-3 py-1.5">{{ t('project.domains.colName') }}</th>
                  <th class="font-medium px-3 py-1.5 w-16">{{ t('project.domains.colType') }}</th>
                  <th class="font-medium px-3 py-1.5">{{ t('project.domains.colValue') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in records" :key="i" class="border-t border-gray-800" data-testid="global-dns-record">
                  <td v-for="f in ['name', 'type', 'value']" :key="f" class="px-3 py-1.5 align-top">
                    <div class="flex items-start gap-1.5">
                      <span class="font-mono text-gray-100 break-all">{{ r[f] }}</span>
                      <button
                        v-if="f !== 'type'"
                        type="button"
                        class="shrink-0 mt-px text-gray-400 hover:text-gray-100 transition-colors"
                        :title="t('project.domains.copy')"
                        @click="copy(r[f], `${i}:${f}`)"
                      >
                        <Check v-if="copiedKey === `${i}:${f}`" class="w-3.5 h-3.5 text-green-400" />
                        <Copy v-else class="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- Servers -->
      <div>
        <p class="text-[11px] uppercase tracking-wider font-medium text-gray-400 mb-2">{{ t('globalDns.platform.servers') }}</p>
        <p v-if="!servers.length" class="text-xs text-gray-400">{{ t('globalDns.platform.serversEmpty') }}</p>
        <div v-else class="rounded-md border border-gray-800 bg-gray-950/40 overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr class="text-left text-gray-400">
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.region') }}</th>
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.status') }}</th>
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.address') }}</th>
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.reachable') }}</th>
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.sync') }}</th>
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.tls') }}</th>
                <th class="font-medium px-3 py-1.5">{{ t('globalDns.field.geoip') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in servers" :key="s.region_id" class="border-t border-gray-800 align-top" :data-server="s.region_name">
                <td class="px-3 py-1.5 font-mono text-gray-100">
                  {{ s.region_name }}
                  <span v-if="s.region_id === overview.update_region_id" class="ml-1 text-[10px] text-sky-400" :title="t('globalDns.platform.updateServerHint')">{{ t('globalDns.platform.updateServer') }}</span>
                </td>
                <td class="px-3 py-1.5">
                  <span class="inline-flex items-center gap-1.5">
                    <span :class="serverStatusColors(s.status).dot" class="w-2 h-2 rounded-full shrink-0" />
                    <span :class="serverStatusColors(s.status).text">{{ t(`globalDns.status.${s.status}`, s.status) }}</span>
                  </span>
                  <p v-if="s.status_message" class="text-[11px] text-gray-400 mt-0.5">{{ s.status_message }}</p>
                </td>
                <td class="px-3 py-1.5 font-mono text-gray-100">{{ s.address ?? '—' }}</td>
                <td class="px-3 py-1.5" :class="s.reachable === true ? 'text-emerald-400' : s.reachable === false ? 'text-red-400' : 'text-gray-400'">{{ reachableText(s.reachable) }}</td>
                <td class="px-3 py-1.5" :class="stateTextClass(s.sync_status)">
                  {{ s.sync_status ? t(`globalDns.sync.${s.sync_status}`, s.sync_status) : '—' }}
                  <p v-if="s.sync_message" class="text-[11px] text-gray-400 mt-0.5">{{ s.sync_message }}</p>
                </td>
                <td class="px-3 py-1.5" :class="stateTextClass(s.tls_status)">{{ s.tls_status ? t(`globalDns.tls.${s.tls_status}`, s.tls_status) : '—' }}</td>
                <td class="px-3 py-1.5" data-testid="global-dns-server-geoip">
                  <template v-if="s.geoip">
                    <span class="inline-flex items-center gap-1.5">
                      <span :class="geoipStatusColors(s.geoip.status).dot" class="w-2 h-2 rounded-full shrink-0" />
                      <span :class="geoipStatusColors(s.geoip.status).text">{{ t(`globalDns.geoip.status.${s.geoip.status ?? 'unknown'}`, s.geoip.status ?? 'unknown') }}</span>
                    </span>
                    <p class="text-[11px] text-gray-400 mt-0.5">{{ t(`globalDns.geoip.source.${s.geoip.source ?? 'default'}`, s.geoip.source ?? 'default') }}</p>
                    <p v-if="s.geoip.message" class="text-[11px] text-gray-400 mt-0.5">{{ s.geoip.message }}</p>
                  </template>
                  <span v-else class="text-gray-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
    </div>
  </div>
</template>
