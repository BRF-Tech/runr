<script setup>
// The bot protection of a form (composables/useCaptcha.js): the provider's widget, and — while
// the provider cannot be reached, or right away when its configuration is wrong (no widget then) —
// the local check the browser solves (a progress bar, readable by screen readers; the form is sent
// again by itself). Renders nothing while the form is not guarded.
// data-captcha-solve-ms carries how long the local check took (the e2e tests measure it).
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Loader2, ShieldCheck, ShieldAlert } from '@lucide/vue'
import CaptchaWidget from './CaptchaWidget.vue'

const props = defineProps({
  captcha: { type: Object, required: true },
  // 'light' | 'dark' for a surface with a fixed theme; none: the console's theme (CaptchaWidget)
  theme:   { type: String, default: null },
})
const { t } = useI18n()

const widget = ref(null)

watch(() => props.captcha.resets, () => widget.value?.reset())

function retry() {
  props.captcha.reset()
  props.captcha.load()
}
</script>

<template>
  <div v-if="captcha.required" class="space-y-2" data-test="captcha-field" :data-captcha-state="captcha.state">
    <CaptchaWidget v-if="captcha.showWidget" ref="widget" :provider="captcha.provider" :site-key="captcha.siteKey" :theme="theme"
      @token="captcha.setToken($event)" @unavailable="captcha.widgetUnavailable()" />

    <!-- Local check: the provider cannot be reached -->
    <div v-if="captcha.state === 'solving' || captcha.state === 'solved'" class="captcha-local rounded-md px-4 py-3 text-sm"
      role="status" aria-live="polite" data-test="captcha-local"
      :data-captcha-solve-ms="captcha.state === 'solved' && captcha.solveMs !== null ? captcha.solveMs : undefined">
      <div class="flex items-center gap-2">
        <Loader2 v-if="captcha.state === 'solving'" class="w-4 h-4 shrink-0 animate-spin" aria-hidden="true" />
        <ShieldCheck v-else class="w-4 h-4 shrink-0" style="color:#059669" aria-hidden="true" />
        <span>{{ captcha.state === 'solving' ? t('whiteLabel.captcha.solving') : t('whiteLabel.captcha.solved') }}</span>
      </div>
      <div v-if="captcha.state === 'solving'" class="captcha-bar mt-2 h-1.5 rounded-full overflow-hidden" role="progressbar"
        :aria-label="t('whiteLabel.captcha.progress')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(captcha.progress * 100)">
        <div class="h-full rounded-full transition-all duration-200" :style="{ width: `${Math.round(captcha.progress * 100)}%`, background: 'rgb(var(--brand-600))' }" />
      </div>
    </div>

    <!-- The widget cannot load and the provider answers the API: nothing to fall back on -->
    <div v-if="captcha.problem === 'loadFailed' || captcha.problem === 'solveFailed'" class="flex items-start gap-2 text-xs" style="color:#dc2626"
      role="alert" data-test="captcha-problem">
      <ShieldAlert class="w-4 h-4 shrink-0" aria-hidden="true" />
      <span class="flex-1">{{ captcha.problem === 'solveFailed' ? t('whiteLabel.captcha.solveFailed') : t('whiteLabel.captcha.loadFailed') }}</span>
      <button type="button" class="font-semibold hover:underline shrink-0" data-test="captcha-retry" @click="retry">{{ t('whiteLabel.captcha.retry') }}</button>
    </div>
  </div>
</template>

<style scoped>
.captcha-local { background: rgb(var(--surface-light-900)); border: 1px solid rgb(var(--surface-light-700)); color: rgb(var(--surface-light-300)); }
.captcha-bar { background: rgb(var(--surface-light-700)); }
</style>
