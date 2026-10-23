<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldAlert, Waypoints } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useSettingsStore } from '@/stores/settings'
import { apiProductApi } from '../api/apiProduct'
import GatewayRoutes from './GatewayRoutes.vue'
import ApiConsumers from './ApiConsumers.vue'
import ApiPlans from './ApiPlans.vue'
import ApiUsage from './ApiUsage.vue'
import GatewayDomain from './GatewayDomain.vue'

/**
 * Build & Run → API gateways (docs/api-product.md): one gateway per region; its routes and their
 * authentication, consumers with their keys, plans, usage, the custom domain and developer page.
 */
const { t } = useI18n()
const toast = useToast()

const gateways = ref([])
const selectedId = ref(null)
const loading = ref(true)
const section = ref('routes')
const sections = ['routes', 'consumers', 'plans', 'usage', 'domain']

const gateway = computed(() => gateways.value.find((g) => g.id === selectedId.value) ?? null)

async function load() {
  loading.value = true
  try {
    const { data } = await apiProductApi.gateways()
    gateways.value = data.data ?? []
    if (!gateway.value) selectedId.value = gateways.value[0]?.id ?? null
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    loading.value = false
  }
}

/** A child changed the gateway (route, domain, page): keep the list current. */
function replace(updated) {
  if (!updated?.id) return
  const i = gateways.value.findIndex((g) => g.id === updated.id)
  if (i >= 0) gateways.value.splice(i, 1, { ...gateways.value[i], ...updated })
}

const regionOf = (g) => g.region?.name ?? g.knative_region_id

// A gateway of one's own in another region, e.g. for an environment that runs there
// (docs/environments.md#gateway-routes)
const settings = useSettingsStore()
const newRegion = ref('')
const creating = ref(false)
const freeRegions = computed(() => (settings.data.regions ?? [])
  .filter((r) => !r.is_demo && !gateways.value.some((g) => g.knative_region_id === r.id)))

async function createGateway() {
  if (!newRegion.value) return
  creating.value = true
  try {
    const { data } = await apiProductApi.createGateway(newRegion.value)
    await load()
    selectedId.value = data.data?.id ?? selectedId.value
    toast.success(t('apiProduct.newGateway.created', { region: data.data?.region?.name ?? newRegion.value }))
    newRegion.value = ''
  } catch (e) {
    toast.error(e.response?.data?.errors?.knative_region_id?.[0] ?? e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    creating.value = false
  }
}

onMounted(() => {
  load()
  if (!settings.loaded) settings.fetch()
})
</script>

<template>
  <div class="space-y-5" data-test="api-gateways">
    <div>
      <div class="flex items-center gap-2">
        <Waypoints class="w-4 h-4 text-sky-400" />
        <h2 class="text-[15px] font-semibold text-gray-100">{{ t('apiProduct.title') }}</h2>
      </div>
      <p class="text-xs text-gray-400 mt-1.5">{{ t('apiProduct.intro') }}</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>

    <p v-else-if="!gateways.length" class="text-sm text-gray-400" data-test="api-gateways-empty">{{ t('apiProduct.empty') }}</p>

    <form v-if="!loading && freeRegions.length" class="flex flex-wrap items-end gap-2" data-test="api-gateway-new" @submit.prevent="createGateway">
      <div>
        <label class="label">{{ t('apiProduct.newGateway.label') }}</label>
        <select v-model="newRegion" class="input text-xs" data-test="api-gateway-new-region">
          <option value="" disabled>{{ t('apiProduct.newGateway.region') }}</option>
          <option v-for="r in freeRegions" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
      </div>
      <button type="submit" class="btn-secondary text-xs px-3 py-1.5" :disabled="!newRegion || creating" data-test="api-gateway-new-create">
        {{ t('apiProduct.newGateway.create') }}
      </button>
    </form>

    <template v-if="!loading && gateways.length">
      <div class="flex flex-wrap gap-2" data-test="api-gateway-picker">
        <button
          v-for="g in gateways"
          :key="g.id"
          type="button"
          class="px-3 py-1.5 rounded-md border text-xs text-left"
          :class="g.id === selectedId ? 'border-sky-500/60 bg-sky-500/10 text-sky-200' : 'border-gray-800 bg-gray-900 text-gray-300 hover:border-gray-700'"
          :data-gateway="g.id"
          @click="selectedId = g.id"
        >
          <span class="font-medium">{{ regionOf(g) }}</span>
          <span class="block font-mono text-[11px] text-gray-500">{{ g.custom_domain || g.host }}</span>
        </button>
      </div>

      <!-- fail closed: while gateway-auth is not ready its key and JWT routes answer 503 -->
      <div
        v-if="gateway && gateway.status !== 'active'"
        class="flex items-start gap-2 rounded-md border px-3 py-2 text-xs"
        :class="gateway.status === 'pending' ? 'border-amber-700/50 bg-amber-900/10 text-amber-200' : 'border-red-800/60 bg-red-900/10 text-red-200'"
        role="status"
        data-test="api-gateway-status"
      >
        <ShieldAlert class="w-4 h-4 mt-0.5 shrink-0" />
        <div>
          <p class="font-medium">{{ t(gateway.status === 'pending' ? 'apiProduct.status.pending' : 'apiProduct.status.error') }}</p>
          <p v-if="gateway.status_message" class="mt-0.5 opacity-90" data-test="api-gateway-status-message">{{ gateway.status_message }}</p>
        </div>
      </div>

      <nav class="flex gap-1 border-b border-gray-800" data-test="api-sections">
        <button
          v-for="s in sections"
          :key="s"
          type="button"
          class="px-3 py-2 text-xs -mb-px border-b-2"
          :class="section === s ? 'border-sky-400 text-sky-200' : 'border-transparent text-gray-400 hover:text-gray-200'"
          :data-section="s"
          @click="section = s"
        >{{ t(`apiProduct.sections.${s}`) }}</button>
      </nav>

      <GatewayRoutes v-if="gateway && section === 'routes'" :gateway="gateway" @updated="replace" />
      <ApiConsumers v-else-if="gateway && section === 'consumers'" :gateway="gateway" />
      <ApiPlans v-else-if="gateway && section === 'plans'" :gateway="gateway" />
      <ApiUsage v-else-if="gateway && section === 'usage'" :gateway="gateway" />
      <GatewayDomain v-else-if="gateway && section === 'domain'" :gateway="gateway" @updated="replace" />
    </template>
  </div>
</template>
