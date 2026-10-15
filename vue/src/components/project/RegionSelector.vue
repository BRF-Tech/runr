<script setup>
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { isRegionReady } from '@/composables/useProjectForm'
import { useResidency } from '@/modules/supply-chain/composables/useResidency'

const { t } = useI18n()

const props = defineProps({
  regions: { type: Array, required: true },
  selectedRegionId: { type: [Number, String], default: null },
})

const emit = defineEmits(['select'])

// Data residency (docs/supply-chain.md): regions the organization may not use are greyed out,
// its default region is preselected
const { residency, load: loadResidency, isAllowed } = useResidency()
const selectable = (r) => isRegionReady(r) && isAllowed(r.id)

onMounted(async () => {
  await loadResidency()
  const preferred = residency.value?.default_region_id
  const region = props.regions.find(r => r.id === preferred)
  if (!props.selectedRegionId && region && selectable(region)) emit('select', region.id)
})
</script>

<template>
  <div>
    <p v-if="regions.length === 0" class="text-xs text-gray-400 italic">{{ t('settings.regions.noRegions') }}</p>
    <div v-else class="grid grid-cols-2 gap-2">
      <button
        v-for="r in regions"
        :key="r.id"
        type="button"
        class="text-left px-3 py-2 rounded-md border text-xs transition-colors"
        :class="selectedRegionId === r.id
          ? 'text-white border-transparent'
          : selectable(r)
            ? 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'
            : 'bg-gray-900 border-gray-800 text-gray-400 cursor-not-allowed opacity-50'"
        :style="selectedRegionId === r.id ? 'background-color:rgb(var(--brand-600));border-color:rgb(var(--brand-600))' : ''"
        :disabled="!selectable(r)"
        :title="isAllowed(r.id) ? '' : t('supplyChain.residency.notAllowed')"
        :data-region-allowed="isAllowed(r.id) ? 'true' : 'false'"
        @click="selectable(r) && emit('select', r.id)"
      >
        <div class="flex items-center gap-1.5">
          <p class="font-mono font-medium">{{ r.name }}</p>
          <span v-if="r.is_demo" class="px-1 py-0.5 rounded text-[9px] font-medium" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))">Demo</span>
          <span v-if="!r.is_active" class="text-[9px] text-gray-400">({{ t('settings.regions.inactive') }})</span>
          <span v-else-if="!r.is_synced && !r.is_demo && !r.has_kubeconfig" class="text-[9px] text-orange-500">({{ t('settings.regions.knativeNotReady') }})</span>
          <span v-if="residency?.default_region_id === r.id" class="text-[9px] text-sky-400">({{ t('supplyChain.residency.defaultBadge') }})</span>
        </div>
        <p class="text-[10px] mt-0.5 opacity-70">
          <template v-if="r.unlimited || r.region_mode === 'infinite'">∞</template>
          <template v-else>{{ r.total_cpu_m }}m · {{ r.total_memory_mb }} MB</template>
        </p>
      </button>
    </div>
  </div>
</template>
