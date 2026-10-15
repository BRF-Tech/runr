<script setup>
// GPU count, GPU type (nvidia.com/gpu.product of the region's GPU nodes) and minimum GPU memory.
// Used by the project forms (GpuConfig) and the model deploy dialog.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatGpuMemory } from '@/utils/ai'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

const props = defineProps({
  count:     { type: [Number, String], default: 1 },
  type:      { type: String, default: '' },
  memoryMin: { type: [Number, String], default: null },
  products:  { type: Array, default: () => [] },
  disabled:  { type: Boolean, default: false },
  idPrefix:  { type: String, default: 'gpu' },
})
const emit = defineEmits(['update:count', 'update:type', 'update:memoryMin'])

const productOptions = computed(() => props.products.map(p => ({
  value: p.product,
  label: t('ai.gpu.productOption', {
    product: p.product,
    memory:  formatGpuMemory(p.memory_mb),
    count:   p.allocatable ?? p.count ?? 0,
  }),
})))

/** Most GPUs one pod can get on a single node of the chosen type (or of any node). */
const maxCount = computed(() => {
  const list = props.type ? props.products.filter(p => p.product === props.type) : props.products
  const max  = Math.max(0, ...list.map(p => Number(p.max_per_node ?? p.allocatable ?? p.count ?? 0)))
  return Math.min(8, Math.max(1, max || 8))
})

const memoryTooHigh = computed(() => {
  const min = Number(props.memoryMin)
  if (!min || !props.products.length) return false
  const list = props.type ? props.products.filter(p => p.product === props.type) : props.products
  return !list.some(p => Number(p.memory_mb) >= min)
})
</script>

<template>
  <div class="grid grid-cols-3 gap-3">
    <div>
      <label class="label" :for="`${idPrefix}-count`">{{ t('ai.gpu.count') }}</label>
      <input :id="`${idPrefix}-count`" :value="count" type="number" min="1" :max="maxCount" class="input font-mono"
        data-testid="gpu-count" :disabled="disabled"
        @input="emit('update:count', Math.max(1, parseInt($event.target.value, 10) || 1))" />
    </div>
    <div>
      <label class="label" :for="`${idPrefix}-type`">{{ t('ai.gpu.type') }}</label>
      <select :id="`${idPrefix}-type`" :value="type" class="input" data-testid="gpu-type" :disabled="disabled"
        @change="emit('update:type', $event.target.value)">
        <option value="">{{ t('ai.gpu.anyType') }}</option>
        <option v-for="o in productOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </div>
    <div>
      <label class="label" :for="`${idPrefix}-memory`"><TermText path="ai.gpu.memoryMin" /></label>
      <input :id="`${idPrefix}-memory`" :value="memoryMin ?? ''" type="number" min="0" step="1024" class="input font-mono"
        data-testid="gpu-memory-min" :placeholder="t('ai.gpu.memoryMinPlaceholder')" :disabled="disabled"
        @input="emit('update:memoryMin', $event.target.value === '' ? null : Math.max(0, parseInt($event.target.value, 10) || 0))" />
    </div>
    <p v-if="Number(count) > maxCount" class="col-span-3 text-xs text-amber-400">{{ t('ai.gpu.countAboveNode', { max: maxCount }) }}</p>
    <p v-if="memoryTooHigh" class="col-span-3 text-xs text-amber-400">{{ t('ai.gpu.memoryAboveNodes') }}</p>
  </div>
</template>
