// @vitest-environment jsdom
import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import SettingsFormTab from './SettingsFormTab.vue'

let app, host, form
beforeEach(() => {
  window.CSS ||= {}
  window.CSS.escape ||= (value) => String(value).replaceAll('"', '\\"')
  window.matchMedia = vi.fn(() => ({ matches: true }))
  Element.prototype.scrollIntoView = vi.fn()
})
afterEach(() => { app?.unmount(); host?.remove() })
async function mount(readonly = false) {
  host = document.createElement('div')
  document.body.appendChild(host)
  app = createApp({ render: () => h(SettingsFormTab, {
    ref: (value) => { form = value }, readonly,
    sections: [{ key: 'contact', title: 'Contact', fields: [{ k: 'phone', t: 'text', label: 'Phone' }] }],
    data: { phone: '123' }, changed: new Set(),
  }) })
  app.mount(host)
  await nextTick()
}
it('reveals and focuses the exact settings field', async () => {
  await mount()
  expect(await form.focusField('phone')).toBe(true)
  expect(document.activeElement).toBe(host.querySelector('input'))
  expect(host.querySelector('[data-field-path="phone"]').classList.contains('hl')).toBe(true)
  expect(await form.focusField('secret')).toBe(false)
})
it('reveals a view-only field without focusing its edit control', async () => {
  await mount(true)
  expect(await form.focusField('phone')).toBe(true)
  expect(document.activeElement).not.toBe(host.querySelector('input'))
  expect(host.querySelector('[inert]')).toBeTruthy()
})
