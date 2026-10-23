<script setup>
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { gitApi } from '@/api/git'
import TermText from '@/components/TermText.vue'

// Add or edit a git provider (Data & Integrations → Git providers). Secrets are write-only: an
// empty field keeps the stored value.
const props = defineProps({
  provider: { type: Object, default: null },
})
const emit = defineEmits(['saved', 'close'])

const { t } = useI18n()
const editing = computed(() => !!props.provider)

const form = reactive({
  type:           props.provider?.type ?? 'github',
  name:           props.provider?.name ?? '',
  base_url:       props.provider?.base_url ?? '',
  api_url:        props.provider?.api_url ?? '',
  app_id:         props.provider?.app_id ?? '',
  app_slug:       props.provider?.app_slug ?? '',
  client_id:      props.provider?.client_id ?? '',
  client_secret:  '',
  private_key:    '',
  webhook_secret: '',
  is_enabled:     props.provider?.is_enabled ?? true,
})

const saving = ref(false)
const errors = ref({})
const message = ref(null)

const basePlaceholder = computed(() => ({
  github:  'https://github.com',
  gitlab:  'https://gitlab.com',
  forgejo: 'https://code.example.com',
}[form.type]))

function generateSecret() {
  const bytes = new Uint8Array(24)
  crypto.getRandomValues(bytes)
  form.webhook_secret = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('')
}

function payload() {
  const data = {
    name:       form.name.trim(),
    base_url:   form.base_url.trim() || null,
    api_url:    form.api_url.trim() || null,
    is_enabled: form.is_enabled,
  }
  if (!editing.value) data.type = form.type
  if (form.type === 'github') {
    Object.assign(data, { app_id: form.app_id.trim(), app_slug: form.app_slug.trim(), client_id: form.client_id.trim() })
  } else if (form.type === 'gitlab') {
    data.client_id = form.client_id.trim() || null
  }
  for (const secret of ['client_secret', 'private_key', 'webhook_secret']) {
    if (form[secret].trim() !== '') data[secret] = form[secret]
  }
  return data
}

async function submit() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    const { data } = editing.value
      ? await gitApi.updateProvider(props.provider.id, payload())
      : await gitApi.createProvider(payload())
    emit('saved', data.data ?? data)
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    message.value = Object.keys(errors.value).length ? null : (e.response?.data?.message ?? t('gitDeploy.providers.saveFailed'))
  } finally {
    saving.value = false
  }
}

const err = (field) => errors.value[field]?.[0] ?? null
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" data-testid="git-provider-modal" @click.self="emit('close')">
    <form class="card w-full max-w-xl max-h-[90vh] overflow-y-auto space-y-4" @submit.prevent="submit">
      <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold text-gray-100">{{ editing ? t('gitDeploy.providers.editTitle') : t('gitDeploy.providers.addTitle') }}</h3>
        <button type="button" class="text-gray-500 hover:text-gray-200" :aria-label="t('common.cancel')" @click="emit('close')">✕</button>
      </div>

      <div v-if="!editing">
        <label class="label">{{ t('gitDeploy.providers.type') }}</label>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="type in ['github', 'gitlab', 'forgejo']" :key="type" type="button"
            class="px-3 py-2 rounded-lg border text-sm" :data-type="type"
            :class="form.type === type ? 'border-brand-500 text-brand-500 bg-brand-500/10' : 'border-gray-700 text-gray-400 hover:border-gray-600'"
            @click="form.type = type">
            {{ t(`gitDeploy.providerTypes.${type}`) }}
          </button>
        </div>
      </div>

      <p class="text-xs text-gray-400">{{ t(`gitDeploy.providers.intro.${form.type}`) }}</p>

      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2">
          <label class="label" for="gp-name">{{ t('gitDeploy.providers.name') }}</label>
          <input id="gp-name" v-model="form.name" class="input" required data-testid="gp-name" />
          <p v-if="err('name')" class="text-xs text-red-400 mt-1">{{ err('name') }}</p>
        </div>
        <div>
          <label class="label" for="gp-base">{{ t('gitDeploy.providers.baseUrl') }}</label>
          <input id="gp-base" v-model="form.base_url" class="input font-mono text-xs" :placeholder="basePlaceholder" data-testid="gp-base-url" />
          <p v-if="err('base_url')" class="text-xs text-red-400 mt-1">{{ err('base_url') }}</p>
        </div>
        <div>
          <label class="label" for="gp-api">{{ t('gitDeploy.providers.apiUrl') }}</label>
          <input id="gp-api" v-model="form.api_url" class="input font-mono text-xs" :placeholder="t('gitDeploy.providers.apiUrlPlaceholder')" />
          <p v-if="err('api_url')" class="text-xs text-red-400 mt-1">{{ err('api_url') }}</p>
        </div>
      </div>

      <!-- GitHub App -->
      <div v-if="form.type === 'github'" class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="gp-app-id">{{ t('gitDeploy.providers.appId') }}</label>
          <input id="gp-app-id" v-model="form.app_id" class="input font-mono" data-testid="gp-app-id" />
          <p v-if="err('app_id')" class="text-xs text-red-400 mt-1">{{ err('app_id') }}</p>
        </div>
        <div>
          <label class="label" for="gp-slug">{{ t('gitDeploy.providers.appSlug') }}</label>
          <input id="gp-slug" v-model="form.app_slug" class="input font-mono" data-testid="gp-app-slug" />
          <p v-if="err('app_slug')" class="text-xs text-red-400 mt-1">{{ err('app_slug') }}</p>
        </div>
        <div class="col-span-2">
          <label class="label" for="gp-key">{{ t('gitDeploy.providers.privateKey') }}</label>
          <textarea id="gp-key" v-model="form.private_key" class="input h-24 font-mono text-[11px] resize-none" data-testid="gp-private-key"
            :placeholder="provider?.has_private_key ? t('gitDeploy.providers.secretStored') : '-----BEGIN RSA PRIVATE KEY-----'" />
          <p v-if="err('private_key')" class="text-xs text-red-400 mt-1">{{ err('private_key') }}</p>
        </div>
        <div class="col-span-2">
          <label class="label" for="gp-whsecret">{{ t('gitDeploy.providers.webhookSecret') }}</label>
          <div class="flex gap-2">
            <input id="gp-whsecret" v-model="form.webhook_secret" type="password" autocomplete="off" class="input font-mono" data-testid="gp-webhook-secret"
              :placeholder="provider?.has_webhook_secret ? t('gitDeploy.providers.secretStored') : ''" />
            <button type="button" class="btn-secondary shrink-0" @click="generateSecret">{{ t('gitDeploy.providers.generate') }}</button>
          </div>
          <p v-if="err('webhook_secret')" class="text-xs text-red-400 mt-1">{{ err('webhook_secret') }}</p>
        </div>
      </div>

      <!-- OAuth client (GitHub App user authorization / GitLab application) -->
      <div v-if="form.type !== 'forgejo'" class="grid grid-cols-2 gap-3">
        <div>
          <label class="label" for="gp-client"><TermText path="gitDeploy.providers.clientId" /></label>
          <input id="gp-client" v-model="form.client_id" class="input font-mono text-xs" data-testid="gp-client-id" />
          <p v-if="err('client_id')" class="text-xs text-red-400 mt-1">{{ err('client_id') }}</p>
        </div>
        <div>
          <label class="label" for="gp-client-secret"><TermText path="gitDeploy.providers.clientSecret" /></label>
          <input id="gp-client-secret" v-model="form.client_secret" type="password" autocomplete="off" class="input font-mono text-xs" data-testid="gp-client-secret"
            :placeholder="provider?.has_client_secret ? t('gitDeploy.providers.secretStored') : ''" />
          <p v-if="err('client_secret')" class="text-xs text-red-400 mt-1">{{ err('client_secret') }}</p>
        </div>
        <p class="col-span-2 text-xs text-gray-500 -mt-1">{{ t(`gitDeploy.providers.clientHint.${form.type}`) }}</p>
      </div>

      <label class="flex items-center gap-2 text-sm text-gray-300">
        <input v-model="form.is_enabled" type="checkbox" class="accent-brand-500" />{{ t('gitDeploy.providers.enabled') }}
      </label>

      <p v-if="message" class="text-xs text-red-400">{{ message }}</p>

      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving" data-testid="gp-submit">
          {{ saving ? t('common.saving') : (editing ? t('common.save') : t('gitDeploy.providers.add')) }}
        </button>
      </div>
    </form>
  </div>
</template>
