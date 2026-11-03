// CAPTCHA provider scripts (explicit rendering), loaded once per page and shared by every widget:
// Cloudflare Turnstile, Google reCAPTCHA (v2 checkbox), hCaptcha.

export const CAPTCHA_PROVIDERS = ['turnstile', 'recaptcha', 'hcaptcha']

export const CAPTCHA_SCRIPTS = {
  turnstile: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
  recaptcha: 'https://www.google.com/recaptcha/api.js?render=explicit',
  hcaptcha:  'https://js.hcaptcha.com/1/api.js?render=explicit',
}

// How long a script may take before the widget counts as unavailable
export const SCRIPT_TIMEOUT_MS = 15000

const loaders = {}

const globals = { turnstile: 'turnstile', recaptcha: 'grecaptcha', hcaptcha: 'hcaptcha' }

/** window.turnstile / window.grecaptcha / window.hcaptcha, or null. */
export function captchaApi(provider) {
  if (typeof window === 'undefined' || !globals[provider]) return null
  const api = window[globals[provider]] ?? null
  // reCAPTCHA's object exists before it is ready: wait for render()
  return api && typeof api.render === 'function' ? api : null
}

/** Resolves when the provider's API is available; rejects when its script fails or times out. */
export function loadCaptchaScript(provider, timeoutMs = SCRIPT_TIMEOUT_MS) {
  if (!CAPTCHA_SCRIPTS[provider]) return Promise.reject(new Error(`unknown captcha provider ${provider}`))
  if (captchaApi(provider)) return Promise.resolve()
  if (loaders[provider]) return loaders[provider]

  loaders[provider] = new Promise((resolve, reject) => {
    let script = document.querySelector(`script[data-captcha="${provider}"]`)
    if (!script) {
      script = document.createElement('script')
      script.src = CAPTCHA_SCRIPTS[provider]
      script.async = true
      script.defer = true
      script.dataset.captcha = provider
      document.head.appendChild(script)
    }
    const fail = (message) => {
      delete loaders[provider]
      reject(new Error(message))
    }
    const timer = timeoutMs > 0 ? setTimeout(() => fail('captcha script timed out'), timeoutMs) : null
    script.addEventListener('load', () => {
      clearTimeout(timer)
      // reCAPTCHA defines render() a moment after its script ran
      if (provider === 'recaptcha' && !captchaApi(provider) && window.grecaptcha?.ready) {
        window.grecaptcha.ready(() => resolve())
      } else {
        resolve()
      }
    })
    script.addEventListener('error', () => {
      clearTimeout(timer)
      fail('captcha script failed to load')
    })
  })

  return loaders[provider]
}

/** Tests: forget loaded scripts. */
export function resetCaptchaLoaders() {
  for (const key of Object.keys(loaders)) delete loaders[key]
}
