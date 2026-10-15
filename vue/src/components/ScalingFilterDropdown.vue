<script setup>
import { useI18n } from 'vue-i18n'
import { ArrowDownToLine, ArrowUpDown } from 'lucide-vue-next'

const { t } = useI18n()

defineProps({
  selectedScaling: { type: Array, required: true },
  open: { type: Boolean, default: false },
})

defineEmits(['toggle', 'clear', 'update:open'])

const options = [
  { key: 's2z',    icon: ArrowDownToLine, color: '#10b981', selectedClass: 'border-emerald-600/50 bg-emerald-950/30', idleClass: 'border-gray-800 bg-gray-900/40 hover:border-gray-700' },
  { key: 'custom', icon: ArrowUpDown,     color: '#60a5fa', selectedClass: 'border-sky-600/50 bg-sky-950/30',        idleClass: 'border-gray-800 bg-gray-900/40 hover:border-gray-700' },
]
</script>

<template>
  <div class="relative" data-scaling-dropdown>
    <button
      class="h-9 flex items-center gap-1.5 px-3 rounded-md text-xs font-mono border transition-colors"
      :class="selectedScaling.length > 0
        ? 'text-white border-transparent'
        : 'bg-gray-900 border-gray-800 text-gray-400 hover:text-gray-100'"
      :style="selectedScaling.length > 0 ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
      @click.stop="$emit('update:open', !open)"
    >
      {{ t('projects.scalingFilter') }}
      <span v-if="selectedScaling.length > 0" class="text-white rounded-md w-4 h-4 flex items-center justify-center text-[10px] leading-none" style="background-color:rgb(var(--brand-600))">
        {{ selectedScaling.length }}
      </span>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
      </svg>
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-full mt-1.5 z-20 bg-gray-950 border border-gray-800 rounded-lg shadow-2xl"
      style="min-width: 320px"
    >
      <div class="grid grid-cols-2 gap-2 p-3">
        <button
          v-for="opt in options"
          :key="opt.key"
          class="flex items-center gap-2 rounded-lg border p-2.5 transition-all cursor-pointer"
          :class="selectedScaling.includes(opt.key) ? opt.selectedClass : opt.idleClass"
          @click.stop="$emit('toggle', opt.key)"
        >
          <component
            :is="opt.icon"
            class="w-5 h-5 shrink-0"
            :style="selectedScaling.includes(opt.key) ? `color:${opt.color}` : ''"
            :class="!selectedScaling.includes(opt.key) && 'text-gray-400'"
          />
          <span
            class="text-sm font-semibold"
            :class="selectedScaling.includes(opt.key) ? 'text-gray-100' : 'text-gray-400'"
          >{{ t(`projects.scaling${opt.key === 's2z' ? 'S2z' : 'Custom'}`) }}</span>
        </button>
      </div>

      <button
        v-if="selectedScaling.length > 0"
        class="w-full text-center px-3 py-2 text-[11px] text-gray-400 hover:text-gray-100 border-t border-gray-800/60 transition-colors"
        @click.stop="$emit('clear')"
      >{{ t('projects.scalingAll') }}</button>
    </div>
  </div>
</template>
