<script setup>
import { computed } from 'vue'
import { VueMonacoEditor } from '@guolao/vue-monaco-editor'
import { runtimeLanguageFor } from '@/utils/runtimes'
import { cssHex } from '@/utils/brandColor'

const props = defineProps({
  modelValue: { type: String, default: '' },
  filename:   { type: String, default: '' },
  height:     { type: String, default: '58vh' },
  readonly:   { type: Boolean, default: false },
  // Project runtime: files with one of its manifest extensions use the manifest language
  runtime:    { type: String, default: null },
  // Explicit Monaco language (wins over runtime and file extension)
  language:   { type: String, default: null },
})
const emit = defineEmits(['update:modelValue'])

const EXT_MAP = {
  py: 'python', js: 'javascript', mjs: 'javascript', cjs: 'javascript', ts: 'typescript', tsx: 'typescript',
  go: 'go', php: 'php', rb: 'ruby', sh: 'shell', bash: 'shell',
  java: 'java', cs: 'csharp',
  c: 'c', h: 'c', rs: 'rust', zig: 'plaintext',
  json: 'json', yaml: 'yaml', yml: 'yaml', xml: 'xml', csproj: 'xml',
  html: 'html', css: 'css', md: 'markdown',
  txt: 'plaintext',
}
const editorLanguage = computed(() => {
  if (props.language) return props.language
  const fromRuntime = runtimeLanguageFor(props.runtime, props.filename)
  if (fromRuntime) return fromRuntime
  const name = props.filename.toLowerCase()
  if (name === 'dockerfile' || name.endsWith('.dockerfile')) return 'dockerfile'
  if (name === 'gemfile') return 'ruby'
  const ext = name.split('.').pop() ?? ''
  return EXT_MAP[ext] ?? 'plaintext'
})

// Monaco's dark theme with the console's UI accents: the dark theme's page behind the code, the
// brand for the cursor, the selection, the focus border and the active line number (vs-dark's are
// blue). The syntax colours stay vs-dark's: they tell code apart, they are not accents.
const THEME = 'runr-dark'

function defineTheme(monaco) {
  monaco.editor.defineTheme(THEME, {
    base: 'vs-dark',
    inherit: true,
    rules: [],
    colors: {
      'editor.background':                cssHex('surface-dark-950', 1, '10 10 10'),
      'editorGutter.background':          cssHex('surface-dark-950', 1, '10 10 10'),
      'editor.lineHighlightBackground':   cssHex('surface-dark-900', 1, '23 23 23'),
      'editorLineNumber.foreground':      cssHex('surface-dark-700', 1, '64 64 64'),
      'editorLineNumber.activeForeground': cssHex('brand-300', 1, '253 186 116'),
      'editorCursor.foreground':          cssHex('brand-400', 1, '251 146 60'),
      'editor.selectionBackground':       cssHex('brand-700', 0.45, '194 65 12'),
      'editor.inactiveSelectionBackground': cssHex('brand-700', 0.25, '194 65 12'),
      'editor.wordHighlightBackground':   cssHex('brand-800', 0.4, '154 52 18'),
      'editor.findMatchBackground':       cssHex('brand-600', 0.55, '234 88 12'),
      'editor.findMatchHighlightBackground': cssHex('brand-800', 0.45, '154 52 18'),
      'editorBracketMatch.border':        cssHex('brand-500', 1, '249 115 22'),
      'focusBorder':                      cssHex('brand-500', 1, '249 115 22'),
      'editorWidget.border':              cssHex('surface-dark-700', 1, '64 64 64'),
      'scrollbarSlider.background':       cssHex('surface-dark-700', 0.5, '64 64 64'),
    },
  })
}

const options = computed(() => ({
  theme: THEME,
  fontSize: 13,
  fontFamily: '"JetBrains Mono", "Fira Code", monospace',
  minimap: { enabled: false },
  scrollBeyondLastLine: false,
  automaticLayout: true,
  lineNumbers: 'on',
  renderWhitespace: 'selection',
  tabSize: 2,
  readOnly: props.readonly,
}))
</script>

<template>
  <VueMonacoEditor
    :value="modelValue"
    :language="editorLanguage"
    :options="options"
    :height="height"
    @before-mount="defineTheme"
    @change="emit('update:modelValue', $event)"
  />
</template>
