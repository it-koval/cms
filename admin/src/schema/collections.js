// Collections: lists of records edited as forms. Lists WITHOUT pages of their own (what has a page of its own is a page with a template, schema/templates.js).
// They come from the site schema (resource classes); nothing here is code.
//
// config: { key, title, singular, newLabel, icon, description, view:'table'|'cards'|'matrix', titleField, thumbField?, subField?,
//           toggleField?, sortable?, singleton?, matrix?, tabs:[{ key, label, fields }], empty:{ title, text } }
// `sortable: true` = the order of the list is the order on the site and the staff can change it («Изменить порядок» in the list, api.collections.reorder).
// `matrix` (view 'matrix'): the options table — { colFields, rowKinds, rowLabel, words? } — one record {cols:[...], rows:[...]}; the UI is a table, not a form.
//    `matrix.words` renames what the table speaks of (schema/matrix.js: «тариф» / «опция» instead of «столбец» / «строка»).
// Field types are described in utils/fields.js.
import { byKey, schema } from './registry.js'

/** Collections of the site, in the order of the rail's sub-menu. */
export const getCollectionConfigs = () => schema.collections
export const getCollectionConfig = (key) => byKey(schema.collections, key)
/** All content fields of a collection (every tab), flat. */
export const collectionFields = (cfg) => cfg.tabs.flatMap((t) => t.fields)
