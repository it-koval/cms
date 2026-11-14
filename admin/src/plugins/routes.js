// ITK.addRoute: a plugin's own route, with a path of its own, next to the screens of the admin. (/x/<panel> from `panels` is the simpler way when a menu item is all you need.)
// The router and the plugin scripts meet in any order: the script of a plugin comes AFTER the bundle, but the router may already have sent a person who opened #/reports straight to «Сегодня»
// (an unknown address); once the route exists, such a person is taken where they were going.
import { markRaw } from 'vue'
import { warnTemplateOption } from './state.js'
import { t } from '@/i18n/index.js'

const firstSegment = (path) => String(path).split('/')[1] || ''

/**
 * Add a route to `router`. `route` = { path, component, name?, meta? }: the path starts with «/», its first segment is not one of the admin's own («pages», «settings», «x»...),
 * the component is a component or a function that imports one (a component written with the `template` option draws nothing and is refused), the name, if given, is not the name of a route of the admin. meta.layout defaults to 'app' (inside the rail), meta.title is the browser tab title.
 * Returns a function that removes the route, or null when the route was refused (the reason is in the console).
 */
export function addPluginRoute(router, route) {
  const refuse = (why) => { console.warn(route && route.path ? t('core.plugin_route_refused', { path: route.path, why }) : t('core.plugin_route_refused_anon', { why })); return null }
  if (!route || typeof route.path !== 'string' || !route.path.startsWith('/') || route.path === '/') return refuse(t('core.plugin_route_path'))
  if (!route.component) return refuse(t('core.plugin_route_no_component'))
  const first = firstSegment(route.path)
  if (first.startsWith(':')) return refuse(t('core.plugin_route_param_first'))
  if (router.getRoutes().some((r) => !(r.meta && r.meta.plugin) && firstSegment(r.path) === first)) return refuse(t('core.plugin_route_taken', { segment: first }))
  if (warnTemplateOption('route', route.path, route.component)) return refuse(t('core.plugin_route_template'))
  const name = route.name || `plugin:${route.path}`
  if (router.getRoutes().some((r) => r.name === name && !(r.meta && r.meta.plugin))) return refuse(t('core.plugin_route_name_taken', { name: String(name) }))
  const remove = router.addRoute({
    ...route,
    name,
    component: typeof route.component === 'function' ? route.component : markRaw(route.component),
    meta: { layout: 'app', title: t('core.area_panel'), ...(route.meta || {}), plugin: true },
  })
  // the person asked for this address before the plugin arrived: the router has sent them to «Сегодня» through the catch-all route
  router.isReady().then(() => {
    const now = router.currentRoute.value
    if (now.redirectedFrom && now.fullPath === '/' && router.resolve(now.redirectedFrom.fullPath).name === name) router.replace(now.redirectedFrom.fullPath)
  })
  return remove
}
