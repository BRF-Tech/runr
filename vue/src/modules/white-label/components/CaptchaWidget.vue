<script setup>
// CAPTCHA of the signup form: Cloudflare Turnstile or hCaptcha, rendered explicitly into this
// component's element. Emits the response token ('' when it expires or fails). provider "none"
// renders nothing.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { captchaApi, loadCaptchaScript } from '../utils/captcha'

const props = defineProps({
  provider: { type: String, default: 'none' },
  siteKey:  { type: String, default: '' },
  theme:    { type: String, default: 'light' },
})
const emit = defineEmits(['token'])
const { t } = useI18n()

const el = ref(null)
const failed = ref(false)
let widgetId = null

const active = () => ['turnstile', 'hcaptcha'].includes(props.provider) && !!props.siteKey

async function render() {
  if (!active()) return
  failed.value = false
  try {
    await loadCaptchaScript(props.provider)
    const api = captchaApi(props.provider)
    if (!api || !el.value) return
    widgetId = api.render(el.value, {
      sitekey: props.siteKey,
      theme: props.theme,
      callback: (token) => emit('token', token || ''),
      'expired-callback': () => emit('token', ''),
      'error-callback': () => emit('token', ''),
    })
  } catch {
    failed.value = true
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
  <div v-if="provider !== 'none' && siteKey" data-test="captcha">
    <div ref="el" class="min-h-[65px]" />
    <p v-if="failed" class="text-xs mt-1" style="color:#dc2626">{{ t('whiteLabel.signup.captchaLoadFailed') }}</p>
  </div>
</template>
