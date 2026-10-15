<script setup>
import { useI18n } from 'vue-i18n'
import { MapPin } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

defineProps({
  version:     { type: Object, default: null },
  regionLabel: { type: String, default: null },
})
</script>

<template>
  <div class="flex-1 overflow-y-auto">
    <div v-if="version" class="card space-y-5 text-sm">
      <template v-if="version.config_snapshot">
        <!-- Proje bilgileri -->
        <div class="grid grid-cols-2 gap-4 pb-4 border-b border-gray-800/60">
          <div class="col-span-2">
            <p class="label">{{ t('project.config.name') }}</p>
            <p class="text-gray-100 font-medium">{{ version.config_snapshot.name ?? '—' }}</p>
          </div>
          <div v-if="version.config_snapshot.description" class="col-span-2">
            <p class="label">{{ t('project.config.description') }}</p>
            <p class="text-gray-400">{{ version.config_snapshot.description }}</p>
          </div>
          <div v-if="regionLabel">
            <p class="label">{{ t('project.config.region') }}</p>
            <p class="font-mono text-gray-100 flex items-center gap-1"><MapPin class="w-3.5 h-3.5" />{{ regionLabel }}</p>
          </div>
          <div v-if="version.config_snapshot.runtime">
            <p class="label"><TermText path="project.config.runtime" /></p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.runtime }}</p>
          </div>
        </div>

        <!-- İmaj + Komut -->
        <div class="grid grid-cols-2 gap-4">
          <div v-if="version.config_snapshot.image" class="col-span-2">
            <p class="label">{{ t('project.config.image') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.image }}</p>
          </div>
          <div v-if="version.config_snapshot.command?.length" class="col-span-2">
            <p class="label">{{ t('project.config.command') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.command.join(' ') }}</p>
          </div>
          <div v-if="version.config_snapshot.args?.length" class="col-span-2">
            <p class="label">{{ t('project.config.arguments') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.args.join(' ') }}</p>
          </div>
        </div>

        <!-- Tetikleyici -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="label">{{ t('project.config.trigger') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.trigger_type ?? '—' }}</p>
          </div>
          <div v-if="version.config_snapshot.schedule">
            <p class="label">{{ t('project.schedule') }}</p>
            <p class="font-mono text-amber-400/80">{{ version.config_snapshot.schedule }}</p>
          </div>
        </div>

        <!-- Kaynaklar -->
        <div class="grid grid-cols-3 gap-4">
          <div>
            <p class="label">{{ t('project.config.cpu') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.cpu ?? '—' }}</p>
          </div>
          <div>
            <p class="label">{{ t('project.config.memory') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.memory_mb ?? '—' }} MB</p>
          </div>
          <div>
            <p class="label">{{ t('project.config.timeout') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.timeout_seconds ?? '—' }} s</p>
          </div>
        </div>

        <!-- Image pull -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="label">{{ t('create.fields.pullPolicy') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.image_pull_policy ?? '—' }}</p>
          </div>
          <div v-if="version.config_snapshot.image_pull_secret">
            <p class="label">{{ t('project.config.pullSecret') }}</p>
            <p class="font-mono text-gray-100">{{ version.config_snapshot.image_pull_secret }}</p>
          </div>
        </div>

        <!-- Portlar -->
        <div v-if="version.config_snapshot.ports?.length">
          <p class="label mb-2">{{ t('project.config.ports') }}</p>
          <div class="flex flex-wrap gap-2">
            <span
              v-for="port in version.config_snapshot.ports" :key="port"
              class="font-mono text-xs bg-gray-800 border border-gray-700 rounded-sm px-2 py-0.5 text-gray-100"
            >{{ port }}</span>
          </div>
        </div>

        <!-- Ortam değişkenleri -->
        <div v-if="version.config_snapshot.env_vars && Object.keys(version.config_snapshot.env_vars).length">
          <p class="label mb-2">{{ t('project.config.envVars') }}</p>
          <div class="space-y-1">
            <div
              v-for="(val, key) in version.config_snapshot.env_vars" :key="key"
              class="flex gap-2 font-mono text-xs"
            >
              <span class="text-indigo-400">{{ key }}</span>
              <span class="text-gray-400">=</span>
              <span class="text-gray-400">{{ val }}</span>
            </div>
          </div>
        </div>
      </template>

      <p v-else class="text-gray-400 text-xs">{{ t('project.config.noDetails') }}</p>
    </div>

    <div v-else class="h-full flex items-center justify-center text-gray-400 text-sm">
      {{ t('project.editor.noVersionSelected') }}
    </div>
  </div>
</template>
