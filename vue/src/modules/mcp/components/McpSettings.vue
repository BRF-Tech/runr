<script setup>
// Settings → AI agents (MCP): how to connect an MCP client to RunR, scoped MCP tokens, the
// clients connected through OAuth and the tool catalog (docs/mcp.md).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePermissionsStore } from '@/stores/permissions'
import { mcpApi } from '../api/mcp'
import CopyBlock from './CopyBlock.vue'
import McpClientSnippets from './McpClientSnippets.vue'
import McpTokenForm from './McpTokenForm.vue'
import McpConnectedClients from './McpConnectedClients.vue'
import McpToolCatalog from './McpToolCatalog.vue'

const { t } = useI18n()
const perms = usePermissionsStore()

const info = ref(null)
const loading = ref(true)
const error = ref(null)
const auth = ref('oauth')        // snippets: one-click OAuth or an API token header
const token = ref(null)          // plain value of a token created on this page

const endpoint = computed(() => info.value?.endpoint || `${window.location.origin}/mcp`)
const oauthEnabled = computed(() => info.value?.oauth?.enabled !== false)
const canManageTokens = computed(() => perms.can('tokens.manage'))

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await mcpApi.info()
    info.value = data
    if (!oauthEnabled.value) auth.value = 'token'
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

function onTokenCreated(plain) {
  token.value = plain
  auth.value = 'token'
}

onMounted(load)
</script>

<template>
  <div class="space-y-6" data-testid="mcp-settings">
    <div class="flex items-center gap-3">
      <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('mcp.settings.title') }}</h3>
        <p class="text-xs text-gray-400">{{ t('mcp.settings.subtitle') }}</p>
      </div>
    </div>

    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="text-xs text-red-400" data-testid="mcp-settings-error">{{ error }}</p>

    <!-- Connect -->
    <section v-if="!loading" class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 space-y-4" data-testid="mcp-connect">
      <div>
        <p class="text-sm font-semibold text-gray-100">{{ t('mcp.settings.connectTitle') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.settings.connectHint') }}</p>
      </div>

      <CopyBlock :text="endpoint" :label="t('mcp.settings.endpoint')" inline />

      <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-400">
        <span>{{ t('mcp.settings.transport') }}</span>
        <span v-for="v in info?.protocol_versions || []" :key="v" class="font-mono px-1.5 py-0.5 rounded-sm border border-gray-700 bg-gray-800/40">{{ v }}</span>
      </div>

      <div class="flex gap-2">
        <button
          v-if="oauthEnabled" type="button" data-testid="mcp-auth-oauth"
          class="px-3 py-1.5 rounded-lg border text-xs font-medium"
          :class="auth === 'oauth' ? 'border-emerald-800/50 text-emerald-400 bg-emerald-900/20' : 'border-gray-700 text-gray-400'"
          @click="auth = 'oauth'"
        >{{ t('mcp.settings.authOauth') }}</button>
        <button
          type="button" data-testid="mcp-auth-token"
          class="px-3 py-1.5 rounded-lg border text-xs font-medium"
          :class="auth === 'token' ? 'border-brand-500/50 text-brand-500 bg-brand-500/10' : 'border-gray-700 text-gray-400'"
          @click="auth = 'token'"
        >{{ t('mcp.settings.authToken') }}</button>
      </div>
      <p class="text-[11px] text-gray-400">{{ auth === 'oauth' ? t('mcp.settings.authOauthHint') : t('mcp.settings.authTokenHint') }}</p>

      <McpClientSnippets :url="endpoint" name="runr" :auth="auth" :token="token" />
    </section>

    <!-- Scoped MCP token -->
    <McpTokenForm v-if="!loading && canManageTokens" @created="onTokenCreated" />

    <!-- OAuth clients -->
    <McpConnectedClients v-if="!loading && canManageTokens && oauthEnabled" />

    <!-- Tools -->
    <McpToolCatalog v-if="!loading" />
  </div>
</template>
