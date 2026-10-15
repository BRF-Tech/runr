<script setup>
// Client configuration for one MCP endpoint: Claude Code, Claude Desktop, Cursor, VS Code and
// any other Streamable HTTP client.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { clientSnippets } from '../utils/snippets'
import CopyBlock from './CopyBlock.vue'

const props = defineProps({
  url:   { type: String, required: true },
  name:  { type: String, default: 'runr' },
  auth:  { type: String, default: 'oauth' },   // oauth | token | public
  token: { type: String, default: null },
})

const { t } = useI18n()
const snippets = computed(() => clientSnippets(props.url, { name: props.name, auth: props.auth, token: props.token }))
const active = ref('claudeCode')
const current = computed(() => snippets.value.find(s => s.id === active.value) || snippets.value[0])
</script>

<template>
  <div data-testid="mcp-snippets">
    <div class="flex flex-wrap gap-1.5 mb-2">
      <button
        v-for="s in snippets" :key="s.id" type="button"
        class="px-2.5 py-1 rounded-md text-[11px] font-medium border transition-colors"
        :class="active === s.id ? 'border-brand-500/50 text-brand-500 bg-brand-500/10' : 'border-gray-700 text-gray-400 hover:text-gray-100'"
        :data-testid="`snippet-${s.id}`"
        @click="active = s.id"
      >{{ t(`mcp.snippets.client.${s.id}`) }}</button>
    </div>
    <p class="text-[11px] text-gray-400 mb-2">{{ t(`mcp.snippets.hint.${current.id}`) }}</p>
    <CopyBlock :text="current.code" />
  </div>
</template>
