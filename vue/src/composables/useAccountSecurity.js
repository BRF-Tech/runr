import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { accountApi } from '@/api/account'

export function useAccountSecurity() {
  const { t } = useI18n()
  const auth  = useAuthStore()
  const toast = useToast()

  // Password
  const pwSaving = ref(false)
  const pwError  = ref(null)

  async function changePassword(current, newPw, confirmPw) {
    pwSaving.value = true
    pwError.value  = null
    try {
      await accountApi.changePassword({
        current_password:      current,
        password:              newPw,
        password_confirmation: confirmPw,
      })
      toast.success(t('account.passwordChanged'))
      return true
    } catch (e) {
      pwError.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
      return false
    } finally {
      pwSaving.value = false
    }
  }

  // 2FA
  const twoFactorEnabled = ref(!!auth.user?.two_factor_confirmed_at)
  const setupData        = ref(null)
  const recoveryCodes    = ref(null)
  const tfSaving         = ref(false)

  async function setup2FA() {
    tfSaving.value = true
    try {
      const { data } = await accountApi.setup2FA()
      setupData.value = data
      return true
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
      return false
    } finally {
      tfSaving.value = false
    }
  }

  async function confirm2FA(code) {
    tfSaving.value = true
    try {
      const { data } = await accountApi.confirm2FA(code)
      recoveryCodes.value    = data.recovery_codes
      twoFactorEnabled.value = true
      await auth.refreshUser()
      return true
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
      return false
    } finally {
      tfSaving.value = false
    }
  }

  async function disable2FA(password) {
    tfSaving.value = true
    try {
      await accountApi.disable2FA(password)
      twoFactorEnabled.value = false
      setupData.value        = null
      recoveryCodes.value    = null
      await auth.refreshUser()
      toast.success(t('account.twoFactorDisabledMsg'))
      return true
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
      return false
    } finally {
      tfSaving.value = false
    }
  }

  async function regenerateCodes() {
    try {
      const { data } = await accountApi.regenerateCodes()
      recoveryCodes.value = data.recovery_codes
      toast.success(t('account.codesRegenerated'))
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
    }
  }

  // Sessions
  const sessions       = ref([])
  const sessionsLoading = ref(false)

  async function loadSessions() {
    sessionsLoading.value = true
    try {
      const { data } = await accountApi.sessions()
      sessions.value = data.sessions
    } finally {
      sessionsLoading.value = false
    }
  }

  async function revokeSession(id) {
    await accountApi.revokeSession(id)
    sessions.value = sessions.value.filter(s => s.id !== id)
    toast.success(t('account.sessionRevoked'))
  }

  async function revokeAllSessions() {
    await accountApi.revokeAll()
    sessions.value = sessions.value.filter(s => s.is_current)
    toast.success(t('account.allSessionsRevoked'))
  }

  return {
    // password
    pwSaving, pwError, changePassword,
    // 2fa
    twoFactorEnabled, setupData, recoveryCodes, tfSaving,
    setup2FA, confirm2FA, disable2FA, regenerateCodes,
    // sessions
    sessions, sessionsLoading, loadSessions, revokeSession, revokeAllSessions,
  }
}
