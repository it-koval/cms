// @vitest-environment jsdom
import { createApp, h, nextTick, ref } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import PhotosField from './PhotosField.vue'

vi.mock('../MediaPickerDialog.vue', () => ({ default: { render: () => null } }))
let app, host, value
async function mount(initial) {
  value = ref(initial)
  host = document.createElement('div')
  document.body.appendChild(host)
  app = createApp({ render: () => h(PhotosField, { field: { k: 'photos', label: 'Фото' }, path: 'photos', modelValue: value.value, 'onUpdate:modelValue': (v) => { value.value = v } }) })
  app.mount(host)
  await nextTick()
}
afterEach(() => { app?.unmount(); host?.remove() })

it('edits each alt independently and keeps it with its photo after moving and remounting', async () => {
  await mount(['/a.jpg', { src: '/b.jpg', alt: 'Б', caption: 'Keep' }])
  const inputs = host.querySelectorAll('input[type=text]')
  expect(inputs).toHaveLength(2)
  inputs[0].value = 'А'
  inputs[0].dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
  inputs[1].value = 'Б новое'
  inputs[1].dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
  host.querySelectorAll('[data-photo-down]')[0].click()
  await nextTick()
  expect(value.value).toEqual([{ src: '/b.jpg', alt: 'Б новое', caption: 'Keep' }, { src: '/a.jpg', alt: 'А' }])
  const saved = JSON.parse(JSON.stringify(value.value))
  app.unmount(); host.remove()
  await mount(saved)
  expect([...host.querySelectorAll('input[type=text]')].map((input) => input.value)).toEqual(['Б новое', 'А'])
})

it('leaves legacy string values unchanged until their alt is edited', async () => {
  const initial = ['/a.jpg', { src: '/b.jpg', alt: '' }]
  await mount(initial)
  expect(value.value).toEqual(initial)
  expect(host.querySelectorAll('label[for]').length).toBeGreaterThanOrEqual(2)
})
