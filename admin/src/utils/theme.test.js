import { afterEach, describe, expect, it } from 'vitest'
import { PRODUCT, themeLogo, themeName } from './theme.js'

const setTheme = (theme) => { globalThis.window = { ITK_ADMIN: theme === undefined ? {} : { theme } } }
afterEach(() => { delete globalThis.window })

describe('themeName', () => {
  it('shows the name from the developer config', () => {
    setTheme({ name: 'Студия «Ромашка»' })
    expect(themeName()).toBe('Студия «Ромашка»')
  })

  it('keeps the built-in name when the config has none (or while the scheme is unknown, npm run dev)', () => {
    for (const theme of [{ name: null }, { name: '' }, { name: '   ' }, {}, undefined]) {
      setTheme(theme)
      expect(themeName()).toBe(PRODUCT)
    }
    expect(PRODUCT).toBe('ITK CMS')
  })
})

describe('themeLogo', () => {
  it('is empty without a logo', () => {
    setTheme({ logo: null })
    expect(themeLogo()).toBe('')
    setTheme({ logo: '/build/logo.svg' })
    expect(themeLogo()).toBe('/build/logo.svg')
  })
})
