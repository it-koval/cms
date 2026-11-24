// Page address helpers for «Новая страница» and «Настройки страницы»: transliteration, normalising, validation, uniqueness.
import { t } from '@/i18n/index.js'
import { verGen } from '@/utils/langWords.js'
import MAP from '../../../../packages/cms/src/Support/slug-map.json'

/** «Новые поступления 2024» -> «novye-postupleniya-2024» */
export function slugify(text, max = 48) {
  const latin = [...String(text || '').toLowerCase()].map((ch) => (ch in MAP ? MAP[ch] : ch)).join('')
  const s = latin.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
  return s.slice(0, max).replace(/-+$/g, '')
}

/** Comparable form of an address: lower case, no trailing slash (except the root). */
export const urlKey = (u) => {
  const s = String(u || '').trim().toLowerCase()
  return s.length > 1 ? s.replace(/\/+$/, '') : s
}

/** The page that already uses this address, or null. `exceptId` = the page being edited. */
export const pageWithUrl = (pages, url, exceptId = null) => pages.find((p) => p.id !== exceptId && urlKey(p.url) === urlKey(url)) || null

/** «/o-kompanii» for a title, made unique among `pages` with a -2, -3 ... suffix. '' for an empty title. */
export function autoUrl(title, pages, exceptId = null) {
  const s = slugify(title)
  if (!s) return ''
  let url = '/' + s
  for (let i = 2; pageWithUrl(pages, url, exceptId); i++) url = `/${s}-${i}`
  return url
}

/** Tidy what the person typed when they leave the field: leading slash, no spaces, lower case. */
export function normalizeUrl(u) {
  let s = String(u || '').trim().toLowerCase().replace(/\s+/g, '-').replace(/\/{2,}/g, '/')
  if (!s) return ''
  if (!s.startsWith('/')) s = '/' + s
  return s
}

/** The error text in the language of the screen or ''. `keep` = the address the page already has: it is not re-checked for characters (pages moved from the old site may have any).
 *  `reserved` = codes of the other languages of the site (reservedCodes(locale) in utils/locales.js): /en and /en/… belong to the English version, not to a main-language page. */
export function urlError(url, pages, exceptId = null, keep = '', reserved = []) {
  const u = String(url || '').trim()
  if (!u) return t('pages.url_required')
  if (!u.startsWith('/')) return t('pages.url_slash')
  if (/\s/.test(u)) return t('pages.url_spaces')
  if (/\/\//.test(u)) return t('pages.url_double_slash')
  if (u !== keep && !/^[A-Za-z0-9\-_./]+$/.test(u)) return t('pages.url_chars')
  const key = urlKey(u)
  const hit = reserved.find((c) => key === '/' + c || key.startsWith('/' + c + '/'))
  if (hit) return t('pages.url_reserved', { code: hit, version: verGen(hit) })
  const same = pageWithUrl(pages, u, exceptId)
  if (same) return t('pages.url_taken', { title: same.title })
  return ''
}
