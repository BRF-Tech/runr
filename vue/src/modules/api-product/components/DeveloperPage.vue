<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiProductApi } from '../api/apiProduct'
import { curlExample, latency } from '../utils/format'

/**
 * Public developer page of a gateway (/developers/<key>, no sign-in): what the API offers, its
 * routes and how to send a key or a token — and, when the owner shows them, each key / JWT
 * route's success rate and latency of the last days. Consumers get their keys from the API's owner.
 */
const route = useRoute()
const { t } = useI18n()

const page = ref(null)
const missing = ref(false)

onMounted(async () => {
  try {
    const { data } = await apiProductApi.developerPage(String(route.params.key))
    page.value = data.data
  } catch {
    missing.value = true
  }
})
</script>

<template>
  <main class="min-h-screen bg-gray-950 text-gray-200 px-4 py-10" data-test="developer-page">
    <div class="max-w-3xl mx-auto space-y-8">
      <p v-if="missing" class="text-sm text-gray-400" data-test="developer-page-missing">{{ t('apiProduct.developer.missing') }}</p>

      <template v-else-if="page">
        <header class="space-y-2">
          <h1 class="text-2xl font-semibold text-gray-100">{{ page.name }}</h1>
          <p v-if="page.description" class="text-sm text-gray-300 whitespace-pre-line">{{ page.description }}</p>
          <p v-for="u in page.base_urls" :key="u" class="font-mono text-sm text-sky-300">{{ u }}</p>
        </header>

        <section class="space-y-3">
          <h2 class="text-lg text-gray-100">{{ t('apiProduct.developer.routes') }}</h2>
          <div v-for="r in page.routes" :key="r.path" class="bg-gray-900 border border-gray-800 rounded-md p-3 space-y-1" :data-route="r.path">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-mono text-sm text-gray-100">{{ r.path }}</span>
              <span class="text-[11px] text-gray-500">{{ r.methods?.length ? r.methods.join(', ') : t('apiProduct.developer.anyMethod') }}</span>
              <span class="text-[11px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-300">{{ t(`apiProduct.routes.modes.${r.auth}`) }}</span>
            </div>
            <code class="block text-xs font-mono text-gray-400 break-all">{{ curlExample(page.base_urls[0] ?? '', r) }}</code>
            <template v-if="page.metrics_days && r.auth !== 'public'">
              <p v-if="r.health" class="text-[11px] text-gray-400" data-test="route-health">
                {{ t('apiProduct.developer.health', {
                  days: page.metrics_days,
                  rate: `${Number(r.health.success_rate).toLocaleString(undefined, { maximumFractionDigits: 2 })}%`,
                  p50: latency(r.health.latency_p50_ms), p95: latency(r.health.latency_p95_ms), p99: latency(r.health.latency_p99_ms),
                }) }}
              </p>
              <p v-else class="text-[11px] text-gray-500">{{ t('apiProduct.developer.noHealth', { days: page.metrics_days }) }}</p>
            </template>
          </div>
        </section>

        <section class="space-y-2 text-sm text-gray-300">
          <h2 class="text-lg text-gray-100">{{ t('apiProduct.developer.howTo') }}</h2>
          <p>{{ t('apiProduct.developer.key') }}</p>
          <p>{{ t('apiProduct.developer.jwt') }}</p>
          <p>{{ t('apiProduct.developer.limits') }}</p>
          <p class="text-gray-500">{{ t('apiProduct.developer.signup') }}</p>
        </section>
      </template>
    </div>
  </main>
</template>
