// Declarative-field helpers: defaults, equality, validation, human descriptions. Fields look like
//   { k:'title', t:'text'|'textarea'|'headline'|'number'|'toggle'|'select'|'image'|'link'|'repeater'|'photos'|'readonly'|'relation' (+ the types of plugins),
//     (more keys from the server: ro, form, list:{show,sortable,width}, filter:{kind,options}, detail, to + multi for a relation — see the Field classes of the core)
//     label, max?, req?, help?, rows?, rich?, article?, opts?:[[value,label]], sub?:[fields], addLabel?, noun?, variant?, brk?,
//     half? (two short fields in one row), section? (a heading before the field), and for a repeater: itemTitle?/itemSummary? (templates of the row, see itemTemplate.js), dup? (the «Дублировать» button) }
// Text fields, by what they hold:
//   text                         one plain line (labels, button texts, SEO title, addresses)
//   textarea                     plain multi-line text (SEO description, «по одной на строку», staff notes)
//   headline                     a heading: HTML with line breaks (+ gradient words where the site design has them)
//   textarea + rich: true        DESCRIPTION: HTML paragraphs, bold, italic, link, list — all prose that visitors read
//   textarea + rich + article    the long text of a blog article (+ subheadings, quote, divider, photos)
import { nid } from './ids.js'
import { formatNumber, t } from '@/i18n/index.js'
import { homeUrl } from './locales.js'
import { same } from './objects.js'
import { siteText } from './siteTexts.js'
import { htmlToText, isHtmlEmpty, sanitizeHtml, textLength, textToHtml } from './html.js'
import { renderFirst } from './itemTemplate.js'

export const fieldsOf = (schema) => (schema || []).filter((f) => f.t !== 'readonly')

export function defaultFor(f) {
  switch (f.t) {
    // The values below are data of the site, not a text of the interface: they are saved with the section, so they follow the language of the content (main_locale), not of the screen
    case 'text': case 'textarea': return f.req ? siteText('item') : ''
    case 'headline': return f.req ? siteText('section') : ''
    case 'toggle': return false
    case 'number': return ''
    case 'select': return f.opts && f.opts.length ? f.opts[0][0] : ''
    case 'relation': return f.multi ? [] : ''
    case 'image': return { src: '', alt: '' }
    case 'link': return { kind: 'page', page: homeUrl(), url: '' }
    case 'repeater': case 'photos': return []
    default: return ''
  }
}

/** A fresh repeater item with an _id and a default for every sub-field. */
export function defaultItem(f) {
  const o = { _id: nid('p') }
  f.sub.forEach((s) => { o[s.k] = defaultFor(s) })
  return o
}

/** Data for a brand-new record of `schema` (readonly fields skipped). */
export function defaultsFor(schema) {
  const o = {}
  fieldsOf(schema).forEach((f) => { o[f.k] = defaultFor(f) })
  return o
}

/** Plain text (an AI proposal) as the stored value of field f: headlines and descriptions hold HTML. */
export function fromPlainText(f, v) {
  if (f && f.t === 'headline') return textToHtml(v, 'headline')
  if (f && f.t === 'textarea' && f.rich) return textToHtml(v, 'rich')
  return v
}

/** True when a REQUIRED field has no usable value. */
export function isMissing(f, v) {
  if (!f.req) return false
  if (f.t === 'headline' || (f.t === 'textarea' && f.rich)) return isHtmlEmpty(v)
  if (f.t === 'image') return !(v && v.src)
  return !String(v ?? '').trim()
}

const TITLE_LABEL = /^Заголовок/ // i18n-ignore: it matches the label of a field, which the schema of the site gives
export const reqMessage = (f) =>
  TITLE_LABEL.test(f.label) ? t('core.field_required_title') : t('core.field_required', { field: f.label })

/** Required-field errors, including repeater sub-fields: [{ path, field, message }]. */
export function validate(schema, data, base = '') {
  const out = []
  fieldsOf(schema).forEach((f) => {
    if (f.form === false || f.ro) return // hidden on the form or readonly: the person cannot fill it
    const v = data ? data[f.k] : undefined
    const path = base ? `${base}.${f.k}` : f.k
    if (isMissing(f, v)) out.push({ path, field: f, message: reqMessage(f) })
    if (f.t === 'repeater') (v || []).forEach((it, i) => out.push(...validate(f.sub, it, `${path}.${i}`)))
  })
  return out
}

/** Equality of two values of field f. Rich/headline HTML is compared after sanitising, so normalising is never a change. */
export function valuesEqual(f, a, b) {
  if (f && (f.t === 'headline' || (f.t === 'textarea' && f.rich))) {
    const mode = f.t === 'headline' ? 'headline' : f.article ? 'article' : 'rich'
    return sanitizeHtml(a, mode) === sanitizeHtml(b, mode)
  }
  if (f && f.t === 'repeater') return same(stripIds(a), stripIds(b))
  return same(a, b)
}
const stripIds = (v) => JSON.parse(JSON.stringify(v ?? null, (k, x) => (k === '_id' ? undefined : x)))

/** Keys of the fields whose value differs from the published one. */
export function changedKeys(schema, data, published) {
  if (!published) return []
  return fieldsOf(schema).filter((f) => !valuesEqual(f, data?.[f.k], published[f.k])).map((f) => f.k)
}

/** Characters for the «n / max» budget of a text-like field. */
export function budgetLength(f, v) {
  if (f.t === 'headline' || (f.t === 'textarea' && f.rich)) return textLength(v)
  return [...String(v ?? '')].length
}
export const budgetState = (n, max) => (n > max ? 'over' : n >= max * 0.9 ? 'warn' : '')

/** Short human description of a value for change lists («было → стало»). */
export function describeValue(f, v) {
  if (f && f.t === 'select') return ((f.opts || []).find((o) => o[0] === v) || [])[1] || String(v ?? '')
  if (f && f.t === 'relation' && !Array.isArray(v)) return v ? t('core.value_relation_set') : t('core.value_relation_empty')
  if (f && (f.t === 'headline' || (f.t === 'textarea' && f.rich))) return clip(htmlToText(v).replace(/\n/g, ' / ')) || t('core.value_empty')
  if (v && typeof v === 'object' && !Array.isArray(v)) {
    if ('src' in v) return v.alt || (v.src ? t('core.value_photo') : t('core.value_no_photo'))
    if ('kind' in v) return v.kind === 'url' ? v.url : v.kind === 'modal' ? t('core.value_call_form') : v.page
  }
  if (f && f.t === 'photos' && Array.isArray(v)) return v.length ? t('core.value_photos', { count: v.length }) : t('core.value_no_photo')
  if (Array.isArray(v)) return t('core.value_items', { count: v.length })
  if (typeof v === 'boolean') return v ? t('core.value_on') : t('core.value_off')
  return clip(String(v ?? '')) || t('core.value_empty')
}
const clip = (s) => (s.length > 56 ? s.slice(0, 54) + '…' : s)

/* ---------- «было → стало» for a changed value ---------- */
const isRich = (f) => !!f && (f.t === 'headline' || (f.t === 'textarea' && f.rich))
const richMode = (f) => (f.t === 'headline' ? 'headline' : f.article ? 'article' : 'rich')
const flat = (html) => htmlToText(html).replace(/\n/g, ' / ')
const tidy = (chars) => chars.join('').replace(/^[\s/]+|[\s/]+$/g, '')

// What the markup carries besides the words. Every group lists its elements in order, so adding, removing, moving or re-linking one is a difference.
const MARKS = [
  ['subheads', 'h2,h3'],
  ['quotes', 'blockquote'],
  ['photos', 'img'],
  ['links', 'a'],
  ['emphasis', 'strong,em'],
  ['lists', 'ul,ol'],
  ['dividers', 'hr'],
  ['tables', 'table'],
  ['gradient', 'span.grad'],
]
/** The names of the formatting groups for a person: t('core.mark_<id>') of each, joined. */
const markNames = (ids) => ids.map((id) => t(`core.mark_${id}`)).join(', ')
function markup(html, mode) {
  const body = new DOMParser().parseFromString(`<body>${sanitizeHtml(html, mode)}</body>`, 'text/html').body
  return MARKS.map(([, sel]) => [...body.querySelectorAll(sel)].map((el) => `${el.tagName}|${el.getAttribute('href') || el.getAttribute('src') || ''}|${el.textContent}`).join('\n'))
}
/** Ids of the formatting groups that differ between two rich values: ['subheads', 'photos']. */
function changedMarks(cur, old, mode) {
  const a = markup(cur, mode)
  const b = markup(old, mode)
  return MARKS.filter((_, i) => a[i] !== b[i]).map((m) => m[0])
}

/** The first place where two texts differ: the start they share is skipped, so the two sides never look identical. */
function textChange(a, b) {
  if (!a) return { val: t('core.value_empty'), old: clip(b) }
  if (!b) return { val: clip(a), old: t('core.value_empty') }
  if (a.length <= 56 && b.length <= 56) return { val: a, old: b }
  const A = Array.from(a)
  const B = Array.from(b)
  let p = 0
  while (p < A.length && p < B.length && A[p] === B[p]) p++
  if (p === B.length) return { val: t('core.change_added_end', { text: clip(tidy(A.slice(p))) }), old: '' }
  if (p === A.length) return { val: t('core.change_removed_end', { text: clip(tidy(B.slice(p))) }), old: '' }
  let from = Math.max(0, p - 16) // a little of the shared text before the difference, from a word start
  if (from > 0) { const sp = A.indexOf(' ', from); if (sp >= 0 && sp < p) from = sp + 1 }
  const piece = (X) => (from > 0 ? '…' : '') + X.slice(from, from + 46).join('').trim() + (from + 46 < X.length ? '…' : '')
  return { val: piece(A), old: piece(B) }
}

/**
 * Which item of a repeater changed, and how: { where, val, old } («ИИ Бот»: Описание — the first difference, «и ещё 2» for more), or null when
 * items were added, removed or moved (the caller then says so). Items are matched by _id.
 */
function describeItemChange(f, cur, old) {
  const a = cur || []
  const b = old || []
  if (a.length !== b.length) return null
  if (a.some((it, i) => it._id !== b[i]._id)) return { where: '', val: t('core.change_items_reordered'), old: '' }
  const diffs = []
  a.forEach((it, i) => fieldsOf(f.sub).forEach((s) => { if (!valuesEqual(s, it[s.k], b[i][s.k])) diffs.push([s, it, b[i], i]) }))
  if (!diffs.length) return null
  const [s, it, was, i] = diffs[0]
  const d = describeChange(s, it[s.k], was[s.k])
  const name = repeaterTitle(f, it, i)
  return { where: diffs.length > 1 ? t('core.change_where_more', { name, field: s.label, count: diffs.length - 1 }) : t('core.change_where', { name, field: s.label }), val: d.val, old: d.old }
}

/**
 * Short description of a changed value for the publish lists: { val, old, where? }. Plain values: `describeValue` of both sides.
 * Rich text, articles and headlines: the first fragment that differs («…дум. Квадроцикл — это → …дум. Мотоцикл — это»), «Добавлено в конце: «…»»,
 * or, when only the formatting changed, what kind («Изменено оформление: подзаголовки, фото»).
 * Repeaters: the item and field that changed (`where`, «ИИ Бот»: Описание) with the description of that field; the lists show it after the field name.
 */
export function describeChange(f, cur, old) {
  if (f && f.t === 'repeater') { const d = describeItemChange(f, cur, old); if (d) return d }
  if (!isRich(f)) return { val: describeValue(f, cur), old: describeValue(f, old) }
  const mode = richMode(f)
  const a = flat(cur)
  const b = flat(old)
  const marks = changedMarks(cur, old, mode)
  if (a === b) {
    // a headline carries nothing but the gradient (only where the site has it) and line breaks, so any other markup difference is a break
    if (f.t === 'headline') return { val: marks.includes('gradient') ? t('core.change_gradient') : t('core.change_line_break'), old: '' }
    return { val: marks.length ? t('core.change_format_marks', { marks: markNames(marks) }) : t('core.change_format'), old: '' }
  }
  const change = textChange(a, b)
  if (marks.length && f.t !== 'headline') change.val = t('core.change_text_and_format', { text: change.val, marks: markNames(marks) })
  return change
}

/* ---------- the row of a repeater: title, summary, thumbnail, copy ---------- */
let relationTitleOf = () => ''
/** The way to a record's title for a Relation inside a title template: (collection, id) → title. relationOptions.js sets it. */
export const setRelationTitle = (fn) => { relationTitleOf = typeof fn === 'function' ? fn : () => '' }

/** The value of sub-field `key` of an item as a person reads it, for a title template: a record by its title, an option by its label, rich text as plain text. */
export function itemValueText(f, it, key) {
  const s = (f.sub || []).find((x) => x.k === key)
  const v = it ? it[key] : undefined
  if (!s || v === undefined || v === null) return ''
  switch (s.t) {
    case 'select': return ((s.opts || []).find((o) => o[0] === v) || [])[1] || ''
    case 'relation': return [].concat(v).map((id) => (id ? relationTitleOf(s.to, String(id)) : '')).filter(Boolean).join(', ')
    case 'headline': case 'textarea': return htmlToText(v).replace(/\n/g, ' ')
    case 'toggle': return v ? s.label : ''
    case 'image': return v && v.src ? v.alt || '' : ''
    case 'link': case 'photos': case 'repeater': case 'readonly': return ''
    case 'number': return s.grouped && Number.isFinite(Number(v)) && v !== '' ? formatNumber(v, { maximumFractionDigits: 20 }) : String(v)
    default: return typeof v === 'object' ? '' : String(v)
  }
}

/** Title/summary of a repeater item for its collapsed header. itemTitle / itemSummary of the field (templates) win; without them, or when they give nothing, the first text. */
export function repeaterTitle(f, it, i) {
  if (f.itemTitle && f.itemTitle.length) {
    const s = renderFirst(f.itemTitle, (k) => itemValueText(f, it, k))
    if (s) return s
  }
  const net = f.sub.find((s) => s.k === 'net')
  if (net) {
    const o = net.opts.find((x) => x[0] === it.net)
    return o ? o[1] : t('core.repeater_social')
  }
  const tf = f.sub.find((s) => s.t === 'text')
  return (tf && it[tf.k]) || (f.noun ? f.noun[0].toUpperCase() + f.noun.slice(1) + ' ' + (i + 1) : t('core.repeater_item', { n: i + 1 }))
}
export function repeaterSummary(f, it) {
  if (f.itemSummary && f.itemSummary.length) return renderFirst(f.itemSummary, (k) => itemValueText(f, it, k))
  if (f.sub.find((s) => s.k === 'net')) return String(it.url || '').slice(0, 80)
  const tf = f.sub.find((s) => s.t === 'text')
  const df = f.sub.find((s) => s.t === 'textarea' || (s.t === 'text' && s !== tf))
  return df && it[df.k] ? htmlToText(it[df.k]).replace(/\n/g, ' ').slice(0, 80) : ''
}
/**
 * Thumbnail of a row: { has, src }. The first image field; `has` without `src` is an empty frame (the row keeps its shape).
 * Only a repeater with itemTitle / itemSummary also takes the first photo of its first photos field (a frame for it even when empty): the schemas without them look as before.
 */
export const repeaterThumb = (f, it) => {
  const img = f.sub.find((s) => s.t === 'image')
  const templated = !!((f.itemTitle && f.itemTitle.length) || (f.itemSummary && f.itemSummary.length))
  const pho = templated ? f.sub.find((s) => s.t === 'photos') : null
  if (!img && !pho) return { has: false, src: '' }
  const own = img && it[img.k] ? it[img.k].src : ''
  const first = pho && Array.isArray(it[pho.k]) ? it[pho.k].map((p) => (p && typeof p === 'object' ? p.src : p)).find((x) => typeof x === 'string' && x) : ''
  return { has: true, src: own || first || '' }
}

/** A copy of a repeater item with new _id everywhere: its own and those of the items nested in it. */
export function duplicateItem(it) {
  const walk = (v) => {
    if (Array.isArray(v)) return v.map(walk)
    if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, k === '_id' ? nid('p') : walk(x)]))
    return v
  }
  return walk(it)
}
