<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { INSTANCE_SIZES, findInstanceSize, matchInstanceSize, formatVcpu, toMillicores } from '@/utils/instanceSizes'

const { t } = useI18n()

const pullPolicyOptions = [
  { value: 'IfNotPresent', label: t('create.fields.pullIfNotPresent') },
  { value: 'Always', label: t('create.fields.pullAlways') },
  { value: 'Never', label: t('create.fields.pullNever') },
]

const props = defineProps({
  cpu: { type: Number, required: true },
  memoryMb: { type: Number, required: true },
  timeoutSeconds: { type: Number, required: true },
  serviceType: { type: String, default: null },
  imagePullPolicy: { type: String, default: 'IfNotPresent' },
  // region request_timeout: the API rejects a longer timeout
  maxTimeout: { type: Number, default: null },
})

const emit = defineEmits(['update:cpu', 'update:memoryMb', 'update:timeoutSeconds', 'update:imagePullPolicy'])

// Reference size presets: derived from the current CPU + memory, never stored.
const sizeOptions = computed(() => INSTANCE_SIZES.map(s => ({
  id: s.id,
  label: t('create.sizes.option', { name: s.id, vcpu: formatVcpu(s.cpu), memory: s.memory_mb }),
})))
const matchedSizeId = computed(() => matchInstanceSize(props.cpu, props.memoryMb)?.id ?? '')

function applySize(event) {
  const size = findInstanceSize(event.target.value)
  if (!size) { event.target.value = matchedSizeId.value; return }
  emit('update:cpu', toMillicores(size.cpu))
  emit('update:memoryMb', size.memory_mb)
}
</script>

<template>
  <section>
    <p class="section-title">{{ t('create.sections.resources') }}</p>
    <div class="mb-3">
      <label class="label">{{ t('create.sizes.label') }}</label>
      <select class="input font-mono" :value="matchedSizeId" @change="applySize">
        <option value="" disabled>{{ t('create.sizes.custom') }}</option>
        <option v-for="o in sizeOptions" :key="o.id" :value="o.id">{{ o.label }}</option>
      </select>
      <p class="text-xs text-gray-400 mt-1">{{ t('create.sizes.hint') }}</p>
    </div>
    <div class="grid grid-cols-3 gap-3">
      <div>
        <label class="label">{{ t('create.fields.cpu') }}</label>
        <div class="relative">
          <input :value="cpu" type="number" class="input font-mono pr-7" min="1" placeholder="250" @input="$emit('update:cpu', +$event.target.value)" />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 font-mono text-sm pointer-events-none">m</span>
        </div>
      </div>
      <div>
        <label class="label">{{ t('create.fields.memoryMb') }}</label>
        <input :value="memoryMb" type="number" class="input" min="64" step="64" @input="$emit('update:memoryMb', +$event.target.value)" />
      </div>
      <div>
        <label class="label">{{ t('create.fields.timeoutS') }}</label>
        <input :value="timeoutSeconds" type="number" class="input" min="1" :max="maxTimeout || undefined" @input="$emit('update:timeoutSeconds', +$event.target.value)" />
        <p v-if="maxTimeout && timeoutSeconds > maxTimeout" class="text-xs text-red-400 mt-1">{{ t('create.validation.timeoutMax', { max: maxTimeout }) }}</p>
      </div>
    </div>
    <div v-if="serviceType === 'caas'" class="mt-3">
      <label class="label">{{ t('create.fields.pullPolicy') }}</label>
      <div class="flex rounded-md overflow-hidden border border-gray-700">
        <button
          v-for="p in pullPolicyOptions"
          :key="p.value"
          type="button"
          class="flex-1 px-3 py-2 text-sm font-mono transition-colors"
          :class="imagePullPolicy === p.value ? 'text-white' : 'bg-gray-800 text-gray-400 hover:text-brand-500'"
          :style="imagePullPolicy === p.value ? 'background:rgb(var(--brand-500))' : ''"
          @click="$emit('update:imagePullPolicy', p.value)"
        >{{ p.label }}</button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
