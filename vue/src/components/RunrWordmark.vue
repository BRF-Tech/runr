<script setup>
// Product wordmark, following the white-label branding:
//  - a custom logo (light theme → light logo, dark surfaces → dark logo) is shown as the image;
//  - a custom product name is shown as text;
//  - RunR's own brand: the same wordmark as runr.sh ("Run" + "R" in the RunR gradient, the
//    "server" + "less" tagline under it), as an SVG for dark or light surfaces.
import { computed } from 'vue'
import { useTheme } from '@/composables/useTheme'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import runrWordmarkDarkUrl from '@/assets/runr-wordmark-dark.svg'
import runrWordmarkLightUrl from '@/assets/runr-wordmark-light.svg'

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
// runr.sh shows its wordmark 30 px high next to the 32 px icon; the console's header does the same
const runrHeight = computed(() => (props.size === 'lg' ? 'h-16' : props.size === 'sm' ? 'h-[30px]' : 'h-10'))
const runrWordmarkUrl = computed(() => (onDark.value ? runrWordmarkDarkUrl : runrWordmarkLightUrl))
</script>

<template>
  <img v-if="logo" :src="logo" :alt="branding.productName" class="object-contain object-left max-w-[16rem]" :class="logoHeight" data-test="wordmark-logo" />

  <span v-else-if="!branding.isDefaultName"
    class="font-extrabold tracking-tight leading-none select-none truncate max-w-[20rem]"
    :class="[size === 'lg' ? 'text-5xl' : size === 'sm' ? 'text-2xl' : 'text-3xl', textColor]"
    data-test="wordmark-name">{{ branding.productName }}</span>

  <img v-else :src="runrWordmarkUrl" alt="RunR serverless"
    class="w-auto select-none" :class="runrHeight" draggable="false"
    data-test="wordmark-runr" />
</template>
