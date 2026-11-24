// @vitest-environment jsdom
import { createApp, h, ssrContextKey } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { app, can, setContentLocale } from '@/stores/app.js'
import { loadSchema } from '@/schema/registry.js'
import { setLanguages } from '@/utils/locales.js'
import { installContentLocaleRouting } from '@/router/contentLocale.js'
import { previewDestination } from '@/components/editor/previewTarget.js'
import SettingsView from './SettingsView.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn(), getMenus: vi.fn(), getMenuAreas: vi.fn(), listPages: vi.fn() }))
vi.mock('@/api/index.js', async () => ({ api: {
  site: { currentSiteSync: () => ({}) }, settings: mocks,
  pages: { list: mocks.listPages },
  requests: await import('@/api/requests.js'),
} }))
vi.mock('@/components/ui/Icon.vue', () => ({ default: { render: () => null } }))

let root, host
const languages = { main: 'ru', list: [{ code: 'ru', name: 'Русский' }, { code: 'kk', name: 'Қазақша' }] }
beforeEach(() => {
  vi.clearAllMocks()
  window.CSS ||= {}
  window.CSS.escape ||= (value) => String(value).replaceAll('"', '\\"')
  window.matchMedia = vi.fn(() => ({ matches: true }))
  Element.prototype.scrollIntoView = vi.fn()
  app.languages = languages
  setLanguages(languages)
  setContentLocale('ru')
  loadSchema({ settings: { groups: [{ key: 'contact', title: 'Contact', fields: [{ k: 'headline', t: 'text', label: 'Headline', tr: true }] }] }, forms: [{ key: 'lead', title: 'Lead' }] })
  mocks.get.mockImplementation(async (locale) => ({ headline: locale === 'kk' ? 'Қазақша мәтін' : 'Русский текст' }))
  mocks.listPages.mockResolvedValue([])
})
afterEach(() => { root?.unmount(); host?.remove() })

it.each([['ru', true], ['kk', true], ['ru', false], ['kk', false]])('opens the real %s settings field in one route transition with edit=%s', async (locale, edit) => {
  app.user = { perms: { pages: ['view'], 'set:contact': edit ? ['view', 'edit'] : ['view'] } }
  expect(can('requests')).toBe(false)
  expect(can('set:menus')).toBe(false)
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/pages/:id', name: 'page-editor', component: { render: () => null } },
    { path: '/settings/:tab?', name: 'settings', component: SettingsView, meta: { byLocale: true } },
  ] })
  installContentLocaleRouting(router)
  await router.push('/pages/home')
  root = createApp({ render: () => h(RouterView, null, { default: ({ Component }) => h(Component, { key: app.locale }) }) })
  root.use(router)
  root.provide(ssrContextKey, { modules: new Set() })
  host = document.createElement('div')
  document.body.appendChild(host)
  root.mount(host)
  const destination = previewDestination({ sec: '@settings', path: 'headline' }, locale)
  expect(destination).toBeTruthy()
  await router.push(destination)
  await vi.waitFor(() => expect(host.querySelector('[data-field-path="headline"]')?.classList.contains('hl')).toBe(true))
  const input = host.querySelector('#set_headline')
  expect(input.value).toBe(locale === 'kk' ? 'Қазақша мәтін' : 'Русский текст')
  expect(app.locale).toBe(locale)
  expect(mocks.get).toHaveBeenLastCalledWith(locale)
  expect(mocks.getMenus).not.toHaveBeenCalled()
  expect(mocks.getMenuAreas).not.toHaveBeenCalled()
  expect(!!input.closest('[inert]')).toBe(!edit)
  if (edit) expect(document.activeElement).toBe(input)
  else expect(document.activeElement).not.toBe(input)
})
