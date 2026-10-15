<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { moduleRegistry } from '@/modules/index'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { sandboxAdminApi } from '../../api/sandboxes'
import { apiError } from '../../utils'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const LIMITS = [
  { key: 'max_concurrent',            usage: 'concurrent',            label: 'concurrent' },
  { key: 'max_cpu_m',                 usage: 'cpu_m',                 label: 'cpu' },
  { key: 'max_memory_mb',             usage: 'memory_mb',             label: 'memory' },
  { key: 'max_sandbox_seconds_month', usage: 'sandbox_seconds_month', label: 'seconds' },
  { key: 'max_pool_size',             usage: 'pool_size',             label: 'poolSize' },
]

const own       = ref(null)
const tenants   = ref([])
const forms     = ref({})
const showTable = ref(false)
const error     = ref(null)
const saving    = ref({})

// Platform admins in central context (tenancy module), every user of a single-tenant install;
// the API answers 403 to everyone else and the table stays hidden
const mayManageTenants = computed(() => moduleRegistry.isCentralAdminFn ? moduleRegistry.isCentralAdminFn() : true)

const rowKey = (row) => row.tenant?.id ?? 'default'

function toForm(row) {
  const l = row.limits ?? {}
  return {
    max_concurrent:            l.max_concurrent ?? 0,
    max_cpu_m:                 l.max_cpu_m ?? 0,
    max_memory_mb:             l.max_memory_mb ?? 0,
    max_sandbox_seconds_month: l.max_sandbox_seconds_month ?? 0,
    max_pool_size:             l.max_pool_size ?? 0,
    allowed_classes:           Array.isArray(l.allowed_classes) ? l.allowed_classes.join(', ') : '',
  }
}

async function loadOwn() {
  try {
    const { data } = await sandboxAdminApi.quota()
    own.value = data?.data ?? null
  } catch (e) {
    error.value = apiError(e, t('sandboxes.settings.quotas.failed'))
  }
}

async function loadTenants() {
  if (!mayManageTenants.value) return
  try {
    const { data } = await sandboxAdminApi.tenantQuotas()
    tenants.value   = data?.data ?? []
    forms.value     = Object.fromEntries(tenants.value.map(r => [rowKey(r), toForm(r)]))
    showTable.value = true
  } catch {
    showTable.value = false
  }
}

function payloadOf(key) {
  const f = forms.value[key]
  const classes = String(f.allowed_classes ?? '').split(',').map(s => s.trim()).filter(Boolean)
  return {
    max_concurrent:            Number(f.max_concurrent) || 0,
    max_cpu_m:                 Number(f.max_cpu_m) || 0,
    max_memory_mb:             Number(f.max_memory_mb) || 0,
    max_sandbox_seconds_month: Number(f.max_sandbox_seconds_month) || 0,
    max_pool_size:             Number(f.max_pool_size) || 0,
    allowed_classes:           classes.length ? classes : null,
  }
}

async function save(row) {
  const key = rowKey(row)
  saving.value = { ...saving.value, [key]: true }
  try {
    await sandboxAdminApi.updateQuota(key, payloadOf(key))
    toast.success(t('sandboxes.settings.quotas.saved'))
    await Promise.all([loadTenants(), loadOwn()])
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.settings.quotas.failed')))
  } finally {
    saving.value = { ...saving.value, [key]: false }
  }
}

async function reset(row) {
  const ok = await confirm({
    title:        t('sandboxes.settings.quotas.resetTitle'),
    message:      t('sandboxes.settings.quotas.resetConfirm', { tenant: row.tenant?.name ?? '' }),
    destructive:  true,
    confirmLabel: t('sandboxes.actions.reset'),
  })
  if (!ok) return
  try {
    await sandboxAdminApi.resetQuota(row.tenant.id)
    toast.success(t('sandboxes.settings.quotas.resetDone'))
    await Promise.all([loadTenants(), loadOwn()])
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.settings.quotas.failed')))
  }
}

function pct(used, limit) {
  if (!limit) return 0
  return Math.min(100, Math.round((Number(used) || 0) / limit * 100))
}

function barColor(p) {
  if (p >= 90) return '#ef4444'
  if (p >= 70) return '#f59e0b'
  return 'rgb(var(--brand-500))'
}

onMounted(() => {
  loadOwn()
  loadTenants()
})
</script>

<template>
  <section data-section="quotas">
    <h4 class="text-sm font-semibold text-gray-100">{{ t('sandboxes.settings.quotas.title') }}</h4>
    <p class="text-xs text-gray-400 mb-3">{{ t('sandboxes.settings.quotas.subtitle') }}</p>
    <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>

    <!-- Own organization -->
    <div v-if="own" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 mb-4" data-own-quota>
      <p class="text-xs font-semibold text-gray-300 mb-3">{{ t('sandboxes.settings.quotas.own') }}</p>
      <div class="grid md:grid-cols-2 gap-x-6 gap-y-3">
        <div v-for="l in LIMITS" :key="l.key" :data-quota="l.key">
          <div class="flex justify-between text-xs mb-1">
            <span class="text-gray-400">{{ t(`sandboxes.settings.quotas.${l.label}`) }}</span>
            <span class="font-mono text-gray-300">
              {{ own.usage?.[l.usage] ?? 0 }} /
              {{ own.limits?.[l.key] ? own.limits[l.key] : t('sandboxes.settings.quotas.unlimited') }}
            </span>
          </div>
          <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden">
            <div class="h-full rounded-full transition-all"
              :style="{ width: pct(own.usage?.[l.usage], own.limits?.[l.key]) + '%', background: barColor(pct(own.usage?.[l.usage], own.limits?.[l.key])) }" />
          </div>
        </div>
      </div>
    </div>

    <!-- Organizations (platform) -->
    <div v-if="showTable" class="rounded-xl border border-gray-800 bg-gray-900/60 overflow-x-auto" data-tenant-quotas>
      <div class="px-4 pt-3 pb-1 flex items-baseline gap-2">
        <p class="text-xs font-semibold text-gray-300">{{ t('sandboxes.settings.quotas.tenants') }}</p>
        <p class="text-[11px] text-gray-500">{{ t('sandboxes.settings.quotas.unlimitedHint') }}</p>
      </div>
      <table class="w-full text-xs">
        <thead>
          <tr class="text-left text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
            <th class="px-3 py-2 font-medium">{{ t('sandboxes.settings.quotas.tenant') }}</th>
            <th v-for="l in LIMITS" :key="l.key" class="px-2 py-2 font-medium"><TermText :path="`sandboxes.settings.quotas.${l.label}`" /></th>
            <th class="px-2 py-2 font-medium" :title="t('sandboxes.settings.quotas.allowedClassesHint')">{{ t('sandboxes.settings.quotas.allowedClasses') }}</th>
            <th class="px-3 py-2" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in tenants" :key="rowKey(row)" :data-quota-row="rowKey(row)" class="border-b border-gray-800/60 last:border-0">
            <td class="px-3 py-2">
              <span class="text-gray-200">{{ row.tenant ? row.tenant.name : t('sandboxes.settings.quotas.defaults') }}</span>
              <span v-if="row.tenant && row.custom" class="ml-1 px-1.5 py-0.5 rounded border border-sky-800/50 bg-sky-950/40 text-[10px] text-sky-400">{{ t('sandboxes.settings.quotas.custom') }}</span>
            </td>
            <td v-for="l in LIMITS" :key="l.key" class="px-2 py-2">
              <input v-model.number="forms[rowKey(row)][l.key]" class="input font-mono !h-8 !w-24 text-xs" type="number" min="0" :name="l.key" />
              <p class="text-[10px] font-mono text-gray-500 mt-0.5">{{ row.usage?.[l.usage] ?? 0 }}</p>
            </td>
            <td class="px-2 py-2">
              <input v-model="forms[rowKey(row)].allowed_classes" class="input font-mono !h-8 !w-36 text-xs" type="text" name="allowed_classes" :placeholder="t('sandboxes.settings.quotas.allowedClassesHint')" />
            </td>
            <td class="px-3 py-2 whitespace-nowrap text-right">
              <button class="btn-primary !px-2.5 !py-1 text-xs" :disabled="saving[rowKey(row)]" data-action="save-quota" @click="save(row)">{{ t('sandboxes.actions.save') }}</button>
              <button v-if="row.tenant && row.custom" class="btn-secondary !px-2.5 !py-1 text-xs ml-1" data-action="reset-quota" @click="reset(row)">{{ t('sandboxes.actions.reset') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
