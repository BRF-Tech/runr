<script setup>
// Settings of a webhook inbox trigger: the inbox URL, signature verification (preset or a custom
// HMAC), the write-only signing secret and the advanced fields (delivery id header, timestamp
// tolerance, largest body, retention).
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDown, ChevronRight } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'
import CopyField from './CopyField.vue'
import { HMAC_ALGORITHMS, HMAC_ENCODINGS, WEBHOOK_PRESETS } from '../utils/triggers'

const props = defineProps({
  form:    { type: Object, required: true },     // editor state (utils emptyForm), mutated
  trigger: { type: Object, default: null },      // the stored trigger (url, has_signing_secret)
  presets: { type: Array, default: () => WEBHOOK_PRESETS },
  errors:  { type: Object, default: () => ({}) },
})

const { t } = useI18n()
const hasSecret = computed(() => !!(props.trigger?.has_signing_secret || props.trigger?.secrets?.includes('signing_secret')))
const advanced = ref(!!(props.form.delivery_id_header || props.form.tolerance_seconds || props.form.max_body_kb || props.form.retention_days))
const usesTimestamp = computed(() => ['stripe', 'slack'].includes(props.form.verify))
</script>

<template>
  <div class="space-y-4" data-test="webhook-fields">
    <!-- Inbox URL -->
    <div>
      <label class="label">{{ t('triggers.webhook.url') }}</label>
      <template v-if="trigger?.url">
        <CopyField :value="trigger.url" test-id="webhook-url" />
        <p class="text-[11px] text-gray-400 mt-1">{{ t('triggers.webhook.urlHint') }}</p>
      </template>
      <p v-else class="text-[11px] text-gray-400">{{ t('triggers.webhook.urlAfterSave') }}</p>
    </div>

    <!-- Verification -->
    <div>
      <label class="label">{{ t('triggers.webhook.verify') }}</label>
      <select v-model="form.verify" class="input text-xs" data-field="verify">
        <option v-for="p in presets" :key="p" :value="p">{{ t(`triggers.webhook.presets.${p}`) }}</option>
      </select>
      <p class="text-[11px] mt-1" :class="form.verify === 'none' ? 'text-amber-300' : 'text-gray-400'">{{ t(`triggers.webhook.presetHints.${form.verify}`) }}</p>
    </div>

    <div v-if="form.verify === 'hmac'" class="grid grid-cols-2 md:grid-cols-4 gap-3" data-test="webhook-hmac">
      <div>
        <label class="label">{{ t('triggers.webhook.hmacHeader') }}</label>
        <input v-model="form.hmac.header" class="input font-mono text-xs" placeholder="X-Signature" data-field="hmac.header" />
        <p v-if="errors['config.hmac.header']" class="text-[11px] text-red-400 mt-1">{{ errors['config.hmac.header'] }}</p>
      </div>
      <div>
        <label class="label">{{ t('triggers.webhook.hmacAlgorithm') }}</label>
        <select v-model="form.hmac.algorithm" class="input text-xs font-mono" data-field="hmac.algorithm">
          <option v-for="a in HMAC_ALGORITHMS" :key="a" :value="a">{{ a }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('triggers.webhook.hmacEncoding') }}</label>
        <select v-model="form.hmac.encoding" class="input text-xs font-mono" data-field="hmac.encoding">
          <option v-for="e in HMAC_ENCODINGS" :key="e" :value="e">{{ e }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('triggers.webhook.hmacPrefix') }}</label>
        <input v-model="form.hmac.prefix" class="input font-mono text-xs" placeholder="sha256=" maxlength="32" data-field="hmac.prefix" />
        <p v-if="errors['config.hmac.prefix']" class="text-[11px] text-red-400 mt-1">{{ errors['config.hmac.prefix'] }}</p>
      </div>
      <p class="col-span-2 md:col-span-4 text-[11px] text-gray-400 -mt-1">{{ t('triggers.webhook.hmacPrefixHint') }}</p>
    </div>

    <!-- Signing secret (write-only) -->
    <div v-if="form.verify !== 'none'">
      <label class="label">
        {{ t('triggers.webhook.secret') }}
        <span
          class="normal-case ml-1 px-1.5 py-0.5 rounded-sm text-[10px] border"
          :class="hasSecret ? 'border-emerald-800/50 bg-emerald-900/30 text-emerald-300' : 'border-gray-700 bg-gray-800 text-gray-400'"
          data-test="secret-state"
        >{{ hasSecret ? t('triggers.webhook.secretSet') : t('triggers.webhook.secretNotSet') }}</span>
      </label>
      <input
        v-model="form.signing_secret" type="password" autocomplete="new-password" class="input font-mono text-xs"
        :placeholder="hasSecret ? t('triggers.webhook.secretKeep') : t('triggers.webhook.secretNew')" data-field="signing_secret"
      />
      <p v-if="errors['secrets.signing_secret']" class="text-[11px] text-red-400 mt-1">{{ errors['secrets.signing_secret'] }}</p>
      <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.webhook.secretHint') }}</p>
    </div>

    <!-- Advanced -->
    <section class="rounded-lg border border-gray-800 bg-gray-900/40">
      <button type="button" class="w-full flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-gray-300 hover:text-gray-100" data-test="webhook-advanced" @click="advanced = !advanced">
        <component :is="advanced ? ChevronDown : ChevronRight" class="w-3.5 h-3.5" />{{ t('triggers.webhook.advanced') }}
      </button>
      <div v-if="advanced" class="px-3 pb-3 grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="label">{{ t('triggers.webhook.deliveryIdHeader') }}</label>
          <input v-model="form.delivery_id_header" class="input font-mono text-xs" placeholder="X-Request-Id" data-field="delivery_id_header" />
          <p v-if="errors['config.delivery_id_header']" class="text-[11px] text-red-400 mt-1">{{ errors['config.delivery_id_header'] }}</p>
          <p v-else class="text-[11px] text-gray-400 mt-1">{{ t(`triggers.webhook.deliveryIdHints.${form.verify}`) }}</p>
        </div>
        <div v-if="usesTimestamp">
          <label class="label">{{ t('triggers.webhook.tolerance') }}</label>
          <input v-model="form.tolerance_seconds" type="number" min="0" max="86400" class="input font-mono text-xs" placeholder="300" data-field="tolerance_seconds" />
          <p v-if="errors['config.tolerance_seconds']" class="text-[11px] text-red-400 mt-1">{{ errors['config.tolerance_seconds'] }}</p>
          <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.webhook.toleranceHint') }}</p>
        </div>
        <div>
          <label class="label"><TermText path="triggers.webhook.maxBody" /></label>
          <input v-model="form.max_body_kb" type="number" min="1" max="1024" class="input font-mono text-xs" placeholder="1024" data-field="max_body_kb" />
          <p v-if="errors['config.max_body_kb']" class="text-[11px] text-red-400 mt-1">{{ errors['config.max_body_kb'] }}</p>
          <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.webhook.maxBodyHint') }}</p>
        </div>
        <div>
          <label class="label">{{ t('triggers.fields.retention') }}</label>
          <input v-model="form.retention_days" type="number" min="1" max="30" class="input font-mono text-xs" placeholder="7" data-field="retention_days" />
          <p v-if="errors['config.retention_days']" class="text-[11px] text-red-400 mt-1">{{ errors['config.retention_days'] }}</p>
          <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.fields.retentionHint') }}</p>
        </div>
      </div>
    </section>
  </div>
</template>
