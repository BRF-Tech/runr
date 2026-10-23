<script setup>
// The main menu on narrow screens: a drawer with Projects and the groups as an accordion
// (the same tree as the mega menus). The group of the current page starts open.
//
// Keyboard: focus starts on the close button and stays in the drawer (Tab wraps); ↓/↑
// (Home/End) move between the drawer's buttons and links; Esc closes the drawer.
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDown, FolderKanban, X } from 'lucide-vue-next'
import { useNavigation } from '@/navigation/useNavigation'
import NavMenuLink from './NavMenuLink.vue'

const emit = defineEmits(['close'])

const { t } = useI18n()
const { groups, projectsActive, isGroupActive } = useNavigation()

const dialog = ref(null)
const closeButton = ref(null)
const expanded = ref(groups.value.find(isGroupActive)?.key ?? null)

function toggleGroup(key) {
  expanded.value = expanded.value === key ? null : key
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
// the links of a closed group stay in the page (aria-controls) but are not reachable
const reachable = (selector) => [...(dialog.value?.querySelectorAll(selector) ?? [])]
  .filter(el => !el.closest('[data-collapsed="true"]'))
const focusables = () => reachable(FOCUSABLE)
const movables = () => reachable('[data-drawer-item], [data-nav-link]')

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
    return
  }
  if (event.key === 'Tab') {
    const list = focusables()
    if (!list.length) return
    const first = list[0]
    const last = list[list.length - 1]
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
    return
  }
  const list = movables()
  const index = list.indexOf(document.activeElement)
  const move = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: list.length - 1 }[event.key]
  if (move === undefined || !list.length) return
  event.preventDefault()
  list[(move + list.length) % list.length]?.focus()
}

// The page behind the drawer does not scroll while it is open
let overflow = ''
onMounted(() => {
  overflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  nextTick(() => closeButton.value?.focus())
})
onBeforeUnmount(() => {
  document.body.style.overflow = overflow
})
</script>

<template>
  <div class="fixed inset-0 z-50 lg:hidden" data-testid="mobile-nav">
    <div class="absolute inset-0 bg-black/60" aria-hidden="true" data-testid="mobile-nav-backdrop" @click="emit('close')" />

    <div
      id="mobile-nav"
      ref="dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="mobile-nav-title"
      class="absolute inset-y-0 left-0 w-[88%] max-w-sm flex flex-col border-r border-gray-800 bg-gray-950 shadow-2xl"
      @keydown="onKeydown"
    >
      <div class="h-14 shrink-0 px-4 flex items-center justify-between border-b border-gray-800">
        <h2 id="mobile-nav-title" class="text-sm font-semibold text-gray-100">{{ t('nav.menu') }}</h2>
        <button
          ref="closeButton"
          type="button"
          data-drawer-item
          data-testid="mobile-nav-close"
          :aria-label="t('nav.closeMenu')"
          class="h-9 w-9 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          @click="emit('close')"
        >
          <X class="w-5 h-5" aria-hidden="true" />
        </button>
      </div>

      <nav :aria-label="t('nav.main')" class="flex-1 overflow-y-auto px-3 py-3 space-y-1">
        <RouterLink
          to="/projects"
          data-drawer-item
          data-testid="nav-projects"
          :aria-current="projectsActive ? 'page' : undefined"
          class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          :class="projectsActive ? 'bg-brand-500/10 text-brand-500' : 'text-gray-200 hover:bg-gray-800/70'"
        >
          <FolderKanban class="w-4 h-4" aria-hidden="true" />{{ t('ai.nav.projects') }}
        </RouterLink>

        <div v-for="group in groups" :key="group.key" class="border-t border-gray-800/70 pt-1">
          <h3>
            <button
              :id="`mobile-nav-trigger-${group.key}`"
              type="button"
              data-drawer-item
              :data-testid="`nav-group-${group.key}`"
              :aria-expanded="expanded === group.key ? 'true' : 'false'"
              :aria-controls="`mobile-nav-panel-${group.key}`"
              class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-left transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
              :class="isGroupActive(group) ? 'text-brand-500' : 'text-gray-200 hover:bg-gray-800/70'"
              @click="toggleGroup(group.key)"
            >
              <component :is="group.icon" class="w-4 h-4" aria-hidden="true" />
              <span class="flex-1">{{ t(group.labelKey) }}</span>
              <ChevronDown class="w-4 h-4 text-gray-500 transition-transform" :class="expanded === group.key && 'rotate-180'" aria-hidden="true" />
            </button>
          </h3>
          <div
            v-show="expanded === group.key"
            :id="`mobile-nav-panel-${group.key}`"
            role="region"
            :aria-labelledby="`mobile-nav-trigger-${group.key}`"
            :data-testid="`nav-panel-${group.key}`"
            :data-collapsed="expanded === group.key ? 'false' : 'true'"
            class="pb-2"
          >
            <section v-for="section in group.sections" :key="section.key" class="mt-2" :data-nav-section="section.key">
              <h4 class="px-3 mb-1 text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ t(section.labelKey) }}</h4>
              <ul class="space-y-0.5" role="list">
                <li v-for="link in section.links" :key="link.key">
                  <NavMenuLink :link="link" />
                </li>
              </ul>
            </section>
          </div>
        </div>
      </nav>
    </div>
  </div>
</template>
