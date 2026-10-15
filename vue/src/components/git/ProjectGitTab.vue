<script setup>
import { provide, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useProjectGit, PROJECT_GIT } from '@/composables/useProjectGit'
import GitRemoteCard from '@/components/git/GitRemoteCard.vue'
import GitConnectCard from '@/components/git/GitConnectCard.vue'
import GitSettingsCard from '@/components/git/GitSettingsCard.vue'
import GitPreviewList from '@/components/git/GitPreviewList.vue'
import GitDeployHistory from '@/components/git/GitDeployHistory.vue'

// Project → Git tab (docs/git-deploy.md); the sections share one state (inject PROJECT_GIT)
const props = defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()
const git = reactive(useProjectGit(() => props.project.id))
provide(PROJECT_GIT, git)
</script>

<template>
  <div data-testid="git-tab">
    <div v-if="git.loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="git.loadError" class="card text-sm text-red-400 flex items-center justify-between gap-3">
      <span>{{ git.loadError }}</span>
      <button type="button" class="btn-secondary" @click="git.load()">{{ t('gitDeploy.retry') }}</button>
    </div>

    <div v-else-if="git.info" class="space-y-5">
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 items-start">
        <GitRemoteCard />
        <GitConnectCard />
      </div>
      <GitSettingsCard />
      <GitPreviewList />
      <GitDeployHistory />
    </div>
  </div>
</template>
