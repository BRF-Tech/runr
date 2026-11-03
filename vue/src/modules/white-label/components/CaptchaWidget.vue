<script setup>
// The provider's CAPTCHA widget of the bot protection: Cloudflare Turnstile (managed), Google
// reCAPTCHA (v2 checkbox) or hCaptcha, rendered explicitly into this component's element. Emits
// the response token ('' when it expires or fails) and `unavailable` when the widget cannot load
// (script blocked or timed out, the provider's own error): CaptchaField then asks the API for the
// local check. provider "none" renders nothing.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { CAPTCHA_PROVIDERS, captchaApi, loadCaptchaScript } from '../utils/captcha'

const props = defineProps({
  provider: { type: String, default: 'none' },
  siteKey:  { type: String, default: '' },
  theme:    { type: String, default: 'light' },
})
const emit = defineEmits(['token', 'unavailable'])
const { t, locale } = useI18n()

const el = ref(null)
const failed = ref(false)
let widgetId = null

const active = () => CAPTCHA_PROVIDERS.includes(props.provider) && !!props.siteKey

function unavailable() {
  failed.value = true
  emit('token', '')
  emit('unavailable')
}

async function render() {
  if (!active()) return
  failed.value = false
  try {
    await loadCaptchaScript(props.provider)
    const api = captchaApi(props.provider)
    if (!api || !el.value) return
    widgetId = api.render(el.value, {
      sitekey: props.siteKey,
      // reCAPTCHA knows light and dark only
      theme: props.provider === 'recaptcha' && props.theme === 'auto' ? 'light' : props.theme,
      ...(props.provider === 'turnstile' ? { language: locale.value === 'tr' ? 'tr' : 'en' } : {}),
      callback: (token) => emit('token', token || ''),
      'expired-callback': () => emit('token', ''),
      // Turnstile reports a widget that cannot reach Cloudflare here; the others a failed challenge
      'error-callback': () => (props.provider === 'turnstile' ? unavailable() : emit('token', '')),
    })
  } catch {
    unavailable()
  }
}

function destroy() {
  const api = captchaApi(props.provider)
  if (api && widgetId !== null) {
    try { api.remove?.(widgetId) } catch { /* already gone */ }
  }
  widgetId = null
}

/** Fresh challenge (tokens are single-use: call after a rejected submit). */
function reset() {
  const api = captchaApi(props.provider)
  if (api && widgetId !== null) {
    try { api.reset(widgetId) } catch { /* ignore */ }
  }
  emit('token', '')
}

defineExpose({ reset })

onMounted(render)
onBeforeUnmount(destroy)
watch(() => [props.provider, props.siteKey], () => { destroy(); render() })
</script>

<template>
  <div v-if="provider !== 'none' && siteKey" data-test="captcha" :data-captcha-provider="provider">
    <div ref="el" class="min-h-[65px]" />
    <p v-if="failed" class="text-xs mt-1" style="color:#dc2626" role="status">{{ t('whiteLabel.signup.captchaLoadFailed') }}</p>
  </div>
</template>
