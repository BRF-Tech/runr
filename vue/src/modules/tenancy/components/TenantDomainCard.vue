<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Globe } from 'lucide-vue-next'
import { settingsApi } from '@/api/settings'
import { useSettingsStore } from '@/stores/settings'

/**
 * The organization domain (Settings, inside an organization; settings.manage): the organization's
 * own base domain — its functions answer at <project>.<region>.<domain> — or, without one, the
 * platform's. GET /settings carries both inside an organization (tenant_base_domain,
 * platform_base_domain); PATCH /settings { base_domain } sets or clears the organization's. A
 * changed domain moves (tenant_domain_migration): the functions answer on the old and the new
 * domain until DNS of the new one answers; PATCH /settings { complete_domain_migration } ends the
 * move at once.
 */
const { t } = useI18n()
const settings = useSettingsStore()

const domain = ref('')
const saving = ref(false)
const saved  = ref(false)
const error  = ref(null)
const completing = ref(false)

// Only inside an organization does the API answer with the platform default apart
const available      = computed(() => Object.prototype.hasOwnProperty.call(settings.data ?? {}, 'platform_base_domain'))
const platformDomain = computed(() => settings.data?.platform_base_domain || '')
const ownDomain      = computed(() => settings.data?.tenant_base_domain || '')
const effective      = computed(() => domain.value.trim().toLowerCase() || platformDomain.value)
const dirty          = computed(() => domain.value.trim().toLowerCase() !== ownDomain.value)
const migration      = computed(() => settings.data?.tenant_domain_migration || null)

watch(ownDomain, (value) => { domain.value = value }, { immediate: true })

function errorOf(e) {
  return e.response?.data?.errors?.base_domain?.[0]
    ?? e.response?.data?.message
    ?? t('common.error')
}

async function save(value) {
  error.value = null; saved.value = false; saving.value = true
  try {
    const { data } = await settingsApi.update({ base_domain: value || null })
    settings.update(data)
    domain.value = data.tenant_base_domain || ''
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    saving.value = false
  }
}

// Ends the move now, whatever DNS answers: the new domain becomes the functions' domain
async function completeMigration() {
  if (!window.confirm(t('tenancy.domain.completeConfirm', { domain: migration.value?.to }))) return
  error.value = null; completing.value = true
  try {
    const { data } = await settingsApi.update({ complete_domain_migration: true })
    settings.update(data)
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    completing.value = false
  }
}
</script>

<template>
  <div v-if="available" class="card" data-testid="tenant-domain-card">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Globe class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('tenancy.domain.title') }}</h3>
    </div>
    <p class="text-xs text-gray-400 mb-2">{{ t('tenancy.domain.description', { format: '{project}.{region}.{domain}' }) }}</p>
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="domain" class="input font-mono flex-1 min-w-[220px]" data-testid="tenant-domain-input"
        :placeholder="platformDomain || t('tenancy.domain.placeholder')" autocomplete="off" spellcheck="false"
        @keyup.enter="dirty && save(domain.trim())" />
      <button type="button" class="btn-primary text-xs" data-testid="tenant-domain-save"
        :disabled="saving || !dirty" @click="save(domain.trim())">
        {{ saved ? t('tenancy.domain.saved') : t('tenancy.domain.save') }}
      </button>
      <button v-if="ownDomain" type="button" class="btn-secondary text-xs" data-testid="tenant-domain-clear"
        :disabled="saving" @click="save(null)">
        {{ t('tenancy.domain.usePlatform') }}
      </button>
    </div>
    <p class="text-xs text-gray-500 mt-2">
      {{ platformDomain ? t('tenancy.domain.platform', { domain: platformDomain }) : t('tenancy.domain.noPlatform') }}
    </p>
    <p v-if="effective" class="text-xs text-gray-400 font-mono mt-2 break-all">
      {{ t('tenancy.domain.example') }} <span style="color:rgb(var(--brand-500))">https://function.region.{{ effective }}</span>
    </p>
    <p v-if="ownDomain" class="text-xs text-gray-500 mt-1">{{ t('tenancy.domain.dnsHint', { host: `*.region.${ownDomain}` }) }}</p>
    <div v-if="migration" class="mt-3 rounded border border-amber-700/50 bg-amber-900/20 p-3" data-testid="tenant-domain-migration">
      <p class="text-xs text-amber-200">
        {{ migration.from
          ? t('tenancy.domain.moving', { from: migration.from, to: migration.to || t('tenancy.domain.platformShort') })
          : t('tenancy.domain.movingFirst', { to: migration.to }) }}
      </p>
      <p v-if="migration.regions?.length" class="text-xs text-gray-400 mt-1">
        {{ t('tenancy.domain.movingRegions', { regions: migration.regions.join(', ') }) }}
      </p>
      <button type="button" class="btn-secondary text-xs mt-2" data-testid="tenant-domain-complete"
        :disabled="completing" @click="completeMigration">
        {{ t('tenancy.domain.complete') }}
      </button>
    </div>
    <p v-if="error" class="text-sm text-red-400 mt-3">{{ error }}</p>
  </div>
</template>
