<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial, useTokensTutorial, useUsersTutorial } from '@/composables/useTutorial'
import { settingsSteps } from '@/composables/tutorialSteps'
import { useSettingsPage } from '@/composables/useSettingsPage'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import { useNavigation } from '@/navigation/useNavigation'
import { routeTab } from '@/navigation/menu'
import { Globe, ShieldCheck, Cpu, Package, Settings, MapPin, HardDrive, Boxes, Network } from 'lucide-vue-next'
import AppHeader from '@/components/AppHeader.vue'
import RegionDetailModal from '@/components/RegionDetailModal.vue'
import DemoManager from '@/components/settings/DemoManager.vue'
import RegionsList from '@/components/settings/RegionsList.vue'
import UsersManager from '@/components/settings/UsersManager.vue'
import ApiTokensManager from '@/components/settings/ApiTokensManager.vue'
import RuntimesManager from '@/components/settings/RuntimesManager.vue'
import RuntimePatchesManager from '@/components/settings/RuntimePatchesManager.vue'
import TimezoneCard from '@/components/settings/TimezoneCard.vue'
import GlobalDnsPanel from '@/components/settings/GlobalDnsPanel.vue'
import { useRuntimesStore } from '@/stores/runtimes'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()
const route  = useRoute()
const tutorialStore = useTutorialStore()
const runtimesStore = useRuntimesStore()
runtimesStore.fetch()
const regionModalRef = ref(null)
const { start: startSettingsTutorial } = createTutorial(settingsSteps, {
  onComplete: () => { openAdd(); setTimeout(() => regionModalRef.value?.startTutorial(), 400) },
})
const { start: startTokensTutorial } = useTokensTutorial()
const { start: startUsersTutorial } = useUsersTutorial()
const usersRef = ref(null)

watch(() => tutorialStore.restartCounter, () => {
  const tab = activeTab.value
  if (tab === 'settings') nextTick(() => startSettingsTutorial())
  else if (tab === 'tokens') nextTick(() => startTokensTutorial())
  else if (tab === 'users') {
    usersRef.value?.openAdd()
    nextTick(() => startUsersTutorial())
  }
  // Module tabs (auth-providers, tenants etc.) handle their own tutorial via restartCounter watch
})

const perms = usePermissionsStore()
const canChangeSettings = computed(() => perms.can('settings.manage'))

// The page of each tab is a link of the main menu (src/navigation/menu.js): /settings?tab=<key>.
// The tab shows when the menu shows its link; without ?tab (or with an unknown one) the page
// shows the regions and platform settings. The tabs above the content are the other pages of
// the same menu section.
const navigation = useNavigation()
const builtInKeys = ['settings', 'users', 'tokens', 'runtimes', 'patches']
const validKeys  = computed(() => [...builtInKeys, ...moduleRegistry.settingsTabs.map(tab => tab.key)])
const activeTab  = computed(() => {
  const key = routeTab(route)
  return validKeys.value.includes(key) ? key : 'settings'
})
const place      = computed(() => navigation.findTab(activeTab.value))
const activeLink = computed(() => place.value?.link ?? null)
const siblings   = computed(() => (place.value?.section?.links ?? []).filter(link => link.tab))

// The menu group (a user-menu page: its own name)
const pageTitle  = computed(() => {
  if (place.value?.group) return t(place.value.group.labelKey)
  return activeLink.value ? t(activeLink.value.labelKey) : t('settings.title')
})

// Core pages: the menu shows their link (the tenancy module's Users replaces the core one)
const showsCore = (key) => activeTab.value === key && activeLink.value?.source === 'core'

const {
  baseDomain, registrationEnabled, gpuDemoEnabled, customRuntimesEnabled, totalCpuM, totalMemoryMb, regions, demoStatus,
  internalCrossTenantGrants, canSwitchCrossTenant,
  loading, saving, error, saved, usedCpuM, usedMemoryMb,
  submit, openAdd, openEdit, closeRegionDetail, onRegionSaved,
  deleteRegion, downloadKubeconfig, refreshRegions,
  regionDetailOpen, regionDetailTarget, fmtCpu, fmtMem,
} = useSettingsPage()

function barColor(used, total) {
  if (!total) return 'rgb(var(--brand-500))'
  const pct = used / total * 100
  if (pct >= 80) return '#ef4444'
  if (pct >= 60) return '#f59e0b'
  return 'rgb(var(--brand-500))'
}

const isTenantContext = computed(() => {
  if (!moduleRegistry.isCentralAdminFn) return false
  return !moduleRegistry.isCentralAdminFn()
})

// Global DNS (global projects, docs/global-projects.md): the platform's zone, outside an organization
const showGlobalDns = computed(() => !isTenantContext.value && perms.can('regions.view'))

// Inside an organization: its own domain (Tenancy module card), for members with settings.manage
const tenantDomainCard = computed(() =>
  isTenantContext.value && canChangeSettings.value ? moduleRegistry.settingsSlots?.tenantDomain ?? null : null
)

</script>

<template>
  <AppHeader />

  <main class="px-4 sm:px-6 py-6">
    <!-- Page Header: the menu group and section of the page -->
    <div class="flex items-center gap-2 mb-4">
      <RouterLink to="/projects" class="flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-500 transition-colors font-medium">
        {{ t('settings.backToProjects') }}
      </RouterLink>
    </div>
    <div class="flex items-center gap-3 mb-6">
      <div class="w-0.5 h-9 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <div class="min-w-0">
        <h1 class="text-lg font-semibold text-gray-100" data-testid="settings-title">{{ pageTitle }}</h1>
        <p v-if="place?.section" class="text-xs text-gray-400" data-testid="settings-section">{{ t(place.section.labelKey) }}</p>
      </div>
    </div>

    <!-- The pages of the same menu section -->
    <nav v-if="siblings.length > 1" class="flex flex-wrap gap-2 mb-6" :aria-label="t(place.section.labelKey)" data-testid="settings-section-tabs">
      <RouterLink
        v-for="link in siblings"
        :key="link.key"
        :to="navigation.linkLocation(link)"
        class="settings-tab"
        :class="link.tab === activeTab && 'is-active'"
        :aria-current="link.tab === activeTab ? 'page' : undefined"
        :data-tour="link.dataTour || undefined"
        :data-settings-tab="link.tab"
      >
        <component :is="link.icon || Settings" class="w-4 h-4" aria-hidden="true" />{{ t(link.labelKey) }}
      </RouterLink>
    </nav>

    <!-- Platform tab content -->
    <template v-if="activeTab === 'settings'">
      <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

      <div v-else>
        <div class="flex items-center gap-3 mb-5">
          <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('settings.platformTitle') }}</h3>
        </div>
        <!-- Stats ribbon -->
        <div data-tour="settings-stats" class="grid grid-cols-3 gap-4 mb-6">
          <div class="rounded-xl border border-gray-800 bg-gray-900/80 p-4 relative overflow-hidden">
            <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
                <MapPin class="w-5 h-5" style="color:rgb(var(--brand-500))" />
              </div>
              <div>
                <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('settings.statsRegions') }}</p>
                <p class="text-xl font-bold text-gray-100 font-mono leading-tight">{{ regions.length }}</p>
              </div>
            </div>
          </div>
          <div class="rounded-xl border border-gray-800 bg-gray-900/80 p-4 relative overflow-hidden">
            <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
            <div class="flex items-center gap-3 mb-2.5">
              <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
                <Cpu class="w-5 h-5" style="color:rgb(var(--brand-500))" />
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('settings.resources.cpu') }}</p>
                <p class="text-lg font-bold text-gray-100 font-mono leading-tight">{{ fmtCpu(usedCpuM) }} <span class="text-xs text-gray-500 font-normal">/ {{ fmtCpu(totalCpuM) }}</span></p>
              </div>
            </div>
            <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500" :style="{ width: totalCpuM ? (usedCpuM/totalCpuM*100).toFixed(1)+'%' : '0%', backgroundColor: barColor(usedCpuM, totalCpuM) }" />
            </div>
          </div>
          <div class="rounded-xl border border-gray-800 bg-gray-900/80 p-4 relative overflow-hidden">
            <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
            <div class="flex items-center gap-3 mb-2.5">
              <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
                <HardDrive class="w-5 h-5" style="color:rgb(var(--brand-500))" />
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('settings.resources.memory') }}</p>
                <p class="text-lg font-bold text-gray-100 font-mono leading-tight">{{ fmtMem(usedMemoryMb) }} <span class="text-xs text-gray-500 font-normal">/ {{ fmtMem(totalMemoryMb) }}</span></p>
              </div>
            </div>
            <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
              <div class="h-full rounded-full transition-all duration-500" :style="{ width: totalMemoryMb ? (usedMemoryMb/totalMemoryMb*100).toFixed(1)+'%' : '0%', backgroundColor: barColor(usedMemoryMb, totalMemoryMb) }" />
            </div>
          </div>
        </div>

        <!-- Main grid -->
        <div :class="isTenantContext ? '' : 'grid grid-cols-5 gap-6 items-start'">
          <!-- Left: Configuration (hidden for tenants) -->
          <div v-if="!isTenantContext" class="col-span-2 space-y-4">
            <div class="card" data-tour="settings-endpoint">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <Globe class="w-4 h-4" style="color:rgb(var(--brand-500))" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.endpoint.section') }}</h3>
              </div>
              <label class="label"><TermText path="settings.endpoint.domain" /></label>
              <p class="text-xs text-gray-400 mb-2">{{ t('settings.endpoint.description', { format: '{slug}.{region}.{domain}' }) }}</p>
              <input v-model="baseDomain" class="input font-mono" :placeholder="t('settings.endpoint.placeholder')" autocomplete="off" spellcheck="false" />
              <p v-if="baseDomain.trim()" class="text-xs text-gray-400 font-mono mt-2 break-all">
                {{ t('settings.endpoint.example') }} <span style="color:rgb(var(--brand-500))">https://function.knative-region.{{ baseDomain.trim() }}</span>
              </p>
            </div>

            <!-- The organization's time zone: the timed warmers' crons run in it (saved on its own) -->
            <TimezoneCard />

            <div v-if="!moduleRegistry.hideRegistrationToggleFn?.()" class="card" data-tour="settings-registration">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <ShieldCheck class="w-4 h-4" style="color:rgb(var(--brand-500))" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.registration.section') }}</h3>
              </div>
              <p class="text-xs text-gray-400 mb-3">{{ t('settings.registration.description') }}</p>
              <div class="flex items-center gap-3">
                <button type="button" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                  :class="registrationEnabled ? 'bg-brand-500' : 'bg-gray-700'" @click="registrationEnabled = !registrationEnabled">
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="registrationEnabled ? 'translate-x-5' : 'translate-x-0'" />
                </button>
                <span class="text-sm" :class="registrationEnabled ? 'text-brand-500' : 'text-gray-400'">
                  {{ registrationEnabled ? t('settings.registration.enabled') : t('settings.registration.disabled') }}
                </span>
              </div>
            </div>

            <div class="card">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <Cpu class="w-4 h-4" style="color:#f59e0b" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.gpuDemo.section') }}</h3>
              </div>
              <p class="text-xs text-gray-400 mb-3">{{ t('settings.gpuDemo.description') }}</p>
              <div class="flex items-center gap-3">
                <button type="button" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                  :class="gpuDemoEnabled ? 'bg-amber-500' : 'bg-gray-700'" @click="gpuDemoEnabled = !gpuDemoEnabled">
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="gpuDemoEnabled ? 'translate-x-5' : 'translate-x-0'" />
                </button>
                <span class="text-sm" :class="gpuDemoEnabled ? 'text-amber-400' : 'text-gray-400'">
                  {{ gpuDemoEnabled ? t('settings.gpuDemo.enabled') : t('settings.gpuDemo.disabled') }}
                </span>
              </div>
            </div>

            <div v-if="runtimesStore.canManage" class="card">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <Boxes class="w-4 h-4" style="color:rgb(var(--brand-500))" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('runtimes.customSetting.section') }}</h3>
              </div>
              <p class="text-xs text-gray-400 mb-3">{{ t('runtimes.customSetting.description') }}</p>
              <div class="flex items-center gap-3">
                <button type="button" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                  :class="customRuntimesEnabled ? 'bg-brand-500' : 'bg-gray-700'" @click="customRuntimesEnabled = !customRuntimesEnabled">
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="customRuntimesEnabled ? 'translate-x-5' : 'translate-x-0'" />
                </button>
                <span class="text-sm" :class="customRuntimesEnabled ? 'text-brand-500' : 'text-gray-400'">
                  {{ customRuntimesEnabled ? t('runtimes.customSetting.enabled') : t('runtimes.customSetting.disabled') }}
                </span>
              </div>
            </div>

            <div v-if="canSwitchCrossTenant" class="card" data-testid="settings-cross-tenant">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <Network class="w-4 h-4" style="color:rgb(var(--brand-500))" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('network.platform.section') }}</h3>
              </div>
              <p class="text-xs text-gray-400 mb-3">{{ t('network.platform.description') }}</p>
              <div class="flex items-center gap-3">
                <button type="button" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                  :class="internalCrossTenantGrants ? 'bg-brand-500' : 'bg-gray-700'" data-testid="settings-cross-tenant-toggle"
                  @click="internalCrossTenantGrants = !internalCrossTenantGrants">
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="internalCrossTenantGrants ? 'translate-x-5' : 'translate-x-0'" />
                </button>
                <span class="text-sm" :class="internalCrossTenantGrants ? 'text-brand-500' : 'text-gray-400'">
                  {{ internalCrossTenantGrants ? t('network.platform.enabled') : t('network.platform.disabled') }}
                </span>
              </div>
            </div>

            <DemoManager :status="demoStatus" @changed="refreshRegions" />

            <div data-tour="settings-save">
              <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>
              <button type="button" class="btn-primary w-full justify-center" :disabled="saving || !canChangeSettings" @click="submit">
                {{ saving ? t('common.saving') : saved ? t('settings.saved') : t('common.save') }}
              </button>
            </div>
          </div>

          <!-- Organization domain (inside an organization) -->
          <component :is="tenantDomainCard" v-if="tenantDomainCard" class="mb-6" />

          <!-- The organization's time zone (inside an organization) -->
          <TimezoneCard v-if="isTenantContext" class="mb-6" />

          <!-- Regions -->
          <div :class="isTenantContext ? '' : 'col-span-3 space-y-4'">
            <div class="card">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <Package class="w-4 h-4" style="color:rgb(var(--brand-500))" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.regions.section') }}</h3>
              </div>
              <RegionsList :regions="regions" :loading="loading"
                @edit="openEdit" @delete="deleteRegion" @download-kubeconfig="downloadKubeconfig" @add="openAdd" />
            </div>

            <!-- Global DNS of global projects: the zone, its delegation, the regions' servers -->
            <GlobalDnsPanel v-if="showGlobalDns" />
          </div>
        </div>
      </div>

      <RegionDetailModal v-if="regionDetailOpen" ref="regionModalRef" :region="regionDetailTarget" @close="closeRegionDetail" @saved="onRegionSaved" />
    </template>

    <!-- Users (core; the tenancy module's own page replaces it) -->
    <UsersManager v-if="showsCore('users')" ref="usersRef" />

    <!-- Runtimes (runtimes.manage, or runtimes.use_custom for custom runtimes) -->
    <RuntimesManager v-if="showsCore('runtimes')" />

    <!-- Patches tab (platform administrators): patch feed, policy, deadlines -->
    <RuntimePatchesManager v-if="showsCore('patches')" />

    <!-- API Tokens -->
    <ApiTokensManager v-if="showsCore('tokens')" />

    <!-- Module pages -->
    <component :is="activeLink.component" v-if="activeLink?.source === 'module' && activeLink.component" :key="activeLink.key" />
  </main>
</template>
