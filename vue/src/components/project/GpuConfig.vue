<script setup>
// GPU of a project (config.gpu): CaaS projects can ask for GPUs; XaaS / FaaS projects get them
// on GPU runtime versions (PyTorch / TensorFlow GPU), where at least one GPU is always used.
import { computed, toRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Cpu, Info, AlertTriangle } from 'lucide-vue-next'
import { useRuntimesStore } from '@/stores/runtimes'
import { useRegionGpu } from '@/composables/useRegionGpu'
import { GPU_DEFAULT_TIMEOUT } from '@/utils/ai'
import GpuFields from '@/components/ai/GpuFields.vue'

const { t } = useI18n()
const runtimesStore = useRuntimesStore()

const props = defineProps({
  modelValue:     { type: Object, default: () => ({ enabled: false, count: 1, type: '', memory_min: null }) },
  serviceType:    { type: String, default: null },
  regionId:       { type: String, default: null },
  runtime:        { type: String, default: null },
  runtimeVersion: { type: String, default: null },
  timeoutSeconds: { type: Number, default: 30 },
  maxTimeout:     { type: Number, default: null },
  scaleToZero:    { type: Boolean, default: true },
  minScale:       { type: Number, default: 0 },
})
const emit = defineEmits(['update:modelValue', 'update:timeoutSeconds'])

/** The chosen runtime version needs a GPU node (catalog `gpu_required` / manifest resources.gpu). */
const gpuRequired = computed(() => {
  if (props.serviceType === 'caas' || !props.runtime) return false
  const entry = runtimesStore.byId?.(props.runtime)
  if (entry?.gpu) return true
  const version = (entry?.versions ?? []).find(v => v.version === props.runtimeVersion)
  return !!version?.gpu_required
})

const visible = computed(() => props.serviceType === 'caas' || gpuRequired.value)

const { products, hasGpus, gpu, sandboxFor } = useRegionGpu(toRef(props, 'regionId'))
const sandbox = computed(() => sandboxFor(props.serviceType))

const value = computed(() => ({ enabled: false, count: 1, type: '', memory_min: null, ...(props.modelValue ?? {}) }))

function update(patch) {
  emit('update:modelValue', { ...value.value, ...patch })
}

function setEnabled(on) {
  update({ enabled: on })
  // A GPU workload loads large images and models: a longer default timeout
  if (on && props.timeoutSeconds === 30) {
    const max = props.maxTimeout || GPU_DEFAULT_TIMEOUT
    emit('update:timeoutSeconds', Math.min(GPU_DEFAULT_TIMEOUT, max))
  }
}

// GPU runtime versions always run with a GPU
watch([gpuRequired, visible], ([required, shown]) => {
  if (required && !value.value.enabled) setEnabled(true)
  if (!shown && value.value.enabled) update({ enabled: false })
}, { immediate: true })
</script>

<template>
  <section v-if="visible" data-testid="gpu-config">
    <p class="section-title flex items-center gap-1.5"><Cpu class="w-3.5 h-3.5" />{{ t('ai.gpu.section') }}</p>

    <div class="flex items-center gap-3 mb-3">
      <button type="button" class="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors"
        :class="[value.enabled ? 'bg-amber-500' : 'bg-gray-700', gpuRequired ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer']"
        :disabled="gpuRequired" data-testid="gpu-toggle" :aria-pressed="value.enabled"
        @click="setEnabled(!value.enabled)">
        <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="value.enabled ? 'translate-x-5' : 'translate-x-0'" />
      </button>
      <span class="text-sm" :class="value.enabled ? 'text-amber-400' : 'text-gray-400'">
        {{ value.enabled ? t('ai.gpu.enabled') : t('ai.gpu.disabled') }}
      </span>
    </div>
    <p v-if="gpuRequired" class="text-xs text-gray-400 mb-3">{{ t('ai.gpu.runtimeRequiresGpu') }}</p>

    <template v-if="value.enabled">
      <GpuFields
        id-prefix="project-gpu"
        :count="value.count" :type="value.type" :memory-min="value.memory_min" :products="products"
        @update:count="update({ count: $event })"
        @update:type="update({ type: $event })"
        @update:memory-min="update({ memory_min: $event })"
      />

      <p v-if="gpu && !hasGpus" class="mt-3 flex items-start gap-1.5 text-xs text-amber-400" data-testid="gpu-none">
        <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ t('ai.gpu.noGpuNodes') }}
      </p>
      <p v-if="sandbox?.gpu === 'unsupported'" class="mt-3 flex items-start gap-1.5 text-xs text-red-400" data-testid="gpu-sandbox-unsupported">
        <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ sandbox.message || t('ai.gpu.sandboxUnsupported') }}
      </p>
      <p v-else-if="sandbox?.gpu === 'nvproxy'" class="mt-3 flex items-start gap-1.5 text-xs text-gray-400">
        <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ t('ai.gpu.sandboxNvproxy') }}
      </p>

      <!-- Cold start: scale to zero stays available -->
      <div class="mt-3 flex items-start gap-2 rounded-lg border border-gray-700 bg-gray-800/40 px-3 py-2 text-[11px] text-gray-400" data-testid="gpu-cold-start">
        <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" />
        <span>
          {{ t('ai.gpu.coldStartHint') }}
          <template v-if="scaleToZero && minScale < 1"> {{ t('ai.gpu.minScaleSuggestion') }}</template>
        </span>
      </div>
    </template>
  </section>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
