<script setup>
// Settings of a trigger type the console has no dedicated form for, built from the type's
// `fields` (GET /trigger-types; labels and help come translated from the API). Secret fields are
// write-only: they show whether the trigger has the value and are never prefilled.
import { useI18n } from 'vue-i18n'
import { secretIsSet } from '../utils/triggers'

defineProps({
  fields:  { type: Array, default: () => [] },
  values:  { type: Object, required: true },   // form.values: field key → input value (mutated)
  secrets: { type: Array, default: () => [] }, // names of the stored trigger's set secrets
  errors:  { type: Object, default: () => ({}) },
})

const { t } = useI18n()

const errorOf = (errors, f) => errors[f.secret ? `secrets.${f.key}` : `config.${f.key}`]
const placeholder = (f) => (f.default !== null && f.default !== undefined && typeof f.default !== 'object' ? String(f.default) : '')
</script>

<template>
  <div class="space-y-4" data-test="generic-fields">
    <p v-if="!fields.length" class="text-xs text-gray-400">{{ t('triggers.generic.noFields') }}</p>

    <div v-for="f in fields" :key="f.key" :data-generic-field="f.key">
      <!-- boolean: a checkbox with the label next to it -->
      <label v-if="f.type === 'boolean'" class="flex items-center gap-2 text-sm text-gray-200">
        <input v-model="values[f.key]" type="checkbox" :data-field="f.key" />{{ f.label }}
      </label>

      <template v-else>
        <label class="label">
          {{ f.label }}<span v-if="f.required" class="normal-case text-red-500/70"> *</span>
          <span
            v-if="f.secret"
            class="normal-case ml-1 px-1.5 py-0.5 rounded-sm text-[10px] border"
            :class="secretIsSet(secrets, f.key) ? 'border-emerald-800/50 bg-emerald-900/30 text-emerald-300' : 'border-gray-700 bg-gray-800 text-gray-400'"
            data-test="secret-state"
          >{{ secretIsSet(secrets, f.key) ? t('triggers.webhook.secretSet') : t('triggers.webhook.secretNotSet') }}</span>
        </label>

        <input
          v-if="f.secret" v-model="values[f.key]" type="password" autocomplete="new-password" class="input font-mono text-xs"
          :placeholder="secretIsSet(secrets, f.key) ? t('triggers.generic.secretKeep') : ''" :data-field="f.key"
        />
        <select v-else-if="f.type === 'select'" v-model="values[f.key]" class="input text-xs" :data-field="f.key">
          <option value="">{{ t('triggers.generic.choose') }}</option>
          <option v-for="o in f.options ?? []" :key="o" :value="String(o)">{{ o }}</option>
        </select>
        <textarea
          v-else-if="['text', 'list', 'json'].includes(f.type)" v-model="values[f.key]" :rows="f.type === 'json' ? 6 : 3" spellcheck="false"
          class="input font-mono text-xs resize-y" :placeholder="placeholder(f)" :data-field="f.key"
        />
        <input
          v-else v-model="values[f.key]" :type="f.type === 'integer' ? 'number' : 'text'" class="input font-mono text-xs"
          :placeholder="placeholder(f)" :data-field="f.key"
        />
      </template>

      <p v-if="errorOf(errors, f)" class="text-[11px] text-red-400 mt-1">{{ errorOf(errors, f) }}</p>
      <template v-else>
        <p v-if="f.help" class="text-[11px] text-gray-400 mt-1">{{ f.help }}</p>
        <p v-if="f.secret" class="text-[11px] text-gray-400 mt-1">{{ t('triggers.generic.secretHint') }}</p>
        <p v-else-if="f.type === 'list'" class="text-[11px] text-gray-400 mt-1">{{ t('triggers.generic.listHint') }}</p>
        <p v-else-if="f.type === 'duration'" class="text-[11px] text-gray-400 mt-1">{{ t('triggers.generic.durationHint') }}</p>
        <p v-else-if="f.type === 'json'" class="text-[11px] text-gray-400 mt-1">{{ t('triggers.generic.jsonHint') }}</p>
      </template>
    </div>
  </div>
</template>
