<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useApiTokens } from '@/composables/useApiTokens'
import { useConfirm } from '@/composables/useConfirm'
import AbilityPicker from '@/components/settings/AbilityPicker.vue'
import TokenExpiryPicker from '@/components/settings/TokenExpiryPicker.vue'
import { Plus, Key, Trash2, Copy, Check } from 'lucide-vue-next'

const { t, locale } = useI18n()
const { confirm } = useConfirm()

const {
  tokens, availableAbilities, loading, creating, error,
  newName, newAbilities, newExpiresAt, noExpiry, fullAccess, createdToken, copied,
  canCreate,
  fetchTokens, createToken, deleteToken, copyToken,
  dismissCreatedToken, abilityLabel,
} = useApiTokens()

onMounted(fetchTokens)

function fmtDate(iso) {
  if (!iso) return '-'
  const d = new Date(iso)
  return d.toLocaleDateString(locale.value === 'tr' ? 'tr-TR' : 'en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

function timeAgo(iso) {
  if (!iso) return null
  const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
  if (diff < 60) return t('tokens.justNow')
  if (diff < 3600) return t('tokens.minutesAgo', { n: Math.floor(diff / 60) })
  if (diff < 86400) return t('tokens.hoursAgo', { n: Math.floor(diff / 3600) })
  return t('tokens.daysAgo', { n: Math.floor(diff / 86400) })
}

function isExpired(token) {
  return token.expires_at && new Date(token.expires_at) < new Date()
}

// Colour of an ability chip by its catalog group
const abilityGroupOf = computed(() =>
  Object.fromEntries(availableAbilities.value.map(ab => [ab.key ?? ab, ab.group])))

const abilityCategoryStyle = {
  projects:       { bg: 'bg-sky-900/30', border: 'border-sky-800/40', text: 'text-sky-400' },
  configuration:  { bg: 'bg-violet-900/30', border: 'border-violet-800/40', text: 'text-violet-400' },
  git:            { bg: 'bg-rose-900/30', border: 'border-rose-800/40', text: 'text-rose-400' },
  operations:     { bg: 'bg-emerald-900/30', border: 'border-emerald-800/40', text: 'text-emerald-400' },
  infrastructure: { bg: 'bg-amber-900/30', border: 'border-amber-800/40', text: 'text-amber-400' },
}

function abilityStyle(ab) {
  return abilityCategoryStyle[abilityGroupOf.value[ab]] || { bg: 'bg-gray-800', border: 'border-gray-700/60', text: 'text-gray-400' }
}

async function confirmDelete(token) {
  const ok = await confirm({
    title: t('tokens.deleteTitle'),
    message: t('tokens.deleteConfirm', { name: token.name }),
    destructive: true,
  })
  if (ok) deleteToken(token.id)
}

</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-5">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('tokens.title') }}</h3>
      </div>
    </div>

    <!-- Created token banner -->
    <div v-if="createdToken" class="rounded-xl border px-5 py-4 mb-5" style="border-color:rgb(var(--brand-500)/0.4);background:rgb(var(--brand-500)/0.06)">
      <div class="flex items-center gap-2 mb-2">
        <Key class="w-4 h-4" style="color:rgb(var(--brand-500))" />
        <p class="text-sm font-semibold text-gray-100">{{ t('tokens.createdBanner') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <code class="flex-1 text-xs font-mono px-3 py-2.5 rounded-lg bg-gray-950 text-gray-100 break-all select-all border border-gray-800">{{ createdToken }}</code>
        <button class="btn-secondary text-xs shrink-0 flex items-center gap-1.5" @click="copyToken">
          <Check v-if="copied" class="w-3.5 h-3.5 text-emerald-400" />
          <Copy v-else class="w-3.5 h-3.5" />
          {{ copied ? t('common.copied') : t('common.copy') }}
        </button>
      </div>
      <p class="text-[11px] text-gray-400 mt-2">{{ t('tokens.createdWarning') }}</p>
      <button class="text-xs text-gray-400 hover:text-gray-100 mt-2" @click="dismissCreatedToken">{{ t('common.dismiss') }}</button>
    </div>

    <!-- Create form -->
    <div data-tour="tokens-create-form" class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 mb-5 space-y-4">
      <p class="text-sm font-semibold text-gray-100">{{ t('tokens.createTitle') }}</p>

      <!-- Name -->
      <div>
        <label class="label">{{ t('tokens.name') }}</label>
        <input v-model="newName" class="input" :placeholder="t('tokens.namePlaceholder')" />
      </div>

      <!-- Expiration — duration buttons -->
      <div data-tour="tokens-expiry">
        <label class="label mb-2">{{ t('tokens.expiresAt') }}</label>
        <TokenExpiryPicker v-model:no-expiry="noExpiry" v-model:expires-at="newExpiresAt" />
      </div>

      <!-- Permissions -->
      <div data-tour="tokens-permissions">
        <label class="label mb-2">{{ t('tokens.abilities') }}</label>
        <AbilityPicker v-model="newAbilities" v-model:full-access="fullAccess" :abilities="availableAbilities" />
      </div>

      <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

      <button class="btn-primary text-xs flex items-center gap-1.5" :disabled="!canCreate || creating" @click="createToken">
        <Plus class="w-3.5 h-3.5" />
        {{ creating ? t('common.saving') : t('tokens.createBtn') }}
      </button>
    </div>

    <!-- Loading -->
    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>

    <!-- Token list -->
    <div v-else data-tour="tokens-list" class="space-y-2">
      <div v-for="token in tokens" :key="token.id"
        class="rounded-xl border bg-gray-900/60 px-4 py-3 transition-all"
        :class="isExpired(token) ? 'border-gray-800/50 opacity-50' : 'border-gray-800 hover:border-gray-700'"
      >
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
            :class="isExpired(token) ? 'bg-red-900/20 border border-red-800/30' : 'bg-gray-800/60 border border-gray-700/60'">
            <Key class="w-4 h-4" :class="isExpired(token) ? 'text-red-400' : 'text-gray-400'" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-semibold text-gray-100">{{ token.name }}</span>
              <span v-if="isExpired(token)" class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-red-900/30 border border-red-800/50 text-red-400">
                {{ t('tokens.expired') }}
              </span>
              <span v-if="token.abilities?.includes('*')" class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-emerald-900/30 border border-emerald-800/40 text-emerald-400">
                {{ t('tokens.fullAccess') }}
              </span>
            </div>
            <div class="flex items-center gap-3 mt-0.5 text-[11px] text-gray-400">
              <span>{{ t('tokens.created') }}: {{ fmtDate(token.created_at) }}</span>
              <span v-if="token.created_by" class="text-gray-500">{{ t('tokens.createdBy', { name: token.created_by.name }) }}</span>
              <span>{{ t('tokens.expires') }}: {{ token.expires_at ? fmtDate(token.expires_at) : t('tokens.noExpiry') }}</span>
              <span v-if="token.last_used_at">{{ t('tokens.lastUsed') }}: {{ timeAgo(token.last_used_at) }}</span>
            </div>
            <div v-if="token.abilities && !token.abilities.includes('*')" class="flex flex-wrap gap-1 mt-1.5">
              <span v-for="ab in token.abilities" :key="ab"
                class="text-[10px] font-medium px-1.5 py-0.5 rounded border"
                :class="[abilityStyle(ab).bg, abilityStyle(ab).border, abilityStyle(ab).text]">
                {{ abilityLabel(ab) }}
              </span>
            </div>
          </div>
          <button class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors shrink-0" @click="confirmDelete(token)">
            <Trash2 class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div v-if="tokens.length === 0" class="py-10 text-center">
        <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
          <Key class="w-6 h-6 text-gray-400" />
        </div>
        <p class="text-sm text-gray-400">{{ t('tokens.noTokens') }}</p>
      </div>
    </div>
  </div>
</template>
