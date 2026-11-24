// @vitest-environment jsdom
import { compile, createApp, h, ssrContextKey } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import PageChips from './LangChips.vue'
import pageSource from './LangChips.vue?raw'
import CollectionChips from '@/components/collections/LangChips.vue'
import collectionSource from '@/components/collections/LangChips.vue?raw'
import LangChip from '@/components/ui/LangChip.vue'
import chipSource from '@/components/ui/LangChip.vue?raw'
import { setLanguages } from '@/utils/locales.js'

const mocks = vi.hoisted(() => ({ push: vi.fn(), app: { locale: 'ru', languages: { main: 'ru', list: ['ru', 'kk', 'tr', 'zh', 'en'].map((code) => ({ code, name: code })) } } }))
vi.mock('@/stores/app.js', () => ({ app: mocks.app }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mocks.push }),
  RouterLink: { props: ['to'], render() { return h('a', { href: this.to }, this.$slots.default?.()) } },
}))
for (const [component, source] of [[PageChips, pageSource], [CollectionChips, collectionSource], [LangChip, chipSource]]) {
  const { descriptor } = parse(source)
  const { bindings } = compileScript(descriptor, { id: 'language-chip-test' })
  component.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })
}
let mounted, host
const row = { id: 'one', title: 'Название', locales: { ru: { status: 'published' }, kk: null, tr: { status: 'draft' }, zh: null, en: { status: 'published', changed: true, changes: 2 } } }
function mount(component, props) {
  host = document.createElement('div')
  mounted = createApp({ render: () => h(component, props) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(host)
}
beforeEach(() => { vi.clearAllMocks(); setLanguages(mocks.app.languages) })
afterEach(() => mounted?.unmount())

it('gives every page language a direct version link and an accessible missing status', () => {
  mount(PageChips, { page: row, to: '/pages/one' })
  const links = [...host.querySelectorAll('a')]
  expect(links.map((a) => a.getAttribute('href'))).toEqual(['ru', 'kk', 'tr', 'zh', 'en'].map((code) => `/pages/one?lang=${code}`))
  expect(links[1].classList.contains('missing')).toBe(true)
  expect(links[1].getAttribute('aria-label')).toBeTruthy()
  expect(host.querySelector('select')).toBeNull()
})

it('keeps every collection version one click away, including on the narrow layout', () => {
  mount(CollectionChips, { cfg: { key: 'team', titleField: 'title', tabs: [] }, row })
  expect([...host.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual(['ru', 'kk', 'tr', 'zh', 'en'].map((code) => `/collections/team/one?lang=${code}`))
  expect(host.querySelector('select')).toBeNull()
})

it('keeps collection version statuses readable without navigation controls during card reordering', () => {
  mount(CollectionChips, { cfg: { key: 'team', titleField: 'title', tabs: [] }, row, interactive: false })
  expect(host.querySelectorAll('a,select,button')).toHaveLength(0)
  expect(host.querySelectorAll('.lc')).toHaveLength(5)
  expect(host.textContent).toContain('RU')
  expect(host.querySelector('.lc.missing')).not.toBeNull()
})
