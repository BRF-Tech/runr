<script setup>
// Administration → Signup (platform admin): self-service registration — e-mail verification,
// invite-only mode, e-mail domain allowlist, terms / privacy links; multi-tenant: automatic tenant
// creation, default quota and the regions offered in the onboarding wizard. The sign-up form's
// CAPTCHA is the bot protection's (BotProtectionSettings): this page links there.
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useSettingsStore } from '@/stores/settings'
import { signupApi } from '../api/whiteLabel'
import { parseDomains } from '../utils/signup'
import { ShieldCheck } from '@lucide/vue'

const { t } = useI18n()
const toast = useToast()
const settingsStore = useSettingsStore()

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const errors = ref({})
const multiTenant = ref(false)
const domainsText = ref('')

const form = reactive({
  registration_enabled: true,
  email_verification: false,
  invite_only: false,
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
  form.terms_url = data.terms_url ?? ''
  form.privacy_url = data.privacy_url ?? ''
  domainsText.value = (data.allowed_domains ?? []).join('\n')
  multiTenant.value = !!data.multi_tenant
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

/** PUT payload. */
function payload() {
  const out = {
    registration_enabled: !!form.registration_enabled,
    email_verification: !!form.email_verification,
    invite_only: !!form.invite_only,
    allowed_domains: parseDomains(domainsText.value),
    terms_url: form.terms_url?.trim() || null,
    privacy_url: form.privacy_url?.trim() || null,
    verification_ttl_hours: intOrNull(form.verification_ttl_hours) ?? 48,
  }
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
      <div class="w-1 h-8 rounded-full shrink-0 bg-linear-to-b from-brand-500 to-brand-700"></div>
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

      <!-- CAPTCHA: the bot protection's register form -->
      <section class="card flex items-start gap-3" data-test="signup-captcha-moved">
        <ShieldCheck class="w-4 h-4 mt-0.5 shrink-0 text-brand-400" aria-hidden="true" />
        <div class="text-sm">
          <h4 class="font-semibold text-gray-100">{{ t('whiteLabel.signupSettings.captcha') }}</h4>
          <p class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.signupSettings.captchaMoved') }}</p>
          <RouterLink :to="{ path: '/settings', query: { tab: 'bot-protection' } }" class="inline-block mt-2 text-xs font-semibold text-brand-400 hover:underline"
            data-test="signup-captcha-link">{{ t('whiteLabel.signupSettings.captchaMovedLink') }}</RouterLink>
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
