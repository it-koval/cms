// Content languages of the current site: names, URL prefixes, small helpers. Pure code: no api, no store imports (api/* and stores/* both import it).
//
// MODEL (details in README «Языки»): a site has one MAIN language (no URL prefix) and any number of others, each with a prefix: /en/about-us.
// Every page and collection record has its own version per language. The language of the interface (i18n/index.js) is a separate concept from the language of the content.
//
// Two groups of helpers:
//  1. PURE ones that take the main language explicitly (prefixUrl, stripPrefix, localeOfUrl, addLang) — used by api/*.
//  2. REACTIVE ones bound to `langState` (langName, isMain, prefix, unprefix, isMulti, tag, withLang) — used by screens.
//     langState mirrors api.site.getLanguages(); stores/app.js fills it (setLanguages) on start, on site switch and after Settings → Языки.
import { reactive } from 'vue'

/** Languages the «Добавить язык» list offers (Russian is the usual main language and is listed so seeds / the list can name it). */
export const LANGUAGE_CATALOG = [
  { code: 'en', name: 'English' },
  { code: 'de', name: 'Deutsch' },
  { code: 'es', name: 'Español' },
  { code: 'fr', name: 'Français' },
  { code: 'it', name: 'Italiano' },
  { code: 'kk', name: 'Қазақша' }, // i18n-ignore: a language is named in itself
  { code: 'uk', name: 'Українська' }, // i18n-ignore: a language is named in itself
  { code: 'zh', name: '中文' },
]
const KNOWN = { ru: 'Русский', // i18n-ignore: a language is named in itself
  ...Object.fromEntries(LANGUAGE_CATALOG.map((l) => [l.code, l.name])) }
/** Name of a language as the client sees it («Русский», «English»); unknown codes fall back to the upper-case code. */
export const knownName = (code) => KNOWN[code] || String(code || '').toUpperCase()
/** Language codes: two or three lower-case letters. */
export const isLangCode = (code) => typeof code === 'string' && /^[a-z]{2,3}$/.test(code)

/* ---------- pure helpers (explicit main language) ---------- */

/** '/about-us' + 'en' (main 'ru') -> '/en/about-us'; '/' -> '/en'; the main language and already prefixed addresses stay as they are. */
export function prefixUrl(url, locale, main) {
  const u = String(url || '').trim()
  if (!locale || locale === main) return u
  const p = '/' + locale
  if (u === p || u.startsWith(p + '/') || u.startsWith(p + '?') || u.startsWith(p + '#')) return u
  if (u === '' || u === '/') return p
  return p + (u.startsWith('/') ? u : '/' + u)
}

/** '/en/about-us' -> '/about-us'; '/en' -> '/'; the main language and addresses without the prefix stay as they are. */
export function stripPrefix(url, locale, main) {
  const u = String(url || '').trim()
  if (!locale || locale === main) return u
  const p = '/' + locale
  if (u === p) return '/'
  return u.startsWith(p + '/') ? u.slice(p.length) : u
}

/** Which language an address belongs to: '/en/x' -> 'en', '/x' -> main. `languages` = { main, list:[{code}] }. */
export function localeOfUrl(url, languages) {
  const u = String(url || '').trim()
  const hit = languages.list.find((l) => l.code !== languages.main && (u === '/' + l.code || u.startsWith('/' + l.code + '/')))
  return hit ? hit.code : languages.main
}

/** Admin route + `?lang=xx` (the router guard switches the content language and removes the query). Only on sites with 2+ languages. */
export function addLang(path, locale, languages) {
  if (!languages || languages.list.length < 2 || !locale) return path
  return path + (path.includes('?') ? '&' : '?') + 'lang=' + locale
}

/* ---------- reactive helpers (the current site) ---------- */

/** { main:'ru', list:[{ code, name }] } of the current site. Read it in templates; never write it (use setLanguages). */
export const langState = reactive({ main: 'ru', list: [{ code: 'ru', name: 'Русский' }], current: 'ru' }) // i18n-ignore: the default of the site data

/** The content language on screen (stores/app.js keeps it in step with app.locale): what defaults that depend on the language read (homeUrl). */
export function setCurrentLocale(code) {
  langState.current = code
}

export function setLanguages(languages) {
  langState.main = languages.main
  langState.list = languages.list.map((l) => ({ code: l.code, name: l.name }))
}

/** More than one language: the site is multilingual, language chips / switchers / tags are shown. A one-language site is not. */
export const isMulti = () => langState.list.length > 1
export const isMain = (code) => code === langState.main
export const codes = () => langState.list.map((l) => l.code)
export const hasLang = (code) => langState.list.some((l) => l.code === code)
/** Codes whose address space (/en, /en/…) a version of language `locale` may not use: for the main language every other language, for the others none (their prefix is added for them). */
export const reservedCodes = (locale) => (isMain(locale) ? langState.list.map((l) => l.code).filter((c) => c !== langState.main) : [])
/** «Русский» / «English» (the name the client gave it in Settings → Языки). */
export const langName = (code) => (langState.list.find((l) => l.code === code) || {}).name || knownName(code)
/** Short tag for chips and tabs: 'RU', 'EN'. */
export const tag = (code) => String(code || '').toUpperCase()
/** '' for the main language, '/en' for the others: the fixed part in front of the address input. */
export const pathPrefix = (code) => (isMain(code) ? '' : '/' + code)
export const prefix = (url, locale) => prefixUrl(url, locale, langState.main)
export const unprefix = (url, locale) => stripPrefix(url, locale, langState.main)
export const withLang = (path, locale) => addLang(path, locale, langState)
/** The home page of the content language: '/' in the main language, '/en' in English. A NEW link points here, so English content never links to the Russian home. */
export const homeUrl = () => prefixUrl('/', langState.current, langState.main)
