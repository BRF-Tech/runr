<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Lock, FolderKanban } from 'lucide-vue-next'
import { useRbacLabels, expandPermissions } from '@/composables/useRbac'

/**
 * Permission matrix of a role: one column per permission group. Implied permissions (env.edit
 * → env.view …) are shown checked and locked while the permission implying them is selected.
 */
const props = defineProps({
  catalog:    { type: Array, required: true },   // [{ key, group, scope, implies }]
  modelValue: { type: Array, default: () => [] }, // explicitly selected permissions
  readonly:   { type: Boolean, default: false },
  allowed:    { type: Array, default: null },     // permissions the editor may hand out (null = all)
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
const { permLabel, permHint, groupLabel } = useRbacLabels()

const groups = computed(() => {
  const out = {}
  for (const p of props.catalog) (out[p.group] ||= []).push(p)
  return out
})

const effective = computed(() => new Set(expandPermissions(props.modelValue, props.catalog)))

function impliedBy(key) {
  if (props.modelValue.includes(key)) return null
  return props.modelValue.find(sel => expandPermissions([sel], props.catalog).includes(key)) || null
}

function isDisabled(p) {
  return props.readonly || !!impliedBy(p.key) || (props.allowed && !props.allowed.includes(p.key))
}

function toggle(key) {
  if (props.readonly) return
  const next = props.modelValue.includes(key)
    ? props.modelValue.filter(k => k !== key)
    : [...props.modelValue, key]
  emit('update:modelValue', next)
}
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="permission-matrix">
    <div v-for="(perms, group) in groups" :key="group" class="rounded-lg border border-gray-800 bg-gray-950/50 p-3">
      <p class="text-xs font-semibold text-gray-100 mb-2">{{ groupLabel(group) }}</p>
      <div class="space-y-1.5">
        <label v-for="p in perms" :key="p.key"
          class="flex items-start gap-2 group/perm"
          :class="isDisabled(p) ? 'cursor-default' : 'cursor-pointer'"
          :title="impliedBy(p.key) ? t('rbac.impliedBy', { permission: permLabel(impliedBy(p.key)) }) : permHint(p.key)"
          :data-permission="p.key">
          <input type="checkbox"
            class="mt-0.5 rounded-sm border-gray-600 bg-gray-800 text-brand-500 focus:ring-brand-500"
            :checked="effective.has(p.key)"
            :disabled="isDisabled(p)"
            @change="toggle(p.key)" />
          <span class="min-w-0">
            <span class="text-xs transition-colors flex items-center gap-1"
              :class="effective.has(p.key) ? 'text-gray-100' : 'text-gray-400 group-hover/perm:text-gray-100'">
              {{ permLabel(p.key) }}
              <Lock v-if="impliedBy(p.key)" class="w-3 h-3 text-gray-500" />
              <FolderKanban v-if="p.scope === 'project'" class="w-3 h-3 text-sky-500" :title="t('rbac.projectScopedHint')" />
            </span>
            <span class="block text-[10px] font-mono text-gray-500">{{ p.key }}</span>
          </span>
        </label>
      </div>
    </div>
  </div>
</template>
