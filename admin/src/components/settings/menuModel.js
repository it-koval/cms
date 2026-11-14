// Menu items: { _id, label, link:{ kind:'page'|'url', page, url }, children?:[same, one level] }
// The menus of a site are its menu AREAS (api.settings.getMenuAreas, schema/menuAreas.js); `menuGroups` turns them into the sections of the editor.
import { nid } from '@/utils/ids.js'
import { t } from '@/i18n/index.js'
import { siteText } from '@/utils/siteTexts.js'

/** The line under the name of an area: what kind of menu it is. */
export const areaKind = (a) => (a.depth === 2 ? t('settings.area_kind_nested') : t('settings.area_kind_flat'))
/** An area that became flat: nested items turn into ordinary ones right after their parent (what the api does in every language). */
export const flattenItems = (list) => (list || []).flatMap((it) => { const { children = [], ...top } = it; return [top, ...children] })
export const newMenuItem = (page = '/') => ({ _id: nid('mi'), label: siteText('item'), link: { kind: 'page', page, url: '' } }) // the name is data of the site: in the language of the content

const SAFE_URL = /^(https?:\/\/|mailto:|tel:|\/|#)\S*$/i
/** Message about one item, or ''. */
export function itemError(it) {
  if (!String(it.label || '').trim()) return t('settings.err_item_name')
  const l = it.link || {}
  if (l.kind === 'url' && !SAFE_URL.test(String(l.url || '').trim())) return t('settings.err_item_url')
  if (l.kind === 'page' && !l.page) return t('settings.err_item_page')
  return ''
}
/** Number of items with an error across all menus (children included). */
export function menuErrorCount(menus) {
  let n = 0
  Object.values(menus || {}).forEach((list) => (list || []).forEach((it) => {
    if (itemError(it)) n++
    ;(it.children || []).forEach((c) => { if (itemError(c)) n++ })
  }))
  return n
}

/**
 * Warning (not an error: it never blocks saving) about a menu item that leads to a page the site does not show in this menu's language.
 * `pages` = [{ value: address, label: title, draft: boolean }] — the pages that have a version in the menu's language.
 * `on` = «на английском» on a site with 2+ languages, '' on a one-language site. Returns '' when the item is fine.
 */
export function pageFlag(item, pages, on = '') {
  const l = item.link || {}
  if (l.kind !== 'page' || !l.page) return ''
  const p = pages.find((x) => x.value === l.page)
  if (!p) return on ? t('settings.flag_missing_lang', { lang: on }) : t('settings.flag_missing')
  if (p.draft) return on ? t('settings.flag_draft_lang', { label: p.label, lang: on }) : t('settings.flag_draft', { label: p.label })
  return ''
}
/** Number of items of one menu with such a warning (children included). */
export function flagCount(list, pages, on = '') {
  let n = 0
  ;(list || []).forEach((it) => {
    if (pageFlag(it, pages, on)) n++
    ;(it.children || []).forEach((c) => { if (pageFlag(c, pages, on)) n++ })
  })
  return n
}
