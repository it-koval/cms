// @vitest-environment jsdom
import { createApp, h, nextTick, ref } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { useUnsavedChanges } from './useUnsavedChanges.js'
import { installContentLocaleRouting } from '@/router/contentLocale.js'
const mocks = vi.hoisted(() => ({ confirm: vi.fn(), localeGuard: null, app: null }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ locale: 'ru', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }] } })
  return {
    app: mocks.app,
    useLocaleGuard: (guard) => { mocks.localeGuard = guard },
    checkLocaleSwitch: (code) => code === mocks.app.locale ? true : mocks.localeGuard?.(code),
    setContentLocale: (code) => { mocks.app.locale = code }, routeLocaleSwitchThrough: vi.fn(),
  }
})
let mounted
beforeEach(() => { vi.clearAllMocks(); mocks.localeGuard = null; mocks.app.locale = 'ru'; mocks.confirm.mockResolvedValue(true) })
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
async function mount() {
  const Panel = { setup() { useUnsavedChanges(ref(true)); return () => h('p', 'Draft') } }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ name: 'settings', path: '/settings/:tab?', component: Panel }] })
  installContentLocaleRouting(router)
  await router.push('/settings/mail'); await router.isReady()
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(RouterView) }); mounted.use(router); mounted.mount(host); await nextTick()
  return router
}
it.each(['/settings/mail?lang=kk', '/settings/theme?lang=kk'])('asks once when locale and route guards both run for %s', async (path) => {
  const router = await mount()
  await router.push(path)
  expect(mocks.confirm).toHaveBeenCalledTimes(1)
  expect(router.currentRoute.value.fullPath).toBe(path)
  mocks.confirm.mockClear()
  await router.push('/settings/bots?lang=kk')
  expect(mocks.confirm).toHaveBeenCalledTimes(1)
})
it('does not carry a locale approval into another navigation cancelled by a later guard', async () => {
  const router = await mount()
  const remove = router.beforeResolve((to) => to.query.lang !== 'kk')
  await router.push('/settings/mail?lang=kk')
  expect(router.currentRoute.value.fullPath).toBe('/settings/mail')
  remove(); mocks.confirm.mockClear(); mocks.confirm.mockResolvedValueOnce(false)
  await router.push('/settings/theme')
  expect(mocks.confirm).toHaveBeenCalledTimes(1)
  expect(router.currentRoute.value.fullPath).toBe('/settings/mail')
})

it('uses language-switch copy with no invented change count', async () => {
  const router = await mount()
  await router.push('/settings/mail?lang=kk')
  const dialog = mocks.confirm.mock.calls[0][0]
  expect(dialog.title).toBe('Переключить язык?')
  expect(dialog.confirmLabel).toBe('Переключить без сохранения')
  expect(dialog.message).not.toContain('(1)')
  expect(dialog.message).toContain('язык')
})
it('uses count-free leave copy for a settings section change', async () => {
  const router = await mount()
  await router.push('/settings/theme')
  const dialog = mocks.confirm.mock.calls[0][0]
  expect(dialog.title).toBe('Выйти без сохранения?')
  expect(dialog.message).not.toContain('(1)')
})
