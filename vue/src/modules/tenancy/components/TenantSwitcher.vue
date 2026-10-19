<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '../stores/tenant'
import { useTenantSwitch } from '../composables/useTenantSwitch'
import { useSettingsStore } from '@/stores/settings'
import { ChevronDown, Building2, Search } from 'lucide-vue-next'

const { t } = useI18n()
const auth = useAuthStore()
const store = useTenantStore()
const settingsStore = useSettingsStore()
const tenantSwitch = useTenantSwitch()

const MAX_DISPLAY = 5
const open = ref(false)
const search = ref('')
const tenancyEnabled = computed(() => settingsStore.data.tenancy_enabled === true)

// The sign-in's tenant list has no membership flag (impersonation): /tenant/current has it
function tryFetch() {
  if (!tenancyEnabled.value) return
  if (!store.loaded || !store.tenants.length || store.tenants.some(tn => tn.member === undefined)) store.fetchTenants()
}
onMounted(tryFetch)
watch(tenancyEnabled, (v) => { if (v) tryFetch() })

const activeName = computed(() =>
  store.activeTenant?.name ?? (auth.isAdmin ? 'Central' : t('tenancy.noTenant'))
)

// When no search: show recent tenants (max 5, excluding active)
// When searching: filter all tenants by name
const displayTenants = computed(() => {
  const others = store.tenants.filter(tn => tn.id !== store.activeTenantId)
  const q = search.value.trim().toLowerCase()
  if (q) {
    return others.filter(tn => tn.name.toLowerCase().includes(q))
  }
  // Show recent tenants, or all tenants if no recent history
  return store.recentTenants.length ? store.recentTenants : others.slice(0, MAX_DISPLAY)
})

const hasMore = computed(() => {
  if (search.value.trim()) return false
  const totalOthers = store.tenants.filter(tn => tn.id !== store.activeTenantId).length
  return totalOthers > store.recentTenants.length
})

function toggle() {
  open.value = !open.value
  if (!open.value) search.value = ''
}

function close() { open.value = false; search.value = '' }

const switchError = ref(null)

async function select(tenantId) {
  switchError.value = null
  const ok = await tenantSwitch.enter(tenantId)
  if (!ok) {
    switchError.value = t('tenancy.switchFailed')
    return
  }
  close()
}

async function goToCentral() {
  close()
  await tenantSwitch.leave()
}
</script>

<template>
  <div v-if="tenancyEnabled" class="relative" v-click-outside="close">
    <button
      class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-sm text-gray-100 hover:bg-gray-800 transition-colors"
      @click="toggle"
    >
      <Building2 class="w-3.5 h-3.5 text-gray-400" />
      <span class="max-w-[120px] truncate">{{ activeName }}</span>
      <ChevronDown class="w-3 h-3 text-gray-400" />
    </button>

    <div
      v-if="open"
      class="absolute left-0 mt-1 w-56 rounded-md border border-gray-800 bg-gray-900 shadow-xl py-1 z-50"
    >
      <!-- Active -->
      <div class="px-3 pt-3 pb-2.5 border-b border-gray-800">
        <p class="text-[11px] uppercase tracking-wider text-gray-100 font-medium mb-2">{{ t('tenancy.activeTenant') }}</p>
        <div class="flex items-center gap-2">
          <div class="w-0.5 h-7 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <div class="min-w-0">
            <p class="text-sm text-gray-100 font-semibold truncate leading-tight">{{ activeName }}</p>
            <span v-if="store.activeTenant" class="text-[10px] font-mono px-1.5 py-0.5 rounded-sm mt-0.5 inline-block" style="background:rgb(var(--brand-500)/0.12);border:1px solid rgb(var(--brand-500)/0.25);color:rgb(var(--brand-500))">{{ store.activeTenant.slug }}</span>
            <span v-else-if="auth.isAdmin" class="text-[10px] font-mono px-1.5 py-0.5 rounded-sm mt-0.5 inline-block border text-amber-500" style="background:rgb(180 83 9/0.12);border-color:rgb(180 83 9/0.35)">{{ t('tenancy.platform') }}</span>
          </div>
        </div>
      </div>

      <!-- Central (admin only, when in a tenant) -->
      <button
        v-if="auth.isAdmin && store.activeTenant"
        class="w-full px-3 py-2 text-left hover:bg-gray-800 transition-colors flex items-center justify-between border-b border-gray-800 group"
        @click="goToCentral"
      >
        <div class="flex items-center gap-2">
          <Building2 class="w-3.5 h-3.5 text-amber-500" />
          <span class="text-sm text-amber-400 group-hover:text-amber-300">Central</span>
        </div>
        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded-sm border text-amber-500" style="background:rgb(180 83 9/0.12);border-color:rgb(180 83 9/0.35)">{{ t('tenancy.platform') }}</span>
      </button>

      <!-- Search -->
      <div class="px-2 py-1.5 border-b border-gray-800">
        <div class="flex items-center gap-1.5 px-2 py-1 rounded-sm bg-gray-800">
          <Search class="w-3 h-3 text-gray-400 shrink-0" />
          <input
            v-model="search"
            type="text"
            class="bg-transparent text-xs text-gray-100 placeholder-gray-400 outline-hidden w-full"
            :placeholder="t('tenancy.searchTenants')"
            @click.stop
          />
        </div>
      </div>

      <!-- Tenant list -->
      <div v-if="displayTenants.length" class="py-1 max-h-48 overflow-y-auto">
        <button
          v-for="tenant in displayTenants"
          :key="tenant.id"
          class="w-full px-3 py-2 text-left text-sm text-gray-400 hover:text-brand-500 hover:bg-gray-800 transition-colors flex items-center justify-between"
          @click="select(tenant.id)"
        >
          <span class="truncate">{{ tenant.name }}</span>
          <span class="text-[10px] font-mono text-gray-400" :title="tenant.member === false ? t('tenancy.openAsAdminHint') : undefined">{{ tenant.member === false ? t('tenancy.asAdministrator') : tenant.role }}</span>
        </button>
      </div>

      <!-- Switch error -->
      <div v-if="switchError" class="px-3 py-1.5 text-xs text-red-400">{{ switchError }}</div>

      <!-- Hint or no results -->
      <div v-if="!search && hasMore" class="px-3 py-1.5 text-[10px] text-gray-400">
        {{ t('tenancy.searchTenants') }}
      </div>
      <div v-else-if="search && !displayTenants.length" class="px-3 py-2 text-xs text-gray-400">
        {{ t('tenancy.noResults') }}
      </div>
    </div>
  </div>
</template>
