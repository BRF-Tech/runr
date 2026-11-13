<script setup>
// One theme's tones in the branding editor (BrandingForm): the base colour (the page background,
// shade 950; the other shades are derived from it), the scale --gray-50 … --gray-950 with a
// swatch per shade to set it by hand, and a preview of the theme with the brand colours and the
// contrast of its text. v-model:color — "#rrggbb" | null (null: the default); v-model:scale —
// { shade: "#rrggbb" | "" } | null (null: every shade derived).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RotateCcw, TriangleAlert } from '@lucide/vue'
import { SURFACE_SHADES, contrastRatio, editorSurfaceScale, isHexColor, normalizeHex, readableTextOn } from '../utils/color'

const props = defineProps({
  /** "dark" | "light" */
  theme:       { type: String, required: true, validator: v => ['dark', 'light'].includes(v) },
  color:       { type: String, default: null },
  scale:       { type: Object, default: null },
  /** The base colour used while the field is empty (the default: Helm value / platform branding) */
  fallback:    { type: String, required: true },
  /** brand-300 … brand-900 as "#rrggbb", for the preview */
  brand:       { type: Object, required: true },
  error:       { type: String, default: null },
})
const emit = defineEmits(['update:color', 'update:scale'])

const { t } = useI18n()

const field = computed(() => `${props.theme}_color`)
const base = computed(() => (props.color && isHexColor(props.color) ? normalizeHex(props.color) : null) ?? normalizeHex(props.fallback))

/** shade → "#rrggbb" actually used (override, else derived from the base colour). */
const effective = computed(() => editorSurfaceScale(props.scale, base.value, props.theme))

function setShade(shade, value) {
  const next = props.scale ? { ...props.scale } : Object.fromEntries(SURFACE_SHADES.map(s => [s, '']))
  next[shade] = value
  emit('update:scale', Object.values(next).some(Boolean) ? next : null)
}

// Text on the page and the muted text on a card: what the console's contrast rests on
const contrasts = computed(() => [
  { key: 'text', ratio: contrastRatio(effective.value['100'], effective.value['950']) },
  { key: 'muted', ratio: contrastRatio(effective.value['400'], effective.value['900']) },
])
const weak = computed(() => contrasts.value.some(c => c.ratio < 4.5))
</script>

<template>
  <div class="space-y-3" :data-test="`theme-tones-${theme}`">
    <div class="flex items-center justify-between">
      <label class="label mb-0" :for="`branding-${field}`">{{ t(`whiteLabel.branding.field_${field}`) }}</label>
      <button v-if="color !== null" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
        :data-test="`reset-${field}`" @click="emit('update:color', null)">
        <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.reset') }}
      </button>
    </div>
    <div class="flex items-center gap-2">
      <input type="color" class="h-9 w-12 rounded-sm border border-gray-700 bg-gray-800 cursor-pointer"
        :value="base" :data-test="`picker-${field}`" @input="emit('update:color', $event.target.value)" />
      <input :id="`branding-${field}`" :value="color ?? ''" class="input font-mono" maxlength="7" :placeholder="fallback"
        :data-test="`field-${field}`" @input="emit('update:color', $event.target.value || null)" />
    </div>
    <p v-if="color && !isHexColor(color)" class="text-xs text-red-400">{{ t('whiteLabel.branding.invalidColor') }}</p>
    <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>
    <p class="text-xs text-gray-400">{{ t('whiteLabel.branding.toneBaseHint') }}</p>

    <!-- The scale -->
    <div>
      <div class="flex items-center justify-between mb-2">
        <span class="label mb-0">{{ t('whiteLabel.branding.toneScale') }}</span>
        <button v-if="scale" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
          :data-test="`reset-${theme}_scale`" @click="emit('update:scale', null)">
          <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.scaleReset') }}
        </button>
      </div>
      <div class="grid grid-cols-6 sm:grid-cols-11 gap-1.5" :data-test="`${theme}-scale`">
        <div v-for="shade in SURFACE_SHADES" :key="shade" class="flex flex-col items-center gap-1">
          <label class="relative w-full h-9 rounded-md border cursor-pointer"
            :class="scale?.[shade] ? 'border-brand-500' : 'border-gray-700'"
            :style="{ background: effective[shade] }" :title="effective[shade]" :data-test="`${theme}-swatch-${shade}`">
            <input type="color" class="absolute inset-0 opacity-0 cursor-pointer" :value="effective[shade]"
              :aria-label="`${t(`whiteLabel.branding.field_${field}`)} ${shade}`"
              :data-test="`${theme}-shade-${shade}`" @input="setShade(shade, $event.target.value)" />
          </label>
          <span class="text-[10px] text-gray-400 font-mono">{{ shade }}</span>
          <button v-if="scale?.[shade]" type="button" class="text-[10px] text-gray-500 hover:text-gray-100"
            :data-test="`${theme}-reset-shade-${shade}`" @click="setShade(shade, '')">{{ t('whiteLabel.branding.reset') }}</button>
        </div>
      </div>
      <p class="text-xs text-gray-400 mt-2">{{ t('whiteLabel.branding.toneScaleHint') }}</p>
    </div>

    <!-- Preview of the theme: page, card, texts, the brand's button, link and badge, a warning -->
    <div class="rounded-lg overflow-hidden border border-gray-700" :data-test="`${theme}-preview`"
      :style="{ background: effective['950'], color: effective['100'] }">
      <div class="px-3 py-2 text-xs font-semibold flex items-center justify-between"
        :style="{ background: effective['900'], borderBottom: `1px solid ${effective['800']}` }">
        <span>{{ t(`whiteLabel.branding.preview_${theme}`) }}</span>
        <span class="font-normal" :style="{ color: effective['400'] }">{{ t('whiteLabel.branding.previewMuted') }}</span>
      </div>
      <div class="p-3">
        <div class="rounded-md p-3 space-y-2" :style="{ background: effective['900'], border: `1px solid ${effective['700']}` }">
          <p class="text-sm font-semibold">{{ t('whiteLabel.branding.previewCard') }}</p>
          <p class="text-xs" :style="{ color: effective['400'] }">{{ t('whiteLabel.branding.previewCardText') }}</p>
          <div class="h-7 rounded-sm" :style="{ background: effective['800'], border: `1px solid ${effective['700']}` }" />
          <div class="flex flex-wrap items-center gap-2 pt-1">
            <span class="px-2.5 py-1 rounded-md text-xs font-semibold"
              :style="{ background: brand['600'], color: readableTextOn(brand['600']) }">{{ t('whiteLabel.branding.previewButton') }}</span>
            <span class="text-xs font-semibold" :style="{ color: theme === 'light' ? brand['700'] : brand['400'] }">{{ t('whiteLabel.branding.previewLink') }}</span>
            <span class="px-1.5 py-0.5 rounded-sm text-[10px] border"
              :style="theme === 'light'
                ? { background: `${brand['300']}26`, color: brand['700'], borderColor: `${brand['300']}99` }
                : { background: `${brand['900']}66`, color: brand['300'], borderColor: `${brand['800']}99` }">FaaS</span>
          </div>
        </div>
      </div>
    </div>
    <p class="text-xs flex items-center gap-1.5" :class="weak ? 'text-yellow-300' : 'text-gray-400'" :data-test="`${theme}-contrast`">
      <TriangleAlert v-if="weak" class="w-3.5 h-3.5 shrink-0" />
      <span v-for="c in contrasts" :key="c.key">{{ t(`whiteLabel.branding.contrast_${c.key}`, { ratio: c.ratio.toFixed(1) }) }}</span>
      <span v-if="weak">— {{ t('whiteLabel.branding.contrastWeak') }}</span>
    </p>
  </div>
</template>
