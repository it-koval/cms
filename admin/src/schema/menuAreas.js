// Menu AREAS: a place for a menu that the site templates can show. The area is declared in the site schema ($s->menuArea) or added by the client in Настройки → «Меню»;
// the developers insert the snippet {{ itk_menu('footer_main') }} into a site template, the client edits the items of the area and the menu appears wherever the snippet is.
//   area  = { key (latin, unique), name, depth (1 = a flat list, 2 = items may have one level of nested items) }  shared by all languages
//   items = per language per area: [{ _id, label, link:{ kind:'page'|'url', page, url }, children?:[...] }]  (see components/settings/menuModel.js)
// Where an area is shown (`usedIn`, `usedInAll`) is worked out by the server from the templates' `areas` and the schema layout.
import { slugify } from '@/components/pages/slug.js'
import { t } from '@/i18n/index.js'

/** The snippet that inserts an area into a site template. */
export const menuSnippet = (key) => `{{ itk_menu('${key}') }}`
export const SNIPPET_HINT = t('forms.menu_snippet_hint')

/** A latin key for a new area made from its name («Футер: второе меню» -> footer_vtoroe_menyu), unique among `taken`. The server makes the real one; this is the preview. */
export function makeAreaKey(name, taken = []) {
  let base = slugify(name, 32).replace(/-/g, '_') || 'menu'
  if (!/^[a-z]/.test(base)) base = 'menu_' + base
  let key = base
  for (let n = 2; taken.includes(key); n++) key = `${base}_${n}`
  return key
}
