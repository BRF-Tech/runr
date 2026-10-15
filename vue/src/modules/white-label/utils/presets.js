// Colour presets of the branding editor. A preset fills the colour fields of the form
// (primary_color = the 500 base colour, accent_color, primary_scale = brand-300 … brand-900).
import { RUNR_PALETTE, SHADES, rgbToHex } from './color'

const scale = (rgbByShade) => Object.fromEntries(SHADES.map(s => [s, rgbToHex(rgbByShade[s])]))

export const BRANDING_PRESETS = Object.freeze([
  {
    key: 'runr',
    primary_color: '#f97316',
    accent_color: '#e63946',
    primary_scale: scale(RUNR_PALETTE),
  },
  {
    key: 'ocean',
    primary_color: '#009fe3',
    accent_color: '#009fe3',
    primary_scale: scale({
      300: [102, 197, 237],
      400: [51, 181, 233],
      500: [0, 159, 227],
      600: [0, 123, 192],
      700: [0, 85, 184],
      800: [0, 68, 153],
      900: [0, 43, 128],
    }),
  },
])

export function findPreset(key) {
  return BRANDING_PRESETS.find(p => p.key === key) ?? null
}
