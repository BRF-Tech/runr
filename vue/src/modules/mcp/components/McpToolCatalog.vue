<script setup>
// The tools of the RunR MCP server with the permissions each needs: a client sees a tool only
// when its token holds them (GET /api/mcp/tools, "allowed" for the signed-in user).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRbacLabels } from '@/composables/useRbac'
import { mcpApi } from '../api/mcp'
import { Lock, Search } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'

const { t, te } = useI18n()
const { permLabel } = useRbacLabels()

const tools = ref([])
const groupOrder = ref([])
const loading = ref(true)
const error = ref(null)
const filter = ref('')

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await mcpApi.tools()
    tools.value = data.data ?? []
    groupOrder.value = data.groups ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const q = filter.value.trim().toLowerCase()
  if (!q) return tools.value
  return tools.value.filter(tool =>
    [tool.name, tool.title, tool.description, ...(tool.permissions || [])].some(v => String(v || '').toLowerCase().includes(q)))
})

const grouped = computed(() => {
  const out = new Map()
  const order = [...groupOrder.value, ...new Set(filtered.value.map(x => x.group))]
  for (const g of order) if (!out.has(g)) out.set(g, [])
  for (const tool of filtered.value) out.get(tool.group)?.push(tool)
  return [...out.entries()].filter(([, list]) => list.length)
})

const allowedCount = computed(() => tools.value.filter(x => x.allowed).length)

// The group's heading key; a group without one shows its (English) name
function groupKey(group) {
  const key = `mcp.tools.group.${group}`
  return te(key) ? key : null
}

// "a|b" — either permission; "@platform:a" — a platform administrator whose token carries a
const PLATFORM_PREFIX = '@platform:'
function requirementLabel(requirement) {
  const value = String(requirement)
  if (value.startsWith(PLATFORM_PREFIX)) {
    return t('mcp.tools.platformAdmin', { permissions: requirementLabel(value.slice(PLATFORM_PREFIX.length)) })
  }
  return value.split('|').map(permLabel).join(` ${t('mcp.tools.or')} `)
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4" data-testid="mcp-tools">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <div>
        <p class="text-sm font-semibold text-gray-100">{{ t('mcp.tools.title') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.tools.hint', { allowed: allowedCount, total: tools.length }) }}</p>
      </div>
      <div class="relative">
        <Search class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
        <input v-model="filter" class="input pl-8 w-56" :placeholder="t('mcp.tools.filter')" data-testid="mcp-tools-filter" />
      </div>
    </div>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>
    <p v-else-if="grouped.length === 0" class="text-xs text-gray-400 italic">{{ t('mcp.tools.none') }}</p>

    <div v-else class="space-y-4">
      <div v-for="[group, list] in grouped" :key="group">
        <p class="text-[11px] uppercase tracking-wider font-medium text-gray-400 mb-1.5"><TermText v-if="groupKey(group)" :path="groupKey(group)" /><span v-else lang="en">{{ group }}</span></p>
        <div class="space-y-1.5">
          <div v-for="tool in list" :key="tool.name"
            class="rounded-lg border px-3 py-2"
            :class="tool.allowed ? 'border-gray-800 bg-gray-950/40' : 'border-gray-800/60 bg-gray-950/20 opacity-60'"
            :data-testid="`mcp-tool-${tool.name}`"
            :data-allowed="tool.allowed ? 'true' : 'false'">
            <div class="flex flex-wrap items-center gap-2">
              <Lock v-if="!tool.allowed" class="w-3.5 h-3.5 text-gray-400" :title="t('mcp.tools.locked')" />
              <code class="text-xs font-mono text-gray-100">{{ tool.name }}</code>
              <span class="text-xs text-gray-400">{{ tool.title }}</span>
              <span v-if="tool.annotations?.readOnlyHint" class="text-[10px] font-medium px-1.5 py-0.5 rounded-sm border bg-emerald-900/30 border-emerald-800/40 text-emerald-400">{{ t('mcp.tools.readOnly') }}</span>
              <span v-if="tool.annotations?.destructiveHint" class="text-[10px] font-medium px-1.5 py-0.5 rounded-sm border bg-red-900/30 border-red-800/40 text-red-400">{{ t('mcp.tools.destructive') }}</span>
              <span v-if="tool.streams" class="text-[10px] font-medium px-1.5 py-0.5 rounded-sm border bg-sky-900/30 border-sky-800/40 text-sky-400">{{ t('mcp.tools.streams') }}</span>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">{{ tool.description }}</p>
            <div class="flex flex-wrap gap-1 mt-1.5">
              <span v-if="!(tool.permissions || []).length" class="text-[10px] px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800 text-gray-400">{{ t('mcp.tools.everyone') }}</span>
              <span v-for="p in tool.permissions || []" :key="p"
                class="text-[10px] px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800 text-gray-400">{{ requirementLabel(p) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
