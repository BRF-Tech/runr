<script setup>
import { reactive, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Key, Shield, ShieldCheck, Settings, Layers, Copy, Users } from 'lucide-vue-next'
import { rbacApi } from '@/api/rbac'
import RoleSelect from '@/components/rbac/RoleSelect.vue'
import TermText from '@/components/TermText.vue'

const props = defineProps({
  editingId: { type: String, default: null },
  initialData: { type: Object, default: null },
  saving: Boolean,
  error: { type: String, default: null },
  // SAML: SP endpoints of the saved provider ({ entity_id, acs_url, metadata_url, login_url })
  samlSp: { type: Object, default: null },
  hasSpKey: Boolean,
})
const emit = defineEmits(['save', 'cancel'])
const { t } = useI18n()

const drivers = [
  { value: 'google',    label: 'authProvider.driverGoogle' },
  { value: 'github',    label: 'authProvider.driverGithub' },
  { value: 'microsoft', label: 'authProvider.driverMicrosoft' },
  { value: 'gitlab',    label: 'authProvider.driverGitlab' },
  { value: 'oidc',      label: 'authProvider.driverOidc' },
  { value: 'saml',      label: 'authProvider.driverSaml' },
]

const form = reactive({
  name: '', driver: 'google', client_id: '', client_secret: '',
  scopes: '', oidc_discovery_url: '', oidc_issuer: '',
  google_hosted_domain: '', microsoft_tenant: '', gitlab_instance_uri: '',
  saml_idp_entity_id: '', saml_idp_sso_url: '', saml_idp_x509_cert: '',
  saml_email_attribute: '', saml_name_attribute: '',
  saml_sign_requests: false, saml_sp_x509_cert: '', saml_sp_private_key: '',
  is_enabled: true, auto_register: true, display_order: 0,
  groups_attribute: '', default_role: null,
})

// SSO group mapping: roles for the just-in-time role picker (docs/permissions.md)
const roles = ref([])
rbacApi.roles().then(({ data }) => { roles.value = data.roles ?? [] }).catch(() => {})

if (props.initialData) Object.assign(form, props.initialData)

const isSaml = computed(() => form.driver === 'saml')
const namePlaceholder = computed(() => t(`authProvider.namePlaceholder${capitalize(form.driver)}`))
const scopesPlaceholder = computed(() => t(`authProvider.scopesPlaceholder${capitalize(form.driver)}`))

const spRows = [
  { key: 'entity_id',    label: 'authProvider.samlSpEntityId' },
  { key: 'acs_url',      label: 'authProvider.samlSpAcsUrl' },
  { key: 'metadata_url', label: 'authProvider.samlSpMetadataUrl' },
]

function capitalize(s) {
  const map = { google: 'Google', github: 'Github', microsoft: 'Microsoft', gitlab: 'Gitlab', oidc: 'Oidc', saml: 'Saml' }
  return map[s] || s
}

// Pre-fill scopes when driver changes (only for new providers)
watch(() => form.driver, (v) => {
  if (!props.editingId && !form.scopes) {
    const defaults = { google: 'openid, email, profile', github: 'user:email', microsoft: 'openid, email, profile', gitlab: 'read_user', oidc: 'openid, email, profile' }
    form.scopes = defaults[v] || ''
  }
})

async function copy(value) {
  try { await navigator.clipboard?.writeText(value) } catch { /* clipboard unavailable — the field is selectable */ }
}

function submit() { emit('save', { ...form }) }
</script>

<template>
  <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 space-y-5">
    <h3 class="text-sm font-semibold text-gray-100">
      {{ editingId ? t('authProvider.editTitle') : t('authProvider.addTitle') }}
    </h3>

    <!-- Driver selector cards -->
    <div data-tour="auth-provider-driver">
      <div class="flex items-center gap-2 mb-3">
        <Shield class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
        <span class="text-xs text-gray-100 font-semibold tracking-wider">{{ t('authProvider.driver') }}</span>
      </div>
      <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
        <button v-for="d in drivers" :key="d.value" type="button"
          class="flex flex-col items-center gap-1.5 px-3 py-3 rounded-lg border transition-all text-center"
          :class="form.driver === d.value
            ? 'border-brand-500 bg-brand-500/10'
            : 'border-gray-700 bg-gray-800/40 hover:border-gray-600'"
          :disabled="!!editingId"
          @click="!editingId && (form.driver = d.value)">
          <!-- Google -->
          <svg v-if="d.value === 'google'" class="w-5 h-5" viewBox="0 0 24 24">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
          </svg>
          <!-- GitHub -->
          <svg v-else-if="d.value === 'github'" class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
          </svg>
          <!-- Microsoft -->
          <svg v-else-if="d.value === 'microsoft'" class="w-5 h-5" viewBox="0 0 24 24">
            <path d="M1 1h10.5v10.5H1z" fill="#F25022"/><path d="M12.5 1H23v10.5H12.5z" fill="#7FBA00"/>
            <path d="M1 12.5h10.5V23H1z" fill="#00A4EF"/><path d="M12.5 12.5H23V23H12.5z" fill="#FFB900"/>
          </svg>
          <!-- GitLab -->
          <svg v-else-if="d.value === 'gitlab'" class="w-5 h-5" fill="#FC6D26" viewBox="0 0 24 24">
            <path d="m23.6004 9.5927-.0337-.0862L20.3.9814a.851.851 0 0 0-.3362-.405.8748.8748 0 0 0-.9997.0539.8748.8748 0 0 0-.29.4399l-2.2055 6.748H7.5375l-2.2057-6.748a.8573.8573 0 0 0-.29-.4412.8748.8748 0 0 0-.9997-.0537.8585.8585 0 0 0-.3362.4049L.4332 9.5015l-.0325.0862a6.0657 6.0657 0 0 0 2.0119 7.0105l.0113.0087.03.0213 4.976 3.7264 2.462 1.8633 1.4995 1.1321a1.0085 1.0085 0 0 0 1.2197 0l1.4995-1.1321 2.4619-1.8633 5.006-3.7489.0125-.01a6.0682 6.0682 0 0 0 2.0094-7.003z"/>
          </svg>
          <!-- SAML 2.0 -->
          <ShieldCheck v-else-if="d.value === 'saml'" class="w-5 h-5 text-emerald-400" />
          <!-- OIDC -->
          <svg v-else class="w-5 h-5" fill="#F78C40" viewBox="0 0 24 24">
            <path d="M14.54.889l-3.63 1.773v18.17c-4.15-.52-7.27-2.78-7.27-5.5 0-2.58 2.8-4.75 6.63-5.41v-2.31C4.42 8.322 0 11.502 0 15.332c0 3.96 4.74 7.24 10.91 7.78l3.63-1.71V.888m.64 6.724v2.31c1.43.25 2.71.7 3.76 1.31l-1.97 1.11 7.03 1.53-.5-5.21-1.87 1.06c-1.74-1.06-3.96-1.81-6.45-2.11z"/>
          </svg>
          <span class="text-[10px] font-medium" :class="form.driver === d.value ? 'text-gray-100' : 'text-gray-400'">
            {{ t(d.label) }}
          </span>
        </button>
      </div>
    </div>

    <!-- General -->
    <div>
      <div class="flex items-center gap-2 mb-3">
        <Settings class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
        <span class="text-xs text-gray-100 font-semibold tracking-wider">{{ t('authProvider.sectionGeneral') }}</span>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="label">{{ t('authProvider.name') }}</label>
          <input v-model="form.name" class="input" :placeholder="namePlaceholder" />
        </div>
        <div>
          <label class="label">{{ t('authProvider.displayOrder') }}</label>
          <input v-model.number="form.display_order" type="number" min="0" class="input" />
        </div>
      </div>
    </div>

    <!-- Credentials (OAuth / OIDC only — SAML uses the IdP certificate below) -->
    <div v-if="!isSaml" data-tour="auth-provider-credentials">
      <div class="flex items-center gap-2 mb-3">
        <Key class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
        <span class="text-xs text-gray-100 font-semibold tracking-wider">{{ t('authProvider.sectionCredentials') }}</span>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label"><TermText path="authProvider.clientId" /></label>
          <input v-model="form.client_id" class="input font-mono text-xs" />
        </div>
        <div>
          <label class="label"><TermText path="authProvider.clientSecret" /></label>
          <input v-model="form.client_secret" type="password" class="input font-mono text-xs" />
          <p v-if="editingId" class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.clientSecretHint') }}</p>
        </div>
      </div>
    </div>

    <!-- Provider-specific config -->
    <div v-if="form.driver !== 'github'" data-tour="auth-provider-config">
      <div class="flex items-center gap-2 mb-3">
        <Layers class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
        <span class="text-xs text-gray-100 font-semibold tracking-wider">{{ t('authProvider.sectionProviderConfig') }}</span>
      </div>

      <!-- Google: Hosted Domain -->
      <div v-if="form.driver === 'google'">
        <label class="label"><TermText path="authProvider.googleHostedDomain" /></label>
        <input v-model="form.google_hosted_domain" class="input text-xs" :placeholder="t('authProvider.googleHostedDomainPlaceholder')" />
        <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.googleHostedDomainHint') }}</p>
      </div>

      <!-- Microsoft: Tenant -->
      <div v-if="form.driver === 'microsoft'">
        <label class="label">{{ t('authProvider.microsoftTenant') }}</label>
        <input v-model="form.microsoft_tenant" class="input font-mono text-xs" :placeholder="t('authProvider.microsoftTenantPlaceholder')" />
        <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.microsoftTenantHint') }}</p>
      </div>

      <!-- GitLab: Instance URI -->
      <div v-if="form.driver === 'gitlab'">
        <label class="label">{{ t('authProvider.gitlabInstanceUri') }}</label>
        <input v-model="form.gitlab_instance_uri" class="input text-xs" :placeholder="t('authProvider.gitlabInstanceUriPlaceholder')" />
        <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.gitlabInstanceUriHint') }}</p>
      </div>

      <!-- OIDC: Discovery URL + Issuer -->
      <div v-if="form.driver === 'oidc'" class="grid grid-cols-2 gap-3">
        <div>
          <label class="label"><TermText path="authProvider.oidcDiscoveryUrl" /></label>
          <input v-model="form.oidc_discovery_url" class="input text-xs" :placeholder="t('authProvider.oidcDiscoveryPlaceholder')" />
        </div>
        <div>
          <label class="label">{{ t('authProvider.oidcIssuer') }}</label>
          <input v-model="form.oidc_issuer" class="input text-xs" :placeholder="t('authProvider.oidcIssuerPlaceholder')" />
        </div>
      </div>

      <!-- SAML 2.0: IdP, attribute mapping, AuthnRequest signing, SP endpoints -->
      <div v-if="isSaml" class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label"><TermText path="authProvider.samlIdpEntityId" /></label>
            <input v-model="form.saml_idp_entity_id" class="input font-mono text-xs" :placeholder="t('authProvider.samlIdpEntityIdPlaceholder')" />
          </div>
          <div>
            <label class="label"><TermText path="authProvider.samlIdpSsoUrl" /></label>
            <input v-model="form.saml_idp_sso_url" class="input text-xs" :placeholder="t('authProvider.samlIdpSsoUrlPlaceholder')" />
          </div>
        </div>
        <div>
          <label class="label">{{ t('authProvider.samlIdpCert') }}</label>
          <textarea v-model="form.saml_idp_x509_cert" rows="4" class="input font-mono text-[11px]" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.samlIdpCertHint') }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">{{ t('authProvider.samlEmailAttribute') }}</label>
            <input v-model="form.saml_email_attribute" class="input font-mono text-xs" placeholder="email" />
            <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.samlEmailAttributeHint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('authProvider.samlNameAttribute') }}</label>
            <input v-model="form.saml_name_attribute" class="input font-mono text-xs" placeholder="displayName" />
            <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.samlNameAttributeHint') }}</p>
          </div>
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-100 cursor-pointer">
          <input v-model="form.saml_sign_requests" type="checkbox" class="accent-brand-500" />
          {{ t('authProvider.samlSignRequests') }}
          <span class="text-gray-400">— {{ t('authProvider.samlSignRequestsHint') }}</span>
        </label>
        <div v-if="form.saml_sign_requests" class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">{{ t('authProvider.samlSpCert') }}</label>
            <textarea v-model="form.saml_sp_x509_cert" rows="4" class="input font-mono text-[11px]" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          </div>
          <div>
            <label class="label">{{ t('authProvider.samlSpKey') }}</label>
            <textarea v-model="form.saml_sp_private_key" rows="4" class="input font-mono text-[11px]"
              :placeholder="hasSpKey ? t('authProvider.samlSpKeyStored') : '-----BEGIN PRIVATE KEY-----'"></textarea>
            <p class="text-[10px] text-gray-400 mt-1">{{ hasSpKey ? t('authProvider.samlSpKeyKeepHint') : t('authProvider.samlSpKeyHint') }}</p>
          </div>
        </div>

        <!-- Read-only SP endpoints to register at the IdP -->
        <div class="rounded-lg border border-gray-700 bg-gray-800/40 p-3 space-y-2" data-tour="auth-provider-saml-sp">
          <p class="text-xs font-semibold text-gray-100">{{ t('authProvider.samlSpTitle') }}</p>
          <template v-if="samlSp">
            <p class="text-[10px] text-gray-400">{{ t('authProvider.samlSpHint') }}</p>
            <div v-for="row in spRows" :key="row.key">
              <label class="label">{{ t(row.label) }}</label>
              <div class="flex items-center gap-2">
                <input :value="samlSp[row.key]" readonly class="input font-mono text-[11px]" @focus="$event.target.select()" />
                <button type="button" class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors shrink-0"
                  :title="t('authProvider.copy')" @click="copy(samlSp[row.key])">
                  <Copy class="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          </template>
          <p v-else class="text-[10px] text-gray-400">{{ t('authProvider.samlSpAfterSave') }}</p>
        </div>
      </div>
    </div>

    <!-- Scopes & Options -->
    <div data-tour="auth-provider-scopes">
      <div class="flex items-center gap-2 mb-3">
        <Shield class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
        <span class="text-xs text-gray-100 font-semibold tracking-wider">{{ t('authProvider.sectionScopes') }}</span>
      </div>
      <div v-if="!isSaml" class="mb-3">
        <label class="label">{{ t('authProvider.scopes') }}</label>
        <input v-model="form.scopes" class="input text-xs" :placeholder="scopesPlaceholder" />
      </div>
      <div class="flex items-center gap-6">
        <label class="flex items-center gap-2 text-xs text-gray-100 cursor-pointer">
          <input v-model="form.is_enabled" type="checkbox" class="accent-brand-500" />
          {{ t('authProvider.enabled') }}
        </label>
        <label class="flex items-center gap-2 text-xs text-gray-100 cursor-pointer">
          <input v-model="form.auto_register" type="checkbox" class="accent-brand-500" />
          {{ t('authProvider.autoRegister') }}
          <span class="text-gray-400">— {{ t('authProvider.autoRegisterHint') }}</span>
        </label>
      </div>
    </div>

    <!-- Groups & roles (SSO group mapping, just-in-time role) -->
    <div data-testid="auth-provider-groups">
      <div class="flex items-center gap-2 mb-3">
        <Users class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
        <span class="text-xs text-gray-100 font-semibold tracking-wider">{{ t('authProvider.sectionGroups') }}</span>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label"><TermText path="authProvider.groupsAttribute" /></label>
          <input v-model="form.groups_attribute" class="input text-xs font-mono" placeholder="groups" />
          <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.groupsAttributeHint') }}</p>
        </div>
        <div>
          <label class="label">{{ t('authProvider.defaultRole') }}</label>
          <RoleSelect v-model="form.default_role" :roles="roles" allow-empty :empty-label="t('authProvider.orgDefaultRole')" />
          <p class="text-[10px] text-gray-400 mt-1">{{ t('authProvider.defaultRoleHint') }}</p>
        </div>
      </div>
    </div>

    <p v-if="error" class="text-xs font-medium text-red-400">{{ error }}</p>

    <div class="flex gap-2 pt-1">
      <button class="btn-primary text-xs" :disabled="saving" @click="submit">
        {{ saving ? t('common.saving') : t('common.save') }}
      </button>
      <button class="btn-secondary text-xs" @click="emit('cancel')">
        {{ t('common.cancel') }}
      </button>
    </div>
  </div>
</template>
