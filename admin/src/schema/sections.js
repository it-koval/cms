// Section types: the schema that drives the sidebar form, validation, the block library and the preview.
// The types themselves come from the site schema (schema/registry.js); this file is the generic logic over them. Headline and description values are HTML strings (see utils/html.js).
//
// ONE RULE for every section of every site (the CMS does common things the same way):
//   - the heading of a section is a `headline` field (key `headline`, TipTap, line breaks; the «Градиент» mark only when the site design has it, see siteDesign());
//   - the page's single <h1> is the headline of the first visible firstScreen section (pageH1Id), every other headline is an <h2>;
//   - prose (descriptions, paragraphs, answers, card texts) is `{ t:'textarea', rich:true }` = the description editor (paragraphs, bold, italic, link, list);
//   - short titles of repeater items (slide, card, step, FAQ question), button texts, eyebrows and one-line captions are plain `text` — they are labels,
//     not headings and not prose. SEO fields and list-like fields («Модели, по одной на строку») stay plain too.
//
// A type: { key, name, short, group, tone, h, sil, about, fields:[...], variants:[{value,label}] }
//   name     — full name in the block library («Карточка и фото на сером фоне»)
//   short    — name on the canvas toolbar and in «Структура» (a short phrase like «Для кого» in a site's own schema; the type label when there is none)
//   sil/tone — silhouette kind and tone for preview/thumbnail (preview/silhouettes.js)
//   variants — options of the field flagged `variant: true` («Вид секции»); [] when the type has none.
// Field types are described in utils/fields.js. Readonly fields (`t:'readonly'`) are not data, they only explain.
import { defaultFor, fieldsOf } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'
import { byKey, schema } from './registry.js'

export const FIRST_SCREEN = 'Первый экран' // i18n-ignore: the name of a group of the site schema, matched against section.group (the site's own wording)

/** What the site's DESIGN can show. headlineMarks — marks a headline may carry: 'gradient' = words in gradient colour (the «Градиент» button, <span class="grad">). */
export const siteDesign = () => schema.design

export const getSectionType = (type) => byKey(schema.sections, type)
/** Short name for toolbars and lists; falls back to the raw key for unknown types. */
export const sectionName = (type) => { const t = getSectionType(type); return t ? t.short || t.name : type }
/** Editable fields (readonly notes excluded) of a section type. */
export const sectionFields = (type) => { const t = getSectionType(type); return t ? fieldsOf(t.fields) : [] }

/**
 * The page's one <h1>: the headline of the FIRST VISIBLE section marked firstScreen (pageH1Id); every other headline is an <h2>
 * (also the headline of a second first-screen section). Hidden sections do not count. Returns the section id, or null when the page has none.
 * A TEMPLATE with `h1` ({ field }) owns the H1 (schema/templates.js): pass the template and no section is the H1; `hasPageH1` tells whether the page has a main heading at all.
 */
export function pageH1Id(sections, template = null) {
  if (template && template.h1) return null // the template gives the H1 (an article's title): no section carries it, every section headline is an <h2>
  const s = (sections || []).find((x) => { const t = !x.hidden && getSectionType(x.type); return t && (t.firstScreen ?? t.group === FIRST_SCREEN) })
  return s ? s.id : null
}
/** Does the page have a main heading (H1): from its template, or from the first visible first-screen section? */
export const hasPageH1 = (sections, template = null) => !!(template && template.h1) || !!pageH1Id(sections)
export const H1_LABEL = t('forms.h1_label')
export const H1_HELP = t('forms.h1_help')
/** The name of a group of the block library on screen: the first-screen group is the identifier of the schema and has a label of its own, the groups of a site are named by the site. */
export const groupLabel = (group) => (group === FIRST_SCREEN ? t('editor.lib_first_screen') : group)
export const NO_H1_NOTICE = t('forms.h1_missing', { group: groupLabel(FIRST_SCREEN) })

/**
 * The field that IS the page's H1 gets its label and the H1 explanation (once, as its own lines): `extra` lines go after it, then the field's own help.
 * Only the field the schema declares as the H1 (a template's `h1`, a first-screen section's `h1`) is passed here; every other headline keeps its own label and help.
 */
export const asH1Field = (f, extra = []) => ({ ...f, label: H1_LABEL, help: [H1_HELP, ...extra, f.help].filter(Boolean).join('\n') })

/** All fields of a section type for its form (with the readonly notes). When the section carries the page's h1, the ONE field the type declares as its h1 is labelled so. */
export function formFields(type, isH1 = false) {
  const t = getSectionType(type)
  if (!t) return []
  return isH1 && t.h1 ? t.fields.map((f) => (f.k === t.h1 ? asH1Field(f) : f)) : t.fields
}

/** Block library of the site: [{ group, items:[{ key, name, group, sil, tone, about }] }] in the order of schema.sectionGroups («Первый экран» first). */
export function sectionLibrary() {
  const items = schema.sections.map((t) => ({ key: t.key, name: t.name, group: t.group, sil: t.sil, tone: t.tone, about: t.about }))
  const groups = [...(schema.sectionGroups.length ? schema.sectionGroups : [FIRST_SCREEN]), ...items.map((i) => i.group)].filter((g, i, a) => a.indexOf(g) === i)
  return groups.filter((g) => items.some((i) => i.group === g)).map((group) => ({ group, items: items.filter((i) => i.group === group) }))
}

/** Default data for a new section of `type` (required text gets a visible placeholder value). */
export function newSectionData(type) {
  const data = {}
  sectionFields(type).forEach((f) => { data[f.k] = defaultFor(f) })
  return data
}
