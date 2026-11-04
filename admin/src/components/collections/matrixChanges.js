// Granular unpublished changes of the options matrix (the API only says «cols» / «rows» changed). The words are the collection's (schema/matrix.js).
// record: { cols:[{id,name,price,note}], rows:[{_id,label,kind:'text'|'bool',vals:[...]}] }; vals are aligned with cols.
import { fill, matrixWords } from '@/schema/matrix.js'
import { same } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'

const show = (v) => (v === true ? t('collections.yes') : v === false ? t('collections.no') : String(v ?? '').trim() || t('collections.empty'))

/** Published value of row `q` for column id `cid` (undefined when the column or the row is new). */
export function pubVal(published, q, cid) {
  if (!published || !q) return undefined
  const i = published.cols.findIndex((c) => c.id === cid)
  return i < 0 ? undefined : q.vals[i]
}

/** [{ label, val, old }] — what differs from the published table. Not published yet -> one «first publication» entry. `words` = matrixWords(cfg). */
export function matrixChanges(tbl, p, words = matrixWords(null), firstLabel = words.first) {
  if (!p) return [{ label: firstLabel, val: t('collections.first_publication'), old: '' }]
  const out = []
  const pc = Object.fromEntries(p.cols.map((c) => [c.id, c]))
  const pr = Object.fromEntries(p.rows.map((r) => [r._id, r]))

  tbl.cols.forEach((c) => {
    const q = pc[c.id]
    if (!q) { out.push({ label: words.colAdded, val: c.name || t('collections.unnamed'), old: '' }); return }
    if (c.name !== q.name) out.push({ label: words.colRenamed, val: c.name || t('collections.empty'), old: q.name })
    if (c.price !== q.price) out.push({ label: t('collections.mx_price_label', { label: words.priceLabel, name: c.name }), val: c.price || t('collections.mx_price_unset'), old: q.price || t('collections.mx_price_unset') })
    if ((c.note || '') !== (q.note || '')) out.push({ label: fill(words.noteChanged, { name: c.name }), val: c.note || t('collections.mx_none'), old: q.note || t('collections.mx_none') })
  })
  p.cols.filter((q) => !tbl.cols.some((c) => c.id === q.id)).forEach((q) => out.push({ label: words.colRemoved, val: q.name, old: '' }))

  tbl.rows.forEach((r) => {
    const q = pr[r._id]
    if (!q) { out.push({ label: words.rowAdded, val: r.label || t('collections.unnamed'), old: '' }); return }
    if (r.label !== q.label) out.push({ label: words.rowRenamed, val: r.label || t('collections.empty'), old: q.label })
    if (r.kind !== q.kind) { out.push({ label: fill(words.rowKind, { name: r.label }), val: r.kind === 'bool' ? t('collections.mx_kind_bool') : t('collections.mx_kind_text'), old: q.kind === 'bool' ? t('collections.mx_kind_bool') : t('collections.mx_kind_text') }); return }
    tbl.cols.forEach((c, i) => {
      const was = pubVal(p, q, c.id)
      if (was === undefined) return // a new column is already reported once
      if (r.vals[i] !== was) out.push({ label: t('collections.mx_cell_label', { row: r.label, col: c.name }), val: show(r.vals[i]), old: show(was) })
    })
  })
  p.rows.filter((q) => !tbl.rows.some((r) => r._id === q._id)).forEach((q) => out.push({ label: words.rowGone, val: q.label, old: '' }))

  const a = p.rows.filter((q) => tbl.rows.some((r) => r._id === q._id)).map((q) => q._id)
  const b = tbl.rows.filter((r) => pr[r._id]).map((r) => r._id)
  if (!same(a, b)) out.push({ label: words.rowOrder, val: '', old: '' })
  return out
}
