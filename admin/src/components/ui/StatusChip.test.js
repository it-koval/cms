// @vitest-environment jsdom
import { createApp, ssrContextKey } from 'vue'
import { afterEach, expect, it } from 'vitest'
import { clientComponent } from '@/test/clientComponent.js'
import StatusChip from './StatusChip.vue'
import statusChipSource from './StatusChip.vue?raw'

clientComponent(StatusChip, statusChipSource)
let app
afterEach(() => app?.unmount())

it.each([
  { status: 'published', label: 'Опубликована', classes: ['chip', 'pub'], dot: true },
  { status: 'ai', label: 'ai', classes: ['chip'], dot: false },
])('renders $status with the current status map', ({ status, label, classes, dot }) => {
  const host = document.createElement('div')
  app = createApp(StatusChip, { status })
  app.provide(ssrContextKey, { modules: new Set() })
  app.mount(host)

  expect(host.firstElementChild.textContent.trim()).toBe(label)
  expect([...host.firstElementChild.classList]).toEqual(classes)
  expect(!!host.querySelector('.dot')).toBe(dot)
  expect(host.querySelector('svg')).toBeNull()
})
