<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { isRegionReady } from '@/composables/useProjectForm'
import { useResidency } from '@/modules/supply-chain/composables/useResidency'
import {
  GLOBAL_MAX_REGIONS, GLOBAL_MIN_REGIONS, globalRegionUnavailableKey, toggleRegion,
} from '@/utils/globalProjects'
import { Globe, Star } from 'lucide-vue-next'

// Regions, primary region and DNS mode of a global project (create wizard, step 1). Only regions
// whose global DNS server is ready take part; the others are listed greyed out with the reason.
const { t } = useI18n()

const props = defineProps({
  regions:   { type: Array, required: true },
  regionIds: { type: Array, default: () => [] },
  primaryId: { type: String, default: null },
  mode:      { type: String, default: 'ha' },
})

const emit = defineEmits(['update:regionIds', 'update:primaryId', 'update:mode'])

// Data residency (docs/supply-chain.md) applies to every region of the project
const { load: loadResidency, isAllowed } = useResidency()
onMounted(() => loadResidency())

const unavailableKey = (r) => globalRegionUnavailableKey(r, { ready: isRegionReady(r), allowed: isAllowed(r.id) })
const selectable = (r) => unavailableKey(r) === null
const full = computed(() => props.regionIds.length >= GLOBAL_MAX_REGIONS)

const chosen = computed(() => props.regionIds
  .map(id => props.regions.find(r => r.id === id))
  .filter(Boolean))
const hiddenCount = computed(() => props.regions.filter(r => !selectable(r)).length)

function toggle(r) {
  if (!selectable(r)) return
  if (!props.regionIds.includes(r.id) && full.value) return
  const next = toggleRegion(props.regionIds, props.primaryId, r.id)
  emit('update:regionIds', next.regionIds)
  if (next.primaryId !== props.primaryId) emit('update:primaryId', next.primaryId)
}

const modes = computed(() => [
  { id: 'ha',        label: t('globalProjects.mode.ha'),        hint: t('globalProjects.mode.haHint') },
  { id: 'proximity', label: t('globalProjects.mode.proximity'), hint: t('globalProjects.mode.proximityHint') },
])
</script>

<template>
  <div class="space-y-5" data-testid="global-regions">
    <div>
      <p class="text-xs text-gray-400 mb-2">{{ t('globalProjects.create.regionsHint', { min: GLOBAL_MIN_REGIONS, max: GLOBAL_MAX_REGIONS }) }}</p>
      <p v-if="regions.length === 0" class="text-xs text-gray-400 italic">{{ t('settings.regions.noRegions') }}</p>
      <div v-else class="grid grid-cols-2 gap-2">
        <button
          v-for="r in regions"
          :key="r.id"
          type="button"
          class="text-left px-3 py-2 rounded-md border text-xs transition-colors"
          :class="regionIds.includes(r.id)
            ? 'text-white border-transparent'
            : selectable(r) && !full
              ? 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'
              : 'bg-gray-900 border-gray-800 text-gray-400 cursor-not-allowed opacity-50'"
          :style="regionIds.includes(r.id) ? 'background-color:rgb(var(--brand-600));border-color:rgb(var(--brand-600))' : ''"
          :disabled="!selectable(r) || (full && !regionIds.includes(r.id))"
          :title="unavailableKey(r) ? t(unavailableKey(r)) : ''"
          :aria-pressed="regionIds.includes(r.id) ? 'true' : 'false'"
          :data-global-region="r.name"
          @click="toggle(r)"
        >
          <div class="flex items-center gap-1.5">
            <span class="inline-block w-3 h-3 rounded-sm border shrink-0 text-[9px] leading-[10px] text-center"
              :class="regionIds.includes(r.id) ? 'border-white bg-white/20' : 'border-gray-600'">{{ regionIds.includes(r.id) ? '✓' : '' }}</span>
            <p class="font-mono font-medium">{{ r.name }}</p>
            <Star v-if="r.id === primaryId" class="w-3 h-3 text-amber-300" :aria-label="t('globalProjects.roles.primary')" />
          </div>
          <p v-if="unavailableKey(r)" class="text-[10px] mt-0.5 opacity-80">{{ t(unavailableKey(r)) }}</p>
          <p v-else class="text-[10px] mt-0.5 opacity-70 flex items-center gap-1"><Globe class="w-2.5 h-2.5" />{{ t('globalProjects.create.globalDnsReady') }}</p>
        </button>
      </div>
      <p v-if="hiddenCount > 0" class="text-[11px] text-gray-400 mt-2" data-testid="global-regions-missing">
        {{ t('globalProjects.create.missingRegions', { n: hiddenCount }) }}
      </p>
      <p v-if="regionIds.length > 0 && regionIds.length < GLOBAL_MIN_REGIONS" class="text-[11px] text-yellow-400 mt-1">
        {{ t('globalProjects.create.needMore', { min: GLOBAL_MIN_REGIONS }) }}
      </p>
    </div>

    <div v-if="chosen.length">
      <label class="label">{{ t('globalProjects.create.primary') }}</label>
      <select
        class="input font-mono"
        :value="primaryId"
        data-testid="global-primary"
        @change="emit('update:primaryId', $event.target.value)"
      >
        <option v-for="r in chosen" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
      <p class="text-[11px] text-gray-400 mt-1">{{ t('globalProjects.create.primaryHint') }}</p>
    </div>

    <div>
      <label class="label">{{ t('globalProjects.create.mode') }}</label>
      <div class="grid grid-cols-2 gap-2">
        <button
          v-for="m in modes" :key="m.id"
          type="button"
          class="rounded-md border px-3 py-2 text-xs text-left transition-colors"
          :class="mode === m.id ? 'border-sky-500/60 bg-sky-900/20 text-sky-300' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
          :aria-pressed="mode === m.id ? 'true' : 'false'"
          :data-global-mode="m.id"
          @click="emit('update:mode', m.id)"
        >
          <span class="font-medium">{{ m.label }}</span>
          <p class="text-[10px] mt-0.5 opacity-80 leading-relaxed">{{ m.hint }}</p>
        </button>
      </div>
    </div>
  </div>
</template>
