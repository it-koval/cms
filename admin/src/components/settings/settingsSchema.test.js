import { describe, expect, it } from 'vitest'
import { resolveTab } from './settingsSchema.js'

describe('resolveTab', () => {
  it('opens an item by its key, and none for an empty or unknown key', () => {
    expect(resolveTab('menus')).toEqual({ item: 'menus' })
    expect(resolveTab('')).toBe(null)
    expect(resolveTab('nope')).toBe(null)
  })

  it('«Пользователи и роли» has two segments', () => {
    expect(resolveTab('users')).toEqual({ item: 'users', segment: 'users' })
    expect(resolveTab('roles')).toEqual({ item: 'users', segment: 'roles' })
  })

  it('«SEO» has two segments: the rules and robots.txt / llms.txt', () => {
    expect(resolveTab('seo')).toEqual({ item: 'seo', segment: 'rules' })
    expect(resolveTab('seo-files')).toEqual({ item: 'seo', segment: 'files' })
  })
})
