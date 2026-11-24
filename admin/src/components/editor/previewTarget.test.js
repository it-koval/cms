import { beforeEach, expect, it, vi } from 'vitest'
import { previewDestination } from './previewTarget.js'

const mocks = vi.hoisted(() => ({ can: vi.fn(), collection: vi.fn(), fields: vi.fn() }))
vi.mock('@/stores/app.js', () => ({ can: (...args) => mocks.can(...args) }))
vi.mock('@/schema/collections.js', () => ({ getCollectionConfig: (...args) => mocks.collection(...args) }))
vi.mock('@/schema/settings.js', () => ({ getSettingsFields: () => mocks.fields() }))
vi.mock('@/components/settings/settingsSchema.js', () => ({ groupOfKey: () => 'contact' }))
beforeEach(() => {
  mocks.can.mockReturnValue(true)
  mocks.collection.mockReturnValue({ key: 'team', tabs: [{ fields: [{ k: 'title' }, { k: 'people', t: 'repeater', sub: [{ k: 'name' }] }] }] })
  mocks.fields.mockReturnValue([{ k: 'phone' }])
})
it.each(['ru', 'kk'])('opens the exact record or settings field in %s', (locale) => {
  expect(previewDestination({ sec: '@collection/team/record-1', path: 'people.2.name' }, locale)).toEqual({ name: 'collection-item', params: { col: 'team', id: 'record-1' }, query: { lang: locale, field: 'people.2.name' } })
  expect(previewDestination({ sec: '@settings', path: 'phone' }, locale)).toEqual({ name: 'settings', params: { tab: 'general' }, query: { lang: locale, field: 'phone' } })
})
it('keeps uppercase letters in registered collection keys', () => {
  expect(previewDestination({ sec: '@collection/Team/record-1', path: 'title' }, 'ru')?.params).toEqual({ col: 'Team', id: 'record-1' })
  expect(mocks.collection).toHaveBeenCalledWith('Team')
})
it('accepts view-only access and refuses invisible, hidden or unknown fields and malformed owners', () => {
  mocks.can.mockImplementation((area, action) => action === 'view')
  expect(previewDestination({ sec: '@settings', path: 'phone' }, 'kk')).toBeTruthy()
  expect(previewDestination({ sec: '@settings', path: 'password' }, 'kk')).toBeNull()
  expect(previewDestination({ sec: '@collection/team/one/extra', path: 'title' }, 'kk')).toBeNull()
  expect(previewDestination({ sec: '@collection/team/one', path: 'people.name' }, 'kk')).toBeNull()
  mocks.fields.mockReturnValue([{ k: 'phone', form: false }])
  expect(previewDestination({ sec: '@settings', path: 'phone' }, 'kk')).toBeNull()
  mocks.can.mockReturnValue(false)
  expect(previewDestination({ sec: '@collection/team/one', path: 'title' }, 'kk')).toBeNull()
})
