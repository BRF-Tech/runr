<script setup>
// Administration → Access & security → Bot protection (docs/security.md#bot-protection).
//   platform (single-tenant: settings.manage; multi-tenant: platform administrators outside an
//   organization): provider, keys, guarded forms, fallback, timeout; the fallback's status
//   organization (multi-tenant, settings.manage in it): its own provider and keys, or the platform's
// A configuration error (no site key, no secret, a secret that cannot be decrypted or that the
// provider refuses) shows under the keys: the forms run on the browser check until it is fixed.
// The secret is write-only (SecretInput). A tutorial restart starts the page's tour
// (tutorialSteps.js botProtectionSteps).
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldAlert } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import { botProtectionSteps } from '@/composables/tutorialSteps'
import { botProtectionApi } from '../api/whiteLabel'
import { inTenantContext } from '../utils/context'
import SecretInput from './SecretInput.vue'

const { t } = useI18n()
const toast = useToast()
const tutorialStore = useTutorialStore()
const { start: startTutorial } = createTutorial(botProtectionSteps)
watch(() => tutorialStore.restartCounter, () => { nextTick(() => startTutorial()) })

// The forms of the console; password_reset exists in the shared model, RunR has no such form
const FORMS = ['login', 'demo_login', 'register', 'public_forms']
const PROVIDERS = ['turnstile', 'recaptcha', 'hcaptcha']

const scope = inTenantContext() ? 'tenant' : 'platform'
const isPlatform = scope === 'platform'

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const errors = ref({})
const data = ref(null)
const secret = ref(undefined)

const form = reactive({
  provider: isPlatform ? 'none' : '',
  site_key: '',
  forms: [],
  fallback: 'local',
  verify_timeout_ms: 4000,
})

const providerName = (p) => t(`whiteLabel.botProtection.providers.${p || 'none'}`)
const usesProvider = computed(() => (isPlatform ? form.provider !== 'none' : form.provider !== ''))
const secretSet = computed(() => !!data.value?.secret_set)
const defaults = computed(() => data.value?.defaults ?? null)
// {reason: site_key_missing | secret_missing | secret_unreadable | secret_rejected, at} — the API's
const problem = computed(() => (data.value?.problem?.reason ? data.value.problem : null))

function fill(payload) {
  data.value = payload
  form.provider = isPlatform ? (payload.provider ?? 'none') : (payload.provider ?? '')
  form.site_key = payload.site_key ?? ''
  if (isPlatform) {
    form.forms = [...(payload.forms ?? [])]
    form.fallback = payload.fallback ?? 'local'
    form.verify_timeout_ms = payload.verify_timeout_ms ?? 4000
  }
  secret.value = undefined
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data: payload } = isPlatform ? await botProtectionApi.adminGet() : await botProtectionApi.tenantGet()
    fill(payload)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** PUT payload. secret: undefined = keep, '' = remove (secret_clear), a value = replace (never read back). */
function payload() {
  const out = isPlatform
    ? {
        provider: form.provider,
        site_key: form.site_key.trim() || null,
        forms: [...form.forms],
        fallback: form.fallback,
        verify_timeout_ms: Number(form.verify_timeout_ms) || 4000,
      }
    : {
        provider: form.provider || null,
        ...(form.provider ? { site_key: form.site_key.trim() || null } : {}),
      }
  if (secret.value === '') out.secret_clear = true
  else if (secret.value) out.secret = secret.value
  return out
}

defineExpose({ payload })

async function save(body = payload()) {
  saving.value = true
  error.value = null
  errors.value = {}
  try {
    const { data: answer } = isPlatform ? await botProtectionApi.adminUpdate(body) : await botProtectionApi.tenantUpdate(body)
    fill(answer)
    toast.success(t('whiteLabel.botProtection.saved'))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

function resetToDefaults() {
  return save({ reset: true })
}

function toggleForm(key) {
  const i = form.forms.indexOf(key)
  if (i === -1) form.forms.push(key)
  else form.forms.splice(i, 1)
}

function fieldError(key) {
  return errors.value?.[key]?.[0] ?? null
}

function when(at) {
  if (!at) return t('whiteLabel.botProtection.never')
  try { return new Date(at).toLocaleString() } catch { return at }
}
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-2">
      <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.botProtection.title') }}</h3>
    </div>
    <p class="text-sm text-gray-400 mb-5">{{ isPlatform ? t('whiteLabel.botProtection.subtitle') : t('whiteLabel.botProtection.tenantSubtitle') }}</p>

    <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <form v-else-if="data" class="space-y-6 max-w-3xl" :data-test="`bot-protection-${scope}`" @submit.prevent="save()">
      <!-- Provider and keys -->
      <section class="card space-y-4">
        <div data-tour="bot-protection-provider">
          <label for="bot-provider" class="label">{{ t('whiteLabel.botProtection.provider') }}</label>
          <select id="bot-provider" v-model="form.provider" class="input" data-test="field-provider">
            <option v-if="isPlatform" value="none">{{ providerName('none') }}</option>
            <option v-else value="">{{ t('whiteLabel.botProtection.inherit', { provider: providerName(data.platform?.provider) }) }}</option>
            <option v-for="p in PROVIDERS" :key="p" :value="p">{{ providerName(p) }}</option>
          </select>
          <p v-if="fieldError('provider')" class="text-xs text-red-400 mt-1">{{ fieldError('provider') }}</p>
          <p class="text-xs text-gray-400 mt-1" data-test="provider-hint">
            <template v-if="isPlatform && defaults">{{ t('whiteLabel.botProtection.installDefault', { provider: providerName(defaults.provider) }) }}</template>
            <template v-else-if="!isPlatform">{{ t('whiteLabel.botProtection.tenantHint') }}</template>
          </p>
        </div>

        <div v-if="usesProvider" class="space-y-4" data-tour="bot-protection-keys">
          <div>
            <label for="bot-site-key" class="label">{{ t('whiteLabel.botProtection.siteKey') }}</label>
            <input id="bot-site-key" v-model="form.site_key" class="input font-mono" autocomplete="off" data-test="field-site_key" />
            <p v-if="fieldError('site_key')" class="text-xs text-red-400 mt-1">{{ fieldError('site_key') }}</p>
          </div>
          <div>
            <SecretInput v-model="secret" :label="t('whiteLabel.botProtection.secret')" :set="secretSet" test-id="field-secret" />
            <p v-if="fieldError('secret')" class="text-xs text-red-400 mt-1">{{ fieldError('secret') }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.secret.hint') }}</p>
          </div>
          <p class="text-xs text-gray-400">{{ t('whiteLabel.botProtection.keysHint') }}</p>
          <!-- A configuration error the forms meet: they run on the browser check until it is fixed -->
          <p v-if="problem" class="flex items-start gap-2 text-xs text-red-400" role="alert" data-test="bot-protection-problem" :data-problem="problem.reason">
            <ShieldAlert class="w-4 h-4 shrink-0" aria-hidden="true" />
            <span>
              {{ t(`whiteLabel.botProtection.problems.${problem.reason}`, { provider: providerName(data.provider ?? data.effective?.provider) }) }}
              {{ t('whiteLabel.botProtection.problemFallback') }}
              <template v-if="problem.at">{{ t('whiteLabel.botProtection.fallbackLast', { when: when(problem.at) }) }}</template>
            </span>
          </p>
        </div>
      </section>

      <!-- Guarded forms (platform) -->
      <section v-if="isPlatform" class="card space-y-3" data-tour="bot-protection-forms" data-test="bot-protection-forms">
        <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.botProtection.forms') }}</h4>
        <label v-for="key in FORMS" :key="key" class="flex items-start gap-3 cursor-pointer">
          <input type="checkbox" class="mt-1" :checked="form.forms.includes(key)" :data-test="`form-${key}`" @change="toggleForm(key)" />
          <span>
            <span class="block text-sm text-gray-100">{{ t(`whiteLabel.botProtection.formNames.${key}`) }}</span>
            <span class="block text-xs text-gray-400">{{ t(`whiteLabel.botProtection.formHints.${key}`) }}</span>
          </span>
        </label>
        <p v-if="fieldError('forms') || fieldError('forms.0')" class="text-xs text-red-400">{{ fieldError('forms') || fieldError('forms.0') }}</p>
      </section>

      <!-- The local fallback (platform) -->
      <section v-if="isPlatform" class="card space-y-4" data-tour="bot-protection-fallback">
        <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.botProtection.fallbackTitle') }}</h4>
        <p class="text-xs text-gray-400">{{ t('whiteLabel.botProtection.fallbackHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="bot-fallback" class="label">{{ t('whiteLabel.botProtection.fallback') }}</label>
            <select id="bot-fallback" v-model="form.fallback" class="input" data-test="field-fallback">
              <option value="local">{{ t('whiteLabel.botProtection.fallbackLocal') }}</option>
              <option value="off">{{ t('whiteLabel.botProtection.fallbackOff') }}</option>
            </select>
          </div>
          <div>
            <label for="bot-timeout" class="label">{{ t('whiteLabel.botProtection.timeout') }}</label>
            <input id="bot-timeout" v-model="form.verify_timeout_ms" type="number" min="500" max="30000" step="500" class="input" data-test="field-verify_timeout_ms" />
            <p v-if="fieldError('verify_timeout_ms')" class="text-xs text-red-400 mt-1">{{ fieldError('verify_timeout_ms') }}</p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-gray-400" data-tour="bot-protection-status" data-test="bot-protection-status">
          <span v-if="problem" class="text-red-400" data-test="fallback-active">{{ t('whiteLabel.botProtection.misconfigured') }}</span>
          <span v-else :class="data.fallback_active ? 'text-yellow-400' : 'text-emerald-400'" data-test="fallback-active">
            {{ data.fallback_active ? t('whiteLabel.botProtection.fallbackActive') : t('whiteLabel.botProtection.fallbackIdle') }}
          </span>
          <span data-test="fallback-total">{{ t('whiteLabel.botProtection.fallbackTotal', { count: data.fallback_total ?? 0 }) }}</span>
          <span>{{ t('whiteLabel.botProtection.fallbackLast', { when: when(data.fallback_last_at) }) }}</span>
        </div>
      </section>

      <p v-if="error" class="text-sm text-red-400" data-test="bot-protection-error">{{ error }}</p>
      <div class="flex justify-end gap-3" data-tour="bot-protection-save">
        <button v-if="isPlatform && data.stored" type="button" class="btn-secondary" :disabled="saving" data-test="bot-protection-reset" @click="resetToDefaults">
          {{ t('whiteLabel.botProtection.reset') }}
        </button>
        <button type="submit" class="btn-primary" :disabled="saving" data-test="bot-protection-save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>

    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
  </div>
</template>
