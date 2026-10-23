import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { h } from 'vue'
import { withPlugins } from '../helpers'

// Sign-in page, the identifier field: the user name or the e-mail address in one plain text
// field (an e-mail field would make the browser refuse a user name), sent as `login`.
const client = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/api/client', () => ({ default: client }))
const authApi = vi.hoisted(() => ({
  registrationStatus: vi.fn(() => Promise.resolve({ data: { enabled: false } })),
  login: vi.fn(),
}))
vi.mock('@/api/auth', () => ({ authApi }))
vi.mock('@/components/RegisterForm.vue', () => ({ default: { name: 'RegisterForm', render: () => h('div') } }))
vi.mock('@/components/RunrWordmark.vue', () => ({ default: { name: 'RunrWordmark', render: () => h('div') } }))

const route = vi.hoisted(() => ({ query: {} }))
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => route, useRouter: () => router }))

import LoginView from '@/views/LoginView.vue'

function mountLogin(locale = 'en') {
  return mount(LoginView, { global: withPlugins(locale).global })
}

async function submit(w, login, password = 'secret-password') {
  await w.find('#login-identifier').setValue(login)
  await w.find('#login-password').setValue(password)
  await w.find('form').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  route.query = {}
  router.push.mockReset()
  authApi.login.mockReset()
  authApi.login.mockResolvedValue({ status: 200, data: { token: '1|session', user: { id: 1, username: 'admin' } } })
  client.get.mockResolvedValue({ data: [] })
  localStorage.clear()
})

describe('LoginView identifier field', () => {
  it('is a plain text field labelled “E-mail or user name”', async () => {
    const w = mountLogin()
    await flushPromises()

    const input = w.find('#login-identifier')
    expect(input.attributes('type')).toBe('text')
    expect(input.attributes('autocomplete')).toBe('username')
    expect(input.attributes('autocapitalize')).toBe('none')
    expect(input.attributes('placeholder')).toBe('E-mail or user name')
    expect(w.find('label[for="login-identifier"]').text()).toBe('E-mail or user name')
  })

  it('reads “E-posta veya kullanıcı adı” in Turkish', async () => {
    const w = mountLogin('tr')
    await flushPromises()

    expect(w.find('label[for="login-identifier"]').text()).toBe('E-posta veya kullanıcı adı')
    expect(w.find('#login-identifier').attributes('placeholder')).toBe('E-posta veya kullanıcı adı')
  })

  it('signs in with the user name', async () => {
    const w = mountLogin()
    await flushPromises()

    await submit(w, 'admin')

    expect(authApi.login).toHaveBeenCalledWith('admin', 'secret-password', null, null)
    expect(router.push).toHaveBeenCalledWith('/projects')
  })

  it('signs in with the e-mail address, without the white space around it', async () => {
    const w = mountLogin()
    await flushPromises()

    await submit(w, '  Admin@Example.com ')

    expect(authApi.login).toHaveBeenCalledWith('Admin@Example.com', 'secret-password', null, null)
    expect(router.push).toHaveBeenCalledWith('/projects')
  })

  it('shows the API’s answer to wrong credentials and stays on the page', async () => {
    authApi.login.mockRejectedValue({ response: { status: 401, data: { message: 'Invalid username/email or password.' } } })
    const w = mountLogin()
    await flushPromises()

    await submit(w, 'admin', 'wrong')

    expect(w.text()).toContain('Invalid username/email or password.')
    expect(router.push).not.toHaveBeenCalled()
  })

  it('asks for the second factor and sends the same identifier with the code', async () => {
    authApi.login
      .mockResolvedValueOnce({ status: 202, data: { requires_2fa: true } })
      .mockResolvedValueOnce({ status: 200, data: { token: '1|session', user: { id: 1, username: 'admin' } } })
    const w = mountLogin()
    await flushPromises()

    await submit(w, 'admin')
    await w.find('input[autocomplete="one-time-code"]').setValue('123456')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(authApi.login).toHaveBeenLastCalledWith('admin', 'secret-password', null, '123456')
    expect(router.push).toHaveBeenCalledWith('/projects')
  })
})
