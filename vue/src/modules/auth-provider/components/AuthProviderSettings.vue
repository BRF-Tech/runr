<script setup>
import { ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useTutorialStore } from '@/stores/tutorial'
import { Plus, Pencil, Trash2, ShieldCheck } from 'lucide-vue-next'
import { createTutorial } from '@/composables/useTutorial'
import api from '../api/authProviders'
import AuthProviderForm from './AuthProviderForm.vue'
import SsoGroupMappings from './SsoGroupMappings.vue'

const { t } = useI18n()
const { confirm } = useConfirm()
const tutorialStore = useTutorialStore()

const providers = ref([])
const loading = ref(true)
const showForm = ref(false)
const editing = ref(null)
const saving = ref(false)
const formError = ref(null)
const formInitial = ref(null)
const formSamlSp = ref(null)
const formHasSpKey = ref(false)
const formKey = ref(0)

function authProviderSteps(t) {
  return [
    { element: '[data-tour="auth-provider-driver"]',      popover: { title: t('authProvider.tutorialDriver'),      description: t('authProvider.tutorialDriverDesc') } },
    { element: '[data-tour="auth-provider-credentials"]', popover: { title: t('authProvider.tutorialCredentials'), description: t('authProvider.tutorialCredentialsDesc') } },
    { element: '[data-tour="auth-provider-config"]',      popover: { title: t('authProvider.tutorialConfig'),      description: t('authProvider.tutorialConfigDesc') } },
    { element: '[data-tour="auth-provider-scopes"]',      popover: { title: t('authProvider.tutorialScopes'),      description: t('authProvider.tutorialScopesDesc') } },
  ]
}
const { start: startTutorial } = createTutorial(authProviderSteps)

watch(() => tutorialStore.restartCounter, () => {
  editing.value = null
  formInitial.value = null
  formSamlSp.value = null
  formHasSpKey.value = false
  formError.value = null
  formKey.value++
  showForm.value = true
  nextTick(() => startTutorial())
})

const driverMeta = {
  google:    { bg: 'bg-blue-900/30', border: 'border-blue-800/40', text: 'text-blue-400' },
  github:    { bg: 'bg-gray-800/60', border: 'border-gray-700/60', text: 'text-gray-100' },
  microsoft: { bg: 'bg-sky-900/30', border: 'border-sky-800/40', text: 'text-sky-400' },
  gitlab:    { bg: 'bg-orange-900/30', border: 'border-orange-800/40', text: 'text-orange-400' },
  oidc:      { bg: 'bg-indigo-900/30', border: 'border-indigo-800/40', text: 'text-indigo-400' },
  saml:      { bg: 'bg-emerald-900/30', border: 'border-emerald-800/40', text: 'text-emerald-400' },
}

const driverLabels = {
  google: 'authProvider.driverGoogle', github: 'authProvider.driverGithub',
  microsoft: 'authProvider.driverMicrosoft', gitlab: 'authProvider.driverGitlab',
  oidc: 'authProvider.driverOidc', saml: 'authProvider.driverSaml',
}

const SAML_FIELDS = [
  'saml_idp_entity_id', 'saml_idp_sso_url', 'saml_idp_x509_cert', 'saml_email_attribute',
  'saml_name_attribute', 'saml_sign_requests', 'saml_sp_x509_cert', 'saml_sp_private_key',
]

async function fetchProviders() {
  try { providers.value = (await api.list()).data }
  catch { /* silent */ }
  finally { loading.value = false }
}

function openAdd() {
  editing.value = null
  formInitial.value = null
  formSamlSp.value = null
  formHasSpKey.value = false
  formError.value = null
  formKey.value++
  showForm.value = true
}

function openEdit(p) {
  editing.value = p.id
  formInitial.value = {
    name: p.name, driver: p.driver,
    client_id: p.client_id ? '••••••••' : '',
    client_secret: '',
    scopes: (p.scopes || []).join(', '),
    oidc_discovery_url: p.oidc_discovery_url || '',
    oidc_issuer: p.oidc_issuer || '',
    google_hosted_domain: p.google_hosted_domain || '',
    microsoft_tenant: p.microsoft_tenant || '',
    gitlab_instance_uri: p.gitlab_instance_uri || '',
    saml_idp_entity_id: p.saml_idp_entity_id || '',
    saml_idp_sso_url: p.saml_idp_sso_url || '',
    saml_idp_x509_cert: p.saml_idp_x509_cert || '',
    saml_email_attribute: p.saml_email_attribute || '',
    saml_name_attribute: p.saml_name_attribute || '',
    saml_sign_requests: !!p.saml_sign_requests,
    saml_sp_x509_cert: p.saml_sp_x509_cert || '',
    saml_sp_private_key: '',
    is_enabled: p.is_enabled,
    auto_register: p.auto_register,
    display_order: p.display_order,
    groups_attribute: p.groups_attribute || '',
    default_role: p.default_role || null,
  }
  formSamlSp.value = p.saml_sp || null
  formHasSpKey.value = !!p.has_saml_sp_private_key
  formError.value = null
  formKey.value++
  showForm.value = true
}

function buildPayload(form) {
  const payload = { ...form, scopes: form.scopes ? form.scopes.split(',').map(s => s.trim()).filter(Boolean) : null }
  if (form.driver === 'saml') {
    // SAML has no OAuth client or scopes; an empty key field keeps the stored key
    delete payload.client_id; delete payload.client_secret; delete payload.scopes
    if (!payload.saml_sp_private_key) delete payload.saml_sp_private_key
    if (!payload.saml_sign_requests) { delete payload.saml_sp_x509_cert; delete payload.saml_sp_private_key }
    return payload
  }
  SAML_FIELDS.forEach(f => delete payload[f])
  if (editing.value && payload.client_id === '••••••••') delete payload.client_id
  if (editing.value && !payload.client_secret) delete payload.client_secret
  return payload
}

async function handleSave(form) {
  saving.value = true; formError.value = null
  try {
    const payload = buildPayload(form)
    const { data } = editing.value ? await api.update(editing.value, payload) : await api.create(payload)
    await fetchProviders()
    // SAML: stay on the saved provider so its SP endpoints can be copied to the IdP
    if (data?.driver === 'saml') { openEdit(data); return }
    showForm.value = false
  } catch (e) {
    const errors = e.response?.data?.errors
    formError.value = errors ? Object.values(errors).flat()[0] : e.response?.data?.message ?? t('common.error')
  } finally { saving.value = false }
}

function handleCancel() { showForm.value = false }

async function toggleProvider(provider) {
  await api.toggle(provider.id); await fetchProviders()
}

async function confirmDelete(p) {
  const ok = await confirm({ title: t('authProvider.deleteTitle'), message: t('authProvider.deleteConfirm'), destructive: true })
  if (!ok) return
  await api.destroy(p.id); await fetchProviders()
}

function meta(driver) { return driverMeta[driver] || driverMeta.oidc }

fetchProviders()
</script>

<template>
  <div class="space-y-5">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <div>
          <h2 class="text-base font-semibold text-gray-100">{{ t('authProvider.title') }}</h2>
          <p class="text-xs text-gray-400 mt-0.5">{{ t('authProvider.description') }}</p>
        </div>
      </div>
      <button class="btn-primary text-xs flex items-center gap-1.5" data-tour="auth-provider-add" @click="openAdd">
        <Plus class="w-3.5 h-3.5" />{{ t('authProvider.addBtn') }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="py-12 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <!-- Empty state -->
    <div v-else-if="!providers.length && !showForm" class="py-12 text-center">
      <div class="w-14 h-14 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <svg class="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
        </svg>
      </div>
      <p class="text-sm text-gray-400">{{ t('authProvider.noProviders') }}</p>
    </div>

    <!-- Provider cards -->
    <div v-else class="space-y-3">
      <div v-for="(p, idx) in providers" :key="p.id"
        class="rounded-xl border bg-gray-900/80 px-4 py-3.5 transition-all hover:border-gray-700"
        :class="p.is_enabled ? 'border-gray-800' : 'border-gray-800/50 opacity-60'"
        :data-tour="idx === 0 ? 'auth-provider-card' : undefined">
        <div class="flex items-center gap-3.5">
          <div class="w-10 h-10 rounded-lg border flex items-center justify-center shrink-0" :class="[meta(p.driver).bg, meta(p.driver).border]">
            <!-- Google -->
            <svg v-if="p.driver === 'google'" class="w-5 h-5" viewBox="0 0 24 24">
              <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
              <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
              <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
              <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            <!-- GitHub -->
            <svg v-else-if="p.driver === 'github'" class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
            </svg>
            <!-- Microsoft -->
            <svg v-else-if="p.driver === 'microsoft'" class="w-5 h-5" viewBox="0 0 24 24">
              <path d="M1 1h10.5v10.5H1z" fill="#F25022"/><path d="M12.5 1H23v10.5H12.5z" fill="#7FBA00"/>
              <path d="M1 12.5h10.5V23H1z" fill="#00A4EF"/><path d="M12.5 12.5H23V23H12.5z" fill="#FFB900"/>
            </svg>
            <!-- GitLab -->
            <svg v-else-if="p.driver === 'gitlab'" class="w-5 h-5" fill="#FC6D26" viewBox="0 0 24 24">
              <path d="m23.6004 9.5927-.0337-.0862L20.3.9814a.851.851 0 0 0-.3362-.405.8748.8748 0 0 0-.9997.0539.8748.8748 0 0 0-.29.4399l-2.2055 6.748H7.5375l-2.2057-6.748a.8573.8573 0 0 0-.29-.4412.8748.8748 0 0 0-.9997-.0537.8585.8585 0 0 0-.3362.4049L.4332 9.5015l-.0325.0862a6.0657 6.0657 0 0 0 2.0119 7.0105l.0113.0087.03.0213 4.976 3.7264 2.462 1.8633 1.4995 1.1321a1.0085 1.0085 0 0 0 1.2197 0l1.4995-1.1321 2.4619-1.8633 5.006-3.7489.0125-.01a6.0682 6.0682 0 0 0 2.0094-7.003z"/>
            </svg>
            <!-- SAML 2.0 -->
            <ShieldCheck v-else-if="p.driver === 'saml'" class="w-5 h-5 text-emerald-400" />
            <!-- OIDC -->
            <svg v-else class="w-5 h-5" fill="#F78C40" viewBox="0 0 24 24">
              <path d="M14.54.889l-3.63 1.773v18.17c-4.15-.52-7.27-2.78-7.27-5.5 0-2.58 2.8-4.75 6.63-5.41v-2.31C4.42 8.322 0 11.502 0 15.332c0 3.96 4.74 7.24 10.91 7.78l3.63-1.71V.888m.64 6.724v2.31c1.43.25 2.71.7 3.76 1.31l-1.97 1.11 7.03 1.53-.5-5.21-1.87 1.06c-1.74-1.06-3.96-1.81-6.45-2.11z"/>
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-semibold text-gray-100">{{ p.name }}</span>
              <span class="text-[10px] font-mono font-medium px-1.5 py-0.5 rounded border" :class="[meta(p.driver).bg, meta(p.driver).border, meta(p.driver).text]">
                {{ t(driverLabels[p.driver] || 'authProvider.driverOidc') }}
              </span>
              <span v-if="p.auto_register" class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-emerald-900/30 border border-emerald-800/40 text-emerald-400">
                {{ t('authProvider.autoRegister') }}
              </span>
            </div>
            <div class="text-xs text-gray-400 mt-0.5 font-mono truncate">
              <template v-if="p.scopes?.length">{{ p.scopes.join(', ') }}</template>
              <template v-if="p.driver === 'oidc' && p.oidc_issuer">
                <span v-if="p.scopes?.length" class="mx-1">·</span>{{ p.oidc_issuer }}
              </template>
              <template v-if="p.driver === 'saml'">{{ p.saml_idp_entity_id }}</template>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button type="button" class="relative w-9 h-5 rounded-full transition-colors cursor-pointer"
              :class="p.is_enabled ? 'bg-brand-500' : 'bg-gray-700'"
              :data-tour="idx === 0 ? 'auth-provider-toggle' : undefined"
              @click="toggleProvider(p)">
              <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform" :class="p.is_enabled ? 'translate-x-4' : ''" />
            </button>
            <button class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors" @click="openEdit(p)">
              <Pencil class="w-3.5 h-3.5" />
            </button>
            <button class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors" @click="confirmDelete(p)">
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Form -->
    <AuthProviderForm v-if="showForm"
      :key="formKey"
      :editing-id="editing"
      :initial-data="formInitial"
      :saml-sp="formSamlSp"
      :has-sp-key="formHasSpKey"
      :saving="saving"
      :error="formError"
      @save="handleSave"
      @cancel="handleCancel" />

    <!-- SSO group mapping (docs/permissions.md) -->
    <SsoGroupMappings v-if="!loading" :providers="providers" />
  </div>
</template>
