<script setup>
// The main menu on wide screens: Projects on its own, then one button per group that opens
// its mega menu (MegaMenuPanel). Narrow screens use MobileNavDrawer with the same tree. The
// parent sets the display (AppHeader: hidden below lg, flex from lg) and gives the menu the
// header's free width.
//
// Compact: when the labels do not fit that width — a 1024 px window in Turkish with the tenant
// switcher in the header — the groups show their icon only (the label stays the button's
// accessible name and its tooltip). A hidden copy of the full menu (data-testid main-nav-measure)
// is measured against the free width whenever either changes (window, language, permissions).
//
// Keyboard: ←/→, Home/End move between the menu items; ↓/↑ on a group open its menu at the
// first/last link; in a menu ↓/↑ (Home/End) move between the links and ←/→ between the
// sections; Esc closes the menu and returns to its button. Focus or a click leaving the menu
// closes it, so does a navigation.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronDown } from 'lucide-vue-next'
import { useNavigation } from '@/navigation/useNavigation'
import MegaMenuPanel from './MegaMenuPanel.vue'

const { t } = useI18n()
const route = useRoute()
const { groups, projectsActive, isGroupActive } = useNavigation()

const root = ref(null)
const measure = ref(null)
const openKey = ref(null)
const compact = ref(false)

// Icons only while the full menu is wider than the space the header leaves it
function fit() {
  const nav = root.value
  const full = measure.value
  if (!nav || !full || !nav.clientWidth) return // hidden (narrow screens)
  compact.value = full.offsetWidth > nav.clientWidth
}

let resizeObserver = null
onMounted(() => {
  if (typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => fit())
    resizeObserver.observe(root.value)
    resizeObserver.observe(measure.value)
  }
  nextTick(fit)
})
onBeforeUnmount(() => resizeObserver?.disconnect())

const items = () => [...(root.value?.querySelectorAll('[data-nav-item]') ?? [])]
const trigger = (key) => root.value?.querySelector(`#nav-trigger-${key}`)
const panel = () => (openKey.value ? root.value?.querySelector(`#nav-panel-${openKey.value}`) : null)
const panelLinks = () => [...(panel()?.querySelectorAll('[data-nav-link]') ?? [])]

function open(key, focus = null) {
  openKey.value = key
  if (focus) {
    nextTick(() => {
      const links = panelLinks()
      ;(focus === 'last' ? links[links.length - 1] : links[0])?.focus()
    })
  }
}

function close({ restoreFocus = false } = {}) {
  const key = openKey.value
  openKey.value = null
  if (restoreFocus && key) trigger(key)?.focus()
}

// A menu is open and the pointer moves to another group: show that one. The click that usually
// follows the move keeps it open instead of closing it again.
let hoverSwitch = { key: null, at: 0 }
function hover(key) {
  if (openKey.value && openKey.value !== key) {
    open(key)
    hoverSwitch = { key, at: Date.now() }
  }
}

function toggle(key) {
  const justHovered = hoverSwitch.key === key && Date.now() - hoverSwitch.at < 600
  hoverSwitch = { key: null, at: 0 }
  if (openKey.value === key && !justHovered) close()
  else open(key)
}

function focusItem(index) {
  const list = items()
  if (!list.length) return
  const target = list[(index + list.length) % list.length]
  target.focus()
  // with a menu open, the open menu follows the focus
  if (openKey.value) {
    const key = target.dataset.navGroup
    if (key) open(key)
    else close()
  }
}

function onItemKeydown(event, groupKey = null) {
  const list = items()
  const index = list.indexOf(event.currentTarget)
  switch (event.key) {
    case 'ArrowRight': event.preventDefault(); focusItem(index + 1); break
    case 'ArrowLeft':  event.preventDefault(); focusItem(index - 1); break
    case 'Home':       event.preventDefault(); focusItem(0); break
    case 'End':        event.preventDefault(); focusItem(list.length - 1); break
    case 'ArrowDown':
      if (groupKey) { event.preventDefault(); open(groupKey, 'first') }
      break
    case 'ArrowUp':
      if (groupKey) { event.preventDefault(); open(groupKey, 'last') }
      break
    case 'Escape':
      if (openKey.value) { event.preventDefault(); close({ restoreFocus: true }) }
      break
  }
}

function onPanelKeydown(event) {
  const links = panelLinks()
  const index = links.indexOf(document.activeElement)
  const sectionStarts = () => [...(panel()?.querySelectorAll('[data-nav-section]') ?? [])]
    .map(section => links.indexOf(section.querySelector('[data-nav-link]')))
  switch (event.key) {
    case 'ArrowDown': event.preventDefault(); links[(index + 1) % links.length]?.focus(); break
    case 'ArrowUp':   event.preventDefault(); links[(index - 1 + links.length) % links.length]?.focus(); break
    case 'Home':      event.preventDefault(); links[0]?.focus(); break
    case 'End':       event.preventDefault(); links[links.length - 1]?.focus(); break
    case 'ArrowRight': {
      event.preventDefault()
      const next = sectionStarts().find(start => start > index)
      links[next ?? 0]?.focus()
      break
    }
    case 'ArrowLeft': {
      event.preventDefault()
      const starts = sectionStarts()
      const current = [...starts].reverse().find(start => start <= index) ?? 0
      const previous = [...starts].reverse().find(start => start < current)
      links[previous ?? starts[starts.length - 1] ?? 0]?.focus()
      break
    }
    case 'Escape': event.preventDefault(); close({ restoreFocus: true }); break
  }
}

function onFocusOut(event) {
  if (openKey.value && event.relatedTarget && !root.value?.contains(event.relatedTarget)) close()
}

function onDocumentPointer(event) {
  if (openKey.value && root.value && !root.value.contains(event.target)) close()
}

watch(openKey, (key, previous) => {
  if (key && !previous) document.addEventListener('pointerdown', onDocumentPointer)
  if (!key && previous) document.removeEventListener('pointerdown', onDocumentPointer)
})
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointer))

// A navigation (a link in the menu, back / forward) closes the menu
watch(() => route.fullPath, () => close())

// A group that disappears (permissions changed) cannot stay open
watch(groups, (list) => {
  if (openKey.value && !list.some(group => group.key === openKey.value)) close()
})

defineExpose({ open, close, openKey, compact })
</script>

<template>
  <nav ref="root" :aria-label="t('nav.main')" class="items-center min-w-0 flex-1" data-testid="main-nav" data-tour="main-nav"
    :data-compact="compact ? 'true' : 'false'" @focusout="onFocusOut">
    <!-- The full menu, never shown (clipped to nothing): its width decides whether the labels fit -->
    <div class="absolute top-0 left-0 w-0 h-0 overflow-hidden invisible pointer-events-none" aria-hidden="true">
      <ul ref="measure" data-testid="main-nav-measure" class="w-max flex items-center gap-0.5">
        <li class="flex items-center h-9 px-3 text-sm font-medium whitespace-nowrap">{{ t('ai.nav.projects') }}</li>
        <li v-for="group in groups" :key="`measure-${group.key}`" class="flex items-center gap-1 h-9 px-3 text-sm font-medium whitespace-nowrap">
          {{ t(group.labelKey) }}<span class="w-3.5 h-3.5"></span>
        </li>
      </ul>
    </div>

    <ul class="flex items-center gap-0.5" role="list">
      <li>
        <RouterLink
          to="/projects"
          data-nav-item
          data-testid="nav-projects"
          :aria-current="projectsActive ? 'page' : undefined"
          class="flex items-center h-9 px-3 rounded-md text-sm font-medium whitespace-nowrap transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          :class="projectsActive ? 'text-gray-100 bg-gray-800' : 'text-gray-400 hover:text-gray-100 hover:bg-gray-800/60'"
          @keydown="onItemKeydown($event)"
        >{{ t('ai.nav.projects') }}</RouterLink>
      </li>
      <li v-for="group in groups" :key="group.key">
        <button
          :id="`nav-trigger-${group.key}`"
          type="button"
          data-nav-item
          :data-nav-group="group.key"
          :data-testid="`nav-group-${group.key}`"
          :aria-expanded="openKey === group.key ? 'true' : 'false'"
          :aria-controls="openKey === group.key ? `nav-panel-${group.key}` : undefined"
          class="flex items-center gap-1 h-9 px-3 rounded-md text-sm font-medium whitespace-nowrap transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
          :class="openKey === group.key
            ? 'text-gray-100 bg-gray-800'
            : isGroupActive(group) ? 'text-brand-500 hover:bg-gray-800/60' : 'text-gray-400 hover:text-gray-100 hover:bg-gray-800/60'"
          :title="compact ? t(group.labelKey) : undefined"
          @click="toggle(group.key)"
          @keydown="onItemKeydown($event, group.key)"
          @pointerenter="hover(group.key)"
        >
          <component :is="group.icon" v-if="compact && group.icon" class="w-4 h-4" aria-hidden="true" data-testid="nav-group-icon" />
          <span :class="compact ? 'sr-only' : null">{{ t(group.labelKey) }}</span>
          <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="openKey === group.key && 'rotate-180'" aria-hidden="true" />
        </button>
      </li>
    </ul>

    <template v-for="group in groups" :key="`panel-${group.key}`">
      <MegaMenuPanel v-if="openKey === group.key" :group="group" @keydown="onPanelKeydown" />
    </template>
  </nav>
</template>
