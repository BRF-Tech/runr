<script setup>
// Product wordmark, following the white-label branding:
//  - a custom logo (light theme → light logo, dark surfaces → dark logo) is shown as the image;
//  - a custom product name is shown as text;
//  - RunR's own brand: "Run" + "R" (R in the brand gradient) and the "serverless" tagline.
import { computed } from 'vue'
import { useTheme } from '@/composables/useTheme'
import { useBrandingStore } from '@/modules/white-label/stores/branding'

const props = defineProps({
  size:       { type: String, default: 'md' },
  forceWhite: { type: Boolean, default: false },
  // false: never the custom logo (the caller shows it itself)
  showLogo:   { type: Boolean, default: true },
})

const { current: theme } = useTheme()
const branding = useBrandingStore()

const onDark = computed(() => props.forceWhite || theme.value !== 'light')
const logo = computed(() => (props.showLogo ? branding.logoFor(onDark.value ? 'dark' : 'light') : null))
const textColor = computed(() => (onDark.value ? 'text-white' : 'text-black'))
const logoHeight = computed(() => (props.size === 'lg' ? 'h-14' : props.size === 'sm' ? 'h-10' : 'h-12'))
</script>

<template>
  <img v-if="logo" :src="logo" :alt="branding.productName" class="object-contain object-left max-w-[16rem]" :class="logoHeight" data-test="wordmark-logo" />

  <span v-else-if="!branding.isDefaultName"
    class="font-extrabold tracking-tight leading-none select-none truncate max-w-[20rem]"
    :class="[size === 'lg' ? 'text-5xl' : size === 'sm' ? 'text-2xl' : 'text-3xl', textColor]"
    data-test="wordmark-name">{{ branding.productName }}</span>

  <div v-else class="flex flex-col items-start leading-none select-none justify-center"
    :class="size === 'lg' ? 'gap-1.5 h-14' : size === 'sm' ? 'gap-1 h-10' : 'gap-1'"
    data-test="wordmark-runr"
  >
    <span
      class="font-extrabold tracking-tight"
      :class="[size === 'lg' ? 'text-5xl' : size === 'sm' ? 'text-2xl' : 'text-3xl', textColor]"
    >Run<span class="runr-r">R</span></span>
    <span
      class="font-medium tracking-[0.18em] uppercase"
      :class="[size === 'lg' ? 'text-sm' : 'text-xs', textColor]"
    >server<span style="color:rgb(var(--brand-500))">less</span></span>
  </div>
</template>

<style scoped>
.runr-r {
  background: linear-gradient(135deg, rgb(var(--brand-400)), rgb(var(--brand-accent)));
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
</style>
