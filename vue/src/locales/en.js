import common from './en/common'
import projects from './en/projects'
import projectForms from './en/projectForms'
import settings from './en/settings'
import regionDetail from './en/regionDetail'
import tutorial from './en/tutorial'
import notificationsLocale from './en/notifications'
import accountLocale from './en/account'
import aiProjects from './en/aiProjects'
import security from './en/security'
import runtimesLocale from './en/runtimes'
import wasmLocale from './en/wasm'
import rbac from './en/rbac'
import bindingsLocale from './en/bindings'
import workflowsLocale from './en/workflows'
import triggersLocale from './en/triggers'
import aiLocale from './en/ai'
import gitDeploy from './en/git'
import mcpLocale from './en/mcp'
import networkLocale from './en/network'
import runtimePatchesLocale from './en/runtimePatches'
import deployStrategyLocale from './en/deployStrategy'
import rightsizingLocale from './en/rightsizing'
import navLocale from './en/nav'
import environmentsLocale from './en/environments'
import globalProjectsLocale from './en/globalProjects'

export default {
  ...common,
  ...workflowsLocale,
  ...triggersLocale,
  ...gitDeploy,
  ...projects,
  ...projectForms,
  ...tutorial,
  ...notificationsLocale,
  ...accountLocale,
  ...aiProjects,
  ...security,
  ...runtimesLocale,
  ...wasmLocale,
  ...rbac,
  // ai: the retired-type notice + GPU / Models screens
  ai: { ...aiProjects.ai, ...aiLocale.ai },
  ...bindingsLocale,
  ...mcpLocale,
  ...networkLocale,
  ...runtimePatchesLocale,
  // deploy strategies (blue-green, canary) and their rollouts
  ...deployStrategyLocale,
  ...rightsizingLocale,
  ...navLocale,
  ...environmentsLocale,
  // Global projects and their global DNS (docs/global-projects.md)
  ...globalProjectsLocale,
  settings: {
    ...settings.settings,
    ...regionDetail.settings,
  },
  tenancy: {
    regionMode_managed:  'Managed',
    regionMode_infinite: 'Infinite',
    dnsInstructions:     'Add the following CNAME records to your DNS provider:',
  },
  users: {
    tab:               'Users',
    title:             'User Management',
    searchPlaceholder: 'Search by name or email...',
    addBtn:            '+ Add User',
    addTitle:          'New User',
    name:              'Name',
    username:          'Username',
    email:             'Email',
    password:          'Password',
    noUsers:           'No users found.',
    created:           'User created.',
    updated:           'User updated.',
    deleted:           'User deleted.',
    deleteTitle:       'Delete User',
    deleteConfirm:     'Are you sure you want to delete "{name}"?',
    reset2FA:          'Reset 2FA',
    reset2FATitle:     'Reset Two-Factor Authentication',
    reset2FAConfirm:   'Are you sure you want to reset 2FA for "{name}"? They will need to set it up again.',
    reset2FASuccess:   '2FA has been reset.',
  },
  tokens: {
    tab:            'API Tokens',
    title:          'API Token Management',
    createTitle:    'Create New Token',
    createBtn:      'Create Token',
    name:           'Token Name',
    namePlaceholder:'e.g. CI/CD, Mobile App',
    expiresAt:      'Expiration',
    expiresOn:      'Expires on {date}',
    abilities:      'Permissions',
    fullAccess:     'Full Access',
    customAccess:   'Custom',
    noTokens:       'No tokens created yet.',
    createdBanner:  'Token created! You won\'t be able to see this value again.',
    createdWarning: 'This token is only shown once. Save it in a secure place.',
    deleteTitle:    'Delete Token',
    deleteConfirm:  'Are you sure you want to delete "{name}"?',
    created:        'Created',
    expires:        'Expires',
    lastUsed:       'Last used',
    expired:        'Expired',
    noExpiry:       'No expiration',
    duration_30d:   '30 days',
    duration_90d:   '90 days',
    duration_180d:  '6 months',
    duration_1y:    '1 year',
    justNow:        'Just now',
    minutesAgo:     '{n} min ago',
    hoursAgo:       '{n}h ago',
    daysAgo:        '{n}d ago',
    createdBy:    'by {name}',
    ability_projects_read:    'View Projects',
    ability_projects_write:   'Edit Projects',
    ability_deployments_read: 'View Deployments',
    ability_deployments_write:'Deploy',
    ability_settings_read:    'View Settings',
    ability_settings_write:   'Edit Settings',
  },
  metrics: {
    range:         'Range',
    avgCpu:        'Avg CPU',
    avgMemory:     'Avg Memory',
    avgRps:        'Avg RPS',
    totalSessions: 'Total Sessions',
    cpuCores:      'CPU (cores)',
    memoryMb:      'Memory (MiB)',
    unitCores:     'cores',
    sessionUsage:  '{cpu} vCPU·h, {memory} GiB·h at the deployment\'s size',
    rps:           'Requests/sec',
    noData:        'No metric data for this period',
    history:       'Metrics History',
  },
}
