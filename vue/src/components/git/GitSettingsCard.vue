<script setup>
import { computed, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { Settings2, Eye } from 'lucide-vue-next'
import { PROJECT_GIT } from '@/composables/useProjectGit'
import GitToggle from '@/components/git/GitToggle.vue'
import TermText from '@/components/TermText.vue'

// Deploy branch, root directory, auto-deploy, force pushes and the preview settings
const { t } = useI18n()
const git = inject(PROJECT_GIT)

const canManage   = computed(() => !!git.abilities.manage)
const canPreviews = computed(() => !!git.abilities.manage_previews)
const limits      = computed(() => git.info?.limits ?? {})
</script>

<template>
  <section class="card" data-testid="git-settings">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Deploys -->
      <div class="space-y-4">
        <div class="flex items-center gap-2 pb-3 border-b border-gray-800">
          <Settings2 class="w-4 h-4" style="color:rgb(var(--brand-500))" />
          <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.settings.title') }}</h3>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="git-deploy-branch">{{ t('gitDeploy.settings.deployBranch') }}</label>
            <input id="git-deploy-branch" v-model="git.form.deploy_branch" class="input font-mono" :disabled="!canManage" data-testid="git-deploy-branch" />
          </div>
          <div>
            <label class="label" for="git-root-dir">{{ t('gitDeploy.settings.rootDir') }}</label>
            <input id="git-root-dir" v-model="git.form.root_dir" class="input font-mono" :disabled="!canManage" :placeholder="t('gitDeploy.settings.rootDirPlaceholder')" data-testid="git-root-dir" />
          </div>
        </div>
        <p class="text-xs text-gray-500 -mt-2">{{ t('gitDeploy.settings.rootDirHint') }}</p>

        <GitToggle v-model="git.form.auto_deploy" :disabled="!canManage" test-id="git-auto-deploy"
          :label="t('gitDeploy.settings.autoDeploy')" :hint="t('gitDeploy.settings.autoDeployHint', { branch: git.form.deploy_branch })" />
        <GitToggle v-model="git.form.allow_force_push" :disabled="!canManage || git.info.read_only" test-id="git-force-push"
          :label="t('gitDeploy.settings.forcePush')" :hint="t('gitDeploy.settings.forcePushHint')" />

        <div v-if="git.info.runr_yaml" class="text-xs text-gray-500" data-testid="git-runr-yaml">
          {{ t('gitDeploy.settings.runrYaml', { keys: Object.keys(git.info.runr_yaml).join(', ') }) }}
        </div>
      </div>

      <!-- Previews -->
      <div class="space-y-4">
        <div class="flex items-center gap-2 pb-3 border-b border-gray-800">
          <Eye class="w-4 h-4" style="color:rgb(var(--brand-500))" />
          <h3 class="text-sm font-semibold text-gray-100">{{ t('gitDeploy.previews.settingsTitle') }}</h3>
        </div>

        <GitToggle v-model="git.form.previews_enabled" :disabled="!canPreviews" test-id="git-previews-enabled"
          :label="t('gitDeploy.previews.enabled')" :hint="git.connection ? t('gitDeploy.previews.enabledHint') : t('gitDeploy.previews.needsRepo')" />

        <template v-if="git.form.previews_enabled">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="git-preview-limit">{{ t('gitDeploy.previews.limit') }}</label>
              <input id="git-preview-limit" v-model.number="git.form.preview_limit" type="number" min="0" :max="limits.preview_limit_max" class="input" :disabled="!canPreviews" data-testid="git-preview-limit" />
            </div>
            <div>
              <label class="label" for="git-preview-ttl"><TermText path="gitDeploy.previews.ttl" /></label>
              <input id="git-preview-ttl" v-model.number="git.form.preview_ttl_hours" type="number" min="1" :max="limits.preview_ttl_max" class="input" :disabled="!canPreviews" data-testid="git-preview-ttl" />
            </div>
          </div>

          <div>
            <label class="label" for="git-preview-env">{{ t('gitDeploy.previews.env') }}</label>
            <textarea id="git-preview-env" v-model="git.form.preview_env" class="input h-20 resize-none font-mono text-xs leading-relaxed" :disabled="!canPreviews"
              :placeholder="t('gitDeploy.previews.envPlaceholder')" data-testid="git-preview-env" />
            <p v-if="git.previewEnvError" class="text-xs text-red-400 mt-1">{{ t(git.previewEnvError.key, git.previewEnvError.params) }}</p>
            <p v-else class="text-xs text-gray-500 mt-1">{{ t('gitDeploy.previews.envHint') }}</p>
          </div>

          <GitToggle v-model="git.form.fork_previews" :disabled="!canPreviews" test-id="git-fork-previews"
            :label="t('gitDeploy.previews.forks')" :hint="t('gitDeploy.previews.forksHint')" />
          <GitToggle v-if="git.form.fork_previews" v-model="git.form.fork_secrets" :disabled="!canPreviews" test-id="git-fork-secrets"
            :label="t('gitDeploy.previews.forkSecrets')" :hint="t('gitDeploy.previews.forkSecretsHint')" />
        </template>
      </div>
    </div>

    <div v-if="canManage" class="flex items-center justify-end gap-3 mt-6 pt-4 border-t border-gray-800">
      <p v-if="git.saveError" class="text-xs text-red-400 mr-auto" data-testid="git-save-error">{{ git.saveError }}</p>
      <button type="button" class="btn-primary" :disabled="git.saving || !git.dirty || !!git.previewEnvError" data-testid="git-save" @click="git.save()">
        {{ git.saving ? t('common.saving') : t('common.save') }}
      </button>
    </div>
  </section>
</template>
