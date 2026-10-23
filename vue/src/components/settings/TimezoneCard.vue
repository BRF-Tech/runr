<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Clock } from 'lucide-vue-next'
import { settingsApi } from '@/api/settings'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { DEFAULT_TIMEZONE, timeZoneOptions } from '@/utils/warmSchedule'

/**
 * The organization's time zone (Settings, the install's or an organization's): the timed warmers'
 * crons run in it. GET /settings carries the effective zone (timezone, Europe/Istanbul when none
 * is set); PATCH /settings { timezone } sets it (settings.manage), "" goes back to the default.
 * Saved on its own, so it works in the central and the organization view alike.
 */
const { t } = useI18n()
const settings = useSettingsStore()
const perms = usePermissionsStore()

const zone   = ref('')
const saving = ref(false)
const saved  = ref(false)
const error  = ref(null)

const zones     = timeZoneOptions()
const current   = computed(() => settings.data?.timezone || DEFAULT_TIMEZONE)
const canChange = computed(() => perms.can('settings.manage'))
const dirty     = computed(() => zone.value.trim() !== current.value)
const known     = computed(() => !zone.value.trim() || zones.includes(zone.value.trim()))

watch(current, (value) => { zone.value = value }, { immediate: true })

const localTime = computed(() => {
  try {
    return new Intl.DateTimeFormat(undefined, { timeZone: zone.value.trim() || DEFAULT_TIMEZONE, timeStyle: 'short' }).format(new Date())
  } catch {
    return null
  }
})

async function save(value) {
  error.value = null; saved.value = false; saving.value = true
  try {
    const { data } = await settingsApi.update({ timezone: value || null })
    settings.update(data)
    zone.value = data.timezone || DEFAULT_TIMEZONE
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch (e) {
    error.value = e.response?.data?.errors?.timezone?.[0] ?? e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="card" data-testid="timezone-card">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Clock class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.timezone.section') }}</h3>
    </div>
    <p class="text-xs text-gray-400 mb-3">{{ t('settings.timezone.description', { zone: DEFAULT_TIMEZONE }) }}</p>
    <label class="label" for="org-timezone">{{ t('settings.timezone.label') }}</label>
    <input
      id="org-timezone"
      v-model="zone"
      list="org-timezone-options"
      class="input font-mono"
      :placeholder="DEFAULT_TIMEZONE"
      :disabled="!canChange || saving"
      autocomplete="off"
      spellcheck="false"
      data-testid="timezone-input"
    />
    <datalist id="org-timezone-options">
      <option v-for="z in zones" :key="z" :value="z" />
    </datalist>
    <p v-if="localTime && known" class="text-xs text-gray-400 font-mono mt-2">{{ t('settings.timezone.now', { time: localTime }) }}</p>
    <p v-if="!known" class="text-xs text-amber-400 mt-2">{{ t('settings.timezone.unknown') }}</p>
    <p v-if="error" class="text-xs text-red-400 mt-2" data-testid="timezone-error">{{ error }}</p>
    <div v-if="canChange" class="flex items-center gap-2 mt-3">
      <button type="button" class="btn-primary" :disabled="saving || !dirty" data-testid="timezone-save" @click="save(zone.trim())">
        {{ saving ? t('common.saving') : saved ? t('settings.saved') : t('common.save') }}
      </button>
      <button v-if="current !== DEFAULT_TIMEZONE" type="button" class="btn-secondary" :disabled="saving" @click="save('')">
        {{ t('settings.timezone.reset', { zone: DEFAULT_TIMEZONE }) }}
      </button>
    </div>
  </div>
</template>
