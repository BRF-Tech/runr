<script setup>
// Scope of an API token: full access ('*') or a selection of catalog permissions, grouped like
// the permission catalog (docs/permissions.md). Used by Administration → API Tokens, the MCP
// token form and the OAuth consent page.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRbacLabels } from '@/composables/useRbac'
import { Shield, ShieldCheck } from 'lucide-vue-next'

const props = defineProps({
  // [{ key, group }] (GET /tokens available_abilities) or plain keys
  abilities:       { type: Array, required: true },
  modelValue:      { type: Array, default: () => [] },
  fullAccess:      { type: Boolean, default: true },
  allowFullAccess: { type: Boolean, default: true },
})
const emit = defineEmits(['update:modelValue', 'update:fullAccess'])

const { t } = useI18n()
const { permLabel, groupLabel } = useRbacLabels()

const groups = computed(() => {
  const out = {}
  for (const ab of props.abilities) {
    const key = ab?.key ?? ab
    const group = ab?.group || 'projects'
    if (!out[group]) out[group] = []
    out[group].push(key)
  }
  return out
})

function toggle(key) {
  const selected = [...props.modelValue]
  const idx = selected.indexOf(key)
  if (idx === -1) selected.push(key)
  else selected.splice(idx, 1)
  emit('update:modelValue', selected)
}
</script>

<template>
  <div>
    <div v-if="allowFullAccess" class="flex gap-3 mb-3">
      <button type="button" data-tour="tokens-full-access"
        class="flex items-center gap-2 px-3 py-2 rounded-lg border text-xs font-medium transition-all cursor-pointer"
        :class="fullAccess ? 'border-emerald-800/50 text-emerald-400' : 'border-gray-700 text-gray-400 hover:border-gray-600'"
        :style="fullAccess ? 'background:rgb(16 185 129/0.1)' : ''"
        @click="emit('update:fullAccess', true)"
      >
        <ShieldCheck class="w-4 h-4" />
        {{ t('tokens.fullAccess') }}
      </button>
      <button type="button" data-tour="tokens-custom-access"
        class="flex items-center gap-2 px-3 py-2 rounded-lg border text-xs font-medium transition-all cursor-pointer"
        :class="!fullAccess ? 'border-brand-500/50 text-brand-500' : 'border-gray-700 text-gray-400 hover:border-gray-600'"
        :style="!fullAccess ? 'background:rgb(var(--brand-500)/0.1)' : ''"
        @click="emit('update:fullAccess', false)"
      >
        <Shield class="w-4 h-4" />
        {{ t('tokens.customAccess') }}
      </button>
    </div>

    <p class="text-[11px] text-gray-400 mb-2">{{ t('rbac.tokenScopesHint') }}</p>
    <div v-if="!fullAccess || !allowFullAccess" class="grid grid-cols-1 sm:grid-cols-3 gap-3" data-testid="token-scopes">
      <div v-for="(keys, group) in groups" :key="group"
        class="rounded-lg border border-gray-800 bg-gray-950/50 p-3">
        <p class="text-xs font-semibold text-gray-100 mb-2">{{ groupLabel(group) }}</p>
        <div class="space-y-1.5">
          <label v-for="key in keys" :key="key"
            class="flex items-center gap-2 cursor-pointer group/ab" :data-ability="key">
            <input type="checkbox"
              :checked="modelValue.includes(key)"
              class="rounded-sm border-gray-600 bg-gray-800 text-brand-500 focus:ring-brand-500"
              @change="toggle(key)" />
            <span class="text-xs text-gray-400 group-hover/ab:text-gray-100 transition-colors">{{ permLabel(key) }}</span>
          </label>
        </div>
      </div>
    </div>
  </div>
</template>
