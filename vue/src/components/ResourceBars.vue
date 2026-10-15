<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Cpu, MemoryStick, MapPin, ChevronDown } from 'lucide-vue-next'

const { t } = useI18n()

defineProps({
  cpuUsed:   { type: Number, default: 0 },
  cpuTotal:  { type: Number, default: 0 },
  cpuPct:    { type: Number, default: 0 },
  memUsed:   { type: Number, default: 0 },
  memTotal:  { type: Number, default: 0 },
  memPct:    { type: Number, default: 0 },
  regionBreakdown: { type: Array, default: () => [] },
})

const open = ref(false)

function barColor(pct) {
  if (pct > 80) return '#ef4444'
  if (pct > 60) return '#f97316'
  return 'rgb(var(--brand-500))'
}

function barBg(pct) {
  if (pct > 80) return 'rgba(239,68,68,0.08)'
  if (pct > 60) return 'rgba(249,115,22,0.08)'
  return 'rgb(var(--brand-500) / 0.07)'
}
</script>

<template>
  <div
    class="mb-6 rounded-xl border border-gray-800 bg-gray-900/60 select-none cursor-pointer overflow-hidden"
    @click="open = !open"
  >
    <!-- Header -->
    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800/60">
      <div class="flex items-center gap-2">
        <div class="w-0.5 h-4 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <span class="text-[13px] font-semibold text-gray-100 uppercase tracking-wider">{{ t('projects.totalResources') }}</span>
      </div>
      <ChevronDown
        class="w-4 h-4 text-gray-400 transition-transform duration-200"
        :class="open ? 'rotate-180' : ''"
      />
    </div>

    <!-- Total bars -->
    <div class="px-5 py-4 flex flex-col gap-4">
      <div v-if="cpuTotal">
        <div class="flex items-center justify-between text-xs mb-2 gap-2">
          <span class="flex items-center gap-1.5 shrink-0 whitespace-nowrap text-gray-400 font-medium">
            <Cpu class="w-3.5 h-3.5 text-gray-400" />{{ t('common.cpu') }}
          </span>
          <span class="font-mono text-xs whitespace-nowrap" :style="{ color: barColor(cpuPct) }">
            {{ cpuUsed }}m / {{ cpuTotal }}m
            <span class="text-gray-400 ml-1">({{ cpuPct }}%)</span>
          </span>
        </div>
        <div class="h-2 rounded-full overflow-hidden" :style="{ background: barBg(cpuPct) }">
          <div class="h-full rounded-full transition-all duration-500" :style="{ width: cpuPct + '%', backgroundColor: barColor(cpuPct) }" />
        </div>
      </div>

      <div v-if="memTotal">
        <div class="flex items-center justify-between text-xs mb-2 gap-2">
          <span class="flex items-center gap-1.5 shrink-0 whitespace-nowrap text-gray-400 font-medium">
            <MemoryStick class="w-3.5 h-3.5 text-gray-400" />{{ t('common.memory') }}
          </span>
          <span class="font-mono text-xs whitespace-nowrap" :style="{ color: barColor(memPct) }">
            {{ memUsed }} MB / {{ memTotal }} MB
            <span class="text-gray-400 ml-1">({{ memPct }}%)</span>
          </span>
        </div>
        <div class="h-2 rounded-full overflow-hidden" :style="{ background: barBg(memPct) }">
          <div class="h-full rounded-full transition-all duration-500" :style="{ width: memPct + '%', backgroundColor: barColor(memPct) }" />
        </div>
      </div>
    </div>

    <!-- Per-region breakdown (accordion) -->
    <div v-if="open && regionBreakdown.length > 0" @click.stop="open = false">
      <!-- Section label -->
      <div class="px-5 py-2 border-t border-gray-800/60" style="background:rgb(var(--gray-800)/0.3)">
        <span class="text-[10px] font-semibold uppercase tracking-widest text-gray-100">{{ t('projects.regionBreakdown') }}</span>
      </div>

      <div
        v-for="(r, idx) in regionBreakdown"
        :key="r.id"
        class="px-5 py-4"
        :class="[
          idx < regionBreakdown.length - 1 ? 'border-b border-gray-800/40' : '',
          r.cluster_status !== 'online' && !r.is_demo ? 'opacity-40' : '',
        ]"
      >
        <!-- Region header row -->
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-1.5 h-1.5 rounded-full shrink-0"
              :class="r.cluster_status === 'online' || r.is_demo ? 'bg-emerald-400' : 'bg-red-400'"
            ></div>
            <MapPin class="w-3 h-3 text-gray-400 shrink-0" />
            <span class="text-xs font-mono font-semibold text-gray-100">{{ r.name }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="r.cluster_status !== 'online' && !r.is_demo"
              class="text-[10px] px-1.5 py-0.5 rounded font-medium bg-red-900/30 border border-red-800/40 text-red-400">
              {{ t('projects.regionUnreachable') }}
            </span>
            <span v-if="r.unlimited || r.region_mode === 'infinite'"
              class="text-[10px] px-1.5 py-0.5 rounded font-mono font-semibold"
              style="background:rgb(var(--brand-500)/0.1);border:1px solid rgb(var(--brand-500)/0.2);color:rgb(var(--brand-500))">∞ unlimited</span>
          </div>
        </div>

        <!-- Infinite region -->
        <template v-if="r.unlimited || r.region_mode === 'infinite'">
          <div class="flex items-center gap-4 text-xs font-mono text-gray-400">
            <span class="flex items-center gap-1"><Cpu class="w-3 h-3" />{{ r.usedCpu }}m</span>
            <span class="flex items-center gap-1"><MemoryStick class="w-3 h-3" />{{ r.usedMem }} MB</span>
          </div>
        </template>

        <!-- Managed region bars -->
        <template v-else>
          <div class="flex flex-col gap-4">
            <div>
              <div class="flex items-center justify-between text-xs mb-2 gap-2">
                <span class="flex items-center gap-1.5 shrink-0 whitespace-nowrap text-gray-400 font-medium">
                  <Cpu class="w-3.5 h-3.5 text-gray-400" />{{ t('common.cpu') }}
                </span>
                <span class="font-mono text-xs whitespace-nowrap" :style="{ color: barColor(r.cpuPct) }">
                  {{ r.usedCpu }}m / {{ r.total_cpu_m }}m
                  <span class="text-gray-400 ml-1">({{ r.cpuPct }}%)</span>
                </span>
              </div>
              <div class="h-2 rounded-full overflow-hidden" :style="{ background: barBg(r.cpuPct) }">
                <div class="h-full rounded-full transition-all duration-500" :style="{ width: r.cpuPct + '%', backgroundColor: barColor(r.cpuPct) }" />
              </div>
            </div>
            <div>
              <div class="flex items-center justify-between text-xs mb-2 gap-2">
                <span class="flex items-center gap-1.5 shrink-0 whitespace-nowrap text-gray-400 font-medium">
                  <MemoryStick class="w-3.5 h-3.5 text-gray-400" />{{ t('common.memory') }}
                </span>
                <span class="font-mono text-xs whitespace-nowrap" :style="{ color: barColor(r.memPct) }">
                  {{ r.usedMem }} MB / {{ r.total_memory_mb }} MB
                  <span class="text-gray-400 ml-1">({{ r.memPct }}%)</span>
                </span>
              </div>
              <div class="h-2 rounded-full overflow-hidden" :style="{ background: barBg(r.memPct) }">
                <div class="h-full rounded-full transition-all duration-500" :style="{ width: r.memPct + '%', backgroundColor: barColor(r.memPct) }" />
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
