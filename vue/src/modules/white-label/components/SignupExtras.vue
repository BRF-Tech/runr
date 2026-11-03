<script setup>
// Register form, step 2 (moduleRegistry.registerExtensions): what GET /api/auth/registration-status
// asks for — terms / privacy acceptance (accept_terms) — plus a hint when only some e-mail domains
// may sign up. The CAPTCHA is the bot protection's, rendered by RegisterForm itself (CaptchaField).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { signupApi } from '../api/whiteLabel'

const model = defineModel({ type: Object, default: () => ({}) })
defineProps({
  // incremented by the form after a rejected submit (extensions with a single-use answer start over)
  attempt: { type: Number, default: 0 },
})
const { t } = useI18n()

const status = ref(null)

const needsTerms = computed(() => !!(status.value?.terms_url || status.value?.privacy_url))
const allowedDomains = computed(() => status.value?.allowed_domains ?? [])

function patch(values) {
  model.value = { ...(model.value || {}), ...values }
}

onMounted(async () => {
  try {
    const { data } = await signupApi.status()
    status.value = data
  } catch {
    status.value = null
  }
})
</script>

<template>
  <div v-if="status" class="space-y-5" data-test="signup-extras">
    <!-- Allowed e-mail domains -->
    <p v-if="allowedDomains.length" class="text-xs" style="color:rgb(var(--surface-light-600))" data-test="allowed-domains">
      {{ t('whiteLabel.signup.allowedDomainsHint', { domains: allowedDomains.join(', ') }) }}
    </p>

    <!-- Terms / privacy -->
    <label v-if="needsTerms" class="flex items-start gap-2 text-sm cursor-pointer" style="color:rgb(var(--surface-light-500))" data-test="terms">
      <input type="checkbox" class="mt-1" :checked="!!model?.accept_terms" data-test="terms-checkbox"
        @change="patch({ accept_terms: $event.target.checked })" />
      <span>
        {{ t('whiteLabel.signup.acceptPrefix') }}
        <a v-if="status.terms_url" :href="status.terms_url" target="_blank" rel="noopener noreferrer"
          class="font-semibold hover:underline" style="color:rgb(var(--brand-600))">{{ t('whiteLabel.signup.terms') }}</a>
        <template v-if="status.terms_url && status.privacy_url"> {{ t('whiteLabel.signup.and') }} </template>
        <a v-if="status.privacy_url" :href="status.privacy_url" target="_blank" rel="noopener noreferrer"
          class="font-semibold hover:underline" style="color:rgb(var(--brand-600))">{{ t('whiteLabel.signup.privacy') }}</a>
        {{ t('whiteLabel.signup.acceptSuffix') }}
      </span>
    </label>
  </div>
</template>
