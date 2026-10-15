<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import TenantCharges from './TenantCharges.vue'
import { Building2, ChevronDown, Cpu, HardDrive, Layers, Calendar, Zap, DollarSign } from 'lucide-vue-next'

const { t } = useI18n()
const tenants = ref([])
const expanded = ref(null)
const loading = ref(false)
const tenantCosts = ref({})

async function loadTenants() {
  loading.value = true
  try {
    const { data } = await tenantsApi.list()
    tenants.value = Array.isArray(data) ? data : data?.data ?? []
  } catch { /* silent */ } finally {
    loading.value = false
  }
}

async function loadTenantBilling(tenantId) {
  if (tenantCosts.value[tenantId]) return
  try {
    const { data } = await tenantsApi.billingSummary(tenantId)
    tenantCosts.value[tenantId] = data
  } catch { /* silent */ }
}

function toggle(id) {
  if (expanded.value === id) {
    expanded.value = null
  } else {
    expanded.value = id
    loadTenantBilling(id)
  }
}

function fmtCost(v) {
  return v != null ? Number(v).toFixed(2) : '—'
}

onMounted(loadTenants)
</script>

<template>
  <div>
    <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider mb-4">{{ t('cost.adminOverview') }}</p>

    <div v-if="loading" class="flex justify-center py-8">
      <div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else data-tour="billing-overview" class="space-y-2">
      <div v-for="tenant in tenants" :key="tenant.id"
        class="rounded-xl border border-gray-800 bg-gray-900/60 overflow-hidden transition-all"
        :class="expanded === tenant.id ? 'border-gray-700' : 'hover:border-gray-700'">
        <div class="flex items-center gap-3 px-4 py-3 cursor-pointer" @click="toggle(tenant.id)">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-gray-800/60 border border-gray-700/60">
            <Building2 class="w-4 h-4 text-gray-400" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-semibold text-gray-100">{{ tenant.name }}</span>
              <code class="text-[10px] font-mono px-1.5 py-0.5 rounded-sm bg-gray-800 border border-gray-700 text-gray-400">{{ tenant.slug }}</code>
            </div>
          </div>
          <span v-if="tenantCosts[tenant.id]" class="font-mono text-sm font-semibold" style="color:rgb(var(--brand-500))">
            {{ fmtCost(tenantCosts[tenant.id].total_cost) }} {{ tenantCosts[tenant.id].currency ?? 'USD' }}
          </span>
          <ChevronDown class="w-4 h-4 text-gray-400 transition-transform shrink-0"
            :class="expanded === tenant.id ? 'rotate-180' : ''" />
        </div>

        <!-- Expanded detail -->
        <div v-if="expanded === tenant.id" class="border-t border-gray-800 px-4 py-4" @click.stop>
          <div v-if="tenantCosts[tenant.id]" class="space-y-3">
            <!-- Cost breakdown -->
            <div class="grid grid-cols-3 gap-3">
              <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3">
                <div class="flex items-center gap-1 mb-1">
                  <Calendar class="w-3 h-3 text-sky-400" />
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">{{ t('cost.monthlyQuota') }}</span>
                </div>
                <p class="text-lg font-mono font-bold" style="color:rgb(var(--brand-500))">
                  {{ fmtCost(tenantCosts[tenant.id].monthly_cost) }}
                </p>
              </div>
              <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3">
                <div class="flex items-center gap-1 mb-1">
                  <Zap class="w-3 h-3 text-emerald-400" />
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">{{ t('cost.usageCost') }}</span>
                </div>
                <p class="text-lg font-mono font-bold text-emerald-400">
                  {{ fmtCost(tenantCosts[tenant.id].usage_cost) }}
                </p>
              </div>
              <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3">
                <div class="flex items-center gap-1 mb-1">
                  <DollarSign class="w-3 h-3 text-amber-400" />
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">{{ t('cost.totalFee') }}</span>
                </div>
                <p class="text-lg font-mono font-bold text-amber-400">
                  {{ fmtCost((tenantCosts[tenant.id].monthly_cost ?? 0) + (tenantCosts[tenant.id].usage_cost ?? 0)) }}
                </p>
              </div>
            </div>
            <!-- Resource cards -->
            <div class="grid grid-cols-3 gap-3">
              <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3 text-center">
                <div class="flex items-center justify-center gap-1 mb-1">
                  <Cpu class="w-3 h-3 text-sky-400" />
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">CPU</span>
                </div>
                <p class="text-lg font-mono font-bold text-sky-400">
                  {{ tenantCosts[tenant.id].cpu_m ?? 0 }}m
                </p>
                <p class="text-[10px] text-gray-500 font-mono">
                  / {{ tenantCosts[tenant.id].cpu_quota_m ?? 0 }}m {{ t('cost.allocated') }}
                </p>
              </div>
              <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3 text-center">
                <div class="flex items-center justify-center gap-1 mb-1">
                  <HardDrive class="w-3 h-3 text-emerald-400" />
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">{{ t('cost.memory') }}</span>
                </div>
                <p class="text-lg font-mono font-bold text-emerald-400">
                  {{ tenantCosts[tenant.id].memory_mb ?? 0 }}MB
                </p>
                <p class="text-[10px] text-gray-500 font-mono">
                  / {{ tenantCosts[tenant.id].memory_quota_mb ?? 0 }}MB {{ t('cost.allocated') }}
                </p>
              </div>
              <div class="rounded-lg border border-gray-800 bg-gray-950/40 p-3 text-center">
                <div class="flex items-center justify-center gap-1 mb-1">
                  <Layers class="w-3 h-3 text-amber-400" />
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">{{ t('cost.deployments') }}</span>
                </div>
                <p class="text-lg font-mono font-bold text-amber-400">
                  {{ tenantCosts[tenant.id].active_deployments ?? 0 }}
                </p>
              </div>
            </div>
            <!-- Charges (what the tenant owes per month) -->
            <div>
              <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider mb-2">{{ t('cost.charges') }}</p>
              <TenantCharges :tenant-id="tenant.id" mode="admin" />
            </div>
          </div>
          <div v-else class="py-4 text-center">
            <div class="animate-spin h-4 w-4 border-2 border-gray-400 border-t-transparent rounded-full mx-auto" />
          </div>
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
