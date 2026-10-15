<script setup>
// OAuth consent (docs/mcp.md, "OAuth"): an MCP client asked to connect to RunR (or to a hosted
// MCP server). The signed-in user picks what the connection may do and for how long; approving
// issues a RunR API token with that scope, pinned to the current organization, revocable in
// Settings → API tokens / AI agents.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/AppHeader.vue'
import AbilityPicker from '@/components/settings/AbilityPicker.vue'
import { useRbacLabels } from '@/composables/useRbac'
import { mcpApi } from '../api/mcp'
import { navigateAway } from '../utils/navigate'
import { AlertTriangle, Bot, Building2, Clock, Loader2, ShieldAlert } from 'lucide-vue-next'

const route = useRoute()
const { t } = useI18n()
const { permLabel } = useRbacLabels()

const status = ref('loading')   // loading | ready | expired | error | redirecting
const request = ref(null)
const message = ref('')
const submitting = ref(false)
const fieldError = ref(null)

const fullAccess = ref(false)
const selected = ref([])
const lifetime = ref(30)

const errorCode = computed(() => route.query.error ? String(route.query.error) : null)
const errorDescription = computed(() => route.query.error_description ? String(route.query.error_description) : '')

const available = computed(() => request.value?.available_abilities ?? [])
const fixedScopes = computed(() => request.value?.fixed_scopes ?? null)
const lifetimes = computed(() => request.value?.token_lifetimes ?? [7, 30, 90, 180, 365, null])
const blockedByTenant = computed(() => !!request.value?.required_tenant)
const canApprove = computed(() => request.value?.can_approve !== false && !blockedByTenant.value
  && (fixedScopes.value || fullAccess.value || selected.value.length > 0))

function preselect(data) {
  const keys = new Set((data.available_abilities ?? []).map(a => a.key ?? a))
  const requested = data.requested_scopes ?? []
  if (requested.includes('*')) {
    fullAccess.value = true
    selected.value = []
  } else if (requested.length) {
    fullAccess.value = false
    selected.value = requested.filter(s => keys.has(s))
  } else {
    // Nothing asked for: start with read access, the user widens it
    fullAccess.value = false
    selected.value = [...keys].filter(k => k.endsWith('.view'))
  }
  lifetime.value = data.default_token_lifetime ?? 30
}

async function load() {
  if (errorCode.value) {
    status.value = 'error'
    return
  }
  const id = route.query.request
  if (!id) {
    status.value = 'expired'
    return
  }
  status.value = 'loading'
  try {
    const { data } = await mcpApi.consentRequest(String(id))
    request.value = data.data ?? data
    preselect(request.value)
    status.value = 'ready'
  } catch (e) {
    const code = e.response?.status
    message.value = e.response?.data?.message ?? ''
    status.value = code === 404 || code === 410 ? 'expired' : 'error'
  }
}

function lifetimeLabel(days) {
  return days === null ? t('mcp.consent.untilRevoked') : t('mcp.consent.days', { n: days })
}

async function approve() {
  if (!canApprove.value || submitting.value) return
  submitting.value = true
  fieldError.value = null
  try {
    const abilities = fixedScopes.value ? [...fixedScopes.value] : fullAccess.value ? ['*'] : [...selected.value]
    const { data } = await mcpApi.approve(request.value.id, { abilities, expires_in_days: lifetime.value })
    status.value = 'redirecting'
    navigateAway(data.redirect_url)
  } catch (e) {
    const res = e.response
    fieldError.value = res?.data?.errors?.abilities?.[0]
      ?? (res?.status === 409 ? res?.data?.message : null)
      ?? res?.data?.message
      ?? t('common.error')
    if (res?.status === 404 || res?.status === 410) status.value = 'expired'
  } finally {
    submitting.value = false
  }
}

async function deny() {
  if (submitting.value) return
  submitting.value = true
  try {
    const { data } = await mcpApi.deny(request.value.id)
    status.value = 'redirecting'
    navigateAway(data.redirect_url)
  } catch (e) {
    fieldError.value = e.response?.data?.message ?? t('common.error')
    if (e.response?.status === 404 || e.response?.status === 410) status.value = 'expired'
  } finally {
    submitting.value = false
  }
}

onMounted(load)
watch(() => route.query.request, load)
</script>

<template>
  <div class="min-h-screen bg-gray-950">
    <AppHeader />
    <main class="max-w-2xl mx-auto px-4 py-10">
      <div class="bg-gray-900 border border-gray-800 rounded-xl p-8" data-testid="oauth-consent">
        <!-- Loading -->
        <div v-if="status === 'loading' || status === 'redirecting'" class="text-center py-6">
          <Loader2 class="w-10 h-10 mx-auto mb-3 text-sky-400 animate-spin" />
          <p class="text-sm text-gray-400">{{ status === 'redirecting' ? t('mcp.consent.redirecting') : t('common.loading') }}</p>
        </div>

        <!-- Error from the authorization endpoint (?error=…) or unreadable request -->
        <div v-else-if="status === 'error'" class="text-center py-4" data-testid="oauth-consent-error">
          <ShieldAlert class="w-10 h-10 mx-auto mb-3 text-red-400" />
          <h2 class="text-lg font-semibold text-gray-100 mb-1">{{ t('mcp.consent.errorTitle') }}</h2>
          <p class="text-sm text-gray-400">{{ errorDescription || message || t('common.error') }}</p>
          <p v-if="errorCode" class="text-[11px] font-mono text-gray-500 mt-2">{{ errorCode }}</p>
        </div>

        <!-- Expired / unknown request -->
        <div v-else-if="status === 'expired'" class="text-center py-4" data-testid="oauth-consent-expired">
          <Clock class="w-10 h-10 mx-auto mb-3 text-amber-400" />
          <h2 class="text-lg font-semibold text-gray-100 mb-1">{{ t('mcp.consent.expiredTitle') }}</h2>
          <p class="text-sm text-gray-400">{{ t('mcp.consent.expiredText') }}</p>
        </div>

        <!-- The request -->
        <template v-else-if="status === 'ready' && request">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.12);border:1px solid rgb(var(--brand-500)/0.35)">
              <Bot class="w-6 h-6" style="color:rgb(var(--brand-500))" />
            </div>
            <div class="min-w-0">
              <h2 class="text-lg font-semibold text-gray-100" data-testid="oauth-consent-client">
                {{ t('mcp.consent.title', { client: request.client?.name || t('mcp.consent.unnamedClient') }) }}
              </h2>
              <p class="text-sm text-gray-400 mt-0.5">
                {{ request.resource_type === 'hosted' && request.hosted_server
                  ? t('mcp.consent.targetHosted', { project: request.hosted_server.project_name || request.hosted_server.handle })
                  : t('mcp.consent.targetRunr') }}
              </p>
              <p class="text-[11px] text-gray-500 mt-1 font-mono break-all">{{ t('mcp.consent.redirectHost', { host: request.client?.redirect_host || '—' }) }}</p>
            </div>
          </div>

          <div class="rounded-lg border border-amber-800/50 bg-amber-900/20 px-4 py-3 mb-5 flex gap-2">
            <AlertTriangle class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
            <p class="text-xs text-amber-200">{{ t('mcp.consent.warning') }}</p>
          </div>

          <!-- Organization the token works in -->
          <div v-if="request.tenant || request.required_tenant" class="rounded-lg border border-gray-800 bg-gray-950/40 px-4 py-3 mb-5" data-testid="oauth-consent-tenant">
            <div class="flex items-center gap-2 text-sm text-gray-100">
              <Building2 class="w-4 h-4 text-gray-400" />
              <span v-if="request.tenant">{{ t('mcp.consent.tenant', { tenant: request.tenant.name }) }}</span>
            </div>
            <p v-if="request.required_tenant" class="text-xs text-amber-300 mt-1.5" data-testid="oauth-consent-required-tenant">
              {{ t('mcp.consent.requiredTenant', { tenant: request.required_tenant.name }) }}
            </p>
            <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('mcp.consent.tenantHint') }}</p>
          </div>

          <!-- What the connection may do -->
          <div class="mb-5">
            <p class="label mb-2">{{ t('mcp.consent.scopes') }}</p>
            <div v-if="fixedScopes" class="flex flex-wrap gap-1.5" data-testid="oauth-consent-fixed">
              <span v-for="s in fixedScopes" :key="s" class="text-xs px-2 py-1 rounded border border-gray-700 bg-gray-800 text-gray-100">{{ permLabel(s) }}</span>
            </div>
            <AbilityPicker v-else v-model="selected" v-model:full-access="fullAccess" :abilities="available" />
          </div>

          <!-- Lifetime -->
          <div class="mb-6">
            <label class="label mb-2" for="oauth-lifetime">{{ t('mcp.consent.lifetime') }}</label>
            <select id="oauth-lifetime" v-model="lifetime" class="input w-56" data-testid="oauth-consent-lifetime">
              <option v-for="d in lifetimes" :key="String(d)" :value="d">{{ lifetimeLabel(d) }}</option>
            </select>
            <p class="text-[11px] text-gray-400 mt-1.5">{{ t('mcp.consent.lifetimeHint') }}</p>
          </div>

          <p v-if="request.can_approve === false" class="text-xs text-amber-300 mb-3">{{ t('mcp.consent.cannotApprove') }}</p>
          <p v-if="fieldError" class="text-xs text-red-400 mb-3" data-testid="oauth-consent-field-error">{{ fieldError }}</p>

          <div class="flex items-center justify-end gap-2">
            <button class="btn-secondary text-sm" :disabled="submitting" data-testid="oauth-consent-deny" @click="deny">{{ t('mcp.consent.deny') }}</button>
            <button class="btn-primary text-sm" :disabled="!canApprove || submitting" data-testid="oauth-consent-approve" @click="approve">{{ t('mcp.consent.approve') }}</button>
          </div>
        </template>
      </div>
    </main>
  </div>
</template>
