// @vitest-environment jsdom
import { compile, createApp, h } from 'vue'
import { parse } from '@vue/compiler-sfc'
import { afterEach, expect, it, vi } from 'vitest'
import editorSource from './EditorTopBar.vue?raw'
import pagesSource from '@/views/PagesView.vue?raw'

let mounted, host
function mount(template, data) {
  const component = { setup: () => data, render: compile(template) }
  mounted = createApp(component)
  mounted.component('Icon', { render: () => null })
  for (const name of ['Popover', 'PublishPopover', 'HistoryDrawer', 'StatusChip']) mounted.component(name, { render: () => null })
  mounted.component('PageLangSelect', { render: () => h('button', { class: 'language-stub' }, 'TR') })
  mounted.component('RouterLink', { props: ['to'], render() { return h('a', { href: this.to }, this.$slots.default?.()) } })
  host = document.createElement('div')
  mounted.mount(host)
}
afterEach(() => mounted?.unmount())

it('keeps the mobile page name, language and publish on the first row, with secondary actions on the toolbar row', () => {
  const template = parse(editorSource).descriptor.template.content
  mount(template, {
    multi: true, ed: { page: {}, pageId: 'p1', pageTitle: 'Название страницы', changes: [{}], save: 'saved', readOnly: false, locale: 'tr', pane: 'form', canUndo: true, canRedo: false },
    vp: { phone: true }, backTo: '/pages', backLabel: 'Страницы', t: (key) => key, pgOpen: false, subText: 'Черновик', n: 1, submitMode: false, pubText: 'Опубликовать', moreOpen: false,
    pubOpen: false, histOpen: false, pgBtn: null, moreBtn: null, pubBtn: null, onRestored: vi.fn(), pluginButtons: [], ui: { setPane: vi.fn(), step: vi.fn() }, openPublish: vi.fn(), cap: (v) => v,
  })
  expect(host.querySelector('.ed-l .pg-t').textContent).toContain('Название страницы')
  expect(host.querySelector('.ed-r .btn.primary').textContent).toContain('Опубликовать')
  expect(host.querySelector('.ed-r .btn.icon')).toBeNull()
  expect(host.querySelector('.ed-sub .btn.icon')).not.toBeNull()
})

it('keeps mobile page guidance short while retaining the complete desktop guidance', () => {
  const template = parse(pagesSource).descriptor.template.content
  const header = template.slice(template.indexOf('<div class="page-h">'), template.indexOf('\n\n    <div v-if="loadError"'))
  mount(header, {
    flatT: null, loaded: true, loadError: '', multi: true, subtitle: 'Длинное описание со счётчиком и подробной инструкцией переключения языков.', namesNote: () => 'Названия — на русском.',
    t: (key) => key, canNew: true, canArrange: true, arrange: false, openNew: vi.fn(),
  })
  expect(host.querySelector('.page-intro-compact')?.textContent).toBe('Названия — на русском.')
  expect(host.querySelector('.page-intro-full')?.textContent).toContain('подробной инструкцией')
  expect(host.querySelector('.btn.primary')).not.toBeNull()
})
