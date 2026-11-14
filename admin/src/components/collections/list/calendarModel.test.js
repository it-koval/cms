import { describe, expect, it, vi } from 'vitest'
import { addDays, byDay, dayLabel, dayOf, diffDays, monthDays, shiftMonth, shiftValue, spans, titleOf, weekDays } from './calendarModel.js'

describe('days', () => {
  it('takes the day out of a date or a date with time, and rejects what is no day', () => {
    expect(dayOf('2026-11-05T09:30')).toBe('2026-11-05')
    expect(dayOf('2026-02-31')).toBe('')
    expect(dayOf('')).toBe('')
    expect(dayOf(null)).toBe('')
  })

  it('adds days across month and year ends, in UTC', () => {
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
    expect(addDays('2026-03-29', 1)).toBe('2026-03-30') // a clock change never moves a day
    expect(diffDays('2026-11-05', '2026-11-01')).toBe(4)
  })

  it('shifts a value and keeps the time of day', () => {
    expect(shiftValue('2026-11-05T09:30', 1)).toBe('2026-11-06T09:30')
    expect(shiftValue('2026-11-05', -5)).toBe('2026-10-31')
    expect(shiftValue('nope', 3)).toBe('nope')
  })
})

describe('month and week grids', () => {
  it('shows whole weeks, Monday to Sunday', () => {
    const days = monthDays('2026-11-15')
    expect(days[0]).toBe('2026-10-26') // 1 November 2026 is a Sunday
    expect(days[days.length - 1]).toBe('2026-12-06') // 30 November is a Monday: its week belongs to the grid
    expect(days.length % 7).toBe(0)
  })

  it('gives the seven days of the week of a day', () => {
    expect(weekDays('2026-11-05')).toEqual(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'])
  })

  it('moves by months and lands on the first', () => {
    expect(shiftMonth('2026-12-20', 1)).toBe('2027-01-01')
    expect(shiftMonth('2026-03-31', -1)).toBe('2026-02-01')
  })
})

describe('spans / byDay', () => {
  const rowsList = [
    { id: 1, start: '2026-11-02', end: '2026-11-04' },
    { id: 2, start: '2026-11-03' },
    { id: 3, start: '' },
    { id: 4, start: '2026-11-10', end: '2026-11-08' }, // the end before the start: one day
  ]

  it('skips rows without a start and fixes a wrong end', () => {
    const s = spans(rowsList, 'start', 'end')
    expect(s.map((x) => [x.row.id, x.from, x.to])).toEqual([[1, '2026-11-02', '2026-11-04'], [2, '2026-11-03', '2026-11-03'], [4, '2026-11-10', '2026-11-10']])
  })

  it('puts a span on each visible day with its first and last mark', () => {
    const map = byDay(spans(rowsList, 'start', 'end'), weekDays('2026-11-02'))
    expect(map.get('2026-11-02').map((x) => [x.row.id, x.first, x.last])).toEqual([[1, true, false]])
    expect(map.get('2026-11-04').map((x) => [x.row.id, x.last])).toEqual([[1, true]])
    expect(map.get('2026-11-03').map((x) => x.row.id)).toEqual([1, 2])
    expect(map.get('2026-11-08')).toEqual([])
  })
})

describe('labels', () => {
  it('names a day and a period in Russian', () => {
    expect(dayLabel('2026-11-05')).toBe('5 ноября')
    expect(titleOf('month', '2026-11-05')).toBe('ноябрь 2026')
    expect(titleOf('week', '2026-11-05')).toBe('2 – 8 ноября 2026')
    expect(titleOf('week', '2026-11-30')).toBe('30 ноября – 6 декабря 2026')
  })
})

describe('labels in a browser without Intl data for kk', () => {
  it('keeps the weekday names and gives a week by two numeric dates', async () => {
    const real = Intl.DateTimeFormat
    // what Chrome without the ICU data of kk does: the month comes out as a template
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function (code, options) {
      return code === 'kk' ? { format: () => 'M11 2' } : new real(code, options)
    })
    vi.resetModules()
    const { initI18n } = await import('@/i18n/index.js')
    await initI18n('kk')
    const model = await import('./calendarModel.js')
    expect(model.WEEKDAYS).toHaveLength(7)
    model.WEEKDAYS.forEach((name) => expect(name).toMatch(/^[А-Яа-я]{2}$/))
    expect(model.weekdayShort('2026-11-02')).toMatch(/^[а-я]{2}$/)
    expect(model.titleOf('week', '2026-11-05')).toBe('2026 ж. 02.11 – 08.11')
    expect(model.titleOf('month', '2026-11-05')).toBe('11.2026')
    vi.restoreAllMocks()
  })
})
