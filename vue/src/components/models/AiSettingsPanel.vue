<script setup>
// Models → Settings: the organization's Hugging Face token (gated / private models; stored
// encrypted, handed to the regions as a Kubernetes Secret) and the GPU quota per region.
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { KeyRound, Cpu } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useSettingsStore } from '@/stores/settings'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const ai = useAiStore()
const settingsStore = useSettingsStore()
const { confirm } = useConfirm()
const toast = useToast()

const emit = defineEmits(['token-changed'])

const token   = ref(null)        // { configured, hint, updated_at }
const input   = ref('')
const saving  = ref(false)
const quotas  = ref({})          // region id → { quota, used, remaining }

const regions = computed(() => settingsStore.data.regions ?? [])

async function loadToken() {
  try {
    const { data } = await aiApi.hubToken()
    token.value = data
  } catch { token.value = { configured: false } }
}

async function loadQuotas() {
  const out = {}
  await Promise.all(regions.value.map(async (r) => {
    try {
      const { data } = await aiApi.gpuQuota(r.id)
      out[r.id] = data
    } catch { /* region without access */ }
  }))
  quotas.value = out
}

async function save() {
  saving.value = true
  try {
    const { data } = await aiApi.setHubToken(input.value.trim())
    token.value = data
    input.value = ''
    toast.success(t('ai.settings.tokenSaved'))
    emit('token-changed', true)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error'))
  } finally { saving.value = false }
}

async function remove() {
  const ok = await confirm({ title: t('ai.settings.removeToken'), message: t('ai.settings.removeTokenConfirm'), destructive: true })
  if (!ok) return
  try {
    await aiApi.removeHubToken()
    token.value = { configured: false }
    toast.success(t('ai.settings.tokenRemoved'))
    emit('token-changed', false)
  } catch (e) { toast.error(e?.response?.data?.message ?? t('common.error')) }
}

onMounted(async () => {
  await loadToken()
  if (!settingsStore.loaded) await settingsStore.fetch()
  loadQuotas()
})
</script>

<template>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4" data-testid="ai-settings">
    <div class="card space-y-3">
      <div class="flex items-center gap-2 pb-3 border-b border-gray-800">
        <KeyRound class="w-4 h-4" style="color:rgb(var(--brand-500))" />
        <h3 class="text-sm font-semibold text-gray-100">{{ t('ai.settings.tokenTitle') }}</h3>
      </div>
      <p class="text-xs text-gray-400">{{ t('ai.settings.tokenDescription') }}</p>
      <p v-if="token?.configured" class="text-xs text-emerald-400" data-testid="token-configured">
        {{ t('ai.settings.tokenConfigured', { hint: token.hint ?? '••••' }) }}
        <span v-if="token.updated_at" class="text-gray-500">· {{ new Date(token.updated_at).toLocaleString() }}</span>
      </p>
      <p v-else class="text-xs text-gray-400" data-testid="token-none">{{ t('ai.settings.tokenNone') }}</p>

      <template v-if="ai.canManage">
        <input v-model="input" type="password" autocomplete="off" class="input font-mono" :placeholder="t('ai.settings.tokenPlaceholder')" data-testid="token-input" />
        <div class="flex gap-2">
          <button class="btn-primary text-xs" :disabled="saving || input.trim().length < 8" data-testid="token-save" @click="save">
            {{ token?.configured ? t('ai.settings.replaceToken') : t('ai.settings.setToken') }}
          </button>
          <button v-if="token?.configured" class="btn-secondary text-xs" data-testid="token-remove" @click="remove">{{ t('ai.settings.removeToken') }}</button>
        </div>
      </template>
      <p v-else class="text-[11px] text-gray-500">{{ t('ai.settings.tokenManageOnly') }}</p>
    </div>

    <div class="card space-y-3">
      <div class="flex items-center gap-2 pb-3 border-b border-gray-800">
        <Cpu class="w-4 h-4" style="color:#f59e0b" />
        <h3 class="text-sm font-semibold text-gray-100">{{ t('ai.settings.quotaTitle') }}</h3>
      </div>
      <p class="text-xs text-gray-400">{{ t('ai.settings.quotaDescription') }}</p>
      <div v-for="r in regions" :key="r.id" class="flex items-center justify-between text-xs" :data-quota-region="r.name">
        <span class="text-gray-200">{{ r.name }}</span>
        <span v-if="!quotas[r.id]" class="text-gray-500">—</span>
        <span v-else-if="quotas[r.id].quota === null || quotas[r.id].quota === undefined" class="font-mono text-gray-300">
          {{ t('ai.settings.quotaUnlimited', { used: quotas[r.id].used ?? 0 }) }}
        </span>
        <span v-else class="font-mono text-gray-300">{{ t('ai.settings.quotaUsage', { used: quotas[r.id].used ?? 0, quota: quotas[r.id].quota }) }}</span>
      </div>
    </div>
  </div>
</template>
