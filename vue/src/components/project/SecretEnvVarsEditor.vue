<script setup>
// Secret env vars of a project (docs/security.md, docs/secret-stores.md). A variable is either a
// value — written straight into the project's region (pass-through), write-only: the API never
// returns it unless the user may view values (owners) and asks for one explicitly ("Show") — or a
// reference to one of the organization's secret stores, synced in the region; RunR never sees it.
//   projectId set   → changes go straight to the API (edit project)
//   projectId null  → v-model list of { name, value } the caller saves after creating the project
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectSecretsApi } from '@/api/security'
import { secretStoresApi } from '@/api/secretStores'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { KeyRound, Plus, Trash2, Eye, EyeOff, Pencil, Link2, RefreshCw } from 'lucide-vue-next'

const ENV_KEY = /^[A-Za-z_][A-Za-z0-9_]*$/
const RESERVED = ['PORT', 'K_SERVICE', 'K_CONFIGURATION', 'K_REVISION', 'RUNR_MODE', 'RUNR_HANDLER', 'RUNR_FILENAME']

const props = defineProps({
  projectId:  { type: String, default: null },
  modelValue: { type: Array, default: () => [] },
  // Names of the plain env vars of the form — a name is either plain or secret
  plainNames: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue', 'changed'])

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const remote = computed(() => !!props.projectId)
const secrets = ref([])           // remote: [{ name, source, version, stored_in, fingerprint, reference, sync, updated_at, updated_by }]
const canViewValues = ref(false)
const loading = ref(false)
const loadError = ref(false)
const busy = ref(null)            // name being saved / removed
const revealed = ref({})          // name → value (remote, while shown)
const hideTimers = {}             // name → timer that hides it again
onUnmounted(() => Object.values(hideTimers).forEach(clearTimeout))
const mismatch = ref({})          // name → true: the region's value is not the one RunR wrote
const rowError = ref({})          // name → message of the last failed action on the row
const replacing = ref(null)       // name whose value is being replaced
const replaceValue = ref('')
const newName = ref('')
const newValue = ref('')
const formError = ref(null)
const syncing = ref(false)

// Reference form (remote only): a secret store, a path and an optional key
const refOpen = ref(false)
const refEditing = ref(null)      // name of the reference being changed (null: a new one)
const refName = ref('')
const refStore = ref('')
const refPath = ref('')
const refKey = ref('')
const refError = ref(null)
const stores = ref(null)          // null: not loaded yet
const storesError = ref(null)

const rows = computed(() => remote.value
  ? secrets.value
  : props.modelValue.map(s => ({ name: s.name, pending: true })))

const hasReferences = computed(() => secrets.value.some(isReference))

function isReference(row) {
  return row?.source === 'external'
}

// A value in the region's own OpenBao (source "openbao"), not in its Kubernetes Secret
function isOpenBao(row) {
  return row?.source === 'openbao' || row?.stored_in === 'openbao'
}

async function load() {
  if (!remote.value) return
  loading.value = true
  loadError.value = false
  try {
    const { data } = await projectSecretsApi.list(props.projectId)
    apply(data)
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function apply(data) {
  secrets.value = data?.data ?? []
  canViewValues.value = !!data?.can_view_values
}

function nameError(name, { allowExisting = false } = {}) {
  if (!ENV_KEY.test(name)) return t('secretEnv.errors.nameInvalid')
  if (RESERVED.includes(name.toUpperCase())) return t('secretEnv.errors.nameReserved', { name })
  if (!allowExisting && rows.value.some(r => r.name === name)) return t('secretEnv.errors.nameTaken', { name })
  if (props.plainNames.includes(name)) return t('secretEnv.errors.nameIsPlain', { name })
  return null
}

// The server's message: validation errors, and the reasons of the secret backends
// (region_unreachable 503, value_missing 409, no_cluster, eso_not_installed 422)
function apiError(e) {
  return e?.response?.data?.message
    ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0]
    ?? t('common.error')
}

async function add() {
  const name = newName.value.trim()
  formError.value = nameError(name) ?? (newValue.value === '' ? t('secretEnv.errors.valueRequired') : null)
  if (formError.value) return

  if (!remote.value) {
    emit('update:modelValue', [...props.modelValue, { name, value: newValue.value }])
    newName.value = ''
    newValue.value = ''
    return
  }

  busy.value = name
  try {
    await projectSecretsApi.set(props.projectId, name, newValue.value)
    newName.value = ''
    newValue.value = ''
    await load()
    emit('changed')
  } catch (e) {
    formError.value = apiError(e)
  } finally {
    busy.value = null
  }
}

function startReplace(row) {
  if (isReference(row)) {
    openReference(row)
    return
  }
  replacing.value = row.name
  replaceValue.value = ''
}

async function saveReplace(name) {
  if (replaceValue.value === '') return
  busy.value = name
  delete rowError.value[name]
  try {
    await projectSecretsApi.set(props.projectId, name, replaceValue.value)
    replacing.value = null
    replaceValue.value = ''
    delete revealed.value[name]
    delete mismatch.value[name]
    await load()
    emit('changed')
  } catch (e) {
    rowError.value[name] = apiError(e)
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

async function remove(name) {
  if (!remote.value) {
    emit('update:modelValue', props.modelValue.filter(s => s.name !== name))
    return
  }
  const ok = await confirm({
    title: t('secretEnv.removeTitle'),
    message: t('secretEnv.removeConfirm', { name }),
    destructive: true,
    confirmLabel: t('secretEnv.remove'),
  })
  if (!ok) return

  busy.value = name
  try {
    await projectSecretsApi.remove(props.projectId, name)
    delete revealed.value[name]
    delete mismatch.value[name]
    delete rowError.value[name]
    await load()
    emit('changed')
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

async function toggleReveal(name) {
  if (revealed.value[name] !== undefined) {
    delete revealed.value[name]
    return
  }
  busy.value = name
  delete rowError.value[name]
  try {
    const { data } = await projectSecretsApi.reveal(props.projectId, name)
    revealed.value[name] = data?.value ?? ''
    // false: the value in the region is not the one RunR wrote (changed in the cluster)
    if (data?.matches_fingerprint === false) mismatch.value[name] = true
    else delete mismatch.value[name]
    // Shown briefly only (30 s from this reveal: a timer of an earlier reveal does not cut it short)
    clearTimeout(hideTimers[name])
    hideTimers[name] = setTimeout(() => { delete revealed.value[name]; delete hideTimers[name] }, 30000)
  } catch (e) {
    rowError.value[name] = apiError(e)
  } finally {
    busy.value = null
  }
}

async function loadStores() {
  storesError.value = null
  try {
    const { data } = await secretStoresApi.list()
    stores.value = data?.data ?? []
  } catch (e) {
    stores.value = []
    storesError.value = e?.response?.status === 403 ? t('secretEnv.ref.storesForbidden') : apiError(e)
  }
}

function openReference(row = null) {
  refOpen.value = true
  refEditing.value = row?.name ?? null
  refName.value = row?.name ?? ''
  refStore.value = row?.reference?.store_id ?? ''
  refPath.value = row?.reference?.path ?? ''
  refKey.value = row?.reference?.key ?? ''
  refError.value = null
  if (stores.value === null) loadStores()
}

function closeReference() {
  refOpen.value = false
  refEditing.value = null
  refError.value = null
}

async function saveReference() {
  const name = refName.value.trim()
  refError.value = nameError(name, { allowExisting: refEditing.value === name })
    ?? (!refStore.value ? t('secretEnv.ref.errors.storeRequired') : null)
    ?? (refPath.value.trim() === '' ? t('secretEnv.ref.errors.pathRequired') : null)
  if (refError.value) return

  busy.value = name
  try {
    const reference = { store_id: refStore.value, path: refPath.value.trim() }
    if (refKey.value.trim() !== '') reference.key = refKey.value.trim()
    await projectSecretsApi.setReference(props.projectId, name, reference)
    delete revealed.value[name]
    delete mismatch.value[name]
    closeReference()
    await load()
    emit('changed')
  } catch (e) {
    refError.value = apiError(e)
  } finally {
    busy.value = null
  }
}

async function syncNow() {
  syncing.value = true
  try {
    const { data } = await projectSecretsApi.refresh(props.projectId)
    apply(data)
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    syncing.value = false
  }
}

function fmtDate(value) {
  return value ? new Date(value).toLocaleString(locale.value) : ''
}

function updatedLine(row) {
  if (!row.updated_at) return ''
  const date = fmtDate(row.updated_at)
  return row.updated_by?.name
    ? `${t('secretEnv.updatedAt', { date })} ${t('secretEnv.updatedBy', { name: row.updated_by.name })}`
    : t('secretEnv.updatedAt', { date })
}

function referenceLine(row) {
  const r = row.reference ?? {}
  const where = r.key ? `${r.path}#${r.key}` : r.path
  return t('secretEnv.source.store', { store: r.store_name ?? r.store_id ?? '—', path: where ?? '—' })
}

const syncClasses = {
  synced:  'bg-emerald-900/30 border-emerald-800/60 text-emerald-300',
  failed:  'bg-red-900/30 border-red-800/60 text-red-300',
  pending: 'bg-gray-800 border-gray-700 text-gray-400',
}

function syncTitle(row) {
  const parts = []
  if (row.sync?.message) parts.push(row.sync.message)
  if (row.sync?.synced_at) parts.push(t('secretEnv.sync.lastSync', { date: fmtDate(row.sync.synced_at) }))
  return parts.join(' — ') || undefined
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <section data-testid="secret-env-editor">
    <p class="section-title flex items-center gap-1.5">
      <KeyRound class="w-3.5 h-3.5" />{{ t('secretEnv.title') }}
      <span class="normal-case text-gray-400 font-normal">{{ t('common.optional') }}</span>
      <button
        v-if="remote && hasReferences" type="button" class="ml-auto normal-case text-[11px] text-gray-400 hover:text-gray-100 flex items-center gap-1 disabled:opacity-50"
        :disabled="syncing" data-testid="secret-sync-now" @click="syncNow"
      >
        <RefreshCw class="w-3 h-3" :class="syncing && 'animate-spin'" />{{ t('secretEnv.sync.now') }}
      </button>
    </p>
    <p class="text-xs text-gray-400 mb-3">{{ t('secretEnv.hint') }}<template v-if="remote"> {{ t('secretEnv.deployHint') }}</template></p>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="text-xs text-red-400">{{ t('secretEnv.loadError') }}</p>

    <ul v-else-if="rows.length" class="space-y-1.5 mb-3">
      <li
        v-for="row in rows" :key="row.name"
        class="rounded-lg border border-gray-800 bg-gray-900/60 px-3 py-2"
        :data-testid="`secret-row-${row.name}`"
      >
        <div class="flex items-center gap-2">
          <span class="font-mono text-xs text-gray-100 truncate">{{ row.name }}</span>
          <span v-if="isReference(row)" class="font-mono text-xs text-gray-400 truncate flex-1 flex items-center gap-1" :data-testid="`secret-value-${row.name}`">
            <Link2 class="w-3 h-3 shrink-0" />{{ referenceLine(row) }}
          </span>
          <span v-else class="font-mono text-xs text-gray-400 truncate flex-1" :data-testid="`secret-value-${row.name}`">
            {{ revealed[row.name] !== undefined ? revealed[row.name] : '••••••••' }}
          </span>
          <span v-if="row.pending" class="text-[11px] text-gray-400">{{ t('secretEnv.pending') }}</span>
          <template v-else>
            <button
              v-if="canViewValues && !isReference(row)" type="button" class="icon-btn" :disabled="busy === row.name"
              :title="revealed[row.name] !== undefined ? t('secretEnv.hide') : t('secretEnv.reveal')"
              :data-testid="`secret-reveal-${row.name}`" @click="toggleReveal(row.name)"
            >
              <EyeOff v-if="revealed[row.name] !== undefined" class="w-3.5 h-3.5" />
              <Eye v-else class="w-3.5 h-3.5" />
            </button>
            <button
              type="button" class="icon-btn" :disabled="busy === row.name"
              :title="isReference(row) ? t('secretEnv.ref.change') : t('secretEnv.replace')"
              :data-testid="`secret-replace-${row.name}`" @click="startReplace(row)"
            >
              <Pencil class="w-3.5 h-3.5" />
            </button>
          </template>
          <button
            type="button" class="icon-btn hover:text-red-400" :disabled="busy === row.name" :title="t('secretEnv.remove')"
            :data-testid="`secret-remove-${row.name}`" @click="remove(row.name)"
          >
            <Trash2 class="w-3.5 h-3.5" />
          </button>
        </div>

        <!-- Where it lives: a value in the region (its fingerprint), or a store reference and its sync -->
        <div v-if="!row.pending && remote" class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1 text-[11px]">
          <template v-if="isReference(row)">
            <span
              class="px-1.5 py-0.5 rounded-sm border" :class="syncClasses[row.sync?.status] ?? syncClasses.pending"
              :title="syncTitle(row)" :data-testid="`secret-sync-${row.name}`"
            >{{ t(`secretEnv.sync.${row.sync?.status ?? 'pending'}`) }}</span>
            <span v-if="row.sync?.synced_at" class="text-gray-500">{{ t('secretEnv.sync.lastSync', { date: fmtDate(row.sync.synced_at) }) }}</span>
          </template>
          <template v-else>
            <!-- The region's own OpenBao, or its Kubernetes Secret -->
            <span
              v-if="isOpenBao(row)" class="px-1.5 py-0.5 rounded-sm border bg-teal-900/20 border-teal-800/60 text-teal-300"
              :title="t('secretEnv.source.openbaoHint')" :data-testid="`secret-source-${row.name}`"
            >{{ t('secretEnv.source.openbao') }}</span>
            <span v-else class="text-gray-500" :data-testid="`secret-source-${row.name}`">{{ t('secretEnv.source.region') }}</span>
            <span
              v-if="row.stored_in === 'database'"
              class="px-1.5 py-0.5 rounded-sm border bg-amber-900/20 border-amber-800/60 text-amber-300"
              :title="t('secretEnv.source.databaseHint')" :data-testid="`secret-in-database-${row.name}`"
            >{{ t('secretEnv.source.database') }}</span>
            <span v-if="row.fingerprint" class="font-mono text-gray-600" :title="t('secretEnv.fingerprintHint')" :data-testid="`secret-fingerprint-${row.name}`">
              {{ t('secretEnv.fingerprint', { fp: row.fingerprint }) }}
            </span>
          </template>
        </div>
        <p v-if="isReference(row) && row.sync?.status === 'failed' && row.sync?.message" class="text-[11px] text-red-400/90 mt-0.5" :data-testid="`secret-sync-message-${row.name}`">
          {{ row.sync.message }}
        </p>
        <p v-if="!row.pending && updatedLine(row)" class="text-[11px] text-gray-500 mt-0.5">{{ updatedLine(row) }}</p>
        <p v-if="mismatch[row.name]" class="text-[11px] text-amber-300 mt-0.5" :data-testid="`secret-mismatch-${row.name}`">{{ t('secretEnv.mismatch') }}</p>
        <p v-if="rowError[row.name]" class="text-[11px] text-red-400 mt-0.5" :data-testid="`secret-row-error-${row.name}`">{{ rowError[row.name] }}</p>

        <div v-if="replacing === row.name" class="flex gap-2 mt-2">
          <input
            v-model="replaceValue" type="password" autocomplete="new-password" class="input font-mono text-xs flex-1"
            :placeholder="t('secretEnv.replacePlaceholder')" :data-testid="`secret-replace-input-${row.name}`"
            @keydown.enter.prevent="saveReplace(row.name)"
          />
          <button type="button" class="btn-primary text-xs" :disabled="!replaceValue || busy === row.name" @click="saveReplace(row.name)">
            {{ t('secretEnv.save') }}
          </button>
          <button type="button" class="btn-secondary text-xs" @click="replacing = null">{{ t('secretEnv.cancel') }}</button>
        </div>
      </li>
    </ul>
    <p v-else class="text-xs text-gray-500 mb-3">{{ t('secretEnv.empty') }}</p>

    <div v-if="!loadError" class="flex gap-2">
      <input
        v-model="newName" class="input font-mono text-xs w-1/3" :placeholder="t('secretEnv.namePlaceholder')"
        autocomplete="off" spellcheck="false" data-testid="secret-new-name"
      />
      <input
        v-model="newValue" type="password" autocomplete="new-password" class="input font-mono text-xs flex-1"
        :placeholder="t('secretEnv.valuePlaceholder')" data-testid="secret-new-value" @keydown.enter.prevent="add"
      />
      <button type="button" class="btn-secondary text-xs flex items-center gap-1" :disabled="!!busy" data-testid="secret-add" @click="add">
        <Plus class="w-3.5 h-3.5" />{{ t('secretEnv.add') }}
      </button>
    </div>
    <p v-if="formError" class="text-xs text-red-400 mt-1" data-testid="secret-error">{{ formError }}</p>

    <!-- A reference to a secret store (edit project only: the project must exist) -->
    <template v-if="remote && !loadError">
      <button
        v-if="!refOpen" type="button" class="mt-2 text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
        data-testid="secret-add-reference" @click="openReference()"
      >
        <Link2 class="w-3.5 h-3.5" />{{ t('secretEnv.ref.add') }}
      </button>
      <div v-else class="mt-3 rounded-lg border border-gray-800 bg-gray-900/40 p-3 space-y-2" data-testid="secret-reference-form">
        <p class="text-xs text-gray-300 font-medium">{{ refEditing ? t('secretEnv.ref.editTitle', { name: refEditing }) : t('secretEnv.ref.title') }}</p>
        <p class="text-[11px] text-gray-500">{{ t('secretEnv.ref.hint') }}</p>
        <p v-if="stores === null" class="text-[11px] text-gray-400">{{ t('common.loading') }}</p>
        <p v-else-if="storesError" class="text-[11px] text-amber-300" data-testid="secret-ref-stores-error">{{ storesError }}</p>
        <p v-else-if="!stores.length" class="text-[11px] text-amber-300" data-testid="secret-ref-no-stores">{{ t('secretEnv.ref.noStores') }}</p>
        <div class="grid grid-cols-2 gap-2">
          <input
            v-model="refName" class="input font-mono text-xs" :placeholder="t('secretEnv.namePlaceholder')" :disabled="!!refEditing"
            autocomplete="off" spellcheck="false" data-testid="secret-ref-name"
          />
          <select v-model="refStore" class="input text-xs" data-testid="secret-ref-store">
            <option value="" disabled>{{ t('secretEnv.ref.storePlaceholder') }}</option>
            <option v-for="s in stores ?? []" :key="s.id" :value="s.id">{{ s.name }} ({{ t(`secretStores.providers.${s.provider}`) }})</option>
          </select>
          <input
            v-model="refPath" class="input font-mono text-xs" :placeholder="t('secretEnv.ref.pathPlaceholder')"
            autocomplete="off" spellcheck="false" data-testid="secret-ref-path"
          />
          <input
            v-model="refKey" class="input font-mono text-xs" :placeholder="t('secretEnv.ref.keyPlaceholder')"
            autocomplete="off" spellcheck="false" data-testid="secret-ref-key" @keydown.enter.prevent="saveReference"
          />
        </div>
        <p v-if="refError" class="text-xs text-red-400" data-testid="secret-ref-error">{{ refError }}</p>
        <div class="flex gap-2">
          <button type="button" class="btn-primary text-xs" :disabled="!!busy" data-testid="secret-ref-save" @click="saveReference">{{ t('secretEnv.save') }}</button>
          <button type="button" class="btn-secondary text-xs" @click="closeReference">{{ t('secretEnv.cancel') }}</button>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
.icon-btn {
  @apply p-1 rounded-sm text-gray-400 hover:text-gray-100 transition-colors disabled:opacity-50;
}
</style>
