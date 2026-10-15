<script setup>
import { inject, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { History, ChevronDown, ChevronRight } from 'lucide-vue-next'
import { PROJECT_GIT } from '@/composables/useProjectGit'
import GitStatusBadge from '@/components/git/GitStatusBadge.vue'
import TermText from '@/components/TermText.vue'

// Deploy history of pushes and pull requests: commit, branch, author, version, status
const { t } = useI18n()
const git = inject(PROJECT_GIT)
const open = ref({})

function toggle(id) {
  open.value = { ...open.value, [id]: !open.value[id] }
}

function fmtDate(iso) {
  return iso ? new Date(iso).toLocaleString() : '-'
}

function hasDetails(d) {
  return (d.warnings?.length ?? 0) > 0 || (d.skipped?.length ?? 0) > 0 || !!d.status_message || Object.keys(d.applied ?? {}).length > 0
}
</script>

<template>
  <section class="card" data-testid="git-history">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <History class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.history.title') }}</h3>
    </div>

    <p v-if="!git.deploys.length" class="text-sm text-gray-500" data-testid="git-history-empty">{{ t('gitDeploy.history.empty') }}</p>

    <div v-else class="overflow-x-auto -mx-5">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-[11px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
            <th class="pl-5 py-2 w-6" />
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.history.when') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.history.target') }}</th>
            <th class="px-3 py-2 font-medium"><TermText path="gitDeploy.history.commit" /></th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.history.branch') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.history.author') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('gitDeploy.history.version') }}</th>
            <th class="px-5 py-2 font-medium">{{ t('gitDeploy.history.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="d in git.deploys" :key="d.id">
            <tr class="border-b border-gray-800/60" :data-deploy="d.short_sha">
              <td class="pl-5 py-2.5">
                <button v-if="hasDetails(d)" type="button" class="text-gray-500 hover:text-gray-200" :aria-label="t('gitDeploy.history.details')" @click="toggle(d.id)">
                  <component :is="open[d.id] ? ChevronDown : ChevronRight" class="w-4 h-4" />
                </button>
              </td>
              <td class="px-3 py-2.5 text-xs text-gray-400 whitespace-nowrap">{{ fmtDate(d.created_at) }}</td>
              <td class="px-3 py-2.5 text-xs">
                <span v-if="d.kind === 'preview'" class="text-violet-300">{{ t('gitDeploy.history.preview', { number: d.pr_number }) }}</span>
                <span v-else class="text-gray-200">{{ t('gitDeploy.history.production') }}</span>
                <span class="text-gray-500"> · {{ t(`gitDeploy.sources.${d.provider}`) }}</span>
              </td>
              <td class="px-3 py-2.5">
                <a v-if="d.url" :href="d.url" target="_blank" rel="noopener" class="font-mono text-xs text-brand-500 hover:underline" data-testid="git-history-sha">{{ d.short_sha }}</a>
                <code v-else class="font-mono text-xs text-gray-200" data-testid="git-history-sha">{{ d.short_sha }}</code>
                <p v-if="d.message" class="text-[11px] text-gray-500 truncate max-w-[18rem]" :title="d.message">{{ d.message.split('\n')[0] }}</p>
              </td>
              <td class="px-3 py-2.5 font-mono text-xs text-gray-300">{{ d.branch || '-' }}</td>
              <td class="px-3 py-2.5 text-xs text-gray-300">{{ d.author_name || '-' }}</td>
              <td class="px-3 py-2.5 text-xs text-gray-300">{{ d.version?.label ?? '-' }}</td>
              <td class="px-5 py-2.5"><GitStatusBadge :status="d.status" /></td>
            </tr>
            <tr v-if="open[d.id]" class="border-b border-gray-800/60 bg-gray-950/40">
              <td colspan="8" class="px-5 py-3 text-xs space-y-2">
                <p v-if="d.status_message" class="text-gray-300">{{ d.status_message }}</p>
                <div v-if="Object.keys(d.applied ?? {}).length">
                  <p class="text-gray-500 mb-1">{{ t('gitDeploy.history.applied') }}</p>
                  <p class="font-mono text-gray-300">{{ Object.keys(d.applied).join(', ') }}</p>
                </div>
                <div v-if="d.warnings?.length">
                  <p class="text-gray-500 mb-1">{{ t('gitDeploy.history.warnings') }}</p>
                  <ul class="list-disc pl-5 text-amber-300 space-y-0.5">
                    <li v-for="(w, i) in d.warnings" :key="i">{{ w }}</li>
                  </ul>
                </div>
                <div v-if="d.skipped?.length">
                  <p class="text-gray-500 mb-1">{{ t('gitDeploy.history.skipped') }}</p>
                  <p class="font-mono text-gray-400">{{ d.skipped.map(s => `${s.path} (${s.reason})`).join(', ') }}</p>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>
