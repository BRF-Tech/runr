<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { splitList } from '../utils/format'

/**
 * Create / edit an alert channel. Secrets (webhook URLs, bot tokens, HMAC secrets) are
 * write-only: an edit shows what is set and keeps it unless a new value is entered.
 */
const props = defineProps({
  channel: { type: Object, default: null },
  saving:  { type: Boolean, default: false },
  errors:  { type: Object, default: () => ({}) },
})
const emit = defineEmits(['submit', 'cancel'])

const { t } = useI18n()
const TYPES = ['email', 'slack', 'teams', 'telegram', 'webhook']

const ch = props.channel
const form = ref({
  name:        ch?.name ?? '',
  type:        ch?.type ?? 'slack',
  emails:      (ch?.config?.emails ?? []).join(', '),
  webhook_url: '',
  bot_token:   '',
  chat_id:     ch?.config?.chat_id ?? '',
  url:         '',
  secret:      '',
  is_enabled:  ch?.is_enabled ?? true,
})
const isEdit = computed(() => !!props.channel)
const err = (k) => (props.errors?.[k] || [])[0]

function config() {
  const f = form.value
  const keep = (v) => (v === '' ? undefined : v)
  switch (f.type) {
    case 'email':    return { emails: splitList(f.emails) }
    case 'slack':
    case 'teams':    return { webhook_url: keep(f.webhook_url) }
    case 'telegram': return { bot_token: keep(f.bot_token), chat_id: f.chat_id }
    default:         return { url: keep(f.url), secret: keep(f.secret) }
  }
}

function submit() {
  const cfg = Object.fromEntries(Object.entries(config()).filter(([, v]) => v !== undefined))
  const payload = { name: form.value.name, is_enabled: form.value.is_enabled }
  if (!isEdit.value) payload.type = form.value.type
  if (!isEdit.value || Object.keys(cfg).length) payload.config = cfg
  emit('submit', payload)
}
</script>

<template>
  <form class="bg-gray-900 border border-gray-800 rounded-md p-4 grid gap-3 md:grid-cols-2" data-test="channel-form" @submit.prevent="submit">
    <div>
      <label class="label">{{ t('observability.alerts.channels.name') }}</label>
      <input v-model="form.name" class="input" required data-test="channel-name">
    </div>
    <div>
      <label class="label">{{ t('observability.alerts.channels.type') }}</label>
      <select v-model="form.type" class="input" :disabled="isEdit" data-test="channel-type">
        <option v-for="type in TYPES" :key="type" :value="type">{{ t('observability.alerts.channels.types.' + type) }}</option>
      </select>
    </div>

    <div v-if="form.type === 'email'" class="md:col-span-2">
      <label class="label">{{ t('observability.alerts.channels.emails') }}</label>
      <input v-model="form.emails" class="input" placeholder="ops@example.com, oncall@example.com" data-test="channel-emails">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.alerts.channels.emailsHint') }}</p>
      <p v-if="err('config.emails') || err('config.emails.0')" class="text-[11px] text-red-400 mt-1">{{ err('config.emails') || err('config.emails.0') }}</p>
    </div>

    <div v-else-if="form.type === 'slack' || form.type === 'teams'" class="md:col-span-2">
      <label class="label">{{ t('observability.alerts.channels.webhookUrl') }}</label>
      <input v-model="form.webhook_url" class="input font-mono" type="password" autocomplete="off" :placeholder="channel?.config?.webhook_set ? t('observability.alerts.channels.keepSecret', { host: channel.config.webhook_host }) : 'https://…'" data-test="channel-webhook">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.alerts.channels.webhookUrlHint.' + form.type) }}</p>
      <p v-if="err('config.webhook_url')" class="text-[11px] text-red-400 mt-1" data-test="channel-error">{{ err('config.webhook_url') }}</p>
    </div>

    <template v-else-if="form.type === 'telegram'">
      <div>
        <label class="label">{{ t('observability.alerts.channels.botToken') }}</label>
        <input v-model="form.bot_token" class="input font-mono" type="password" autocomplete="off" :placeholder="channel?.config?.bot_token_set ? t('observability.alerts.channels.keepSecretShort') : '123456:ABC…'" data-test="channel-bot-token">
        <p v-if="err('config.bot_token')" class="text-[11px] text-red-400 mt-1">{{ err('config.bot_token') }}</p>
      </div>
      <div>
        <label class="label">{{ t('observability.alerts.channels.chatId') }}</label>
        <input v-model="form.chat_id" class="input font-mono" placeholder="-1001234567890" data-test="channel-chat-id">
        <p v-if="err('config.chat_id')" class="text-[11px] text-red-400 mt-1">{{ err('config.chat_id') }}</p>
      </div>
    </template>

    <template v-else>
      <div>
        <label class="label">{{ t('observability.alerts.channels.url') }}</label>
        <input v-model="form.url" class="input font-mono" type="password" autocomplete="off" :placeholder="channel?.config?.url_set ? t('observability.alerts.channels.keepSecret', { host: channel.config.url_host }) : 'https://…'" data-test="channel-url">
        <p v-if="err('config.url')" class="text-[11px] text-red-400 mt-1" data-test="channel-error">{{ err('config.url') }}</p>
      </div>
      <div>
        <label class="label">{{ t('observability.alerts.channels.secret') }}</label>
        <input v-model="form.secret" class="input font-mono" type="password" autocomplete="off" :placeholder="channel?.config?.secret_set ? t('observability.alerts.channels.keepSecretShort') : ''" data-test="channel-secret">
        <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.alerts.channels.secretHint') }}</p>
      </div>
    </template>

    <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.is_enabled" type="checkbox"> {{ t('observability.alerts.channels.enabled') }}</label>

    <div class="md:col-span-2 flex gap-2">
      <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="channel-submit">{{ t('observability.common.save') }}</button>
      <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="emit('cancel')">{{ t('observability.common.cancel') }}</button>
    </div>
  </form>
</template>
