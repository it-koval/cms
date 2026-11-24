// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import View from './CollectionList.vue'
import { setLanguages } from '@/utils/locales.js'

const mocks = vi.hoisted(() => ({ app: null, query: vi.fn(), get: vi.fn(), create: vi.fn(), list: vi.fn(), push: vi.fn(), update: vi.fn(), reorder: vi.fn(), confirm: vi.fn(), show: vi.fn(), localeGuard: null, leaveGuard: null, updateGuard: null }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ locale: 'kk', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }] }, collections: [{ key: 'team', count: 3, locales: { ru: { count: 3, unpublished: 0 }, kk: { count: 2, unpublished: 1 } } }] })
  return { app: mocks.app, can: () => true, refreshCounters: vi.fn(), useLocaleGuard: (fn) => { mocks.localeGuard = fn } }
})
vi.mock('vue-router', () => ({ useRouter: () => ({ push: mocks.push }), onBeforeRouteLeave: (fn) => { mocks.leaveGuard = fn }, onBeforeRouteUpdate: (fn) => { mocks.updateGuard = fn } }))
vi.mock('@/api/index.js', () => ({ api: { collections: mocks, pages: { list: async () => [] }, prefs: { get: async () => ({}), put: async () => ({}) } } }))
vi.mock('@/components/collections/actions/useActions.js', () => ({ useActions: () => ({ at: () => [], busy: { value: false }, dialog: { busy: false } }) }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: mocks.show, error: vi.fn() }) }))

let mounted, state
const cfg = { key: 'team', title: 'Команда', description: '', titleField: 'title', view: 'table', tabs: [{ key: 'main', fields: [{ k: 'title', t: 'text', label: 'Название' }] }] }
const settle = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
const envelope = (items = []) => ({ items, total: 3, page: 1, perPage: 50, facets: { status: { published: 1, draft: 1, missing: 1 } }, metrics: [] })
async function mount(config = cfg) {
  View.render = () => null
  mounted = createApp({ render: () => h(View, { cfg: config }) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(document.createElement('div'))
  state = mounted._instance.subTree.component.setupState
  await settle()
}
beforeEach(() => {
  vi.clearAllMocks()
  mocks.app.locale = 'kk'
  mocks.app.collections = [{ key: 'team', count: 3, locales: { ru: { count: 3, unpublished: 0 }, kk: { count: 2, unpublished: 1 } } }]
  mocks.localeGuard = mocks.leaveGuard = mocks.updateGuard = null
  setLanguages(mocks.app.languages)
  mocks.query.mockResolvedValue(envelope())
  mocks.create.mockResolvedValue({ id: 'new' })
  mocks.get.mockResolvedValue({ item: { id: 'one', title: 'Русский оригинал' } })
  mocks.update.mockResolvedValue({})
  mocks.list.mockResolvedValue([{ id: 'one', translated: true }, { id: 'two', translated: true }])
  mocks.confirm.mockResolvedValue(false)
})
afterEach(() => mounted?.unmount())

it('loads the active language and uses per-language counts independently of a status filter', async () => {
  await mount()
  expect(mocks.query).toHaveBeenCalledWith('team', expect.objectContaining({ locale: 'kk' }))
  expect(state.translatedCount).toBe(2)
  state.ls.state.filter = { status: 'missing' }
  mocks.query.mockResolvedValueOnce({ ...envelope(), total: 1, facets: { status: { missing: 1 } } })
  await state.load()
  expect(mocks.query.mock.lastCall[1]).toMatchObject({ status: 'missing', filter: {} })
  expect(state.translatedCount).toBe(2)
  expect(state.wholeTotal).toBe(3)
})

it('keeps counts reactive after a slower counter refresh finishes', async () => {
  await mount()
  mocks.app.collections = [{ key: 'team', count: 2, locales: { kk: { count: 1, unpublished: 0 } } }]
  expect(state.translatedCount).toBe(1)
  expect(state.wholeTotal).toBe(2)
})

it('reads the entire wide view beyond 2000 rows', async () => {
  mocks.query.mockImplementation(async (_col, { page }) => ({ items: Array.from({ length: page === 11 ? 1 : 200 }, (_, i) => ({ id: String((page - 1) * 200 + i) })), total: 2001, page, perPage: 200 }))
  await mount({ ...cfg, view: 'calendar', views: [{ type: 'calendar', start: 'date' }] })
  await vi.waitFor(() => expect(state.rows).toHaveLength(2001))
  expect(mocks.query).toHaveBeenCalledTimes(11)
})

it.each(['toggle', 'calendar', 'kanban'])('keeps %s undo bound to the original collection language after navigation', async (action) => {
  await mount({ ...cfg, toggleField: 'visible' })
  const row = { id: 'one', title: 'KK title', translated: true, visible: false, date: '2026-11-01', state: 'old' }
  if (action === 'toggle') await state.toggle(row, { target: { checked: true } })
  else await state.patchRow(row, action === 'calendar' ? { date: '2026-11-02' } : { state: 'new' }, () => 'changed')
  const undo = mocks.show.mock.lastCall[1].undo
  mounted.unmount()
  mounted = null
  mocks.app.locale = 'ru'
  mocks.query.mockClear()
  await undo()
  expect(mocks.update.mock.lastCall).toEqual(['team', 'one', action === 'toggle' ? { visible: false } : action === 'calendar' ? { date: '2026-11-01' } : { state: 'old' }, 'kk'])
  expect(mocks.query).not.toHaveBeenCalled()
})

it('protects an unsaved reorder on locale, leave and collection changes', async () => {
  await mount({ ...cfg, view: 'cards', sortable: true })
  await state.startReorder()
  state.place(0, 1)
  expect(mocks.localeGuard).toBeTypeOf('function')
  expect(await mocks.localeGuard('ru')).toBe(false)
  expect(await mocks.leaveGuard({ name: 'pages' })).toBe(false)
  expect(await mocks.updateGuard({ params: { col: 'other' } }, { params: { col: 'team' } })).toBe(false)
  expect(state.dirty).toBe(true)
  mocks.confirm.mockResolvedValueOnce(true)
  expect(await mocks.localeGuard('ru')).toBe(true)
})

it('waits for an in-flight reorder save before allowing a locale change', async () => {
  await mount({ ...cfg, view: 'cards', sortable: true })
  await state.startReorder()
  state.place(0, 1)
  let finish
  mocks.reorder.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  const saving = state.saveReorder()
  let allowed = false
  const leaving = mocks.localeGuard('ru').then((value) => { allowed = value })
  await settle()
  expect(allowed).toBe(false)
  expect(mocks.confirm).not.toHaveBeenCalled()
  finish({ ok: true })
  await Promise.all([saving, leaving])
  expect(allowed).toBe(true)
  expect(mocks.confirm).not.toHaveBeenCalled()
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
