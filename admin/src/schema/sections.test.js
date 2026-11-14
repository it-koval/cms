import { afterEach, describe, expect, it } from 'vitest'
import { initI18n } from '@/i18n/index.js'
import { FIRST_SCREEN, groupLabel } from './sections.js'

afterEach(async () => { await initI18n('ru') })

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
