import pythonIcon from '@/assets/runtimes/python.svg'
import nodejsIcon from '@/assets/runtimes/nodejs.svg'
import goIcon from '@/assets/runtimes/go.svg'
import phpIcon from '@/assets/runtimes/php.svg'
import dockerIcon from '@/assets/runtimes/docker.svg'
import tensorflowIcon from '@/assets/runtimes/tensorflow.svg'
import pytorchIcon from '@/assets/runtimes/pytorch.svg'
import { useRuntimesStore, BUILTIN_RUNTIME_NAMES } from '@/stores/runtimes'

export const runtimeIcons = {
  python: pythonIcon,
  nodejs: nodejsIcon,
  go: goIcon,
  php: phpIcon,
  docker: dockerIcon,
  tensorflow: tensorflowIcon,
  pytorch: pytorchIcon,
}

export const runtimeLabels = BUILTIN_RUNTIME_NAMES

/** Handler file extension of the built-in runtimes (used until the catalog is loaded). */
export const BUILTIN_EXTENSIONS = {
  python: 'py', nodejs: 'js', go: 'go', php: 'php', tensorflow: 'py', pytorch: 'py',
}

/** Handler checks of the built-in runtimes (used until the catalog is loaded). */
export const BUILTIN_HANDLER_PATTERNS = {
  python: /def\s+handler\s*\(/,
  nodejs: /(?:exports\.handler|module\.exports\.handler|function\s+handler|const\s+handler)/,
  go:     /func\s+Handler\s*\(/,
  php:    /function\s+handler\s*\(/,
}

function catalogEntry(runtime) {
  if (!runtime) return null
  try {
    return useRuntimesStore().byId(runtime)
  } catch {
    return null // pinia not active (e.g. outside the app)
  }
}

/** A manifest icon is shown only as an image URL or an image data URI. */
function safeIcon(icon) {
  if (typeof icon !== 'string') return null
  const s = icon.trim()
  return /^(https?:\/\/|data:image\/)/i.test(s) ? s : null
}

export function runtimeIcon(runtime) {
  return safeIcon(catalogEntry(runtime)?.icon) ?? runtimeIcons[runtime] ?? null
}

export function runtimeLabel(runtime) {
  return catalogEntry(runtime)?.name ?? runtimeLabels[runtime] ?? runtime
}

/** Handler file extension: the manifest's first extension, else the built-in one. */
export function runtimeExtension(runtime) {
  return catalogEntry(runtime)?.extensions?.[0] ?? BUILTIN_EXTENSIONS[runtime] ?? null
}

function escapeRegExp(s) {
  return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Handler check of a runtime as a RegExp: the manifest pattern with "{handler}" replaced by the
 * escaped handler name; the built-in pattern while the catalog is loading; null = no check.
 */
export function runtimeHandlerPattern(runtime, handlerName = 'handler') {
  const entry = catalogEntry(runtime)
  const pattern = entry?.handler?.pattern
  if (pattern) {
    try {
      return new RegExp(String(pattern).replaceAll('{handler}', escapeRegExp(handlerName || 'handler')))
    } catch {
      return null // a server-side (PCRE) pattern JavaScript can not compile: the API still checks it
    }
  }
  if (entry && entry.source !== 'builtin') return null
  return BUILTIN_HANDLER_PATTERNS[runtime] ?? null
}

/** Monaco language of a file: the runtime's manifest language for its own extensions. */
export function runtimeLanguageFor(runtime, filename) {
  const entry = catalogEntry(runtime)
  if (!entry || !filename) return null
  const ext = filename.split('.').pop()?.toLowerCase() ?? ''
  return entry.extensions?.includes(ext) ? (entry.language || 'plaintext') : null
}
