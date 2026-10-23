<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Layers, ExternalLink, MapPin } from 'lucide-vue-next'
import StatusBadge from '@/components/StatusBadge.vue'

/**
 * The environment the deployments tab shows and deploys to (docs/environments.md): production —
 * the project's own workload — and the project's other environments in pipeline order, each with
 * the version it runs. Below it the selected one's URL, region and live deployment.
 */
const props = defineProps({
  environments: { type: Array, default: () => [] },
  // The selected environment's name; null: production
  modelValue:   { type: String, default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const keyOf = env => (env.is_primary ? null : env.name)
const isSelected = env => keyOf(env) === (props.modelValue ?? null)
const selected = computed(() => props.environments.find(isSelected) ?? null)

function pick(env) {
  if (!isSelected(env)) emit('update:modelValue', keyOf(env))
}
</script>

<template>
  <div v-if="environments.length > 1" class="px-6 mb-4" data-testid="environment-switcher">
    <div class="flex items-center gap-2 flex-wrap">
      <span class="flex items-center gap-1.5 text-xs text-gray-400 mr-1">
        <Layers class="w-3.5 h-3.5" />{{ t('environments.switcherLabel') }}
      </span>
      <button
        v-for="env in environments"
        :key="env.id"
        type="button"
        class="flex items-center gap-2 px-3 py-1.5 rounded-md border text-sm transition-colors"
        :class="isSelected(env) ? 'text-gray-100' : 'border-gray-800 text-gray-400 hover:text-gray-100 hover:bg-gray-800/40'"
        :style="isSelected(env) ? 'background:rgb(var(--brand-600) / 0.15);border-color:rgb(var(--brand-500) / 0.5)' : ''"
        :aria-pressed="isSelected(env)"
        :data-testid="`environment-${env.name}`"
        @click="pick(env)"
      >
        <span class="font-medium">{{ env.name }}</span>
        <span v-if="env.deployment?.version" class="font-mono text-xs" style="color:rgb(var(--brand-500))">{{ env.deployment.version.label }}</span>
        <span v-else class="text-[11px] text-gray-400">—</span>
      </button>
    </div>

    <div v-if="selected" class="flex items-center gap-4 flex-wrap mt-2.5 text-xs text-gray-400" data-testid="environment-summary">
      <StatusBadge v-if="selected.deployment" :status="selected.deployment.status" />
      <span v-else>{{ t('environments.notDeployed') }}</span>
      <span v-if="selected.region" class="flex items-center gap-1"><MapPin class="w-3 h-3" />{{ selected.region.name }}</span>
      <a
        v-if="selected.url"
        :href="selected.url" target="_blank" rel="noopener noreferrer"
        class="flex items-center gap-1 font-mono text-brand-400 hover:text-brand-300 hover:underline"
      >{{ selected.url }}<ExternalLink class="w-3 h-3" /></a>
    </div>
  </div>
</template>
