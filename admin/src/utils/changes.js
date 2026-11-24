// Unpublished-change detection for pages and collection records. Pure functions: usable on local editor state or on api data.
import { getSectionType, sectionFields, sectionName } from '@/schema/sections.js'
import { PAGE_FIELDS } from '@/schema/pages.js'
import { collectionFields } from '@/schema/collections.js'
import { getTemplate } from '@/schema/templates.js'
import { describeChange, fieldsOf, valuesEqual } from './fields.js'
import { getPath, same } from './objects.js'
import { matrixWords } from '@/schema/matrix.js'
import { t } from '@/i18n/index.js'

/**
 * Differences between a page's working copy and its published snapshot.
 * kinds: 'edit' (a field of a section), 'add' (new section), 'del' (removed section), 'vis' (hidden/shown), 'move' (order), 'meta' (title, address, SEO),
 * 'field' (a field of the page's TEMPLATE: page.fields, e.g. the text of an article).
 * @returns {{ kind:string, sec:string|null, key?:string, name:string, label:string, val:string, old:string }[]}
 */
export function pageChanges(page, published) {
  if (!published) return []
  const out = []
  const pubById = Object.fromEntries((published.sections || []).map((s) => [s.id, s]))
  const curIds = (page.sections || []).map((s) => s.id)

  PAGE_FIELDS.forEach((f) => {
    const a = getPath(page, f.k)
    const b = getPath(published, f.k)
    if (!valuesEqual(f, a, b)) { const d = describeChange(f, a, b); out.push({ kind: 'meta', sec: null, key: f.k, name: t('core.change_page'), label: f.label, val: d.val, old: d.old }) }
  })

  const tpl = getTemplate(page.template)
  if (tpl) {
    fieldsOf(tpl.fields).forEach((f) => {
      const a = page.fields ? page.fields[f.k] : undefined
      const b = published.fields ? published.fields[f.k] : undefined
      if (!valuesEqual(f, a, b)) { const d = describeChange(f, a, b); out.push({ kind: 'field', sec: null, key: f.k, name: tpl.name, label: d.where ? `${f.label} · ${d.where}` : f.label, val: d.val, old: d.old }) }
    })
  }

  ;(page.sections || []).forEach((s) => {
    const name = sectionName(s.type)
    const pub = pubById[s.id]
    if (!pub) { out.push({ kind: 'add', sec: s.id, name, label: t('core.change_section_new'), val: '', old: '' }); return }
    sectionFields(s.type).forEach((f) => {
      if (!valuesEqual(f, s.data?.[f.k], pub.data?.[f.k])) {
        const d = describeChange(f, s.data?.[f.k], pub.data?.[f.k])
        out.push({ kind: 'edit', sec: s.id, key: f.k, name, label: d.where ? `${f.label} · ${d.where}` : f.label, val: d.val, old: d.old })
      }
    })
    if (!!pub.hidden !== !!s.hidden) out.push({ kind: 'vis', sec: s.id, name, label: s.hidden ? t('core.change_section_hidden') : t('core.change_section_shown'), val: '', old: '' })
  })
  ;(published.sections || []).filter((s) => !curIds.includes(s.id)).forEach((s) => out.push({ kind: 'del', sec: null, name: sectionName(s.type), label: t('core.change_section_deleted'), val: '', old: '' }))

  const a = (published.sections || []).map((s) => s.id).filter((id) => curIds.includes(id))
  const b = curIds.filter((id) => pubById[id])
  if (!same(a, b)) out.push({ kind: 'move', sec: null, name: t('core.change_sections_order'), label: t('core.change_sections_moved'), val: '', old: '' })
  return out
}

/** Changed top-level keys of one section (for the form's magenta marks). */
export function sectionChangedKeys(section, publishedSection) {
  if (!publishedSection) return []
  return sectionFields(section.type).filter((f) => !valuesEqual(f, section.data?.[f.k], publishedSection.data?.[f.k])).map((f) => f.k)
}

/** «2 columns» for the list of changes: the unit of the collection (matrix.words) goes after the number, without one the catalogue gives the form by the number. */
const colsCount = (w, n) => (w.colsUnit ? `${n} ${w.colsUnit}` : t('forms.matrix_cols_count', { count: n }))
const rowsCount = (w, n) => (w.rowsUnit ? `${n} ${w.rowsUnit}` : t('forms.matrix_rows_count', { count: n }))

/** Fields of a collection record that differ from its published copy: [{ field, label, val, old }]. Matrix records compare cols and rows. */
export function recordChanges(cfg, record, published) {
  if (!published) return []
  if (cfg.view === 'matrix') {
    const w = matrixWords(cfg)
    const out = []
    if (!same(record.cols, published.cols)) out.push({ field: { k: 'cols', label: w.colsTitle }, val: colsCount(w, record.cols.length), old: colsCount(w, published.cols.length) })
    if (!same(stripIds(record.rows), stripIds(published.rows))) out.push({ field: { k: 'rows', label: w.rowsTitle }, val: rowsCount(w, record.rows.length), old: rowsCount(w, published.rows.length) })
    return out
  }
  return collectionFields(cfg)
    .filter((f) => !valuesEqual(f, record[f.k], published[f.k]))
    .map((f) => { const d = describeChange(f, record[f.k], published[f.k]); return { field: f, ...d, label: d.where ? `${f.label} · ${d.where}` : f.label } })
}
const stripIds = (v) => JSON.parse(JSON.stringify(v ?? null, (k, x) => (k === '_id' ? undefined : x)))

export { getSectionType }
