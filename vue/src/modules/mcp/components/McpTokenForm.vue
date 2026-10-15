<script setup>
// A scoped API token for an MCP client (POST /api/tokens — the same tokens as Settings → API
// tokens; the MCP server shows a client only the tools the token's permissions allow).
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useApiTokens } from '@/composables/useApiTokens'
import AbilityPicker from '@/components/settings/AbilityPicker.vue'
import TokenExpiryPicker from '@/components/settings/TokenExpiryPicker.vue'
import { Key, Plus, Copy, Check } from 'lucide-vue-next'

const emit = defineEmits(['created'])
const { t } = useI18n()

const {
  availableAbilities, creating, error,
  newName, newAbilities, newExpiresAt, noExpiry, fullAccess, createdToken, copied,
  canCreate, fetchTokens, createToken, copyToken, dismissCreatedToken,
} = useApiTokens()

onMounted(fetchTokens)

watch(createdToken, (plain) => { if (plain) emit('created', plain) })
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 space-y-4" data-testid="mcp-token-form">
    <div>
      <p class="text-sm font-semibold text-gray-100">{{ t('mcp.token.title') }}</p>
      <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.token.hint') }}</p>
    </div>

    <div v-if="createdToken" class="rounded-lg border px-4 py-3" style="border-color:rgb(var(--brand-500)/0.4);background:rgb(var(--brand-500)/0.06)" data-testid="mcp-token-created">
      <div class="flex items-center gap-2 mb-2">
        <Key class="w-4 h-4" style="color:rgb(var(--brand-500))" />
        <p class="text-sm font-semibold text-gray-100">{{ t('tokens.createdBanner') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <code class="flex-1 text-xs font-mono px-3 py-2.5 rounded-lg bg-gray-950 text-gray-100 break-all select-all border border-gray-800" data-testid="mcp-token-plain">{{ createdToken }}</code>
        <button class="btn-secondary text-xs shrink-0 flex items-center gap-1.5" @click="copyToken">
          <Check v-if="copied" class="w-3.5 h-3.5 text-emerald-400" />
          <Copy v-else class="w-3.5 h-3.5" />
          {{ copied ? t('common.copied') : t('common.copy') }}
        </button>
      </div>
      <p class="text-[11px] text-gray-400 mt-2">{{ t('mcp.token.createdHint') }}</p>
      <button class="text-xs text-gray-400 hover:text-gray-100 mt-2" @click="dismissCreatedToken">{{ t('common.dismiss') }}</button>
    </div>

    <div>
      <label class="label">{{ t('tokens.name') }}</label>
      <input v-model="newName" class="input" :placeholder="t('mcp.token.namePlaceholder')" data-testid="mcp-token-name" />
    </div>

    <div>
      <label class="label mb-2">{{ t('tokens.expiresAt') }}</label>
      <TokenExpiryPicker v-model:no-expiry="noExpiry" v-model:expires-at="newExpiresAt" />
    </div>

    <div>
      <label class="label mb-2">{{ t('tokens.abilities') }}</label>
      <AbilityPicker v-model="newAbilities" v-model:full-access="fullAccess" :abilities="availableAbilities" />
    </div>

    <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

    <button class="btn-primary text-xs flex items-center gap-1.5" :disabled="!canCreate || creating" data-testid="mcp-token-create" @click="createToken">
      <Plus class="w-3.5 h-3.5" />
      {{ creating ? t('common.saving') : t('mcp.token.create') }}
    </button>
  </section>
</template>
