/**
 * Tutorial step definitions for each tutorial flow.
 * Each function receives the i18n `t` function and returns an array of step objects.
 * tests/composables/tutorialSteps.spec.js checks that every target and text exists.
 */
import { GLOBAL_MAX_REGIONS, GLOBAL_MIN_REGIONS } from '@/utils/globalProjects'

export function projectsSteps(t) {
  return [
    {
      popover: {
        title: t('tutorial.projects.welcome'),
        description: t('tutorial.projects.welcomeDesc'),
        side: 'over',
        align: 'center',
      },
    },
    {
      element: '[data-tour="search-input"]',
      popover: {
        title: t('tutorial.projects.search'),
        description: t('tutorial.projects.searchDesc'),
      },
    },
    {
      element: '[data-tour="filter-tabs"]',
      popover: {
        title: t('tutorial.projects.filters'),
        description: t('tutorial.projects.filtersDesc'),
      },
    },
    {
      element: '[data-tour="filter-dropdowns"]',
      popover: {
        title: t('tutorial.projects.filterDropdowns'),
        description: t('tutorial.projects.filterDropdownsDesc'),
      },
    },
    {
      // Module action next to New project (modules/mcp, moduleRegistry.projectsActions)
      element: '[data-tour="new-mcp-server-btn"]',
      popover: {
        title: t('tutorial.projects.mcpServer'),
        description: t('tutorial.projects.mcpServerDesc'),
      },
    },
    {
      element: '[data-tour="new-project-btn"]',
      popover: {
        title: t('tutorial.projects.newProject'),
        description: t('tutorial.projects.newProjectDesc'),
      },
    },
    {
      element: '[data-tour="resource-bars"]',
      popover: {
        title: t('tutorial.projects.resources'),
        description: t('tutorial.projects.resourcesDesc'),
      },
    },
    {
      // Shown while the organization has rightsizing recommendations
      element: '[data-tour="rightsizing-link"]',
      popover: {
        title: t('tutorial.projects.rightsizing'),
        description: t('tutorial.projects.rightsizingDesc'),
      },
    },
    {
      element: '[data-tour="project-card"]',
      popover: {
        title: t('tutorial.projects.card'),
        description: t('tutorial.projects.cardDesc'),
      },
    },
    {
      element: '[data-tour="notifications-btn"]',
      popover: {
        title: t('tutorial.projects.notifications'),
        description: t('tutorial.projects.notificationsDesc'),
      },
    },
    {
      element: '[data-tour="main-nav"]',
      popover: {
        title: t('tutorial.projects.menus'),
        description: t('tutorial.projects.menusDesc'),
      },
    },
    {
      element: '[data-tour="user-menu-btn"]',
      popover: {
        title: t('tutorial.projects.userMenu'),
        description: t('tutorial.projects.userMenuDesc'),
      },
    },
  ]
}

// The create wizard's "?" starts this on the step it shows: the steps of the other wizard steps
// (and of fields the step hides, e.g. regional vs global) are not in the DOM and drop out.
export function createSteps(t) {
  const regions = { min: GLOBAL_MIN_REGIONS, max: GLOBAL_MAX_REGIONS }
  return [
    // Step 1: name, description, regional or global, region(s)
    { element: '[data-tour="create-name"]',           popover: { title: t('tutorial.create.name'),          description: t('tutorial.create.nameDesc') } },
    { element: '[data-tour="create-description"]',    popover: { title: t('tutorial.create.description'),   description: t('tutorial.create.descriptionDesc') } },
    { element: '[data-tour="create-placement"]',      popover: { title: t('tutorial.create.placement'),     description: t('tutorial.create.placementDesc') } },
    { element: '[data-tour="create-region"]',         popover: { title: t('tutorial.create.region'),        description: t('tutorial.create.regionDesc') } },
    { element: '[data-tour="create-global-regions"]', popover: { title: t('tutorial.create.globalRegions'), description: t('tutorial.create.globalRegionsDesc', regions) } },
    { element: '[data-tour="create-global-primary"]', popover: { title: t('tutorial.create.globalPrimary'), description: t('tutorial.create.globalPrimaryDesc') } },
    { element: '[data-tour="create-dns-mode"]',       popover: { title: t('tutorial.create.dnsMode'),       description: t('tutorial.create.dnsModeDesc') } },
    // Step 2: type
    { element: '[data-tour="create-types"]',          popover: { title: t('tutorial.create.types'),         description: t('tutorial.create.typesDesc') } },
    // Step 3: runtime or container, trigger, endpoint type
    { element: '[data-tour="create-lang"]',           popover: { title: t('tutorial.create.lang'),          description: t('tutorial.create.langDesc') } },
    { element: '[data-tour="create-container"]',      popover: { title: t('tutorial.create.container'),     description: t('tutorial.create.containerDesc') } },
    { element: '[data-tour="create-trigger"]',        popover: { title: t('tutorial.create.trigger'),       description: t('tutorial.create.triggerDesc') } },
    { element: '[data-tour="create-endpoint"]',       popover: { title: t('tutorial.create.endpoint'),      description: t('tutorial.create.endpointDesc') } },
    // Step 4: resources, GPU, scale, variables
    { element: '[data-tour="create-resources"]',      popover: { title: t('tutorial.create.resources'),     description: t('tutorial.create.resourcesDesc') } },
    { element: '[data-tour="create-gpu"]',            popover: { title: t('tutorial.create.gpu'),           description: t('tutorial.create.gpuDesc') } },
    { element: '[data-tour="create-scale"]',          popover: { title: t('tutorial.create.scale'),         description: t('tutorial.create.scaleDesc') } },
    { element: '[data-tour="create-env"]',            popover: { title: t('tutorial.create.env'),           description: t('tutorial.create.envDesc') } },
    { element: '[data-tour="create-secrets"]',        popover: { title: t('tutorial.create.secrets'),       description: t('tutorial.create.secretsDesc') } },
  ]
}

// The tabs above a settings page are the other pages of its menu section (src/navigation/menu.js):
// a step for each tab whose link names a data-tour (core links, module settingsTabs), in menu
// order. Only the tabs of the section shown are in the DOM.
export function settingsTabSteps(t) {
  return [
    { element: '[data-tour="settings-sandboxes-tab"]', popover: { title: t('tutorial.settings.sandboxes'),     description: t('tutorial.settings.sandboxesDesc') } },
    { element: '[data-tour="settings-events-tab"]',    popover: { title: t('tutorial.settings.events'),        description: t('tutorial.settings.eventsDesc') } },
    { element: '[data-tour="settings-ai-agents-tab"]', popover: { title: t('tutorial.settings.aiAgents'),      description: t('tutorial.settings.aiAgentsDesc') } },
    { element: '[data-tour="settings-users-tab"]',     popover: { title: t('tutorial.settings.users'),         description: t('tutorial.settings.usersDesc') } },
    { element: '[data-tour="settings-tokens-tab"]',    popover: { title: t('tutorial.settings.tokens'),        description: t('tutorial.settings.tokensDesc') } },
    { element: '[data-tour="auth-providers-tab"]',     popover: { title: t('tutorial.settings.authProviders'), description: t('tutorial.settings.authProvidersDesc') } },
    { element: '[data-tour="settings-audit-tab"]',     popover: { title: t('tutorial.settings.audit'),         description: t('tutorial.settings.auditDesc') } },
    { element: '[data-tour="settings-billing-tab"]',   popover: { title: t('tutorial.settings.billing'),       description: t('tutorial.settings.billingDesc') } },
    { element: '[data-tour="settings-tenants-tab"]',   popover: { title: t('tutorial.settings.tenants'),       description: t('tutorial.settings.tenantsDesc') } },
    { element: '[data-tour="settings-usage-tab"]',     popover: { title: t('tutorial.settings.resourceUsage'), description: t('tutorial.settings.resourceUsageDesc') } },
  ]
}

export function settingsSteps(t) {
  return [
    ...settingsTabSteps(t),
    // Stats ribbon (single step for the whole grid)
    { element: '[data-tour="settings-stats"]',         popover: { title: t('tutorial.settings.stats'),         description: t('tutorial.settings.statsDesc') } },
    // Platform tab content: the configuration column, then the regions
    { element: '[data-tour="settings-endpoint"]',      popover: { title: t('tutorial.settings.endpoint'),      description: t('tutorial.settings.endpointDesc', { format: '{slug}.{region}.{domain}' }) } },
    { element: '[data-tour="settings-timezone"]',      popover: { title: t('tutorial.settings.timezone'),      description: t('tutorial.settings.timezoneDesc') } },
    { element: '[data-tour="settings-registration"]',  popover: { title: t('tutorial.settings.registration'),  description: t('tutorial.settings.registrationDesc') } },
    { element: '[data-tour="settings-cross-tenant"]',  popover: { title: t('tutorial.settings.crossTenant'),   description: t('tutorial.settings.crossTenantDesc') } },
    { element: '[data-tour="settings-demo"]',          popover: { title: t('tutorial.settings.demo'),          description: t('tutorial.settings.demoDesc') } },
    { element: '[data-tour="settings-save"]',          popover: { title: t('tutorial.settings.save'),          description: t('tutorial.settings.saveDesc') } },
    { element: '[data-tour="settings-regions"]',       popover: { title: t('tutorial.settings.regions'),       description: t('tutorial.settings.regionsDesc') } },
    { element: '[data-tour="settings-global-dns"]',    popover: { title: t('tutorial.settings.globalDns'),     description: t('tutorial.settings.globalDnsDesc') } },
    // Last: the tour goes on with the region wizard (SettingsView opens it)
    { element: '[data-tour="settings-add-region"]',    popover: { title: t('tutorial.settings.addRegion'),     description: t('tutorial.settings.addRegionDesc') } },
  ]
}

export function tokensSteps(t) {
  return [
    ...settingsTabSteps(t),
    { element: '[data-tour="tokens-create-form"]',   popover: { title: t('tutorial.tokens.createForm'),   description: t('tutorial.tokens.createFormDesc') } },
    { element: '[data-tour="tokens-expiry"]',        popover: { title: t('tutorial.tokens.expiry'),        description: t('tutorial.tokens.expiryDesc') } },
    { element: '[data-tour="tokens-full-access"]',   popover: { title: t('tutorial.tokens.fullAccess'),    description: t('tutorial.tokens.fullAccessDesc') } },
    { element: '[data-tour="tokens-custom-access"]', popover: { title: t('tutorial.tokens.customAccess'),  description: t('tutorial.tokens.customAccessDesc') } },
  ]
}

export function usersSteps(t) {
  return [
    ...settingsTabSteps(t),
    { element: '[data-tour="users-add-form"]',  popover: { title: t('tutorial.users.addForm'),  description: t('tutorial.users.addFormDesc') } },
  ]
}

export function detailVersionsSteps(t) {
  return [
    { element: '[data-tour="project-header"]',  popover: { title: t('tutorial.detail.header'),   description: t('tutorial.detail.headerDesc') } },
    { element: '[data-tour="action-buttons"]',  popover: { title: t('tutorial.detail.actions'),  description: t('tutorial.detail.actionsDesc') } },
    { element: '[data-tour="project-tabs"]',    popover: { title: t('tutorial.detail.tabs'),     description: t('tutorial.detail.tabsDesc') } },
    // Shown while the project has a size recommendation
    { element: '[data-tour="rightsizing-card"]', popover: { title: t('tutorial.detail.rightsizing'), description: t('tutorial.detail.rightsizingDesc') } },
    { element: '[data-tour="version-list"]',    popover: { title: t('tutorial.detail.versions'), description: t('tutorial.detail.versionsDesc') } },
    { element: '[data-tour="editor-area"]',     popover: { title: t('tutorial.detail.editor'),   description: t('tutorial.detail.editorDesc') } },
    { element: '[data-tour="commit-bar"]',      popover: { title: t('tutorial.detail.save'),     description: t('tutorial.detail.saveDesc') } },
  ]
}

export function detailDeploymentsSteps(t) {
  return [
    // The environment switcher shows with more than one environment, the rollout while one runs
    { element: '[data-tour="environment-switcher"]',    popover: { title: t('tutorial.detail.environment'),          description: t('tutorial.detail.environmentDesc') } },
    { element: '[data-tour="rollout-panel"]',           popover: { title: t('tutorial.detail.rollout'),              description: t('tutorial.detail.rolloutDesc') } },
    { element: '[data-tour="deploy-strategy-btn"]',     popover: { title: t('tutorial.detail.strategy'),             description: t('tutorial.detail.strategyDesc') } },
    { element: '[data-tour="deployment-table"]',        popover: { title: t('tutorial.detail.deploymentTable'),      description: t('tutorial.detail.deploymentTableDesc') } },
    { element: '[data-tour="deployment-log-btn"]',      popover: { title: t('tutorial.detail.deploymentLogs'),       description: t('tutorial.detail.deploymentLogsDesc') } },
    { element: '[data-tour="deployment-rollback-btn"]', popover: { title: t('tutorial.detail.deploymentRollback'),   description: t('tutorial.detail.deploymentRollbackDesc') } },
    { element: '[data-tour="deployment-delete-btn"]',   popover: { title: t('tutorial.detail.deploymentDelete'),     description: t('tutorial.detail.deploymentDeleteDesc') } },
  ]
}

export function detailMetricsSteps(t) {
  return [
    { element: '[data-tour="metrics-range"]',   popover: { title: t('tutorial.detail.metricsRange'),   description: t('tutorial.detail.metricsRangeDesc') } },
    { element: '[data-tour="metrics-summary"]',  popover: { title: t('tutorial.detail.metricsSummary'), description: t('tutorial.detail.metricsSummaryDesc') } },
    { element: '[data-tour="metrics-charts"]',   popover: { title: t('tutorial.detail.metricsCharts'),  description: t('tutorial.detail.metricsChartsDesc') } },
  ]
}

// Like the create wizard's: the edit modal's "?" starts it on the step it shows
export function editSteps(t) {
  return [
    { element: '[data-tour="edit-name"]',      popover: { title: t('tutorial.edit.name'),      description: t('tutorial.edit.nameDesc') } },
    { element: '[data-tour="edit-type"]',      popover: { title: t('tutorial.edit.type'),      description: t('tutorial.edit.typeDesc') } },
    { element: '[data-tour="edit-runtime"]',   popover: { title: t('tutorial.edit.runtime'),   description: t('tutorial.edit.runtimeDesc') } },
    { element: '[data-tour="edit-trigger"]',   popover: { title: t('tutorial.edit.trigger'),   description: t('tutorial.edit.triggerDesc') } },
    { element: '[data-tour="edit-resources"]', popover: { title: t('tutorial.edit.resources'), description: t('tutorial.edit.resourcesDesc') } },
    { element: '[data-tour="edit-gpu"]',       popover: { title: t('tutorial.edit.gpu'),       description: t('tutorial.edit.gpuDesc') } },
    { element: '[data-tour="edit-scale"]',     popover: { title: t('tutorial.edit.scale'),     description: t('tutorial.edit.scaleDesc') } },
    { element: '[data-tour="edit-env"]',       popover: { title: t('tutorial.edit.env'),       description: t('tutorial.edit.envDesc') } },
    { element: '[data-tour="edit-secrets"]',   popover: { title: t('tutorial.edit.secrets'),   description: t('tutorial.edit.secretsDesc') } },
    { element: '[data-tour="edit-strategy"]',  popover: { title: t('tutorial.edit.strategy'),  description: t('tutorial.edit.strategyDesc') } },
    { element: '[data-tour="edit-note"]',      popover: { title: t('tutorial.edit.note'),      description: t('tutorial.edit.noteDesc') } },
  ]
}

export function accountProfileSteps(t) {
  return [
    { element: '[data-tour="account-name-fields"]',  popover: { title: t('tutorial.account.nameFields'),   description: t('tutorial.account.nameFieldsDesc') } },
    { element: '[data-tour="account-bio"]',          popover: { title: t('tutorial.account.bio'),          description: t('tutorial.account.bioDesc') } },
    { element: '[data-tour="account-save-profile"]', popover: { title: t('tutorial.account.saveProfile'),  description: t('tutorial.account.saveProfileDesc') } },
    { element: '[data-tour="account-password"]',     popover: { title: t('tutorial.account.password'),     description: t('tutorial.account.passwordDesc') } },
    { element: '[data-tour="account-2fa"]',          popover: { title: t('tutorial.account.twoFactor'),    description: t('tutorial.account.twoFactorDesc') } },
    { element: '[data-tour="account-sessions"]',     popover: { title: t('tutorial.account.sessions'),     description: t('tutorial.account.sessionsDesc') } },
    { element: '[data-tour="account-danger"]',       popover: { title: t('tutorial.account.danger'),       description: t('tutorial.account.dangerDesc') } },
  ]
}

export function createTicketSteps(t) {
  return [
    { element: '[data-tour="ticket-subject"]',           popover: { title: t('tutorial.createTicket.subject'),     description: t('tutorial.createTicket.subjectDesc') } },
    { element: '[data-tour="ticket-category-priority"]', popover: { title: t('tutorial.createTicket.catPriority'), description: t('tutorial.createTicket.catPriorityDesc') } },
    { element: '[data-tour="ticket-description"]',       popover: { title: t('tutorial.createTicket.description'), description: t('tutorial.createTicket.descriptionDesc') } },
    { element: '[data-tour="ticket-attachments"]',       popover: { title: t('tutorial.createTicket.attachments'), description: t('tutorial.createTicket.attachmentsDesc') } },
    { element: '[data-tour="ticket-submit"]',            popover: { title: t('tutorial.createTicket.submit'),      description: t('tutorial.createTicket.submitDesc') } },
  ]
}

export function accountSupportSteps(t) {
  return [
    { element: '[data-tour="support-stats"]',          popover: { title: t('tutorial.support.stats'),       description: t('tutorial.support.statsDesc') } },
    { element: '[data-tour="support-new-ticket"]',     popover: { title: t('tutorial.support.newTicket'),    description: t('tutorial.support.newTicketDesc') } },
    { element: '[data-tour="support-search"]',         popover: { title: t('tutorial.support.search'),      description: t('tutorial.support.searchDesc') } },
    { element: '[data-tour="support-ticket-list"]',    popover: { title: t('tutorial.support.ticketList'),   description: t('tutorial.support.ticketListDesc') } },
  ]
}