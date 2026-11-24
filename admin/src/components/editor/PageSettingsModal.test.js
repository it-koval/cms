// @vitest-environment jsdom
import { createApp, h } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import { EDITOR_KEY } from './editorState.js'
import Modal from './PageSettingsModal.vue'

const confirm = vi.hoisted(() => vi.fn())
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => confirm }))

let app
async function mount(saveMeta) {
  confirm.mockReset()
  Modal.render = () => null
  const page = { title: 'Acerca', url: '/about', template: '', parentId: null, seo: {} }
  app = createApp({ render: () => h(Modal, { open: true }) })
  app.provide(EDITOR_KEY, { page, pages: [], pageId: 'page-1', locale: 'ru', saveMeta })
  app.mount(document.createElement('div'))
  return app._instance.subTree.component.setupState
}
afterEach(() => app?.unmount())

it('asks before leaving with changed SEO and keeps the input when the editor stays', async () => {
  const modal = await mount(vi.fn())
  expect(await modal.mayLeave()).toBe(true)
  modal.model.seo.description = 'New description'
  confirm.mockResolvedValueOnce(false).mockResolvedValueOnce(true)
  expect(await modal.mayLeave()).toBe(false)
  expect(modal.model.seo.description).toBe('New description')
  expect(await modal.mayLeave()).toBe(true)
})

it('blocks navigation during a settings save and protects the input after the request fails', async () => {
  let fail
  const modal = await mount(() => new Promise((_resolve, reject) => { fail = reject }))
  modal.model.seo.description = 'Unsaved SEO'
  const pending = modal.submit()
  expect(await modal.mayLeave()).toBe(false)
  expect(confirm).not.toHaveBeenCalled()
  fail(new Error('Cannot save'))
  await pending
  confirm.mockResolvedValueOnce(false)
  expect(await modal.mayLeave()).toBe(false)
  expect(modal.model.seo.description).toBe('Unsaved SEO')
  expect(modal.err).toBe('Cannot save')
})
