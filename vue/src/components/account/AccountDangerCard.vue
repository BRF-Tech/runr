<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { accountApi } from '@/api/account'
import { AlertTriangle } from 'lucide-vue-next'

const { t } = useI18n()
const router  = useRouter()
const auth    = useAuthStore()
const toast   = useToast()
const { confirm } = useConfirm()

const showDeleteModal = ref(false)
const deletePassword  = ref('')
const deleting        = ref(false)
const deleteError     = ref(null)

async function disableAccount() {
  const ok = await confirm({
    title:       t('account.disableAccount'),
    message:     t('account.disableConfirm'),
    destructive: true,
  })
  if (!ok) return

  try {
    await accountApi.disableAccount()
    toast.success(t('account.accountDisabled'))
    await auth.logout()
    router.push('/login')
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function submitDelete() {
  deleting.value = true
  deleteError.value = null
  try {
    await accountApi.deleteAccount(deletePassword.value)
    showDeleteModal.value = false
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    router.push('/login')
  } catch (e) {
    deleteError.value = e.response?.data?.message ?? t('common.error')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="card p-6 border-red-900/40 flex flex-col">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-red-900/40">
      <AlertTriangle class="w-4 h-4 text-red-400" />
      <h3 class="text-sm font-semibold text-red-400">{{ t('account.dangerTitle') }}</h3>
    </div>

    <div class="space-y-4">
      <!-- Disable -->
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-gray-200">{{ t('account.disableAccount') }}</p>
          <p class="text-xs text-gray-500">{{ t('account.disableDesc') }}</p>
        </div>
        <button class="btn-secondary text-xs text-red-400 hover:text-red-300 shrink-0" @click="disableAccount">
          {{ t('account.disableAccount') }}
        </button>
      </div>

      <div class="border-t border-gray-800"></div>

      <!-- Delete -->
      <div>
        <p class="text-sm text-gray-200">{{ t('account.deleteAccount') }}</p>
        <p class="text-xs text-gray-500">{{ t('account.deleteDesc') }}</p>
      </div>
    </div>

    <div class="flex-1"></div>

    <div class="flex justify-end pt-4 mt-4 border-t border-red-900/40">
      <button class="text-xs px-3 py-1.5 rounded-md bg-red-600 text-white hover:bg-red-500 transition-colors" @click="showDeleteModal = true">
        {{ t('account.deleteAccount') }}
      </button>
    </div>

    <!-- Delete confirmation modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showDeleteModal = false">
      <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 w-full max-w-sm">
        <h4 class="text-sm font-semibold text-red-400 mb-2">{{ t('account.deleteAccount') }}</h4>
        <p class="text-xs text-gray-400 mb-4">{{ t('account.deleteConfirm') }}</p>
        <input v-model="deletePassword" type="password" class="input mb-3" :placeholder="t('account.deletePassword')" autocomplete="current-password" />
        <p v-if="deleteError" class="text-xs text-red-400 mb-3">{{ deleteError }}</p>
        <div class="flex gap-2">
          <button class="text-xs px-3 py-1.5 rounded-md bg-red-600 text-white hover:bg-red-500 transition-colors" :disabled="!deletePassword || deleting" @click="submitDelete">
            {{ deleting ? t('common.saving') : t('account.deleteAccount') }}
          </button>
          <button class="btn-secondary text-xs" @click="showDeleteModal = false; deletePassword = ''; deleteError = null">{{ t('common.cancel') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
