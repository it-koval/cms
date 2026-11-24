// @vitest-environment jsdom
import { createApp, h, nextTick } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RequestsView from './RequestsView.vue'
import HealthView from './HealthView.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
import { toastState } from '@/components/ui/useToast.js'
import { initI18n } from '@/i18n/index.js'
import { vp } from '@/utils/viewport.js'

const mocks = vi.hoisted(() => ({ list: vi.fn(), get: vi.fn(), resend: vi.fn(), report: vi.fn(), healthResend: vi.fn() }))
vi.mock('@/api/index.js', () => ({ api: {
  requests: { list: mocks.list, get: mocks.get, resend: mocks.resend, forms: async () => [], counts: async () => ({ total: 1 }) },
  prefs: { get: async () => 'table' },
  health: { report: mocks.report, resendRequest: mocks.healthResend },
} }))
vi.mock('@/stores/app.js', () => ({ app: { languages: { list: [{ code: 'ru', name: 'Русский' }] } }, can: () => true, refreshCounters: async () => {} }))
vi.mock('@/schema/registry.js', () => ({ schema: { forms: [] }, byKey: () => null }))
vi.mock('@/components/ui/useConfirm.js', () => ({ useConfirm: () => vi.fn() }))

const request = { id: 41, form: 'order', place: 'Fixture form', phone: '+10000000000', dt: '2026-11-24T12:00:00Z', work: 'new', data: {}, comment: '', delivery: 'failed', st: 'failed', channels: [{ channel: 'telegram', state: 'failed', error: 'Fixture delivery failure' }], sendable: [], log: [] }
const report = { status: 'warn', generatedAt: request.dt, messenger: { queues: [], failed: { available: true, total: 0, items: [] } }, requests: { failed: 1, items: [{ id: 41, form: 'Fixture form', dt: request.dt, error: 'Fixture delivery failure', queuedChannels: [], canResend: true }] }, schedules: { available: true, items: [] }, disk: [], versions: { php: '8.4', symfony: '7.4', itk: '0.3.0' }, updates: { enabled: false }, checks: [] }
const locales = [['ru', 'Поставлено в очередь'], ['en', 'Queued for delivery'], ['kk', 'Кезекке қойылды']]
let app, host
const flush = async () => { for (let i = 0; i < 12; i++) { await Promise.resolve(); await nextTick() } }
async function mount(path) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/requests/:id?', name: 'requests', component: RequestsView }, { path: '/health', component: HealthView }] })
  await router.push(path)
  await router.isReady()
  host = document.createElement('div')
  document.body.append(host)
  app = createApp({ render: () => h('main', [h(RouterView), h(ToastHost)]) })
  app.use(router).mount(host)
  await flush()
  return router
}

beforeEach(() => {
  vi.clearAllMocks()
  toastState.items = []
  mocks.list.mockResolvedValue([structuredClone(request)])
  mocks.get.mockResolvedValue(structuredClone(request))
  mocks.resend.mockResolvedValue({ ...structuredClone(request), queuedChannels: ['telegram'] })
  mocks.report.mockResolvedValue(structuredClone(report))
  mocks.healthResend.mockResolvedValue({ delivery: 'failed', queuedChannels: ['telegram'] })
})
afterEach(async () => {
  app?.unmount()
  host?.remove()
  toastState.items = []
  document.body.classList.remove('ov-lock')
  await initI18n('ru')
})

// jsdom checks behavior for responsive branches; it cannot establish layout or real touch usability.
for (const width of [375, 320, 1440]) {
  describe(`queue flows at ${width}px responsive state`, () => {
    beforeEach(() => {
      Object.defineProperty(window, 'innerWidth', { configurable: true, value: width })
      Object.assign(vp, { phone: width < 768, compact: width < 1200, touch: width < 768 })
    })
    for (const [locale, queued] of locales) {
      it(`${locale}: list opens the card and queues delivery in two clicks`, async () => {
        await initI18n(locale)
        const router = await mount('/requests')
        expect(document.querySelector('.drawer')).toBeNull()
        host.querySelector('.rq-row').click()
        await flush()
        await vi.waitFor(() => expect(router.currentRoute.value.params.id).toBe('41'))
        await vi.waitFor(() => expect(document.querySelector('.rd-ch-b')).not.toBeNull())
        document.querySelector('.rd-ch-b').click()
        await flush()
        expect(mocks.resend).toHaveBeenCalledExactlyOnceWith(41, { channel: 'telegram' })
        expect(document.querySelector('.drawer [role="status"]').textContent).toBe(queued)
        expect(document.querySelector('.toast').textContent).toBe(queued)
        expect(document.querySelector('.toast.err-t')).toBeNull()
        expect(document.querySelector('.rd-ch-b')).toBeNull()
      })
      it(`${locale}: an open card queues in one click`, async () => {
        await initI18n(locale)
        await mount('/requests/41')
        document.querySelector('.rd-ch-b').click()
        await flush()
        expect(mocks.resend).toHaveBeenCalledExactlyOnceWith(41, { channel: 'telegram' })
        expect(document.querySelector('.drawer [role="status"]').textContent).toBe(queued)
      })
      it(`${locale}: the health queue action acknowledges acceptance in one click`, async () => {
        const queuedReport = structuredClone(report)
        Object.assign(queuedReport.requests.items[0], { queuedChannels: ['telegram'], canResend: false })
        mocks.report.mockResolvedValueOnce(structuredClone(report)).mockResolvedValue(queuedReport)
        await initI18n(locale)
        await mount('/health')
        const button = host.querySelector('[aria-labelledby="hl-req"] button')
        expect(button).not.toBeNull()
        button.click()
        await flush()
        expect(mocks.healthResend).toHaveBeenCalledExactlyOnceWith(41)
        expect(host.querySelector('[aria-labelledby="hl-req"] [role="status"]').textContent).toBe(queued)
        expect(host.querySelector('.toast').textContent).toBe(queued)
        expect(host.querySelector('.toast.err-t')).toBeNull()
        expect(host.querySelector('[aria-labelledby="hl-req"] button')).toBeNull()
      })
      it(`${locale}: a final failure restores the health action after refresh without leaving the page`, async () => {
        await initI18n(locale)
        const queuedReport = structuredClone(report)
        Object.assign(queuedReport.requests.items[0], { queuedChannels: ['telegram'], canResend: false })
        mocks.report.mockResolvedValueOnce(queuedReport).mockResolvedValue(structuredClone(report))
        const router = await mount('/health')
        expect(host.querySelector('[aria-labelledby="hl-req"] [role="status"]').textContent).toBe(queued)
        expect(host.querySelector('[aria-labelledby="hl-req"] button')).toBeNull()
        host.querySelector('.page-h .actions button').click()
        await flush()
        expect(router.currentRoute.value.path).toBe('/health')
        expect(host.querySelector('[aria-labelledby="hl-req"] [role="status"]')).toBeNull()
        expect(host.querySelector('[aria-labelledby="hl-req"] button')).not.toBeNull()
        expect(mocks.healthResend).not.toHaveBeenCalled()
      })
    }
  })
}
