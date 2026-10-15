<script setup>
// Models: Hugging Face models as one-click model services that functions in any language call
// (online or fully offline), plus the model library, uploads for air-gapped regions and the
// organization's Hugging Face token.
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Search, Boxes, Library, Settings, Sparkles } from 'lucide-vue-next'
import AppHeader from '@/components/AppHeader.vue'
import HubBrowser from '@/components/models/HubBrowser.vue'
import HubModelDrawer from '@/components/models/HubModelDrawer.vue'
import DeployModelDialog from '@/components/models/DeployModelDialog.vue'
import ModelServicesList from '@/components/models/ModelServicesList.vue'
import ModelServiceDetail from '@/components/models/ModelServiceDetail.vue'
import ModelLibrary from '@/components/models/ModelLibrary.vue'
import AiSettingsPanel from '@/components/models/AiSettingsPanel.vue'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useSettingsStore } from '@/stores/settings'
import { AI_TRANSIENT } from '@/utils/ai'

const { t } = useI18n()
const route  = useRoute()
const router = useRouter()
const ai = useAiStore()
const settingsStore = useSettingsStore()

const TABS = ['browse', 'services', 'library', 'settings']
const tabIcons = { browse: Search, services: Boxes, library: Library, settings: Settings }
const activeTab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'browse')

const hubModelId     = ref(null)
const deployFromHub  = ref(null)
const deployFromModel = ref(null)
const selectedServiceId = ref(route.query.service ?? null)
const tokenConfigured = ref(false)
const loadingServices = ref(false)
const loadingModels   = ref(false)

const selectedService = computed(() => ai.services.find(s => s.id === selectedServiceId.value) ?? null)

function setTab(tab) {
  activeTab.value = tab
  router.replace({ query: tab === 'browse' ? {} : { tab } })
}

// The first services answer carries the abilities (deploy / manage / hub access)
const abilitiesReady = ref(false)

async function loadServices() {
  loadingServices.value = true
  try { await ai.fetchServices() } catch { /* shown empty */ } finally {
    loadingServices.value = false
    abilitiesReady.value = true
  }
}
async function loadModels() {
  loadingModels.value = true
  try { await ai.fetchModels() } catch { /* shown empty */ } finally { loadingModels.value = false }
}

function onDeployed(service) {
  deployFromHub.value = null
  deployFromModel.value = null
  hubModelId.value = null
  setTab('services')
  if (service?.id) selectedServiceId.value = service.id
}

function openService(s) {
  selectedServiceId.value = s.id
}

watch(selectedServiceId, (id) => {
  const query = { ...route.query }
  if (id) query.service = id; else delete query.service
  router.replace({ query })
})

watch(activeTab, (tab) => {
  if (tab === 'library' && !ai.modelsLoaded) loadModels()
})

// Without Hub access the page opens on the library (uploads / imports)
watch(() => ai.hubAvailable, (available) => {
  if (!available && activeTab.value === 'browse' && !route.query.tab) setTab('library')
})

// Deploying services and running mirrors change on their own: refresh while one is shown
const transient = computed(() =>
  ai.services.some(s => AI_TRANSIENT.includes(s.status))
  || ai.models.some(m => (m.snapshots ?? []).some(s => AI_TRANSIENT.includes(s.status))))
let timer = null
watch(transient, (on) => {
  clearInterval(timer)
  if (on) {
    timer = setInterval(() => {
      ai.fetchServices().catch(() => {})
      if (ai.modelsLoaded) ai.fetchModels().catch(() => {})
    }, 5000)
  }
}, { immediate: true })
onUnmounted(() => clearInterval(timer))

onMounted(async () => {
  if (!settingsStore.loaded) settingsStore.fetch()
  ai.fetchEngines()
  loadServices()
  if (activeTab.value === 'library') loadModels()
  try {
    const { data } = await aiApi.hubToken()
    tokenConfigured.value = !!data?.configured
  } catch { tokenConfigured.value = false }
})
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />

    <main class="flex-1 w-full px-6 py-8" data-testid="models-view">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <Sparkles class="w-5 h-5 text-gray-400" />
          <div>
            <h1 class="text-xl font-semibold text-gray-100 tracking-wide">{{ t('ai.title') }}</h1>
            <p class="text-xs text-gray-400">{{ t('ai.subtitle') }}</p>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-2 mb-6">
        <button v-for="tab in TABS" :key="tab" class="settings-tab" :class="activeTab === tab && 'is-active'"
          :data-testid="`models-tab-${tab}`" @click="setTab(tab)">
          <component :is="tabIcons[tab]" class="w-4 h-4" />{{ t(`ai.tabs.${tab}`) }}
          <span v-if="tab === 'services' && ai.services.length" class="ml-1 px-1.5 rounded-sm text-[10px] font-mono bg-gray-800 text-gray-300">{{ ai.services.length }}</span>
        </button>
      </div>

      <div v-if="activeTab === 'browse' && !abilitiesReady" class="py-10 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>
      <HubBrowser v-else-if="activeTab === 'browse' && ai.hubAvailable" @open="hubModelId = $event" />
      <!-- Air-gapped console: no Hub browsing, models come in as uploads / imports -->
      <div v-else-if="activeTab === 'browse'" class="card max-w-2xl space-y-3" data-testid="hub-unavailable">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('ai.hub.unavailableTitle') }}</h3>
        <p class="text-xs text-gray-400">{{ t('ai.hub.unavailableText') }}</p>
        <button class="btn-primary text-xs" @click="setTab('library')">{{ t('ai.hub.openLibrary') }}</button>
      </div>
      <ModelServicesList v-else-if="activeTab === 'services'" :loading="loadingServices" @select="openService" @browse="setTab('browse')" />
      <ModelLibrary v-else-if="activeTab === 'library'" :loading="loadingModels" @deploy="deployFromModel = $event" />
      <AiSettingsPanel v-else-if="activeTab === 'settings'" @token-changed="tokenConfigured = $event" />
    </main>

    <HubModelDrawer v-if="hubModelId" :model-id="hubModelId" :can-deploy="ai.canDeploy" :hub-token-configured="tokenConfigured"
      @close="hubModelId = null" @deploy="deployFromHub = $event" @added="ai.upsertModel($event)" />
    <DeployModelDialog v-if="deployFromHub || deployFromModel" :hub-info="deployFromHub" :model="deployFromModel"
      @close="deployFromHub = null; deployFromModel = null" @deployed="onDeployed" />
    <ModelServiceDetail v-if="selectedService" :service="selectedService"
      @close="selectedServiceId = null" @deleted="selectedServiceId = null" />
  </div>
</template>
