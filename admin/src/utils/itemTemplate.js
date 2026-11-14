// Templates for the title and the summary of a repeater row (Repeater::itemTitle / itemSummary).
//
//   '{bike} · {yearFrom}–{yearTo}'      {key} is the value of the sub-field with that key; everything else is plain text.
//
// A value is shown as a person reads it (a Relation by the title of the record, a Select by the label of the option) — that is up to the caller (`valueOf`).
// An empty value takes its text away with it, so the row never reads « · –»:
//   - the text between two values is printed only between two values that are there; when values in the middle are empty, the text that stood
//     before the first of them is used:  '{a} · {b}–{c}'  with b empty → «A · C»;
//   - the text before the first value and after the last one stays only next to a value that is there: '№ {sku}', '{price} ₽' (nothing, when the sku or the price is empty);
//   - a template with no {key} at all is a fixed text.
// Several templates are tried in order, the first that gives something wins: ['{bike} · {yearFrom}–{yearTo}', '{title}'].
const KEY = /\{([A-Za-z0-9_]+)\}/g

const tidy = (v) => String(v ?? '').replace(/\s+/g, ' ').trim()

/** One template → text ('' when every value in it is empty). valueOf(key) → string | undefined. */
export function renderTemplate(tpl, valueOf) {
  const src = String(tpl ?? '')
  const lits = []
  const keys = []
  let last = 0
  for (const m of src.matchAll(KEY)) {
    lits.push(src.slice(last, m.index))
    keys.push(m[1])
    last = m.index + m[0].length
  }
  lits.push(src.slice(last))
  if (!keys.length) return tidy(src)
  let out = ''
  let sep = null // the text in front of the first empty value of a run: it joins the next value that is there
  keys.forEach((k, i) => {
    if (sep === null) sep = lits[i]
    const v = tidy(valueOf(k))
    if (!v) return
    out += out ? sep + v : (i === 0 ? lits[0] : '') + v
    sep = null
    if (i === keys.length - 1) out += lits[keys.length]
  })
  return tidy(out)
}

/** The first of the templates that gives a non-empty text, or ''. */
export function renderFirst(templates, valueOf) {
  for (const tpl of [].concat(templates || [])) {
    const s = renderTemplate(tpl, valueOf)
    if (s) return s
  }
  return ''
}
