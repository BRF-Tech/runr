<script setup>
// A stopped project (project.stopped: { at, by, reason, level }): every member sees why and who
// stopped it — the platform (level "platform", the administrator is not named) or the
// organization. Holders of projects.stop resume it (POST /projects/{id}/resume — the version that
// ran is deployed again); a platform stop only a platform administrator.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { OctagonPause, Play } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'

const props = defineProps({
  project: { type: Object, required: true },
})
const emit = defineEmits(['resumed'])
const { t, locale } = useI18n()
const toast = useToast()
const perms = usePermissionsStore()

const busy = ref(false)
const stopped = computed(() => props.project.stopped ?? null)
const byPlatform = computed(() => stopped.value?.level === 'platform')
const canResume = computed(() => perms.can('projects.stop') && (!byPlatform.value || perms.platformAdmin))

const when = computed(() => {
  if (!stopped.value?.at) return ''
  const d = new Date(stopped.value.at)
  return Number.isNaN(d.getTime()) ? String(stopped.value.at) : d.toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' })
})

const byline = computed(() => {
  if (byPlatform.value) return t('projectLifecycle.bannerByPlatform', { date: when.value })
  if (stopped.value?.by?.name) return t('projectLifecycle.bannerByOrganization', { name: stopped.value.by.name, date: when.value })
  return t('projectLifecycle.bannerAt', { date: when.value })
})

async function resume() {
  busy.value = true
  try {
    const { data } = await projectsApi.resume(props.project.id)
    // version_rebuilding: built again for the region's registry first, deployed when ready
    if (data?.redeploy_code === 'version_rebuilding') toast.success(t('projectLifecycle.resumedRebuilding'), 8000)
    else if (data?.redeploy_error) toast.error(t('projectLifecycle.resumedNotDeployed', { reason: data.redeploy_error }))
    else toast.success(data?.deployment_id ? t('projectLifecycle.resumedDeploying') : t('projectLifecycle.resumed'))
    emit('resumed', data?.data ?? null)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = false
  }
}

defineExpose({ resume })
</script>

<template>
  <div v-if="stopped" class="mx-6 mt-3 rounded-xl border border-red-900/60 bg-red-950/30 px-4 py-3 flex flex-wrap items-start justify-between gap-3" data-test="project-stopped-banner">
    <div class="flex items-start gap-3 min-w-0">
      <OctagonPause class="w-5 h-5 text-red-400 shrink-0 mt-0.5" />
      <div class="space-y-0.5 min-w-0">
        <p class="text-sm font-medium text-red-300" data-test="project-stopped-title">{{ byPlatform ? t('projectLifecycle.bannerPlatform') : t('projectLifecycle.banner') }}</p>
        <p class="text-xs text-gray-300" data-test="project-stopped-reason">{{ t('projectLifecycle.bannerReason', { reason: stopped.reason }) }}</p>
        <p class="text-[11px] text-gray-400" data-test="project-stopped-by">
          {{ byline }}
          · {{ byPlatform && !canResume ? t('projectLifecycle.bannerHintPlatform') : t('projectLifecycle.bannerHint') }}
        </p>
      </div>
    </div>
    <button v-if="canResume" type="button" class="btn-secondary text-xs" :disabled="busy" data-test="project-resume" @click="resume">
      <Play class="w-3.5 h-3.5" />{{ t('projectLifecycle.resume') }}
    </button>
  </div>
</template>
