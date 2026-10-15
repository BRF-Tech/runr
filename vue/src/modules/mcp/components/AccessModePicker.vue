<script setup>
// Who may call a hosted MCP server: anyone (public), holders of a RunR API token, or clients that
// sign in through RunR's OAuth authorization server.
import { useI18n } from 'vue-i18n'
import { Globe, KeyRound, ShieldCheck } from 'lucide-vue-next'

defineProps({
  modelValue: { type: String, default: 'token' },
  disabled:   { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const modes = [
  { id: 'oauth',  icon: ShieldCheck },
  { id: 'token',  icon: KeyRound },
  { id: 'public', icon: Globe },
]
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-2" data-testid="mcp-access-mode">
    <button
      v-for="m in modes" :key="m.id" type="button"
      class="text-left rounded-lg border px-3 py-2.5 transition-colors"
      :class="modelValue === m.id
        ? 'border-brand-500/60 bg-brand-500/10'
        : 'border-gray-700 bg-gray-900/40 hover:border-gray-600'"
      :disabled="disabled"
      :data-testid="`mcp-access-${m.id}`"
      @click="emit('update:modelValue', m.id)"
    >
      <div class="flex items-center gap-2 text-sm font-medium" :class="modelValue === m.id ? 'text-brand-500' : 'text-gray-100'">
        <component :is="m.icon" class="w-4 h-4" />{{ t(`mcp.access.${m.id}`) }}
      </div>
      <p class="text-[11px] text-gray-400 mt-1">{{ t(`mcp.access.${m.id}Hint`) }}</p>
    </button>
  </div>
</template>
