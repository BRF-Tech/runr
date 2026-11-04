<script setup>
// The provider's CAPTCHA widget of the bot protection: Cloudflare Turnstile (managed), Google
// reCAPTCHA (v2 checkbox) or hCaptcha, rendered explicitly into this component's element. Emits
// the response token ('' when it expires or fails) and `unavailable` when the widget cannot load
// (script blocked or timed out, the provider's own error): CaptchaField then asks the API for the
// local check. provider "none" renders nothing.
//
// Its theme (all three providers know light and dark): `theme` when the surface it sits on has a
// fixed one (the sign-in and sign-up cards are light in both console themes), else the console's
// active theme, followed when it changes (the widget is rendered again; its token goes). The
// container carries the same color-scheme: the provider's iframe takes it from there, not from
// the page (the console's root is dark by default).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTheme } from '@/composables/useTheme'
import { CAPTCHA_PROVIDERS, captchaApi, loadCaptchaScript } from '../utils/captcha'

const props = defineProps({
  provider: { type: String, default: 'none' },
  siteKey:  { type: String, default: '' },
  // 'light' | 'dark'; anything else ('auto', none): the console's theme
  theme:    { type: String, default: null },
})
const emit = defineEmits(['token', 'unavailable'])
const { t, locale } = useI18n()
const { current: consoleTheme } = useTheme()

const widgetTheme = computed(() => (['light', 'dark'].includes(props.theme) ? props.theme : consoleTheme.value === 'light' ? 'light' : 'dark'))

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
    // a fresh element per widget: reCAPTCHA renders into an element once and cannot remove a widget
    const target = document.createElement('div')
    el.value.replaceChildren(target)
    widgetId = api.render(target, {
      sitekey: props.siteKey,
      theme: widgetTheme.value,
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
// the provider sets the theme when it renders: a new theme is a new widget
watch(widgetTheme, () => {
  if (widgetId === null) return
  destroy()
  emit('token', '')
  render()
})
</script>

<template>
  <div v-if="provider !== 'none' && siteKey" data-test="captcha" :data-captcha-provider="provider">
    <div ref="el" class="min-h-[65px]" :style="{ colorScheme: widgetTheme }" :data-captcha-theme="widgetTheme" />
    <p v-if="failed" class="text-xs mt-1" style="color:#dc2626" role="status">{{ t('whiteLabel.signup.captchaLoadFailed') }}</p>
  </div>
</template>
