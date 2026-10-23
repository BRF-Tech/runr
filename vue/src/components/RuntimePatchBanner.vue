<script setup>
// Banners that stay on screen until what they say is resolved (GET /runtime-patches/banners,
// docs/runtimes.md#runtime-patches):
//  - a critical / zero-day runtime patch the user's projects still run behind — cannot be
//    dismissed; it goes when the API stops returning it (the projects were migrated)
//  - platform administrators: a newer RunR release, a patch feed that is off or was refused
// Read on load, every five minutes and when a runtime patch notification arrives in real time.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ShieldAlert, X, ExternalLink } from 'lucide-vue-next'
import { runtimePatchesApi } from '@/api/runtimePatches'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { bannerClasses, DEADLINE_LEVELS, levelKey, patchReason } from '@/utils/runtimePatches'

const POLL_MS = 5 * 60 * 1000
const DISMISSED_KEY = 'runr.runtimePatchBanners.dismissed'
const REFRESH_TYPES = ['runtime_patch', 'runtime_patch_feed']

const { t, locale } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationStore()

const banners = ref([])
const dismissed = ref(readDismissed())
let timer = null

function readDismissed() {
  try { return JSON.parse(sessionStorage.getItem(DISMISSED_KEY) || '[]') } catch { return [] }
}

function keyOf(b) {
  return [b.kind, b.patch?.id ?? b.version ?? b.status ?? ''].join(':')
}

/** Zero-day / critical patches stay until resolved; everything else can be put away for the session. */
function dismissible(b) {
  return !(b.kind === 'runtime_patch' && DEADLINE_LEVELS.includes(b.level)) && b.kind !== 'feed_invalid'
}

const visible = computed(() => banners.value.filter(b => !dismissible(b) || !dismissed.value.includes(keyOf(b))))

async function load() {
  if (!auth.token) {
    banners.value = []
    return
  }
  try {
    const { data } = await runtimePatchesApi.banners()
    banners.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    // keep what is shown; the next poll tries again
  }
}

function dismiss(b) {
  if (!dismissible(b)) return
  dismissed.value = [...dismissed.value, keyOf(b)]
  try { sessionStorage.setItem(DISMISSED_KEY, JSON.stringify(dismissed.value)) } catch { /* private mode */ }
}

function levelLabel(level) {
  return t(`runtimePatches.levels.${levelKey(level)}`)
}

function patchText(b) {
  const p = b.patch || {}
  return t('runtimePatches.banner.patch', {
    level: levelLabel(b.level),
    tag: p.tag ?? '',
    runtime: `${p.runtime ?? ''} ${p.version ?? ''}`.trim(),
    reason: patchReason(p, locale.value),
  })
}

function projectsText(b) {
  const names = (b.projects || []).map(p => p.name)
  const more = (b.project_count ?? names.length) > names.length ? ', …' : ''
  return t('runtimePatches.banner.projects', { count: b.project_count ?? names.length, projects: names.join(', ') + more })
}

function openPatch(b) {
  const projects = b.projects || []
  router.push(projects.length === 1 ? `/projects/${projects[0].id}` : '/projects')
}

const fmt = iso => (iso ? new Date(iso).toLocaleString() : '')

// A runtime patch notification in real time: the banners may have changed
watch(() => notifications.notifications[0]?.id, () => {
  if (REFRESH_TYPES.includes(notifications.notifications[0]?.type)) load()
})
watch(() => auth.token, load)

onMounted(() => {
  load()
  timer = setInterval(load, POLL_MS)
})
onUnmounted(() => clearInterval(timer))

defineExpose({ load, banners, visible, dismiss })
</script>

<template>
  <div v-if="visible.length" data-test="runtime-patch-banners">
    <div
      v-for="b in visible"
      :key="keyOf(b)"
      class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-xs py-1.5 px-4 font-medium"
      :class="bannerClasses(b.level)"
      :data-test="`runtime-patch-banner-${b.kind}`"
    >
      <template v-if="b.kind === 'runtime_patch'">
        <ShieldAlert class="w-3.5 h-3.5 shrink-0" />
        <span>{{ patchText(b) }}</span>
        <span class="opacity-90">{{ projectsText(b) }}</span>
        <span v-if="b.overdue" class="font-semibold">{{ t('runtimePatches.banner.overdue') }}</span>
        <span v-else-if="b.patch?.deadline_at">{{ t('runtimePatches.banner.deadline', { date: fmt(b.patch.deadline_at) }) }}</span>
        <button type="button" class="underline underline-offset-2" data-test="runtime-patch-banner-open" @click="openPatch(b)">{{ t('runtimePatches.banner.open') }}</button>
      </template>

      <template v-else-if="b.kind === 'platform_advisory'">
        <span>{{ t('runtimePatches.banner.advisory', { version: b.version, level: levelLabel(b.level), reason: b.reason ?? '' }) }}</span>
        <span v-if="b.running" class="opacity-80">({{ t('runtimePatches.banner.running', { running: b.running }) }})</span>
        <a v-if="b.url" :href="b.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 underline underline-offset-2">{{ t('runtimePatches.banner.releaseNotes') }}<ExternalLink class="w-3 h-3" /></a>
      </template>

      <template v-else-if="b.kind === 'feed_disabled' || b.kind === 'feed_invalid'">
        <span>{{ b.kind === 'feed_disabled' ? t('runtimePatches.banner.feedDisabled') : t('runtimePatches.banner.feedInvalid', { status: b.status ?? '', error: b.error ?? '' }) }}</span>
        <RouterLink :to="{ path: '/settings', query: { tab: 'patches' } }" class="underline underline-offset-2">{{ t('runtimePatches.banner.patchesPage') }}</RouterLink>
      </template>

      <button
        v-if="dismissible(b)"
        type="button"
        class="opacity-80 hover:opacity-100"
        :title="t('runtimePatches.banner.dismiss')"
        :data-test="`runtime-patch-banner-dismiss-${b.kind}`"
        @click="dismiss(b)"
      ><X class="w-3.5 h-3.5" /></button>
    </div>
  </div>
</template>
