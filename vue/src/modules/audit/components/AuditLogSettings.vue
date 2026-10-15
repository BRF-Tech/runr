<script setup>
// Settings → Audit log (docs/security.md): filters, paginated events, detail drawer, CSV/JSON
// export (audit.export) and the retention setting (platform admins).
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useAuditLog } from '../composables/useAuditLog'
import { ScrollText, Download, X, ChevronLeft, ChevronRight, Filter, RotateCcw } from 'lucide-vue-next'

const { t, locale } = useI18n()
const toast = useToast()
const {
  filters, events, meta, loading, error, selected, exporting, settings, savingSettings,
  load, apply, reset, open, close, exportAs, loadSettings, saveRetention,
} = useAuditLog()

const retentionDays = ref(null)
const targetTypes = ['project', 'deployment', 'domain', 'gateway', 'gateway_route', 'region', 'user', 'api_token',
  'session', 'tenant', 'invitation', 'auth_provider', 'private_connection', 'ticket', 'payment', 'cost_config',
  'runtime', 'role', 'group', 'sso_group_mapping', 'social_account', 'workflow_run', 'plan']

const resultClass = {
  success: 'bg-emerald-900/30 text-emerald-300 border-emerald-800/50',
  denied:  'bg-amber-900/30 text-amber-300 border-amber-800/50',
  failure: 'bg-red-900/30 text-red-300 border-red-800/50',
}

const metadataText = computed(() => {
  const m = selected.value?.metadata
  if (!m || !Object.keys(m).length) return null
  const { changes, ...rest } = m
  return Object.keys(rest).length ? JSON.stringify(rest, null, 2) : null
})
const changes = computed(() => selected.value?.metadata?.changes ?? [])

function fmtTime(iso) {
  if (!iso) return '-'
  return new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US')
}

function actorLabel(e) {
  return e.actor?.name || e.actor?.email || t(`audit.actorTypes.${e.actor?.type ?? 'anonymous'}`)
}

function targetLabel(e) {
  if (!e.target) return '-'
  return `${e.target.type}${e.target.name ? ': ' + e.target.name : (e.target.id ? ': ' + e.target.id : '')}`
}

async function doExport(format) {
  const ok = await exportAs(format)
  if (!ok) toast.error(t('audit.exportError'))
}

async function doSaveRetention() {
  if (await saveRetention(retentionDays.value)) toast.success(t('audit.retention.saved'))
  else toast.error(t('common.error'))
}

onMounted(async () => {
  await Promise.all([load(1), loadSettings()])
  retentionDays.value = settings.value?.retention_days ?? 365
})
</script>

<template>
  <div data-testid="audit-log">
    <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <h3 class="text-base font-semibold text-gray-100 flex items-center gap-2">
          <ScrollText class="w-4 h-4" />{{ t('audit.title') }}
        </h3>
      </div>
      <div v-if="meta.can_export" class="flex items-center gap-2">
        <span class="text-xs text-gray-400">{{ exporting ? t('audit.exporting') : t('audit.export') }}</span>
        <button class="btn-secondary text-xs flex items-center gap-1" :disabled="exporting" data-testid="audit-export-csv" @click="doExport('csv')">
          <Download class="w-3.5 h-3.5" />{{ t('audit.exportCsv') }}
        </button>
        <button class="btn-secondary text-xs flex items-center gap-1" :disabled="exporting" data-testid="audit-export-json" @click="doExport('json')">
          <Download class="w-3.5 h-3.5" />{{ t('audit.exportJson') }}
        </button>
      </div>
    </div>
    <p class="text-xs text-gray-400 mb-5">{{ t('audit.subtitle') }}</p>

    <!-- Filters -->
    <form class="card mb-4 grid grid-cols-1 md:grid-cols-4 gap-3" data-testid="audit-filters" @submit.prevent="apply">
      <label class="block">
        <span class="label">{{ t('audit.filters.actor') }}</span>
        <input v-model="filters.actor" class="input text-xs" :placeholder="t('audit.filters.actorPlaceholder')" data-testid="audit-filter-actor" />
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.action') }}</span>
        <select v-model="filters.action" class="input text-xs" data-testid="audit-filter-action">
          <option value="">{{ t('audit.filters.anyAction') }}</option>
          <option v-for="a in meta.actions" :key="a" :value="a">{{ a }}</option>
        </select>
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.targetType') }}</span>
        <select v-model="filters.target_type" class="input text-xs" data-testid="audit-filter-target-type">
          <option value="">{{ t('audit.filters.anyTargetType') }}</option>
          <option v-for="tt in targetTypes" :key="tt" :value="tt">{{ tt }}</option>
        </select>
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.target') }}</span>
        <input v-model="filters.target" class="input text-xs" :placeholder="t('audit.filters.targetPlaceholder')" data-testid="audit-filter-target" />
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.result') }}</span>
        <select v-model="filters.result" class="input text-xs" data-testid="audit-filter-result">
          <option value="">{{ t('audit.filters.anyResult') }}</option>
          <option v-for="r in ['success', 'denied', 'failure']" :key="r" :value="r">{{ t(`audit.results.${r}`) }}</option>
        </select>
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.from') }}</span>
        <input v-model="filters.from" type="datetime-local" class="input text-xs" data-testid="audit-filter-from" />
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.to') }}</span>
        <input v-model="filters.to" type="datetime-local" class="input text-xs" data-testid="audit-filter-to" />
      </label>
      <label class="block">
        <span class="label">{{ t('audit.filters.search') }}</span>
        <input v-model="filters.q" class="input text-xs" :placeholder="t('audit.filters.searchPlaceholder')" data-testid="audit-filter-q" />
      </label>
      <div class="md:col-span-4 flex justify-end gap-2">
        <button type="button" class="btn-secondary text-xs flex items-center gap-1" data-testid="audit-filter-reset" @click="reset">
          <RotateCcw class="w-3.5 h-3.5" />{{ t('audit.filters.reset') }}
        </button>
        <button type="submit" class="btn-primary text-xs flex items-center gap-1" data-testid="audit-filter-apply">
          <Filter class="w-3.5 h-3.5" />{{ t('audit.filters.apply') }}
        </button>
      </div>
    </form>

    <!-- Events -->
    <div class="card p-0 overflow-x-auto">
      <p v-if="loading" class="py-10 text-center text-sm text-gray-400">{{ t('common.loading') }}</p>
      <p v-else-if="error" class="py-10 text-center text-sm text-red-400" data-testid="audit-error">{{ t('audit.loadError') }}</p>
      <p v-else-if="!events.length" class="py-10 text-center text-sm text-gray-400" data-testid="audit-empty">{{ t('audit.empty') }}</p>
      <table v-else class="w-full text-xs">
        <thead>
          <tr class="text-left text-gray-400 border-b border-gray-800">
            <th class="px-3 py-2 font-medium">{{ t('audit.columns.time') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('audit.columns.actor') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('audit.columns.action') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('audit.columns.target') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('audit.columns.result') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('audit.columns.ip') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="e in events" :key="e.id"
            class="border-b border-gray-800/60 hover:bg-gray-800/40 cursor-pointer"
            data-testid="audit-row" @click="open(e)"
          >
            <td class="px-3 py-2 text-gray-400 whitespace-nowrap">{{ fmtTime(e.occurred_at) }}</td>
            <td class="px-3 py-2 text-gray-100">
              {{ actorLabel(e) }}
              <span v-if="e.actor?.impersonator" class="block text-[11px] text-amber-300">{{ t('audit.impersonating') }}</span>
            </td>
            <td class="px-3 py-2 font-mono text-gray-100">{{ e.action }}</td>
            <td class="px-3 py-2 text-gray-300 truncate max-w-[16rem]">{{ targetLabel(e) }}</td>
            <td class="px-3 py-2">
              <span class="px-2 py-0.5 rounded border text-[11px]" :class="resultClass[e.result]">{{ t(`audit.results.${e.result}`) }}</span>
            </td>
            <td class="px-3 py-2 font-mono text-gray-400">{{ e.ip_address || '-' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="meta.last_page > 1 || meta.total" class="flex items-center justify-between mt-3 text-xs text-gray-400">
      <span>{{ t('audit.total', { total: meta.total }) }}</span>
      <div class="flex items-center gap-2">
        <button class="btn-secondary text-xs flex items-center gap-1" :disabled="meta.current_page <= 1 || loading" data-testid="audit-prev" @click="load(meta.current_page - 1)">
          <ChevronLeft class="w-3.5 h-3.5" />{{ t('audit.previous') }}
        </button>
        <span>{{ t('audit.page', { page: meta.current_page, last: meta.last_page }) }}</span>
        <button class="btn-secondary text-xs flex items-center gap-1" :disabled="meta.current_page >= meta.last_page || loading" data-testid="audit-next" @click="load(meta.current_page + 1)">
          {{ t('audit.next') }}<ChevronRight class="w-3.5 h-3.5" />
        </button>
      </div>
    </div>

    <!-- Retention -->
    <div v-if="settings" class="card mt-6" data-testid="audit-retention">
      <p class="text-sm font-semibold text-gray-100 mb-2">{{ t('audit.retention.title') }}</p>
      <p class="text-xs text-gray-400 mb-3">
        {{ settings.retention_days > 0 ? t('audit.retention.current', { days: settings.retention_days }) : t('audit.retention.currentForever') }}
        {{ settings.forward_enabled ? t('audit.retention.siemOn') : t('audit.retention.siemOff') }}
      </p>
      <div v-if="settings.can_update" class="flex items-end gap-2">
        <label class="block">
          <span class="label">{{ t('audit.retention.days') }}</span>
          <input v-model.number="retentionDays" type="number" min="0" :max="settings.max_days" class="input text-xs w-32" data-testid="audit-retention-days" />
        </label>
        <span class="text-[11px] text-gray-500 pb-2">{{ t('audit.retention.forever') }}</span>
        <button class="btn-primary text-xs" :disabled="savingSettings" data-testid="audit-retention-save" @click="doSaveRetention">
          {{ t('audit.retention.save') }}
        </button>
      </div>
    </div>

    <!-- Detail drawer -->
    <div v-if="selected" class="fixed inset-0 z-50 flex justify-end" data-testid="audit-drawer">
      <div class="absolute inset-0 bg-black/60" @click="close" />
      <aside class="relative w-full max-w-lg h-full overflow-y-auto bg-gray-900 border-l border-gray-800 p-5">
        <div class="flex items-center justify-between mb-4">
          <h4 class="text-sm font-semibold text-gray-100">{{ t('audit.detail.title') }}</h4>
          <button class="p-1 text-gray-400 hover:text-gray-100" :title="t('audit.detail.close')" data-testid="audit-drawer-close" @click="close">
            <X class="w-4 h-4" />
          </button>
        </div>
        <div class="grid grid-cols-3 gap-x-3 gap-y-2 text-xs">
          <div class="text-gray-400">{{ t('audit.detail.occurredAt') }}</div><div class="col-span-2 text-gray-100">{{ fmtTime(selected.occurred_at) }}</div>
          <div class="text-gray-400">{{ t('audit.detail.action') }}</div><div class="col-span-2 font-mono text-gray-100">{{ selected.action }}</div>
          <div class="text-gray-400">{{ t('audit.detail.result') }}</div><div class="col-span-2">{{ t(`audit.results.${selected.result}`) }}</div>
          <div class="text-gray-400">{{ t('audit.detail.actor') }}</div>
          <div class="col-span-2 text-gray-100">
            {{ actorLabel(selected) }}
            <span v-if="selected.actor?.email" class="text-gray-400"> &lt;{{ selected.actor.email }}&gt;</span>
            <span class="block text-gray-500">{{ t(`audit.actorTypes.${selected.actor?.type ?? 'anonymous'}`) }}<template v-if="selected.actor?.id"> · {{ selected.actor.id }}</template></span>
          </div>
          <template v-if="selected.actor?.auth_method">
            <div class="text-gray-400">{{ t('audit.detail.authMethod') }}</div><div class="col-span-2 font-mono text-gray-300">{{ selected.actor.auth_method }}</div>
          </template>
          <template v-if="selected.actor?.impersonator">
            <div class="text-gray-400">{{ t('audit.detail.impersonator') }}</div><div class="col-span-2 text-amber-300">{{ selected.actor.impersonator.name }} ({{ selected.actor.impersonator.id }})</div>
          </template>
          <template v-if="selected.tenant_id">
            <div class="text-gray-400">{{ t('audit.detail.tenant') }}</div><div class="col-span-2 font-mono text-gray-300">{{ selected.tenant_id }}</div>
          </template>
          <div class="text-gray-400">{{ t('audit.detail.target') }}</div>
          <div class="col-span-2 text-gray-100">{{ targetLabel(selected) }}<span v-if="selected.target?.id" class="block font-mono text-gray-500">{{ selected.target.id }}</span></div>
          <div class="text-gray-400">{{ t('audit.detail.ip') }}</div><div class="col-span-2 font-mono text-gray-300">{{ selected.ip_address || '-' }}</div>
          <div class="text-gray-400">{{ t('audit.detail.userAgent') }}</div><div class="col-span-2 text-gray-300 break-all">{{ selected.user_agent || '-' }}</div>
          <div class="text-gray-400">{{ t('audit.detail.request') }}</div><div class="col-span-2 font-mono text-gray-300 break-all">{{ selected.http_method || '' }} {{ selected.route || '-' }}</div>
          <template v-if="selected.status_code">
            <div class="text-gray-400">{{ t('audit.detail.status') }}</div><div class="col-span-2 font-mono text-gray-300">{{ selected.status_code }}</div>
          </template>
          <div class="text-gray-400">{{ t('audit.detail.requestId') }}</div><div class="col-span-2 font-mono text-gray-300 break-all">{{ selected.request_id || '-' }}</div>
        </div>

        <div v-if="changes.length" class="mt-5">
          <p class="text-xs font-semibold text-gray-100 mb-2">{{ t('audit.detail.changes') }}</p>
          <ul class="space-y-2">
            <li v-for="(c, i) in changes" :key="i" class="rounded border border-gray-800 bg-gray-950/60 p-2 text-[11px]" data-testid="audit-change">
              <p class="font-mono text-gray-100">{{ c.model }} {{ c.name || c.id }} · {{ c.event }}</p>
              <p v-if="c.fields?.length" class="text-gray-400">{{ c.fields.join(', ') }}</p>
              <p v-if="c.env_vars" class="text-gray-400 font-mono">env: {{ JSON.stringify(c.env_vars) }}</p>
              <pre v-if="c.before || c.after" class="mt-1 text-gray-300 whitespace-pre-wrap break-all">{{ JSON.stringify({ before: c.before, after: c.after }, null, 2) }}</pre>
            </li>
          </ul>
        </div>

        <div class="mt-5">
          <p class="text-xs font-semibold text-gray-100 mb-2">{{ t('audit.detail.metadata') }}</p>
          <pre v-if="metadataText" class="rounded border border-gray-800 bg-gray-950/60 p-2 text-[11px] text-gray-300 whitespace-pre-wrap break-all" data-testid="audit-metadata">{{ metadataText }}</pre>
          <p v-else class="text-xs text-gray-500">{{ t('audit.detail.noMetadata') }}</p>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.label {
  @apply block text-[11px] text-gray-400 mb-1;
}
</style>
