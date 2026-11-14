// What the «Яндекс Вебмастер» card of «Сегодня» draws for a status body of api/webmaster.js: the logic without the DOM.
import { formatDate, t } from '@/i18n/index.js'
import { nf } from '@/utils/format.js'

export const HOW_TO_TOKEN = 'https://yandex.ru/dev/webmaster/doc/ru/tasks/how-to-get-oauth'

/** The states in which a token is stored: the card has the «Обновлено / Обновить / Открыть / Отключить» line. */
const CONNECTED = new Set(['token_invalid', 'verifying', 'verification_failed', 'ready'])
export const isConnected = (s) => CONNECTED.has(s.state)

/** What the body of the card is: a form for the token, a note, or the numbers. */
export function bodyKind(s) {
  switch (s.state) {
    case 'not_connected': return s.canManage ? 'connect' : 'ask_admin'
    case 'token_invalid': return s.canManage ? 'renew' : 'ask_admin_renew'
    case 'no_address': case 'no_http_client': case 'verifying': case 'verification_failed': case 'ready': return s.state
    default: return 'unknown'
  }
}

/** Is the token form drawn above the body (an expiring token of a working connection)? Only for a person who may manage it. */
export const renewsSoon = (s) => isConnected(s) && s.state !== 'token_invalid' && !!s.tokenExpiresSoon

export const isExpired = (s, now = Date.now()) => !!s.tokenExpiresAt && new Date(s.tokenExpiresAt).getTime() <= now

const REASONS = { META_TAG_NOT_FOUND: 'ui.ym_reason_meta_tag_not_found' }
/** Why Yandex did not verify the site, in plain words. A reason that is not known is named by its code. */
export function failReasonText(reason) {
  if (!reason) return t('ui.ym_reason_unknown')
  return REASONS[reason] ? t(REASONS[reason]) : t('ui.ym_reason_other', { code: reason })
}

const PROBLEMS = [
  ['FATAL', 'ui.ym_problem_fatal', 'fail'],
  ['CRITICAL', 'ui.ym_problem_critical', 'fail'],
  ['POSSIBLE_PROBLEM', 'ui.ym_problem_possible', ''],
  ['RECOMMENDATION', 'ui.ym_problem_recommendation', ''],
]
/** The groups of site problems with something in them: [{ code, label, count, tone: 'fail'|'' }]. FATAL and CRITICAL come first and are drawn red. */
export function problemGroups(problems) {
  return PROBLEMS
    .map(([code, key, tone]) => ({ code, label: t(key), count: Number((problems || {})[code]) || 0, tone }))
    .filter((g) => g.count > 0)
}

/** A number of Yandex that may be undefined: '—' then. */
export const num = (n) => (n == null ? '—' : nf(n))

/** '2026-10-30' as a date of the day (not shifted by the time zone) */
const day = (v) => {
  const [y, m, d] = String(v).split('-').map(Number)
  return new Date(y, m - 1, d)
}
/** «30 окт – 5 нояб» */
export function periodText(q) {
  const f = (v) => formatDate(day(v), { day: 'numeric', month: 'short' })
  return t('ui.ym_period', { from: f(q.from), to: f(q.to) })
}
