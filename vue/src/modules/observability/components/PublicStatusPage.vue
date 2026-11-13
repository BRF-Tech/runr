<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { CheckCircle2, AlertTriangle, XCircle, HelpCircle } from '@lucide/vue'
import { observabilityApi } from '../api/observability'
import { formatPercent, formatSeconds } from '../utils/format'
import UptimeBars from './UptimeBars.vue'
import { useTheme } from '@/composables/useTheme'
import { applyBranding } from '@/modules/white-label/utils/branding'
import { useBrandingStore } from '@/modules/white-label/stores/branding'

/**
 * Public status page (no sign-in): /status/<slug>, or /status on the page's custom domain.
 * Shows the overall status, each component's daily uptime and the incident history, in the
 * organization's branding when it has one: its brand scale, both themes' tones and its default
 * theme (dark_color / dark_scale, light_color / light_scale, default_theme), applied to the
 * document while the page shows; leaving it puts the console's own branding back.
 */
const { t } = useI18n()
const route = useRoute()

const page = ref(null)
const notFound = ref(false)
const loading = ref(true)

const overall = computed(() => page.value?.status ?? 'unknown')
// The page's brand scale is applied to --brand-*: its 500 is the brand colour (palette or primary)
const brandColor = 'rgb(var(--brand-500))'
const { current: theme } = useTheme()
const consoleBranding = useBrandingStore()
let ownBranding = false
// The page's own logo, else the organization's logo for the theme shown
const logo = computed(() => {
  const b = page.value?.branding ?? {}
  return page.value?.logo_url || (theme.value === 'light'
    ? (b.logo_light_url || b.logo_dark_url)
    : (b.logo_dark_url || b.logo_light_url)) || null
})

const ICONS = { operational: CheckCircle2, partial_outage: AlertTriangle, major_outage: XCircle, unknown: HelpCircle, outage: XCircle }
const COLORS = { operational: 'text-emerald-400', partial_outage: 'text-yellow-400', major_outage: 'text-red-400', outage: 'text-red-400', unknown: 'text-gray-400' }

async function load() {
  loading.value = true
  try {
    const slug = route.params.slug
    const { data } = slug ? await observabilityApi.publicStatusPage(slug) : await observabilityApi.publicStatusByDomain(window.location.hostname)
    page.value = data.data
    if (page.value?.branding) {
      applyBranding(page.value.branding)
      ownBranding = true
    }
    document.title = page.value.title
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => { if (ownBranding) consoleBranding.apply() })
</script>

<template>
  <div class="min-h-screen bg-gray-950 text-gray-100" data-test="public-status-page">
    <div class="max-w-3xl mx-auto px-6 py-10 space-y-8">
      <div v-if="loading" class="flex justify-center py-20"><div class="animate-spin h-6 w-6 border-2 border-brand-500 border-t-transparent rounded-full" /></div>

      <div v-else-if="notFound" class="text-center py-20 text-gray-400" data-test="status-not-found">{{ t('observability.statusPublic.notFound') }}</div>

      <template v-else-if="page">
        <header class="flex items-center gap-4">
          <img v-if="logo" :src="logo" alt="" class="h-9" data-test="status-logo">
          <div>
            <h1 class="text-2xl font-semibold" data-test="status-title">{{ page.title }}</h1>
            <p v-if="page.description" class="text-sm text-gray-400 mt-1">{{ page.description }}</p>
          </div>
        </header>

        <div class="flex items-center gap-3 rounded-lg border px-5 py-4" :class="overall === 'operational' ? 'border-emerald-800/60 bg-emerald-900/20' : overall === 'unknown' ? 'border-gray-800 bg-gray-900' : 'border-red-800/60 bg-red-900/20'" data-test="status-overall" :data-status="overall">
          <component :is="ICONS[overall]" class="w-6 h-6" :class="COLORS[overall]" />
          <span class="text-lg font-medium">{{ t('observability.statusPublic.overall.' + overall) }}</span>
        </div>

        <section class="space-y-4">
          <h2 class="text-sm uppercase tracking-wider text-gray-400">{{ t('observability.statusPublic.components') }}</h2>
          <div v-for="c in page.components" :key="c.id" class="space-y-2 border-b border-gray-800/60 pb-4" :data-component="c.name">
            <div class="flex items-center gap-3">
              <component :is="ICONS[c.status]" class="w-4 h-4" :class="COLORS[c.status]" />
              <span class="font-medium">{{ c.name }}</span>
              <span class="ml-auto text-xs text-gray-400">{{ t('observability.statusPublic.uptime', { value: formatPercent(c.uptime_90d) }) }}</span>
            </div>
            <UptimeBars :days="c.days" />
            <div class="flex justify-between text-[10px] text-gray-500">
              <span>{{ t('observability.statusPublic.daysAgo', { n: c.days.length }) }}</span>
              <span>{{ t('observability.statusPublic.today') }}</span>
            </div>
          </div>
        </section>

        <section class="space-y-3" data-test="status-incidents">
          <h2 class="text-sm uppercase tracking-wider text-gray-400">{{ t('observability.statusPublic.incidents') }}</h2>
          <p v-if="!page.incidents.length" class="text-sm text-gray-500">{{ t('observability.statusPublic.noIncidents') }}</p>
          <article v-for="inc in page.incidents" :key="inc.id" class="border-l-2 pl-4 py-1" :class="inc.status === 'open' ? 'border-red-500' : 'border-gray-700'" :data-incident="inc.id">
            <div class="flex flex-wrap items-center gap-3">
              <span class="font-medium">{{ inc.title }}</span>
              <span v-if="inc.status === 'open'" class="text-[11px] px-1.5 rounded-sm bg-red-900/40 text-red-300">{{ t('observability.statusPublic.ongoing') }}</span>
              <span v-else class="text-[11px] text-gray-500">{{ t('observability.statusPublic.resolvedAfter', { duration: formatSeconds(inc.duration_seconds) }) }}</span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">{{ inc.component }} · {{ new Date(inc.started_at).toLocaleString() }}</p>
            <p v-if="inc.message" class="text-sm text-gray-300 mt-1">{{ inc.message }}</p>
          </article>
        </section>

        <footer class="text-[11px] text-gray-500 flex justify-between">
          <span>{{ t('observability.statusPublic.updated', { time: new Date(page.generated_at).toLocaleString() }) }}</span>
          <span v-if="page.branding?.powered_by !== false" :style="{ color: brandColor }">{{ t('observability.statusPublic.poweredBy', { product: page.branding?.product_name || 'RunR' }) }}</span>
        </footer>
      </template>
    </div>
  </div>
</template>
