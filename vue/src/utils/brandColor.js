// Brand colour for consumers that cannot read CSS variables themselves (xterm themes, canvas,
// chart libraries): resolves --brand-<shade> (an "r g b" triplet set by the white-label
// branding, see assets/main.css) at call time and returns an rgb()/rgba() string.
const FALLBACK = '249 115 22' // RunR 500, only used when no stylesheet is loaded (tests)

export function brandColor(shade = 500, alpha = 1) {
  const [r, g, b] = (cssTriplet(`--brand-${shade}`) || FALLBACK).split(/[\s,]+/).map(Number)
  return alpha >= 1 ? `rgb(${r}, ${g}, ${b})` : `rgba(${r}, ${g}, ${b}, ${alpha})`
}

function cssTriplet(name) {
  try {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  } catch {
    return ''
  }
}
