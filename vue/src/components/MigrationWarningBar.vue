<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'

const { t } = useI18n()
const settings = useSettingsStore()

const warnings = computed(() => {
  const items = []
  const regions = settings.data?.regions || []

  for (const r of regions) {
    if (r.is_migrating && r.migration_started_at) {
      const hours = hoursRemaining(r.migration_started_at)
      items.push({ type: 'cluster', message: t('settings.migration.clusterWarning', { name: r.name, hours }) })
    }
    if (r.is_domain_migrating && r.domain_migration_started_at) {
      const hours = hoursRemaining(r.domain_migration_started_at)
      items.push({ type: 'domain', message: t('settings.migration.domainWarning', { hours }) })
    }
  }

  return items
})

function hoursRemaining(startedAt) {
  const start = new Date(startedAt)
  const elapsed = (Date.now() - start.getTime()) / 3600000
  return Math.max(0, Math.ceil(48 - elapsed))
}
</script>

<template>
  <div
    v-for="(w, i) in warnings"
    :key="i"
    class="bg-amber-600 text-white text-center text-xs py-1.5 px-4 font-medium"
  >
    {{ w.message }}
  </div>
</template>
