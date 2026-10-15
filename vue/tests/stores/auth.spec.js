import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/api/auth', () => ({
  authApi: {
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
  },
}))

import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(authApi.login).mockReset()
  vi.mocked(authApi.logout).mockReset()
  vi.mocked(authApi.me).mockReset()
})

describe('auth store', () => {
  it('restores the session from localStorage', () => {
    localStorage.setItem('token', 'saved')
    localStorage.setItem('user', JSON.stringify({ id: 3, is_admin: true }))
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.isAdmin).toBe(true)
    expect(auth.user.id).toBe(3)
  })

  it('starts signed out with empty storage', () => {
    const auth = useAuthStore()
    expect(auth.token).toBeNull()
    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.isAdmin).toBe(false)
  })

  it('stores token and user after a successful login', async () => {
    authApi.login.mockResolvedValue({ status: 200, data: { token: 'abc', user: { id: 1, is_admin: false } } })
    const auth = useAuthStore()

    await auth.login('admin', 'secret', 'acme', undefined)

    expect(authApi.login).toHaveBeenCalledWith('admin', 'secret', 'acme', undefined)
    expect(auth.isAuthenticated).toBe(true)
    expect(localStorage.getItem('token')).toBe('abc')
    expect(JSON.parse(localStorage.getItem('user'))).toEqual({ id: 1, is_admin: false })
  })

  it.each([
    ['HTTP 202', { status: 202, data: { requires_2fa: true } }],
    ['requires_2fa flag', { status: 200, data: { requires_2fa: true } }],
  ])('keeps the session closed while a second factor is pending (%s)', async (_, response) => {
    authApi.login.mockResolvedValue(response)
    const auth = useAuthStore()

    const result = await auth.login('admin', 'secret')

    expect(result.requires_2fa).toBe(true)
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('propagates login failures without touching storage', async () => {
    authApi.login.mockRejectedValue(new Error('invalid credentials'))
    const auth = useAuthStore()
    await expect(auth.login('a', 'b')).rejects.toThrow('invalid credentials')
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('clears local state on logout even when the API call fails', async () => {
    localStorage.setItem('token', 't')
    localStorage.setItem('user', '{"id":1}')
    localStorage.setItem('active_tenant', 'acme')
    localStorage.setItem('recent_tenants', '[]')
    authApi.logout.mockRejectedValue(new Error('network'))
    const auth = useAuthStore()

    await expect(auth.logout()).rejects.toThrow('network')

    expect(auth.token).toBeNull()
    expect(auth.user).toBeNull()
    for (const key of ['token', 'user', 'active_tenant', 'recent_tenants']) {
      expect(localStorage.getItem(key)).toBeNull()
    }
  })

  it('forceLogout clears without calling the API', () => {
    localStorage.setItem('token', 't')
    const auth = useAuthStore()
    auth.forceLogout()
    expect(authApi.logout).not.toHaveBeenCalled()
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('setTokenDirectly and setUser persist', () => {
    const auth = useAuthStore()
    auth.setTokenDirectly('oauth-token')
    auth.setUser({ id: 9, is_admin: true })
    expect(localStorage.getItem('token')).toBe('oauth-token')
    expect(JSON.parse(localStorage.getItem('user')).id).toBe(9)
    expect(auth.isAdmin).toBe(true)
  })

  it('markTutorialCompleted stamps the user and is a no-op when signed out', () => {
    const auth = useAuthStore()
    auth.markTutorialCompleted()
    expect(localStorage.getItem('user')).toBeNull()

    auth.setUser({ id: 1 })
    auth.markTutorialCompleted()
    expect(auth.user.tutorial_completed_at).toMatch(/^\d{4}-\d{2}-\d{2}T/)
    expect(JSON.parse(localStorage.getItem('user')).tutorial_completed_at).toBe(auth.user.tutorial_completed_at)
  })

  it('refreshUser replaces the user and swallows errors', async () => {
    const auth = useAuthStore()
    authApi.me.mockResolvedValueOnce({ data: { id: 5, name: 'Ada' } })
    await auth.refreshUser()
    expect(auth.user).toEqual({ id: 5, name: 'Ada' })

    authApi.me.mockRejectedValueOnce(new Error('offline'))
    await expect(auth.refreshUser()).resolves.toBeUndefined()
    expect(auth.user).toEqual({ id: 5, name: 'Ada' })
  })
})
