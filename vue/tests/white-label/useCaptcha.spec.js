import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import client from '@/api/client'
import { solveInBrowser, useCaptcha } from '@/modules/white-label/composables/useCaptcha'
import { hashOf } from '@/modules/white-label/utils/captchaPow'
import { flush, ok } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const SALT = '0123456789abcdef0123456789abcdef?expires=1762300600'
const CHALLENGE = { algorithm: 'SHA-256', salt: SALT, hash: hashOf(SALT, 777), signature: 'ab'.repeat(32), maxnumber: 2000 }

const config = (over = {}) => ({ provider: 'turnstile', site_key: '1x00000000000000000000AA', fallback: 'local', forms: ['login', 'register'], ...over })
const refused = (status, data) => Object.assign(new Error(`HTTP ${status}`), { response: { status, data } })

async function captchaFor(form, data = config(), options = {}) {
  vi.mocked(client.get).mockResolvedValue(ok(data))
  const captcha = useCaptcha(form, options)
  await flush()
  return captcha
}

describe('useCaptcha', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
    vi.mocked(client.post).mockReset()
  })

  it('reads the protection of the form, also for an organization', async () => {
    const tenant = ref(null)
    const captcha = await captchaFor('login', config(), { tenant })
    expect(client.get).toHaveBeenCalledWith('/captcha/config', { params: {} })
    expect(captcha.required).toBe(true)
    expect(captcha.showWidget).toBe(true)
    expect(captcha.siteKey).toBe('1x00000000000000000000AA')

    tenant.value = 'acme'
    await flush()
    expect(client.get).toHaveBeenLastCalledWith('/captcha/config', { params: { tenant: 'acme' } })
  })

  it('a form outside `forms` and provider "none" need nothing', async () => {
    expect((await captchaFor('public_forms')).required).toBe(false)
    const off = await captchaFor('login', { provider: 'none', site_key: null, fallback: 'local', forms: [] })
    expect(off.required).toBe(false)
    expect(off.fields()).toEqual({})

    // an unreadable config is off
    vi.mocked(client.get).mockRejectedValue(new Error('offline'))
    const broken = useCaptcha('login')
    await flush()
    expect(broken.enabled).toBe(false)
  })

  it('sends the token with the form', async () => {
    const captcha = await captchaFor('login')
    captcha.setToken('tok-1')
    const send = vi.fn().mockResolvedValue({ data: { ok: true } })

    await expect(captcha.submit(send)).resolves.toEqual({ data: { ok: true } })
    expect(send).toHaveBeenCalledWith({ captcha_token: 'tok-1' })
  })

  it('409 fallback: solves the challenge and sends the form again with captcha_local', async () => {
    const solve = vi.fn().mockResolvedValue('bG9jYWw=')
    const captcha = await captchaFor('login', config(), { solve })
    captcha.setToken('tok-1')
    const send = vi.fn()
      .mockRejectedValueOnce(refused(409, { captcha: 'fallback', challenge: CHALLENGE }))
      .mockResolvedValueOnce({ data: { token: 't' } })

    await expect(captcha.submit(send)).resolves.toEqual({ data: { token: 't' } })
    expect(solve).toHaveBeenCalledWith(CHALLENGE, expect.objectContaining({ onProgress: expect.any(Function) }))
    expect(send.mock.calls).toEqual([[{ captcha_token: 'tok-1' }], [{ captcha_local: 'bG9jYWw=' }]])
    expect(captcha.state).toBe('solved')
    expect(captcha.solveMs).toEqual(expect.any(Number))
  })

  it('422 resets the answer and never falls back', async () => {
    const solve = vi.fn()
    const captcha = await captchaFor('login', config(), { solve })
    captcha.setToken('tok-1')
    const resets = captcha.resets
    const error = refused(422, { captcha: 'invalid', message: 'nope' })

    await expect(captcha.submit(vi.fn().mockRejectedValue(error))).rejects.toBe(error)
    expect(captcha.token).toBe('')
    expect(captcha.resets).toBe(resets + 1)
    expect(solve).not.toHaveBeenCalled()
  })

  it('any refused submit spends the answer — unless the next step reuses it', async () => {
    const captcha = await captchaFor('login')
    captcha.setToken('tok-1')
    const wrongCode = refused(422, { message: 'The two-factor code is invalid.' })

    await expect(captcha.submit(vi.fn().mockRejectedValue(wrongCode), { keep: true })).rejects.toBe(wrongCode)
    expect(captcha.token).toBe('tok-1')

    // a refusal of the bot check itself always starts over
    await expect(captcha.submit(vi.fn().mockRejectedValue(refused(422, { captcha: 'invalid' })), { keep: true })).rejects.toBeTruthy()
    expect(captcha.token).toBe('')

    captcha.setToken('tok-2')
    await expect(captcha.submit(vi.fn().mockRejectedValue(refused(401, { message: 'wrong password' })))).rejects.toBeTruthy()
    expect(captcha.token).toBe('')
  })

  it('a 422 "required" on a form outside `forms` asks for the widget (the demo sign-in)', async () => {
    const captcha = await captchaFor('login', config({ forms: ['demo_login'] }))
    expect(captcha.required).toBe(false)

    await expect(captcha.submit(vi.fn().mockRejectedValue(refused(422, { captcha: 'required' })))).rejects.toBeTruthy()
    expect(captcha.required).toBe(true)
  })

  it('the widget cannot load: a local challenge while the provider is down', async () => {
    const solve = vi.fn().mockResolvedValue('c29sdmVk')
    const captcha = await captchaFor('register', config(), { solve })
    vi.mocked(client.post).mockResolvedValue(ok({ challenge: CHALLENGE }))

    await captcha.widgetUnavailable()

    expect(client.post).toHaveBeenCalledWith('/captcha/local/challenge', { form: 'register' })
    expect(captcha.local).toBe('c29sdmVk')
    expect(captcha.showWidget).toBe(false)
    expect(captcha.fields()).toEqual({ captcha_local: 'c29sdmVk' })
  })

  it('the widget cannot load and the provider answers the API: say so', async () => {
    const captcha = await captchaFor('login', config(), { tenant: 'acme', solve: vi.fn() })
    vi.mocked(client.post).mockRejectedValue(refused(409, { captcha: 'provider' }))

    await captcha.widgetUnavailable()

    expect(client.post).toHaveBeenCalledWith('/captcha/local/challenge', { form: 'login', tenant: 'acme' })
    expect(captcha.problem).toBe('loadFailed')
    expect(captcha.showWidget).toBe(false)

    captcha.reset()
    expect(captcha.problem).toBe(null)
  })

  it('a wrong configuration (misconfigured): no widget, the local check at once', async () => {
    const solve = vi.fn().mockResolvedValue('bWlzY29uZmln')
    vi.mocked(client.post).mockResolvedValue(ok({ challenge: CHALLENGE }))
    const captcha = await captchaFor('login', config({ site_key: null, misconfigured: true }), { tenant: 'acme', solve })

    expect(captcha.misconfigured).toBe(true)
    expect(captcha.showWidget).toBe(false)
    expect(client.post).toHaveBeenCalledWith('/captcha/local/challenge', { form: 'login', tenant: 'acme' })
    expect(solve).toHaveBeenCalledWith(CHALLENGE, expect.any(Object))
    expect(captcha.state).toBe('solved')
    expect(captcha.problem).toBe(null)
    expect(captcha.fields()).toEqual({ captcha_local: 'bWlzY29uZmln' })

    // a form the protection does not guard asks for nothing
    vi.mocked(client.post).mockClear()
    await captchaFor('public_forms', config({ misconfigured: true }), { solve })
    expect(client.post).not.toHaveBeenCalled()
  })

  it('misconfigured, but the API reaches the provider after all: the widget comes back', async () => {
    vi.mocked(client.post).mockRejectedValue(refused(409, { captcha: 'provider' }))
    const captcha = await captchaFor('login', config({ misconfigured: true }), { solve: vi.fn() })

    expect(client.post).toHaveBeenCalledTimes(1)
    expect(captcha.misconfigured).toBe(false)
    expect(captcha.showWidget).toBe(true)
    expect(captcha.problem).toBe(null)
  })

  it('misconfigured with the fallback off: no widget, no challenge (the form answers 503)', async () => {
    const captcha = await captchaFor('login', config({ fallback: 'off', misconfigured: true }), { solve: vi.fn() })

    expect(captcha.showWidget).toBe(false)
    expect(client.post).not.toHaveBeenCalled()
  })

  it('a failed scan is reported', async () => {
    const captcha = await captchaFor('login', config(), { solve: vi.fn().mockRejectedValue(new Error('unsolved')) })
    const send = vi.fn().mockRejectedValue(refused(409, { captcha: 'fallback', challenge: CHALLENGE }))

    await expect(captcha.submit(send)).rejects.toMatchObject({ captchaSolveFailed: true })
    expect(captcha.state).toBe('failed')
    expect(captcha.problem).toBe('solveFailed')
    expect(send).toHaveBeenCalledTimes(1)
  })
})

describe('solveInBrowser', () => {
  it('solves on the main thread where Web Workers are missing', async () => {
    expect(typeof Worker).toBe('undefined')
    const progress = vi.fn()
    const payload = await solveInBrowser(CHALLENGE, { onProgress: progress })
    expect(JSON.parse(atob(payload))).toMatchObject({ number: 777, salt: SALT })
    await nextTick()
  })

  it('uses a Web Worker when there is one', async () => {
    const workers = []
    class FakeWorker {
      constructor(url, options) { this.url = String(url); this.options = options; this.terminated = false; workers.push(this) }
      postMessage(message) {
        queueMicrotask(() => {
          this.onmessage({ data: { type: 'progress', done: 1000, total: 2000 } })
          this.onmessage({ data: { type: 'solved', number: 777, payload: 'from-worker', took: 5 } })
        })
        this.message = message
      }
      terminate() { this.terminated = true }
    }
    vi.stubGlobal('Worker', FakeWorker)
    try {
      const progress = vi.fn()
      await expect(solveInBrowser(CHALLENGE, { onProgress: progress })).resolves.toBe('from-worker')
      expect(workers[0].url).toContain('captchaPow.worker')
      expect(workers[0].options).toEqual({ type: 'module' })
      expect(workers[0].message).toEqual({ challenge: CHALLENGE })
      expect(workers[0].terminated).toBe(true)
      expect(progress).toHaveBeenCalledWith(1000, 2000)
    } finally {
      vi.unstubAllGlobals()
    }
  })
})
