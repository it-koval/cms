// @vitest-environment jsdom
import { createApp, h, nextTick, ssrContextKey } from 'vue'
import { afterEach, expect, it, vi } from 'vitest'
import MediaView from './MediaView.vue'
import source from './MediaView.vue?raw'
import { readFileSync } from 'node:fs'
const styles = readFileSync('src/styles/views.css', 'utf8')
import { clientComponent } from '@/test/clientComponent.js'
const names = ['A readable photo caption with several words.jpg', 'a'.repeat(180) + '.jpg']
vi.mock('@/stores/app.js', () => ({ can: () => false }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('@/utils/viewport.js', () => ({ vp: { phone: true, compact: true, touch: true } }))
vi.mock('@/api/index.js', () => ({ api: { media: {
  list: async () => ({ items: names.map((name, id) => ({ id: String(id), name, type: 'pdf', kb: 1 })), total: 2, page: 1, perPage: 50 }),
  folders: async () => ({ folders: [], total: 2, unfiled: 2 }),
} } }))
vi.mock('@/components/media/FolderTree.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/media/MediaDrawer.vue', () => ({ default: { render: () => null } }))
clientComponent(MediaView, source)
let mounted
const tick = async () => { for (let i = 0; i < 5; i++) { await Promise.resolve(); await nextTick() } }
afterEach(() => { mounted?.unmount(); document.body.innerHTML = ''; document.head.querySelectorAll('[data-caption-test]').forEach((el) => el.remove()) })
it.each([375, 320])('keeps complete worded and unbroken media captions with emergency wrapping at %ipx', async (width) => {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: width })
  const style = document.createElement('style'); style.dataset.captionTest = ''; style.textContent = styles; document.head.append(style)
  const host = document.createElement('div'); host.style.width = width + 'px'; document.body.append(host)
  mounted = createApp({ render: () => h(MediaView) }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host); await tick()
  const captions = [...host.querySelectorAll('.mdi .nm')]
  expect(captions).toHaveLength(2)
  captions.forEach((caption, index) => {
    expect(caption.firstChild.textContent).toBe(names[index])
    const look = getComputedStyle(caption)
    expect(look.overflowWrap).toBe('anywhere')
    expect(look.maxWidth).toBe('100%')
    expect(look.wordBreak).not.toBe('break-all')
    expect(look.whiteSpace).not.toBe('nowrap')
  })
  // Discrimination check against the former declaration, without changing application files.
  const former = document.createElement('style'); former.dataset.captionTest = ''; former.textContent = '.mdi .nm{word-break:break-all}'; document.head.append(former)
  expect(getComputedStyle(captions[0]).wordBreak).toBe('break-all')
})
