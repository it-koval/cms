import { afterEach, describe, expect, it } from 'vitest'
import { initI18n } from '@/i18n/index.js'
import { FIRST_SCREEN, groupLabel, pageH1Id, hasPageH1 } from './sections.js'
import { loadSchema } from './registry.js'

afterEach(async () => { loadSchema(); await initI18n('ru') })

describe('page H1', () => {
  it('uses the declared first-screen flag independently of group labels', () => {
    loadSchema({ sections: [
      { key: 'off', group: FIRST_SCREEN, firstScreen: false },
      { key: 'en', group: 'First screen', firstScreen: true },
      { key: 'ru', group: FIRST_SCREEN, firstScreen: true },
    ] })
    const sections = [{ id: 'off', type: 'off' }, { id: 'hidden', type: 'en', hidden: true }, { id: 'en', type: 'en' }, { id: 'ru', type: 'ru' }]
    expect(pageH1Id(sections)).toBe('en')
    expect(hasPageH1(sections)).toBe(true)
    expect(hasPageH1([sections[0], sections[1]])).toBe(false)
    expect(pageH1Id(sections, { h1: { field: 'title' } })).toBeNull()
    expect(hasPageH1([], { h1: { field: 'title' } })).toBe(true)
  })

  it('keeps the legacy Russian group fallback when a schema has no flag', () => {
    loadSchema({ sections: [{ key: 'legacy', group: FIRST_SCREEN }] })
    expect(pageH1Id([{ id: 'legacy', type: 'legacy' }])).toBe('legacy')
  })
})

describe('groupLabel', () => {
  it('labels the first-screen group in the language of the screen and leaves the groups of a site as they are', async () => {
    expect(groupLabel(FIRST_SCREEN)).toBe('Первый экран')
    await initI18n('en')
    expect(groupLabel(FIRST_SCREEN)).toBe('Hero')
    await initI18n('kk')
    expect(groupLabel(FIRST_SCREEN)).toBe('Бірінші экран')
    expect(groupLabel('Каталог')).toBe('Каталог')
  })
})
