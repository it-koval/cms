// @vitest-environment jsdom
import { createApp, h, nextTick, ref, ssrContextKey } from 'vue'
import { afterEach, expect, it } from 'vitest'
import FieldShell from './FieldShell.vue'
import shellSource from './FieldShell.vue?raw'
import TextField from './fields/TextField.vue'
import textSource from './fields/TextField.vue?raw'
import NumberField from './fields/NumberField.vue'
import numberSource from './fields/NumberField.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
clientComponent(FieldShell, shellSource); clientComponent(TextField, textSource); clientComponent(NumberField, numberSource)
let mounted
const tick = async () => { await nextTick(); await nextTick() }
afterEach(() => { mounted?.unmount(); document.body.innerHTML = '' })
it.each([TextField, NumberField])('connects the focused control with its changing field error and help', async (Field) => {
  const error = ref('Please enter a value')
  const host = document.createElement('div'); document.body.append(host)
  mounted = createApp({ render: () => h(Field, { field: { k: 'title', label: 'Title', help: 'A useful hint' }, path: 'title', error: error.value }) })
  mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host); await tick()
  const input = host.querySelector('input'); input.focus()
  expect(document.activeElement).toBe(input)
  expect(input.getAttribute('aria-invalid')).toBe('true')
  const descriptions = input.getAttribute('aria-describedby').split(' ').map((id) => document.getElementById(id)?.textContent)
  expect(descriptions).toContain('A useful hint'); expect(descriptions).toContain('Please enter a value')
  error.value = ''; await tick()
  expect(input.hasAttribute('aria-invalid')).toBe(false)
  expect(input.getAttribute('aria-describedby')).toBe('f_title_h')
})
