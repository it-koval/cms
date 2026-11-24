// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import View from './PagesView.vue'
import { setLanguages } from '@/utils/locales.js'

const mocks = vi.hoisted(() => ({ app: null, list: vi.fn(), push: vi.fn(), replace: vi.fn(), removeTranslation: vi.fn(), confirm: vi.fn() }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ locale: 'kk', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }] }, siteInfo: { domain: 'test' }, user: null })
  return { app: mocks.app, can: () => true }
})
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ push: mocks.push, replace: mocks.replace }) }))
vi.mock('@/api/index.js', () => ({ api: { pages: { list: mocks.list, removeTranslation: mocks.removeTranslation } }, isConflict: () => false }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))
vi.mock('@/components/pages/pageTemplateRules.js', () => ({ hasFlatList: () => false, flatWords: () => ({}), isLeafPage: () => false, isRootPage: (p) => p.template === 'home', newPageChoices: () => [] }))
vi.mock('@/schema/templates.js', () => ({ canPlace: () => true, getTemplate: () => null, listingSwitch: () => null, templateName: () => '', templatesFor: () => [] }))

let mounted, state
const settle = async () => { await Promise.resolve(); await nextTick(); await Promise.resolve(); await nextTick() }
const row = (id = 'p1', template = 'page') => ({ id, template, parentId: null, locale: 'kk', translated: true, title: id, status: 'published', fields: {}, locales: { ru: { status: 'published' }, kk: { status: 'published', published: true } } })
async function mount() {
  View.render = () => null
  mounted = createApp({ render: () => h(View) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(document.createElement('div'))
  state = mounted._instance.subTree.component.setupState
  await settle()
}
beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  mocks.app.locale = 'kk'
  setLanguages(mocks.app.languages)
  mocks.list.mockResolvedValue([row('home', 'home'), row('child')])
})
afterEach(() => mounted?.unmount())

it('shows translated and missing language versions and permits unpublishing a secondary home with live children', async () => {
  await mount()
  expect(mocks.list).toHaveBeenCalledWith({ locale: 'kk' })
  state.menuRow = state.allPages[0]
  expect(state.m.unpublish).toBe(true)
  mocks.app.locale = 'ru'
  expect(state.m.unpublish).toBe(false)
  state.menuRow = { ...row(), translated: false, status: 'missing' }
  expect(state.m.settingsOff).toBe(true)
  expect(state.m.publish).toBe(false)
})

it('creates in the main language through a navigable language URL while retaining the list language', async () => {
  await mount()
  await state.onCreated({ id: 'new', title: 'Новая' })
  expect(mocks.push).toHaveBeenCalledWith('/pages/new?lang=ru')
  expect(mocks.app.locale).toBe('kk')
})

it('lets the newer list request win when an earlier request returns late', async () => {
  await mount()
  let finish
  mocks.list.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  const old = state.load()
  mocks.list.mockResolvedValueOnce([row('latest')])
  await state.load()
  finish([row('stale')])
  await old
  expect(state.allPages[0].id).toBe('latest')
})

it('does not open the current page language while the native version selector is being opened', async () => {
  await mount()
  state.onRow(row(), { target: document.createElement('select') })
  expect(mocks.push).not.toHaveBeenCalled()
})
