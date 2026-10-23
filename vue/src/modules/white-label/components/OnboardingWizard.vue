<script setup>
// First-run wizard of a self-service tenant (multi-tenant): organization name and region (when the
// platform offers several) → POST /api/onboarding.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { moduleRegistry } from '@/modules/index'
import { useLatest } from '@/composables/useLatest'
import { onboardingApi } from '../api/whiteLabel'
import { activeTenant, inTenantContext } from '../utils/context'
import { Building2, MapPin, X } from 'lucide-vue-next'

const { t } = useI18n()
const route = useRoute()

const DISMISS_KEY = 'onboarding_dismissed'

const info = ref(null)
const open = ref(false)
const stepIndex = ref(0)
const orgName = ref('')
const regionId = ref(null)
const saving = ref(false)
const error = ref(null)

const regions = computed(() => info.value?.regions ?? [])
const showRegionStep = computed(() => !info.value?.region_assigned && regions.value.length > 1)

const steps = computed(() => [
  'organization',
  ...(showRegionStep.value ? ['region'] : []),
])
const step = computed(() => steps.value[stepIndex.value] ?? 'organization')
const isLast = computed(() => stepIndex.value >= steps.value.length - 1)

const singleRegion = computed(() => (!info.value?.region_assigned && regions.value.length === 1 ? regions.value[0] : null))

function dismissed(tenantId) {
  try { return sessionStorage.getItem(DISMISS_KEY) === tenantId } catch { return false }
}

// The newest load wins: its answer is the one for the organization now active
const latest = useLatest()

async function load() {
  clearTimeout(loadTimer)
  const current = latest.begin()
  open.value = false
  info.value = null
  if (!inTenantContext() || route.meta?.public) return
  const tenant = activeTenant()
  if (!tenant || dismissed(tenant.id)) return
  try {
    const { data } = await onboardingApi.get()
    if (!current()) return
    info.value = data
    if (data?.status !== 'pending' || !data?.can_complete) return
    orgName.value = data.tenant?.name ?? tenant.name ?? ''
    regionId.value = singleRegion.value?.id ?? regions.value[0]?.id ?? null
    stepIndex.value = 0
    open.value = true
  } catch {
    if (current()) info.value = null
  }
}

// Triggers that come together load once: an organization switch changes the active tenant and
// then the view key (moduleRegistry.viewKey), one after the other in the same task
let loadTimer = null
function scheduleLoad() {
  clearTimeout(loadTimer)
  loadTimer = setTimeout(load, 0)
}
// The tenant watcher is set up after an await (outside the setup), so it is stopped by hand
let stopTenantWatch = null
let unmounted = false
onUnmounted(() => {
  unmounted = true
  clearTimeout(loadTimer)
  stopTenantWatch?.()
})

onMounted(async () => {
  load()
  // The tenancy module picks the active tenant after sign-in (TenantSwitcher): follow it
  try {
    const { useTenantStore } = await import('@/modules/tenancy/stores/tenant')
    if (unmounted) return
    const tenantStore = useTenantStore()
    stopTenantWatch = watch(() => tenantStore.activeTenantId, (id, previous) => { if (id !== previous) scheduleLoad() })
  } catch { /* tenancy module not built */ }
})
watch(() => moduleRegistry.viewKey, scheduleLoad)
watch(() => route.name, (name, previous) => { if (previous === 'login' || previous === 'signup-verify') scheduleLoad() })

function next() {
  error.value = null
  if (step.value === 'organization' && !orgName.value.trim()) {
    error.value = t('whiteLabel.onboarding.nameRequired')
    return
  }
  if (isLast.value) finish()
  else stepIndex.value++
}

function back() {
  error.value = null
  if (stepIndex.value > 0) stepIndex.value--
}

function dismiss() {
  try { sessionStorage.setItem(DISMISS_KEY, activeTenant()?.id ?? '') } catch { /* storage unavailable */ }
  open.value = false
}

async function finish() {
  saving.value = true
  error.value = null
  try {
    const body = { org_name: orgName.value.trim() }
    if (!info.value?.region_assigned && regionId.value) body.region_id = regionId.value
    const { data } = await onboardingApi.complete(body)

    // The organization may have been renamed: keep the stored active tenant in sync
    try {
      const tenant = activeTenant()
      if (tenant && data?.tenant?.name) localStorage.setItem('active_tenant', JSON.stringify({ ...tenant, name: data.tenant.name }))
    } catch { /* ignore */ }

    open.value = false
    moduleRegistry.viewKey++
  } catch (e) {
    const errors = e.response?.data?.errors
    error.value = errors ? Object.values(errors).flat()[0] : (e.response?.data?.message ?? t('common.error'))
  } finally {
    saving.value = false
  }
}

const stepIcons = { organization: Building2, region: MapPin }

defineExpose({ steps, load })
</script>

<template>
  <div v-if="open" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 65" data-test="onboarding-wizard">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" />
    <div class="relative card w-full max-w-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-4 mb-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-100">{{ t('whiteLabel.onboarding.title') }}</h2>
          <p class="text-sm text-gray-400">{{ t('whiteLabel.onboarding.subtitle') }}</p>
        </div>
        <button type="button" class="text-gray-400 hover:text-gray-100" :title="t('whiteLabel.onboarding.later')" data-test="onboarding-dismiss" @click="dismiss">
          <X class="w-4 h-4" />
        </button>
      </div>

      <!-- Steps -->
      <ol class="flex gap-2 mb-6" data-test="onboarding-steps">
        <li v-for="(s, i) in steps" :key="s" class="flex-1">
          <div class="h-1 rounded-full" :class="i <= stepIndex ? 'bg-brand-500' : 'bg-gray-800'" />
          <span class="flex items-center gap-1.5 mt-2 text-xs" :class="i === stepIndex ? 'text-gray-100' : 'text-gray-500'" :data-test="`onboarding-step-${s}`">
            <component :is="stepIcons[s]" class="w-3.5 h-3.5" />{{ t(`whiteLabel.onboarding.step_${s}`) }}
          </span>
        </li>
      </ol>

      <!-- Organization -->
      <div v-if="step === 'organization'" class="space-y-4">
        <div>
          <label class="label">{{ t('whiteLabel.onboarding.orgName') }}</label>
          <input v-model="orgName" class="input" maxlength="255" autofocus data-test="onboarding-org-name" @keyup.enter="next" />
        </div>
        <p v-if="singleRegion" class="text-sm text-gray-400" data-test="onboarding-single-region">
          {{ t('whiteLabel.onboarding.singleRegion', { name: singleRegion.name }) }}
        </p>
        <p v-else-if="!info?.region_assigned && !regions.length" class="text-sm text-amber-400" data-test="onboarding-no-region">
          {{ t('whiteLabel.onboarding.noRegion') }}
        </p>
      </div>

      <!-- Region -->
      <div v-else-if="step === 'region'" class="space-y-3">
        <p class="text-sm text-gray-400">
          {{ t('whiteLabel.onboarding.regionHint', { cpu: info?.default_quota?.cpu_quota_m ?? '—', memory: info?.default_quota?.memory_quota_mb ?? '—' }) }}
        </p>
        <label v-for="r in regions" :key="r.id" class="flex items-center gap-3 px-4 py-3 rounded-lg border cursor-pointer transition-colors"
          :class="regionId === r.id ? 'border-brand-500 bg-brand-500/5' : 'border-gray-800 hover:border-gray-700'" :data-test="`onboarding-region-${r.id}`">
          <input v-model="regionId" type="radio" :value="r.id" />
          <MapPin class="w-4 h-4 text-gray-400" />
          <span class="text-sm text-gray-100">{{ r.name }}</span>
        </label>
      </div>

      <p v-if="error" class="text-sm text-red-400 mt-4">{{ error }}</p>

      <div class="flex justify-between gap-2 mt-6">
        <button type="button" class="btn-secondary" :class="stepIndex === 0 && 'invisible'" @click="back">{{ t('common.back') }}</button>
        <button type="button" class="btn-primary" :disabled="saving" data-test="onboarding-next" @click="next">
          {{ saving ? t('common.saving') : isLast ? t('whiteLabel.onboarding.finish') : t('common.next') }}
        </button>
      </div>
    </div>
  </div>
</template>
