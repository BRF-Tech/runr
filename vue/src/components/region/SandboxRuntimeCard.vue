<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  runtime:     { type: String, required: true },
  status:      { type: String, default: 'not_installed' },
  message:     { type: String, default: '' },
  installing:  { type: Boolean, default: false },
  // the runtime cannot run on this cluster (Kata without /dev/kvm): no Install button
  unavailable: { type: Boolean, default: false },
  statusLabel: { type: String, default: '' },
  hypervisor:  { type: [Array, String], default: null },
})

defineEmits(['install', 'uninstall'])

const statusColors = {
  not_installed: 'text-gray-400',
  installing:    'text-yellow-400',
  uninstalling:  'text-yellow-400',
  ready:         'text-emerald-400',
  error:         'text-red-400',
}

const statusDots = {
  not_installed: 'bg-gray-500',
  installing:    'bg-yellow-400 animate-pulse',
  uninstalling:  'bg-yellow-400 animate-pulse',
  ready:         'bg-emerald-400',
  error:         'bg-red-400',
}

// A failed addition (e.g. Firecracker) to a working Kata install: still ready, the log ends with
// "Kata stays installed with …; fc not added" after the ERROR line — shown, not hidden in the log
const notAdded = computed(() => {
  if (props.status !== 'ready' || !props.message) return null
  const lines = props.message.trim().split('\n')
  const last = lines[lines.length - 1] || ''
  if (!last.includes('not added')) return null
  const error = [...lines].reverse().find(l => l.includes('ERROR:'))
  return [error, last].filter(Boolean).map(l => l.replace(/^\[\d{2}:\d{2}:\d{2}\]\s*/, '')).join('\n')
})
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <div>
        <h4 class="text-sm font-medium text-gray-100">
          {{ t(`settings.regionDetail.sandbox.${runtime}.title`) }}
        </h4>
        <p class="text-xs text-gray-400 mt-0.5">
          {{ t(`settings.regionDetail.sandbox.${runtime}.description`) }}
        </p>
      </div>
      <div class="flex items-center gap-2">
        <!-- Show hypervisor badges when Kata is installed -->
        <template v-if="runtime === 'kata' && hypervisor && status === 'ready'">
          <span v-for="hv in (Array.isArray(hypervisor) ? hypervisor : [hypervisor])" :key="hv"
            class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-gray-800 border border-gray-700 text-gray-400">
            {{ hv }}
          </span>
        </template>
        <span :class="statusDots[status]" class="w-2 h-2 rounded-full shrink-0" />
        <span :class="statusColors[status]" class="text-xs font-medium">{{ statusLabel }}</span>
      </div>
    </div>

    <!-- Error: the step log, ending with the error line -->
    <p v-if="status === 'error' && message" class="text-xs text-red-400/80 bg-red-900/20 rounded px-3 py-2 font-mono whitespace-pre-wrap break-words max-h-40 overflow-y-auto">
      {{ message }}
    </p>

    <!-- Install log -->
    <p v-if="['installing', 'uninstalling'].includes(status) && message" class="text-xs text-gray-400 bg-gray-900 rounded px-3 py-2 font-mono whitespace-pre-wrap max-h-24 overflow-y-auto">
      {{ message }}
    </p>

    <!-- Ready, but the last install's additions failed (the working install was kept) -->
    <p v-if="notAdded" class="text-xs text-amber-300/90 bg-amber-900/20 rounded px-3 py-2 font-mono whitespace-pre-wrap break-words">{{ notAdded }}</p>

    <!-- Ready: the install's step log stays available (collapsed) -->
    <details v-if="status === 'ready' && message" class="text-xs text-gray-400">
      <summary class="cursor-pointer select-none hover:text-gray-300">
        {{ t('settings.regionDetail.sandbox.installLog') }}
      </summary>
      <p class="mt-2 bg-gray-900 rounded px-3 py-2 font-mono whitespace-pre-wrap break-words max-h-40 overflow-y-auto">{{ message }}</p>
    </details>

    <!-- Action buttons -->
    <div class="flex gap-2">
      <button v-if="(status === 'not_installed' || status === 'error') && !unavailable"
        class="btn-primary text-xs"
        :disabled="installing"
        @click="$emit('install')">
        {{ t(`settings.regionDetail.sandbox.${runtime}.installBtn`) }}
      </button>
      <button v-if="status === 'ready'"
        class="btn-secondary text-xs !text-red-400 hover:!text-red-300"
        :disabled="installing"
        @click="$emit('uninstall')">
        {{ t(`settings.regionDetail.sandbox.${runtime}.uninstallBtn`) }}
      </button>
    </div>
  </div>
</template>
