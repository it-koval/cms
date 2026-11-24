// @vitest-environment jsdom
import { createApp, h, nextTick, ref, ssrContextKey } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import Popover from './Popover.vue'
import popSource from './Popover.vue?raw'
import Modal from './Modal.vue'
import modalSource from './Modal.vue?raw'
import Drawer from './Drawer.vue'
import drawerSource from './Drawer.vue?raw'
import ConfirmDialog from './ConfirmDialog.vue'
import confirmSource from './ConfirmDialog.vue?raw'
import { confirm, settle } from './useConfirm.js'
import { clientComponent } from '@/test/clientComponent.js'
clientComponent(Popover, popSource)
clientComponent(Modal, modalSource)
clientComponent(Drawer, drawerSource)
clientComponent(ConfirmDialog, confirmSource)
const mounted = []
const tick = async () => { await nextTick(); await nextTick() }
afterEach(() => { settle(false); mounted.splice(0).forEach((app) => app.unmount()); document.body.innerHTML = '' })
it.each([Modal, Drawer])('Escape closes only the top confirmation and preserves the lower editor', async (Lower) => {
  const open = ref(true)
  const host = document.createElement('div'); document.body.append(host)
  const app = createApp({ render: () => h('div', [h(ConfirmDialog), h(Lower, { open: open.value, 'onUpdate:open': (v) => { open.value = v } }, () => h('input', { value: 'unsaved name' }))]) })
  app.provide(ssrContextKey, { modules: new Set() }); app.mount(host); mounted.push(app)
  await tick()
  const answer = confirm({ title: 'Remove?', danger: true }); await tick()
  const underlyingHandler = vi.fn()
  document.addEventListener('keydown', underlyingHandler, true)
  try {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await tick()
    expect(underlyingHandler).not.toHaveBeenCalled()
  } finally {
    document.removeEventListener('keydown', underlyingHandler, true)
  }
  expect(await answer).toBe(false)
  expect(open.value).toBe(true)
  expect(document.querySelector('input')?.value).toBe('unsaved name')
})
it('opens a destructive confirmation with the cancel action focused', async () => {
  const host = document.createElement('div'); document.body.append(host)
  const app = createApp(ConfirmDialog); app.provide(ssrContextKey, { modules: new Set() }); app.mount(host); mounted.push(app)
  confirm({ title: 'Remove?', danger: true, cancelLabel: 'Stay', confirmLabel: 'Delete' }); await tick()
  expect(document.activeElement?.textContent).toBe('Stay')
})

it.each([Modal, Drawer])('Escape closes the popover above an overlay and restores its trigger', async (Lower) => {
  const open = ref(true), pop = ref(false), anchor = ref(null)
  const host = document.createElement('div'); document.body.append(host)
  const app = createApp({ render: () => h(Lower, { open: open.value, 'onUpdate:open': (v) => { open.value = v } }, () => [
    h('input', { value: 'Keep my draft' }),
    h('button', { ref: anchor, onClick: () => { pop.value = true } }, 'Languages'),
    h(Popover, { open: pop.value, anchor: anchor.value, 'onUpdate:open': (v) => { pop.value = v } }, () => h('button', { class: 'mi' }, 'English')),
  ]) })
  app.provide(ssrContextKey, { modules: new Set() }); app.mount(host); mounted.push(app); await tick()
  anchor.value.click(); await tick()
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await tick()
  expect(pop.value).toBe(false)
  expect(open.value).toBe(true)
  expect(document.activeElement).toBe(anchor.value)
  expect(document.querySelector('input').value).toBe('Keep my draft')
})

it('Escape closes a confirmation above a popover before the popover or its drawer', async () => {
  const open = ref(true), pop = ref(false), anchor = ref(null)
  const host = document.createElement('div'); document.body.append(host)
  const app = createApp({ render: () => h('div', [h(ConfirmDialog), h(Drawer, { open: open.value, 'onUpdate:open': (v) => { open.value = v } }, () => [
    h('button', { ref: anchor, onClick: () => { pop.value = true } }, 'Options'),
    h(Popover, { open: pop.value, anchor: anchor.value, 'onUpdate:open': (v) => { pop.value = v } }, () => h('button', { class: 'mi' }, 'Remove')),
  ])]) })
  app.provide(ssrContextKey, { modules: new Set() }); app.mount(host); mounted.push(app); await tick()
  anchor.value.click(); await tick()
  const answer = confirm({ title: 'Remove?', danger: true }); await tick()
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await tick()
  expect(await answer).toBe(false)
  expect(pop.value).toBe(true); expect(open.value).toBe(true)
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); await tick()
  expect(pop.value).toBe(false); expect(open.value).toBe(true)
})
