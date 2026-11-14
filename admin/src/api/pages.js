// Pages API: a thin fetch client over /admin/api. A PAGE is one node of the site tree with a version per content language
// (see ARCHITECTURE.md §2). What the api hands out is a FLAT page of one language:
//   { id, parentId, template, locale, translated:true, title, url, status, updatedAt, version, migrated, seo, sections, fields, awaiting, returned }
// `locale` goes in the query string (?locale=ru); omitted = the main language. Errors are ApiError with the server's message (404 / 409 / 422).
//
//   list({ locale, template, parentId })  GET    pages?locale=&template=&parentId=      all pages (without sections), see ROW below
//   get(id, locale)                       GET    pages/:id/draft                        working copy of one language (404 when there is no such version)
//   getPublished(id, locale)              GET    pages/:id/draft                        snapshot on the live site or null
//   getDraft(id, locale)                  GET    pages/:id/draft?locale                 { page, published, changes, locale, translated, locales, shell } — the page editor loads this
//   saveDraft(id, locale, data, baseVersion)  PUT pages/:id/draft?locale                 autosave: title, url, seo, sections, fields, parentId, template -> flat page + changed.
//                                                                                        baseVersion = the `version` of the page as the editor last had it: a draft that changed meanwhile gives 409 (isConflict) and nothing is written; without it the save is not checked
//   create(data)                          POST   pages                                  { title, url, parentId, template, sections?, fields?, id? } — born in the MAIN language
//   update(id, locale, patch)             PATCH  pages/:id?locale                       list-level edits: title, url, seo, fields + parentId, template
//   remove(id)                            DELETE pages/:id
//   publish accepts an optional last argument baseVersion: a changed draft returns 409 before publication.
//   publish / revert / submit / returnToAuthor / unpublish   POST pages/:id/{publish|revert|submit|return|unpublish}?locale
//   tree(nodes)                           PUT    pages/tree [{ id, parentId, position }]   drag and drop in the site tree: { ok, moved }; pages not named stay (the server checks the template rules)
//   undoUnpublish(id, locale)             POST   pages/:id/republish?locale
//   createTranslation(id, locale, {from}) POST   pages/:id/translations?locale          (iteration 2: the server has no other languages yet)
//   removeTranslation(id, locale)         DELETE pages/:id/translations/:locale          (iteration 2)
// ROW (list): { id, parentId, template, locale, translated, title, url, seo, fields, status, updatedAt, migrated, sectionsCount, changed, changes, published, awaiting,
//               locales: { ru: { status, changed, changes, published, title, url, updatedAt, migrated, sectionsCount, awaiting } | null } }
import { ApiError, http } from './http.js'
import { t } from '@/i18n/index.js'

const loc = (locale) => ({ locale: locale || undefined })
const enc = encodeURIComponent
const NO_VERSION = t('core.api_no_version')

/** The part of a page the server takes as a draft; its own marks (status, updatedAt, awaiting...) are not sent back. */
const DRAFT_KEYS = ['title', 'url', 'seo', 'sections', 'fields', 'parentId', 'template']
const pick = (data, keys) => Object.fromEntries(keys.filter((k) => data[k] !== undefined).map((k) => [k, data[k]]))

export const list = ({ locale, template, parentId } = {}) => http.get('pages', { ...loc(locale), template, parentId })

export const getDraft = (id, locale) => http.get(`pages/${enc(id)}/draft`, loc(locale))

export async function get(id, locale) {
  const d = await getDraft(id, locale)
  if (!d.page) throw new ApiError(NO_VERSION, 404, { code: 'no_translation', locale: d.locale })
  return d.page
}
export async function getPublished(id, locale) {
  return (await getDraft(id, locale)).published || null
}

export const saveDraft = (id, locale, data = {}, baseVersion = null) => http.put(`pages/${enc(id)}/draft`, { ...pick(data, DRAFT_KEYS), ...(baseVersion ? { baseVersion } : {}) }, loc(locale))

export const create = (data = {}) => http.post('pages', data)
export const update = (id, locale, patch = {}) => http.patch(`pages/${enc(id)}`, patch, loc(locale))
export const remove = (id) => http.del(`pages/${enc(id)}`)
export const tree = (nodes) => http.put('pages/tree', nodes)

export const publish = (id, locale, baseVersion) => http.post(`pages/${enc(id)}/publish`, { baseVersion }, loc(locale))
export const revert = (id, locale) => http.post(`pages/${enc(id)}/revert`, {}, loc(locale))
export const submit = (id, locale) => http.post(`pages/${enc(id)}/submit`, {}, loc(locale))
export const returnToAuthor = (id, locale) => http.post(`pages/${enc(id)}/return`, {}, loc(locale))
export const unpublish = (id, locale) => http.post(`pages/${enc(id)}/unpublish`, {}, loc(locale))
export const undoUnpublish = (id, locale) => http.post(`pages/${enc(id)}/republish`, {}, loc(locale))

export const createTranslation = (id, locale, { from = null } = {}) => http.post(`pages/${enc(id)}/translations`, { from }, loc(locale))
export const removeTranslation = (id, locale) => http.del(`pages/${enc(id)}/translations/${enc(locale)}`)
