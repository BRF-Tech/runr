import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '@/api/auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('token') || null)
  const user = ref(JSON.parse(localStorage.getItem('user') || 'null'))

  const isAuthenticated = computed(() => !!token.value)
  const isAdmin = computed(() => !!user.value?.is_admin)

  async function login(loginValue, password, tenantSlug, twoFactorCode) {
    const { data, status } = await authApi.login(loginValue, password, tenantSlug, twoFactorCode)

    // 2FA required — don't set token/user yet
    if (status === 202 || data.requires_2fa) {
      return data
    }

    token.value = data.token
    user.value  = data.user
    localStorage.setItem('token', data.token)
    localStorage.setItem('user', JSON.stringify(data.user))

    return data
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      _clearLocalState()
    }
  }

  function forceLogout() {
    _clearLocalState()
  }

  function _clearLocalState() {
    token.value = null
    user.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    localStorage.removeItem('active_tenant')
    localStorage.removeItem('recent_tenants')
  }

  function setTokenDirectly(newToken) {
    token.value = newToken
    localStorage.setItem('token', newToken)
  }

  function setUser(newUser) {
    user.value = newUser
    localStorage.setItem('user', JSON.stringify(newUser))
  }

  function markTutorialCompleted() {
    if (user.value) {
      user.value = { ...user.value, tutorial_completed_at: new Date().toISOString() }
      localStorage.setItem('user', JSON.stringify(user.value))
    }
  }

  async function refreshUser() {
    try {
      const { data } = await authApi.me()
      user.value = data
      localStorage.setItem('user', JSON.stringify(data))
    } catch {}
  }

  return { token, user, isAuthenticated, isAdmin, login, logout, forceLogout, setTokenDirectly, setUser, markTutorialCompleted, refreshUser }
})
