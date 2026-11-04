import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { addPluginRoute } from './routes.js'

const Screen = { render: () => null }
const core = () => createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'today', component: Screen },
    { path: '/pages', name: 'pages', component: Screen },
    { path: '/x/:panel/:rest(.*)*', name: 'plugin-panel', component: Screen },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})
let warn
beforeEach(() => { warn = vi.spyOn(console, 'warn').mockImplementation(() => {}) })
afterEach(() => { warn.mockRestore() })

describe('addPluginRoute', () => {
  it('adds a route that opens, inside the rail layout by default', async () => {
    const router = core()
    const remove = addPluginRoute(router, { path: '/reports/:id?', component: Screen, meta: { title: 'Отчёты' } })
    expect(typeof remove).toBe('function')
    await router.push('/reports/5')
    expect(router.currentRoute.value.params.id).toBe('5')
    expect(router.currentRoute.value.meta).toMatchObject({ layout: 'app', title: 'Отчёты', plugin: true })
    remove()
    await router.push('/reports/5')
    expect(router.currentRoute.value.fullPath).toBe('/') // gone again: the catch-all route
  })

  it('refuses a path that belongs to the admin, a bad path and a route without a component', () => {
    const router = core()
    for (const route of [{ path: '/pages/archive', component: Screen }, { path: '/x/foo', component: Screen }, { path: '/', component: Screen }, { path: 'reports', component: Screen }, { path: '/:any', component: Screen }, { path: '/ok' }, null]) {
      expect(addPluginRoute(router, route)).toBeNull()
    }
    expect(warn).toHaveBeenCalledTimes(7)
    expect(router.getRoutes().filter((r) => r.meta.plugin)).toHaveLength(0)
  })

  it('refuses a component written with the template option: it would draw an empty screen', () => {
    const router = core()
    expect(addPluginRoute(router, { path: '/reports', component: { template: '<p>Отчёты</p>' } })).toBeNull()
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('template'))
    expect(router.getRoutes().filter((r) => r.meta.plugin)).toHaveLength(0)
    // a component with its own render function stays welcome even if it also has a template
    expect(addPluginRoute(router, { path: '/reports', component: { template: '<p></p>', render: () => null } })).toBeTypeOf('function')
  })

  it('refuses a name that belongs to a route of the admin, instead of replacing that route', async () => {
    const router = core()
    expect(addPluginRoute(router, { path: '/reports', name: 'today', component: Screen })).toBeNull()
    expect(addPluginRoute(router, { path: '/reports', name: 'pages', component: Screen })).toBeNull()
    expect(warn).toHaveBeenCalledWith(expect.stringContaining('today'))
    await router.push('/pages')
    expect(router.currentRoute.value.name).toBe('pages')
    expect(router.resolve('/').name).toBe('today')
  })

  it('lets one plugin route sit beside another one with the same start', () => {
    const router = core()
    expect(addPluginRoute(router, { path: '/reports', component: Screen })).toBeTypeOf('function')
    expect(addPluginRoute(router, { path: '/reports/archive', component: Screen, name: 'archive' })).toBeTypeOf('function')
  })

  it('takes a person who asked for the address before the plugin came to where they were going', async () => {
    const router = core()
    await router.push('/reports') // unknown yet: the catch-all sends it to «Сегодня»
    expect(router.currentRoute.value.fullPath).toBe('/')
    addPluginRoute(router, { path: '/reports', component: Screen })
    await new Promise((r) => setTimeout(r, 0))
    expect(router.currentRoute.value.fullPath).toBe('/reports')
  })

  it('does not drag a person back who went to «Сегодня» themselves', async () => {
    const router = core()
    addPluginRoute(router, { path: '/reports', component: Screen })
    await router.push('/')
    await new Promise((r) => setTimeout(r, 0))
    expect(router.currentRoute.value.fullPath).toBe('/')
  })
})
