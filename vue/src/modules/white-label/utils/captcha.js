// CAPTCHA provider scripts (explicit rendering), loaded once per page and shared by every widget.

export const CAPTCHA_SCRIPTS = {
  turnstile: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
  hcaptcha:  'https://js.hcaptcha.com/1/api.js?render=explicit',
}

const loaders = {}

/** window.turnstile / window.hcaptcha, or null. */
export function captchaApi(provider) {
  if (typeof window === 'undefined') return null
  return provider === 'turnstile' ? (window.turnstile ?? null) : provider === 'hcaptcha' ? (window.hcaptcha ?? null) : null
}

/** Resolves when the provider's API is available. */
export function loadCaptchaScript(provider) {
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
    script.addEventListener('load', () => resolve())
    script.addEventListener('error', () => {
      delete loaders[provider]
      reject(new Error('captcha script failed to load'))
    })
  })

  return loaders[provider]
}

/** Tests: forget loaded scripts. */
export function resetCaptchaLoaders() {
  for (const key of Object.keys(loaders)) delete loaders[key]
}
