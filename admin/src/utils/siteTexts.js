// The few texts the admin writes INTO the data of the site (the value of a new item, the name of a new menu item). They follow the language of the
// content (main_locale), not the language of the screen: the server gives them in window.ITK_ADMIN.siteTexts. `npm run dev` has no shell page: the mock site is Russian.
const FALLBACK = { item: 'Новый пункт', section: 'Новая секция' } // i18n-ignore: the defaults of the mock site (main_locale ru)

/** @param {'item'|'section'} key */
export function siteText(key) {
  const given = typeof window !== 'undefined' && window.ITK_ADMIN && window.ITK_ADMIN.siteTexts
  return (given && given[key]) || FALLBACK[key]
}
