<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import { Cpu, HardDrive, AlertTriangle, ChevronDown, BarChart3, Globe } from 'lucide-vue-next'

const { t } = useI18n()
const regions = ref([])
const loading = ref(false)
const expanded = ref(new Set())

const tutorialStore = useTutorialStore()
function usageSteps(t) {
  return [
    { element: '[data-tour="usage-overview"]',  popover: { title: t('tenancy.tutorialUsageOverview'), description: t('tenancy.tutorialUsageOverviewDesc') } },
    { element: '[data-tour="usage-regions"]',   popover: { title: t('tenancy.tutorialUsageRegions'),  description: t('tenancy.tutorialUsageRegionsDesc') } },
  ]
}
const { start: startTutorial } = createTutorial(usageSteps)
watch(() => tutorialStore.restartCounter, () => { nextTick(() => startTutorial()) })

onMounted(loadUsage)

async function loadUsage() {
  loading.value = true
  try {
    const { data } = await tenantsApi.resourceUsage()
    regions.value = Array.isArray(data) ? data : []
  } finally {
    loading.value = false
  }
}

const infinite = computed(() => regions.value.filter(r => r.region_mode === 'infinite'))
const managed  = computed(() => regions.value.filter(r => r.region_mode !== 'infinite'))

const totals = computed(() => {
  let totalCpu = 0, totalMem = 0, usedCpu = 0, usedMem = 0
  for (const r of regions.value) {
    totalCpu += r.total_cpu_m
    totalMem += r.total_memory_mb
  }
  for (const r of managed.value) {
    usedCpu += r.allocated_cpu_m ?? 0
    usedMem += r.allocated_memory_mb ?? 0
  }
  for (const r of infinite.value) {
    usedCpu += r.used_cpu_m
    usedMem += r.used_memory_mb
  }
  return { totalCpu, totalMem, usedCpu, usedMem }
})

function pct(a, b) { return b ? Math.min(100, Math.round((a / b) * 100)) : 0 }

function barColor(p) {
  if (p >= 90) return '#ef4444'
  if (p >= 70) return '#eab308'
  return 'rgb(var(--brand-500))'
}

function toggle(id) {
  const s = new Set(expanded.value)
  s.has(id) ? s.delete(id) : s.add(id)
  expanded.value = s
}

const alerts = computed(() => {
  const result = []
  for (const r of regions.value) {
    const isManaged = r.region_mode !== 'infinite'
    const cpuUsed = isManaged ? (r.allocated_cpu_m ?? 0) : r.used_cpu_m
    const memUsed = isManaged ? (r.allocated_memory_mb ?? 0) : r.used_memory_mb
    const cpuPct = pct(cpuUsed, r.total_cpu_m)
    const memPct = pct(memUsed, r.total_memory_mb)
    if (cpuPct >= 60) result.push({ region: r.name, type: 'CPU', pct: cpuPct, level: cpuPct >= 80 ? 'critical' : 'warning' })
    if (memPct >= 60) result.push({ region: r.name, type: t('tenancy.usageMemory'), pct: memPct, level: memPct >= 80 ? 'critical' : 'warning' })
  }
  return result
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center gap-3 mb-5">
      <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <h3 class="text-base font-semibold text-gray-100">{{ t('tenancy.resourceUsage') }}</h3>
    </div>

    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>

    <template v-else>
      <!-- Threshold alerts -->
      <div v-if="alerts.length" class="mb-5 space-y-1.5">
        <div v-for="(a, i) in alerts" :key="i"
          class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs border"
          :class="a.level === 'critical'
            ? 'bg-red-950/60 border-red-800/50 text-red-400'
            : 'bg-yellow-950/60 border-yellow-800/40 text-yellow-400'">
          <AlertTriangle class="w-3.5 h-3.5 shrink-0" />
          <span class="font-mono">{{ a.region }}: {{ a.type }} %{{ a.pct }}</span>
        </div>
      </div>

      <!-- Overview cards -->
      <div data-tour="usage-overview" class="grid grid-cols-2 gap-3 mb-5">
        <div class="rounded-xl border border-gray-800 bg-gray-900/60 p-4">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-sky-900/30 border border-sky-800/40">
              <Cpu class="w-4 h-4 text-sky-400" />
            </div>
            <p class="text-xs text-gray-100 uppercase tracking-wider font-semibold">{{ t('tenancy.usageTotalCpu') }}</p>
          </div>
          <p class="text-2xl font-mono font-bold text-gray-100 mb-2">
            {{ totals.usedCpu }}m <span class="text-sm font-normal text-gray-400">/ {{ totals.totalCpu }}m</span>
          </p>
          <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500"
              :style="{ width: pct(totals.usedCpu, totals.totalCpu) + '%', backgroundColor: barColor(pct(totals.usedCpu, totals.totalCpu)) }" />
          </div>
          <p class="text-right text-[10px] font-mono text-gray-400 mt-1">{{ pct(totals.usedCpu, totals.totalCpu) }}%</p>
        </div>
        <div class="rounded-xl border border-gray-800 bg-gray-900/60 p-4">
          <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-violet-900/30 border border-violet-800/40">
              <HardDrive class="w-4 h-4 text-violet-400" />
            </div>
            <p class="text-xs text-gray-100 uppercase tracking-wider font-semibold">{{ t('tenancy.usageTotalMemory') }}</p>
          </div>
          <p class="text-2xl font-mono font-bold text-gray-100 mb-2">
            {{ totals.usedMem }} MB <span class="text-sm font-normal text-gray-400">/ {{ totals.totalMem }} MB</span>
          </p>
          <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500"
              :style="{ width: pct(totals.usedMem, totals.totalMem) + '%', backgroundColor: barColor(pct(totals.usedMem, totals.totalMem)) }" />
          </div>
          <p class="text-right text-[10px] font-mono text-gray-400 mt-1">{{ pct(totals.usedMem, totals.totalMem) }}%</p>
        </div>
      </div>

      <!-- Region cards -->
      <div data-tour="usage-regions">
      <!-- Infinite regions -->
      <div v-for="r in infinite" :key="r.id"
        class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 mb-3">
        <div class="flex items-center gap-2 mb-3">
          <div class="w-7 h-7 rounded-lg flex items-center justify-center bg-violet-900/30 border border-violet-800/40">
            <Globe class="w-3.5 h-3.5 text-violet-400" />
          </div>
          <span class="font-semibold text-gray-100 text-xs font-mono">{{ r.name }}</span>
          <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-violet-900/40 border-violet-800/40 text-violet-400">
            {{ t('tenancy.regionMode_infinite') }}
          </span>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="flex justify-between text-[10px] text-gray-400 mb-1">
              <span class="flex items-center gap-1"><Cpu class="w-2.5 h-2.5" />CPU</span>
              <span class="font-mono text-gray-100">{{ r.used_cpu_m }}m / {{ r.total_cpu_m }}m</span>
            </div>
            <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500"
                :style="{ width: pct(r.used_cpu_m, r.total_cpu_m) + '%', backgroundColor: barColor(pct(r.used_cpu_m, r.total_cpu_m)) }" />
            </div>
          </div>
          <div>
            <div class="flex justify-between text-[10px] text-gray-400 mb-1">
              <span class="flex items-center gap-1"><HardDrive class="w-2.5 h-2.5" />{{ t('tenancy.usageMemory') }}</span>
              <span class="font-mono text-gray-100">{{ r.used_memory_mb }} / {{ r.total_memory_mb }} MB</span>
            </div>
            <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500"
                :style="{ width: pct(r.used_memory_mb, r.total_memory_mb) + '%', backgroundColor: barColor(pct(r.used_memory_mb, r.total_memory_mb)) }" />
            </div>
          </div>
        </div>
      </div>

      <!-- Managed regions (expandable) -->
      <div v-for="r in managed" :key="r.id"
        class="rounded-xl border border-gray-800 bg-gray-900/60 mb-3 cursor-pointer transition-all hover:border-gray-700"
        @click="toggle(r.id)">
        <div class="p-4">
          <div class="flex items-center gap-2 mb-3">
            <div class="w-7 h-7 rounded-lg flex items-center justify-center bg-sky-900/30 border border-sky-800/40">
              <Globe class="w-3.5 h-3.5 text-sky-400" />
            </div>
            <span class="font-semibold text-gray-100 text-xs font-mono">{{ r.name }}</span>
            <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-sky-900/40 border-sky-800/40 text-sky-400">
              {{ t('tenancy.regionMode_managed') }}
            </span>
            <ChevronDown class="w-4 h-4 text-gray-400 ml-auto transition-transform"
              :class="expanded.has(r.id) && 'rotate-180'" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <div class="flex justify-between text-[10px] text-gray-400 mb-1">
                <span class="flex items-center gap-1"><Cpu class="w-2.5 h-2.5" />CPU {{ t('tenancy.usageAllocated') }}</span>
                <span class="font-mono text-gray-100">{{ r.allocated_cpu_m ?? 0 }}m / {{ r.total_cpu_m }}m</span>
              </div>
              <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all duration-500"
                  :style="{ width: pct(r.allocated_cpu_m, r.total_cpu_m) + '%', backgroundColor: barColor(pct(r.allocated_cpu_m, r.total_cpu_m)) }" />
              </div>
            </div>
            <div>
              <div class="flex justify-between text-[10px] text-gray-400 mb-1">
                <span class="flex items-center gap-1"><HardDrive class="w-2.5 h-2.5" />{{ t('tenancy.usageMemory') }} {{ t('tenancy.usageAllocated') }}</span>
                <span class="font-mono text-gray-100">{{ r.allocated_memory_mb ?? 0 }} / {{ r.total_memory_mb }} MB</span>
              </div>
              <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all duration-500"
                  :style="{ width: pct(r.allocated_memory_mb, r.total_memory_mb) + '%', backgroundColor: barColor(pct(r.allocated_memory_mb, r.total_memory_mb)) }" />
              </div>
            </div>
          </div>
        </div>

        <!-- Expanded: tenant breakdown -->
        <div v-if="expanded.has(r.id)" class="border-t border-gray-800 p-4" @click.stop>
          <p class="text-[10px] text-gray-400 uppercase tracking-wider font-medium mb-2">{{ t('tenancy.usageByTenant') }}</p>
          <div v-if="r.tenants.length" class="space-y-1.5">
            <div v-for="tu in r.tenants" :key="tu.tenant_id"
              class="flex items-center justify-between rounded-lg bg-gray-800/40 px-3 py-2 text-[11px]">
              <span class="text-gray-100 font-mono truncate">{{ tu.tenant_name }}</span>
              <div class="flex gap-4 text-gray-400 font-mono text-[10px] shrink-0">
                <span class="flex items-center gap-1"><Cpu class="w-2.5 h-2.5" /><span class="text-gray-100">{{ tu.cpu_quota_m }}m</span></span>
                <span class="flex items-center gap-1"><HardDrive class="w-2.5 h-2.5" /><span class="text-gray-100">{{ tu.memory_quota_mb }} MB</span></span>
              </div>
            </div>
          </div>
          <p v-else class="text-[10px] text-gray-400 italic">{{ t('tenancy.usageNoTenants') }}</p>
        </div>
      </div>

      </div>

      <!-- Empty state -->
      <div v-if="regions.length === 0" class="py-10 text-center">
        <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
          <BarChart3 class="w-6 h-6 text-gray-400" />
        </div>
        <p class="text-sm text-gray-400">{{ t('tenancy.usageNoRegions') }}</p>
      </div>
    </template>
  </div>
</template>
