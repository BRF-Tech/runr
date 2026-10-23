<script setup>
import TermText from '@/components/TermText.vue'
// Administration → Secret stores (docs/secret-stores.md): the organization's Vault / OpenBao, AWS
// Secrets Manager, GCP Secret Manager and Azure Key Vault stores. A store never gets a credential from
// RunR: it trusts the region cluster's service account issuer and the service account RunR names
// (the Trust panel shows both per region). Project secrets refer to a store (path + key);
// External Secrets Operator syncs them in the region.
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { secretStoresApi } from '@/api/secretStores'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import {
  PROVIDERS, MULTILINE_SETTINGS, emptySettings, providerSettings, providerAuth, storePayload,
  isValidStoreName, trustSnippets,
} from '@/utils/secretStores'
import { LockKeyhole, Plus, Pencil, Trash2, ShieldCheck, Copy, RefreshCw, AlertTriangle } from 'lucide-vue-next'

const { t, te, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const perms = usePermissionsStore()

const canManage = computed(() => perms.can('secret_stores.manage'))

const stores = ref([])
const providers = ref([])
const regions = ref([])
const loading = ref(false)
const loadError = ref(null)

// Create / edit form
const formOpen = ref(false)
const editing = ref(null)          // the store being edited (null: a new one)
const saving = ref(false)
const errors = ref({})
const formError = ref(null)
const form = reactive({ name: '', provider: 'vault', auth: 'kubernetes', settings: {}, refresh_interval: '' })

// Trust panel
const trustStore = ref(null)
const trustRegion = ref('')
const trust = ref(null)
const trustLoading = ref(false)
const trustError = ref(null)
const verifying = ref(false)
const verifyResult = ref(null)

const formFields = computed(() => providerSettings(form.provider, providers.value))
const authOptions = computed(() => providerAuth(form.provider, providers.value))
const providerOptions = computed(() => providers.value.length ? providers.value.map(p => p.provider) : PROVIDERS)
const canSave = computed(() => !saving.value && isValidStoreName(form.name.trim())
  && Object.entries(formFields.value).every(([key, required]) => !required || editing.value || String(form.settings[key] ?? '').trim() !== ''))
const regionNames = computed(() => Object.fromEntries(regions.value.map(r => [r.id, r.name])))
const snippets = computed(() => trustSnippets(trustStore.value, trust.value))

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await secretStoresApi.list()
    stores.value = data?.data ?? []
    providers.value = data?.providers ?? []
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? t('secretStores.loadError')
  } finally {
    loading.value = false
  }
}

async function loadRegions() {
  try {
    const { data } = await knativeRegionsApi.list()
    regions.value = (data?.data ?? data ?? []).filter(r => !r.is_demo)
  } catch {
    regions.value = []
  }
}

// ── Create / edit ────────────────────────────────────────────────────────────────────────
function openCreate() {
  editing.value = null
  form.name = ''
  form.provider = providerOptions.value[0] ?? 'vault'
  onProviderChange()
  form.refresh_interval = ''
  errors.value = {}
  formError.value = null
  formOpen.value = true
}

function openEdit(store) {
  editing.value = store
  form.name = store.name
  form.provider = store.provider
  form.auth = store.auth
  form.settings = { ...emptySettings(store.provider, providers.value), ...(store.settings ?? {}) }
  form.refresh_interval = store.refresh_interval ?? ''
  errors.value = {}
  formError.value = null
  formOpen.value = true
}

function onProviderChange() {
  form.auth = authOptions.value[0] ?? ''
  form.settings = emptySettings(form.provider, providers.value)
}

function fieldError(key) {
  return errors.value?.[key]?.[0] ?? null
}

// An example value for the settings that have one
function placeholder(key) {
  const path = `secretStores.placeholders.${key}`
  return te(path) ? t(path) : ''
}

async function save() {
  if (!canSave.value) return
  saving.value = true
  errors.value = {}
  formError.value = null
  try {
    const payload = storePayload(form, providers.value, { editing: !!editing.value })
    if (editing.value) await secretStoresApi.update(editing.value.id, payload)
    else await secretStoresApi.create(payload)
    toast.success(t(editing.value ? 'secretStores.updated' : 'secretStores.created'))
    formOpen.value = false
    editing.value = null
    await load()
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    formError.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

async function remove(store) {
  const ok = await confirm({
    title: t('secretStores.deleteTitle'),
    message: t('secretStores.deleteConfirm', { name: store.name }),
    destructive: true,
    confirmLabel: t('secretStores.delete'),
  })
  if (!ok) return
  try {
    await secretStoresApi.remove(store.id)
    toast.success(t('secretStores.deleted'))
    if (trustStore.value?.id === store.id) closeTrust()
    await load()
  } catch (e) {
    // 409: project secrets still refer to it (the API names the projects)
    toast.error(e?.response?.data?.message ?? t('common.error'), 6000)
  }
}

// ── Trust ────────────────────────────────────────────────────────────────────────────────
function openTrust(store) {
  trustStore.value = store
  trust.value = null
  trustError.value = null
  verifyResult.value = null
  trustRegion.value = trustRegion.value || regions.value[0]?.id || ''
  if (trustRegion.value) loadTrust()
}

function closeTrust() {
  trustStore.value = null
  trust.value = null
}

async function loadTrust() {
  if (!trustStore.value || !trustRegion.value) return
  trustLoading.value = true
  trustError.value = null
  verifyResult.value = null
  try {
    const { data } = await secretStoresApi.trust(trustStore.value.id, trustRegion.value)
    trust.value = data?.data ?? null
  } catch (e) {
    trust.value = null
    trustError.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    trustLoading.value = false
  }
}

async function verify() {
  if (!trustStore.value || !trustRegion.value) return
  verifying.value = true
  verifyResult.value = null
  try {
    const { data } = await secretStoresApi.verify(trustStore.value.id, trustRegion.value)
    verifyResult.value = data?.data ?? null
    await load()
  } catch (e) {
    verifyResult.value = { status: 'failed', message: e?.response?.data?.message ?? t('common.error') }
  } finally {
    verifying.value = false
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(t('secretStores.copied'))
  } catch {
    toast.error(t('secretStores.copyFailed'))
  }
}

function jwksText(value) {
  return JSON.stringify(value ?? { keys: [] }, null, 2)
}

function fmtDate(value) {
  return value ? new Date(value).toLocaleString(locale.value) : ''
}

const statusClasses = {
  ready:   'bg-emerald-900/30 border-emerald-800/60 text-emerald-300',
  failed:  'bg-red-900/30 border-red-800/60 text-red-300',
  pending: 'bg-gray-800 border-gray-700 text-gray-400',
}

function regionStatuses(store) {
  return Object.entries(store.regions ?? {}).map(([id, s]) => ({ id, name: regionNames.value[id] ?? id, ...s }))
}

onMounted(() => {
  load()
  loadRegions()
})
defineExpose({ load })
</script>

<template>
  <div class="space-y-6" data-testid="secret-stores">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('secretStores.title') }}</h3>
        </div>
        <p class="text-xs text-gray-400 mt-2 max-w-3xl">{{ t('secretStores.intro') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button type="button" class="btn btn-secondary text-xs" :title="t('secretStores.refresh')" @click="load">
          <RefreshCw class="w-3.5 h-3.5" />
        </button>
        <button v-if="canManage && !formOpen" type="button" class="btn btn-primary text-xs" data-testid="secret-store-create" @click="openCreate">
          <Plus class="w-3.5 h-3.5" /> {{ t('secretStores.create') }}
        </button>
      </div>
    </div>

    <!-- Create / edit ──────────────────────────────────────────────────────────────── -->
    <form v-if="formOpen" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-3" data-testid="secret-store-form" @submit.prevent="save">
      <p class="text-sm font-medium text-gray-100">{{ editing ? t('secretStores.editTitle', { name: editing.name }) : t('secretStores.createTitle') }}</p>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="label">{{ t('secretStores.fields.name') }}</label>
          <input v-model="form.name" class="input font-mono text-xs" placeholder="team-vault" autocomplete="off" spellcheck="false" data-field="name" />
          <p v-if="form.name && !isValidStoreName(form.name.trim())" class="text-xs text-red-400 mt-1">{{ t('secretStores.nameInvalid') }}</p>
          <p v-else-if="fieldError('name')" class="text-xs text-red-400 mt-1">{{ fieldError('name') }}</p>
        </div>
        <div>
          <label class="label">{{ t('secretStores.fields.provider') }}</label>
          <select v-model="form.provider" class="input text-xs" :disabled="!!editing" data-field="provider" @change="onProviderChange">
            <option v-for="p in providerOptions" :key="p" :value="p">{{ t(`secretStores.providers.${p}`) }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('secretStores.fields.auth') }}</label>
          <select v-model="form.auth" class="input text-xs" data-field="auth">
            <option v-for="a in authOptions" :key="a" :value="a">{{ t(`secretStores.auth.${a}`) }}</option>
          </select>
        </div>
      </div>
      <p class="text-[11px] text-gray-500">{{ t(`secretStores.authHint.${form.auth}`, t('secretStores.authHint.default')) }}</p>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="(required, key) in formFields" :key="key" :class="MULTILINE_SETTINGS.includes(key) && 'md:col-span-2'">
          <label class="label">
            <TermText :path="`secretStores.settings.${key}`" />
            <span v-if="required" class="normal-case text-red-500/70">*</span>
            <span v-else class="normal-case text-gray-400">{{ t('common.optional') }}</span>
          </label>
          <textarea
            v-if="MULTILINE_SETTINGS.includes(key)" v-model="form.settings[key]" rows="4" class="input font-mono text-xs"
            :placeholder="placeholder(key)" :data-field="`settings.${key}`"
          />
          <input
            v-else v-model="form.settings[key]" class="input font-mono text-xs" autocomplete="off" spellcheck="false"
            :placeholder="placeholder(key)" :data-field="`settings.${key}`"
          />
          <p v-if="fieldError(`settings.${key}`)" class="text-xs text-red-400 mt-1">{{ fieldError(`settings.${key}`) }}</p>
        </div>
        <div>
          <label class="label">{{ t('secretStores.fields.refreshInterval') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
          <input v-model="form.refresh_interval" type="number" min="60" max="86400" class="input font-mono text-xs" placeholder="3600" data-field="refresh_interval" />
        </div>
      </div>
      <p class="text-[11px] text-gray-500">{{ t('secretStores.noCredentials') }}</p>

      <p v-if="formError" class="text-xs text-red-400" data-testid="secret-store-error">{{ formError }}</p>
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary text-xs" :disabled="!canSave" data-testid="secret-store-save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <button type="button" class="btn btn-secondary text-xs" @click="formOpen = false">{{ t('common.cancel') }}</button>
      </div>
    </form>

    <!-- List ───────────────────────────────────────────────────────────────────────── -->
    <p v-if="loading && !stores.length" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="text-xs text-red-400">{{ loadError }}</p>
    <div v-else-if="!stores.length" class="rounded-xl border border-dashed border-gray-800 p-6 text-center text-xs text-gray-400" data-testid="secret-stores-empty">
      {{ t('secretStores.empty') }}
    </div>
    <div v-else class="space-y-2">
      <div
        v-for="store in stores" :key="store.id"
        class="rounded-xl border border-gray-800 bg-gray-900/60 p-3" :data-testid="`secret-store-${store.name}`"
      >
        <div class="flex items-center gap-3">
          <LockKeyhole class="w-4 h-4 text-gray-400 shrink-0" />
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-100 font-mono truncate">{{ store.name }}</p>
            <p class="text-[11px] text-gray-500">
              {{ t(`secretStores.providers.${store.provider}`) }} · {{ t(`secretStores.auth.${store.auth}`) }}
              · {{ t('secretStores.references', { count: store.references ?? 0 }, store.references ?? 0) }}
            </p>
          </div>
          <button type="button" class="btn btn-secondary text-xs" :data-testid="`secret-store-trust-${store.name}`" @click="openTrust(store)">
            <ShieldCheck class="w-3.5 h-3.5" /> {{ t('secretStores.trust.open') }}
          </button>
          <template v-if="canManage">
            <button type="button" class="icon-btn" :title="t('secretStores.edit')" :data-testid="`secret-store-edit-${store.name}`" @click="openEdit(store)">
              <Pencil class="w-3.5 h-3.5" />
            </button>
            <button type="button" class="icon-btn hover:text-red-400" :title="t('secretStores.delete')" :data-testid="`secret-store-delete-${store.name}`" @click="remove(store)">
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </template>
        </div>
        <div v-if="regionStatuses(store).length" class="flex flex-wrap gap-1.5 mt-2">
          <span
            v-for="r in regionStatuses(store)" :key="r.id"
            class="text-[10px] px-1.5 py-0.5 rounded-sm border" :class="statusClasses[r.status] ?? statusClasses.pending"
            :title="[r.message, r.checked_at && t('secretStores.checkedAt', { date: fmtDate(r.checked_at) })].filter(Boolean).join(' — ')"
            :data-testid="`secret-store-region-${store.name}-${r.id}`"
          >{{ r.name }}: {{ t(`secretStores.status.${r.status}`, r.status) }}</span>
        </div>
      </div>
    </div>

    <!-- Trust ──────────────────────────────────────────────────────────────────────── -->
    <section v-if="trustStore" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-3" data-testid="secret-store-trust">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-sm font-medium text-gray-100">{{ t('secretStores.trust.title', { name: trustStore.name }) }}</p>
          <p class="text-[11px] text-gray-500">{{ t('secretStores.trust.intro') }}</p>
        </div>
        <button type="button" class="btn btn-secondary text-xs" @click="closeTrust">{{ t('common.close') }}</button>
      </div>

      <div class="flex items-end gap-2">
        <div class="flex-1">
          <label class="label">{{ t('secretStores.trust.region') }}</label>
          <select v-model="trustRegion" class="input text-xs" data-testid="secret-store-trust-region" @change="loadTrust">
            <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <button v-if="canManage" type="button" class="btn btn-primary text-xs" :disabled="!trustRegion || verifying" data-testid="secret-store-verify" @click="verify">
          {{ verifying ? t('secretStores.trust.verifying') : t('secretStores.trust.verify') }}
        </button>
      </div>
      <p v-if="!regions.length" class="text-xs text-gray-400">{{ t('secretStores.trust.noRegions') }}</p>

      <p
        v-if="verifyResult" class="text-xs rounded-sm px-3 py-2 border" :class="statusClasses[verifyResult.status] ?? statusClasses.pending"
        data-testid="secret-store-verify-result"
      >
        {{ t(`secretStores.status.${verifyResult.status}`, verifyResult.status) }}<template v-if="verifyResult.message"> — {{ verifyResult.message }}</template>
      </p>

      <p v-if="trustLoading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
      <p v-else-if="trustError" class="text-xs text-red-400" data-testid="secret-store-trust-error">{{ trustError }}</p>
      <template v-else-if="trust">
        <p v-if="trust.eso_status && trust.eso_status !== 'ready'" class="text-xs text-amber-300 bg-amber-900/20 rounded-sm px-3 py-2" data-testid="secret-store-trust-eso">
          {{ t('secretStores.trust.esoMissing') }}
        </p>

        <dl class="grid grid-cols-1 md:grid-cols-[10rem_1fr] gap-x-3 gap-y-1.5 text-xs">
          <dt class="text-gray-500">{{ t('secretStores.trust.issuer') }}</dt>
          <dd class="font-mono text-gray-200 break-all flex items-center gap-1.5" data-testid="secret-store-trust-issuer">
            {{ trust.issuer }}
            <button type="button" class="icon-btn" :title="t('secretStores.copy')" @click="copy(trust.issuer)"><Copy class="w-3 h-3" /></button>
          </dd>
          <dt class="text-gray-500">{{ t('secretStores.trust.namespace') }}</dt>
          <dd class="font-mono text-gray-200" data-testid="secret-store-trust-namespace">{{ trust.namespace }}</dd>
          <dt class="text-gray-500">{{ t('secretStores.trust.serviceAccount') }}</dt>
          <dd class="font-mono text-gray-200" data-testid="secret-store-trust-sa">{{ trust.service_account }}</dd>
          <dt class="text-gray-500">{{ t('secretStores.trust.subject') }}</dt>
          <dd class="font-mono text-gray-200 break-all flex items-center gap-1.5" data-testid="secret-store-trust-subject">
            {{ trust.subject }}
            <button type="button" class="icon-btn" :title="t('secretStores.copy')" @click="copy(trust.subject)"><Copy class="w-3 h-3" /></button>
          </dd>
          <template v-if="trust.audience">
            <dt class="text-gray-500">{{ t('secretStores.trust.audience') }}</dt>
            <dd class="font-mono text-gray-200" data-testid="secret-store-trust-audience">{{ trust.audience }}</dd>
          </template>
        </dl>

        <p v-if="trust.publicly_reachable === false" class="text-xs text-amber-300 bg-amber-900/20 rounded-sm px-3 py-2 flex gap-2" data-testid="secret-store-trust-private">
          <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ t('secretStores.trust.notPublic') }}
        </p>

        <details class="text-xs" open>
          <summary class="cursor-pointer select-none text-gray-400 hover:text-gray-200">{{ t('secretStores.trust.jwks') }}</summary>
          <div class="relative mt-2">
            <pre class="bg-gray-950 rounded-sm px-3 py-2 font-mono text-[11px] text-gray-300 max-h-48 overflow-auto" data-testid="secret-store-trust-jwks">{{ jwksText(trust.jwks) }}</pre>
            <button type="button" class="icon-btn absolute top-1 right-1" :title="t('secretStores.copy')" @click="copy(jwksText(trust.jwks))"><Copy class="w-3 h-3" /></button>
          </div>
        </details>
        <details v-if="trust.pem_keys?.length" class="text-xs">
          <summary class="cursor-pointer select-none text-gray-400 hover:text-gray-200">{{ t('secretStores.trust.pemKeys', { count: trust.pem_keys.length }) }}</summary>
          <div class="relative mt-2">
            <pre class="bg-gray-950 rounded-sm px-3 py-2 font-mono text-[11px] text-gray-300 max-h-48 overflow-auto" data-testid="secret-store-trust-pem">{{ trust.pem_keys.join('\n') }}</pre>
            <button type="button" class="icon-btn absolute top-1 right-1" :title="t('secretStores.copy')" @click="copy(trust.pem_keys.join('\n'))"><Copy class="w-3 h-3" /></button>
          </div>
        </details>

        <div v-if="snippets.length" class="space-y-2">
          <p class="text-xs text-gray-400 font-medium">{{ t('secretStores.trust.setup') }}</p>
          <div v-for="snippet in snippets" :key="snippet.key" :data-testid="`secret-store-snippet-${snippet.key}`">
            <p class="text-[11px] text-gray-500 mb-1">{{ t(`secretStores.trust.snippets.${snippet.key}`) }}</p>
            <div class="relative">
              <pre class="bg-gray-950 rounded-sm px-3 py-2 font-mono text-[11px] text-gray-300 overflow-auto whitespace-pre">{{ snippet.text }}</pre>
              <button type="button" class="icon-btn absolute top-1 right-1" :title="t('secretStores.copy')" @click="copy(snippet.text)"><Copy class="w-3 h-3" /></button>
            </div>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>

<style scoped>
@reference "../../assets/main.css";
.icon-btn {
  @apply p-1 rounded-sm text-gray-400 hover:text-gray-100 transition-colors disabled:opacity-50;
}
</style>
