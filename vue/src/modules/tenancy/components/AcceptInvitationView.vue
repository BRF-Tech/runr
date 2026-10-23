<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { CheckCircle, XCircle, Clock, Loader2 } from 'lucide-vue-next'

const { t }  = useI18n()
// The redirect after accepting: dropped when the user left the page before it
let redirectTimer = null
onUnmounted(() => clearTimeout(redirectTimer))
const route  = useRoute()
const router = useRouter()

const status     = ref('loading') // loading | form | submitting | success | expired | not-found | error
const invitation = ref(null)      // { email, role, tenant, has_account }
const name       = ref('')
const password   = ref('')
const errorMsg   = ref('')
const tenantName = ref('')

onMounted(async () => {
  try {
    const { data } = await tenantsApi.previewInvitation(route.params.token)
    invitation.value = data
    status.value = 'form'
  } catch (e) {
    const code = e.response?.status
    if (code === 410) status.value = 'expired'
    else if (code === 404) { status.value = 'not-found'; errorMsg.value = e.response?.data?.message || '' }
    else { status.value = 'error'; errorMsg.value = e.response?.data?.message || '' }
  }
})

async function submit() {
  errorMsg.value = ''
  status.value = 'submitting'

  const payload = invitation.value.has_account
    ? { password: password.value }
    : { name: name.value, password: password.value }

  try {
    const { data } = await tenantsApi.acceptInvitation(route.params.token, payload)
    tenantName.value = data.tenant

    // Auto-login with returned token
    localStorage.setItem('token', data.token)
    localStorage.setItem('user', JSON.stringify(data.user))

    status.value = 'success'
    redirectTimer = setTimeout(() => router.push('/projects'), 2000)
  } catch (e) {
    status.value = 'form'
    errorMsg.value = e.response?.data?.message || t('tenancy.invitationError')
  }
}

function goLogin() { router.push('/login') }
</script>

<template>
  <div class="min-h-screen bg-gray-950 flex items-center justify-center px-4">
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-8 max-w-md w-full">

      <!-- Loading -->
      <div v-if="status === 'loading'" class="text-center">
        <Loader2 class="w-12 h-12 mx-auto mb-4 text-blue-400 animate-spin" />
        <p class="text-sm text-gray-400">{{ t('tenancy.invitationPleaseWait') }}</p>
      </div>

      <!-- Form: register or login -->
      <template v-else-if="status === 'form' || status === 'submitting'">
        <h2 class="text-lg font-semibold text-gray-100 mb-1">
          {{ invitation.has_account ? t('tenancy.invitationLoginTitle') : t('tenancy.invitationRegisterTitle') }}
        </h2>
        <p class="text-sm text-gray-400 mb-6">
          {{ t('tenancy.invitationDesc', { tenant: invitation.tenant, role: invitation.role }) }}
        </p>

        <form @submit.prevent="submit" class="space-y-4">
          <!-- Email (readonly) -->
          <div>
            <label class="block text-xs font-medium text-gray-400 mb-1">{{ t('tenancy.email') }}</label>
            <input
              :value="invitation.email"
              type="email"
              disabled
              class="w-full bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 opacity-60"
            />
          </div>

          <!-- Name (only for new users) -->
          <div v-if="!invitation.has_account">
            <label class="block text-xs font-medium text-gray-400 mb-1">{{ t('tenancy.userName') }}</label>
            <input
              v-model="name"
              type="text"
              required
              class="w-full bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 focus:outline-hidden focus:border-blue-500"
              :placeholder="t('tenancy.userName')"
            />
          </div>

          <!-- Password -->
          <div>
            <label class="block text-xs font-medium text-gray-400 mb-1">{{ t('auth.password') }}</label>
            <input
              v-model="password"
              type="password"
              required
              :minlength="invitation.has_account ? undefined : 8"
              class="w-full bg-gray-800 border border-gray-700 rounded-md px-3 py-2 text-sm text-gray-100 focus:outline-hidden focus:border-blue-500"
              placeholder="••••••••"
            />
            <p v-if="!invitation.has_account" class="text-[11px] text-gray-400 mt-1">
              {{ t('tenancy.passwordMinLength') }}
            </p>
          </div>

          <!-- Error -->
          <p v-if="errorMsg" class="text-sm text-red-400">{{ errorMsg }}</p>

          <!-- Submit -->
          <button
            type="submit"
            class="btn-primary w-full"
            :disabled="status === 'submitting'"
          >
            <Loader2 v-if="status === 'submitting'" class="w-4 h-4 inline animate-spin mr-1" />
            {{ invitation.has_account ? t('tenancy.invitationAcceptLogin') : t('tenancy.invitationAcceptRegister') }}
          </button>
        </form>
      </template>

      <!-- Success -->
      <div v-else-if="status === 'success'" class="text-center">
        <CheckCircle class="w-12 h-12 mx-auto mb-4 text-green-400" />
        <h2 class="text-lg font-semibold text-gray-100 mb-2">{{ t('tenancy.invitationAccepted') }}</h2>
        <p class="text-sm text-gray-400">{{ t('tenancy.invitationAcceptedDesc', { tenant: tenantName }) }}</p>
      </div>

      <!-- Expired -->
      <div v-else-if="status === 'expired'" class="text-center">
        <Clock class="w-12 h-12 mx-auto mb-4 text-yellow-400" />
        <h2 class="text-lg font-semibold text-gray-100 mb-2">{{ t('tenancy.invitationExpired') }}</h2>
        <p class="text-sm text-gray-400 mb-4">{{ t('tenancy.invitationExpiredDesc') }}</p>
        <button class="btn-primary" @click="goLogin">{{ t('auth.submit') }}</button>
      </div>

      <!-- Not found / Error -->
      <div v-else class="text-center">
        <XCircle class="w-12 h-12 mx-auto mb-4 text-red-400" />
        <h2 class="text-lg font-semibold text-gray-100 mb-2">{{ t('tenancy.invitationNotFound') }}</h2>
        <p class="text-sm text-gray-400 mb-4">{{ errorMsg }}</p>
        <button class="btn-primary" @click="goLogin">{{ t('auth.submit') }}</button>
      </div>
    </div>
  </div>
</template>
