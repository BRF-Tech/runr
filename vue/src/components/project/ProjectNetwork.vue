<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { usePermissionsStore } from '@/stores/permissions'
import { Network, Copy, Check, Trash2, Plus, AlertTriangle, Info, Users } from 'lucide-vue-next'

// A project's network (docs/internal-network.md): who reaches it from outside the cluster (access
// mode) and which projects of the region may call it (the allow list). Changes: network.manage.
const props = defineProps({
  projectId: { type: [String, Number], required: true },
})
const canManage = computed(() => usePermissionsStore().can('network.manage', String(props.projectId)))

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

// ── State ────────────────────────────────────────────────────────────────────
const network   = ref(null)
const loading   = ref(true)
const loadError = ref(null)

const mode        = ref('public')
const cidrsText   = ref('')
const saving      = ref(false)
const saveError   = ref(null)
const fieldErrors = ref({})

const newCaller = ref('')
const adding    = ref(false)
const addError  = ref(null)
const removing  = ref(null)
const copiedKey = ref(null)

const MODES = ['public', 'allowlist', 'internal']

const cidrs = computed(() => cidrsText.value.split(/[\s,]+/).map(s => s.trim()).filter(Boolean))
const dirty = computed(() => {
  if (!network.value) return false
  if (mode.value !== network.value.access_mode) return true
  return mode.value === 'allowlist' && cidrs.value.join(',') !== (network.value.allowed_cidrs ?? []).join(',')
})
const curlExample = computed(() => network.value
  ? `curl -H "${network.value.token_header}: $${network.value.token_env}" ${network.value.internal_url}/`
  : '')

// ── Loading ──────────────────────────────────────────────────────────────────
function show(data) {
  network.value   = { ...data, callers: data.callers ?? [] }
  mode.value      = data.access_mode ?? 'public'
  cidrsText.value = (data.allowed_cidrs ?? []).join('\n')
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const { data } = await projectsApi.network(props.projectId)
    show(data.data ?? data)
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('network.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.projectId, load)

// ── Access mode ──────────────────────────────────────────────────────────────
const fieldError = key => (fieldErrors.value[key] ?? Object.entries(fieldErrors.value).find(([k]) => k.startsWith(key + '.'))?.[1])?.[0]

async function save() {
  if (!dirty.value || saving.value) return
  // internal takes the public URL away (custom domains and the gateway route with it)
  if (mode.value === 'internal' && network.value.access_mode !== 'internal') {
    const ok = await confirm({
      title:        t('network.access.internalConfirmTitle'),
      message:      t('network.access.internalConfirm'),
      confirmLabel: t('network.access.internalConfirmLabel'),
      destructive:  true,
    })
    if (!ok) return
  }
  saving.value = true
  saveError.value = null
  fieldErrors.value = {}
  try {
    const { data } = await projectsApi.updateNetwork(props.projectId, {
      access_mode:   mode.value,
      allowed_cidrs: mode.value === 'allowlist' ? cidrs.value : null,
    })
    show(data.data)
    if (data.warning) toast.error(data.warning, 6000)
    else toast.success(data.message ?? t('network.saved'))
  } catch (e) {
    const body = e.response?.data ?? {}
    fieldErrors.value = body.errors ?? {}
    saveError.value = Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

// ── Allow list ───────────────────────────────────────────────────────────────
async function addCaller() {
  const value = newCaller.value.trim()
  if (!value || adding.value) return
  adding.value = true
  addError.value = null
  try {
    const { data } = await projectsApi.addNetworkCaller(props.projectId, value)
    const row = data.data
    network.value.callers = [...network.value.callers.filter(c => c.caller !== row.caller), row]
      .sort((a, b) => a.caller.localeCompare(b.caller))
    newCaller.value = ''
    if (data.warning) toast.error(data.warning, 6000)
    else toast.success(row.cross_tenant ? t('network.callers.grantAdded', { caller: row.caller }) : t('network.callers.added', { caller: row.caller }))
  } catch (e) {
    const body = e.response?.data ?? {}
    addError.value = body.errors?.caller?.[0] ?? Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? t('common.error')
  } finally {
    adding.value = false
  }
}

async function removeCaller(c) {
  if (removing.value) return
  const ok = await confirm({
    title:        t('network.callers.removeTitle'),
    message:      t('network.callers.removeConfirm', { caller: c.caller }),
    confirmLabel: t('network.callers.remove'),
    destructive:  true,
  })
  if (!ok) return
  removing.value = c.caller
  try {
    await projectsApi.removeNetworkCaller(props.projectId, c.caller)
    network.value.callers = network.value.callers.filter(x => x.caller !== c.caller)
    toast.success(t('network.callers.removed', { caller: c.caller }))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    removing.value = null
  }
}

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(String(text))
    copiedKey.value = key
    setTimeout(() => { if (copiedKey.value === key) copiedKey.value = null }, 1500)
  } catch {
    toast.error(t('network.copyFailed'))
  }
}
</script>

<template>
  <div class="space-y-4" data-testid="project-network">

    <!-- Header -->
    <div class="min-w-0">
      <div class="flex items-center gap-2">
        <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <span class="text-[15px] font-semibold text-gray-100">{{ t('network.title') }}</span>
      </div>
      <p class="text-xs text-gray-400 mt-1.5 ml-[10px]">{{ t('network.intro') }}</p>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="loadError" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3">
      <p class="text-xs text-red-400">{{ loadError }}</p>
    </div>

    <template v-else-if="network">
      <!-- The region audits -->
      <div v-if="network.enforced === false" class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-4 py-3" data-testid="network-audit-note">
        <Info class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
        <p class="text-xs text-yellow-300 leading-relaxed">{{ t('network.auditNote') }}</p>
      </div>

      <!-- Access mode -->
      <div class="bg-gray-900 border border-gray-800 rounded-md px-4 py-4 space-y-3">
        <div>
          <p class="text-sm font-medium text-gray-100">{{ t('network.access.title') }}</p>
          <p class="text-xs text-gray-400 mt-0.5">{{ t('network.access.intro') }}</p>
        </div>
        <div class="space-y-2">
          <label
            v-for="m in MODES" :key="m"
            class="flex items-start gap-2.5 rounded-md border px-3 py-2.5 transition-colors"
            :class="[
              mode === m ? 'border-brand-500/50 bg-brand-900/20' : 'border-gray-800',
              canManage ? 'cursor-pointer hover:border-gray-700' : 'cursor-not-allowed',
            ]"
          >
            <input
              v-model="mode" type="radio" :value="m" :disabled="!canManage || saving"
              :data-testid="`network-mode-${m}`"
              class="mt-0.5 accent-brand-500 focus:ring-brand-500 bg-gray-800 border-gray-700"
            />
            <span class="min-w-0">
              <span class="block text-sm text-gray-100">{{ t(`network.access.${m}`) }}</span>
              <span class="block text-xs text-gray-400 mt-0.5">{{ t(`network.access.${m}Hint`) }}</span>
            </span>
          </label>
        </div>

        <div v-if="mode === 'allowlist'">
          <label class="label" for="network-cidrs">{{ t('network.access.cidrs') }}</label>
          <textarea
            id="network-cidrs" v-model="cidrsText" rows="3" spellcheck="false"
            class="input font-mono text-xs resize-y" :disabled="!canManage || saving"
            placeholder="203.0.113.0/24&#10;198.51.100.7" data-testid="network-cidrs"
          />
          <p class="text-[11px] text-gray-400 mt-1">{{ t('network.access.cidrsHint') }}</p>
          <p v-if="fieldError('allowed_cidrs')" class="text-xs text-red-400 mt-1">{{ fieldError('allowed_cidrs') }}</p>
        </div>

        <div v-if="canManage" class="flex items-center gap-3">
          <button type="button" class="btn-primary text-xs" :disabled="!dirty || saving" data-testid="network-save" @click="save">
            {{ saving ? t('common.saving') : t('network.access.save') }}
          </button>
          <span v-if="saveError" class="text-xs text-red-400" data-testid="network-save-error">{{ saveError }}</span>
        </div>
      </div>

      <!-- Internal address -->
      <div class="bg-gray-900 border border-gray-800 rounded-md px-4 py-4 space-y-3">
        <div class="flex items-center gap-2">
          <Network class="w-4 h-4 text-gray-400" />
          <p class="text-sm font-medium text-gray-100">{{ t('network.internal.title') }}</p>
        </div>
        <p class="text-xs text-gray-400">{{ t('network.internal.intro', { header: network.token_header, env: network.token_env }) }}</p>
        <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5 text-xs">
          <dt class="text-gray-400">{{ t('network.internal.name') }}</dt>
          <dd class="font-mono text-gray-100 break-all" data-testid="network-internal-name">{{ network.internal_name }}</dd>
          <dt class="text-gray-400">{{ t('network.internal.url') }}</dt>
          <dd class="flex items-start gap-1.5">
            <span class="font-mono text-gray-100 break-all" data-testid="network-internal-url">{{ network.internal_url }}</span>
            <button type="button" class="shrink-0 mt-px text-gray-400 hover:text-gray-100" :title="t('network.copy')" @click="copy(network.internal_url, 'url')">
              <Check v-if="copiedKey === 'url'" class="w-3.5 h-3.5 text-green-400" /><Copy v-else class="w-3.5 h-3.5" />
            </button>
          </dd>
        </dl>
        <div class="flex items-start gap-1.5 rounded-md border border-gray-800 bg-gray-950/40 px-3 py-2">
          <code class="flex-1 font-mono text-[11px] text-gray-300 break-all" data-testid="network-curl">{{ curlExample }}</code>
          <button type="button" class="shrink-0 text-gray-400 hover:text-gray-100" :title="t('network.copy')" @click="copy(curlExample, 'curl')">
            <Check v-if="copiedKey === 'curl'" class="w-3.5 h-3.5 text-green-400" /><Copy v-else class="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      <!-- Allowed callers -->
      <div class="bg-gray-900 border border-gray-800 rounded-md px-4 py-4 space-y-3">
        <div class="flex items-center gap-2">
          <Users class="w-4 h-4 text-gray-400" />
          <p class="text-sm font-medium text-gray-100">{{ t('network.callers.title') }}</p>
        </div>
        <p class="text-xs text-gray-400">{{ t('network.callers.intro', { org: network.organization }) }}</p>
        <div v-if="!network.cross_tenant_grants" class="flex items-start gap-2 text-xs text-yellow-300" data-testid="network-cross-off">
          <AlertTriangle class="w-3.5 h-3.5 text-yellow-400 mt-0.5 shrink-0" />
          <span>{{ t('network.callers.crossOff') }}</span>
        </div>

        <form v-if="canManage" class="max-w-xl" @submit.prevent="addCaller">
          <div class="flex items-start gap-2">
            <div class="flex-1 min-w-0">
              <input
                v-model="newCaller" type="text" class="input font-mono" :class="addError ? 'border-red-700!' : ''"
                :placeholder="t('network.callers.placeholder', { org: network.organization })" :disabled="adding"
                autocomplete="off" autocapitalize="off" spellcheck="false" data-testid="network-caller-input"
                @input="addError = null"
              />
              <p v-if="addError" class="text-xs text-red-400 mt-1" data-testid="network-caller-error">{{ addError }}</p>
            </div>
            <button type="submit" class="btn-primary h-9 shrink-0" :disabled="adding || !newCaller.trim()" data-testid="network-caller-add">
              <Plus class="w-3.5 h-3.5" />{{ adding ? t('network.callers.adding') : t('network.callers.add') }}
            </button>
          </div>
        </form>

        <p v-if="!network.callers.length" class="text-xs text-gray-400 py-2" data-testid="network-callers-empty">{{ t('network.callers.empty') }}</p>
        <ul v-else class="divide-y divide-gray-800 rounded-md border border-gray-800">
          <li v-for="c in network.callers" :key="c.caller" class="flex items-center gap-2.5 px-3 py-2" :data-caller="c.caller">
            <span class="font-mono text-sm text-gray-100 break-all">{{ c.caller }}</span>
            <span
              v-if="c.cross_tenant"
              class="inline-flex items-center px-2 py-0.5 rounded-sm text-[10px] font-medium border bg-amber-900/30 text-amber-300 border-amber-800/50"
            >{{ t('network.callers.grant', { org: c.organization }) }}</span>
            <button
              v-if="canManage" type="button" class="ml-auto btn-secondary text-xs px-2.5 py-1 text-red-400 hover:text-red-300"
              :disabled="removing === c.caller" :title="t('network.callers.remove')" data-testid="network-caller-remove"
              @click="removeCaller(c)"
            >
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
