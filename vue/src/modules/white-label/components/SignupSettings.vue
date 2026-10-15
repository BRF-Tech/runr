<script setup>
// Settings → Signup (platform admin): self-service registration — e-mail verification, invite-only
// mode, e-mail domain allowlist, CAPTCHA, terms / privacy links; multi-tenant: automatic tenant
// creation, default quota and the regions offered in the onboarding wizard.
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useSettingsStore } from '@/stores/settings'
import { signupApi } from '../api/whiteLabel'
import { parseDomains } from '../utils/signup'
import { KeyRound, Trash2 } from 'lucide-vue-next'

const { t } = useI18n()
const toast = useToast()
const settingsStore = useSettingsStore()

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const errors = ref({})
const secretSet = ref(false)
const secretInput = ref('')
const clearSecret = ref(false)
const multiTenant = ref(false)
const domainsText = ref('')

const form = reactive({
  registration_enabled: true,
  email_verification: false,
  invite_only: false,
  captcha_provider: 'none',
  captcha_site_key: '',
  terms_url: '',
  privacy_url: '',
  tenant_auto_create: true,
  default_cpu_quota_m: null,
  default_memory_quota_mb: null,
  self_service_region_ids: [],
  verification_ttl_hours: 48,
})

const regions = computed(() => {
  const list = settingsStore.data?.assignable_regions ?? settingsStore.data?.regions ?? []
  return Array.isArray(list) ? list : []
})

function fill(data) {
  for (const key of Object.keys(form)) {
    if (data[key] !== undefined) form[key] = Array.isArray(data[key]) ? [...data[key]] : data[key]
  }
  form.captcha_site_key = data.captcha_site_key ?? ''
  form.terms_url = data.terms_url ?? ''
  form.privacy_url = data.privacy_url ?? ''
  domainsText.value = (data.allowed_domains ?? []).join('\n')
  secretSet.value = !!data.captcha_secret_set
  multiTenant.value = !!data.multi_tenant
  secretInput.value = ''
  clearSecret.value = false
}

onMounted(async () => {
  try {
    const { data } = await signupApi.settings()
    fill(data)
    if (multiTenant.value && !settingsStore.loaded) settingsStore.fetch().catch(() => {})
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
})

function intOrNull(v) {
  if (v === null || v === undefined || String(v).trim() === '') return null
  const n = Number(v)
  return Number.isFinite(n) && n >= 0 ? Math.floor(n) : null
}

/** PUT payload. captcha_secret: omitted = keep, value = replace (never read back); captcha_secret_clear removes it. */
function payload() {
  const out = {
    registration_enabled: !!form.registration_enabled,
    email_verification: !!form.email_verification,
    invite_only: !!form.invite_only,
    allowed_domains: parseDomains(domainsText.value),
    captcha_provider: form.captcha_provider,
    captcha_site_key: form.captcha_site_key?.trim() || null,
    terms_url: form.terms_url?.trim() || null,
    privacy_url: form.privacy_url?.trim() || null,
    verification_ttl_hours: intOrNull(form.verification_ttl_hours) ?? 48,
  }
  if (secretInput.value.trim() !== '') out.captcha_secret = secretInput.value.trim()
  else if (clearSecret.value) out.captcha_secret_clear = true
  if (multiTenant.value) {
    out.tenant_auto_create = !!form.tenant_auto_create
    out.default_cpu_quota_m = intOrNull(form.default_cpu_quota_m)
    out.default_memory_quota_mb = intOrNull(form.default_memory_quota_mb)
    out.self_service_region_ids = [...form.self_service_region_ids]
  }
  return out
}

defineExpose({ payload })

async function save() {
  saving.value = true
  error.value = null
  errors.value = {}
  try {
    const { data } = await signupApi.updateSettings(payload())
    fill(data)
    toast.success(t('whiteLabel.signupSettings.saved'))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

function toggleRegion(id) {
  const i = form.self_service_region_ids.indexOf(id)
  if (i === -1) form.self_service_region_ids.push(id)
  else form.self_service_region_ids.splice(i, 1)
}

function fieldError(key) {
  return errors.value?.[key]?.[0] ?? null
}
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-2">
      <div class="w-1 h-8 rounded-full shrink-0 bg-gradient-to-b from-brand-500 to-brand-700"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('whiteLabel.signupSettings.title') }}</h3>
    </div>
    <p class="text-sm text-gray-400 mb-5">{{ t('whiteLabel.signupSettings.subtitle') }}</p>

    <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <form v-else class="space-y-6 max-w-3xl" data-test="signup-settings" @submit.prevent="save">
      <!-- Registration -->
      <section class="card space-y-4">
        <label class="flex items-center justify-between gap-4 cursor-pointer">
          <span>
            <span class="block text-sm text-gray-100">{{ t('whiteLabel.signupSettings.registrationEnabled') }}</span>
            <span class="block text-xs text-gray-400">{{ t('whiteLabel.signupSettings.registrationEnabledHint') }}</span>
          </span>
          <input v-model="form.registration_enabled" type="checkbox" data-test="field-registration_enabled" />
        </label>
        <label class="flex items-center justify-between gap-4 cursor-pointer">
          <span>
            <span class="block text-sm text-gray-100">{{ t('whiteLabel.signupSettings.emailVerification') }}</span>
            <span class="block text-xs text-gray-400">{{ t('whiteLabel.signupSettings.emailVerificationHint') }}</span>
          </span>
          <input v-model="form.email_verification" type="checkbox" data-test="field-email_verification" />
        </label>
        <div v-if="form.email_verification">
          <label class="label">{{ t('whiteLabel.signupSettings.verificationTtl') }}</label>
          <input v-model="form.verification_ttl_hours" type="number" min="1" max="720" class="input w-40" />
        </div>
        <label class="flex items-center justify-between gap-4 cursor-pointer">
          <span>
            <span class="block text-sm text-gray-100">{{ t('whiteLabel.signupSettings.inviteOnly') }}</span>
            <span class="block text-xs text-gray-400">{{ t('whiteLabel.signupSettings.inviteOnlyHint') }}</span>
          </span>
          <input v-model="form.invite_only" type="checkbox" data-test="field-invite_only" />
        </label>
        <div>
          <label class="label">{{ t('whiteLabel.signupSettings.allowedDomains') }}</label>
          <textarea v-model="domainsText" rows="3" class="input font-mono" placeholder="example.com" data-test="field-allowed_domains" />
          <p class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.signupSettings.allowedDomainsHint') }}</p>
          <p v-if="fieldError('allowed_domains') || fieldError('allowed_domains.0')" class="text-xs text-red-400 mt-1">
            {{ fieldError('allowed_domains') || fieldError('allowed_domains.0') }}
          </p>
        </div>
      </section>

      <!-- CAPTCHA -->
      <section class="card space-y-4">
        <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.signupSettings.captcha') }}</h4>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label">{{ t('whiteLabel.signupSettings.captchaProvider') }}</label>
            <select v-model="form.captcha_provider" class="input" data-test="field-captcha_provider">
              <option value="none">{{ t('whiteLabel.signupSettings.captchaNone') }}</option>
              <option value="turnstile">Cloudflare Turnstile</option>
              <option value="hcaptcha">hCaptcha</option>
            </select>
          </div>
          <div v-if="form.captcha_provider !== 'none'">
            <label class="label">{{ t('whiteLabel.signupSettings.captchaSiteKey') }}</label>
            <input v-model="form.captcha_site_key" class="input font-mono" data-test="field-captcha_site_key" />
            <p v-if="fieldError('captcha_site_key')" class="text-xs text-red-400 mt-1">{{ fieldError('captcha_site_key') }}</p>
          </div>
        </div>
        <div v-if="form.captcha_provider !== 'none'">
          <div class="flex items-center justify-between">
            <label class="label">{{ t('whiteLabel.signupSettings.captchaSecret') }}</label>
            <span class="text-xs flex items-center gap-1" :class="secretSet && !clearSecret ? 'text-emerald-400' : 'text-gray-400'" data-test="secret-state">
              <KeyRound class="w-3 h-3" />
              {{ secretSet && !clearSecret ? t('whiteLabel.secret.set') : t('whiteLabel.secret.notSet') }}
            </span>
          </div>
          <div class="flex gap-2">
            <input v-model="secretInput" type="password" autocomplete="new-password" class="input font-mono"
              :placeholder="secretSet ? t('whiteLabel.secret.replacePlaceholder') : t('whiteLabel.secret.enterPlaceholder')"
              data-test="field-captcha_secret" @input="clearSecret = false" />
            <button v-if="secretSet" type="button" class="btn-secondary" data-test="clear-captcha_secret"
              @click="clearSecret = true; secretInput = ''">
              <Trash2 class="w-4 h-4" />{{ t('whiteLabel.secret.clear') }}
            </button>
          </div>
          <p class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.secret.hint') }}</p>
        </div>
      </section>

      <!-- Legal -->
      <section class="card space-y-4">
        <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.signupSettings.legal') }}</h4>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label">{{ t('whiteLabel.signupSettings.termsUrl') }}</label>
            <input v-model="form.terms_url" type="url" class="input" placeholder="https://" data-test="field-terms_url" />
            <p v-if="fieldError('terms_url')" class="text-xs text-red-400 mt-1">{{ fieldError('terms_url') }}</p>
          </div>
          <div>
            <label class="label">{{ t('whiteLabel.signupSettings.privacyUrl') }}</label>
            <input v-model="form.privacy_url" type="url" class="input" placeholder="https://" data-test="field-privacy_url" />
            <p v-if="fieldError('privacy_url')" class="text-xs text-red-400 mt-1">{{ fieldError('privacy_url') }}</p>
          </div>
        </div>
        <p class="text-xs text-gray-400">{{ t('whiteLabel.signupSettings.legalHint') }}</p>
      </section>

      <!-- Multi-tenant: tenant creation + onboarding -->
      <section v-if="multiTenant" class="card space-y-4" data-test="signup-tenant-section">
        <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.signupSettings.tenantSection') }}</h4>
        <label class="flex items-center justify-between gap-4 cursor-pointer">
          <span>
            <span class="block text-sm text-gray-100">{{ t('whiteLabel.signupSettings.tenantAutoCreate') }}</span>
            <span class="block text-xs text-gray-400">{{ t('whiteLabel.signupSettings.tenantAutoCreateHint') }}</span>
          </span>
          <input v-model="form.tenant_auto_create" type="checkbox" data-test="field-tenant_auto_create" />
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label">{{ t('whiteLabel.signupSettings.defaultCpu') }}</label>
            <input v-model="form.default_cpu_quota_m" type="number" min="0" step="100" class="input" placeholder="2000" />
          </div>
          <div>
            <label class="label">{{ t('whiteLabel.signupSettings.defaultMemory') }}</label>
            <input v-model="form.default_memory_quota_mb" type="number" min="0" step="128" class="input" placeholder="4096" />
          </div>
        </div>
        <div>
          <label class="label">{{ t('whiteLabel.signupSettings.regions') }}</label>
          <p class="text-xs text-gray-400 mb-2">{{ t('whiteLabel.signupSettings.regionsHint') }}</p>
          <div v-if="regions.length" class="flex flex-wrap gap-2">
            <button v-for="r in regions" :key="r.id" type="button" class="px-3 py-1.5 rounded-lg text-xs border transition-colors"
              :class="form.self_service_region_ids.includes(r.id) ? 'border-brand-500 text-brand-400 bg-brand-500/10' : 'border-gray-700 text-gray-400 hover:text-gray-100'"
              @click="toggleRegion(r.id)">
              {{ r.name }}
            </button>
          </div>
          <p v-else class="text-xs text-gray-500">{{ t('whiteLabel.signupSettings.noRegions') }}</p>
        </div>
      </section>

      <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
      <div class="flex justify-end">
        <button type="submit" class="btn-primary" :disabled="saving" data-test="signup-save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
