<script setup>
import { onMounted, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { moduleRegistry } from '@/modules/index'
import authProviderApi from '../api/authProviders'

const { t } = useI18n()
const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()
const error  = ref(null)

onMounted(async () => {
  // The SSO callback hands over a one-time code (docs/security.md); the token never sits in the URL
  const code = route.query.code
  if (!code) {
    error.value = route.query.oauth_error || t('authProvider.oauthError')
    return
  }

  let token
  try {
    const { data } = await authProviderApi.exchangeCode(String(code))
    token = data.token
  } catch (e) {
    error.value = e.response?.data?.message || t('authProvider.oauthError')
    return
  }
  // The code is used up now (one exchange, 60 seconds); the history entry is harmless

  // Store token and user info
  auth.setTokenDirectly(token)

  // Run module login hooks (e.g. tenancy)
  try {
    const meResponse = await fetch('/api/auth/me', {
      headers: { Authorization: `Bearer ${token}` },
    })
    const user = await meResponse.json()
    auth.setUser(user)

    for (const hook of moduleRegistry.loginHooks) {
      await hook({ user, token })
    }
  } catch {
    // Non-critical — user is already logged in
  }

  router.push('/projects')
})
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-950">
    <div v-if="error" class="text-center">
      <p class="text-red-400 text-sm mb-4">{{ error }}</p>
      <RouterLink to="/login" class="text-sm text-blue-400 hover:text-blue-300">
        {{ t('auth.submit') }}
      </RouterLink>
    </div>
    <div v-else class="text-center">
      <div class="w-8 h-8 border-2 border-blue-400 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
      <p class="text-gray-400 text-sm">{{ t('common.loading') }}</p>
    </div>
  </div>
</template>
