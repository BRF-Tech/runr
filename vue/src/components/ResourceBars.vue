<script setup>
// Projects page → Total resources: the always-on and the FaaS overcommit budget per resource,
// as the API admits projects against them (docs/architecture.md → Region capacity and
// admission), summed over the reachable regions, and per region on click. In an organization a
// platform region shows the organization's quota there, split the same way.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Cpu, MemoryStick, MapPin, ChevronDown } from 'lucide-vue-next'
import CapacityBudgets from '@/components/region/CapacityBudgets.vue'

const { t } = useI18n()

defineProps({
  // summed budgets (sumCapacities())
  total:           { type: Object, default: null },
  // settings regions with `capacity` (and usedCpu / usedMem on unlimited regions)
  regionBreakdown: { type: Array, default: () => [] },
})

const open = ref(false)

const isInfinite = r => r.unlimited || r.region_mode === 'infinite'
</script>

<template>
  <div
    class="mb-6 rounded-xl border border-gray-800 bg-gray-900/60 select-none cursor-pointer overflow-hidden"
    data-testid="resource-bars"
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

    <!-- Total budgets -->
    <div class="px-5 py-4" data-testid="resource-total">
      <CapacityBudgets :capacity="total" icons />
      <p class="mt-3 text-[10px] text-gray-400">{{ t('projects.totalResourcesHint') }}</p>
    </div>

    <!-- Per-region breakdown (accordion) -->
    <div v-if="open && regionBreakdown.length > 0" data-testid="region-breakdown" @click.stop="open = false">
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
          r.reachable ? '' : 'opacity-40',
        ]"
        :data-testid="`region-budgets-${r.id}`"
      >
        <!-- Region header row -->
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-1.5 h-1.5 rounded-full shrink-0"
              :class="r.reachable ? 'bg-emerald-400' : 'bg-red-400'"
            ></div>
            <MapPin class="w-3 h-3 text-gray-400 shrink-0" />
            <span class="text-xs font-mono font-semibold text-gray-100">{{ r.name }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="!r.reachable"
              class="text-[10px] px-1.5 py-0.5 rounded-sm font-medium bg-red-900/30 border border-red-800/40 text-red-400">
              {{ t('projects.regionUnreachable') }}
            </span>
            <span v-if="r.capacity_scope === 'quota'"
              class="text-[10px] px-1.5 py-0.5 rounded-sm font-medium border border-gray-700 text-gray-300">
              {{ t('projects.orgQuota') }}
            </span>
            <span v-if="isInfinite(r)"
              class="text-[10px] px-1.5 py-0.5 rounded-sm font-mono font-semibold"
              style="background:rgb(var(--brand-500)/0.1);border:1px solid rgb(var(--brand-500)/0.2);color:rgb(var(--brand-500))">∞ unlimited</span>
          </div>
        </div>

        <!-- Infinite region: what the projects there reserve -->
        <div v-if="isInfinite(r)" class="flex items-center gap-4 text-xs font-mono text-gray-400">
          <span class="flex items-center gap-1"><Cpu class="w-3 h-3" />{{ r.usedCpu }}m</span>
          <span class="flex items-center gap-1"><MemoryStick class="w-3 h-3" />{{ r.usedMem }} MB</span>
        </div>

        <!-- Region capacity or organization quota: both budgets -->
        <CapacityBudgets v-else-if="r.capacity" :capacity="r.capacity" />
      </div>
    </div>
  </div>
</template>
