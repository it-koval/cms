import { h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// the editor itself needs a browser; here only the way it is loaded matters
vi.mock('./RichText.vue', () => ({ default: { props: ['modelValue'], render() { return h('div', { class: 'rt' }, this.modelValue) } } }))

describe('RichTextAsync: the editor is loaded once and drawn as soon as it is there', () => {
  beforeEach(() => vi.resetModules())
  const load = () => import('./RichTextAsync.js')

  it('draws nothing until the editor has arrived', async () => {
    const { default: RichText } = await load()
    expect(await renderToString(h(RichText, { modelValue: 'x' }))).not.toContain('class="rt"')
  })

  it('after the preload a new field gets the editor in its first render, without waiting a tick', async () => {
    const { default: RichText, preloadRichText } = await load()
    await preloadRichText()
    expect(await renderToString(h(RichText, { modelValue: 'текст' }))).toContain('текст')
  })

  it('asks for the chunk once', async () => {
    const { preloadRichText } = await load()
    expect(preloadRichText()).toBe(preloadRichText())
  })

  it('tries again after a failed load', async () => {
    vi.doMock('./RichText.vue', () => { throw new Error('нет сети') })
    const { preloadRichText } = await load()
    const failed = preloadRichText()
    await expect(failed).rejects.toThrow()
    vi.doUnmock('./RichText.vue')
    vi.doMock('./RichText.vue', () => ({ default: { render: () => h('div', { class: 'rt' }) } }))
    expect(preloadRichText()).not.toBe(failed)
    await expect(preloadRichText()).resolves.toBeUndefined()
  })
})
