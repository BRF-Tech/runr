// Brand colour for consumers that cannot read CSS variables themselves (xterm themes, canvas,
// chart libraries): resolves --brand-<shade> (an "r g b" triplet set by the white-label
// branding, see assets/main.css) at call time and returns an rgb()/rgba() string.
const FALLBACK = '249 115 22' // RunR 500, only used when no stylesheet is loaded (tests)

export function brandColor(shade = 500, alpha = 1) {
  const [r, g, b] = (cssTriplet(`--brand-${shade}`) || FALLBACK).split(/[\s,]+/).map(Number)
  return alpha >= 1 ? `rgb(${r}, ${g}, ${b})` : `rgba(${r}, ${g}, ${b}, ${alpha})`
}

/**
 * A tone of the dark or light theme (--surface-dark-<shade> / --surface-light-<shade>, the
 * white-label theme tones, see assets/main.css) as rgb()/rgba(), whatever theme is shown: the
 * terminal stays dark on the light theme too.
 */
export function surfaceColor(theme = 'dark', shade = 950, alpha = 1) {
  const fallback = theme === 'light' ? '255 252 248' : '10 10 10'
  const [r, g, b] = (cssTriplet(`--surface-${theme}-${shade}`) || fallback).split(/[\s,]+/).map(Number)
  return alpha >= 1 ? `rgb(${r}, ${g}, ${b})` : `rgba(${r}, ${g}, ${b}, ${alpha})`
}

/**
 * "#rrggbb" (or "#rrggbbaa" with alpha < 1) of a brand shade or a theme tone, for libraries that
 * take hex only (Monaco's themes). `name` is the CSS variable without "--": "brand-500",
 * "surface-dark-950".
 */
export function cssHex(name, alpha = 1, fallback = '249 115 22') {
  const rgb = (cssTriplet(`--${name}`) || fallback).split(/[\s,]+/).map(Number)
  const hex = '#' + rgb.map(v => Math.max(0, Math.min(255, v | 0)).toString(16).padStart(2, '0')).join('')
  return alpha >= 1 ? hex : hex + Math.round(alpha * 255).toString(16).padStart(2, '0')
}

function cssTriplet(name) {
  try {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  } catch {
    return ''
  }
}
