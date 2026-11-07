<script setup>
// Add / edit a runtime source (docs/runtimes.md#runtime-sources): a Git repository with
// <path>/<id>/runtime.yaml or an OCI catalog, public or private (an HTTPS token or SSH deploy key,
// registry credentials), its cosign key and patch feed, how often it is read. Test connection
// reads it as entered without saving. Credentials are write-only: a stored one shows as set and is
// kept, replaced or removed. RunR's catalog (the default source) only changes interval and on/off.
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { GitBranch, Package, Loader2, CheckCircle2, XCircle, AlertTriangle, ShieldCheck, PlugZap } from 'lucide-vue-next'
import SourceSecretField from '@/components/settings/runtimes/SourceSecretField.vue'
import TermText from '@/components/TermText.vue'
import { runtimeSourcesApi } from '@/api/runtimeSources'
import { apiErrorMessage } from '@/composables/useRuntimesManager'
import { shortRevision, SOURCE_FAILED } from '@/composables/useRuntimeSources'
import { useRuntimeSourceTutorial } from '@/composables/useTutorial'

const props = defineProps({
  source: { type: Object, default: null }, // null = add
  // where a new source is connected: platform (runtimes.manage) or the organization
  scope:  { type: String, default: 'platform' },
})
const emit = defineEmits(['close', 'saved'])

const { t } = useI18n()
const { start: startTutorial } = useRuntimeSourceTutorial()

const isEdit    = computed(() => !!props.source)
const isDefault = computed(() => !!props.source?.is_default)
const stored    = computed(() => props.source?.credentials ?? {})

const form = reactive({
  name:              props.source?.name ?? '',
  kind:              props.source?.kind ?? 'git',
  url:               props.source?.url ?? '',
  ref:               props.source?.ref ?? '',
  path:              props.source?.path ?? '',
  public_key:        props.source?.public_key ?? '',
  patch_feed:        props.source?.patch_feed ?? '',
  registry_host:     props.source?.registry_host ?? '',
  interval_minutes:  props.source?.interval_minutes ?? 60,
  is_enabled:        props.source?.is_enabled ?? true,
  git_username:      props.source?.credentials?.git_username ?? '',
  registry_username: props.source?.credentials?.registry_username ?? '',
})

// Git authentication: none (a public repository), an HTTPS token, an SSH deploy key
const gitAuth = ref(stored.value.git === 'token' ? 'token' : stored.value.git === 'ssh' ? 'ssh' : 'none')

// Write-only values: kept while set, typed when new
const secretState = (set) => ({ mode: set ? 'keep' : 'replace', value: '' })
const secrets = reactive({
  git_token:         secretState(stored.value.git === 'token'),
  ssh_private_key:   secretState(!!stored.value.ssh_key_set),
  ssh_known_hosts:   secretState(!!stored.value.known_hosts_set),
  registry_password: secretState(!!stored.value.registry_password_set),
})

const saving      = ref(false)
const testing     = ref(false)
const saveError   = ref(null)
const fieldErrors = ref({})
const testResult  = ref(null)
const testError   = ref(null)

const isSshUrl = computed(() => /^(ssh:\/\/|[\w.-]+@[\w.-]+:)/.test(form.url.trim()))
// An SSH deploy key needs an SSH URL, a token an https:// URL (the API refuses the other pairs)
const authMismatch = computed(() => {
  if (form.kind !== 'git' || !form.url.trim()) return null
  if (gitAuth.value === 'ssh' && !isSshUrl.value) return t('runtimes.sources.form.sshNeedsSshUrl')
  if (gitAuth.value === 'token' && isSshUrl.value) return t('runtimes.sources.form.tokenNeedsHttpsUrl')
  return null
})

const title = computed(() => {
  if (!isEdit.value) return t('runtimes.sources.form.titleAdd')
  return t('runtimes.sources.form.titleEdit', { name: props.source.name })
})
const scopeHint = computed(() => {
  if (isDefault.value) return t('runtimes.sources.form.defaultHint')
  const scope = props.source?.scope ?? props.scope
  return scope === 'organization' ? t('runtimes.sources.form.scopeOrganization') : t('runtimes.sources.form.scopePlatform')
})

const err = (field) => fieldErrors.value[field] ?? null

const GIT_FIELDS = { git_token: null, git_username: null, ssh_private_key: null, ssh_known_hosts: null }
const storedGit = computed(() => !!stored.value.git || !!stored.value.known_hosts_set || !!stored.value.git_username)

function secretInto(payload, field) {
  const s = secrets[field]
  if (s.mode === 'remove') payload[field] = null
  else if (s.mode === 'replace' && s.value.trim() !== '') payload[field] = s.value
}

/** A non-secret field that has a stored value: sent when it changed (empty removes it). */
function changedInto(payload, field, value, before) {
  const v = value.trim()
  if (isEdit.value ? v !== (before ?? '') : v !== '') payload[field] = v || null
}

/**
 * The request body: the source's fields, credentials only when typed (the value) or removed
 * (null); an omitted credential keeps the stored one.
 */
function payload() {
  if (isDefault.value) return { interval_minutes: Number(form.interval_minutes), is_enabled: !!form.is_enabled }

  const p = {
    kind:             form.kind,
    url:              form.url.trim(),
    public_key:       form.public_key.trim() || null,
    patch_feed:       form.patch_feed.trim() || null,
    registry_host:    form.registry_host.trim() || null,
    interval_minutes: Number(form.interval_minutes),
    is_enabled:       !!form.is_enabled,
  }
  if (form.name.trim()) p.name = form.name.trim()

  if (form.kind === 'git') {
    p.ref  = form.ref.trim() || null
    p.path = form.path.trim() || null
    if (gitAuth.value === 'token') {
      secretInto(p, 'git_token')
      changedInto(p, 'git_username', form.git_username, stored.value.git_username)
    } else if (gitAuth.value === 'ssh') {
      secretInto(p, 'ssh_private_key')
      secretInto(p, 'ssh_known_hosts')
    } else if (storedGit.value) {
      Object.assign(p, GIT_FIELDS)
    }
  } else if (storedGit.value) {
    // Git credentials do not apply to an OCI catalog
    Object.assign(p, GIT_FIELDS)
  }

  changedInto(p, 'registry_username', form.registry_username, stored.value.registry_username)
  secretInto(p, 'registry_password')
  return p
}

/** What the form needs before a request: a name and URL, the credential of the chosen auth. */
function check({ forTest = false } = {}) {
  const errors = {}
  if (!isDefault.value) {
    if (!forTest && !form.name.trim()) errors.name = t('runtimes.sources.form.required')
    if (!form.url.trim()) errors.url = t('runtimes.sources.form.required')
    if (form.kind === 'git' && gitAuth.value === 'token' && secrets.git_token.mode !== 'keep' && !secrets.git_token.value.trim()) {
      errors.git_token = t('runtimes.sources.form.tokenRequired')
    }
    if (form.kind === 'git' && gitAuth.value === 'ssh' && secrets.ssh_private_key.mode !== 'keep' && !secrets.ssh_private_key.value.trim()) {
      errors.ssh_private_key = t('runtimes.sources.form.sshKeyRequired')
    }
  }
  const interval = Number(form.interval_minutes)
  if (!Number.isInteger(interval) || interval < 5 || interval > 10080) errors.interval_minutes = t('runtimes.sources.form.intervalRange')
  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

function applyError(e, target) {
  const data = e?.response?.data ?? {}
  if (e?.response?.status === 422 && data.errors) {
    fieldErrors.value = Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]))
    target.value = data.message ?? t('runtimes.sources.form.invalid')
  } else {
    target.value = apiErrorMessage(e, t('common.error'))
  }
}

async function test() {
  testError.value = null
  testResult.value = null
  if (!check({ forTest: true })) return
  testing.value = true
  try {
    const body = { ...payload(), ...(isEdit.value ? { source: props.source.id } : {}) }
    const { data } = await runtimeSourcesApi.test(body)
    testResult.value = data.data ?? null
  } catch (e) {
    applyError(e, testError)
  } finally {
    testing.value = false
  }
}

async function save() {
  saveError.value = null
  if (!check()) return
  saving.value = true
  try {
    const res = isEdit.value
      ? await runtimeSourcesApi.update(props.source.id, payload())
      : await runtimeSourcesApi.create(payload())
    emit('saved', { source: res.data?.data ?? res.data, created: !isEdit.value })
  } catch (e) {
    applyError(e, saveError)
  } finally {
    saving.value = false
  }
}

const testClasses = computed(() => {
  const r = testResult.value
  if (!r) return ''
  if (!r.ok || SOURCE_FAILED.includes(r.status)) return 'border-red-800/60 bg-red-950/20'
  if (r.status === 'partial') return 'border-yellow-800/50 bg-yellow-950/20'
  return 'border-emerald-800/50 bg-emerald-950/20'
})

defineExpose({ payload, test, save, form, secrets, gitAuth })
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-test="runtime-source-modal">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-3xl max-h-[92vh] overflow-y-auto">
      <form class="card space-y-5" @submit.prevent="save">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-100">{{ title }}</h2>
            <p class="text-xs text-gray-400 mt-0.5">{{ scopeHint }}</p>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" :title="t('tutorial.restart')" class="text-gray-400 hover:text-gray-100 transition-colors text-sm p-1" @click="startTutorial()">?</button>
            <button type="button" class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
          </div>
        </div>

        <!-- RunR's catalog: the installation configures it; read-only here -->
        <dl v-if="isDefault" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-xs">
          <dt class="text-gray-400">{{ t('runtimes.sources.form.reference') }}</dt>
          <dd class="font-mono text-gray-100 break-all">{{ source.url || t('runtimes.sources.notConfigured') }}</dd>
          <dt class="text-gray-400">{{ t('runtimes.sources.form.publicKey') }}</dt>
          <dd class="text-gray-100">{{ source.public_key ? t('runtimes.sources.form.keyConfigured') : t('runtimes.sources.form.keyMissing') }}</dd>
        </dl>

        <template v-else>
          <!-- Name + kind -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="label">{{ t('runtimes.sources.form.name') }}</label>
              <input v-model="form.name" class="input" maxlength="64" :placeholder="t('runtimes.sources.form.namePlaceholder')" data-test="source-name" />
              <p v-if="err('name')" class="text-[11px] text-red-400 mt-1">{{ err('name') }}</p>
            </div>
            <div data-tour="runtime-source-kind">
              <label class="label">{{ t('runtimes.sources.form.kind') }}</label>
              <div class="flex gap-2">
                <button type="button" class="chip flex items-center gap-1.5" :class="form.kind === 'git' && 'chip-active'" data-test="source-kind-git" @click="form.kind = 'git'">
                  <GitBranch class="w-3.5 h-3.5" />{{ t('runtimes.sources.kind.git') }}
                </button>
                <button type="button" class="chip flex items-center gap-1.5" :class="form.kind === 'oci-catalog' && 'chip-active'" data-test="source-kind-oci" @click="form.kind = 'oci-catalog'">
                  <Package class="w-3.5 h-3.5" />{{ t('runtimes.sources.kind.oci') }}
                </button>
              </div>
              <p v-if="err('kind')" class="text-[11px] text-red-400 mt-1">{{ err('kind') }}</p>
            </div>
          </div>

          <!-- Where: URL (+ ref and path of a repository) -->
          <section data-tour="runtime-source-location" class="space-y-3">
            <div>
              <label class="label">{{ form.kind === 'git' ? t('runtimes.sources.form.gitUrl') : t('runtimes.sources.form.ociRef') }}</label>
              <input v-model="form.url" class="input font-mono" spellcheck="false" autocomplete="off" :placeholder="form.kind === 'git' ? t('runtimes.sources.form.gitUrlPlaceholder') : t('runtimes.sources.form.ociRefPlaceholder')" data-test="source-url" />
              <p class="text-[11px] text-gray-400 mt-1">{{ form.kind === 'git' ? t('runtimes.sources.form.gitUrlHint') : t('runtimes.sources.form.ociRefHint') }}</p>
              <p v-if="err('url')" class="text-[11px] text-red-400 mt-1">{{ err('url') }}</p>
            </div>
            <div v-if="form.kind === 'git'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="label"><TermText path="runtimes.sources.form.ref" /></label>
                <input v-model="form.ref" class="input font-mono" spellcheck="false" :placeholder="t('runtimes.sources.form.refPlaceholder')" data-test="source-ref" />
                <p v-if="err('ref')" class="text-[11px] text-red-400 mt-1">{{ err('ref') }}</p>
              </div>
              <div>
                <label class="label">{{ t('runtimes.sources.form.path') }}</label>
                <input v-model="form.path" class="input font-mono" spellcheck="false" placeholder="runtimes" data-test="source-path" />
                <p class="text-[11px] text-gray-400 mt-1">{{ t('runtimes.sources.form.pathHint', { path: (form.path.trim() || 'runtimes') + '/<id>/runtime.yaml' }) }}</p>
                <p v-if="err('path')" class="text-[11px] text-red-400 mt-1">{{ err('path') }}</p>
              </div>
            </div>
          </section>

          <!-- Credentials: write-only -->
          <section data-tour="runtime-source-credentials" class="space-y-3">
            <p class="section-title">{{ t('runtimes.sources.form.credentials') }}</p>
            <p class="text-[11px] text-gray-400 -mt-1">{{ t('runtimes.sources.form.credentialsHint') }}</p>
            <template v-if="form.kind === 'git'">
              <div class="flex flex-wrap gap-2">
                <button type="button" class="chip" :class="gitAuth === 'none' && 'chip-active'" data-test="source-auth-none" @click="gitAuth = 'none'">{{ t('runtimes.sources.form.authNone') }}</button>
                <button type="button" class="chip" :class="gitAuth === 'token' && 'chip-active'" data-test="source-auth-token" @click="gitAuth = 'token'">{{ t('runtimes.sources.form.authToken') }}</button>
                <button type="button" class="chip" :class="gitAuth === 'ssh' && 'chip-active'" data-test="source-auth-ssh" @click="gitAuth = 'ssh'">{{ t('runtimes.sources.form.authSsh') }}</button>
              </div>
              <p v-if="authMismatch" class="text-[11px] text-yellow-400" data-test="source-auth-mismatch">{{ authMismatch }}</p>
              <p v-if="err('credentials')" class="text-[11px] text-red-400">{{ err('credentials') }}</p>
              <div v-if="gitAuth === 'token'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="label">{{ t('runtimes.sources.form.gitUsername') }}</label>
                  <input v-model="form.git_username" class="input font-mono" autocomplete="off" placeholder="x-access-token" data-test="source-git-username" />
                  <p v-if="err('git_username')" class="text-[11px] text-red-400 mt-1">{{ err('git_username') }}</p>
                </div>
                <SourceSecretField
                  v-model="secrets.git_token" field="git_token" :label="t('runtimes.sources.form.gitToken')"
                  :set="stored.git === 'token'" :hint="stored.git_token_hint" :error="err('git_token')"
                />
              </div>
              <div v-if="gitAuth === 'ssh'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <SourceSecretField
                  v-model="secrets.ssh_private_key" field="ssh_private_key" :label="t('runtimes.sources.form.sshKey')" multiline
                  placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" :set="!!stored.ssh_key_set" :error="err('ssh_private_key')"
                />
                <div>
                  <SourceSecretField
                    v-model="secrets.ssh_known_hosts" field="ssh_known_hosts" :label="t('runtimes.sources.form.knownHosts')" multiline
                    placeholder="github.com ssh-ed25519 AAAA…" :set="!!stored.known_hosts_set" :error="err('ssh_known_hosts')"
                  />
                  <p class="text-[11px] text-gray-400 mt-1">{{ t('runtimes.sources.form.knownHostsHint') }}</p>
                </div>
              </div>
            </template>

            <!-- Registry: a private catalog / patch feed, the runtime images -->
            <p class="text-[11px] text-gray-300 pt-1">{{ form.kind === 'git' ? t('runtimes.sources.form.registryGit') : t('runtimes.sources.form.registryOci') }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="label"><TermText path="runtimes.sources.form.registryHost" /></label>
                <input v-model="form.registry_host" class="input font-mono" autocomplete="off" spellcheck="false" placeholder="ghcr.io" data-test="source-registry-host" />
                <p v-if="err('registry_host')" class="text-[11px] text-red-400 mt-1">{{ err('registry_host') }}</p>
              </div>
              <div>
                <label class="label"><TermText path="runtimes.sources.form.registryUsername" /></label>
                <input v-model="form.registry_username" class="input font-mono" autocomplete="off" data-test="source-registry-username" />
                <p v-if="err('registry_username')" class="text-[11px] text-red-400 mt-1">{{ err('registry_username') }}</p>
              </div>
              <SourceSecretField
                v-model="secrets.registry_password" field="registry_password" :label="t('runtimes.sources.form.registryPassword')"
                :set="!!stored.registry_password_set" :error="err('registry_password')"
              />
            </div>
          </section>

          <!-- Verification: cosign key, the source's patch feed -->
          <section data-tour="runtime-source-verification" class="space-y-3">
            <p class="section-title">{{ t('runtimes.sources.form.verification') }}</p>
            <div>
              <label class="label">{{ t('runtimes.sources.form.publicKey') }}</label>
              <textarea v-model="form.public_key" class="input font-mono text-xs h-24" spellcheck="false" placeholder="-----BEGIN PUBLIC KEY-----" data-test="source-public-key" />
              <p class="text-[11px] text-gray-400 mt-1">{{ form.kind === 'git' ? t('runtimes.sources.form.publicKeyGitHint') : t('runtimes.sources.form.publicKeyOciHint') }}</p>
              <p v-if="err('public_key')" class="text-[11px] text-red-400 mt-1">{{ err('public_key') }}</p>
            </div>
            <div>
              <label class="label">{{ t('runtimes.sources.form.patchFeed') }}</label>
              <input v-model="form.patch_feed" class="input font-mono" spellcheck="false" autocomplete="off" placeholder="ghcr.io/acme/runtime-patches:feed" data-test="source-patch-feed" />
              <p class="text-[11px] text-gray-400 mt-1">{{ t('runtimes.sources.form.patchFeedHint') }}</p>
              <p v-if="err('patch_feed')" class="text-[11px] text-red-400 mt-1">{{ err('patch_feed') }}</p>
            </div>
          </section>
        </template>

        <!-- How often, on / off -->
        <section data-tour="runtime-source-interval" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="label">{{ t('runtimes.sources.form.interval') }}</label>
            <input v-model.number="form.interval_minutes" type="number" min="5" max="10080" class="input font-mono" data-test="source-interval" />
            <p class="text-[11px] text-gray-400 mt-1">{{ t('runtimes.sources.form.intervalHint') }}</p>
            <p v-if="err('interval_minutes')" class="text-[11px] text-red-400 mt-1">{{ err('interval_minutes') }}</p>
          </div>
          <div>
            <label class="label">{{ t('runtimes.editor.status') }}</label>
            <div class="flex items-center gap-3 h-9">
              <button type="button" role="switch" :aria-checked="form.is_enabled" class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors"
                :class="form.is_enabled ? 'bg-brand-500' : 'bg-gray-700'" data-test="source-enabled" @click="form.is_enabled = !form.is_enabled">
                <span class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition" :class="form.is_enabled ? 'translate-x-4' : 'translate-x-0'" />
              </button>
              <span class="text-sm" :class="form.is_enabled ? 'text-brand-500' : 'text-gray-400'">{{ form.is_enabled ? t('runtimes.sources.form.enabled') : t('runtimes.sources.form.disabled') }}</span>
            </div>
          </div>
        </section>

        <!-- Test connection: read as entered, nothing saved -->
        <section v-if="!isDefault" class="space-y-2">
          <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="btn-secondary text-xs flex items-center gap-1.5" :disabled="testing" data-tour="runtime-source-test" data-test="source-test" @click="test">
              <Loader2 v-if="testing" class="w-3.5 h-3.5 animate-spin" /><PlugZap v-else class="w-3.5 h-3.5" />{{ t('runtimes.sources.form.test') }}
            </button>
            <span class="text-[11px] text-gray-400">{{ t('runtimes.sources.form.testHint') }}</span>
          </div>
          <p v-if="testError" class="text-xs text-red-400" data-test="source-test-error">{{ testError }}</p>
          <div v-if="testResult" class="rounded-lg border px-3 py-2.5 text-xs space-y-2" :class="testClasses" data-test="source-test-result">
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span class="flex items-center gap-1.5 font-medium" :class="testResult.ok ? (testResult.status === 'partial' ? 'text-yellow-400' : 'text-emerald-400') : 'text-red-400'">
                <CheckCircle2 v-if="testResult.ok && testResult.status !== 'partial'" class="w-3.5 h-3.5" />
                <AlertTriangle v-else-if="testResult.ok" class="w-3.5 h-3.5" />
                <XCircle v-else class="w-3.5 h-3.5" />
                {{ t(`runtimes.sources.status.${testResult.status}`) }}
              </span>
              <span v-if="testResult.revision" class="font-mono text-gray-300" :title="testResult.revision">{{ t('runtimes.sources.revision', { revision: shortRevision(testResult.revision) }) }}</span>
              <span v-if="testResult.signature_verified" class="inline-flex items-center gap-1 text-emerald-400"><ShieldCheck class="w-3 h-3" />{{ t('runtimes.sources.signatureVerified') }}</span>
              <span v-if="testResult.ok" class="text-gray-300">{{ t('runtimes.sources.form.testFound', { n: testResult.runtimes?.length ?? 0 }) }}</span>
            </p>
            <p v-if="testResult.error" class="text-red-400 break-words">{{ testResult.error }}</p>
            <ul v-if="testResult.runtimes?.length" class="flex flex-wrap gap-1.5" data-test="source-test-runtimes">
              <li
                v-for="r in testResult.runtimes" :key="r.path"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm border font-mono text-[11px]"
                :class="r.valid ? 'border-emerald-800/50 text-emerald-400' : 'border-red-800/50 text-red-400'"
                :title="r.path"
                :data-valid="r.valid"
              ><CheckCircle2 v-if="r.valid" class="w-3 h-3" /><XCircle v-else class="w-3 h-3" />{{ r.id || r.path }}</li>
            </ul>
            <ul v-if="testResult.problems?.length" class="space-y-1" data-test="source-test-problems">
              <li v-for="(p, i) in testResult.problems" :key="i" class="flex flex-wrap gap-x-2">
                <code class="font-mono text-[11px] text-gray-400">{{ p.file || p.runtime || '—' }}</code>
                <code class="font-mono text-[11px] text-red-300">{{ p.path || '/' }}</code>
                <span class="text-gray-300">{{ p.message }}</span>
              </li>
            </ul>
          </div>
        </section>

        <div class="flex items-center justify-between gap-3 pt-4 border-t border-gray-800/60">
          <p class="text-sm text-red-400 min-w-0 break-words" data-test="source-save-error">{{ saveError }}</p>
          <div class="flex gap-2 shrink-0" data-tour="runtime-source-save">
            <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
            <button type="submit" class="btn-primary" :disabled="saving" data-test="source-save">
              <Loader2 v-if="saving" class="w-4 h-4 animate-spin" />
              {{ saving ? t('runtimes.sources.form.reading') : (isEdit ? t('common.save') : t('runtimes.sources.form.create')) }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
@reference "../../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
.chip {
  @apply px-3 py-1.5 rounded-md border text-xs font-medium transition-all bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500;
}
.chip-active {
  @apply text-white border-transparent;
  background-color: rgb(var(--brand-600));
}
</style>
