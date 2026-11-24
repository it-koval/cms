// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import View from './CollectionList.vue'
import { setLanguages } from '@/utils/locales.js'

const mocks = vi.hoisted(() => ({ app: null, query: vi.fn(), get: vi.fn(), create: vi.fn(), list: vi.fn(), push: vi.fn() }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ locale: 'kk', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }] }, collections: [{ key: 'team', count: 3, locales: { ru: { count: 3, unpublished: 0 }, kk: { count: 2, unpublished: 1 } } }] })
  return { app: mocks.app, can: () => true, refreshCounters: vi.fn() }
})
vi.mock('vue-router', () => ({ useRouter: () => ({ push: mocks.push }) }))
vi.mock('@/api/index.js', () => ({ api: { collections: mocks, prefs: { get: async () => ({}), put: async () => ({}) } } }))
vi.mock('@/components/collections/actions/useActions.js', () => ({ useActions: () => ({ at: () => [], busy: { value: false }, dialog: { busy: false } }) }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => vi.fn() }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))

let mounted, state
const cfg = { key: 'team', title: 'Команда', description: '', titleField: 'title', view: 'table', tabs: [{ key: 'main', fields: [{ k: 'title', t: 'text', label: 'Название' }] }] }
const settle = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
const envelope = (items = []) => ({ items, total: 3, page: 1, perPage: 50, facets: { status: { published: 1, draft: 1, missing: 1 } }, metrics: [] })
async function mount() {
  View.render = () => null
  mounted = createApp({ render: () => h(View, { cfg }) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(document.createElement('div'))
  state = mounted._instance.subTree.component.setupState
  await settle()
}
beforeEach(() => {
  vi.clearAllMocks()
  mocks.app.locale = 'kk'
  setLanguages(mocks.app.languages)
  mocks.query.mockResolvedValue(envelope())
  mocks.create.mockResolvedValue({ id: 'new' })
  mocks.get.mockResolvedValue({ item: { id: 'one', title: 'Русский оригинал' } })
})
afterEach(() => mounted?.unmount())

it('loads the active language and uses per-language counts independently of a status filter', async () => {
  await mount()
  expect(mocks.query).toHaveBeenCalledWith('team', expect.objectContaining({ locale: 'kk' }))
  expect(state.translatedCount).toBe(2)
  state.ls.state.filter = { status: 'missing' }
  mocks.query.mockResolvedValueOnce({ ...envelope(), total: 1, facets: { status: { missing: 1 } } })
  await state.load()
  expect(state.translatedCount).toBe(2)
  expect(state.wholeTotal).toBe(3)
})

it('creates and duplicates in the main language through navigable URLs without changing the current list', async () => {
  await mount()
  await state.add()
  expect(mocks.push).toHaveBeenLastCalledWith('/collections/team/new?new=1&lang=ru')
  await state.duplicate({ id: 'one' })
  expect(mocks.get).toHaveBeenCalledWith('team', 'one', 'ru')
  expect(mocks.push).toHaveBeenLastCalledWith('/collections/team/new?new=1&lang=ru')
  expect(mocks.app.locale).toBe('kk')
})

it('ignores a stale response after a newer filter request completes', async () => {
  await mount()
  let finish
  mocks.query.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  const old = state.load()
  mocks.query.mockResolvedValueOnce(envelope([{ id: 'new' }]))
  await state.load()
  finish(envelope([{ id: 'old' }]))
  await old
  expect(state.rows).toEqual([{ id: 'new' }])
})

it('leaves the compact language selector in control instead of opening the row on its first tap', async () => {
  await mount()
  state.onRow({ target: document.createElement('select') }, { id: 'one' })
  expect(mocks.push).not.toHaveBeenCalled()
})
