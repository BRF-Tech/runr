<script setup>
// The runtime patch state of a project (ProjectResource runtime_patch) as a badge next to the EOL
// badges: a routine patch quietly, security / critical / zero-day in the colour of the level, an
// overdue deadline in red, a pin with its tag (warned when the pinned patch left the feed).
// Clicking it opens the RuntimePatchDialog (the parent decides, @open).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldAlert, Pin, ShieldCheck } from 'lucide-vue-next'
import { levelKey, patchBadge } from '@/utils/runtimePatches'

const props = defineProps({
  state: { type: Object, default: null },
  size:  { type: String, default: 'md' }, // md (header) | sm (project card)
})
defineEmits(['open'])

const { t } = useI18n()

const badge = computed(() => patchBadge(props.state))

const label = computed(() => {
  const b = badge.value
  if (!b) return ''
  if (b.kind === 'overdue') return t('runtimePatches.badge.overdue')
  if (b.kind === 'pinned') return t(b.warn ? 'runtimePatches.badge.pinnedGone' : 'runtimePatches.badge.pinned', { tag: b.tag })
  return t('runtimePatches.badge.available', { level: t(`runtimePatches.levels.${levelKey(b.level)}`) })
})

const title = computed(() => {
  const s = props.state
  if (!s) return ''
  const parts = [t('runtimePatches.badge.title')]
  if (s.latest?.tag) parts.push(`${t('runtimePatches.dialog.latest')}: ${s.latest.tag}`)
  if (s.deadline_at) parts.push(t('runtimePatches.dialog.deadline', { date: new Date(s.deadline_at).toLocaleString() }))
  return parts.join(' · ')
})

const sizeClass = computed(() => props.size === 'sm'
  ? 'text-[11px] px-2 py-0.5 leading-none'
  : 'text-xs px-2.5 py-1')
</script>

<template>
  <button
    v-if="badge"
    type="button"
    class="inline-flex items-center gap-1 font-medium rounded-md border transition-opacity hover:opacity-80"
    :class="[badge.classes, sizeClass, badge.subtle ? 'opacity-80' : '']"
    :title="title"
    :data-test="`runtime-patch-badge-${badge.kind}`"
    @click.stop="$emit('open')"
  >
    <Pin v-if="badge.kind === 'pinned'" class="w-3 h-3" />
    <ShieldCheck v-else-if="badge.subtle" class="w-3 h-3" />
    <ShieldAlert v-else class="w-3 h-3" />
    {{ label }}
  </button>
</template>
