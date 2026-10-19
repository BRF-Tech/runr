<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Link2 } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import api from '../api/authProviders'

// Account settings → Linked accounts: the external identities the user signs in with.
// Linking goes through the provider; its callback returns here with ?sso_link=<code>, which only
// this signed-in user can confirm (or ?sso_link_error=<message>).
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { confirm } = useConfirm()
const toast = useToast()

const accounts = ref([])
const providers = ref([])
const loading = ref(true)
const busy = ref(null)
const message = ref(null)

const driverLabels = {
  google: 'authProvider.driverGoogle', github: 'authProvider.driverGithub',
  microsoft: 'authProvider.driverMicrosoft', gitlab: 'authProvider.driverGitlab',
  oidc: 'authProvider.driverOidc', saml: 'authProvider.driverSaml',
}

function label(account) {
  return account.provider_name || t(driverLabels[account.provider] ?? 'authProvider.driverOidc')
}

function formatDate(iso) {
  return iso ? new Date(iso).toLocaleDateString() : ''
}

async function load() {
  try {
    const [linked, linkable] = await Promise.all([api.socialAccounts(), api.linkableProviders()])
    accounts.value = linked.data ?? []
    providers.value = linkable.data ?? []
  } catch {
    // The section stays empty; the rest of the page works
  } finally {
    loading.value = false
  }
}

async function finishLink() {
  const { sso_link: code, sso_link_error: failure, ...rest } = route.query
  if (!code && !failure) return
  // The one-time code leaves the address bar right away
  router.replace({ query: rest })

  if (failure) {
    message.value = { type: 'error', text: String(failure) }
    return
  }
  try {
    const { data } = await api.completeLink(String(code))
    message.value = { type: 'success', text: data.message || t('authProvider.linkedAccounts.linked') }
  } catch (e) {
    message.value = { type: 'error', text: e.response?.data?.message || t('authProvider.linkedAccounts.failed') }
  }
}

onMounted(async () => {
  await finishLink()
  await load()
})

async function link(provider) {
  busy.value = provider.id
  message.value = null
  try {
    const { data } = await api.linkProvider(provider.driver, provider.id)
    window.location.href = data.redirect_url
  } catch (e) {
    busy.value = null
    message.value = { type: 'error', text: e.response?.data?.message || t('authProvider.linkedAccounts.failed') }
  }
}

async function unlink(account) {
  const ok = await confirm({
    title: t('authProvider.linkedAccounts.unlinkTitle'),
    message: t('authProvider.linkedAccounts.unlinkConfirm', { provider: label(account) }),
    destructive: true,
  })
  if (!ok) return

  busy.value = account.id
  try {
    await api.unlinkAccount(account.id)
    accounts.value = accounts.value.filter(a => a.id !== account.id)
    toast.success(t('authProvider.linkedAccounts.unlinked'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section data-testid="linked-accounts">
    <div class="flex items-center gap-3 mb-5">
      <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('authProvider.linkedAccounts.title') }}</h3>
    </div>

    <div class="card p-6">
      <p class="text-xs text-gray-400 mb-4">{{ t('authProvider.linkedAccounts.description') }}</p>

      <p v-if="message" class="text-xs font-medium mb-4" :class="message.type === 'error' ? 'text-red-400' : 'text-green-400'" data-testid="linked-accounts-message">
        {{ message.text }}
      </p>

      <div v-if="loading" class="py-6 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

      <template v-else>
        <p v-if="!accounts.length" class="text-sm text-gray-400 mb-4" data-testid="linked-accounts-empty">{{ t('authProvider.linkedAccounts.none') }}</p>
        <div v-else class="space-y-2 mb-5">
          <div v-for="account in accounts" :key="account.id" data-testid="linked-account"
            class="flex items-center gap-3 rounded-md border border-gray-800 bg-gray-900/60 px-4 py-3">
            <Link2 class="w-4 h-4 text-gray-500 shrink-0" />
            <div class="flex-1 min-w-0">
              <p class="text-sm text-gray-200 truncate">{{ label(account) }}</p>
              <p class="text-xs text-gray-500 truncate">
                {{ account.email || account.name }}
                <template v-if="account.created_at"> · {{ t('authProvider.linkedAccounts.linkedOn', { date: formatDate(account.created_at) }) }}</template>
              </p>
            </div>
            <button type="button" class="text-xs text-red-400 hover:text-red-300 shrink-0 disabled:opacity-50" :disabled="busy === account.id"
              data-testid="unlink-account" @click="unlink(account)">
              {{ t('authProvider.linkedAccounts.unlink') }}
            </button>
          </div>
        </div>

        <div v-if="providers.length" class="flex flex-wrap gap-2">
          <button v-for="provider in providers" :key="provider.id" type="button" class="btn-secondary text-xs" :disabled="busy !== null"
            data-testid="link-provider" @click="link(provider)">
            {{ t('authProvider.linkedAccounts.linkWith', { provider: provider.name }) }}
          </button>
        </div>
      </template>
    </div>
  </section>
</template>
