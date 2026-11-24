// Collections API (lists of records WITHOUT pages of their own): a thin fetch client over /admin/api.
// A RECORD has a version per content language, like a page. What the api hands out is a FLAT record of one language:
//   { id, locale, translated:true, status:'published'|'draft', updatedAt, version, awaiting, returned, ...fields, changed, changes }
// `locale` goes in the query string; omitted = the main language.
//
//   listCollections(locale)             GET    collections?locale                     [{ key, title, count, unpublished, locales:{ ru:{count,unpublished} }, ... }] for the rail
//   list(col, { q, status, locale, onlyTranslated })   GET collections/:col          ALL records as rows (flat record + { locales:{ ru:{ status, changed, changes, title, updatedAt } | null } }).
//                                                                                    status: '' | 'draft' (draft or changed) | 'published' | 'missing'
//   query(col, { q, status, filter, sort, page, perPage, locale })   GET collections/:col  → { items, total, page, perPage, facets, metrics }: the list made by the database.
//                                                                                    status: domain status including changed drafts and missing translations; filter: { date:{ from, to }, tags:['a','b'] }; sort: 'dateStart' | '-dateStart' | 'a,-b'
//   get(col, id, locale)                GET    collections/:col/:id?locale            { item, published, changes, locale, translated, locales }
//   create(col, data)                   POST   collections/:col                       starts as a draft, in the MAIN language
//   update(col, id, data, locale, baseVersion)   PUT collections/:col/:id?locale     merges `data` into that version. baseVersion = the `version` of the record as the form last had it: a draft that changed meanwhile
//                                                                                    gives 409 (isConflict) and nothing is written; without it the save is not checked
//   remove(col, id)                     DELETE collections/:col/:id
//   publish accepts an optional last argument baseVersion: a changed draft returns 409 before publication.
//   publish / revert / submit / returnToAuthor   POST collections/:col/:id/{publish|revert|submit|return}?locale
//   reorder(col, ids)                   PUT    collections/:col/order { ids }          language independent (collections with `sortable`)
//   tree(col, nodes)                    PUT    collections/:col/tree [{ id, parentId, position }]   collections with tree(): { ok, moved }; records not named stay
//   createTranslation / removeTranslation        POST|DELETE collections/:col/:id/translations[/:locale]
import { http } from './http.js'
import { t } from '@/i18n/index.js'

const loc = (locale) => ({ locale: locale || undefined })
const enc = encodeURIComponent
const at = (col, id) => `collections/${enc(col)}/${enc(id)}`

export const listCollections = (locale) => http.get('collections', loc(locale))
export async function list(col, { q = '', status = '', locale, onlyTranslated = false } = {}) {
  const rows = []
  for (let page = 1; ; page++) {
    const result = await http.get(`collections/${enc(col)}`, { q, status, ...loc(locale), onlyTranslated, page, perPage: 200 })
    rows.push(...result.items)
    if (rows.length >= result.total) return rows
    if (!result.items.length) throw new Error(t('collections.cl_load_failed'))
  }
}
export const query = (col, { q = '', status = '', filter = {}, sort = '', page = 1, perPage, locale } = {}) => http.get(`collections/${enc(col)}`, { q, status, filter, sort, page, perPage, envelope: true, ...loc(locale) })
export const get = (col, id, locale) => http.get(at(col, id), loc(locale))
export const create = (col, data = {}) => http.post(`collections/${enc(col)}`, data)
export const update = (col, id, data, locale, baseVersion = null) => http.put(at(col, id), baseVersion ? { ...data, baseVersion } : data, loc(locale))
export const remove = (col, id) => http.del(at(col, id))
export const publish = (col, id, locale, baseVersion) => http.post(`${at(col, id)}/publish`, { baseVersion }, loc(locale))
export const submit = (col, id, locale) => http.post(`${at(col, id)}/submit`, {}, loc(locale))
export const returnToAuthor = (col, id, locale) => http.post(`${at(col, id)}/return`, {}, loc(locale))
export const revert = (col, id, locale) => http.post(`${at(col, id)}/revert`, {}, loc(locale))
export const reorder = (col, ids) => http.put(`collections/${enc(col)}/order`, { ids })
export const tree = (col, nodes) => http.put(`collections/${enc(col)}/tree`, nodes)
export const createTranslation = (col, id, locale, { from = null } = {}) => http.post(`${at(col, id)}/translations`, { from }, loc(locale))
export const removeTranslation = (col, id, locale) => http.del(`${at(col, id)}/translations/${enc(locale)}`)
