// The bot protection of one anonymous form (docs/security.md#bot-protection): what the form shows
// (CaptchaField) and what it sends.
//
//   const captcha = useCaptcha('login', { tenant: computed(() => slug) })
//   <CaptchaField :captcha="captcha" />
//   const { data } = await captcha.submit((fields) => api.post(url, { ...form, ...fields }))
//
// submit() sends the provider token (captcha_token) or the local answer (captcha_local); on
// 409 {captcha: "fallback", challenge} it solves the challenge in a Web Worker and sends the form
// again by itself. A refused submit spends the answer: the widget starts over (422 never falls
// back). keep: true keeps the answer through an error that is not the CAPTCHA's (the two-factor
// step reuses the password step's answer). When the widget cannot load, the API is asked for a
// local challenge (POST /captcha/local/challenge) — it hands one out only while the provider is down.
// A configuration the provider cannot be asked with (config.misconfigured: no site key, no secret)
// shows no widget: the form asks for the local check as soon as it loads.
import { computed, reactive, ref, unref, watch } from 'vue'
import { botProtectionApi } from '../api/whiteLabel'
import { FIELD, encodeSolution, isChallenge, solveChallengeAsync } from '../utils/captchaPow'

const OFF = Object.freeze({ provider: 'none', site_key: null, fallback: 'local', forms: [], misconfigured: false })

/** Solves a challenge in a Web Worker (main-thread slices where workers are missing). */
export function solveInBrowser(challenge, { onProgress, signal } = {}) {
  if (typeof Worker === 'undefined') {
    return solveChallengeAsync(challenge, { onProgress, signal })
      .then((number) => (number === null ? Promise.reject(new Error('unsolved')) : encodeSolution(challenge, number)))
  }

  return new Promise((resolve, reject) => {
    let worker
    try {
      worker = new Worker(new URL('../workers/captchaPow.worker.js', import.meta.url), { type: 'module' })
    } catch {
      solveChallengeAsync(challenge, { onProgress, signal })
        .then((number) => (number === null ? reject(new Error('unsolved')) : resolve(encodeSolution(challenge, number))), reject)
      return
    }
    const stop = () => { try { worker.terminate() } catch { /* gone */ } }
    signal?.addEventListener('abort', () => { stop(); reject(new DOMException('aborted', 'AbortError')) }, { once: true })
    worker.onmessage = ({ data }) => {
      if (data?.type === 'progress') onProgress?.(data.done, data.total)
      else if (data?.type === 'solved') { stop(); resolve(data.payload) }
      else if (data?.type === 'error') { stop(); reject(new Error(data.error || 'unsolved')) }
    }
    worker.onerror = (event) => { stop(); reject(new Error(event?.message || 'worker failed')) }
    worker.postMessage({ challenge })
  })
}

export function useCaptcha(form, { tenant = null, solve = solveInBrowser } = {}) {
  const config = ref(null)
  const token = ref('')
  const local = ref('')
  // idle | solving | solved | failed
  const state = ref('idle')
  const progress = ref(0)
  const solveMs = ref(null)
  // widgetFailed | loadFailed | solveFailed | null
  const problem = ref(null)
  // a form outside `forms` asked for the check anyway (the demo sign-in when only demo_login is guarded)
  const asked = ref(false)
  // bumped to make CaptchaField reset the provider's widget
  const resets = ref(0)
  let controller = null

  const tenantSlug = computed(() => unref(tenant) || null)
  const provider = computed(() => config.value?.provider ?? 'none')
  const siteKey = computed(() => config.value?.site_key ?? '')
  const enabled = computed(() => provider.value !== 'none')
  const required = computed(() => enabled.value && ((config.value?.forms ?? []).includes(form) || asked.value))
  const misconfigured = computed(() => enabled.value && config.value?.misconfigured === true)
  const showWidget = computed(() => required.value && !!siteKey.value && !misconfigured.value && !local.value && state.value !== 'solving' && problem.value !== 'loadFailed')

  async function load() {
    try {
      const { data } = await botProtectionApi.config(tenantSlug.value)
      config.value = data && typeof data.provider === 'string' ? { ...OFF, ...data } : OFF
    } catch {
      config.value = OFF
    }
    if (misconfigured.value && required.value) await misconfiguredCheck()
  }

  /**
   * The provider cannot be asked (a key is missing): the local check right away, no widget. The
   * API answering 409 {captcha: "provider"} after all (the keys were fixed meanwhile) brings the
   * widget back; any other failure is left to the form's own 409 on submit.
   */
  async function misconfiguredCheck() {
    if (local.value || state.value === 'solving' || config.value?.fallback !== 'local') return
    try {
      const { data } = await botProtectionApi.challenge(form, tenantSlug.value)
      if (isChallenge(data?.challenge)) await solveAndKeep(data.challenge)
    } catch (e) {
      if (e?.response?.status === 409 && e.response.data?.captcha === 'provider') {
        config.value = { ...config.value, misconfigured: false }
      }
    }
  }

  /** What the form sends along. */
  function fields() {
    if (local.value) return { [FIELD]: local.value }
    if (token.value) return { captcha_token: token.value }
    return {}
  }

  function setToken(value) {
    token.value = value || ''
    if (token.value) problem.value = null
  }

  function reset() {
    controller?.abort()
    controller = null
    token.value = ''
    local.value = ''
    state.value = 'idle'
    progress.value = 0
    problem.value = null
    resets.value++
  }

  async function solveAndKeep(challenge) {
    controller?.abort()
    controller = new AbortController()
    state.value = 'solving'
    progress.value = 0
    problem.value = null
    const started = Date.now()
    try {
      local.value = await solve(challenge, {
        signal: controller.signal,
        onProgress: (done, total) => { progress.value = total ? Math.min(1, done / total) : 0 },
      })
      solveMs.value = Date.now() - started
      progress.value = 1
      state.value = 'solved'
      return local.value
    } catch (e) {
      if (e?.name !== 'AbortError') {
        state.value = 'failed'
        problem.value = 'solveFailed'
      }
      throw e
    } finally {
      controller = null
    }
  }

  /** The widget cannot load: a local challenge while the provider is down, else say so. */
  async function widgetUnavailable() {
    if (local.value || state.value === 'solving') return
    problem.value = 'widgetFailed'
    if (config.value?.fallback !== 'local') {
      problem.value = 'loadFailed'
      return
    }
    try {
      const { data } = await botProtectionApi.challenge(form, tenantSlug.value)
      if (!isChallenge(data?.challenge)) throw new Error('no challenge')
      await solveAndKeep(data.challenge)
    } catch (e) {
      if (e?.name !== 'AbortError' && state.value !== 'failed') problem.value = 'loadFailed'
    }
  }

  const isFallback = (e) => e?.response?.status === 409 && e.response.data?.captcha === 'fallback' && isChallenge(e.response.data.challenge)
  const isRefusal = (e) => !!e?.response?.data?.captcha && [409, 422, 503].includes(e.response.status)

  function failed(e, keep) {
    if (e?.response?.status === 422 && e.response.data?.captcha === 'required') asked.value = true
    // the answer is spent at the API, unless the step after it reuses it
    if (!keep || isRefusal(e)) reset()
  }

  /** Sends the form with the answer; solves the fallback challenge and sends it once more. */
  async function submit(send, { keep = false } = {}) {
    try {
      return await send(fields())
    } catch (e) {
      if (!isFallback(e)) {
        failed(e, keep)
        throw e
      }
      try {
        await solveAndKeep(e.response.data.challenge)
      } catch (solveError) {
        throw Object.assign(e, { captchaSolveFailed: true, cause: solveError })
      }
      try {
        return await send(fields())
      } catch (again) {
        failed(again, keep)
        throw again
      }
    }
  }

  watch(tenantSlug, () => { reset(); load() })
  load()

  // reactive: the refs read as plain values (captcha.required, captcha.state) in templates and props
  return reactive({
    form, config, token, local, state, progress, solveMs, problem, resets,
    provider, siteKey, enabled, required, misconfigured, showWidget,
    load, fields, setToken, reset, submit, widgetUnavailable, solveAndKeep,
  })
}
