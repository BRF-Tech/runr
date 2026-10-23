<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { KeyRound, Trash2 } from 'lucide-vue-next'
import { environmentsApi } from '@/api/environments'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'

/**
 * Secret env vars of one environment (docs/environments.md#secrets): its own, over the project's
 * of the same name, and — unless it does not inherit them — the project's. Values are write-only:
 * the list shows names and where they come from. A change reaches the workload with the next
 * deploy.
 */
const props = defineProps({
  projectId:   { type: String, required: true },
  environment: { type: Object, required: true },
  canEdit:     { type: Boolean, default: true },
})

defineEmits(['close'])

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const secrets  = ref([])
const inherits = ref(true)
const loading  = ref(true)
const name     = ref('')
const value    = ref('')
const saving   = ref(false)
const error    = ref(null)

async function load() {
  loading.value = true
  try {
    const { data } = await environmentsApi.secrets(props.projectId, props.environment.name)
    secrets.value  = data.data ?? []
    inherits.value = data.inherit_secrets !== false
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function setSecret() {
  const key = name.value.trim()
  if (!key || !value.value || saving.value) return
  saving.value = true
  error.value  = null
  try {
    await environmentsApi.setSecret(props.projectId, props.environment.name, key, value.value)
    toast.success(t('environments.secrets.saved', { name: key }))
    name.value  = ''
    value.value = ''
    await load()
  } catch (e) {
    const body = e.response?.data ?? {}
    error.value = Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

async function remove(secret) {
  const ok = await confirm({
    title:        t('environments.secrets.removeTitle'),
    message:      t('environments.secrets.removeConfirm', { name: secret.name, environment: props.environment.name }),
    confirmLabel: t('common.delete'),
    destructive:  true,
  })
  if (!ok) return
  try {
    await environmentsApi.deleteSecret(props.projectId, props.environment.name, secret.name)
    toast.success(t('environments.secrets.deleted', { name: secret.name }))
    await load()
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="environment-secrets">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('close')" />
    <div class="relative card w-full max-w-lg max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-2">
        <h2 class="text-base font-semibold flex items-center gap-2">
          <KeyRound class="w-4 h-4 text-gray-400" />{{ t('environments.secrets.title', { name: environment.name }) }}
        </h2>
        <button class="text-gray-400 hover:text-gray-100" @click="$emit('close')">✕</button>
      </div>
      <p class="text-xs text-gray-400 mb-4">{{ t('environments.secrets.intro') }}</p>
      <p v-if="!inherits" class="text-xs text-amber-300 mb-3">{{ t('environments.secrets.notInherited') }}</p>

      <div v-if="loading" class="flex items-center justify-center py-8">
        <div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" />
      </div>
      <ul v-else class="space-y-1 mb-4">
        <li v-for="s in secrets" :key="s.name" class="flex items-center gap-3 px-3 py-2 rounded-md border border-gray-800">
          <span class="font-mono text-sm text-gray-100 flex-1 truncate">{{ s.name }}</span>
          <span
            class="text-[10px] px-1.5 py-0.5 rounded-sm border"
            :class="s.scope === 'environment' ? 'bg-sky-900/40 text-sky-300 border-sky-800/50' : 'bg-gray-800 text-gray-400 border-gray-700'"
          >{{ s.scope === 'environment' ? t('environments.secrets.scopeEnvironment') : t('environments.secrets.scopeProject') }}</span>
          <button
            v-if="canEdit && s.scope === 'environment'"
            class="text-gray-400 hover:text-red-400"
            :title="t('common.delete')"
            @click="remove(s)"
          ><Trash2 class="w-3.5 h-3.5" /></button>
        </li>
        <li v-if="!secrets.length" class="text-sm text-gray-400 text-center py-4">{{ t('environments.secrets.none') }}</li>
      </ul>

      <form v-if="canEdit" class="flex items-start gap-2" @submit.prevent="setSecret">
        <input v-model="name" class="input flex-1 font-mono text-xs" :placeholder="t('environments.secrets.name')" maxlength="255" data-testid="env-secret-name" />
        <input v-model="value" type="password" autocomplete="new-password" class="input flex-1 font-mono text-xs" :placeholder="t('environments.secrets.value')" data-testid="env-secret-value" />
        <button type="submit" class="btn-primary text-xs px-3 py-2" :disabled="saving || !name.trim() || !value">{{ t('environments.secrets.set') }}</button>
      </form>
      <p v-if="error" class="text-xs text-red-400 mt-2">{{ error }}</p>
    </div>
  </div>
</template>
