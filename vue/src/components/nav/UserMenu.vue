<script setup>
// The user menu (avatar): who is signed in, the account pages of the navigation (Account
// settings and the modules' personal pages: My bills, Support), the theme, the language, the
// tutorial and sign-out.
//
// Keyboard: ↓ on the avatar opens the menu at its first item; ↓/↑ (Home/End) move between the
// items; Esc closes it and returns to the avatar. Focus or a click leaving the menu closes it.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CircleHelp, Languages, LogOut, Moon, MoonStar, Sun } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { useTutorialStore } from '@/stores/tutorial'
import { useTheme } from '@/composables/useTheme'
import { useNavigation } from '@/navigation/useNavigation'
import NavMenuLink from './NavMenuLink.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const tutorialStore = useTutorialStore()
const { current: theme, set: setTheme } = useTheme()
const { account } = useNavigation()

const root = ref(null)
const button = ref(null)
const isOpen = ref(false)

const userInitial = computed(() => auth.user?.email?.[0]?.toUpperCase() ?? '?')
const userAvatar  = computed(() => auth.user?.avatar_url || null)

const THEMES = [
  { key: 'light',    icon: Sun,      labelKey: 'nav.themeLight' },
  { key: 'dark',     icon: Moon,     labelKey: 'nav.themeDark' },
  { key: 'fulldark', icon: MoonStar, labelKey: 'nav.themeFullDark' },
]
const LANGUAGES = [
  { key: 'en', labelKey: 'nav.languageEn', flag: 'fi-gb' },
  { key: 'tr', labelKey: 'nav.languageTr', flag: 'fi-tr' },
]

const items = () => [...(root.value?.querySelectorAll('[data-menu-item], [data-nav-link]') ?? [])]

function open(focusFirst = false) {
  isOpen.value = true
  if (focusFirst) nextTick(() => items()[0]?.focus())
}

function close({ restoreFocus = false } = {}) {
  isOpen.value = false
  if (restoreFocus) button.value?.focus()
}

function toggle() {
  if (isOpen.value) close()
  else open()
}

function onButtonKeydown(event) {
  if (event.key === 'ArrowDown') { event.preventDefault(); open(true) }
  else if (event.key === 'Escape' && isOpen.value) { event.preventDefault(); close() }
}

function onMenuKeydown(event) {
  const list = items()
  const index = list.indexOf(document.activeElement)
  if (event.key === 'Escape') { event.preventDefault(); close({ restoreFocus: true }); return }
  const move = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: list.length - 1 }[event.key]
  if (move === undefined || !list.length) return
  event.preventDefault()
  list[(move + list.length) % list.length]?.focus()
}

function onFocusOut(event) {
  if (isOpen.value && event.relatedTarget && !root.value?.contains(event.relatedTarget)) close()
}

function onDocumentPointer(event) {
  if (isOpen.value && root.value && !root.value.contains(event.target)) close()
}

watch(isOpen, (value) => {
  if (value) document.addEventListener('pointerdown', onDocumentPointer)
  else document.removeEventListener('pointerdown', onDocumentPointer)
})
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointer))
watch(() => route.fullPath, () => close())

function setLocale(key) {
  locale.value = key
  localStorage.setItem('locale', key)
}

function restartTutorial() {
  close()
  tutorialStore.requestRestart()
}

async function logout() {
  close()
  await auth.logout()
  router.push('/login')
}

defineExpose({ open, close, isOpen })
</script>

<template>
  <div ref="root" class="relative" @focusout="onFocusOut">
    <button
      ref="button"
      type="button"
      class="h-8 w-8 flex items-center justify-center rounded-md text-sm font-bold transition-colors hover:opacity-90 overflow-hidden focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
      :class="userAvatar ? '' : (theme === 'light' ? 'text-white' : 'text-gray-900')"
      :style="userAvatar ? '' : (theme === 'light' ? 'background:linear-gradient(135deg,rgb(var(--brand-500)),rgb(var(--brand-700)))' : 'background:#ffffff')"
      data-tour="user-menu-btn"
      data-testid="user-menu-button"
      :aria-label="t('nav.accountMenu')"
      :aria-expanded="isOpen ? 'true' : 'false'"
      :aria-controls="isOpen ? 'user-menu' : undefined"
      @click="toggle"
      @keydown="onButtonKeydown"
    >
      <img v-if="userAvatar" :src="userAvatar" alt="" class="w-full h-full object-cover" />
      <span v-else aria-hidden="true">{{ userInitial }}</span>
    </button>

    <!-- Scrolls within the window: the header is sticky -->
    <div
      v-if="isOpen"
      id="user-menu"
      data-testid="user-menu"
      class="absolute right-0 mt-2 w-72 max-w-[calc(100vw-2rem)] max-h-[calc(100vh-4rem)] overflow-y-auto rounded-lg border border-gray-800 bg-gray-900 shadow-xl py-1 z-50"
      @keydown="onMenuKeydown"
    >
      <div class="px-4 py-2.5 border-b border-gray-800">
        <p class="text-xs text-gray-400 mb-0.5">{{ t('nav.signedInAs') }}</p>
        <p class="text-sm text-gray-100 font-medium truncate">{{ auth.user?.email }}</p>
      </div>

      <ul v-if="account.length" class="p-1.5 border-b border-gray-800 space-y-0.5" role="list">
        <li v-for="link in account" :key="link.key">
          <NavMenuLink :link="link" compact />
        </li>
      </ul>

      <div class="px-4 py-3 border-b border-gray-800 space-y-3">
        <div>
          <p id="user-menu-theme" class="text-xs text-gray-400 mb-1.5">{{ t('nav.theme') }}</p>
          <div class="grid grid-cols-3 gap-1" role="group" aria-labelledby="user-menu-theme">
            <button
              v-for="option in THEMES"
              :key="option.key"
              type="button"
              data-menu-item
              :data-theme-option="option.key"
              :aria-pressed="theme === option.key ? 'true' : 'false'"
              class="flex items-center justify-center gap-1.5 h-8 rounded-md border text-xs transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
              :class="theme === option.key ? 'border-brand-500/50 bg-brand-500/10 text-brand-500' : 'border-gray-800 text-gray-400 hover:text-gray-100 hover:border-gray-700'"
              @click="setTheme(option.key)"
            >
              <component :is="option.icon" class="w-3.5 h-3.5" aria-hidden="true" />{{ t(option.labelKey) }}
            </button>
          </div>
        </div>
        <div>
          <p id="user-menu-language" class="text-xs text-gray-400 mb-1.5 flex items-center gap-1.5">
            <Languages class="w-3.5 h-3.5" aria-hidden="true" />{{ t('nav.language') }}
          </p>
          <div class="grid grid-cols-2 gap-1" role="group" aria-labelledby="user-menu-language">
            <button
              v-for="option in LANGUAGES"
              :key="option.key"
              type="button"
              data-menu-item
              :data-locale-option="option.key"
              :lang="option.key"
              :aria-pressed="locale === option.key ? 'true' : 'false'"
              class="flex items-center justify-center gap-1.5 h-8 rounded-md border text-xs transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
              :class="locale === option.key ? 'border-brand-500/50 bg-brand-500/10 text-brand-500' : 'border-gray-800 text-gray-400 hover:text-gray-100 hover:border-gray-700'"
              @click="setLocale(option.key)"
            >
              <span class="fi rounded-full" :class="option.flag" style="display:block;width:0.875rem;height:0.875rem;background-size:cover;background-position:50% 50%" aria-hidden="true"></span>
              {{ t(option.labelKey) }}
            </button>
          </div>
        </div>
      </div>

      <div class="p-1.5 border-b border-gray-800">
        <button
          type="button"
          data-menu-item
          data-testid="user-menu-tutorial"
          class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-gray-100 hover:bg-gray-800/70 transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          @click="restartTutorial"
        >
          <CircleHelp class="w-4 h-4 text-gray-400" aria-hidden="true" />{{ t('tutorial.restart') }}
        </button>
      </div>

      <div class="p-1.5">
        <button
          type="button"
          data-menu-item
          data-testid="user-menu-logout"
          class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-gray-300 hover:text-red-400 hover:bg-gray-800/70 transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          @click="logout"
        >
          <LogOut class="w-4 h-4 text-gray-400" aria-hidden="true" />{{ t('nav.logout') }}
        </button>
      </div>
    </div>
  </div>
</template>
