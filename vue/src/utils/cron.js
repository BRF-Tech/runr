const DAY_KEYS = [
  'cron.daySunday', 'cron.dayMonday', 'cron.dayTuesday', 'cron.dayWednesday',
  'cron.dayThursday', 'cron.dayFriday', 'cron.daySaturday',
]

/**
 * Returns a human-readable, locale-aware description of a cron expression.
 * @param {string} expr  – standard 5-field cron expression
 * @param {Function} t   – vue-i18n translate function
 */
export function describeCron(expr, t) {
  if (!expr?.trim()) return ''
  const parts = expr.trim().split(/\s+/)
  if (parts.length !== 5) return expr
  if (!t) return expr

  const [min, hour, dom, month, dow] = parts
  const simple = dom === '*' && month === '*'

  if (expr === '* * * * *') return t('cron.everyMinute')

  const everyMin = min.match(/^\*\/(\d+)$/)
  if (everyMin && hour === '*' && simple && dow === '*')
    return t('cron.everyNMinutes', { n: everyMin[1] })

  const everyHour = hour.match(/^\*\/(\d+)$/)
  if (everyHour && min === '0' && simple && dow === '*')
    return t('cron.everyNHours', { n: everyHour[1] })

  if (min === '0' && hour === '*' && simple && dow === '*')
    return t('cron.everyHour')

  const fixedMin  = !min.includes('*')  && !min.includes('/')
  const fixedHour = !hour.includes('*') && !hour.includes('/')
  const hhmm      = fixedHour && fixedMin
    ? `${hour.padStart(2, '0')}:${min.padStart(2, '0')}`
    : null

  if (hhmm && dom === '*' && month === '*' && dow === '*')
    return t('cron.everyDay', { time: hhmm })

  if (hhmm && dom === '*' && month === '*' && dow === '1-5')
    return t('cron.weekdays', { time: hhmm })

  if (hhmm && dom === '*' && month === '*' && (dow === '0,6' || dow === '6,0'))
    return t('cron.weekends', { time: hhmm })

  const dowNum = parseInt(dow)
  if (hhmm && dom === '*' && month === '*' && !isNaN(dowNum) && !dow.includes(',') && !dow.includes('-'))
    return t('cron.everyWeekday', { day: t(DAY_KEYS[dowNum] ?? DAY_KEYS[0]), time: hhmm })

  if (hhmm && !dom.includes('*') && month === '*' && dow === '*')
    return t('cron.monthlyDay', { day: dom, time: hhmm })

  return expr
}
