/**
 * Tutorial step definitions for each tutorial flow.
 * Each function receives the i18n `t` function and returns an array of step objects.
 */

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

export function createSteps(t) {
  return [
    { element: '[data-tour="create-name"]',      popover: { title: t('tutorial.create.name'),      description: t('tutorial.create.nameDesc') } },
    { element: '[data-tour="create-types"]',     popover: { title: t('tutorial.create.types'),     description: t('tutorial.create.typesDesc') } },
    { element: '[data-tour="create-lang"]',      popover: { title: t('tutorial.create.lang'),      description: t('tutorial.create.langDesc') } },
    { element: '[data-tour="create-container"]', popover: { title: t('tutorial.create.container'), description: t('tutorial.create.containerDesc') } },
    { element: '[data-tour="create-trigger"]',   popover: { title: t('tutorial.create.trigger'),   description: t('tutorial.create.triggerDesc') } },
    { element: '[data-tour="create-resources"]', popover: { title: t('tutorial.create.resources'), description: t('tutorial.create.resourcesDesc') } },
    { element: '[data-tour="create-scale"]',     popover: { title: t('tutorial.create.scale'),     description: t('tutorial.create.scaleDesc') } },
    { element: '[data-tour="create-region"]',    popover: { title: t('tutorial.create.region'),    description: t('tutorial.create.regionDesc') } },
  ]
}

export function settingsSteps(t) {
  return [
    // Stats ribbon (single step for the whole grid)
    { element: '[data-tour="settings-stats"]',         popover: { title: t('tutorial.settings.stats'),         description: t('tutorial.settings.statsDesc') } },
    // Platform tab content
    { element: '[data-tour="settings-endpoint"]',      popover: { title: t('tutorial.settings.endpoint'),      description: t('tutorial.settings.endpointDesc') } },
    { element: '[data-tour="settings-registration"]',  popover: { title: t('tutorial.settings.registration'),  description: t('tutorial.settings.registrationDesc') } },
    { element: '[data-tour="settings-demo"]',          popover: { title: t('tutorial.settings.demo'),          description: t('tutorial.settings.demoDesc') } },
    { element: '[data-tour="settings-regions"]',       popover: { title: t('tutorial.settings.regions'),       description: t('tutorial.settings.regionsDesc') } },
    { element: '[data-tour="settings-add-region"]',    popover: { title: t('tutorial.settings.addRegion'),     description: t('tutorial.settings.addRegionDesc') } },
    { element: '[data-tour="settings-save"]',          popover: { title: t('tutorial.settings.save'),          description: t('tutorial.settings.saveDesc') } },
  ]
}

export function tokensSteps(t) {
  return [
    { element: '[data-tour="tokens-create-form"]',   popover: { title: t('tutorial.tokens.createForm'),   description: t('tutorial.tokens.createFormDesc') } },
    { element: '[data-tour="tokens-expiry"]',        popover: { title: t('tutorial.tokens.expiry'),        description: t('tutorial.tokens.expiryDesc') } },
    { element: '[data-tour="tokens-full-access"]',   popover: { title: t('tutorial.tokens.fullAccess'),    description: t('tutorial.tokens.fullAccessDesc') } },
    { element: '[data-tour="tokens-custom-access"]', popover: { title: t('tutorial.tokens.customAccess'),  description: t('tutorial.tokens.customAccessDesc') } },
  ]
}

export function usersSteps(t) {
  return [
    { element: '[data-tour="users-add-form"]',  popover: { title: t('tutorial.users.addForm'),  description: t('tutorial.users.addFormDesc') } },
  ]
}

export function detailVersionsSteps(t) {
  return [
    { element: '[data-tour="project-header"]',  popover: { title: t('tutorial.detail.header'),   description: t('tutorial.detail.headerDesc') } },
    { element: '[data-tour="action-buttons"]',  popover: { title: t('tutorial.detail.actions'),  description: t('tutorial.detail.actionsDesc') } },
    { element: '[data-tour="version-list"]',    popover: { title: t('tutorial.detail.versions'), description: t('tutorial.detail.versionsDesc') } },
    { element: '[data-tour="editor-area"]',     popover: { title: t('tutorial.detail.editor'),   description: t('tutorial.detail.editorDesc') } },
    { element: '[data-tour="commit-bar"]',      popover: { title: t('tutorial.detail.save'),     description: t('tutorial.detail.saveDesc') } },
  ]
}

export function detailDeploymentsSteps(t) {
  return [
    { element: '[data-tour="deployment-table"]',      popover: { title: t('tutorial.detail.deploymentTable'),      description: t('tutorial.detail.deploymentTableDesc') } },
    { element: '[data-tour="deployment-log-btn"]',    popover: { title: t('tutorial.detail.deploymentLogs'),       description: t('tutorial.detail.deploymentLogsDesc') } },
    { element: '[data-tour="deployment-delete-btn"]', popover: { title: t('tutorial.detail.deploymentDelete'),     description: t('tutorial.detail.deploymentDeleteDesc') } },
  ]
}

export function detailMetricsSteps(t) {
  return [
    { element: '[data-tour="metrics-range"]',   popover: { title: t('tutorial.detail.metricsRange'),   description: t('tutorial.detail.metricsRangeDesc') } },
    { element: '[data-tour="metrics-summary"]',  popover: { title: t('tutorial.detail.metricsSummary'), description: t('tutorial.detail.metricsSummaryDesc') } },
    { element: '[data-tour="metrics-charts"]',   popover: { title: t('tutorial.detail.metricsCharts'),  description: t('tutorial.detail.metricsChartsDesc') } },
  ]
}

export function editSteps(t) {
  return [
    { element: '[data-tour="edit-name"]',      popover: { title: t('tutorial.edit.name'),      description: t('tutorial.edit.nameDesc') } },
    { element: '[data-tour="edit-type"]',      popover: { title: t('tutorial.edit.type'),      description: t('tutorial.edit.typeDesc') } },
    { element: '[data-tour="edit-trigger"]',   popover: { title: t('tutorial.edit.trigger'),   description: t('tutorial.edit.triggerDesc') } },
    { element: '[data-tour="edit-resources"]', popover: { title: t('tutorial.edit.resources'), description: t('tutorial.edit.resourcesDesc') } },
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
    { element: '[data-tour="account-contact-info"]',   popover: { title: t('tutorial.support.contactInfo'), description: t('tutorial.support.contactInfoDesc') } },
    { element: '[data-tour="account-help"]',           popover: { title: t('tutorial.support.help'),        description: t('tutorial.support.helpDesc') } },
  ]
}