<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import TenantUsers from './TenantUsers.vue'
import TenantQuotas from './TenantQuotas.vue'
import TenantResidency from '@/modules/supply-chain/components/TenantResidency.vue'
import { useTenantDetail } from '../composables/useTenantDetail'
import { useTenantSwitch } from '../composables/useTenantSwitch'
import { Building2, Plus, Pencil, Trash2, ChevronDown, Users, Globe, Loader2, RotateCcw, AlertTriangle, LogIn } from 'lucide-vue-next'

const { t }       = useI18n()
const { confirm }  = useConfirm()
const toast        = useToast()
const detail       = useTenantDetail()
const tenantSwitch = useTenantSwitch()

const tenants     = ref([])
const loading     = ref(false)
const showForm    = ref(false)
const editingId   = ref(null)
const expandedId  = ref(null)

const form = ref({ name: '', slug: '' })
const formError = ref(null)
const formSaving = ref(false)

const tutorialStore = useTutorialStore()
function tenantSteps(t) {
  return [
    { element: '[data-tour="tenant-form"]',  popover: { title: t('tenancy.tutorialTenantForm'), description: t('tenancy.tutorialTenantFormDesc') } },
    { element: '[data-tour="tenant-list"]',  popover: { title: t('tenancy.tutorialTenantList'), description: t('tenancy.tutorialTenantListDesc') } },
  ]
}
const { start: startTutorial } = createTutorial(tenantSteps)
watch(() => tutorialStore.restartCounter, () => {
  openCreate()
  nextTick(() => startTutorial())
})

onMounted(loadTenants)

// Deletion runs in a queued job: poll the list while a tenant is being deleted
let pollTimer = null
onUnmounted(stopPolling)
// The newest list load wins; one that ends after the page is gone starts no poll
const latest = useLatest()

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = null
}

function syncPolling() {
  const deleting = tenants.value.some(tn => tn.status === 'deleting')
  if (deleting && !pollTimer) {
    pollTimer = setInterval(() => loadTenants(true), 5000)
  } else if (!deleting) {
    stopPolling()
  }
}

// Never throws: a failed reload after a successful create / delete is not that action's error
async function loadTenants(silent = false) {
  const current = latest.begin()
  if (!silent) loading.value = true
  try {
    const { data } = await tenantsApi.list()
    if (!current()) return
    tenants.value = Array.isArray(data) ? data : (data.data ?? [])
    if (expandedId.value && !tenants.value.some(tn => tn.id === expandedId.value)) expandedId.value = null
  } catch (e) {
    if (current() && !silent) toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    if (current()) {
      loading.value = false
      syncPolling()
    }
  }
}

const isDeleting = (tenant) => tenant.status === 'deleting'
const deleteFailed = (tenant) => tenant.status === 'delete_failed'

function openCreate() {
  editingId.value = null
  form.value = { name: '', slug: '' }
  formError.value = null
  showForm.value = true
}

function openEdit(tenant) {
  editingId.value = tenant.id
  form.value = { name: tenant.name, slug: tenant.slug }
  formError.value = null
  showForm.value = true
}

function cancelForm() {
  showForm.value = false
  editingId.value = null
  formError.value = null
}

async function submitForm() {
  formSaving.value = true
  formError.value  = null
  try {
    if (editingId.value) {
      await tenantsApi.update(editingId.value, form.value)
      toast.success(t('tenancy.tenantUpdated'))
    } else {
      await tenantsApi.create(form.value)
      toast.success(t('tenancy.tenantCreated'))
    }
    showForm.value = false
    editingId.value = null
    await loadTenants()
  } catch (e) {
    formError.value = e.response?.data?.message
      ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
      ?? t('common.error')
  } finally {
    formSaving.value = false
  }
}

// Also the retry of a failed deletion; force drops the tenant even with leftovers on the regions
async function deleteTenant(tenant, force = false) {
  const ok = await confirm({
    title:       force ? t('tenancy.forceDelete') : (deleteFailed(tenant) ? t('tenancy.retryDelete') : t('tenancy.deleteTenant')),
    message:     force ? t('tenancy.forceDeleteConfirm', { name: tenant.name }) : t('tenancy.deleteConfirm', { name: tenant.name }),
    destructive: true,
  })
  if (!ok) return
  try {
    await tenantsApi.destroy(tenant.id, force ? { force: 1 } : undefined)
    toast.success(t('tenancy.deletionStarted'))
    if (expandedId.value === tenant.id) expandedId.value = null
    await loadTenants()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
    await loadTenants(true)
  }
}

// Open the organization as its administrator (impersonation when not a member, audit-logged)
async function openAsAdmin(tenant) {
  const ok = await tenantSwitch.enter(tenant.id, { toProjects: true })
  if (!ok) toast.error(t('tenancy.switchFailed'))
}

function toggleExpand(tenant) {
  if (expandedId.value === tenant.id) {
    expandedId.value = null
    detail.reset()
  } else {
    expandedId.value = tenant.id
    detail.loadTenant(tenant.id)
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-5">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <h3 class="text-base font-semibold text-gray-100">{{ t('tenancy.tenants') }}</h3>
      </div>
      <button class="btn-primary text-xs flex items-center gap-1.5" @click="openCreate">
        <Plus class="w-3.5 h-3.5" />{{ t('tenancy.createTenant') }}
      </button>
    </div>

    <!-- Create/edit form -->
    <div v-if="showForm" data-tour="tenant-form" class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 mb-5 space-y-4">
      <p class="text-sm font-semibold text-gray-100">
        {{ editingId ? t('tenancy.editTenant') : t('tenancy.createTenant') }}
      </p>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">{{ t('tenancy.tenantName') }}</label>
          <input v-model="form.name" class="input" :placeholder="t('tenancy.tenantName')" />
        </div>
        <div>
          <label class="label">{{ t('tenancy.tenantSlug') }}</label>
          <input v-model="form.slug" class="input font-mono" :placeholder="t('tenancy.tenantSlug')"
            :disabled="!!editingId" />
        </div>
      </div>
      <p v-if="formError" class="text-xs text-red-400">{{ formError }}</p>
      <div class="flex gap-2">
        <button class="btn-primary text-xs" :disabled="formSaving" @click="submitForm">
          {{ formSaving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="btn-secondary text-xs" @click="cancelForm">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <!-- Loading -->
    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>

    <!-- Tenant list -->
    <div v-else data-tour="tenant-list" class="space-y-2">
      <div v-for="tenant in tenants" :key="tenant.id">
        <div class="rounded-xl border bg-gray-900/60 px-4 py-3 transition-all cursor-pointer hover:border-gray-700"
          :class="expandedId === tenant.id ? 'border-gray-700 rounded-b-none' : 'border-gray-800'"
          @click="toggleExpand(tenant)">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 border bg-gray-800/60 border-gray-700/60 text-gray-400">
              <Building2 class="w-4 h-4" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm font-semibold text-gray-100">{{ tenant.name }}</span>
                <code class="text-[10px] font-mono px-1.5 py-0.5 rounded-sm bg-gray-800 border border-gray-700 text-gray-400">{{ tenant.slug }}</code>
                <span v-if="isDeleting(tenant)"
                  class="flex items-center gap-1 px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-amber-900/30 border-amber-800/40 text-amber-400">
                  <Loader2 class="w-3 h-3 animate-spin" />{{ t('tenancy.statusDeleting') }}
                </span>
                <span v-else-if="deleteFailed(tenant)"
                  class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-red-900/30 border-red-800/40 text-red-400">
                  {{ t('tenancy.statusDeleteFailed') }}
                </span>
              </div>
              <div class="flex items-center gap-3 mt-1 text-[11px] text-gray-400">
                <span class="flex items-center gap-1"><Users class="w-3 h-3" />{{ t('tenancy.userCount', { n: tenant.user_count ?? 0 }) }}</span>
                <span class="flex items-center gap-1"><Globe class="w-3 h-3" />{{ t('tenancy.regionCount', { n: tenant.region_count ?? 0 }) }}</span>
              </div>
              <p v-if="deleteFailed(tenant) && tenant.status_message"
                class="mt-1 text-[11px] text-red-400 wrap-break-word">{{ tenant.status_message }}</p>
            </div>
            <button class="p-1.5 rounded-md text-gray-400 hover:text-amber-400 hover:bg-gray-800 transition-colors shrink-0"
              :title="t('tenancy.openAsAdmin')"
              :aria-label="t('tenancy.openAsAdmin')"
              data-testid="open-as-admin"
              @click.stop="openAsAdmin(tenant)">
              <LogIn class="w-3.5 h-3.5" />
            </button>
            <button class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors shrink-0 disabled:opacity-40 disabled:pointer-events-none"
              :disabled="!!tenant.status && tenant.status !== 'active'"
              @click.stop="openEdit(tenant)">
              <Pencil class="w-3.5 h-3.5" />
            </button>
            <template v-if="deleteFailed(tenant)">
              <button class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors shrink-0"
                :title="t('tenancy.retryDelete')"
                @click.stop="deleteTenant(tenant)">
                <RotateCcw class="w-3.5 h-3.5" />
              </button>
              <button class="p-1.5 rounded-md text-red-400 hover:text-red-300 hover:bg-red-900/20 transition-colors shrink-0"
                :title="t('tenancy.forceDelete')"
                @click.stop="deleteTenant(tenant, true)">
                <AlertTriangle class="w-3.5 h-3.5" />
              </button>
            </template>
            <button v-else class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors shrink-0 disabled:opacity-40 disabled:pointer-events-none"
              :disabled="isDeleting(tenant)"
              :title="t('tenancy.deleteTenant')"
              @click.stop="deleteTenant(tenant)">
              <Trash2 class="w-3.5 h-3.5" />
            </button>
            <ChevronDown class="w-4 h-4 text-gray-400 transition-transform shrink-0"
              :class="expandedId === tenant.id ? 'rotate-180' : ''" />
          </div>
        </div>

        <!-- Expanded detail -->
        <div v-if="expandedId === tenant.id"
          class="border border-gray-800 border-t-0 rounded-b-xl bg-gray-950/40 p-5">
          <div class="flex gap-1 mb-4 border-b border-gray-800 pb-2">
            <button v-for="tab in ['users', 'regions', 'residency']" :key="tab"
              class="tab-line text-xs"
              :class="detail.selectedTab.value === tab ? 'is-active' : ''"
              @click="detail.selectedTab.value = tab">
              {{ t('tenancy.' + tab) }}
            </button>
          </div>
          <TenantUsers   v-if="detail.selectedTab.value === 'users'"   :tenant-id="tenant.id" />
          <TenantQuotas  v-if="detail.selectedTab.value === 'regions'" :tenant-id="tenant.id" />
          <TenantResidency v-if="detail.selectedTab.value === 'residency'" :tenant-id="tenant.id" />
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="tenants.length === 0" class="py-10 text-center">
        <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
          <Building2 class="w-6 h-6 text-gray-400" />
        </div>
        <p class="text-sm text-gray-400">{{ t('tenancy.noTenants') }}</p>
      </div>
    </div>
  </div>
</template>
