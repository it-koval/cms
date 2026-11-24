// @vitest-environment jsdom
import { createApp, h } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import PreviewFrame from './PreviewFrame.vue'

let app, host
afterEach(() => { app?.unmount(); host?.remove() })

it('forwards row identities only from its own same-origin preview frame', () => {
  const focus = vi.fn()
  host = document.createElement('div')
  document.body.appendChild(host)
  app = createApp({ render: () => h(PreviewFrame, { pageId: 'page', locale: 'kk', onFocus: focus }) })
  app.mount(host)
  const frame = host.querySelector('iframe')
  const data = { type: 'itk:focus', sec: '@collection/team/one', path: 'faq.0.title', rows: ['row-A'] }
  window.dispatchEvent(new MessageEvent('message', { source: frame.contentWindow, origin: window.location.origin, data }))
  expect(focus).toHaveBeenCalledWith({ sec: '@collection/team/one', path: 'faq.0.title', rows: ['row-A'] })
  window.dispatchEvent(new MessageEvent('message', { source: window, origin: window.location.origin, data }))
  window.dispatchEvent(new MessageEvent('message', { source: frame.contentWindow, origin: 'https://unrelated.invalid', data }))
  expect(focus).toHaveBeenCalledTimes(1)
})
