// The arithmetic of the calendar view of a list: days as 'YYYY-MM-DD' strings, weeks start on Monday. Pure code, no Vue, no api; days are worked out in UTC so a change of the clock never moves a record by a day.
//
//   dayOf(value)                      'YYYY-MM-DD' from a date or a date with time ('2026-11-05T09:30'), '' when the value is no date
//   addDays(day, n) / diffDays(a, b)  day arithmetic (diffDays(a, b) = a - b in days)
//   shiftValue(value, n)              the same date value moved by n days, the time of day stays ('2026-11-05T09:30' + 1 → '2026-11-06T09:30')
//   monthDays(day)                    every day of the weeks the month of `day` touches (whole weeks, Monday to Sunday)
//   weekDays(day)                     the 7 days of the week of `day`
//   spans(rows, startKey, endKey)     [{ row, from, to }] for the rows that have a start; `to` = the end (or the start when there is none or it is earlier)
//   byDay(spans, days)                Map day → [{ row, first, last }]: what stands on each visible day (first/last day of the span)
//   titleOf(mode, day) / dayLabel(day)  «ноябрь 2026» / «1 – 7 ноября 2026», «5 ноября» (the words come from Intl in the language of the screen)
//   WEEKDAYS                          the short names of Monday … Sunday
import { formatDate, locale, t } from '@/i18n/index.js'
const DAY = 86400000
const utc = (day) => { const [y, m, d] = day.split('-').map(Number); return Date.UTC(y, m - 1, d) }
const fromUtc = (ms) => new Date(ms).toISOString().slice(0, 10)

export function dayOf(value) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''))
  if (!m) return ''
  const t = Date.UTC(+m[1], +m[2] - 1, +m[3])
  return fromUtc(t).startsWith(`${m[1]}-${m[2]}-${m[3]}`) ? `${m[1]}-${m[2]}-${m[3]}` : '' // 2026-02-31 is no day
}
export const addDays = (day, n) => fromUtc(utc(day) + n * DAY)
export const diffDays = (a, b) => Math.round((utc(a) - utc(b)) / DAY)
export function shiftValue(value, n) {
  const day = dayOf(value)
  if (!day) return value
  return addDays(day, n) + String(value).slice(10)
}
const mondayOf = (day) => addDays(day, -((new Date(utc(day)).getUTCDay() + 6) % 7))
export const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

export function monthDays(day) {
  const first = `${day.slice(0, 7)}-01`
  const next = new Date(utc(first))
  next.setUTCMonth(next.getUTCMonth() + 1)
  const last = addDays(fromUtc(next.getTime()), -1)
  const from = mondayOf(first)
  const to = addDays(mondayOf(last), 6)
  const out = []
  for (let d = from; d <= to; d = addDays(d, 1)) out.push(d)
  return out
}
export function weekDays(day) {
  const from = mondayOf(day)
  return Array.from({ length: 7 }, (_, i) => addDays(from, i))
}
export function shiftMonth(day, n) {
  const d = new Date(utc(`${day.slice(0, 7)}-01`))
  d.setUTCMonth(d.getUTCMonth() + n)
  return fromUtc(d.getTime())
}

export function spans(rows, startKey, endKey) {
  const out = []
  ;(rows || []).forEach((row) => {
    const from = dayOf(row[startKey])
    if (!from) return
    const end = endKey ? dayOf(row[endKey]) : ''
    out.push({ row, from, to: end && end >= from ? end : from })
  })
  return out
}
export function byDay(list, days) {
  const map = new Map(days.map((d) => [d, []]))
  const first = days[0]
  const last = days[days.length - 1]
  list.forEach(({ row, from, to }) => {
    if (to < first || from > last) return
    for (let d = from < first ? first : from; d <= to && d <= last; d = addDays(d, 1)) map.get(d).push({ row, first: d === from, last: d === to })
  })
  return map
}

const UTC = { timeZone: 'UTC' } // days are 'YYYY-MM-DD' worked out in UTC
const asDate = (day) => new Date(utc(day))
const cap = (s) => s.charAt(0).toLocaleUpperCase(locale) + s.slice(1)
/** «5 ноября» */
export const dayLabel = (day) => formatDate(asDate(day), { ...UTC, day: 'numeric', month: 'long' })
/** The name of the month as it stands next to a day number («ноября»). */
const monthWithDay = (day) => dayLabel(day).replace(/\d+/, '').replace(/^[\s.,]+|[\s.,]+$/g, '')
export function titleOf(mode, day) {
  if (mode === 'month') {
    const month = formatDate(asDate(day), { ...UTC, month: 'long' })
    // without Intl data the month is a number («11»): «11 2026» reads as two numbers, so it is written as a date, «11.2026»
    return /^\d+$/.test(month) ? `${month}.${day.slice(0, 4)}` : t('collections.cal_month_title', { month, year: day.slice(0, 4) })
  }
  const w = weekDays(day)
  const a = w[0]
  const b = w[6]
  // without Intl data the day is numeric («02.11»): there is no month word to put into a sentence, so the range is given by two dates
  if (a.slice(0, 7) === b.slice(0, 7) && !/\d/.test(monthWithDay(a))) return t('collections.cal_week_same_month', { from: +a.slice(8), to: +b.slice(8), month: monthWithDay(a), year: a.slice(0, 4) })
  return t('collections.cal_week_two_months', { from: dayLabel(a), to: dayLabel(b), year: b.slice(0, 4) })
}
/** The short name of a day of the week («пн»), as Intl has it. */
export const weekdayShort = (day) => formatDate(asDate(day), { ...UTC, weekday: 'short' })
// 2026-01-05 is a Monday
export const WEEKDAYS = Array.from({ length: 7 }, (_, i) => cap(weekdayShort(addDays('2026-01-05', i))))
