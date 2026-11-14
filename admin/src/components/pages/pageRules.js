// What «Настройки страницы» may change: the dialog in the list of pages and the one in the page editor both use it
// (the server enforces the same). Pure functions. Templates and their guards: schema/templates.js; the template / parent page choices: pageTemplateRules.js.
import { t } from '@/i18n/index.js'
import { isRootPage } from './pageTemplateRules.js'

export { isRootPage, lostFieldsText, parentChoices, templatePicks, topLevelOk } from './pageTemplateRules.js'

/**
 * @param {{ id:string, template?:string }|null} page
 * @returns {{ isHome:boolean, lockTitle:boolean, lockUrl:boolean, lockParent:boolean, lockTemplate:boolean }}
 *   home: keeps its address, its template and stays at the top level.
 */
export function pageRules(page) {
  const isHome = isRootPage(page)
  return { isHome, lockTitle: false, lockUrl: isHome, lockParent: isHome, lockTemplate: isHome }
}

/**
 * The home page of a NON-main language cannot be deleted (or taken off the site) while other pages of that language are published: its address
 * (/en) would stop working while /en/about-us stays live. `pages` = rows of api.pages.list (their `locales[code].published` tells what is on the site).
 */
export function homeVersionHeld(pages, page, locale) {
  if (!isRootPage(page)) return false
  return pages.some((x) => x.id !== page.id && x.locales && x.locales[locale] && x.locales[locale].published)
}

export const HOME_URL_HELP = t('pages.home_url_help')
export const HOME_PARENT_HELP = t('pages.home_parent_help')
export const HOME_TEMPLATE_HELP = t('pages.home_template_help')
