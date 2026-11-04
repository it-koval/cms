// «Шаблон» and «Родительская страница» of a page, in ONE place. «Новая страница», «Настройки страницы» in the list and the settings modal of the page editor
// all ask these functions what may be picked, so the three always agree (the server enforces the same guards, schema/templates.js). Pure functions.
import { t } from '@/i18n/index.js'
import { canChangeTemplate, canPlace, getTemplate, parentFits, placementText, templateChoicesFor, templateName, templatesFor, topLevelAllowed } from '@/schema/templates.js'
import { flatOptions, subtreeIds } from './tree.js'

/** Is this page the home page (its template is the root one)? */
export const isRootPage = (page) => !!(page && (getTemplate(page.template) || {}).root)

/** May a page with this template stand at the top level? */
export const topLevelOk = (templateKey) => { const t = getTemplate(templateKey); return !t || topLevelAllowed(t) }

/** «Где может стоять» as one sentence (help text under «Родительская страница»). */
export const placementOf = (templateKey) => { const t = getTemplate(templateKey); return t ? placementText(t) : '' }

/* ---------- parent page ---------- */

/** Pages a NEW page with this template may stand under, in tree order: [{ p, lvl }]. Never the home page; only pages the template fits under. */
export function parentsFor(pages, templateKey) {
  const t = getTemplate(templateKey)
  return flatOptions(pages.filter((p) => !isRootPage(p) && (!t || parentFits(t, p.template))))
}

/** Pages an EXISTING page may move under (template `templateKey`, default: its own): like parentsFor, but not the page itself or its own branch. */
export function parentChoices(pages, page, templateKey = page && page.template) {
  if (!page) return []
  const t = getTemplate(templateKey)
  return flatOptions(pages.filter((p) => !isRootPage(p) && (!t || parentFits(t, p.template))), subtreeIds(pages, page.id))
}

/**
 * The parent a form should show after the template changed: `wantedId` (what the person had chosen) if it still fits, else the top level when the template may stand there,
 * else the first page it fits under ('' = top level). `options` = the list from parentsFor / parentChoices.
 */
export function pickParent(options, templateKey, wantedId = '') {
  if (wantedId && options.some((o) => o.p.id === wantedId)) return wantedId
  if (topLevelOk(templateKey)) return ''
  return options.length ? options[0].p.id : ''
}

/* ---------- template of an existing page ---------- */

/**
 * Templates the page may get, each with the reason when it may not: [{ template, ok, reason, lost }] (the page's own first).
 * `parentId` = the parent page chosen in the form (default: the saved one), so a template is judged by the place the page is going to.
 */
export function templatePicks(page, pages, parentId = page.parentId || null) {
  const all = templateChoicesFor({ ...page, parentId: parentId || null }, pages)
  return [...all.filter((c) => c.template.key === page.template), ...all.filter((c) => c.template.key !== page.template)]
}

/** The sentence for the confirm: which fields of the page disappear with the template change, or '' when none. */
export function lostFieldsText(page, templateKey, pages) {
  const { lost } = canChangeTemplate(page, templateKey, pages)
  return lost.length ? lost.map((l) => t('pages.quoted', { text: l })).join(', ') : ''
}

/** The help texts under «Шаблон» and «Родительская страница» of both settings dialogs (same words in the list and in the editor). `count` = templates in the list. */
export function templateHelpText(count, multi) {
  return t(`pages.template_help_${count < 2 ? 'alone' : 'list'}${multi ? '_multi' : ''}`)
}
export function parentHelpText(templateKey, multi) {
  const where = placementOf(templateKey)
  return multi ? t('pages.parent_help_multi', { where }) : where
}

/* ---------- template of a new page ---------- */

const orList = (keys) => keys.map((k) => t('pages.quoted', { text: templateName(k) })).join(t('pages.or_separator'))

/** Can a new page with this template stand SOMEWHERE on the site (the parent is not chosen yet)? { ok, reason } */
function placeAnywhere(tpl, pages) {
  const top = canPlace(tpl.key, null, pages)
  if (top.ok) return top
  if (tpl.single && pages.some((p) => p.template === tpl.key)) return tpl.root ? top : { ok: false, reason: t('pages.single_exists') } // about the template, not about the place
  if (pages.some((p) => !isRootPage(p) && canPlace(tpl.key, p, pages).ok)) return { ok: true, reason: '' }
  return { ok: false, reason: Array.isArray(tpl.parents) ? t('pages.parent_first', { parents: orList(tpl.parents), name: tpl.name }) : top.reason }
}

/**
 * Cards for «Новая страница», template first: [{ template, ok, reason }] in the order of the site's templates. A card is disabled when `ok` is false and shows the reason.
 * `under` = undefined: the parent is not known yet, a template is possible when it can stand somewhere; a page or null (the top level): judged for exactly that place.
 */
export function newPageChoices(pages, under) {
  return templatesFor().map((tpl) => ({ template: tpl, ...(under === undefined ? placeAnywhere(tpl, pages) : canPlace(tpl.key, under, pages)) }))
}

/**
 * The card to preselect: `wanted` if it is possible; under a parent page (`under`) the template that is made for it («Товар» under «Каталог», «Статья блога» under «Блог»); else the plain page; else the first possible one.
 */
export function firstTemplate(cards, wanted = '', under = null) {
  const ok = (key) => cards.find((c) => c.ok && c.template.key === key)
  const made = under ? cards.filter((c) => c.ok && Array.isArray(c.template.parents) && c.template.parents.includes(under.template) && !c.template.single) : []
  return (wanted && ok(wanted)) || made.find((c) => c.template.leaf) || made[0] || ok('default') || cards.find((c) => c.ok) || cards[0]
}

/* ---------- the flat lists of the rail («Товары», «Статьи блога») ---------- */

const FLAT_WORDS = { blog_article: 'article' } // the wording of a template: pages.flat_<kind>_*
/**
 * The words of a flat list of one template: the button («Добавить статью»), the placeholders of «Новая страница», the empty title and `sub(count, name)`, the sentence
 * under the title («3 статьи с шаблоном «Статья блога»…»). A template without its own wording speaks of pages.
 */
export function flatWords(templateKey) {
  const kind = FLAT_WORDS[templateKey] || 'page'
  const k = (name) => `pages.flat_${kind}_${name}`
  return { add: t(k('add')), title: t(k('title')), ph: t(k('ph')), phUrl: t(k('ph_url')), none: t(k('none')), sub: (count, name) => t(k('sub'), { count, template: name }) }
}
/** Does this template get a flat list of its own (thumb, parent page, date / the list switch) instead of the ordinary filtered tree? Those with a card in lists. */
export const hasFlatList = (templateKey) => { const t = getTemplate(templateKey); return !!(t && t.card) }
/** Is a page with this template a leaf (product, article): nothing can be put under it? */
export const isLeafPage = (page) => !!(page && (getTemplate(page.template) || {}).leaf)
