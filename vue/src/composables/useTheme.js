import { ref, watch } from 'vue'

const THEMES = ['dark', 'fulldark', 'light']
const STORAGE_KEY = 'theme'

function detectDefault() {
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
  return prefersDark ? 'fulldark' : 'dark'
}

const stored = localStorage.getItem(STORAGE_KEY)
const current = ref(stored || detectDefault())

function apply(theme) {
  document.documentElement.dataset.theme = theme
  localStorage.setItem(STORAGE_KEY, theme)
}

// Apply on first import
apply(current.value)

watch(current, apply)

export function useTheme() {
  function cycle() {
    const idx = THEMES.indexOf(current.value)
    current.value = THEMES[(idx + 1) % THEMES.length]
  }

  function set(theme) {
    if (THEMES.includes(theme)) {
      current.value = theme
    }
  }

  return { current, themes: THEMES, cycle, set }
}
