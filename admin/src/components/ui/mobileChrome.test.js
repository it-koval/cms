// @vitest-environment jsdom
import { compile, createApp, h, ssrContextKey } from 'vue'
import { compileScript, parse } from '@vue/compiler-sfc'
import { afterEach, expect, it, vi } from 'vitest'
import VersionChoice from './VersionChoice.vue'
import versionSource from './VersionChoice.vue?raw'
import LanguageSelect from './LanguageSelect.vue'
import languageSource from './LanguageSelect.vue?raw'
import LangChip from './LangChip.vue'
import chipSource from './LangChip.vue?raw'

vi.mock('./Popover.vue', () => ({ default: { render: () => null } }))
vi.mock('./Icon.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], render() { return h('a', { href: this.to }, this.$slots.default?.()) } } }))

const styles = []
for (const [component, source] of [[VersionChoice, versionSource], [LanguageSelect, languageSource], [LangChip, chipSource]]) {
  const { descriptor } = parse(source)
  const { bindings } = compileScript(descriptor, { id: 'mobile-chrome-test' })
  component.render = compile(descriptor.template.content, { bindingMetadata: bindings, prefixIdentifiers: true })
  styles.push(...descriptor.styles.map((s) => s.content))
}
let mounted, host, style
function mount(component, props) {
  style = document.createElement('style')
  style.textContent = ':root { --mag: rgb(200, 0, 100); }' + styles.join('\n')
  document.head.append(style)
  // jsdom does not provide viewport layout. Apply the stylesheet's mobile rules;
  // full dimensions and desktop media queries are verified in Chromium acceptance.
  for (let i = style.sheet.cssRules.length - 1; i >= 0; i--) {
    const rule = style.sheet.cssRules[i]
    if (rule.type !== 4) continue
    const min = /min-width:\s*(\d+)px/.exec(rule.conditionText)
    if (min && Number(min[1]) > 375) style.sheet.deleteRule(i)
  }
  host = document.createElement('div')
  document.body.append(host)
  mounted = createApp({ render: () => h(component, props) })
  mounted.provide(ssrContextKey, { modules: new Set() })
  mounted.mount(host)
}
afterEach(() => { mounted?.unmount(); host?.remove(); style?.remove() })

it('stacks missing-version actions in the mobile default and wraps a continuous address', () => {
  const address = '/tr/' + 'w'.repeat(240)
  mount(VersionChoice, { title: 'Создать перевод', options: [{ id: 'copy', label: 'Скопировать', hint: address }, { id: 'blank', label: 'Пустая версия', hint: 'Значения по умолчанию' }] })
  expect(getComputedStyle(host.querySelector('.vc-opts')).gridTemplateColumns).toMatch(/^minmax\(0(?:px)?, 1fr\)$/)
  expect(getComputedStyle(host.querySelector('.vc-o small')).overflowWrap).toBe('anywhere')
  expect(host.textContent).toContain(address)
})

it('uses only the short code in the mobile editor language trigger with the full name available to assistive technology', () => {
  mount(LanguageSelect, { variant: 'editor', modelValue: 'tr', label: 'Язык страницы', languages: [{ code: 'ru', name: 'Русский' }, { code: 'tr', name: 'Türkçe' }], statuses: { tr: 'draft' } })
  expect(getComputedStyle(host.querySelector('.ls-btn .ls-nm')).display).toBe('none')
  expect(host.querySelector('.ls-btn').getAttribute('aria-label')).toContain('Türkçe')
  expect(host.querySelector('.ls-tag').textContent).toBe('TR')
  expect(getComputedStyle(host.querySelector('.ls-btn')).height).toBe('44px')
})

it('distinguishes a draft by its outlined marker even when the status text is compact', () => {
  mount(LangChip, { code: 'tr', state: 'draft', word: 'Черновик', compact: true, to: '/pages/one?lang=tr' })
  const dot = getComputedStyle(host.querySelector('.lc-dot'))
  expect(dot.backgroundColor).toBe('rgba(0, 0, 0, 0)')
  expect(dot.borderStyle).toBe('solid')
  expect(getComputedStyle(host.querySelector('a')).minWidth).toBe('44px')
  expect(getComputedStyle(host.querySelector('a')).minHeight).toBe('44px')
})
