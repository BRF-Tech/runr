<script setup>
// The console header: the brand, the main menu (src/navigation/menu.js — Projects and the
// mega menus on wide screens, a drawer on narrow ones), the modules' header items (tenant
// switcher), the notifications and the user menu.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Bell, Menu, ShieldCheck } from 'lucide-vue-next'
import { moduleRegistry } from '@/modules/index'
import { useAuthStore } from '@/stores/auth'
import { useRuntimesStore } from '@/stores/runtimes'
import { useSettingsStore } from '@/stores/settings'
import { useNotificationStore } from '@/stores/notifications'
import { useApprovalsStore } from '@/stores/approvals'
import { usePermissionsStore } from '@/stores/permissions'
import { useFallbackPolling } from '@/composables/useFallbackPolling'
import RunrWordmark from '@/components/RunrWordmark.vue'
import NotificationPanel from '@/components/NotificationPanel.vue'
import MainNav from '@/components/nav/MainNav.vue'
import MobileNavDrawer from '@/components/nav/MobileNavDrawer.vue'
import UserMenu from '@/components/nav/UserMenu.vue'
import runrIconUrl from '@/assets/runr-icon.svg'
import { useBrandingStore } from '@/modules/white-label/stores/branding'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

// White-label branding: brand icon (logo mark, else the RunR icon) + wordmark; a custom logo
// replaces both, a custom product name replaces the RunR wordmark (RunrWordmark)
const branding = useBrandingStore()
const markUrl  = computed(() => branding.markUrl || runrIconUrl)

// The menus read the runtime abilities (Runtimes) and the settings (multi-tenant: whether the
// console works in the platform or in a tenant); both stores load once
const runtimes = useRuntimesStore()
const settings = useSettingsStore()
onMounted(() => {
  if (!auth.token) return
  runtimes.fetch()
  if (!settings.loaded) settings.fetch().catch(() => { /* the menus wait for the next page's load */ })
})

// ── Notifications ──
const notificationStore = useNotificationStore()
const notifRoot = ref(null)
const notifPanelOpen = ref(false)
function toggleNotifPanel() { notifPanelOpen.value = !notifPanelOpen.value }
function closeNotifPanel()  { notifPanelOpen.value = false }
function onNotifPointer(event) {
  if (notifPanelOpen.value && notifRoot.value && !notifRoot.value.contains(event.target)) closeNotifPanel()
}
watch(notifPanelOpen, (open) => {
  if (open) document.addEventListener('pointerdown', onNotifPointer)
  else document.removeEventListener('pointerdown', onNotifPointer)
})
useFallbackPolling(() => notificationStore.fetchUnreadCount(), 120000)

// ── Approvals ──
// Promotions waiting for an approver (docs/environments.md#approvals): approvers and requesters
// see the inbox next to the notifications, with the number waiting (the page is also a link of
// Build & Run → Workloads, src/navigation/menu.js)
const approvals     = useApprovalsStore()
const permissions   = usePermissionsStore()
const showApprovals = computed(() => permissions.canAnywhere('environments.approve') || permissions.canAnywhere('environments.promote'))
onMounted(() => { if (auth.token && showApprovals.value) approvals.fetchCount() })
useFallbackPolling(() => { if (showApprovals.value) approvals.fetchCount() }, 120000)

// ── Mobile drawer ──
const menuButton = ref(null)
const drawerOpen = ref(false)
function openDrawer() { drawerOpen.value = true }
function closeDrawer({ restoreFocus = true } = {}) {
  if (!drawerOpen.value) return
  drawerOpen.value = false
  if (restoreFocus) menuButton.value?.focus()
}
watch(() => route.fullPath, () => closeDrawer({ restoreFocus: false }))

// Wide enough for the main menu again: the drawer goes (it is hidden there anyway)
let wide = null
function onWide(event) { if (event.matches) closeDrawer({ restoreFocus: false }) }
onMounted(() => {
  wide = window.matchMedia?.('(min-width: 1024px)') ?? null
  wide?.addEventListener?.('change', onWide)
})
onBeforeUnmount(() => {
  wide?.removeEventListener?.('change', onWide)
  document.removeEventListener('pointerdown', onNotifPointer)
})
</script>

<template>
  <header class="sticky top-0 z-40 border-b border-gray-800 bg-gray-950">
    <div class="px-3 sm:px-6 h-14 flex items-center gap-2 sm:gap-4">
      <!-- Menu button (narrow screens) -->
      <button
        ref="menuButton"
        type="button"
        class="lg:hidden h-9 w-9 -ml-1 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
        data-testid="mobile-nav-button"
        :aria-label="t('nav.openMenu')"
        :aria-expanded="drawerOpen ? 'true' : 'false'"
        aria-controls="mobile-nav"
        @click="openDrawer"
      >
        <Menu class="w-5 h-5" aria-hidden="true" />
      </button>

      <!-- Brand -->
      <RouterLink to="/projects" class="flex items-center gap-3 shrink-0 min-w-0 rounded-md focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60" :aria-label="branding.productName">
        <img v-if="!branding.hasCustomLogo" :src="markUrl" alt="" class="h-8 w-8 object-contain" data-test="header-mark" />
        <span :class="branding.hasCustomLogo ? 'block max-w-[10rem] sm:max-w-none overflow-hidden' : 'hidden sm:block'">
          <RunrWordmark size="sm" />
        </span>
      </RouterLink>

      <!-- Main menu (wide screens) -->
      <MainNav class="hidden lg:flex" />

      <!-- Right -->
      <div class="ml-auto flex items-center gap-1 shrink-0">
        <!-- Module header items (tenant switcher) -->
        <template v-for="(comp, i) in moduleRegistry.headerSlots" :key="'hdr-'+i">
          <component :is="comp" />
        </template>

        <!-- Approvals inbox -->
        <RouterLink
          v-if="showApprovals"
          to="/approvals"
          :title="t('environments.approvals.nav')"
          :aria-label="t('environments.approvals.nav')"
          data-testid="nav-approvals"
          class="h-8 w-8 flex items-center justify-center rounded-md transition-colors relative hover:bg-gray-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          :class="route.path.startsWith('/approvals') ? 'text-gray-100 bg-gray-800' : 'text-gray-400 hover:text-gray-100'"
        >
          <ShieldCheck class="w-4 h-4" aria-hidden="true" />
          <span v-if="approvals.pending > 0" data-testid="nav-approvals-count"
            class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 flex items-center justify-center rounded-md bg-amber-500 text-gray-950 text-[10px] font-bold px-1"
          >{{ approvals.pending > 99 ? '99+' : approvals.pending }}</span>
        </RouterLink>

        <!-- Notifications -->
        <div ref="notifRoot" class="relative" data-tour="notifications-btn" @keydown.esc="closeNotifPanel">
          <button
            type="button"
            :title="t('notifications.title')"
            :aria-label="t('notifications.title')"
            :aria-expanded="notifPanelOpen ? 'true' : 'false'"
            data-testid="notifications-button"
            class="h-8 w-8 flex items-center justify-center text-gray-400 hover:text-gray-100 transition-colors rounded-md hover:bg-gray-800 relative focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
            @click="toggleNotifPanel"
          >
            <Bell class="w-4 h-4" aria-hidden="true" />
            <span v-if="notificationStore.unreadCount > 0"
              class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 flex items-center justify-center rounded-md bg-red-500 text-white text-[10px] font-bold px-1"
            >{{ notificationStore.unreadCount > 99 ? '99+' : notificationStore.unreadCount }}</span>
          </button>
          <NotificationPanel v-if="notifPanelOpen" />
        </div>

        <div class="w-px h-4 bg-gray-800 mx-1" aria-hidden="true"></div>

        <UserMenu />
      </div>
    </div>

    <MobileNavDrawer v-if="drawerOpen" @close="closeDrawer()" />
  </header>
</template>
