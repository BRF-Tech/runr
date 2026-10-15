<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRbacLabels } from '@/composables/useRbac'

/** Role picker: built-in roles first, then custom ones; roles the user cannot grant are disabled. */
const props = defineProps({
  modelValue:   { type: String, default: null },
  roles:        { type: Array, required: true },   // GET /roles → roles
  includeOwner: { type: Boolean, default: false },
  allowEmpty:   { type: Boolean, default: false },
  emptyLabel:   { type: String, default: null },
  disabled:     { type: Boolean, default: false },
  size:         { type: String, default: 'sm' },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
const { roleLabel } = useRbacLabels()

const options = computed(() => props.roles.filter(r => props.includeOwner || r.key !== 'owner' || r.key === props.modelValue))
</script>

<template>
  <select class="input" :class="size === 'xs' ? 'text-xs py-1' : 'text-sm'"
    :value="modelValue ?? ''" :disabled="disabled" data-testid="role-select"
    @change="emit('update:modelValue', $event.target.value || null)">
    <option v-if="allowEmpty" value="">{{ emptyLabel ?? t('rbac.noRole') }}</option>
    <option v-for="r in options" :key="r.key" :value="r.key"
      :disabled="r.grantable === false && r.key !== modelValue"
      :title="r.grantable === false ? t('rbac.cannotGrant') : ''">
      {{ roleLabel(r) }}{{ r.builtin ? '' : ` (${t('rbac.custom')})` }}
    </option>
  </select>
</template>
