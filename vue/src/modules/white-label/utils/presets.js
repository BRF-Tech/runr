// Colour presets of the branding editor. A preset fills the colour fields of the form:
// primary_color (the 500 base colour), accent_color, primary_scale (brand-300 … brand-900) and the
// tone scales of the two themes (dark_color / dark_scale, light_color / light_scale: --gray-50 …
// --gray-950, the page background is shade 950). Generic names only.
import { RUNR_DARK, RUNR_LIGHT, RUNR_PALETTE, SHADES, SURFACE_SHADES, rgbToHex } from './color'

const scale = (rgbByShade, shades = SHADES) => Object.fromEntries(shades.map(s => [s, rgbToHex(rgbByShade[s])]))
const surfaces = (rgbByShade) => scale(rgbByShade, SURFACE_SHADES)

/** The navy tones of the ocean preset's dark theme (the console's former navy theme). */
export const OCEAN_DARK = Object.freeze({
  950: [9, 27, 78],
  900: [15, 36, 96],
  800: [22, 46, 114],
  700: [30, 59, 133],
  600: [175, 195, 225],
  500: [205, 218, 240],
  400: [222, 232, 248],
  300: [235, 240, 250],
  200: [242, 245, 252],
  100: [248, 250, 254],
  50:  [252, 253, 255],
})

/** The cool tones of the ocean preset's light theme (the light theme next to the former navy one). */
export const OCEAN_LIGHT = Object.freeze({
  950: [255, 255, 255],
  900: [245, 247, 252],
  800: [230, 235, 245],
  700: [200, 210, 228],
  600: [55, 70, 105],
  500: [30, 45, 80],
  400: [18, 30, 62],
  300: [10, 22, 50],
  200: [6, 15, 40],
  100: [3, 10, 32],
  50:  [2, 6, 25],
})

export const BRANDING_PRESETS = Object.freeze([
  {
    key: 'runr',
    primary_color: '#f97316',
    accent_color: '#e63946',
    primary_scale: scale(RUNR_PALETTE),
    dark_color: rgbToHex(RUNR_DARK[950]),
    dark_scale: surfaces(RUNR_DARK),
    light_color: rgbToHex(RUNR_LIGHT[950]),
    light_scale: surfaces(RUNR_LIGHT),
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
    dark_color: rgbToHex(OCEAN_DARK[950]),
    dark_scale: surfaces(OCEAN_DARK),
    light_color: rgbToHex(OCEAN_LIGHT[950]),
    light_scale: surfaces(OCEAN_LIGHT),
  },
])

export function findPreset(key) {
  return BRANDING_PRESETS.find(p => p.key === key) ?? null
}
