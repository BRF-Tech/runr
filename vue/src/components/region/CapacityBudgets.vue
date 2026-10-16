<script setup>
// The always-on and the FaaS overcommit budget per resource of a capacity: a region's
// (Workers → Capacity, the projects page) or an organization's quota on a region (the projects
// page of an organization). Shapes and rules: @/utils/capacityBudgets.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Cpu, MemoryStick } from 'lucide-vue-next'
import { budgetRows, pct, barWidth, fillColor } from '@/utils/capacityBudgets'

const props = defineProps({
  capacity: { type: Object, default: null },
  // each resource in its own bordered box (Workers → Capacity)
  boxed: { type: Boolean, default: false },
  icons: { type: Boolean, default: false },
})

const { t } = useI18n()

const resources = computed(() => budgetRows(props.capacity).map(r => ({
  ...r,
  icon: r.key === 'cpu_m' ? Cpu : MemoryStick,
  alwaysOnPct: pct(r.always_on_used, r.capacity),
  faasPct: pct(r.faas_used, r.faas_budget),
  used: (r.always_on_used ?? 0) + (r.faas_used ?? 0),
})))

function faasLabel(r) {
  return r.faas_overcommit == null
    ? t('settings.regionDetail.capacity.faasMixed')
    : t('settings.regionDetail.capacity.faas', { factor: r.faas_overcommit })
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div
      v-for="r in resources" :key="r.key"
      class="space-y-2"
      :class="boxed ? 'rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5' : ''"
      :data-testid="`capacity-${r.key}`"
    >
      <p class="flex items-center gap-1.5 text-xs font-medium text-gray-300">
        <component :is="r.icon" v-if="icons" class="w-3.5 h-3.5 text-gray-400" />{{ t(r.label) }}
      </p>

      <template v-if="r.capacity > 0">
        <div data-testid="budget-always-on">
          <div class="flex items-center justify-between text-[11px] text-gray-400 gap-2">
            <span>{{ t('settings.regionDetail.capacity.alwaysOn') }}</span>
            <span class="font-mono" :style="{ color: r.alwaysOnPct > 60 ? fillColor(r.alwaysOnPct) : undefined }">{{ r.always_on_used }}{{ r.unit }} / {{ r.capacity }}{{ r.unit }} · {{ t('settings.regionDetail.capacity.free', { value: r.always_on_available + r.unit }) }}</span>
          </div>
          <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden mt-1">
            <div class="h-full rounded-full transition-all duration-500" :style="{ width: barWidth(r.always_on_used, r.capacity) + '%', backgroundColor: fillColor(r.alwaysOnPct) }" />
          </div>
        </div>
        <div data-testid="budget-faas">
          <div class="flex items-center justify-between text-[11px] text-gray-400 gap-2">
            <span>{{ faasLabel(r) }}</span>
            <span class="font-mono" :style="{ color: r.faasPct > 60 ? fillColor(r.faasPct) : undefined }">{{ r.faas_used }}{{ r.unit }} / {{ r.faas_budget }}{{ r.unit }} · {{ t('settings.regionDetail.capacity.free', { value: r.faas_available + r.unit }) }}</span>
          </div>
          <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden mt-1">
            <div class="h-full rounded-full transition-all duration-500" :style="{ width: barWidth(r.faas_used, r.faas_budget) + '%', backgroundColor: fillColor(r.faasPct) }" />
          </div>
        </div>
      </template>

      <p v-else class="text-[11px] font-mono text-gray-400" data-testid="budget-unlimited">
        {{ r.used }}{{ r.unit }} · {{ t('settings.regionDetail.capacity.noLimit') }}
      </p>
    </div>
  </div>
</template>
