// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import View from './PageEditorView.vue'

const mocks = vi.hoisted(() => ({ app: null, flush: vi.fn(), confirm: vi.fn(), guard: null, leave: null, update: null }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ locale: 'ru' })
  return { app: mocks.app, useLocaleGuard: (fn) => { mocks.guard = fn } }
})
vi.mock('@/components/editor/PageEditor.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: 'p1' } }), onBeforeRouteLeave: (fn) => { mocks.leave = fn }, onBeforeRouteUpdate: (fn) => { mocks.update = fn } }))
vi.mock('@/components/ui/useConfirm.js', () => ({ confirm: mocks.confirm }))
vi.mock('@/components/ui/useToast.js', () => ({ toast: { error: vi.fn() } }))

let mounted, state
beforeEach(() => {
  vi.clearAllMocks()
  mocks.app.locale = 'ru'
  View.render = () => null
  mounted = createApp({ render: () => h(View) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(document.createElement('div'))
  state = mounted._instance.subTree.component.setupState
  state.editor = { flushAll: mocks.flush, canLeave: () => true }
})
afterEach(() => mounted.unmount())

it('uses the same flush protection for locale changes, page navigation and leaving', async () => {
  mocks.flush.mockResolvedValue()
  expect(await mocks.guard('kk')).toBe(true)
  expect(await mocks.leave()).toBe(true)
  expect(await mocks.update({ params: { id: 'p2' } }, { params: { id: 'p1' } })).toBe(true)
  expect(mocks.flush).toHaveBeenCalledTimes(3)
  expect(await mocks.update({ params: { id: 'p1' } }, { params: { id: 'p1' } })).toBe(true)
  expect(mocks.flush).toHaveBeenCalledTimes(3)
})

it('retains the old language when the save failed and the editor chooses to stay', async () => {
  mocks.flush.mockRejectedValue({ code: 'save_failed', reason: 'offline' })
  mocks.confirm.mockResolvedValue(false)
  expect(await mocks.guard('kk')).toBe(false)
  expect(state.shown).toBe('ru')
  expect(mocks.app.locale).toBe('ru')
})

it('follows only committed language changes without an asynchronous rollback race', async () => {
  mocks.app.locale = 'kk'
  mocks.app.locale = 'zh'
  await nextTick()
  expect(state.shown).toBe('zh')
  expect(mocks.flush).not.toHaveBeenCalled()
})

it('waits for page settings protection before navigating or flushing the page', async () => {
  const canLeave = vi.fn().mockResolvedValue(false)
  state.editor = { flushAll: mocks.flush, canLeave }
  expect(await mocks.guard('kk')).toBe(false)
  expect(mocks.flush).not.toHaveBeenCalled()
})

it('discards queued page edits only after confirmed exit without saving', async () => {
  const discardPending = vi.fn()
  state.editor = { flushAll: mocks.flush, canLeave: () => true, discardPending }
  mocks.flush.mockRejectedValue({ code: 'save_failed', reason: 'offline' })
  mocks.confirm.mockResolvedValueOnce(false).mockResolvedValueOnce(true)
  expect(await mocks.leave()).toBe(false)
  expect(discardPending).not.toHaveBeenCalled()
  expect(await mocks.leave()).toBe(true)
  expect(discardPending).toHaveBeenCalledTimes(1)
})
