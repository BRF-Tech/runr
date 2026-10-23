// Rightsizing recommendations (docs/monitoring.md#rightsizing): the card on a project's page and
// the organization's list (/rightsizing).
export default {
  rightsizing: {
    title:        'Rightsizing',
    link:         '{count} size recommendation(s)',
    linkSaving:   '{count} size recommendation(s) · {amount}/month',

    titleDown:    'This project can run smaller',
    titleUp:      'This project needs more resources',
    titleMixed:   'This project needs a different size',

    memoryDown:   'Memory: requests {current} MB but used at most {used} MB in the last {days} days → {recommended} MB is enough.',
    memoryUp:     'Memory: {current} MB is too little ({why}) → {recommended} MB.',
    cpuDown:      'CPU: requests {current}m but used {used}m (95th percentile) in the last {days} days → {recommended}m is enough.',
    cpuUp:        'CPU: {current}m is too little ({why}) → {recommended}m.',

    saving:       'about {amount} less per month',
    cost:         'about {amount} more per month',
    usageBasis:   'billed by use: frees capacity, not money',
    fixedBasis:   'the allocation could shrink by {resources} (about {amount} per month)',
    fixedGrow:    'the allocation must grow by {resources} (about {amount} per month)',
    frees:        'Frees {resources}',
    takes:        'Takes {resources} more',
    perPods:      '× {pods} pods',

    apply:        'Apply',
    snooze:       'Snooze 30 days',
    dismiss:      'Dismiss',
    showEvidence: 'Evidence',
    hideEvidence: 'Hide evidence',

    applied:      'Saved as {version}. Deploy the project to run the new size.',
    pendingDeploy: 'The recommended size is saved. Deploy the project to run it.',
    snoozed:      'Hidden for 30 days.',
    dismissed:    'Dismissed. It comes back only when the recommended size changes.',
    blocked:      'Applying it would be refused now: {message}',
    confirmApply: 'Change {project} from {current} to {recommended}? The change is saved as a new version; the next deploy runs it.',

    reasons: {
      memory_over_provisioned: 'memory unused',
      cpu_over_provisioned:    'CPU unused',
      memory_oom_killed:       'killed for memory',
      memory_near_limit:       'memory near its limit',
      cpu_throttled:           'CPU throttled',
    },

    evidence: {
      window:      'Window',
      windowValue: '{from} – {to}: {days} days with readings, {sessions} sessions',
      memoryMax:   'Memory, highest',
      memoryP95:   'Memory, 95th percentile',
      cpuMax:      'CPU, highest',
      cpuP95:      'CPU, 95th percentile',
      oomKills:    'OOM kills',
      throttled:   'CPU throttled',
      throttledValue: '{percent} % of periods',
      rule:        'Memory = highest × {memoryMargin}, CPU = 95th percentile × {cpuMargin}, rounded up to the next size.',
    },

    page: {
      title:      'Rightsizing recommendations',
      subtitle:   'Projects whose size differs from what their pods used in the last days. Recalculated every night.',
      empty:      'No open recommendation: the running projects are right-sized, or RunR is still collecting their usage.',
      project:    'Project',
      now:        'Now',
      recommended: 'Recommended',
      why:        'Why',
      frees:      'Frees',
      monthly:    'Per month',
      summary:    '{count} recommendation(s) · about {amount} per month in all',
      summaryResources: '{count} recommendation(s) · frees {memory} MB memory and {cpu}m CPU',
    },
  },
}
