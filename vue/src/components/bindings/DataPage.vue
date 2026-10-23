<script setup>
// Data & Integrations → Data (docs/bindings.md): every binding of the organization with its region,
// status, attached projects and measured usage; create, rotate, delete with retention
// (typed name), restore before the purge date; the organization's binding limits.
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { bindingsApi } from '@/api/bindings'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import BindingSettingsFields from '@/components/bindings/BindingSettingsFields.vue'
import {
  BINDING_TYPES, bindingStatusClass, bindingPayload, defaultSettings, isValidName, isBusy,
  fmtBytes, limitBytes, usagePercent,
} from '@/utils/bindings'
import { Database, HardDrive, Boxes, KeyRound, Plus, RefreshCw, Trash2, Undo2, Eye, Copy } from 'lucide-vue-next'
import { useLatest } from '@/composables/useLatest'

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const settings = useSettingsStore()
const perms = usePermissionsStore()

const canManage = computed(() => perms.can('bindings.manage'))
const canReveal = computed(() => perms.can('bindings.view_credentials'))

const TYPE_ICONS = { kv: KeyRound, object: HardDrive, postgres: Database, external: Boxes }
const QUOTA_KEYS = ['max_bindings', 'kv_mb', 'object_gb', 'postgres_gb', 'dedicated_postgres']

const bindings = ref([])
const quota = ref(null)
const types = ref(null)
const retentionDays = ref(7)
const loading = ref(false)
const filterType = ref('')
const showCreate = ref(false)
const saving = ref(false)
const errors = ref({})
const revealed = reactive({})

// Delete dialog: the binding name must be typed
const deleting = ref(null)
const deleteName = ref('')
const deleteBusy = ref(false)

const emptyForm = () => ({
  name: '', type: 'kv', plan: 'shared', knative_region_id: '',
  settings: defaultSettings('kv', 'shared', types.value),
  values: [{ key: '', value: '' }],
})
const form = reactive(emptyForm())

const regions = computed(() => (settings.data.regions ?? []).filter(r => r.has_kubeconfig !== false && !r.is_demo))
const filtered = computed(() => filterType.value ? bindings.value.filter(b => b.type === filterType.value) : bindings.value)
const canCreate = computed(() => !saving.value && isValidName(form.name) && (form.type === 'external' || !!form.knative_region_id))

let pollTimer = null
// The newest list wins (a poll sent before an action's reload must not drop the new row), and a
// load that ends after the page is gone arms no more polls
const latest = useLatest()
// Bindings with a restore request in flight: a second click would answer "not being deleted"
const restoring = ref(new Set())

async function load(silent = false) {
  const current = latest.begin()
  if (!silent) loading.value = true
  try {
    const [{ data: list }, { data: q }] = await Promise.all([bindingsApi.list(), bindingsApi.quota()])
    if (!current()) return
    bindings.value = list?.data ?? []
    quota.value = q?.data ?? null
  } catch {
    if (current() && !silent) bindings.value = []
  } finally {
    if (current()) loading.value = false
  }
  if (!current()) return
  clearTimeout(pollTimer)
  if (bindings.value.some(isBusy)) pollTimer = setTimeout(() => load(true), 3000)
}

async function loadTypes() {
  try {
    const { data } = await bindingsApi.types()
    types.value = data?.data ?? null
    retentionDays.value = data?.retention_days ?? 7
  } catch { types.value = null }
}

// ── Create ───────────────────────────────────────────────────────────────────────────────
function openCreate() {
  Object.assign(form, emptyForm())
  form.knative_region_id = regions.value[0]?.id ?? ''
  errors.value = {}
  showCreate.value = true
}

function onTypeChange() {
  if (form.type !== 'postgres') form.plan = 'shared'
  form.settings = defaultSettings(form.type, form.plan, types.value)
}

async function create() {
  if (!canCreate.value) return
  saving.value = true
  errors.value = {}
  try {
    await bindingsApi.create(bindingPayload(form))
    toast.success(t('bindings.data.created'))
    showCreate.value = false
    await load(true)
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    toast.error(e?.response?.data?.message ?? t('bindings.data.createFailed'))
  } finally {
    saving.value = false
  }
}

// ── Row actions ──────────────────────────────────────────────────────────────────────────
async function rotate(b) {
  const ok = await confirm({
    title: t('bindings.rotateTitle'),
    message: t('bindings.rotateConfirm', { name: b.name }),
    confirmLabel: t('bindings.rotate'),
  })
  if (!ok) return
  try {
    await bindingsApi.rotate(b.id)
    toast.success(t('bindings.rotated'))
    delete revealed[b.id]
    await load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('bindings.rotateFailed'))
  }
}

function askDelete(b) {
  deleting.value = b
  deleteName.value = ''
}

async function confirmDelete() {
  const b = deleting.value
  if (!b || deleteName.value !== b.name) return
  deleteBusy.value = true
  try {
    await bindingsApi.destroy(b.id, deleteName.value)
    toast.success(t('bindings.data.deleted'))
    deleting.value = null
    delete revealed[b.id]
    await load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('bindings.data.deleteFailed'))
  } finally {
    deleteBusy.value = false
  }
}

async function restore(b) {
  if (restoring.value.has(b.id)) return
  restoring.value = new Set([...restoring.value, b.id])
  try {
    await bindingsApi.restore(b.id)
    toast.success(t('bindings.data.restored'))
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('bindings.data.restoreFailed'))
    return
  } finally {
    const next = new Set(restoring.value)
    next.delete(b.id)
    restoring.value = next
  }
  await load(true)
}

// Hidden again (or rotated) while the credentials loaded: the answer is not shown
async function toggleReveal(b) {
  if (revealed[b.id]) {
    delete revealed[b.id]
    return
  }
  revealed[b.id] = 'loading'
  try {
    const { data } = await bindingsApi.credentials(b.id)
    if (revealed[b.id] === 'loading') revealed[b.id] = data?.data?.env ?? {}
  } catch (e) {
    if (revealed[b.id] !== 'loading') return
    delete revealed[b.id]
    toast.error(e?.response?.data?.message ?? t('bindings.revealFailed'))
  }
}

async function copy(value) {
  try {
    await navigator.clipboard.writeText(String(value ?? ''))
    toast.success(t('bindings.copied'))
  } catch { /* clipboard unavailable */ }
}

// ── Formatting ───────────────────────────────────────────────────────────────────────────
function fmtDate(iso) {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleDateString(locale.value === 'tr' ? 'tr-TR' : 'en-GB', { year: 'numeric', month: 'short', day: 'numeric' })
  } catch { return iso }
}

function usageLabel(b) {
  const limit = limitBytes(b)
  return limit ? `${fmtBytes(b.usage_bytes)} / ${fmtBytes(limit)}` : fmtBytes(b.usage_bytes)
}

function barColor(pct) {
  if (pct >= 90) return '#ef4444'
  if (pct >= 70) return '#f59e0b'
  return 'rgb(var(--brand-500))'
}

function quotaPct(key) {
  const limit = quota.value?.limits?.[key] ?? 0
  const used = quota.value?.used?.[key] ?? 0
  return limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0
}

function fieldError(key) {
  return errors.value?.[key]?.[0]
}

onMounted(() => {
  load()
  loadTypes()
  if (!settings.loaded) settings.fetch().catch(() => {})
})
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="space-y-6" data-data-page>
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('bindings.data.title') }}</h3>
        </div>
        <p class="text-xs text-gray-400 mt-2 max-w-3xl">{{ t('bindings.data.intro') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button type="button" class="btn btn-secondary text-xs" :title="t('bindings.refresh')" @click="load(true)">
          <RefreshCw class="w-3.5 h-3.5" />
        </button>
        <button v-if="canManage && !showCreate" type="button" class="btn btn-primary text-xs" data-data-create @click="openCreate">
          <Plus class="w-3.5 h-3.5" /> {{ t('bindings.data.create') }}
        </button>
      </div>
    </div>

    <!-- Limits ───────────────────────────────────────────────────────────────────────── -->
    <div v-if="quota" class="grid grid-cols-2 md:grid-cols-5 gap-3" data-quota>
      <div v-for="key in QUOTA_KEYS" :key="key" class="rounded-xl border border-gray-800 bg-gray-900/80 p-3" :data-quota-key="key">
        <p class="text-[10px] text-gray-500 font-medium uppercase tracking-wider">{{ t(`bindings.data.quota.${key}`) }}</p>
        <p class="text-sm font-bold text-gray-100 font-mono mt-1">
          {{ quota.used?.[key] ?? 0 }}
          <span class="text-xs text-gray-500 font-normal">/ {{ (quota.limits?.[key] ?? 0) > 0 ? quota.limits[key] : t('bindings.data.unlimited') }}</span>
        </p>
        <div v-if="(quota.limits?.[key] ?? 0) > 0" class="h-1 bg-gray-800 rounded-full overflow-hidden mt-2">
          <div class="h-full rounded-full" :style="{ width: quotaPct(key) + '%', backgroundColor: barColor(quotaPct(key)) }" />
        </div>
      </div>
    </div>

    <!-- Create ───────────────────────────────────────────────────────────────────────── -->
    <form v-if="showCreate" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-3" data-data-form @submit.prevent="create">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label class="label">{{ t('bindings.name') }}</label>
          <input v-model="form.name" class="input font-mono text-xs" placeholder="orders-db" data-field="name" />
          <p v-if="form.name && !isValidName(form.name)" class="text-xs text-red-400 mt-1">{{ t('bindings.nameInvalid') }}</p>
          <p v-else-if="fieldError('name')" class="text-xs text-red-400 mt-1">{{ fieldError('name') }}</p>
        </div>
        <div>
          <label class="label">{{ t('bindings.type') }}</label>
          <select v-model="form.type" class="input text-xs" data-field="type" @change="onTypeChange">
            <option v-for="type in BINDING_TYPES" :key="type" :value="type">{{ t(`bindings.types.${type}`) }}</option>
          </select>
        </div>
        <div v-if="form.type !== 'external'">
          <label class="label">{{ t('bindings.region') }}</label>
          <select v-model="form.knative_region_id" class="input text-xs" data-field="region">
            <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <div v-if="form.type === 'postgres'">
          <label class="label">{{ t('bindings.plan') }}</label>
          <select v-model="form.plan" class="input text-xs" data-field="plan" @change="onTypeChange">
            <option value="shared">{{ t('bindings.plans.shared') }}</option>
            <option value="dedicated">{{ t('bindings.plans.dedicated') }}</option>
          </select>
        </div>
      </div>
      <p class="text-[11px] text-gray-400">{{ t(`bindings.typeHints.${form.type}`) }}</p>
      <BindingSettingsFields v-model:settings="form.settings" v-model:values="form.values" :type="form.type" :plan="form.plan" :types="types" />
      <p v-if="fieldError('binding')" class="text-xs text-red-400">{{ fieldError('binding') }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-secondary text-xs" @click="showCreate = false">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary text-xs" :disabled="!canCreate" data-data-submit>{{ t('bindings.data.create') }}</button>
      </div>
    </form>

    <!-- Filter -->
    <div class="flex items-center gap-2">
      <select v-model="filterType" class="input text-xs w-48" data-field="filter">
        <option value="">{{ t('bindings.data.allTypes') }}</option>
        <option v-for="type in BINDING_TYPES" :key="type" :value="type">{{ t(`bindings.types.${type}`) }}</option>
      </select>
    </div>

    <div v-if="loading" class="flex justify-center py-8">
      <div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="!filtered.length" class="py-10 text-center">
      <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <Database class="w-6 h-6 text-gray-400" />
      </div>
      <p class="text-sm text-gray-400">{{ t('bindings.data.empty') }}</p>
    </div>

    <!-- Bindings ─────────────────────────────────────────────────────────────────────── -->
    <div v-else class="space-y-2">
      <div v-for="b in filtered" :key="b.id" class="rounded-xl border px-4 py-3"
        :class="b.status === 'deleting' ? 'border-red-900/40 bg-red-950/10' : 'border-gray-800 bg-gray-900/60'"
        :data-binding-row="b.name">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <component :is="TYPE_ICONS[b.type] || Database" class="w-4 h-4 text-sky-400 shrink-0" />
            <span class="text-sm font-semibold text-gray-100">{{ b.name }}</span>
            <span class="text-[11px] text-gray-400">{{ t(`bindings.types.${b.type}`) }}</span>
            <span v-if="b.plan === 'dedicated'" class="text-[10px] px-1.5 py-0.5 rounded-sm border border-violet-800/40 bg-violet-900/30 text-violet-300">{{ t('bindings.plans.dedicated') }}</span>
            <span :class="bindingStatusClass(b.status)" data-binding-status>{{ t(`bindings.status.${b.status}`) }}</span>
            <span v-if="b.region" class="text-[11px] text-gray-500 font-mono">{{ b.region.name }}</span>
          </div>
          <div class="flex items-center gap-1">
            <template v-if="b.status === 'deleting'">
              <span class="text-[11px] text-red-300 mr-2" data-purge-date>{{ t('bindings.data.purgeOn', { date: fmtDate(b.purge_at) }) }}</span>
              <button v-if="canManage" type="button" class="btn btn-secondary text-xs" data-action="restore" :disabled="restoring.has(b.id)" @click="restore(b)">
                <Undo2 class="w-3.5 h-3.5" /> {{ t('bindings.data.restore') }}
              </button>
            </template>
            <template v-else>
              <button v-if="canReveal" type="button" class="p-1 text-gray-400 hover:text-gray-200" data-action="reveal"
                :title="revealed[b.id] ? t('bindings.hide') : t('bindings.reveal')" @click="toggleReveal(b)">
                <Eye class="w-3.5 h-3.5" />
              </button>
              <button v-if="canManage && b.type !== 'external'" type="button" class="p-1 text-gray-400 hover:text-amber-300" data-action="rotate"
                :title="t('bindings.rotate')" :disabled="b.status !== 'ready'" @click="rotate(b)">
                <KeyRound class="w-3.5 h-3.5" />
              </button>
              <button v-if="canManage" type="button" class="p-1 text-gray-400 hover:text-red-400" data-action="delete"
                :title="t('bindings.data.delete')" @click="askDelete(b)">
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </template>
          </div>
        </div>

        <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-3 text-[11px]">
          <div>
            <p class="text-gray-500 mb-1">{{ t('bindings.attachedTo') }}</p>
            <div class="flex flex-wrap gap-1">
              <RouterLink v-for="a in (b.attachments ?? [])" :key="a.id" :to="`/projects/${a.project_id}#bindings`"
                class="px-1.5 py-0.5 rounded-sm border border-gray-700 bg-gray-800/60 text-gray-200 hover:border-sky-700">
                {{ a.project?.name ?? a.project_id }}<span v-if="a.env_prefix" class="text-gray-500 font-mono"> · {{ a.env_prefix }}</span>
              </RouterLink>
              <span v-if="!(b.attachments ?? []).length" class="text-gray-500">{{ t('bindings.notAttached') }}</span>
            </div>
          </div>
          <div v-if="b.type !== 'external'">
            <p class="text-gray-500 mb-1">{{ t('bindings.usage') }}</p>
            <p class="font-mono text-gray-200" data-usage>{{ usageLabel(b) }}</p>
            <div v-if="usagePercent(b) !== null" class="h-1 bg-gray-800 rounded-full overflow-hidden mt-1">
              <div class="h-full rounded-full" :style="{ width: usagePercent(b) + '%', backgroundColor: barColor(usagePercent(b)) }" />
            </div>
          </div>
          <div class="font-mono text-gray-400 space-y-0.5">
            <p v-if="b.details?.prefix">{{ t('bindings.details.prefix') }}: {{ b.details.prefix }}</p>
            <p v-if="b.details?.bucket">{{ t('bindings.details.bucket') }}: {{ b.details.bucket }}
              <span v-if="b.type === 'object' && b.details?.scoped_keys === false" class="text-amber-400"> · {{ t('bindings.details.sharedKey') }}</span>
            </p>
            <p v-if="b.details?.cluster">{{ t('bindings.details.cluster') }}: {{ b.details.cluster }}</p>
            <p>{{ (b.env_keys ?? []).join(', ') }}</p>
          </div>
        </div>
        <p v-if="b.status_message" class="mt-1 text-[11px]" :class="b.status === 'error' ? 'text-red-400' : 'text-gray-400'">{{ b.status_message }}</p>

        <div v-if="revealed[b.id]" class="mt-2 rounded-lg border border-gray-800 bg-gray-950/60 p-2" data-credentials>
          <div v-if="revealed[b.id] === 'loading'" class="text-[11px] text-gray-400">{{ t('common.loading') }}</div>
          <div v-else class="space-y-1">
            <div v-for="(value, key) in revealed[b.id]" :key="key" class="flex items-center gap-2 text-[11px] font-mono">
              <span class="text-gray-400 shrink-0">{{ key }}</span>
              <span class="text-gray-100 truncate">{{ value }}</span>
              <button type="button" class="p-0.5 text-gray-500 hover:text-gray-200 shrink-0" :title="t('bindings.copy')" @click="copy(value)">
                <Copy class="w-3 h-3" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete (typed name) ──────────────────────────────────────────────────────────── -->
    <div v-if="deleting" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 70" data-delete-dialog>
      <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="deleting = null" />
      <div class="relative card w-full max-w-md space-y-3">
        <h2 class="text-base font-semibold text-gray-100">{{ t('bindings.data.deleteTitle', { name: deleting.name }) }}</h2>
        <p class="text-sm text-gray-400">{{ t('bindings.data.deleteIntro', { days: retentionDays }) }}</p>
        <div>
          <label class="label">{{ t('bindings.data.deleteTypeName', { name: deleting.name }) }}</label>
          <input v-model="deleteName" class="input font-mono text-xs" :placeholder="deleting.name" data-field="confirm_name" autocomplete="off" />
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-secondary" :disabled="deleteBusy" @click="deleting = null">{{ t('common.cancel') }}</button>
          <button type="button" class="btn-secondary text-red-400 hover:text-red-300 border-red-900/50 hover:border-red-700"
            :disabled="deleteBusy || deleteName !== deleting.name" data-action="confirm-delete" @click="confirmDelete">
            {{ t('bindings.data.delete') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
