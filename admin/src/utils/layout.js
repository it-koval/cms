// Layout of a form: which fields share a row and where a heading of a part of the form goes.
//   field.half     (AbstractField::half())      the field takes half of the row: two neighbours with half share one row; a single one stays full width
//   field.section  (AbstractField::section())   a heading before the field; the field starts a new row
// shownFields(fields) → the fields the form draws (not `form: false`); the heading of a hidden field goes to the next shown one that has no heading of its own.
// layoutBlocks(items) → [{ key, section, items: [item, item?] }]: a block is an optional heading and one row of one or two items.
// `items` are fields, or anything that `fieldOf` turns into a field.
export function layoutBlocks(items, fieldOf = (x) => x) {
  const out = []
  let row = null
  for (const item of items || []) {
    const f = fieldOf(item)
    const heading = f.section ? String(f.section) : ''
    const joins = !heading && f.half && row && row.half && row.items.length < 2
    if (joins) { row.items.push(item); continue }
    row = { key: f.k, section: heading, items: [item], half: !!f.half }
    out.push(row)
  }
  return out.map(({ key, section, items: its }) => ({ key, section, items: its }))
}

/** The fields that are drawn, in order. A hidden field (`form: false`) hands its `section` to the next shown field without one; at the end of the list it is dropped. */
export function shownFields(fields, shown = (f) => f.form !== false) {
  const out = []
  let pending = ''
  for (const f of fields || []) {
    if (!shown(f)) {
      if (f.section) pending = String(f.section)
      continue
    }
    out.push(pending && !f.section ? { ...f, section: pending } : f)
    pending = ''
  }
  return out
}
