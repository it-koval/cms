// @vitest-environment jsdom
import { createApp, h, nextTick, ref, ssrContextKey } from 'vue'
import { afterEach, expect, it } from 'vitest'
import Address from './AddressField.vue'
import source from './AddressField.vue?raw'
import Shell from '@/components/form/FieldShell.vue'
import shellSource from '@/components/form/FieldShell.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
import { setLanguages } from '@/utils/locales.js'
clientComponent(Address, source); clientComponent(Shell, shellSource)
let mounted
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
it.each(['url', 'slug'])('links the translated %s address error and help to the focused control', async (mode) => {
  setLanguages({ main: 'ru', list: [{ code: 'ru' }, { code: 'en' }] })
  const error = ref('Enter the address')
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(Address, { field: { label: 'Address', help: 'Use Latin letters' }, path: 'url', locale: 'en', mode, error: error.value }) })
  mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host); await nextTick()
  const control = host.querySelector('input'); control.focus()
  expect(document.activeElement).toBe(control)
  expect(control.getAttribute('aria-invalid')).toBe('true')
  const ids = control.getAttribute('aria-describedby').split(' ')
  expect(ids.map((id) => document.getElementById(id)?.textContent)).toEqual(['Use Latin letters', 'Enter the address'])
  error.value = ''; await nextTick()
  expect(control.hasAttribute('aria-invalid')).toBe(false)
  expect(control.getAttribute('aria-describedby')).toBe('adr-url_h')
})
