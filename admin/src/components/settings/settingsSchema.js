// Settings screen: the left navigation, the settings groups of the site, validation shared by the view and the panels.
// The screen has NINE items (NAV): «Основное» (ALL settings groups of the site schema, one card per group), Меню, Переадресации, SEO, Уведомления,
// «Пользователи и роли» and «SEO» (two segments each, one item), «Оформление админки» and Шаблоны; they are the same on every site. Fields flagged `tr: true` are the TEXTS of one content language;
// every other field is shared by all languages.
// Route /settings/:tab? takes an item key, `roles` (the item «Пользователи и роли», segment Роли), `seo-files` (the item «SEO», segment «robots.txt и llms.txt») or the key of a settings group (old links: «Основное», scrolled to that card).
import { onSchema, schema } from '@/schema/registry.js'
import { t } from '@/i18n/index.js'

// the items of the left navigation, under small headings; `area` = the permission area of the item; «Основное» opens with the area of any settings group (itemAreas)
const NAV_DEF = [
  { heading: t('settings.nav_site'), items: [
    { key: 'general', label: t('settings.nav_general') },
    { key: 'menus', label: t('settings.nav_menus'), area: 'set:menus' },
    { key: 'redirects', label: t('settings.nav_redirects'), area: 'set:redirects' },
    { key: 'seo', label: t('settings.nav_seo'), area: 'set:seo' },
  ] },
  { heading: t('settings.nav_requests_mail'), items: [
    { key: 'notifications', label: t('settings.nav_notifications'), area: 'set:notifications' },
    { key: 'mail', label: t('settings.nav_mail'), area: 'set:mail' },
  ] },
  { heading: t('settings.nav_access'), items: [{ key: 'users', label: t('settings.nav_users'), area: 'set:users' }, { key: 'mcp', label: t('settings.nav_mcp'), area: 'set:mcp' }] },
  { heading: t('settings.nav_panel'), items: [{ key: 'theme', label: t('settings.nav_theme'), area: 'set:theme' }] },
  { heading: t('settings.nav_developer'), items: [{ key: 'templates', label: t('settings.nav_templates'), area: 'templates' }] },
]
export const NAV = NAV_DEF
const ITEMS = NAV_DEF.flatMap((n) => n.items)
// route keys that are not settings groups; a group of the site with such a key is reached as `g-<key>`
const RESERVED = new Set([...ITEMS.map((i) => i.key), 'roles', 'seo-files'])

// GROUPS = the settings groups of the site ({ key, route: its key in the URL, title, area }); ONE array, filled in place when the schema is loaded.
export const GROUPS = []
onSchema(() => {
  GROUPS.splice(0, GROUPS.length, ...schema.settings.groups.map((g) => ({ key: g.key, route: RESERVED.has(g.key) ? 'g-' + g.key : g.key, title: g.title, area: 'set:' + g.key })))
})

/** Permission areas of an item: any of them opens it. */
export const itemAreas = (item) => (item.key === 'general' ? GROUPS.map((g) => g.area) : [item.area])
/** Every permission area of the screen: the groups, then the other items («Пользователи» and «Роли» are one area `set:users`). */
export const settingsAreas = () => [...GROUPS.map((g) => g.area), ...ITEMS.filter((i) => i.area).map((i) => i.area)]

/** What /settings/:tab? points at: { item, group?, segment? } or null for an empty / unknown key. */
export function resolveTab(param) {
  if (!param) return null
  if (param === 'roles') return { item: 'users', segment: 'roles' }
  if (param === 'users') return { item: 'users', segment: 'users' }
  if (param === 'seo-files') return { item: 'seo', segment: 'files' }
  if (param === 'seo') return { item: 'seo', segment: 'rules' }
  if (ITEMS.some((i) => i.key === param)) return { item: param }
  const g = GROUPS.find((x) => x.route === param)
  return g ? { item: 'general', group: g.key } : null
}

export const FALLBACK_ROUTE = '*'

/** Sheets of the form tab of a settings group: [{ key, title, desc, fields }] (sub-fields are checked by the screen itself, so the form engine's generic «секция не покажется» never appears). */
const noSubReq = (f) => (f.sub ? { ...f, sub: f.sub.map(({ req, ...s }) => s) } : f)
export function buildSections(groupKey) {
  const g = schema.settings.groups.find((x) => x.key === groupKey)
  return g ? [{ key: g.key, title: g.title, desc: g.description || '', fields: g.fields.map(noSubReq) }] : []
}

/** Telegram chat id: a number like -1002145550192 or a channel name like @my_channel. '' = empty (the caller decides if that is allowed). */
export function chatIdError(v) {
  const s = String(v || '').trim()
  if (!s) return ''
  return /^-?\d{5,20}$/.test(s) || /^@[A-Za-z][A-Za-z0-9_]{4,31}$/.test(s) ? '' : t('settings.err_chat_id')
}

/** The addresses of a list «a@x.com, b@x.com» (commas, semicolons, spaces, line breaks). */
export const splitEmails = (v) => String(v || '').split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean)

/** Error of one list of e-mail addresses ('' = fine, an empty list is fine). Same rule as the server: at most 10, each looks like an address. */
export function emailsError(v) {
  const list = splitEmails(v)
  if (list.length > 10) return t('settings.err_emails_limit')
  const bad = list.find((x) => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x))
  return bad ? t('settings.err_email_bad', { value: bad }) : ''
}

/** Errors of the settings object: { key: message }. Keys match the field keys; tgRoutes is the Telegram table, mailRoutes the e-mail table. Required fields of the schema groups are checked here, the server checks them again. */
export function settingsErrors(s) {
  const e = {}
  schema.settings.groups.forEach((g) => g.fields.forEach((f) => {
    if (f.t === 'readonly') return
    const v = s[f.k]
    if (f.req && (v === undefined || v === null || (typeof v === 'string' && !v.trim()) || (Array.isArray(v) && !v.length))) e[f.k] = t('settings.err_fill_field', { label: f.label })
    else if (f.t === 'text' && f.inputType === 'email' && v && !/^\S+@\S+\.\S+$/.test(String(v).trim())) e[f.k] = t('settings.err_email_typo')
    else if (f.t === 'repeater' && Array.isArray(v) && v.some((row) => (f.sub || []).some((sf) => sf.req && !String(row[sf.k] ?? '').trim()))) e[f.k] = t('settings.err_fill_all', { label: f.label })
  }))
  const routes = s.tgRoutes || {}
  if (s.tgOn !== false) {
    // «Остальные формы» needs a chat, unless the server has chats of its own (s.tgFallback, a yes / no: config / ITK_TELEGRAM_CHAT_IDS)
    // or every form of the site has its own chat; the server checks the same rule
    const chat = (k) => !!String(routes[k] || '').trim()
    const allRouted = schema.forms.length > 0 && schema.forms.every((f) => chat(f.key))
    if (!chat(FALLBACK_ROUTE) && !s.tgFallback && !allRouted) e.tgRoutes = t('settings.err_chat_required')
    else if (Object.values(routes).some((v) => chatIdError(v))) e.tgRoutes = t('settings.err_chat_ids')
  }
  const mails = s.mailRoutes || {}
  if (Object.values(mails).some((v) => emailsError(v))) e.mailRoutes = t('settings.err_mails')
  return e
}

/** Which item shows the error of settings key `k`: Уведомления (the Telegram and e-mail tables) or Основное (a field of a group). */
export const itemOfKey = (k) => (k === 'tgRoutes' || k === 'mailRoutes' ? 'notifications' : 'general')

/** The settings group that owns the field `k` (a key of the site schema), or null. */
export const groupOfKey = (k) => (schema.settings.groups.find((x) => x.fields.some((f) => f.k === k)) || {}).key || null
