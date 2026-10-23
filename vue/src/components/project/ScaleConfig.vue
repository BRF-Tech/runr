<script setup>
import { useI18n } from 'vue-i18n'
import { ArrowDownToLine, ArrowUpDown, Clock, Minus, Plus } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'
import WarmerSchedule from '@/components/project/WarmerSchedule.vue'
import { defaultWarmerRows } from '@/utils/warmSchedule'

const { t } = useI18n()

const props = defineProps({
  scaleToZero: { type: Boolean, default: true },
  minScale:    { type: Number, default: 0 },
  maxScale:    { type: Number, default: 1 },
  // Highest max_scale the API takes (projects 1000, model services 100)
  maxLimit:    { type: Number, default: 1000 },
  // Timed warmer (projects only): config.scale_schedule rows, null = off. Shown with `warmer`.
  warmer:        { type: Boolean, default: false },
  scaleSchedule: { type: Array, default: null },
  // What the warmer's preview needs: the region, the size and the trigger of the project
  regionId:    { type: String, default: null },
  cpu:         { type: [Number, String], default: null },
  memoryMb:    { type: Number, default: null },
  triggerType: { type: String, default: 'http' },
  warmScale:   { type: Object, default: null },
})

const emit = defineEmits(['update:scaleToZero', 'update:minScale', 'update:maxScale', 'update:scaleSchedule'])

// Scale to zero keeps its max: a project with no traffic has no pod, one with traffic may burst
// up to max_scale pods
function selectZero() {
  emit('update:scaleToZero', true)
  emit('update:minScale', 0)
  emit('update:maxScale', props.maxScale >= 1 ? props.maxScale : 1)
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
  const v = Math.max(1, Math.min(props.maxLimit, props.maxScale + delta))
  emit('update:maxScale', v)
  if (v < props.minScale) emit('update:minScale', v)
}

function toggleWarmer() {
  emit('update:scaleSchedule', props.scaleSchedule ? null : defaultWarmerRows(props.maxScale))
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

    <!-- Min (Custom Scale, unless the warmer replaces it) / Max (both modes) -->
    <div class="grid grid-cols-2 gap-3 mt-4">
      <div v-if="!scaleToZero && !scaleSchedule">
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
            data-testid="scale-min"
            @input="$emit('update:minScale', +$event.target.value)"
          />
          <button
            type="button"
            class="w-8 h-8 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
            @click="adjustMin(1)"
          ><Plus class="w-3.5 h-3.5" /></button>
        </div>
      </div>
      <div v-else-if="!scaleToZero" class="self-end">
        <p class="text-[11px] text-gray-400 leading-snug" data-testid="scale-min-replaced">{{ t('warmer.replacesMin') }}</p>
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
            :max="maxLimit"
            required
            data-testid="scale-max"
            @input="$emit('update:maxScale', +$event.target.value)"
          />
          <button
            type="button"
            class="w-8 h-8 rounded-md border border-gray-700 bg-gray-800 flex items-center justify-center text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
            @click="adjustMax(1)"
          ><Plus class="w-3.5 h-3.5" /></button>
        </div>
      </div>
      <p v-if="scaleToZero" class="self-end text-[11px] text-gray-400 leading-snug" data-testid="scale-zero-burst">
        {{ t('create.fields.scaleZeroBurst', { max: maxScale }, maxScale) }}
      </p>
    </div>

    <!-- Advanced: timed warmer (config.scale_schedule) -->
    <div v-if="warmer" class="mt-4 pt-4 border-t border-gray-800/60">
      <div class="flex items-start gap-3">
        <button
          type="button"
          role="switch"
          :aria-checked="!!scaleSchedule"
          :aria-label="t('warmer.toggle')"
          class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors mt-0.5"
          :class="scaleSchedule ? 'bg-brand-500' : 'bg-gray-700'"
          data-testid="warmer-toggle"
          @click="toggleWarmer"
        >
          <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="scaleSchedule ? 'translate-x-5' : 'translate-x-0'" />
        </button>
        <div class="min-w-0">
          <p class="text-sm text-gray-100 flex items-center gap-1.5"><Clock class="w-3.5 h-3.5 text-gray-400" />{{ t('warmer.toggle') }}</p>
          <p class="text-[11px] text-gray-400 leading-snug">{{ t('warmer.toggleDesc') }}</p>
        </div>
      </div>

      <WarmerSchedule
        v-if="scaleSchedule"
        class="mt-3"
        :rows="scaleSchedule"
        :max-scale="maxScale"
        :scale-to-zero="scaleToZero"
        :region-id="regionId"
        :cpu="cpu"
        :memory-mb="memoryMb"
        :trigger-type="triggerType"
        :warm-scale="warmScale"
        @update:rows="$emit('update:scaleSchedule', $event)"
      />
    </div>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
