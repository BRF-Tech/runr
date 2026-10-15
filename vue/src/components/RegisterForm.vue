<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import { moduleRegistry } from '@/modules/index'

const emit = defineEmits(['back'])
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

const step = ref(1)
const name     = ref('')
const username = ref('')
const email    = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const tenantName = ref('')
const loading  = ref(false)
const error    = ref(null)
const showPw   = ref(false)

const extraFieldsComponent = moduleRegistry.registerSlots?.extraFields

// Module extensions (step 2): extra payload fields, e.g. captcha_token / accept_terms / plan_id
const extensions = moduleRegistry.registerExtensions ?? []
const extra   = ref({})
const attempt = ref(0)

// E-mail verification: the account is created when the link in the e-mail is opened
const pendingEmail = ref(null)
const resending    = ref(false)
const resent       = ref(false)

async function resend() {
  resending.value = true
  try {
    await authApi.resendSignup(pendingEmail.value)
    resent.value = true
  } catch { /* the API answers 200 for any address; network errors only */ }
  finally { resending.value = false }
}

function nextStep() {
  if (!name.value || !username.value || !email.value) return
  error.value = null
  step.value = 2
}

async function submit() {
  error.value = null
  loading.value = true
  try {
    const payload = {
      name: name.value, username: username.value, email: email.value,
      password: password.value, password_confirmation: passwordConfirmation.value,
    }
    if (tenantName.value) payload.tenant_name = tenantName.value
    for (const [key, value] of Object.entries(extra.value || {})) {
      if (value !== undefined && value !== null && value !== '') payload[key] = value
    }
    const { data, status } = await authApi.register(payload)
    if (status === 202 || data?.verification_required) {
      pendingEmail.value = data?.email || email.value
      return
    }
    auth.setTokenDirectly(data.token)
    auth.setUser(data.user)
    for (const hook of moduleRegistry.loginHooks) {
      try { await hook(data) } catch { /* a module hook must not block the sign-up */ }
    }
    router.push('/projects')
  } catch (e) {
    attempt.value++
    const errors = e.response?.data?.errors
    const code = e.response?.data?.code
    error.value = errors
      ? Object.values(errors).flat()[0]
      : e.response?.data?.message
        ?? (code === 'invite_only' ? t('whiteLabel.signup.inviteOnly') : code === 'registration_disabled' ? t('whiteLabel.signup.registrationDisabled') : t('common.error'))
    if (errors && (errors.name || errors.username || errors.email || errors.tenant_name)) step.value = 1
  } finally { loading.value = false }
}
</script>

<template>
  <!-- Verification e-mail sent -->
  <template v-if="pendingEmail">
    <div class="mb-8" data-test="signup-check-inbox">
      <h2 class="login-title text-4xl font-bold mb-3">{{ t('whiteLabel.signup.checkInboxTitle') }}</h2>
      <p class="login-subtitle text-xl leading-relaxed">{{ t('whiteLabel.signup.checkInboxText', { email: pendingEmail }) }}</p>
    </div>
    <p v-if="resent" class="text-sm font-medium mb-4" style="color:#059669">{{ t('whiteLabel.signup.resent') }}</p>
    <button type="button" class="login-submit w-full py-4 rounded-md text-base font-semibold text-white transition-all disabled:opacity-50"
      :disabled="resending" data-test="signup-resend" @click="resend">
      {{ resending ? t('whiteLabel.signup.resending') : t('whiteLabel.signup.resend') }}
    </button>
    <p class="text-center mt-6 text-sm" style="color:#64748b">
      <button type="button" class="hover:underline" style="color:rgb(var(--brand-600))" @click="emit('back')">
        {{ t('auth.backToLogin') }}
      </button>
    </p>
  </template>

  <template v-else>
  <div class="mb-8">
    <h2 class="login-title text-4xl font-bold mb-3">{{ t('auth.registerTitle') }}</h2>
    <p class="login-subtitle text-xl leading-relaxed">
      {{ step === 1 ? t('auth.registerSubtitle') : t('auth.setPassword') }}
    </p>
    <!-- Step indicator -->
    <div class="flex gap-2 mt-4">
      <div class="h-1 rounded-full flex-1 transition-colors" :class="step >= 1 ? 'bg-brand-600' : 'bg-gray-200'"></div>
      <div class="h-1 rounded-full flex-1 transition-colors" :class="step >= 2 ? 'bg-brand-600' : 'bg-gray-200'"></div>
    </div>
  </div>

  <!-- Step 1: Account info -->
  <div v-if="step === 1" class="space-y-5">
    <div>
      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.name') }}</label>
      <input v-model="name" type="text" class="login-input w-full px-4 py-4 rounded-md text-base transition"
        :placeholder="t('auth.name')" autocomplete="name" required />
    </div>
    <div>
      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.username') }}</label>
      <input v-model="username" type="text" class="login-input w-full px-4 py-4 rounded-md text-base transition"
        :placeholder="t('auth.username')" autocomplete="username" required />
    </div>
    <div>
      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.email') }}</label>
      <input v-model="email" type="email" class="login-input w-full px-4 py-4 rounded-md text-base transition"
        :placeholder="t('auth.email')" autocomplete="email" required />
    </div>

    <!-- Module: extra fields (e.g. tenant/workspace name) -->
    <component v-if="extraFieldsComponent" :is="extraFieldsComponent" v-model="tenantName" />

    <p v-if="error" class="text-sm font-medium" style="color:#dc2626">{{ error }}</p>

    <button type="button" class="login-submit w-full py-4 rounded-md text-base font-semibold text-white transition-all" @click="nextStep">
      {{ t('auth.continue') }}
    </button>
  </div>

  <!-- Step 2: Password -->
  <div v-else class="space-y-5">
    <div>
      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.password') }}</label>
      <div class="relative">
        <input v-model="password" :type="showPw ? 'text' : 'password'"
          class="login-input w-full px-4 py-4 rounded-md text-base transition pr-12"
          placeholder="••••••••" autocomplete="new-password" required />
        <button type="button" class="login-eye absolute right-4 top-1/2 -translate-y-1/2 transition-colors" tabindex="-1" @click="showPw = !showPw">
          <svg v-if="!showPw" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
        </button>
      </div>
    </div>
    <div>
      <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">{{ t('auth.confirmPassword') }}</label>
      <input v-model="passwordConfirmation" type="password" class="login-input w-full px-4 py-4 rounded-md text-base transition"
        placeholder="••••••••" autocomplete="new-password" required />
    </div>

    <!-- Module extensions: CAPTCHA, terms acceptance, plan choice -->
    <component v-for="(ext, i) in extensions" :key="'reg-ext-' + i" :is="ext" v-model="extra" :attempt="attempt" />

    <p v-if="error" class="text-sm font-medium" style="color:#dc2626">{{ error }}</p>

    <div class="flex gap-3">
      <button type="button" class="px-6 py-4 rounded-md text-base font-semibold transition-all" style="background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0" @click="step = 1">
        {{ t('common.back') }}
      </button>
      <button type="button" class="login-submit flex-1 py-4 rounded-md text-base font-semibold text-white transition-all disabled:opacity-50"
        :disabled="loading" @click="submit">
        {{ loading ? t('auth.registerSubmitting') : t('auth.registerSubmit') }}
      </button>
    </div>
  </div>

  <p class="text-center mt-6 text-sm" style="color:#64748b">
    <button type="button" class="hover:underline" style="color:rgb(var(--brand-600))" @click="emit('back')">
      {{ t('auth.backToLogin') }}
    </button>
  </p>
  </template>
</template>
