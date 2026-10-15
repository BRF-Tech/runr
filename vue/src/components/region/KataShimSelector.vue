<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
// RunR installs clh, qemu, dragonball and fc — all four may run side by side
const MAX_SHIMS = 4

const props = defineProps({
  shims:        { type: Object, default: null },
  loading:      { type: Boolean, default: false },
  modelValue:   { type: Array, default: () => ['clh'] },
})

const emit = defineEmits(['update:modelValue'])

const categories = [
  { key: 'general',      labelKey: 'settings.regionDetail.sandbox.shimCategory.general' },
  { key: 'gpu',          labelKey: 'settings.regionDetail.sandbox.shimCategory.gpu' },
  { key: 'confidential', labelKey: 'settings.regionDetail.sandbox.shimCategory.confidential' },
]

const groupedShims = computed(() => {
  if (!props.shims) return []
  return categories
    .map(cat => ({
      ...cat,
      items: Object.entries(props.shims)
        .filter(([, s]) => s.category === cat.key)
        .map(([key, s]) => ({ key, ...s })),
    }))
    .filter(cat => cat.items.length > 0)
})

const isMaxed = computed(() => props.modelValue.length >= MAX_SHIMS)

const reasonKeys = {
  arch_mismatch: 'archMismatch',
  no_kvm:        'noKvm',
  no_devmapper:  'noDevmapper',
  no_gpu:        'noGpu',
  no_iommu:      'noIommu',
  no_snp:        'noSnp',
  no_tdx:        'noTdx',
}

function reasonText(reason) {
  const key = reasonKeys[reason]
  return key ? t(`settings.regionDetail.sandbox.shimReason.${key}`) : reason
}

function isSelected(key) {
  return props.modelValue.includes(key)
}

function canSelect(shim) {
  if (!shim.available) return false
  if (isSelected(shim.key)) return true // can always deselect
  return !isMaxed.value
}

function toggle(shim) {
  if (!shim.available) return
  const selected = [...props.modelValue]

  if (isSelected(shim.key)) {
    // Deselect — but must keep at least 1
    if (selected.length <= 1) return
    emit('update:modelValue', selected.filter(k => k !== shim.key))
  } else {
    // Select — check max
    if (selected.length >= MAX_SHIMS) return
    emit('update:modelValue', [...selected, shim.key])
  }
}

function shimClass(shim) {
  if (!shim.available) {
    return 'border-gray-800/50 bg-gray-900/20 text-gray-400 cursor-not-allowed opacity-50'
  }
  if (isSelected(shim.key)) {
    return 'border-brand-500/60 bg-brand-900/20 text-brand-400 ring-1 ring-brand-500/30'
  }
  if (isMaxed.value) {
    return 'border-gray-800/50 bg-gray-900/30 text-gray-400 cursor-not-allowed opacity-60'
  }
  return 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100 hover:border-gray-700'
}
</script>

<template>
  <div class="space-y-3">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center gap-2 py-2">
      <span class="w-3 h-3 border-2 border-gray-600 border-t-gray-300 rounded-full animate-spin" />
      <span class="text-xs text-gray-400">{{ t('settings.regionDetail.sandbox.shimsLoading') }}</span>
    </div>

    <!-- Shim groups -->
    <template v-else-if="shims">
      <!-- Selection count -->
      <p class="text-[10px] text-gray-400">
        {{ t('settings.regionDetail.sandbox.shimCount', { count: modelValue.length, max: MAX_SHIMS }) }}
      </p>

      <div v-for="group in groupedShims" :key="group.key" class="space-y-1.5">
        <p class="text-[10px] text-gray-400 uppercase tracking-wider font-medium">{{ t(group.labelKey) }}</p>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="shim in group.items" :key="shim.key"
            type="button"
            class="relative rounded-md border px-2.5 py-1.5 text-xs text-left transition-colors"
            :class="shimClass(shim)"
            :disabled="!canSelect(shim)"
            @click="toggle(shim)"
          >
            <span class="font-medium">{{ shim.label }}</span>
            <span v-if="shim.recommended"
              class="ml-1 text-[9px] font-mono px-1 py-0.5 rounded-sm bg-emerald-900/40 text-emerald-400 border border-emerald-800/40">
              {{ t('settings.regionDetail.sandbox.recommended') }}
            </span>
            <span v-if="shim.runtime === 'rs'"
              class="ml-1 text-[9px] font-mono px-1 py-0.5 rounded-sm bg-violet-900/30 text-violet-400 border border-violet-800/40">
              Rust
            </span>
            <p v-if="!shim.available && shim.reason" class="text-[10px] mt-0.5 opacity-70">
              {{ reasonText(shim.reason) }}
            </p>
            <p v-else-if="shim.available && shim.setup === 'devmapper'" class="text-[10px] mt-0.5 opacity-70">
              {{ t('settings.regionDetail.sandbox.shimSetup.devmapper') }}
            </p>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
