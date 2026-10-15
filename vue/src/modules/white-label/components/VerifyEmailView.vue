<script setup>
// /signup/verify?token=… — target of the signup verification e-mail. Creates the account
// (POST /api/auth/signup/verify), signs the user in and continues to the projects page.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { moduleRegistry } from '@/modules/index'
import { signupApi } from '../api/whiteLabel'
import { useBrandingStore } from '../stores/branding'
import { CheckCircle2, Loader2, MailWarning } from 'lucide-vue-next'
import runrIconUrl from '@/assets/runr-icon.svg'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const branding = useBrandingStore()

// verifying | invalid | expired | error
const state = ref('verifying')
const message = ref('')
const email = ref('')
const resending = ref(false)
const resent = ref(false)

const logo = computed(() => branding.logoFor('light'))

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''
  if (!token) {
    state.value = 'invalid'
    return
  }

  try {
    const { data } = await signupApi.verify(token)
    auth.setTokenDirectly(data.token)
    auth.setUser(data.user)
    for (const hook of moduleRegistry.loginHooks) {
      try { await hook(data) } catch { /* a module hook must not block the sign-in */ }
    }
    state.value = 'done'
    router.replace('/projects')
  } catch (e) {
    const status = e.response?.status
    message.value = e.response?.data?.message || ''
    state.value = status === 410 ? 'expired' : status === 404 ? 'invalid' : 'error'
  }
})

async function resend() {
  if (!email.value) return
  resending.value = true
  try {
    await signupApi.resend(email.value.trim())
    resent.value = true
  } catch { /* 200 for any address — only network errors land here */ }
  finally { resending.value = false }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-6" style="background:#f1f5f9">
    <div class="w-full max-w-md rounded-2xl bg-white shadow-xl px-10 py-10" data-test="verify-email">
      <div class="flex items-center gap-3 mb-8">
        <img v-if="logo" :src="logo" :alt="branding.productName" class="h-10 object-contain" data-test="verify-logo" />
        <template v-else>
          <img :src="branding.markUrl || runrIconUrl" alt="" class="h-9 w-9 object-contain" />
          <span class="text-2xl font-extrabold tracking-tight" style="color:#0f172a">{{ branding.productName }}</span>
        </template>
      </div>

      <div v-if="state === 'verifying' || state === 'done'" class="flex items-center gap-3" style="color:#334155">
        <CheckCircle2 v-if="state === 'done'" class="w-5 h-5" style="color:#059669" />
        <Loader2 v-else class="w-5 h-5 animate-spin" style="color:rgb(var(--brand-600))" />
        <span class="text-base">{{ state === 'done' ? t('whiteLabel.verify.done') : t('whiteLabel.verify.verifying') }}</span>
      </div>

      <template v-else>
        <div class="flex items-start gap-3 mb-6">
          <MailWarning class="w-6 h-6 shrink-0" style="color:#dc2626" />
          <div>
            <h1 class="text-xl font-bold mb-1" style="color:#0f172a" data-test="verify-state">
              {{ state === 'expired' ? t('whiteLabel.verify.expiredTitle') : state === 'invalid' ? t('whiteLabel.verify.invalidTitle') : t('whiteLabel.verify.errorTitle') }}
            </h1>
            <p class="text-sm" style="color:#64748b">{{ message || t('whiteLabel.verify.resendHint') }}</p>
          </div>
        </div>

        <form class="space-y-3" @submit.prevent="resend">
          <label class="block text-xs font-bold uppercase tracking-widest" style="color:#374151">{{ t('auth.email') }}</label>
          <input v-model="email" type="email" required autocomplete="email"
            class="w-full px-4 py-3 rounded-md text-base outline-hidden" style="background:#f8fafc;border:1.5px solid #e2e8f0;color:#0f172a" />
          <p v-if="resent" class="text-sm font-medium" style="color:#059669">{{ t('whiteLabel.signup.resent') }}</p>
          <button type="submit" class="w-full py-3 rounded-md text-base font-semibold text-white disabled:opacity-50"
            style="background:rgb(var(--brand-600))" :disabled="resending || !email" data-test="verify-resend">
            {{ resending ? t('whiteLabel.signup.resending') : t('whiteLabel.signup.resend') }}
          </button>
        </form>

        <p class="text-center mt-6 text-sm">
          <RouterLink to="/login" class="hover:underline" style="color:rgb(var(--brand-600))">{{ t('auth.backToLogin') }}</RouterLink>
        </p>
      </template>
    </div>
  </div>
</template>
