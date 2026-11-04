// The language of the interface. It is not the language of the content: that is app.locale of stores/app.js (main_locale, the language versions of pages).
//   import { t, locale, formatDate, formatNumber, collator } from '@/i18n/index.js'
//   t('area.key', { name: x })     a text of admin/src/i18n/<locale>/<area>.json; {name} in the text. A key that is missing comes from English, then it is the key itself (console.warn in dev)
//   t('area.key', { count: n })    the value is an object by the categories of Intl.PluralRules: {"one":…,"few":…,"many":…,"other":…} for ru, {"one":…,"other":…} for en, {"other":…} for kk
//   locale                         'en' | 'ru' | 'kk': the language of the screen (a live binding, it does not change until the page reloads)
//   formatDate(value, options)     Intl.DateTimeFormat in the language of the screen; an empty or wrong date gives '' (Intl alone would throw while the screen is drawn). Where the browser has no Intl data for kk, dates are numeric (see hasIntlData)
//   formatNumber(value, options)   Intl.NumberFormat in the language of the screen
//   collator(options).compare      Intl.Collator in the language of the screen; list.sort(collator.compare) when the defaults will do
//   setLocale(code)                saves the choice of the person (null = the language of the site) and reloads the page: a language never changes under a running screen
//   locales()                      [{ code, name }] what he may choose from, each language named in itself
// main.js calls initI18n() BEFORE it imports the rest of the app, so t() works at the top of a module too: in constants, schemas, the list of the menu.
// The server gives the language in window.ITK_ADMIN (locale, locales). `npm run dev` and `npm run dev:mock` have no shell page: there it is remembered in localStorage, English until a person chooses.
// A plugin of a site adds its own texts with addMessages() (window.ITK.i18n.add).
const FALLBACK = 'en'
const REMEMBERED = 'itk-admin:ui-locale'
const BUILT_IN = [{ code: 'en', name: 'English' }, { code: 'ru', name: 'Русский' }, { code: 'kk', name: 'Қазақша' }]

// each language is one chunk of the build (manualChunks in vite.config.js)
const files = import.meta.glob('./*/*.json')
const catalogs = {}
let plurals = new Intl.PluralRules(FALLBACK)
let standalone = false // no shell page gave the language: it is kept in this browser

/** The language of the screen. */
export let locale = FALLBACK

const shell = () => (typeof window !== 'undefined' && window.ITK_ADMIN) || {}

/** What a person chose in a browser without a shell page, or null. */
export function remembered() {
  try {
    return localStorage.getItem(REMEMBERED)
  } catch (e) {
    return null
  }
}

function remember(code) {
  try {
    if (code) localStorage.setItem(REMEMBERED, code)
    else localStorage.removeItem(REMEMBERED)
  } catch (e) { /* not remembered */ }
}

async function load(code) {
  const parts = await Promise.all(Object.keys(files).filter((path) => path.startsWith(`./${code}/`)).map((path) => files[path]()))
  catalogs[code] = Object.assign({}, ...parts.map((part) => part.default), catalogs[code])
}

/** What the person may choose from. */
export const locales = () => (Array.isArray(shell().locales) && shell().locales.length ? shell().locales : BUILT_IN)

/** Loads the catalogue of the language (given by a test, else the server's, else the remembered one) and the English one behind it. */
export async function initI18n(code) {
  const given = code || shell().locale
  standalone = !given
  const wanted = given || remembered() || FALLBACK
  locale = locales().some((l) => l.code === wanted) ? wanted : FALLBACK
  plurals = new Intl.PluralRules(locale)
  degraded = locale in STAND_IN && !hasIntlData(locale)
  formatters.clear()
  if (typeof document !== 'undefined') document.documentElement.lang = locale
  await Promise.all([...new Set([locale, FALLBACK])].map(load))
}

/**
 * Texts from outside the bundle: the catalogue of a plugin. Added on top: a key of the admin may be replaced, but only for t() called after the admin started.
 * Plugins run after the modules of the app, so a constant of a module (the list of the menu, a schema) already holds the text of the admin.
 */
export function addMessages(code, messages) {
  catalogs[code] = { ...catalogs[code], ...messages }
}

const lookup = (key) => {
  const own = catalogs[locale]
  if (own && key in own) return own[key]
  const fallback = catalogs[FALLBACK]
  return fallback && key in fallback ? fallback[key] : undefined
}

export function t(key, params) {
  let text = lookup(key)
  if (text === undefined) {
    if (import.meta.env.DEV) console.warn(`[i18n] no text for "${key}" (${locale})`)
    return key
  }
  if (typeof text === 'object') text = text[plurals.select(Number(params && params.count))] ?? text.other ?? ''
  if (!params) return text
  return text.replace(/\{(\w+)\}/g, (whole, name) => (name in params ? String(params[name]) : whole))
}

// Some browsers (Chrome builds without the full ICU data, checked on 152 and 156) say supportedLocalesOf('kk') is fine, yet format a date as «M11 2, Mon» and a number as «1,234,567.5».
// So the locale is probed once; one that fails is formatted through a stand-in with the same script and separators, and dates lose the names of months and days: they stay numeric.
const STAND_IN = { kk: 'ru' }
const PROBE = new Date(2026, 10, 2, 12)
let degraded = false // the browser has no Intl data for the language of the screen

/** True when Intl really knows the language: a long month is a word, not a template, and the decimal mark is not the English one. */
export function hasIntlData(code) {
  try {
    const month = new Intl.DateTimeFormat(code, { month: 'long' }).format(PROBE)
    const mark = (lang) => new Intl.NumberFormat(lang).formatToParts(1.5).find((p) => p.type === 'decimal')?.value
    return !/\d/.test(month) && mark(code) !== mark('en')
  } catch (e) {
    return false
  }
}

const DATE_FIELDS = ['year', 'month', 'day', 'hour', 'minute', 'second', 'dateStyle', 'timeStyle']

/** The options of a date without the words (months, days): numbers only, for a language whose names the browser does not have. */
export function numericDate(options) {
  const { weekday, month, dateStyle, timeStyle, ...rest } = options || {}
  const out = { ...rest }
  if (month) out.month = month === '2-digit' ? '2-digit' : 'numeric'
  if (dateStyle) {
    out.day = '2-digit'
    out.month = '2-digit'
    out.year = 'numeric'
  }
  if (timeStyle) Object.assign(out, { hour: '2-digit', minute: '2-digit' })
  // a weekday on its own (the head of a calendar column) has no numeric form: it is kept, in the stand-in language. Options like timeZone do not make it a date
  if (weekday && !DATE_FIELDS.some((field) => field in out)) out.weekday = weekday
  return out
}

const formatters = new Map()
function formatter(Kind, options) {
  const id = `${Kind.name}|${locale}|${JSON.stringify(options || {})}`
  if (!formatters.has(id)) formatters.set(id, new Kind(degraded ? STAND_IN[locale] : locale, Kind === Intl.DateTimeFormat && degraded ? numericDate(options) : options))
  return formatters.get(id)
}

const toDate = (value) => (value instanceof Date ? value : new Date(value ?? NaN)) // new Date(null) would be 1970, and null is what the API gives for no date

export function formatDate(value, options) {
  const date = toDate(value)
  return Number.isNaN(date.getTime()) ? '' : formatter(Intl.DateTimeFormat, options).format(date)
}
export const formatNumber = (value, options) => formatter(Intl.NumberFormat, options).format(Number(value))

export function collator(options) {
  return formatter(Intl.Collator, options)
}
collator.compare = (a, b) => collator().compare(a, b)

/** Saves the choice and reloads the page; rejects with the error of the server when it refused. */
export async function setLocale(code) {
  const { http } = await import('@/api/http.js') // http.js itself uses t(): this file imports nothing of the app
  try {
    await http.put('me/locale', { locale: code })
  } catch (e) {
    if (!standalone) throw e
  }
  if (standalone) remember(code)
  window.location.reload()
}
