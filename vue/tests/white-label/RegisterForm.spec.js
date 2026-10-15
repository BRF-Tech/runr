import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { authApi } from '@/api/auth'
import RegisterForm from '@/components/RegisterForm.vue'
import { flush, globalOptions, makeRouter } from './helpers'

vi.mock('@/api/auth', () => ({
  authApi: { register: vi.fn(), resendSignup: vi.fn(), login: vi.fn(), logout: vi.fn(), me: vi.fn(), registrationStatus: vi.fn() },
}))
vi.mock('@/modules/index', async () => {
  const { reactive, markRaw, defineComponent, h } = await import('vue')
  // A register extension standing in for SignupExtras: sets payload fields through v-model
  const Extension = markRaw(defineComponent({
    props: { modelValue: { type: Object, default: () => ({}) }, attempt: { type: Number, default: 0 } },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
      emit('update:modelValue', { ...props.modelValue, accept_terms: true, captcha_token: 'tok' })
      return () => h('span', { 'data-test': 'ext', 'data-attempt': props.attempt })
    },
  }))
  return {
    moduleRegistry: reactive({ registerSlots: {}, registerExtensions: [Extension], loginHooks: [], viewKey: 0 }),
  }
})

async function fillAndSubmit(wrapper) {
  const inputs = wrapper.findAll('input')
  await inputs[0].setValue('Ada Lovelace')
  await inputs[1].setValue('ada')
  await inputs[2].setValue('ada@acme.test')
  await wrapper.find('button.login-submit').trigger('click')            // → step 2
  const pw = wrapper.findAll('input[type="password"]')
  await pw[0].setValue('secret-password')
  await pw[1].setValue('secret-password')
  await wrapper.find('button.login-submit').trigger('click')            // submit
  await flush()
}

describe('RegisterForm (white-label extensions)', () => {
  beforeEach(() => {
    localStorage.clear()
    for (const fn of Object.values(authApi)) vi.mocked(fn).mockReset()
  })

  it('merges extension fields into the payload and shows the check-inbox state on 202', async () => {
    vi.mocked(authApi.register).mockResolvedValue({ status: 202, data: { verification_required: true, email: 'ada@acme.test' } })
    vi.mocked(authApi.resendSignup).mockResolvedValue({ status: 200, data: { message: 'ok' } })
    const router = await makeRouter('/login')
    const wrapper = mount(RegisterForm, { global: globalOptions({ router }) })

    await fillAndSubmit(wrapper)

    const payload = vi.mocked(authApi.register).mock.calls[0][0]
    expect(payload).toMatchObject({ name: 'Ada Lovelace', username: 'ada', email: 'ada@acme.test', accept_terms: true, captcha_token: 'tok' })

    const inbox = wrapper.find('[data-test="signup-check-inbox"]')
    expect(inbox.exists()).toBe(true)
    expect(inbox.text()).toContain('ada@acme.test')
    // no session yet: the account is created when the link is opened
    expect(localStorage.getItem('token')).toBeNull()

    await wrapper.find('[data-test="signup-resend"]').trigger('click')
    await flush()
    expect(authApi.resendSignup).toHaveBeenCalledWith('ada@acme.test')
    expect(wrapper.text()).toContain('a new link is on its way')
  })

  it('signs in right away on 201 (no verification)', async () => {
    vi.mocked(authApi.register).mockResolvedValue({ status: 201, data: { user: { id: 'u1', email: 'ada@acme.test' }, token: '1|abc' } })
    const router = await makeRouter('/login')
    const wrapper = mount(RegisterForm, { global: globalOptions({ router }) })

    await fillAndSubmit(wrapper)

    expect(localStorage.getItem('token')).toBe('1|abc')
    expect(router.currentRoute.value.path).toBe('/projects')
  })

  it('shows the invite-only refusal and asks the extension for a fresh CAPTCHA', async () => {
    vi.mocked(authApi.register).mockRejectedValue({ response: { status: 403, data: { code: 'invite_only' } } })
    const router = await makeRouter('/login')
    const wrapper = mount(RegisterForm, { global: globalOptions({ router }) })

    await fillAndSubmit(wrapper)

    expect(wrapper.text()).toContain('Sign-up is by invitation only')
    expect(wrapper.find('[data-test="ext"]').attributes('data-attempt')).toBe('1')
  })
})
