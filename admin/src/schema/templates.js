// Page TEMPLATES: what a page of a certain kind is. Every page has a template (the client sees it as «Шаблон»); it decides
//   - which fields of its own the page has besides sections (an article: title, date, cover, excerpt, text),
//   - where it may stand (`parents`) and whether the site may have only one such page (`single`),
//   - whether sections can be added (`sections`), which list the page fills by itself (`listing`), where the page's H1 comes from (`h1`).
// The templates come from the site schema (schema/registry.js): CORE templates are the same on every site (`core`), the site adds its own. Templates are added by the
// developers of the site, not in the admin (Настройки → «Шаблоны» is read-only). Field definitions are the ones of utils/fields.js.
//
// Template = {
//   key, name, about (one sentence for the client), core (true = common to all sites; set here),
//   single?  — only one page with this template on the site,
//   root?    — the home page: address «/», top level, never moves, never unpublished (only `home` has it),
//   parents  — null = top level only · 'any' = top level or under any page · [template keys] = only under a page with one of these templates,
//   fields   — fields of the template (live in the language version: page.fields),
//   sections — 'all' = sections can be added · false = the page has no sections,
//   listing? — { of: [template keys], label, onlyIf? } list of the pages below this page, filled by itself (`onlyIf` = a toggle field of the listed pages that must be on: the switch of the page in
//              its list, listingSwitch() below finds it)
//              or { collection, label } the list of a collection (the options table),
//   h1?      — { field } the H1 of the page is this template field (then no section carries the H1),
//   leaf?    — nothing can be put under a page with this template (product, article): it is not offered as a parent page,
//   shortcut? — label of the quick link in the rail («Товары»: the list of pages with this template),
//   card?    — { image, title, text, date } keys of the template fields a list card shows,
//   areas    — menu areas the template's layout shows: 'layout' (the site's layout, schema.layout) or { top: [keys], bottom: [keys] },
// }
// The server checks the same guards when a page is created, moved or gets another template; the UI uses them to disable impossible choices.
import { fieldsOf } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'
import { onSchema } from './registry.js'

/* ---------- the templates of the site (from the schema) ---------- */

let all = [] // the templates with their defaults filled in, in schema order
let index = new Map()
onSchema((sc) => {
  all = sc.templates.map((t) => ({ areas: 'layout', ...t, fields: t.fields || [] }))
  index = new Map(all.map((t) => [t.key, t]))
})

/** All templates in the order of the «Шаблон» pickers: the common (core) ones first, then the site's own. */
export const templatesFor = () => [...all.filter((t) => t.core), ...all.filter((t) => !t.core)]
export const getTemplate = (key) => index.get(key) || null
export const templateName = (key) => (index.has(key) ? index.get(key).name : t('forms.template_default'))
/** Editable fields of a template (readonly notes left out). */
export const templateFields = (key) => (index.has(key) ? fieldsOf(index.get(key).fields) : [])

/* ---------- where a template may stand ---------- */

/** The names of templates in the quotes of the language: «Article», “Article”. */
const quotedNames = (keys) => keys.map((k) => t('forms.quoted', { name: templateName(k) }))

/** «Где может стоять» for the client, one short phrase. */
export function placementText(tpl) {
  if (tpl.root) return t('forms.placement_root')
  if (tpl.parents === null) return t('forms.placement_top')
  if (tpl.parents === 'any') return t('forms.placement_any')
  return t('forms.placement_inside', { templates: quotedNames(tpl.parents).join(', ') })
}
/** Is a top-level page allowed for this template? */
export const topLevelAllowed = (t) => t.parents === null || t.parents === 'any'
/** May a page with template `t` stand directly under a page with template `parentKey`? (A top-level place is `topLevelAllowed`.) A `leaf` template (product, article) is nobody's parent. */
export const parentFits = (t, parentKey) => {
  const p = getTemplate(parentKey)
  if (p && p.leaf) return false
  return t.parents === 'any' || (Array.isArray(t.parents) && t.parents.includes(parentKey))
}

const orList = (keys) => quotedNames(keys).join(t('forms.list_or'))
/**
 * May a page with template `key` stand under `parent`? { ok, reason } — the reason is the plain sentence for a toast / a disabled card.
 *   parent    a page ({ id, template }) or null for the top level
 *   pages     all pages of the site (rows of api.pages.list / the pages table): needed for `single`
 *   exceptId  the page itself when it is moved / gets another template (it does not count as «already there»)
 */
export function canPlace(key, parent, pages = [], exceptId = null) {
  const tpl = getTemplate(key)
  if (!tpl) return { ok: false, reason: t('forms.place_no_template') }
  if (tpl.single && pages.some((p) => p.id !== exceptId && p.template === key)) {
    return { ok: false, reason: tpl.key === 'home' ? t('forms.place_home_exists') : t('forms.place_single', { name: tpl.name }) }
  }
  if (!parent) {
    if (topLevelAllowed(tpl)) return { ok: true, reason: '' }
    return { ok: false, reason: t('forms.place_only_inside', { name: tpl.name, parents: orList(tpl.parents) }) }
  }
  if (tpl.parents === null) return { ok: false, reason: tpl.root ? t('forms.place_home_top') : t('forms.place_top_only', { name: tpl.name }) }
  if (parentFits(tpl, parent.template)) return { ok: true, reason: '' }
  return { ok: false, reason: t('forms.place_only_inside', { name: tpl.name, parents: orList(tpl.parents) }) }
}

/**
 * Can `page` get template `key`? { ok, reason, lost } — `lost` = labels of the page's fields that the new template does not have (they disappear).
 * Checks the home page (its template never changes), `single`, the page's own place and the places of its children.
 */
export function canChangeTemplate(page, key, pages) {
  const cur = getTemplate(page.template)
  const next = getTemplate(key)
  const lost = cur && next ? fieldsOf(cur.fields).filter((f) => !fieldsOf(next.fields).some((g) => g.k === f.k)).map((f) => f.label) : []
  if (!next) return { ok: false, reason: t('forms.place_no_template'), lost }
  if (key === page.template) return { ok: true, reason: '', lost: [] }
  if (cur && cur.root) return { ok: false, reason: t('forms.change_home_locked'), lost }
  const parent = page.parentId ? pages.find((p) => p.id === page.parentId) || null : null
  const here = canPlace(key, parent, pages, page.id)
  if (!here.ok) return { ...here, lost }
  const kid = pages.find((c) => c.parentId === page.id && !parentFits(getTemplate(c.template) || { parents: 'any' }, key))
  if (kid) return { ok: false, reason: t('forms.change_child_blocks', { title: kid.title, template: templateName(kid.template), name: next.name }), lost }
  return { ok: true, reason: '', lost }
}

/**
 * Templates a page may get (its own first): [{ template, ok, reason, lost }].
 */
export function templateChoicesFor(page, pages) {
  return templatesFor().map((t) => ({ template: t, ...canChangeTemplate(page, t.key, pages) }))
}

/**
 * The switch that puts a page of template `key` into the list of its parent page: { key, label } of the toggle field, or null. The listing of the parent template names it (`listing.onlyIf`,
 * its label is the field's); a site whose listing names none but whose pages have a toggle field `catalog` (the name the admin knew before `onlyIf`) keeps working.
 * `templates` = the templates of the schema.
 */
export function listingSwitchIn(templates, key) {
  const own = templates.find((t) => t.key === key)
  if (!own) return null
  const fieldOf = (k) => (own.fields || []).find((f) => f.k === k)
  for (const tpl of templates) {
    const l = tpl.listing
    if (l && l.onlyIf && Array.isArray(l.of) && l.of.includes(key) && fieldOf(l.onlyIf)) return { key: l.onlyIf, label: fieldOf(l.onlyIf).label || t('forms.listing_show') }
  }
  const old = fieldOf('catalog')
  return old ? { key: 'catalog', label: old.label || t('forms.listing_show') } : null
}
export const listingSwitch = (key) => listingSwitchIn(all, key)
