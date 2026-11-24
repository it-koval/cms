// @vitest-environment jsdom
import { createApp, h, nextTick, reactive, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import View from './CollectionItemView.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn(), createTranslation: vi.fn(), removeTranslation: vi.fn(), remove: vi.fn(), update: vi.fn(), confirm: vi.fn(), can: vi.fn(), replace: vi.fn(), actionBusy: { value: false }, guard: null, leave: null, routeUpdate: null, route: null, app: null, cfg: null }))
vi.mock('vue-router', () => ({ useRoute: () => mocks.route, useRouter: () => ({ replace: mocks.replace }), onBeforeRouteLeave: (fn) => { mocks.leave = fn }, onBeforeRouteUpdate: (fn) => { mocks.routeUpdate = fn } }))
vi.mock('@/api/index.js', () => ({ api: { collections: mocks, proposals: { list: async () => [] } }, isConflict: () => false, whileClosing: (fn) => fn() }))
vi.mock('@/stores/app.js', async () => {
  const { reactive } = await import('vue')
  mocks.app = reactive({ locale: 'kk', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }] }, user: null })
  return { app: mocks.app, can: (...args) => mocks.can(...args), refreshCounters: vi.fn(), switchContentLocale: vi.fn(), useLocaleGuard: (fn) => { mocks.guard = fn } }
})
vi.mock('@/schema/collections.js', () => ({ getCollectionConfig: () => mocks.cfg }))
vi.mock('@/components/collections/actions/useActions.js', () => ({ useActions: () => ({ at: () => [], busy: mocks.actionBusy, dialog: { busy: false } }) }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: vi.fn(), error: vi.fn() }) }))

let host, mounted, state
const settle = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
const item = (title = 'Русский') => ({ id: 'one', title, version: 'v1', locale: mocks.app.locale, status: 'draft' })
const response = (value = item()) => ({ translated: !!value, item: value, published: null, locales: { ru: { title: 'Русский' }, kk: value ? { title: value.title } : null } })
async function mount() {
  View.render = () => null
  mounted = createApp({ render: () => h(View) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  host = document.createElement('div')
  mounted.mount(host)
  state = mounted._instance.subTree.component.setupState
  await settle()
}
beforeEach(() => {
  vi.clearAllMocks()
  mocks.actionBusy.value = false
  mocks.route = reactive({ name: 'collection-item', params: { col: 'team', id: 'one' }, query: {}, path: '/collections/team/one' })
  Object.assign(mocks.app, { locale: 'kk', languages: { main: 'ru', list: [{ code: 'ru' }, { code: 'kk' }, { code: 'zh' }] }, user: null })
  mocks.cfg = { key: 'team', title: 'Команда', titleField: 'title', tabs: [{ key: 'main', fields: [{ k: 'title', t: 'text', label: 'Название' }] }] }
  mocks.get.mockResolvedValue(response())
  mocks.createTranslation.mockResolvedValue({})
  mocks.removeTranslation.mockResolvedValue({})
  mocks.remove.mockResolvedValue({})
  mocks.update.mockResolvedValue({ version: 'v2' })
  mocks.confirm.mockResolvedValue(true)
  mocks.can.mockReturnValue(true)
})
afterEach(() => mounted?.unmount())

it('opens the requested language and creates copies or schema-default blank drafts under that language', async () => {
  mocks.get.mockResolvedValueOnce(response(null))
  await mount()
  expect(mocks.get).toHaveBeenCalledWith('team', 'one', 'kk')
  expect(state.translated).toBe(false)
  await state.startTranslation('ru')
  expect(mocks.createTranslation).toHaveBeenLastCalledWith('team', 'one', 'kk', { from: 'ru' })
  await state.startTranslation(null)
  expect(mocks.createTranslation).toHaveBeenLastCalledWith('team', 'one', 'kk', { from: null })
})

it('keeps a refused deletion in the current language with the pending edits intact', async () => {
  await mount()
  state.saver.schedule({ col: 'team', id: 'one', data: { title: 'Мои правки' } })
  mocks.removeTranslation.mockRejectedValueOnce(new Error('offline'))
  await state.removeVersion()
  expect(mocks.removeTranslation).toHaveBeenCalledWith('team', 'one', 'kk')
  expect(state.saver.hasPending()).toBe(true)
  expect(state.draft.title).toBe('Русский')
})

it('does not discard edits or change the language when a failed save is declined', async () => {
  await mount()
  state.saver.schedule({ col: 'team', id: 'one', data: { title: 'Мои правки' } })
  mocks.update.mockRejectedValue(new Error('offline'))
  mocks.confirm.mockResolvedValue(false)
  expect(await mocks.guard('zh')).toBe(false)
  expect(mocks.update).toHaveBeenCalledWith('team', 'one', { title: 'Мои правки' }, 'kk', 'v1')
  expect(state.saver.hasPending()).toBe(true)
  expect(mocks.app.locale).toBe('kk')
  state.saver.reset()
})

it('ignores a late load after navigating to another record', async () => {
  await mount()
  let finishOld
  mocks.get.mockImplementationOnce(() => new Promise((resolve) => { finishOld = resolve }))
  const old = state.load()
  mocks.get.mockResolvedValueOnce(response(item('Новая запись')))
  mocks.route.params.id = 'two'
  await settle()
  finishOld(response(item('Старая запись')))
  await old
  expect(state.draft.title).toBe('Новая запись')
})

it('keeps a singleton record identity while creating a language draft', async () => {
  mocks.cfg.singleton = 'company-info'
  mocks.route.params.id = 'company-info'
  mocks.get.mockResolvedValueOnce(response(null))
  await mount()
  await state.startTranslation(null)
  expect(mocks.get).toHaveBeenCalledWith('team', 'company-info', 'kk')
  expect(mocks.createTranslation).toHaveBeenCalledWith('team', 'company-info', 'kk', { from: null })
})

it('keeps translation deletion behind the delete permission, including singletons', async () => {
  mocks.cfg.singleton = 'one'
  mocks.can.mockImplementation((area, action) => action !== 'delete')
  await mount()
  expect(state.canEdit).toBe(true)
  expect(state.canDelete).toBe(false)
  await state.removeVersion()
  expect(mocks.removeTranslation).not.toHaveBeenCalled()
})

it('allows a form action to redirect, including to another locale, while the action finishes', async () => {
  mocks.actionBusy.value = 'go-to-page'
  await mount()
  expect(await mocks.leave()).toBe(true)
  expect(await mocks.guard('zh')).toBe(true)
})

it('clears a rejected autosave when leaving without saving', async () => {
  await mount()
  state.saver.schedule({ col: 'team', id: 'one', data: { title: 'Discard me' } })
  mocks.update.mockRejectedValueOnce(new Error('offline'))
  expect(await mocks.guard('zh')).toBe(true)
  expect(state.saver.hasPending()).toBe(false)
  expect(await state.saver.flush()).toBe(true)
  expect(mocks.update).toHaveBeenCalledTimes(1)
})

it('redirects old matrix item links and preserves an explicit language', async () => {
  mocks.cfg.view = 'matrix'
  mocks.route.query = { lang: 'kk' }
  mocks.route.hash = '#details'
  await mount()
  expect(mocks.replace).toHaveBeenCalledWith({ path: '/collections/team', query: { lang: 'kk' }, hash: '#details' })
})
