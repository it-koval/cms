// Dates and numbers in the language of the interface (i18n/index.js).
import { formatDate, formatNumber, locale, t } from '@/i18n/index.js'
const pad = (n) => String(n).padStart(2, '0')
const dayKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const toDate = (v) => (v instanceof Date ? v : new Date(v))

/** '2026-10-28' for a Date or ISO string (local date). */
export const dayOf = (v) => dayKey(toDate(v))

// English is read without the leading zero («7:40 PM»), Russian and Kazakh keep «09:04»
const clock = (d) => formatDate(d, { hour: locale === 'en' ? 'numeric' : '2-digit', minute: '2-digit' })

/** «10:12» / «10:12 AM»: the time of day of a date or an ISO string, local to the browser */
export const fmtTime = (v) => clock(toDate(v))

/** «сегодня, 10:12» / «вчера, 21:03» / «26 октября, 19:40» */
export function fmtDT(v) {
  const d = toDate(v)
  const now = new Date()
  const y = new Date(now); y.setDate(now.getDate() - 1)
  const k = dayKey(d)
  const time = clock(d)
  if (k === dayKey(now)) return t('core.date_today_at', { time })
  if (k === dayKey(y)) return t('core.date_yesterday_at', { time })
  return t('core.date_at', { date: formatDate(d, { day: 'numeric', month: 'long' }), time })
}

/** «28.10.2026» */
export const fmtD = (v) => formatDate(toDate(v), { day: '2-digit', month: '2-digit', year: 'numeric' })

/** «12 минут назад» / «сегодня, 09:14» / «вчера» / «7 окт 2026» */
export function fmtUpdated(v) {
  if (!v) return ''
  const d = toDate(v)
  const diff = Date.now() - d.getTime()
  const min = Math.round(diff / 60000)
  if (min < 1) return t('core.date_just_now')
  if (min < 60) return t('core.date_minutes_ago', { count: min })
  const k = dayKey(d)
  const now = new Date()
  const y = new Date(now); y.setDate(now.getDate() - 1)
  if (k === dayKey(now)) return t('core.date_today_at', { time: clock(d) })
  if (k === dayKey(y)) return t('core.date_yesterday')
  const text = formatDate(d, { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' })
  return locale === 'ru' ? text.replace('.', '') : text // only the Russian «окт.» loses its dot; in kk the first dot may stand after the year («2025 ж.»)
}

/** What a page that came over from the site before the CMS says in place of the time of its change, until its first edit. */
export const MIGRATED_NOTE = t('core.date_migrated_note')

/** Page «изменена» column: migrated pages say so until the first edit. */
export const fmtPageUpdated = (p) => (p.migrated ? MIGRATED_NOTE : fmtUpdated(p.updatedAt))

/** 11000 -> «11 000» (non-breaking space) */
export const nf = (n) => (n === '' || n == null ? '' : formatNumber(n).replace(/\s/g, ' '))

/** «Анна Сергеева» -> «АС», «Студия ITK» -> «СI», one word -> one letter. */
export function initialsOf(name) {
  const w = String(name || '').trim().split(/\s+/).filter(Boolean)
  return ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase()
}

/** Address of the site for a link: the «domain» setting may carry a scheme («http://localhost:8091», local development) or not («example.com», https). */
export const siteOrigin = (domain) => (/^https?:\/\//i.test(domain) ? domain : `https://${domain}`).replace(/\/+$/, '')
