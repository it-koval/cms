// @vitest-environment jsdom
import { createApp, h, nextTick, ref, ssrContextKey } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import Profile from './ProfileDialog.vue'
import profileSource from './ProfileDialog.vue?raw'
import Modal from './Modal.vue'
import modalSource from './Modal.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
const mocks = vi.hoisted(() => ({ confirm: vi.fn(), update: vi.fn(), refresh: vi.fn(), app: null }))
vi.mock('@/stores/app.js', async () => {
  mocks.app = (await import('vue')).reactive({ user: { id: 'one', name: 'Original', email: 'old@example.test', roleName: 'Editor' }, siteInfo: { name: 'Site' } })
  return { app: mocks.app, refreshCurrentUser: mocks.refresh }
})
vi.mock('@/api/index.js', () => ({ api: { users: { update: mocks.update } } }))
vi.mock('./useConfirm.js', () => ({ useConfirm: () => mocks.confirm }))
vi.mock('@/components/profile/TwoFactorPanel.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/profile/MyTokens.vue', () => ({ default: { render: () => null } }))
clientComponent(Modal, modalSource); clientComponent(Profile, profileSource)
let mounted
const tick = async () => { for (let i = 0; i < 4; i++) { await Promise.resolve(); await nextTick() } }
beforeEach(() => { vi.clearAllMocks(); mocks.confirm.mockResolvedValue(false) })
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
it.each(['Escape', 'scrim'])('protects unsaved name and email on %s', async (action) => {
  const open = ref(false)
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(Profile, { open: open.value, 'onUpdate:open': (value) => { open.value = value } }) })
  mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host)
  open.value = true; await tick()
  const state = mounted._instance.subTree.component.setupState
  state.form.name = 'Changed name'; state.form.email = 'new@example.test'; await tick()
  const close = () => action === 'Escape' ? document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })) : document.querySelector('.scrim').click()
  close(); await tick()
  expect(mocks.confirm).toHaveBeenCalledOnce()
  expect(open.value).toBe(true)
  expect(document.querySelector('#pf-name').value).toBe('Changed name')
  expect(document.querySelector('#pf-email').value).toBe('new@example.test')
  mocks.confirm.mockResolvedValueOnce(true); close(); await tick()
  expect(open.value).toBe(false)
})
