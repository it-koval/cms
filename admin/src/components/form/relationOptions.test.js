import { beforeEach, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ list: vi.fn(), app: { locale: 'ru' } }))
vi.mock('@/api/index.js', () => ({ api: { collections: { list: mocks.list } } }))
vi.mock('@/stores/app.js', () => ({ app: mocks.app }))
vi.mock('@/schema/collections.js', () => ({ getCollectionConfig: () => ({ titleField: 'title' }) }))
vi.mock('@/utils/fields.js', () => ({ setRelationTitle: vi.fn() }))

const settle = async () => { await Promise.resolve(); await Promise.resolve() }
beforeEach(() => { vi.resetModules(); mocks.list.mockReset(); mocks.app.locale = 'ru' })

it('caches translated relation choices separately for collection and language', async () => {
  mocks.list.mockImplementation(async (col, { locale }) => [{ id: col, title: locale }])
  const { relationOptions, relationLoaded } = await import('./relationOptions.js')
  expect(relationOptions('authors')).toEqual([])
  await settle()
  expect(relationOptions('authors')).toEqual([['authors', 'ru']])
  mocks.app.locale = 'kk'
  expect(relationLoaded('authors')).toBe(false)
  expect(relationOptions('authors')).toEqual([])
  await settle()
  expect(relationOptions('authors')).toEqual([['authors', 'kk']])
  expect(mocks.list).toHaveBeenCalledWith('authors', { locale: 'kk', onlyTranslated: false })
  mocks.app.locale = 'ru'
  expect(relationOptions('authors')).toEqual([['authors', 'ru']])
  expect(mocks.list).toHaveBeenCalledTimes(2)
})

it('keeps late responses under their requested language and finishes a refused load with no choices', async () => {
  let resolveRu
  mocks.list.mockImplementationOnce(() => new Promise((resolve) => { resolveRu = resolve }))
    .mockResolvedValueOnce([{ id: '1', title: 'Қазақша' }])
    .mockRejectedValueOnce(new Error('offline'))
  const { relationOptions, relationLoaded } = await import('./relationOptions.js')
  relationOptions('authors')
  relationOptions('authors')
  expect(mocks.list).toHaveBeenCalledTimes(1)
  mocks.app.locale = 'kk'
  relationOptions('authors')
  await settle()
  resolveRu([{ id: '1', title: 'Русский' }])
  await settle()
  expect(relationOptions('authors')).toEqual([['1', 'Қазақша']])
  relationOptions('categories')
  await settle()
  expect(relationLoaded('categories')).toBe(true)
  expect(relationOptions('categories')).toEqual([])
})
