<script setup>
import { useI18n } from 'vue-i18n'
import { ArrowDownToLine, ArrowUpDown, Minus, Plus } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

const props = defineProps({
  scaleToZero: { type: Boolean, default: true },
  minScale:    { type: Number, default: 0 },
  maxScale:    { type: Number, default: 1 },
})

const emit = defineEmits(['update:scaleToZero', 'update:minScale', 'update:maxScale'])

function selectZero() {
  emit('update:scaleToZero', true)
  emit('update:minScale', 0)
  emit('update:maxScale', 1)
}

function selectCustom() {
  emit('update:scaleToZero', false)
  emit('update:minScale', props.minScale > 0 ? props.minScale : 1)
  emit('update:maxScale', props.maxScale > 1 ? props.maxScale : 5)
}

function adjustMin(delta) {
  const v = Math.max(1, Math.min(10, props.minScale + delta))
  emit('update:minScale', v)
  if (v > props.maxScale) emit('update:maxScale', v)
}

function adjustMax(delta) {
  const v = Math.max(1, Math.min(1000, props.maxScale + delta))
  emit('update:maxScale', v)
  if (v < props.minScale) emit('update:minScale', v)
}
</script>

<template>
  <section>
    <p class="section-title">{{ t('create.sections.scale') }}</p>

    <div class="grid grid-cols-2 gap-3">
      <!-- Zero Scale -->
      <button
        type="button"
        class="flex flex-col items-center gap-2 py-4 px-3 rounded-lg border text-sm font-medium transition-all"
        :class="scaleToZero
          ? 'text-white border-transparent shadow-lg'
          : 'bg-gray-900/60 border-gray-700/60 text-gray-400 hover:border-gray-500 hover:text-gray-100'"
        :style="scaleToZero ? 'background:rgb(16 185 129/0.15);border-color:rgb(16 185 129/0.5);box-shadow:0 0 12px rgb(16 185 129/0.12)' : ''"
        @click="selectZero"
      >
        <div
          class="w-10 h-10 rounded-lg flex items-center justify-center"
          :class="scaleToZero ? 'bg-emerald-500/20' : 'bg-gray-800'"
        >
          <ArrowDownToLine class="w-5 h-5" :class="scaleToZero ? 'text-emerald-400' : 'text-gray-400'" />
        </div>
        <span class="font-mono text-xs">{{ t('create.fields.scaleZero') }}</span>
        <span class="text-[11px] leading-snug opacity-60 text-center">{{ t('create.fields.scaleZeroDesc') }}</span>
      </button>

      <!-- Custom Scale -->
      <button
        type="button"
        class="flex flex-col items-center gap-2 py-4 px-3 rounded-lg border text-sm font-medium transition-all"
        :class="!scaleToZero
          ? 'text-white border-transparent shadow-lg'
          : 'bg-gray-900/60 border-gray-700/60 text-gray-400 hover:border-gray-500 hover:text-gray-100'"
        :style="!scaleToZero ? 'background:rgb(var(--brand-600)/0.15);border-color:rgb(var(--brand-500)/0.5);box-shadow:0 0 12px rgb(var(--brand-600)/0.12)' : ''"
        @click="selectCustom"
      >
        <div
          class="w-10 h-10 rounded-lg flex items-center justify-center"
          :class="!scaleToZero ? 'bg-brand-500/20' : 'bg-gray-800'"
        >
          <ArrowUpDown class="w-5 h-5" :class="!scaleToZero ? 'text-brand-400' : 'text-gray-400'" />
        </div>
        <span class="font-mono text-xs">{{ t('create.fields.scaleCustom') }}</span>
        <span class="text-[11px] leading-snug opacity-60 text-center">{{ t('create.fields.scaleCustomDesc') }}</span>
      </button>
    </div>

    <!-- Min / Max inputs (Custom Scale only) -->
    <div v-if="!scaleToZero" class="grid grid-cols-2 gap-3 mt-4">
      <div>
        <label class="label"><TermText path="create.fields.minScale" /></label>
        <div class="flex items-center gap-1">
          <button
            type="button"
            class="w-8 h-8 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
            @click="adjustMin(-1)"
          ><Minus class="w-3.5 h-3.5" /></button>
          <input
            :value="minScale"
            type="number"
            class="input font-mono text-center flex-1"
            min="1"
            max="10"
            required
            @input="$emit('update:minScale', +$event.target.value)"
          />
          <button
            type="button"
            class="w-8 h-8 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
            @click="adjustMin(1)"
          ><Plus class="w-3.5 h-3.5" /></button>
        </div>
      </div>
      <div>
        <label class="label">{{ t('create.fields.maxScale') }}</label>
        <div class="flex items-center gap-1">
          <button
            type="button"
            class="w-8 h-8 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
            @click="adjustMax(-1)"
          ><Minus class="w-3.5 h-3.5" /></button>
          <input
            :value="maxScale"
            type="number"
            class="input font-mono text-center flex-1"
            min="1"
            max="1000"
            required
            @input="$emit('update:maxScale', +$event.target.value)"
          />
          <button
            type="button"
            class="w-8 h-8 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
            @click="adjustMax(1)"
          ><Plus class="w-3.5 h-3.5" /></button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
