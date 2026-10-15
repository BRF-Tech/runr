<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  regions: { type: Array, required: true },
  selectedRegions: { type: Array, required: true },
  open: { type: Boolean, default: false },
})

defineEmits(['toggle', 'clear', 'update:open'])
</script>

<template>
  <div v-if="regions.length > 1" class="relative" data-region-dropdown>
    <button
      class="h-9 flex items-center gap-1.5 px-3 rounded-md text-xs font-mono border transition-colors"
      :class="selectedRegions.length > 0
        ? 'border-transparent'
        : 'bg-gray-900 border-gray-800 text-gray-400 hover:text-gray-100'"
      :style="selectedRegions.length > 0 ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
      @click.stop="$emit('update:open', !open)"
    >
      {{ t('projects.regionFilter') }}
      <span v-if="selectedRegions.length > 0" class="text-white rounded-md w-4 h-4 flex items-center justify-center text-[10px] leading-none" style="background-color:rgb(var(--brand-600))">
        {{ selectedRegions.length }}
      </span>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
      </svg>
    </button>
    <div
      v-if="open"
      class="absolute right-0 top-full mt-1 z-20 bg-gray-900 border border-gray-800 rounded-md py-1 shadow-xl min-w-[180px]"
    >
      <label
        v-for="r in regions"
        :key="r.id"
        class="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-800/60 cursor-pointer text-xs transition-colors"
        @click.stop
      >
        <input
          type="checkbox"
          :checked="selectedRegions.includes(r.id)"
          class="rounded-sm border-gray-700 bg-gray-800 focus:ring-2 h-3.5 w-3.5"
          @change="$emit('toggle', r.id)"
        />
        <span class="font-mono" :class="selectedRegions.includes(r.id) ? 'text-gray-100' : 'text-gray-400'">{{ r.name }}</span>
        <span
          v-if="r.is_demo"
          class="text-[9px] px-1 py-0.5 rounded-sm leading-none" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))"
        >demo</span>
      </label>
      <button
        v-if="selectedRegions.length > 0"
        class="w-full text-left px-3 py-1.5 text-[11px] text-gray-400 hover:text-gray-400 border-t border-gray-800 mt-1 pt-1.5 transition-colors"
        @click.stop="$emit('clear')"
      >{{ t('projects.regionAll') }}</button>
    </div>
  </div>
</template>
