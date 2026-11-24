// @vitest-environment jsdom
import { createApp, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RequestDrawer from './RequestDrawer.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn(), resend: vi.fn(), update: vi.fn(), show: vi.fn(), error: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: { requests: { get: mocks.get, resend: mocks.resend, update: mocks.update } } }))
vi.mock('@/stores/app.js', () => ({ can: () => true }))
vi.mock('@/schema/registry.js', () => ({ schema: { forms: [] }, byKey: () => null }))
vi.mock('@/components/ui/useToast.js', () => ({ useToast: () => ({ show: mocks.show, error: mocks.error }) }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => vi.fn() }))
vi.mock('vue-router', () => ({ onBeforeRouteLeave: vi.fn(), onBeforeRouteUpdate: vi.fn() }))
vi.mock('@/components/ui/Drawer.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const request = { id: 41, form: 'order', dt: '2026-11-24T12:00:00Z', work: 'new', data: {}, comment: '', delivery: 'failed', st: 'failed', channels: [{ channel: 'telegram', state: 'failed', error: 'earlier failure' }], sendable: [], log: [] }
let app, host
const flush = async () => { await Promise.resolve(); await Promise.resolve(); await nextTick() }
async function mount() {
  host = document.createElement('div')
  document.body.append(host)
  app = createApp({ render: () => h(RequestDrawer, { requestId: request.id }) })
  app.mount(host)
  await flush()
}

beforeEach(() => {
  vi.clearAllMocks()
  mocks.get.mockResolvedValue(structuredClone(request))
})
afterEach(() => { app?.unmount(); host?.remove() })

describe('request card delivery actions', () => {
  it('places the retry action before a long form answer', async () => {
    mocks.get.mockResolvedValue({ ...structuredClone(request), note: 'Long answer '.repeat(8000) })
    await mount()
    const button = host.querySelector('.rd-ch-b')
    const details = host.querySelector('.rd-details')
    expect(button.compareDocumentPosition(details) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('queues with one click, blocks a second click while waiting and shows the accepted result', async () => {
    let finish
    mocks.resend.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    await mount()
    const button = host.querySelector('.rd-ch-b')
    button.click()
    await flush()
    expect(button.disabled).toBe(true)
    button.click()
    expect(mocks.resend).toHaveBeenCalledExactlyOnceWith(41, { channel: 'telegram' })
    expect(host.textContent).not.toContain('Поставлено в очередь')
    finish({ ...structuredClone(request), queuedChannels: ['telegram'] })
    await flush()
    expect(mocks.show).toHaveBeenCalledExactlyOnceWith('Поставлено в очередь')
    expect(mocks.error).not.toHaveBeenCalled()
    expect(host.querySelector('[role="status"]').textContent).toBe('Поставлено в очередь')
    expect(host.querySelector('.rd-ch-b')).toBeNull()
    expect(host.textContent).not.toContain('Заявка № 41 доставлена')
  })

  it('preserves the retry action and reports the error when dispatch is rejected', async () => {
    mocks.resend.mockRejectedValue(new Error('Очередь временно недоступна'))
    await mount()
    host.querySelector('.rd-ch-b').click()
    await flush()
    expect(mocks.error).toHaveBeenCalledExactlyOnceWith('Очередь временно недоступна')
    expect(mocks.show).not.toHaveBeenCalled()
    expect(host.textContent).not.toContain('Поставлено в очередь')
    expect(host.querySelector('.rd-ch-b').disabled).toBe(false)
  })
})
