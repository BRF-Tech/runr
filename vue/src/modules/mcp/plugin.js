import { defineAsyncComponent, markRaw } from 'vue'
import { Bot } from 'lucide-vue-next'
import router from '@/router'
import { usePermissionsStore } from '@/stores/permissions'

/**
 * AI agents (docs/mcp.md): Data & Integrations → AI agents (MCP), the OAuth consent page MCP clients send
 * the user to, the project "MCP" tab of hosted MCP servers and the one-click "New MCP server"
 * action on the projects page. Loaded automatically (modules/index.js); messages:
 * locales/{en,tr}/mcp.js in the core catalog.
 */

const lazy = (loader) => markRaw(defineAsyncComponent(loader))

const DEPLOYABLE = ['xaas', 'faas', 'caas']

/** The project tab: a hosted MCP server, or a project the user may turn into one. */
export function showMcpTab(project) {
  if (!project || !DEPLOYABLE.includes(project.service_type)) return false
  const perms = usePermissionsStore()
  if (project.mcp_server) return perms.can('projects.view', project.id)
  return perms.can('mcp.servers.manage', project.id)
}

export default function (registry) {
  registry.settingsTabs.push({
    key:       'ai-agents',
    labelKey:  'mcp.settings.tab',
    descKey:   'mcp.settings.navDesc',
    icon:      markRaw(Bot),
    group:     'data',
    section:   'integrations',
    component: lazy(() => import('./components/McpSettings.vue')),
    dataTour:  'settings-ai-agents-tab',
    order:     20,
  })

  registry.projectTabs.push({
    id:        'mcp',
    labelKey:  'mcp.project.tab',
    component: lazy(() => import('./components/McpProjectTab.vue')),
    visibleIf: showMcpTab,
    order:     15,
  })

  registry.projectsActions.push(lazy(() => import('./components/NewMcpServerButton.vue')))

  // The consent page an MCP client opens in the browser: added right away (registry.routes are
  // added after mount) so the link resolves on the first navigation. Not public — the guard
  // sends anonymous users to the sign-in and back.
  router.addRoute({
    path:      '/oauth-consent',
    name:      'oauth-consent',
    component: () => import('./components/OAuthConsentView.vue'),
  })
}
