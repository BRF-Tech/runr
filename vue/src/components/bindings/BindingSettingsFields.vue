<script setup>
// Type-specific fields of a new binding (or of its limits): KV memory, bucket quota, Postgres
// storage / connection limit / statement timeout (+ instances / backups for a dedicated
// cluster), the key–value rows of an external binding. Limits come from GET /bindings/types.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { isValidEnvKey } from '@/utils/bindings'
import { Plus, X } from 'lucide-vue-next'

const props = defineProps({
  type:     { type: String, required: true },
  plan:     { type: String, default: 'shared' },
  types:    { type: Object, default: null },
  settings: { type: Object, default: () => ({}) },
  values:   { type: Array, default: () => [] },
  // Limits of an existing binding: no external values, dedicated storage only grows
  editing:  { type: Boolean, default: false },
})
const emit = defineEmits(['update:settings', 'update:values'])

const { t } = useI18n()

const max = computed(() => props.types?.[props.type]?.max ?? {})
const dedicated = computed(() => props.type === 'postgres' && props.plan === 'dedicated')

function set(key, value) {
  emit('update:settings', { ...props.settings, [key]: value })
}

function setRow(index, field, value) {
  const rows = props.values.map((r, i) => (i === index ? { ...r, [field]: field === 'key' ? String(value).toUpperCase() : value } : r))
  emit('update:values', rows)
}

function addRow() {
  emit('update:values', [...props.values, { key: '', value: '' }])
}

function removeRow(index) {
  emit('update:values', props.values.filter((_, i) => i !== index))
}
</script>

<template>
  <div class="space-y-3" data-binding-settings>
    <!-- KV -->
    <div v-if="type === 'kv'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="label">{{ t('bindings.settings.memory_mb') }}</label>
        <input type="number" min="8" :max="max.memory_mb" class="input font-mono text-xs" data-field="memory_mb"
          :value="settings.memory_mb" @input="set('memory_mb', Number($event.target.value))" />
      </div>
    </div>

    <!-- Object storage -->
    <div v-else-if="type === 'object'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="label">{{ t('bindings.settings.quota_gb') }}</label>
        <input type="number" min="1" :max="max.quota_gb" class="input font-mono text-xs" data-field="quota_gb"
          :value="settings.quota_gb" @input="set('quota_gb', Number($event.target.value))" />
      </div>
    </div>

    <!-- Postgres -->
    <div v-else-if="type === 'postgres'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <div>
        <label class="label">{{ t('bindings.settings.storage_gb') }}</label>
        <input type="number" min="1" :max="max.storage_gb" class="input font-mono text-xs" data-field="storage_gb"
          :value="settings.storage_gb" @input="set('storage_gb', Number($event.target.value))" />
      </div>
      <div>
        <label class="label">{{ t('bindings.settings.connection_limit') }}</label>
        <input type="number" min="1" :max="max.connection_limit" class="input font-mono text-xs" data-field="connection_limit"
          :value="settings.connection_limit" @input="set('connection_limit', Number($event.target.value))" />
      </div>
      <div>
        <label class="label">{{ t('bindings.settings.statement_timeout') }}</label>
        <input class="input font-mono text-xs" placeholder="30s" data-field="statement_timeout"
          :value="settings.statement_timeout" @input="set('statement_timeout', $event.target.value.trim())" />
        <p class="text-[11px] text-gray-500 mt-1">{{ t('bindings.settingsHint.statement_timeout') }}</p>
      </div>
      <template v-if="dedicated">
        <div>
          <label class="label">{{ t('bindings.settings.instances') }}</label>
          <input type="number" min="1" :max="max.instances" class="input font-mono text-xs" data-field="instances"
            :value="settings.instances" @input="set('instances', Number($event.target.value))" />
        </div>
        <div class="md:col-span-2 flex items-end">
          <label class="flex items-center gap-2 text-xs text-gray-300">
            <input type="checkbox" :checked="!!settings.backups" data-field="backups" @change="set('backups', $event.target.checked)" />
            {{ t('bindings.settings.backups') }}
          </label>
        </div>
      </template>
    </div>

    <!-- External: values injected as-is -->
    <div v-else-if="type === 'external' && !editing" class="space-y-2">
      <label class="label">{{ t('bindings.external.values') }}</label>
      <div v-for="(row, i) in values" :key="i" class="flex items-start gap-2" data-external-row>
        <div class="flex-1">
          <input class="input font-mono text-xs" :placeholder="t('bindings.external.key')" :value="row.key" data-field="external_key"
            @input="setRow(i, 'key', $event.target.value)" />
          <p v-if="row.key && !isValidEnvKey(row.key)" class="text-[11px] text-red-400 mt-1">{{ t('bindings.external.keyInvalid') }}</p>
        </div>
        <input class="input font-mono text-xs flex-2" type="password" autocomplete="new-password" :placeholder="t('bindings.external.value')"
          :value="row.value" data-field="external_value" @input="setRow(i, 'value', $event.target.value)" />
        <button type="button" class="p-2 text-gray-400 hover:text-red-400" :title="t('common.delete')" @click="removeRow(i)">
          <X class="w-3.5 h-3.5" />
        </button>
      </div>
      <button type="button" class="text-xs text-sky-400 hover:text-sky-300 flex items-center gap-1" data-action="add-value" @click="addRow">
        <Plus class="w-3 h-3" /> {{ t('bindings.external.addValue') }}
      </button>
    </div>
  </div>
</template>
