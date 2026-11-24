import { app, checkLocaleSwitch, routeLocaleSwitchThrough, setContentLocale } from '@/stores/app.js'

/** Keep an explicit language on language links. Remember the language of ordinary links in their browser history entries. */
export function installContentLocaleRouting(router) {
  const history = router.options.history
  const locales = new WeakMap()

  router.beforeEach((to, from) => {
    const stored = history.location === to.fullPath ? history.state.itkLocale : undefined
    const requested = to.query.lang ?? stored ?? app.locale
    const locale = app.languages.list.some((l) => l.code === requested) ? requested : app.languages.main
    locales.set(to, locale)
    // Different resources already use their route leave/update guards. Settings tabs share one draft.
    if (to.name === from.name && (to.path === from.path || to.name === 'settings')) return checkLocaleSwitch(locale)
    return true
  })

  router.afterEach((to, _from, failure) => {
    if (failure) return
    const locale = locales.get(to) ?? app.locale
    if (locale !== app.locale) setContentLocale(locale)
    if (app.languages.list.length > 1 && history.state.itkLocale !== locale) {
      history.replace(to.fullPath, { itkLocale: locale, scroll: history.state.scroll ?? null })
    }
  })

  routeLocaleSwitchThrough(async (lang) => {
    const route = router.currentRoute.value
    await router.push({ path: route.path, query: { ...route.query, lang }, hash: route.hash })
    return app.locale === lang
  })
}
