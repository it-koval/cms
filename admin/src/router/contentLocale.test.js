// @vitest-environment jsdom
import { createApp, h } from 'vue'
import { createMemoryHistory, createRouter, isNavigationFailure, RouterView } from 'vue-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { app, setContentLocale, switchContentLocale, useLocaleGuard } from '@/stores/app.js'
import { installContentLocaleRouting } from './contentLocale.js'

const languages = { main: 'ru', list: ['ru', 'kk', 'zh'].map((code) => ({ code, name: code })) }
const guard = vi.fn()
let root

beforeEach(() => { app.languages = languages; setContentLocale('ru'); guard.mockReset().mockResolvedValue(true) })
afterEach(() => root?.unmount())

async function mountRouter() {
  const Editor = { setup() { useLocaleGuard(guard); return () => h('p', app.locale) } }
  const routes = [{ path: '/pages/:id', name: 'page-editor', component: Editor }]
  const router = createRouter({ history: createMemoryHistory(), routes })
  installContentLocaleRouting(router)
  root = createApp({ render: () => h(RouterView) })
  await router.push('/pages/1')
  root.use(router)
  root.mount(document.createElement('div'))
  return router
}

it('uses the visible editor guard for a direct language link and falls back for a removed language', async () => {
  const router = await mountRouter()
  guard.mockResolvedValueOnce(false)
  expect(isNavigationFailure(await router.push('/pages/1?lang=kk'))).toBe(true)
  expect(app.locale).toBe('ru')
  expect(router.currentRoute.value.fullPath).toBe('/pages/1')
  await router.push('/pages/1?lang=kk')
  expect(app.locale).toBe('kk')
  await router.push('/pages/1?lang=tr')
  expect(app.locale).toBe('ru')
})

it('runs one unsaved guard while switching quickly and Back recovers the previous language', async () => {
  const router = await mountRouter()
  let release
  guard.mockImplementationOnce(() => new Promise((resolve) => { release = resolve }))
  const kk = switchContentLocale('kk')
  await vi.waitFor(() => expect(release).toBeTypeOf('function'))
  const zh = switchContentLocale('zh')
  await new Promise((resolve) => setTimeout(resolve, 0))
  release(true)
  await Promise.all([kk, zh])
  expect(guard).toHaveBeenCalledTimes(1)
  expect(router.currentRoute.value.query.lang).toBe('zh')
  expect(app.locale).toBe('zh')
  router.back()
  await vi.waitFor(() => expect(app.locale).toBe('ru'))
  expect(router.currentRoute.value.fullPath).toBe('/pages/1')
})
