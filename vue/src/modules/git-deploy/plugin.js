import { defineAsyncComponent, markRaw } from 'vue'
import { usePermissionsStore } from '@/stores/permissions'

/**
 * Git deploy (core plugin, always loaded): the project "Git" tab (remote, provider repository,
 * previews, deploy history) and Settings → Git providers (GitHub App, GitLab, Forgejo/Gitea).
 * Strings: src/locales/{en,tr}/git.js.
 */
export default function (registry) {
  registry.projectTabs.push({
    id:        'git',
    labelKey:  'gitDeploy.tab',
    component: markRaw(defineAsyncComponent(() => import('@/components/git/ProjectGitTab.vue'))),
    // Every deployable project type, for users with git.pull on the project (docs/permissions.md)
    visibleIf: (project) => ['xaas', 'faas', 'caas'].includes(project?.service_type)
      && usePermissionsStore().can('git.pull', project.id),
    order:     5,
  })

  registry.settingsTabs.push({
    key:       'git-providers',
    labelKey:  'gitDeploy.providers.tab',
    component: markRaw(defineAsyncComponent(() => import('@/components/git/GitProvidersManager.vue'))),
    // Single-tenant: the organization's git_providers.manage; multi-tenant: platform admins
    visibleIf: () => usePermissionsStore().can('git_providers.manage'),
    order:     60,
  })
}
