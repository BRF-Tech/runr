<script setup>
// Bindings of a project (docs/bindings.md): managed KV / object storage / Postgres / external
// credentials attached with an env prefix. Values reach the functions through the Secret
// <project>-bindings (envFrom); attach, detach and rotation roll the running revision.
// Changes: env.edit on the project + bindings.manage; values: bindings.view_credentials.
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { bindingsApi } from '@/api/bindings'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import BindingSettingsFields from '@/components/bindings/BindingSettingsFields.vue'
import {
  envNames, bindingEnvNames, envConflicts, isValidPrefix, isValidName, normalizePrefix,
  bindingStatusClass, bindingPayload, defaultSettings, isBusy, fmtBytes, limitBytes,
} from '@/utils/bindings'
import { Database, HardDrive, Boxes, KeyRound, Plus, Pencil, RefreshCw, Eye, Copy, X, Check, AlertTriangle } from 'lucide-vue-next'

const props = defineProps({ project: { type: Object, required: true } })

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const perms = usePermissionsStore()

const canManage = computed(() => perms.can('bindings.manage') && perms.can('env.edit', props.project.id))
const canReveal = computed(() => perms.can('bindings.view_credentials'))
const canRotate = computed(() => perms.can('bindings.manage'))

const attachments = ref([])
const available = ref([])
const secretName = ref(`${props.project.slug ?? ''}-bindings`)
const types = ref(null)
const loading = ref(false)
const showForm = ref(false)
const saving = ref(false)
const errors = ref({})
const revealed = reactive({})       // attachment id → { env } | 'loading'
const editingPrefix = reactive({})  // attachment id → draft prefix

const TYPE_ICONS = { kv: KeyRound, object: HardDrive, postgres: Database, external: Boxes }

const emptyForm = () => ({
  mode: 'new',
  binding_id: '',
  env_prefix: '',
  name: '',
  type: 'kv',
  plan: 'shared',
  settings: defaultSettings('kv', 'shared', types.value),
  values: [{ key: '', value: '' }],
})
const form = reactive(emptyForm())

// ── Derived ──────────────────────────────────────────────────────────────────────────────
const selectedExisting = computed(() => available.value.find(b => b.id === form.binding_id) ?? null)

const previewNames = computed(() => {
  if (form.mode === 'existing') return selectedExisting.value ? bindingEnvNames(selectedExisting.value, form.env_prefix) : []
  return envNames(form.type, form.env_prefix, form.values)
})

const takenNames = computed(() => attachments.value.flatMap(a => a.env_names ?? []))
const conflicts = computed(() => envConflicts(previewNames.value, takenNames.value))
const prefixValid = computed(() => isValidPrefix(form.env_prefix))
const nameValid = computed(() => form.mode === 'existing' || isValidName(form.name))

const canSubmit = computed(() => {
  if (saving.value || !prefixValid.value || conflicts.value.length) return false
  if (form.mode === 'existing') return !!form.binding_id
  return nameValid.value && (form.type !== 'external' || envNames('external', '', form.values).length > 0)
})

// ── Loading + polling while the API works on a binding ─────────────────────────────────
let pollTimer = null

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const { data } = await bindingsApi.projectBindings(props.project.id)
    attachments.value = data?.data ?? []
    available.value = data?.available ?? []
    if (data?.secret) secretName.value = data.secret
  } catch {
    if (!silent) attachments.value = []
  } finally {
    loading.value = false
  }
  schedulePoll()
}

function schedulePoll() {
  clearTimeout(pollTimer)
  if (attachments.value.some(a => isBusy(a.binding))) {
    pollTimer = setTimeout(() => load(true), 3000)
  }
}

async function loadTypes() {
  try {
    const { data } = await bindingsApi.types()
    types.value = data?.data ?? null
  } catch { types.value = null }
}

// ── Attach ───────────────────────────────────────────────────────────────────────────────
function openForm() {
  Object.assign(form, emptyForm())
  form.mode = available.value.length ? 'existing' : 'new'
  errors.value = {}
  showForm.value = true
}

function onTypeChange() {
  if (form.type !== 'postgres') form.plan = 'shared'
  form.settings = defaultSettings(form.type, form.plan, types.value)
}

function onPlanChange() {
  form.settings = defaultSettings(form.type, form.plan, types.value)
}

function onPrefixInput(e) {
  form.env_prefix = normalizePrefix(e.target.value)
}

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  errors.value = {}
  try {
    const body = { env_prefix: form.env_prefix || null }
    if (form.mode === 'existing') body.binding_id = form.binding_id
    else body.binding = bindingPayload(form)
    await bindingsApi.attach(props.project.id, body)
    toast.success(t('bindings.attached'))
    showForm.value = false
    await load()
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    toast.error(e?.response?.data?.message ?? t('bindings.attachFailed'))
  } finally {
    saving.value = false
  }
}

function fieldError(key) {
  const direct = errors.value?.[key]?.[0]
  if (direct) return direct
  const nested = Object.entries(errors.value ?? {}).find(([k]) => k === `binding.${key}`)
  return nested?.[1]?.[0]
}

// ── Per attachment ───────────────────────────────────────────────────────────────────────
function startPrefixEdit(a) {
  editingPrefix[a.id] = a.env_prefix ?? ''
}

function cancelPrefixEdit(a) {
  delete editingPrefix[a.id]
}

async function savePrefix(a) {
  const prefix = editingPrefix[a.id] ?? ''
  if (!isValidPrefix(prefix)) return
  try {
    await bindingsApi.updateAttachment(props.project.id, a.id, { env_prefix: prefix || null })
    delete editingPrefix[a.id]
    toast.success(t('bindings.prefixSaved'))
    await load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('bindings.prefixFailed'))
  }
}

async function detach(a) {
  const ok = await confirm({
    title: t('bindings.detachTitle'),
    message: t('bindings.detachConfirm', { name: a.binding?.name ?? '' }),
    destructive: true,
    confirmLabel: t('bindings.detach'),
  })
  if (!ok) return
  try {
    await bindingsApi.detach(props.project.id, a.id)
    toast.success(t('bindings.detached'))
    delete revealed[a.id]
    await load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('bindings.detachFailed'))
  }
}

async function rotate(a) {
  const ok = await confirm({
    title: t('bindings.rotateTitle'),
    message: t('bindings.rotateConfirm', { name: a.binding?.name ?? '' }),
    confirmLabel: t('bindings.rotate'),
  })
  if (!ok) return
  try {
    await bindingsApi.rotate(a.binding_id)
    toast.success(t('bindings.rotated'))
    delete revealed[a.id]
    await load(true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('bindings.rotateFailed'))
  }
}

async function toggleReveal(a) {
  if (revealed[a.id]) {
    delete revealed[a.id]
    return
  }
  revealed[a.id] = 'loading'
  try {
    const { data } = await bindingsApi.credentials(a.binding_id)
    const env = data?.data?.env ?? {}
    // Shown with the attachment's prefix — the names the functions see
    revealed[a.id] = Object.fromEntries(Object.entries(env).map(([k, v]) => [(a.env_prefix ?? '') + k, v]))
  } catch (e) {
    delete revealed[a.id]
    toast.error(e?.response?.data?.message ?? t('bindings.revealFailed'))
  }
}

async function copy(value) {
  try {
    await navigator.clipboard.writeText(String(value ?? ''))
    toast.success(t('bindings.copied'))
  } catch { /* clipboard unavailable */ }
}

function usageLabel(binding) {
  const limit = limitBytes(binding)
  return limit ? `${fmtBytes(binding.usage_bytes)} / ${fmtBytes(limit)}` : fmtBytes(binding.usage_bytes)
}

onMounted(() => { load(); loadTypes() })
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="py-5 space-y-4" data-bindings-tab>
    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-sm font-semibold text-gray-100">{{ t('bindings.title') }}</h3>
        <p class="text-xs text-gray-400 mt-1 max-w-2xl">{{ t('bindings.intro', { secret: secretName }) }}</p>
      </div>
      <button v-if="canManage && !showForm" type="button" class="btn btn-primary text-xs shrink-0" data-bindings-open @click="openForm">
        <Plus class="w-3.5 h-3.5" /> {{ t('bindings.attach') }}
      </button>
    </div>

    <!-- Attach form ──────────────────────────────────────────────────────────────────── -->
    <form v-if="showForm" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-4" data-bindings-form @submit.prevent="submit">
      <div class="flex gap-2">
        <button type="button" class="settings-tab text-xs" :class="form.mode === 'existing' && 'is-active'"
          :disabled="!available.length" data-mode="existing" @click="form.mode = 'existing'">
          {{ t('bindings.attachExisting') }}
        </button>
        <button type="button" class="settings-tab text-xs" :class="form.mode === 'new' && 'is-active'" data-mode="new" @click="form.mode = 'new'">
          {{ t('bindings.createNew') }}
        </button>
      </div>

      <!-- Existing binding -->
      <div v-if="form.mode === 'existing'" class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="label">{{ t('bindings.chooseBinding') }}</label>
          <select v-model="form.binding_id" class="input text-xs" data-field="binding_id">
            <option value="">{{ t('bindings.chooseBindingPlaceholder') }}</option>
            <option v-for="b in available" :key="b.id" :value="b.id">
              {{ b.name }} · {{ t(`bindings.types.${b.type}`) }}{{ b.region ? ` · ${b.region.name}` : '' }}
            </option>
          </select>
          <p v-if="!available.length" class="text-[11px] text-gray-400 mt-1">{{ t('bindings.noAvailable') }}</p>
        </div>
      </div>

      <!-- New binding -->
      <div v-else class="space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="label">{{ t('bindings.name') }}</label>
            <input v-model="form.name" class="input font-mono text-xs" placeholder="orders-db" data-field="name" />
            <p v-if="form.name && !nameValid" class="text-xs text-red-400 mt-1">{{ t('bindings.nameInvalid') }}</p>
            <p v-else-if="fieldError('name')" class="text-xs text-red-400 mt-1">{{ fieldError('name') }}</p>
          </div>
          <div>
            <label class="label">{{ t('bindings.type') }}</label>
            <select v-model="form.type" class="input text-xs" data-field="type" @change="onTypeChange">
              <option v-for="type in ['kv', 'object', 'postgres', 'external']" :key="type" :value="type">{{ t(`bindings.types.${type}`) }}</option>
            </select>
          </div>
          <div v-if="form.type === 'postgres'">
            <label class="label">{{ t('bindings.plan') }}</label>
            <select v-model="form.plan" class="input text-xs" data-field="plan" @change="onPlanChange">
              <option value="shared">{{ t('bindings.plans.shared') }}</option>
              <option value="dedicated">{{ t('bindings.plans.dedicated') }}</option>
            </select>
          </div>
        </div>
        <p class="text-[11px] text-gray-400">{{ t(`bindings.typeHints.${form.type}`) }}</p>
        <BindingSettingsFields v-model:settings="form.settings" v-model:values="form.values" :type="form.type" :plan="form.plan" :types="types" />
      </div>

      <!-- Prefix + env preview -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="label">{{ t('bindings.envPrefix') }}</label>
          <input :value="form.env_prefix" class="input font-mono text-xs" placeholder="ORDERS_" data-field="env_prefix" @input="onPrefixInput" />
          <p v-if="!prefixValid" class="text-xs text-red-400 mt-1">{{ t('bindings.envPrefixInvalid') }}</p>
          <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('bindings.envPrefixHint') }}</p>
          <p v-if="fieldError('env_prefix')" class="text-xs text-red-400 mt-1">{{ fieldError('env_prefix') }}</p>
        </div>
        <div class="md:col-span-2">
          <label class="label">{{ t('bindings.envPreview') }}</label>
          <div class="flex flex-wrap gap-1" data-env-preview>
            <code v-for="n in previewNames" :key="n"
              class="px-1.5 py-0.5 rounded text-[10px] font-mono border"
              :class="conflicts.includes(n) ? 'border-red-800/60 bg-red-900/30 text-red-300' : 'border-gray-700 bg-gray-800/60 text-gray-200'">{{ n }}</code>
          </div>
          <p v-if="conflicts.length" class="text-xs text-red-400 mt-1 flex items-center gap-1" data-env-conflict>
            <AlertTriangle class="w-3 h-3" /> {{ t('bindings.envConflict', { names: conflicts.join(', ') }) }}
          </p>
          <p v-else class="text-[11px] text-gray-500 mt-1">{{ t('bindings.envOverride') }}</p>
        </div>
      </div>

      <p v-if="fieldError('binding')" class="text-xs text-red-400">{{ fieldError('binding') }}</p>

      <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-secondary text-xs" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary text-xs" :disabled="!canSubmit" data-bindings-submit>
          {{ form.mode === 'existing' ? t('bindings.attachBtn') : t('bindings.createAndAttach') }}
        </button>
      </div>
    </form>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="!attachments.length && !showForm" class="py-10 text-center">
      <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <Database class="w-6 h-6 text-gray-400" />
      </div>
      <p class="text-sm text-gray-400">{{ t('bindings.empty') }}</p>
    </div>

    <!-- Attachments ──────────────────────────────────────────────────────────────────── -->
    <div v-else class="space-y-2">
      <div v-for="a in attachments" :key="a.id" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3"
        :data-attachment="a.binding?.name">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <component :is="TYPE_ICONS[a.binding?.type] || Database" class="w-4 h-4 text-sky-400 shrink-0" />
            <span class="text-sm font-semibold text-gray-100">{{ a.binding?.name }}</span>
            <span class="text-[11px] text-gray-400">{{ t(`bindings.types.${a.binding?.type}`) }}</span>
            <span v-if="a.binding?.plan === 'dedicated'" class="text-[10px] px-1.5 py-0.5 rounded border border-violet-800/40 bg-violet-900/30 text-violet-300">{{ t('bindings.plans.dedicated') }}</span>
            <span :class="bindingStatusClass(a.binding?.status)" data-binding-status>{{ t(`bindings.status.${a.binding?.status ?? 'pending'}`) }}</span>
          </div>
          <div class="flex items-center gap-1">
            <button type="button" class="p-1 text-gray-400 hover:text-gray-200" :title="t('bindings.refresh')" @click="load(true)">
              <RefreshCw class="w-3.5 h-3.5" />
            </button>
            <button v-if="canReveal" type="button" class="p-1 text-gray-400 hover:text-gray-200" data-action="reveal"
              :title="revealed[a.id] ? t('bindings.hide') : t('bindings.reveal')" @click="toggleReveal(a)">
              <Eye class="w-3.5 h-3.5" />
            </button>
            <button v-if="canRotate && a.binding?.type !== 'external'" type="button" class="p-1 text-gray-400 hover:text-amber-300" data-action="rotate"
              :title="t('bindings.rotate')" :disabled="a.binding?.status !== 'ready'" @click="rotate(a)">
              <KeyRound class="w-3.5 h-3.5" />
            </button>
            <button v-if="canManage" type="button" class="p-1 text-gray-400 hover:text-red-400" data-action="detach"
              :title="t('bindings.detach')" @click="detach(a)">
              <X class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>

        <!-- Env names + prefix -->
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <template v-if="editingPrefix[a.id] !== undefined">
            <input :value="editingPrefix[a.id]" class="input font-mono text-[11px] py-1 w-40" placeholder="ORDERS_" data-field="edit_prefix"
              @input="editingPrefix[a.id] = normalizePrefix($event.target.value)" @keyup.enter="savePrefix(a)" />
            <button type="button" class="p-1 text-emerald-400 disabled:opacity-40" :disabled="!isValidPrefix(editingPrefix[a.id])" data-action="save-prefix" @click="savePrefix(a)">
              <Check class="w-3.5 h-3.5" />
            </button>
            <button type="button" class="p-1 text-gray-400" @click="cancelPrefixEdit(a)"><X class="w-3.5 h-3.5" /></button>
          </template>
          <button v-else-if="canManage" type="button" class="text-[11px] text-gray-400 hover:text-gray-200 flex items-center gap-1" data-action="edit-prefix" @click="startPrefixEdit(a)">
            <Pencil class="w-3 h-3" /> {{ a.env_prefix ? a.env_prefix : t('bindings.editPrefix') }}
          </button>
          <code v-for="n in (editingPrefix[a.id] !== undefined ? bindingEnvNames(a.binding, editingPrefix[a.id]) : a.env_names)" :key="n"
            class="px-1.5 py-0.5 rounded text-[10px] font-mono border border-gray-700 bg-gray-800/60 text-gray-200">{{ n }}</code>
        </div>

        <!-- Details -->
        <div class="mt-1.5 text-[11px] text-gray-400 font-mono flex flex-wrap gap-x-4 gap-y-1">
          <span v-if="a.binding?.region">{{ t('bindings.region') }}: {{ a.binding.region.name }}</span>
          <span v-if="a.binding?.details?.prefix">{{ t('bindings.details.prefix') }}: {{ a.binding.details.prefix }}</span>
          <span v-if="a.binding?.details?.mode === 'db'">{{ t('bindings.details.mode_db') }}</span>
          <span v-if="a.binding?.details?.bucket">{{ t('bindings.details.bucket') }}: {{ a.binding.details.bucket }}</span>
          <span v-if="a.binding?.type === 'object' && a.binding?.details?.scoped_keys === false" class="text-amber-400">{{ t('bindings.details.sharedKey') }}</span>
          <span v-if="a.binding?.details?.database">{{ t('bindings.details.database') }}: {{ a.binding.details.database }}</span>
          <span v-if="a.binding?.type !== 'external'">{{ t('bindings.usage') }}: {{ usageLabel(a.binding ?? {}) }}</span>
          <span v-if="a.binding?.settings?.over_quota" class="text-red-400">{{ t('bindings.overQuota') }}</span>
        </div>
        <p v-if="a.binding?.status_message" class="mt-1 text-[11px]" :class="a.binding?.status === 'error' ? 'text-red-400' : 'text-gray-400'">{{ a.binding.status_message }}</p>

        <!-- Revealed credentials -->
        <div v-if="revealed[a.id]" class="mt-2 rounded-lg border border-gray-800 bg-gray-950/60 p-2" data-credentials>
          <div v-if="revealed[a.id] === 'loading'" class="text-[11px] text-gray-400">{{ t('common.loading') }}</div>
          <div v-else class="space-y-1">
            <div v-for="(value, key) in revealed[a.id]" :key="key" class="flex items-center gap-2 text-[11px] font-mono">
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
  </div>
</template>
