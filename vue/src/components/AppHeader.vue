<script setup>
import { computed, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useTutorialStore } from '@/stores/tutorial'
import { useTheme } from '@/composables/useTheme'
import { moduleRegistry } from '@/modules/index'
import RunrWordmark from '@/components/RunrWordmark.vue'
import { Sun, Moon, MoonStar, LogOut, Bell, Settings, KeyRound, Users, Building2, BarChart3, CreditCard, Shield, UserCog, LifeBuoy, Sparkles } from 'lucide-vue-next'
import { useNotificationStore } from '@/stores/notifications'
import { useFallbackPolling } from '@/composables/useFallbackPolling'
import NotificationPanel from '@/components/NotificationPanel.vue'
import runrIconUrl from '@/assets/runr-icon.svg'
import { useBrandingStore } from '@/modules/white-label/stores/branding'

const { t, locale } = useI18n()
const router        = useRouter()
const route         = useRoute()
const auth          = useAuthStore()
const tutorialStore = useTutorialStore()
const { current: theme, cycle: cycleTheme } = useTheme()

// White-label branding: brand icon (logo mark, else the RunR icon) + wordmark; a custom logo
// replaces both, a custom product name replaces the RunR wordmark (RunrWordmark)
const branding = useBrandingStore()
const markUrl  = computed(() => branding.markUrl || runrIconUrl)

const notificationStore = useNotificationStore()
const notifPanelOpen = ref(false)
function toggleNotifPanel() { notifPanelOpen.value = !notifPanelOpen.value }
function closeNotifPanel()  { notifPanelOpen.value = false }
useFallbackPolling(() => notificationStore.fetchUnreadCount(), 120000)

const userMenuOpen = ref(false)
const userInitial  = computed(() => auth.user?.email?.[0]?.toUpperCase() ?? '?')
const userAvatar   = computed(() => auth.user?.avatar_url || null)
function toggleUserMenu() { userMenuOpen.value = !userMenuOpen.value }
function closeUserMenu()  { userMenuOpen.value = false }

function goToAccount() {
  closeUserMenu()
  router.push('/account')
}

const tabIcons = { settings: Settings, tokens: KeyRound, users: Users, tenants: Building2, 'resource-usage': BarChart3, billing: CreditCard, 'auth-providers': Shield, 'support-tickets': LifeBuoy }
const visibleModuleTabs = computed(() =>
  moduleRegistry.settingsTabs
    .filter(tab => !tab.visibleIf || tab.visibleIf())
    .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
)
const showBuiltInUsers = computed(() => !visibleModuleTabs.value.some(tab => tab.key === 'users'))

function goToSettings(tab) {
  closeUserMenu()
  router.push(tab === 'settings' ? '/settings' : `/settings?tab=${tab}`)
}

async function logout() {
  closeUserMenu()
  await auth.logout()
  router.push('/login')
}

const themeLabel = computed(() => {
  const map = { dark: 'nav.themeDark', fulldark: 'nav.themeFullDark', light: 'nav.themeLight' }
  return t(map[theme.value] || map.dark)
})

function toggleLocale() {
  locale.value = locale.value === 'tr' ? 'en' : 'tr'
  localStorage.setItem('locale', locale.value)
}
</script>

<template>
  <header class="sticky top-0 z-40 border-b border-gray-800 bg-gray-950">
    <div class="px-6 h-14 flex items-center justify-between">
      <!-- Logo -->
      <div class="flex items-center gap-6">
        <RouterLink to="/projects" class="flex items-center gap-3">
          <img v-if="!branding.hasCustomLogo" :src="markUrl" :alt="branding.productName" class="h-8 w-8 object-contain" data-test="header-mark" />
          <RunrWordmark size="sm" />
        </RouterLink>
        <!-- Main navigation -->
        <nav class="flex items-center gap-1" data-testid="main-nav">
          <RouterLink to="/projects" class="px-3 py-1.5 rounded-md text-sm transition-colors"
            :class="route.path.startsWith('/projects') ? 'text-gray-100 bg-gray-800' : 'text-gray-400 hover:text-gray-100'">
            {{ t('ai.nav.projects') }}
          </RouterLink>
          <RouterLink to="/models" class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm transition-colors" data-testid="nav-models"
            :class="route.path.startsWith('/models') ? 'text-gray-100 bg-gray-800' : 'text-gray-400 hover:text-gray-100'">
            <Sparkles class="w-3.5 h-3.5" />{{ t('ai.nav.models') }}
          </RouterLink>
        </nav>
      </div>

      <!-- Right -->
      <div class="flex items-center gap-1">
        <!-- Module header slots -->
        <template v-for="(comp, i) in moduleRegistry.headerSlots" :key="'hdr-'+i">
          <component :is="comp" />
          <div class="w-px h-4 bg-gray-800 mx-1"></div>
        </template>

        <!-- Notifications -->
        <div class="relative" v-click-outside="closeNotifPanel" data-tour="notifications-btn">
          <button :title="t('notifications.title')"
            class="h-8 w-8 flex items-center justify-center text-gray-400 hover:text-gray-100 transition-colors rounded-md hover:bg-gray-800 relative"
            @click="toggleNotifPanel">
            <Bell class="w-4 h-4" />
            <span v-if="notificationStore.unreadCount > 0"
              class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 flex items-center justify-center rounded-md bg-red-500 text-white text-[10px] font-bold px-1"
            >{{ notificationStore.unreadCount > 99 ? '99+' : notificationStore.unreadCount }}</span>
          </button>
          <NotificationPanel v-if="notifPanelOpen" />
        </div>

        <!-- Tutorial restart -->
        <button :title="t('tutorial.restart')"
          class="h-8 w-8 flex items-center justify-center text-sm text-gray-400 hover:text-gray-100 transition-colors rounded-md hover:bg-gray-800"
          @click="tutorialStore.requestRestart()">?</button>

        <div class="w-px h-4 bg-gray-800 mx-1"></div>

        <!-- Theme toggle -->
        <button class="h-8 w-8 flex items-center justify-center transition-colors rounded-md hover:bg-gray-800" :title="themeLabel" @click="cycleTheme">
          <Sun      v-if="theme === 'light'"     class="w-4 h-4" style="color:#f59e0b" />
          <Moon     v-else-if="theme === 'dark'" class="w-4 h-4" style="color:rgb(var(--brand-500))" />
          <MoonStar v-else                        class="w-4 h-4" style="color:rgb(var(--brand-700))" />
        </button>

        <!-- Language toggle -->
        <button data-tour="lang-toggle"
          class="h-8 w-8 flex items-center justify-center rounded-md hover:bg-gray-800 transition-colors"
          :title="locale === 'tr' ? 'Switch to English' : 'Türkçeye geç'" @click="toggleLocale">
          <span v-if="locale === 'tr'" class="fi fi-tr rounded-full" style="display:block;width:1.25rem;height:1.25rem;background-size:cover;background-position:50% 50%"></span>
          <span v-else                  class="fi fi-gb rounded-full" style="display:block;width:1.25rem;height:1.25rem;background-size:cover;background-position:50% 50%"></span>
        </button>

        <div class="w-px h-4 bg-gray-800 mx-1"></div>

        <!-- User avatar + dropdown -->
        <div class="relative" v-click-outside="closeUserMenu">
          <button
            class="h-8 w-8 flex items-center justify-center rounded-md text-sm font-bold transition-colors hover:opacity-90 overflow-hidden"
            :class="userAvatar ? '' : (theme === 'light' ? 'text-white' : 'text-gray-900')"
            :style="userAvatar ? '' : (theme === 'light' ? 'background:linear-gradient(135deg,rgb(var(--brand-500)),rgb(var(--brand-700)))' : 'background:#ffffff')"
            data-tour="settings-btn"
            @click="toggleUserMenu"
          >
            <img v-if="userAvatar" :src="userAvatar" alt="" class="w-full h-full object-cover" />
            <span v-else>{{ userInitial }}</span>
          </button>

          <!-- Scrolls within the window: the header is sticky, and with the modules' tabs the
               menu of an administrator is taller than a laptop screen (Logout out of reach) -->
          <div v-if="userMenuOpen" class="absolute right-0 mt-2 w-56 max-h-[calc(100vh-4rem)] overflow-y-auto rounded-lg border border-gray-800 bg-gray-900 shadow-xl py-1 z-50">
            <!-- User info -->
            <div class="px-4 py-2.5 border-b border-gray-800">
              <p class="text-xs text-gray-400 mb-0.5">{{ t('nav.signedInAs') }}</p>
              <p class="text-sm text-gray-100 font-medium truncate">{{ auth.user?.email }}</p>
            </div>

            <!-- User Settings -->
            <div class="py-1 border-b border-gray-800">
              <button class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors" @click="goToAccount">
                <UserCog class="w-3.5 h-3.5" />{{ t('nav.userSettings') }}
              </button>
            </div>

            <!-- Administration links -->
            <div class="py-1 border-b border-gray-800">
              <p class="px-4 pt-2 pb-1.5 text-[10px] text-gray-500 font-semibold uppercase tracking-wider">{{ t('nav.administration') }}</p>
              <button class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors" @click="goToSettings('settings')">
                <Settings class="w-3.5 h-3.5" />{{ t('settings.tabLabel') }}
              </button>
              <button v-if="showBuiltInUsers" class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors" @click="goToSettings('users')">
                <Users class="w-3.5 h-3.5" />{{ t('users.tab') }}
              </button>
              <button class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors" @click="goToSettings('tokens')">
                <KeyRound class="w-3.5 h-3.5" />{{ t('tokens.tab') }}
              </button>
              <button v-for="tab in visibleModuleTabs" :key="tab.key"
                class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors"
                @click="goToSettings(tab.key)">
                <component :is="tab.icon || tabIcons[tab.key] || Settings" class="w-3.5 h-3.5" />{{ t(tab.labelKey) }}
              </button>
            </div>

            <!-- Logout -->
            <button class="w-full flex items-center gap-2.5 px-4 py-2.5 text-sm text-gray-400 hover:text-red-400 hover:bg-gray-800 transition-colors" @click="logout">
              <LogOut class="w-3.5 h-3.5" />{{ t('nav.logout') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
