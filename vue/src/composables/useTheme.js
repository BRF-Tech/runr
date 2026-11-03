import { computed, ref, watch } from 'vue'

// The console has two themes: dark (key "fulldark") and light. Their colours are the branding's
// (white-label: RunR's black dark theme and warm light theme unless re-branded). A user who
// picks a theme in the user menu keeps it (localStorage `theme`, marked as picked by
// `theme_chosen`); everyone else sees the branding's default theme (GET /api/branding
// default_theme, applied by the white-label module through setBrandingTheme()).
//
// The pre-paint script of index.html makes the same choice before the first paint (the cached
// branding's default theme), keep the two in step. The former navy theme (key "dark") is gone;
// a stored "fulldark" or "dark" without the marker was written by earlier versions on every
// visit, not picked, so it follows the branding too. A stored "light" was always picked.
const THEMES = ['fulldark', 'light']
const DEFAULT_THEME = 'fulldark'
const STORAGE_KEY = 'theme'
const CHOSEN_KEY = 'theme_chosen'

/** Console theme key of a branding default_theme ("dark" | "light"). */
export function themeKeyOf(brandingTheme) {
  return brandingTheme === 'light' ? 'light' : DEFAULT_THEME
}

function storedChoice() {
  try {
    const theme = localStorage.getItem(STORAGE_KEY)
    if (theme === 'light') return 'light'
    if (theme === 'fulldark' && localStorage.getItem(CHOSEN_KEY)) return 'fulldark'
  } catch { /* storage unavailable */ }
  return null
}

function documentTheme() {
  const theme = typeof document !== 'undefined' ? document.documentElement.dataset.theme : null
  return THEMES.includes(theme) ? theme : DEFAULT_THEME
}

const choice = ref(storedChoice())
// Until the branding is known: what the pre-paint script chose from the cached branding
const brandingTheme = ref(choice.value ? DEFAULT_THEME : documentTheme())
const current = computed(() => choice.value ?? brandingTheme.value)

function apply(theme) {
  document.documentElement.dataset.theme = theme
}

function persist(theme) {
  try {
    localStorage.setItem(STORAGE_KEY, theme)
    localStorage.setItem(CHOSEN_KEY, '1')
  } catch { /* storage unavailable: the pick lasts this visit */ }
}

// Apply on first import
apply(current.value)

watch(current, apply)

/** The branding's default theme ("dark" | "light"): shown while the user has not picked one. */
export function setBrandingTheme(brandingDefault) {
  brandingTheme.value = themeKeyOf(brandingDefault)
}

export function useTheme() {
  function set(theme) {
    if (!THEMES.includes(theme)) return
    choice.value = theme
    persist(theme)
  }

  function cycle() {
    const idx = THEMES.indexOf(current.value)
    set(THEMES[(idx + 1) % THEMES.length])
  }

  return { current, themes: THEMES, cycle, set, picked: computed(() => choice.value !== null) }
}
