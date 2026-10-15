<script setup>
import { computed } from 'vue'
import { VueMonacoEditor } from '@guolao/vue-monaco-editor'
import { runtimeLanguageFor } from '@/utils/runtimes'

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
  py: 'python', js: 'javascript', ts: 'typescript',
  go: 'go', php: 'php', rb: 'ruby', sh: 'shell', bash: 'shell',
  c: 'c', h: 'c', rs: 'rust', zig: 'plaintext',
  json: 'json', yaml: 'yaml', yml: 'yaml',
  html: 'html', css: 'css', md: 'markdown',
  txt: 'plaintext',
}
const editorLanguage = computed(() => {
  if (props.language) return props.language
  const fromRuntime = runtimeLanguageFor(props.runtime, props.filename)
  if (fromRuntime) return fromRuntime
  const name = props.filename.toLowerCase()
  if (name === 'dockerfile' || name.endsWith('.dockerfile')) return 'dockerfile'
  const ext = name.split('.').pop() ?? ''
  return EXT_MAP[ext] ?? 'plaintext'
})

const options = computed(() => ({
  theme: 'vs-dark',
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
    @change="emit('update:modelValue', $event)"
  />
</template>
