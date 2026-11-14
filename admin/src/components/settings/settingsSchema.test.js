import { beforeEach, describe, expect, it } from 'vitest'
import { loadSchema } from '@/schema/registry.js'
import { itemOfKey, maxChatIdsError, NAV, needsFallbackChat, resolveTab, settingsAreas, settingsErrors } from './settingsSchema.js'

describe('resolveTab', () => {
  it('opens an item by its key, and none for an empty or unknown key', () => {
    expect(resolveTab('menus')).toEqual({ item: 'menus' })
    expect(resolveTab('')).toBe(null)
    expect(resolveTab('nope')).toBe(null)
  })

  it('routes bots through its own settings permission', () => {
    expect(resolveTab('bots')).toEqual({ item: 'bots' })
    expect(NAV.flatMap((group) => group.items).find((item) => item.key === 'bots').area).toBe('set:bots')
    expect(settingsAreas()).toContain('set:bots')
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

describe('MAX chat ids', () => {
  it('accepts one id with an optional minus, or several with commas, and an empty value', () => {
    for (const v of ['', '-70000000000001', '123', '-1, 22,333', '0', '-0', '9223372036854775807', '-9223372036854775808']) expect(maxChatIdsError(v)).toBe('')
  })

  it('refuses a name, a letter, a lone minus or an id of more than 20 digits', () => {
    for (const v of ['@my_channel', '12a', '-', '123456789012345678901', '1;2', '01', '-01', ',', '12345678901234567890', '9223372036854775808', '-9223372036854775809']) expect(maxChatIdsError(v)).not.toBe('')
  })
})

describe('settingsErrors of the MAX table', () => {
  beforeEach(() => loadSchema({ forms: [{ key: 'callback', title: 'A' }, { key: 'order', title: 'B' }] }))

  it('never blocks saving when the chat for the other forms is empty: the table only warns', () => {
    expect(settingsErrors({ maxOn: true, maxReady: true, maxRoutes: {} }).maxRoutes).toBeUndefined()
    expect(settingsErrors({ maxOn: true, maxReady: true, maxRoutes: { '*': '' } }, { maxRoutes: { '*': '' } }).maxRoutes).toBeUndefined()
    expect(settingsErrors({ tgOn: true, tgRoutes: {} }, { tgRoutes: {} }).tgRoutes).toBeUndefined()
  })

  it('tells the table when forms are left without any chat', () => {
    const forms = [{ key: 'callback' }, { key: 'order' }]
    expect(needsFallbackChat({}, forms, false)).toBe(true)
    expect(needsFallbackChat({}, forms, true)).toBe(false)
    expect(needsFallbackChat({ callback: '1', order: '2' }, forms, false)).toBe(false)
    expect(needsFallbackChat({ '*': '-5' }, forms, false)).toBe(false)
  })

  it('asks nothing when MAX is off or not connected', () => {
    expect(settingsErrors({ maxOn: false, maxReady: true, maxRoutes: {} }).maxRoutes).toBeUndefined()
    expect(settingsErrors({ maxOn: true, maxReady: false, maxRoutes: {} }).maxRoutes).toBeUndefined()
  })

  it('points at a malformed id while MAX is on', () => {
    expect(settingsErrors({ maxOn: true, maxReady: true, maxRoutes: { '*': '-5', callback: 'abc' } }).maxRoutes).toBeTruthy()
    expect(settingsErrors({ maxOn: false, maxRoutes: { '*': 'abc' } }).maxRoutes).toBeUndefined()
  })

  it('validates routes only for the channel being saved', () => {
    const settings = { tgOn: true, tgRoutes: { '*': 'bad' }, maxOn: true, maxReady: true, maxRoutes: { '*': 'abc' } }
    expect(settingsErrors(settings, { phone: '123' })).toEqual({})
    expect(Object.keys(settingsErrors(settings, { tgRoutes: settings.tgRoutes }))).toEqual(['tgRoutes'])
    expect(Object.keys(settingsErrors(settings, { maxRoutes: settings.maxRoutes }))).toEqual(['maxRoutes'])
  })

  it('validates changed Telegram IDs even when disconnected or off', () => {
    const legacy = { tgOn: true, tgRoutes: {} }
    const malformed = { ...legacy, tgReady: false, tgRoutes: { '*': 'bad' } }
    for (const tgOn of [true, false]) expect(settingsErrors({ ...malformed, tgOn }, { tgRoutes: malformed.tgRoutes }).tgRoutes).toBeTruthy()
  })

  it('needs at least one actual recipient', () => {
    expect(settingsErrors({ maxOn: true, maxReady: true, maxRoutes: { '*': ',' } }).maxRoutes).toBeTruthy()
  })

  it('shows the error on the notifications item', () => {
    expect(itemOfKey('maxRoutes')).toBe('notifications')
    expect(itemOfKey('maxOn')).toBe('notifications')
  })
})
