<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial, useTokensTutorial, useUsersTutorial } from '@/composables/useTutorial'
import { settingsSteps } from '@/composables/tutorialSteps'
import { useSettingsPage } from '@/composables/useSettingsPage'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import { Globe, ShieldCheck, Cpu, Package, Settings, KeyRound, Users, Building2, BarChart3, CreditCard, Shield, MapPin, HardDrive, Ticket, Boxes, ScrollText, Database, GitBranch } from 'lucide-vue-next'
import AppHeader from '@/components/AppHeader.vue'
import RegionDetailModal from '@/components/RegionDetailModal.vue'
import DemoManager from '@/components/settings/DemoManager.vue'
import RegionsList from '@/components/settings/RegionsList.vue'
import UsersManager from '@/components/settings/UsersManager.vue'
import ApiTokensManager from '@/components/settings/ApiTokensManager.vue'
import RuntimesManager from '@/components/settings/RuntimesManager.vue'
import { useRuntimesStore } from '@/stores/runtimes'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()
const route  = useRoute()
const router = useRouter()
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

const visibleTabs = computed(() =>
  moduleRegistry.settingsTabs
    .filter(tab => !tab.visibleIf || tab.visibleIf())
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
)

// If tenancy module provides its own 'users' tab, hide the built-in one
const perms = usePermissionsStore()
const showBuiltInUsers = computed(() =>
  !visibleTabs.value.some(tab => tab.key === 'users') && perms.can('members.view')
)
const showTokens = computed(() => perms.can('tokens.manage'))
const canChangeSettings = computed(() => perms.can('settings.manage'))

// Deep link: read initial tab from query param
const builtInKeys = ['settings', 'users', 'tokens', 'runtimes']
const validKeys  = computed(() => [...builtInKeys, ...moduleRegistry.settingsTabs.map(t => t.key)])
const activeTab  = ref(validKeys.value.includes(route.query.tab || 'settings') ? (route.query.tab || 'settings') : 'settings')

// Sync activeTab when route query changes (e.g. from AppHeader menu)
watch(() => route.query.tab, (tab) => {
  const key = tab || 'settings'
  if (validKeys.value.includes(key)) activeTab.value = key
})

function setTab(key) {
  activeTab.value = key
  router.replace({ query: key === 'settings' ? {} : { tab: key } })
}

const tabIcons = {
  settings: Settings, tokens: KeyRound, users: Users, runtimes: Boxes,
  tenants: Building2, 'resource-usage': BarChart3,
  billing: CreditCard, 'auth-providers': Shield,
  'support-tickets': Ticket,
  'audit-log': ScrollText,
  roles: ShieldCheck, groups: Users, data: Database, 'git-providers': GitBranch,
}

const {
  baseDomain, registrationEnabled, gpuDemoEnabled, customRuntimesEnabled, totalCpuM, totalMemoryMb, regions, demoStatus,
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

</script>

<template>
  <AppHeader />

  <main class="px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center gap-2 mb-4">
      <RouterLink to="/projects" class="flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-500 transition-colors font-medium">
        {{ t('settings.backToProjects') }}
      </RouterLink>
    </div>
    <div class="flex items-center gap-3 mb-6">
      <div class="w-0.5 h-9 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <h1 class="text-lg font-semibold text-gray-100">{{ t('settings.title') }}</h1>
    </div>

    <!-- Tab bar -->
    <div class="flex flex-wrap gap-2 mb-6">
      <button class="settings-tab" :class="activeTab === 'settings' && 'is-active'" @click="setTab('settings')">
        <Settings class="w-4 h-4" />{{ t('settings.tabLabel') }}
      </button>
      <button v-if="showTokens" class="settings-tab" :class="activeTab === 'tokens' && 'is-active'" data-tour="settings-tokens-tab" @click="setTab('tokens')">
        <KeyRound class="w-4 h-4" />{{ t('tokens.tab') }}
      </button>
      <button v-if="showBuiltInUsers" class="settings-tab" :class="activeTab === 'users' && 'is-active'" data-tour="settings-users-tab" @click="setTab('users')">
        <Users class="w-4 h-4" />{{ t('users.tab') }}
      </button>
      <button v-if="runtimesStore.canUseCustom" class="settings-tab" :class="activeTab === 'runtimes' && 'is-active'" @click="setTab('runtimes')">
        <Boxes class="w-4 h-4" />{{ t('runtimes.tab') }}
      </button>
      <button v-for="tab in visibleTabs" :key="tab.key"
        class="settings-tab" :class="activeTab === tab.key && 'is-active'"
        :data-tour="tab.dataTour" @click="setTab(tab.key)">
        <component :is="tab.icon || tabIcons[tab.key] || Settings" class="w-4 h-4" />{{ t(tab.labelKey) }}
      </button>
    </div>

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

            <div v-if="!moduleRegistry.hideRegistrationToggleFn?.()" class="card" data-tour="settings-registration">
              <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
                <ShieldCheck class="w-4 h-4" style="color:rgb(var(--brand-500))" />
                <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.registration.section') }}</h3>
              </div>
              <p class="text-xs text-gray-400 mb-3">{{ t('settings.registration.description') }}</p>
              <div class="flex items-center gap-3">
                <button type="button" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                  :class="registrationEnabled ? 'bg-brand-500' : 'bg-gray-700'" @click="registrationEnabled = !registrationEnabled">
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="registrationEnabled ? 'translate-x-5' : 'translate-x-0'" />
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
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="gpuDemoEnabled ? 'translate-x-5' : 'translate-x-0'" />
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
                  <span class="pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="customRuntimesEnabled ? 'translate-x-5' : 'translate-x-0'" />
                </button>
                <span class="text-sm" :class="customRuntimesEnabled ? 'text-brand-500' : 'text-gray-400'">
                  {{ customRuntimesEnabled ? t('runtimes.customSetting.enabled') : t('runtimes.customSetting.disabled') }}
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

          <!-- Regions -->
          <div :class="isTenantContext ? 'card' : 'col-span-3 card'">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
              <Package class="w-4 h-4" style="color:rgb(var(--brand-500))" />
              <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.regions.section') }}</h3>
            </div>
            <RegionsList :regions="regions" :loading="loading"
              @edit="openEdit" @delete="deleteRegion" @download-kubeconfig="downloadKubeconfig" @add="openAdd" />
          </div>
        </div>
      </div>

      <RegionDetailModal v-if="regionDetailOpen" ref="regionModalRef" :region="regionDetailTarget" @close="closeRegionDetail" @saved="onRegionSaved" />
    </template>

    <!-- Users tab (built-in, only when tenancy doesn't provide its own) -->
    <UsersManager v-if="activeTab === 'users' && showBuiltInUsers" ref="usersRef" />

    <!-- Runtimes tab (runtimes.manage, or runtimes.use_custom for custom runtimes) -->
    <RuntimesManager v-if="activeTab === 'runtimes' && runtimesStore.canUseCustom" />

    <!-- API Tokens tab -->
    <ApiTokensManager v-if="activeTab === 'tokens' && showTokens" />

    <!-- Module tabs -->
    <template v-for="tab in visibleTabs" :key="tab.key">
      <component v-if="activeTab === tab.key" :is="tab.component" />
    </template>
  </main>
</template>
