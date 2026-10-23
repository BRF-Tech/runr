import { describe, it, expect } from 'vitest'
import {
  WARMER_MAX_ROWS, DEFAULT_TIMEZONE, isWarmerCron, describeWarmerCron, warmerCountLimit, warmerRowError,
  warmerScheduleError, scaleSchedulePayload, scheduleForm, defaultWarmerRows, weekdayLabels,
  timelinePeak, rowErrorsFrom, timeZoneOptions,
} from '@/utils/warmSchedule'

describe('isWarmerCron', () => {
  it('accepts five fields with ranges, lists, steps and names', () => {
    expect(isWarmerCron('0 9 * * 1-5')).toBe(true)
    expect(isWarmerCron('*/15 8-17 * * MON-FRI')).toBe(true)
    expect(isWarmerCron(' 30 7,12 1 JAN ? ')).toBe(true)
  })

  it('refuses macros, seconds, four fields and Quartz extensions', () => {
    expect(isWarmerCron('@daily')).toBe(false)
    expect(isWarmerCron('0 0 9 * * 1-5')).toBe(false)
    expect(isWarmerCron('0 9 * *')).toBe(false)
    expect(isWarmerCron('0 9 * * 5L')).toBe(false)
    expect(isWarmerCron('0 9 * * 1#2')).toBe(false)
    expect(isWarmerCron('')).toBe(false)
    expect(isWarmerCron(null)).toBe(false)
  })
})

describe('describeWarmerCron (cronstrue)', () => {
  it('describes a row in English with the 24-hour clock', () => {
    expect(describeWarmerCron('0 9 * * 1-5', 'en')).toBe('At 09:00, Monday through Friday')
    expect(describeWarmerCron('30 18 * * *', 'en')).toBe('At 18:30')
  })

  it('describes a row in Turkish (the bundled tr locale)', () => {
    expect(describeWarmerCron('0 9 * * 1-5', 'tr')).toBe('Saat 09:00, Pazartesi ile Cuma arasında')
    expect(describeWarmerCron('0 9 * * 1-5', 'tr-TR')).toContain('Pazartesi')
  })

  it('is null for what cronstrue or the five-field rule rejects, instead of throwing', () => {
    expect(describeWarmerCron('61 9 * * *', 'en')).toBeNull()
    expect(describeWarmerCron('@hourly', 'en')).toBeNull()
    expect(describeWarmerCron('nonsense', 'tr')).toBeNull()
  })
})

describe('row and schedule checks', () => {
  it('limits the count to min(10, max_scale)', () => {
    expect(warmerCountLimit(3)).toBe(3)
    expect(warmerCountLimit(1000)).toBe(10)
    expect(warmerCountLimit(undefined)).toBe(1)
  })

  it('names the first problem of a row', () => {
    expect(warmerRowError({ cron: '', min_scale: 1 }, 5)).toEqual({ key: 'warmer.errors.cronRequired' })
    expect(warmerRowError({ cron: '0 25 * * *', min_scale: 1 }, 5)).toEqual({ key: 'warmer.errors.cronInvalid' })
    expect(warmerRowError({ cron: '0 9 * * *', min_scale: '' }, 5)).toEqual({ key: 'warmer.errors.countInvalid' })
    expect(warmerRowError({ cron: '0 9 * * *', min_scale: -1 }, 5)).toEqual({ key: 'warmer.errors.countInvalid' })
    expect(warmerRowError({ cron: '0 9 * * *', min_scale: 1.5 }, 5)).toEqual({ key: 'warmer.errors.countInvalid' })
    expect(warmerRowError({ cron: '0 9 * * *', min_scale: 6 }, 5)).toEqual({ key: 'warmer.errors.countAboveMax', params: { max: 5 } })
    expect(warmerRowError({ cron: '0 9 * * *', min_scale: 11 }, 1000)).toEqual({ key: 'warmer.errors.countAboveMax', params: { max: 10 } })
    expect(warmerRowError({ cron: '0 9 * * *', min_scale: 0 }, 1)).toBeNull()
  })

  it('a schedule is off (null), or 1..10 valid rows', () => {
    expect(warmerScheduleError(null, 5)).toBeNull()
    expect(warmerScheduleError([], 5)).toEqual({ key: 'warmer.errors.noRows' })
    const many = Array.from({ length: WARMER_MAX_ROWS + 1 }, () => ({ cron: '0 9 * * *', min_scale: 1 }))
    expect(warmerScheduleError(many, 5)).toEqual({ key: 'warmer.errors.tooManyRows', params: { max: WARMER_MAX_ROWS } })
    expect(warmerScheduleError([{ cron: '0 9 * * *', min_scale: 1 }, { cron: 'x', min_scale: 0 }], 5)).toEqual({ key: 'warmer.errors.cronInvalid' })
    expect(warmerScheduleError(defaultWarmerRows(5), 5)).toBeNull()
  })
})

describe('payload and form', () => {
  it('sends trimmed, single-spaced crons and whole counts; nothing is null', () => {
    expect(scaleSchedulePayload([{ cron: '  0   9 * *  1-5 ', min_scale: '3' }, { cron: '0 18 * * 1-5', min_scale: 0 }]))
      .toEqual([{ cron: '0 9 * * 1-5', min_scale: 3 }, { cron: '0 18 * * 1-5', min_scale: 0 }])
    expect(scaleSchedulePayload([])).toBeNull()
    expect(scaleSchedulePayload(null)).toBeNull()
  })

  it('copies a stored schedule for editing, null when off', () => {
    const stored = [{ cron: '0 9 * * *', min_scale: 2 }]
    const form = scheduleForm(stored)
    expect(form).toEqual(stored)
    form[0].min_scale = 5
    expect(stored[0].min_scale).toBe(2)
    expect(scheduleForm(null)).toBeNull()
    expect(scheduleForm([])).toBeNull()
  })

  it('starts with weekday mornings warm, within the max', () => {
    expect(defaultWarmerRows(5)).toEqual([{ cron: '0 9 * * 1-5', min_scale: 1 }, { cron: '0 18 * * 1-5', min_scale: 0 }])
    // max_scale 0 is none set (the API's default for a count is one container): warmerCountLimit
    expect(defaultWarmerRows(0)[0].min_scale).toBe(1)
    expect(defaultWarmerRows(1)[0].min_scale).toBe(1)
  })
})

describe('preview helpers', () => {
  it('weekday labels start on Monday', () => {
    expect(weekdayLabels('en')).toEqual(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
    expect(weekdayLabels('tr')[0]).toBe('Pzt')
  })

  it('finds the peak of a timeline', () => {
    const week = Array.from({ length: 7 }, () => Array(24).fill(0))
    week[2][10] = 4
    week[5][3] = 1
    expect(timelinePeak(week)).toBe(4)
    expect(timelinePeak(null)).toBe(0)
  })

  it('maps preview and save errors to their rows', () => {
    expect(rowErrorsFrom({
      'scale_schedule.0.cron': ['bad cron'],
      'config.scale_schedule.2.min_scale': ['too many'],
      'scale_schedule.0.min_scale': ['second message of row 0'],
      max_scale: ['other'],
    })).toEqual({ 0: 'bad cron', 2: 'too many' })
    expect(rowErrorsFrom(undefined)).toEqual({})
  })

  it('offers the IANA zones, with the default among them', () => {
    const zones = timeZoneOptions()
    expect(zones).toContain(DEFAULT_TIMEZONE)
    expect(zones).toContain('UTC')
  })
})
