// The schema of the site, as the server hands it out (GET /admin/api/schema, built from the site's config/itk/schema.php).
// Everything site-specific the admin knows is here: section types, page templates, collections, menu areas, settings groups, forms, permission areas, the design.
// The other files of schema/ hold only the generic logic over it (placement guards, the H1 rule, the block library...).
//
// shape: { mainLocale, design:{ headlineMarks }, features:{ ai }, sections:[...], sectionGroups:[...], pageFields:[...], templates:[...], layout:{ top, bottom },
//          collections:[...], menuAreas:[...], settings:{ groups:[{ key, title, description, fields }] }, forms:[{ key, title, fields }], permissionAreas:[...],
//          nav:[{ key, group, icon, order, items:[{ key, label, to, icon, order, badge?, sub? }] }] }   nav = the left rail, built by the server for this person (layouts/AppLayout.vue)
// `schema` is ONE object that is filled in place by loadSchema(), so every importer sees the loaded data (the admin loads it before it mounts).
import { http } from '@/api/http.js'

const EMPTY = () => ({
  mainLocale: 'ru', design: { headlineMarks: [] }, features: { ai: false }, sections: [], sectionGroups: [], pageFields: [], templates: [], layout: { top: [], bottom: [] },
  collections: [], menuAreas: [], settings: { groups: [] }, forms: [], permissionAreas: [], nav: [],
})

export const schema = EMPTY()
const listeners = []
/** Run `fn` every time a schema is loaded (and now, when one is already there): modules that keep derived data register here. */
export function onSchema(fn) {
  listeners.push(fn)
  fn(schema)
}

/** Put a schema from the server into `schema`. Missing parts stay empty. */
export function loadSchema(raw) {
  const next = { ...EMPTY(), ...(raw || {}) }
  next.design = { headlineMarks: [], ...(next.design || {}) }
  next.features = { ai: false, ...(next.features || {}) }
  next.layout = { top: [], bottom: [], ...(next.layout || {}) }
  next.settings = { groups: [], ...(next.settings || {}) }
  Object.assign(schema, next)
  listeners.forEach((fn) => fn(schema))
  return schema
}

export async function fetchSchema() {
  return loadSchema(await http.get('schema'))
}

const maps = new WeakMap()
/** The element of a schema list with this `key`, or null (the lookup table is built once per loaded list). */
export function byKey(list, key) {
  let m = maps.get(list)
  if (!m) {
    m = new Map(list.map((x) => [x.key, x]))
    maps.set(list, m)
  }
  return m.get(key) || null
}
