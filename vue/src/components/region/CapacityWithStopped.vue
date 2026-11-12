<script setup>
// Region → Workers → Capacity → With stopped projects (GET /knative-regions/{id}/capacity →
// with_stopped, ResourceThresholdService::withStopped): a stopped project holds no capacity of a
// pay-as-you-go region, so this is what the region would need if every stopped project were
// resumed at the same time — per resource the fuller budget, by how much it would be exceeded
// and the capacity that keeps it under the alarm's level. The capacity alarm warns the region's
// provider on this figure at the same levels as on the budgets above (docs/architecture.md →
// Region capacity and admission). A fixed region keeps its stopped projects' reservations (held).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { BUDGET_RESOURCES, barWidth, fillColor } from '@/utils/capacityBudgets'

const props = defineProps({
  // with_stopped of the capacity answer
  figure: { type: Object, required: true },
  // the capacity answer: the capacity of each resource
  capacity: { type: Object, required: true },
})

const { t } = useI18n()

const SHOWN_PROJECTS = 5

const thresholds = computed(() => props.figure.thresholds ?? { warning: 60, critical: 80 })

const resources = computed(() => BUDGET_RESOURCES
  .filter(r => props.figure[r.key] && (props.capacity[r.key]?.capacity ?? 0) > 0)
  .map(r => {
    const f = props.figure[r.key]
    const pct = f.utilization == null ? 0 : Math.round(f.utilization)
    return {
      ...r,
      ...f,
      pct,
      stopped: (f.stopped_always_on ?? 0) + (f.stopped_faas ?? 0),
      budgetLabel: t(f.budget === 'always_on' ? 'settings.regionDetail.capacity.withStopped.budgetAlwaysOn' : 'settings.regionDetail.capacity.withStopped.budgetFaas'),
      threshold: f.level === 'critical' ? thresholds.value.critical : thresholds.value.warning,
    }
  }))

const projects = computed(() => (props.figure.stopped_projects ?? []).slice(0, SHOWN_PROJECTS))
const moreProjects = computed(() => Math.max(0, (props.figure.projects ?? 0) - projects.value.length))
const worst = computed(() => resources.value.find(r => r.level === 'critical') ? 'critical' : (resources.value.find(r => r.level === 'warning') ? 'warning' : null))
</script>

<template>
  <div class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5 space-y-2" data-testid="capacity-with-stopped" data-tour="region-capacity-stopped">
    <div class="flex items-center justify-between gap-2">
      <p class="text-xs font-medium text-gray-300">{{ t('settings.regionDetail.capacity.withStopped.title') }}</p>
      <span v-if="worst" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium"
        :style="{ color: fillColor(worst === 'critical' ? 100 : 70), backgroundColor: worst === 'critical' ? 'rgba(239,68,68,0.12)' : 'rgba(234,179,8,0.12)' }"
        data-testid="with-stopped-level">{{ t(`settings.regionDetail.capacity.withStopped.level.${worst}`) }}</span>
    </div>

    <p v-if="figure.held" class="text-[11px] text-gray-400" data-testid="with-stopped-held">{{ t('settings.regionDetail.capacity.withStopped.held') }}</p>
    <p v-else-if="!figure.projects" class="text-[11px] text-gray-400" data-testid="with-stopped-none">{{ t('settings.regionDetail.capacity.withStopped.none') }}</p>

    <template v-else>
      <p class="text-[11px] text-gray-400">
        {{ t('settings.regionDetail.capacity.withStopped.subtitle', { projects: t('settings.regionDetail.capacity.withStopped.projects', { count: figure.projects }, figure.projects) }) }}
      </p>

      <div v-for="r in resources" :key="r.key" :data-testid="`with-stopped-${r.key}`">
        <div class="flex items-center justify-between text-[11px] text-gray-400 gap-2">
          <span>{{ t(r.label) }} · {{ t('settings.regionDetail.capacity.withStopped.adds', { value: r.stopped + r.unit }) }}</span>
          <span class="font-mono" :style="{ color: r.pct > 60 ? fillColor(r.pct) : undefined }">{{ t('settings.regionDetail.capacity.withStopped.full', { percent: r.pct, budget: r.budgetLabel }) }}</span>
        </div>
        <div class="h-1.5 rounded-full bg-gray-800 overflow-hidden mt-1">
          <div class="h-full rounded-full transition-all duration-500" :style="{ width: barWidth(r.pct, 100) + '%', backgroundColor: fillColor(r.pct) }" />
        </div>
        <p v-if="r.exceeded_by > 0" class="text-[10px] mt-1" style="color:#ef4444">{{ t('settings.regionDetail.capacity.withStopped.exceeded', { value: r.exceeded_by + r.unit }) }}</p>
        <p v-if="r.level" class="text-[10px] mt-0.5 text-gray-300">{{ t('settings.regionDetail.capacity.withStopped.add', { value: r.add_capacity + r.unit, threshold: r.threshold }) }}</p>
      </div>

      <ul class="text-[10px] text-gray-400 font-mono space-y-0.5" data-testid="with-stopped-projects">
        <li v-for="p in projects" :key="p.id">
          {{ p.name }} · {{ p.cpu_m }}m · {{ p.memory_mb }} MB<span v-if="p.stopped === false"> · {{ t('settings.regionDetail.capacity.withStopped.waiting') }}</span>
        </li>
        <li v-if="moreProjects > 0">{{ t('settings.regionDetail.capacity.withStopped.more', { count: moreProjects }) }}</li>
      </ul>
    </template>

    <p class="text-[10px] text-gray-400">{{ t('settings.regionDetail.capacity.withStopped.alarm', thresholds) }}</p>
  </div>
</template>
