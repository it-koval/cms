// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import View from './MatrixView.vue'
import { setLanguages } from '@/utils/locales.js'

const mocks = vi.hoisted(() => ({ app: null, get: vi.fn(), createTranslation: vi.fn(), removeTranslation: vi.fn(), update: vi.fn(), confirm: vi.fn(), can: vi.fn(), guard: null }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ locale: 'kk', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }] } })
  return { app: mocks.app, can: (...args) => mocks.can(...args), refreshCounters: vi.fn(), switchContentLocale: vi.fn(), useLocaleGuard: (fn) => { mocks.guard = fn } }
})
vi.mock('vue-router', () => ({ onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { collections: mocks }, isConflict: () => false, whileClosing: (fn) => fn() }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))

let mounted, state
const settle = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
const table = (name = 'Баға') => ({ id: 'price-table', version: 'v1', cols: [{ id: 'c1', name }], rows: [], status: 'draft' })
const response = (item = table()) => ({ item, published: null, locales: { ru: { title: 'Цены' }, kk: item ? { status: 'draft' } : null } })
async function mount() {
  View.render = () => null
  const cfg = { key: 'pricing', title: 'Цены', view: 'matrix', singleton: 'price-table', matrix: { colFields: [], rowKinds: [] } }
  mounted = createApp({ render: () => h(View, { cfg }) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(document.createElement('div'))
  state = mounted._instance.subTree.component.setupState
  await settle()
}
beforeEach(() => {
  vi.clearAllMocks()
  setLanguages(mocks.app.languages)
  mocks.get.mockResolvedValue(response())
  mocks.update.mockResolvedValue({ version: 'v2' })
  mocks.createTranslation.mockResolvedValue({})
  mocks.confirm.mockResolvedValue(true)
  mocks.can.mockReturnValue(true)
})
afterEach(() => { state?.saver.reset(); mounted?.unmount() })

it('opens the singleton translation and supports both a copy and schema-default blank table', async () => {
  mocks.get.mockResolvedValueOnce(response(null))
  await mount()
  expect(mocks.get).toHaveBeenCalledWith('pricing', 'price-table', 'kk')
  expect(state.translated).toBe(false)
  await state.startTranslation('ru')
  expect(mocks.createTranslation).toHaveBeenLastCalledWith('pricing', 'price-table', 'kk', { from: 'ru' })
  await state.startTranslation(null)
  expect(mocks.createTranslation).toHaveBeenLastCalledWith('pricing', 'price-table', 'kk', { from: null })
})

it('keeps pending changes after a refused translation deletion', async () => {
  await mount()
  state.saver.schedule({ data: { cols: [{ id: 'c1', name: 'Новое имя' }], rows: [] } })
  mocks.removeTranslation.mockRejectedValue(new Error('offline'))
  await state.removeVersion()
  expect(mocks.removeTranslation).toHaveBeenCalledWith('pricing', 'price-table', 'kk')
  expect(state.saver.hasPending()).toBe(true)
})

it('retains the table when autosave fails and the language switch is declined', async () => {
  await mount()
  state.saver.schedule({ data: { cols: [], rows: [] } })
  mocks.update.mockRejectedValue(new Error('offline'))
  mocks.confirm.mockResolvedValue(false)
  expect(await mocks.guard('zh')).toBe(false)
  expect(mocks.update).toHaveBeenCalledWith('pricing', 'price-table', { cols: [], rows: [] }, 'kk', 'v1')
  expect(state.saver.hasPending()).toBe(true)
})

it('does not retry a failed autosave after leaving without saving', async () => {
  await mount()
  state.saver.schedule({ data: { cols: [], rows: [] } })
  mocks.update.mockRejectedValueOnce(new Error('offline'))
  expect(await mocks.guard('zh')).toBe(true)
  expect(state.saver.hasPending()).toBe(false)
  expect(await state.saver.flush()).toBe(true)
  expect(mocks.update).toHaveBeenCalledTimes(1)
})

it('ignores an earlier table load that completes after a newer one', async () => {
  await mount()
  let finish
  mocks.get.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  const old = state.load()
  mocks.get.mockResolvedValueOnce(response(table('Новая')))
  await state.load()
  finish(response(table('Старая')))
  await old
  expect(state.tbl.cols[0].name).toBe('Новая')
})

it('saves the current language before opening its history, then reloads a restored draft', async () => {
  await mount()
  state.saver.schedule({ data: { cols: [], rows: [] } })
  await state.openHistory()
  expect(mocks.update).toHaveBeenCalledWith('pricing', 'price-table', { cols: [], rows: [] }, 'kk', 'v1')
  expect(state.histOpen).toBe(true)
  mocks.get.mockResolvedValueOnce(response(table('Из истории')))
  await state.onRestored()
  expect(state.tbl.cols[0].name).toBe('Из истории')
  expect(state.histOpen).toBe(false)
})

it('keeps a matrix translation behind the delete permission', async () => {
  mocks.can.mockImplementation((area, action) => action !== 'delete')
  await mount()
  expect(state.canDelete).toBe(false)
  await state.removeVersion()
  expect(mocks.removeTranslation).not.toHaveBeenCalled()
})
