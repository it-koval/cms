import { describe, expect, it } from 'vitest'
import { listingSwitchIn } from './templates.js'

const toggle = (k, label) => ({ k, t: 'toggle', label })
const catalog = { key: 'catalog', listing: { of: ['product', 'braces'], label: 'Товары', onlyIf: 'show' } }

describe('listingSwitchIn: the switch that puts a page into the list of its parent page', () => {
  it('reads it from the listing of the parent template (listing.onlyIf)', () => {
    const product = { key: 'product', fields: [toggle('show', 'Показывать в каталоге')] }
    expect(listingSwitchIn([catalog, product], 'product')).toEqual({ key: 'show', label: 'Показывать в каталоге' })
  })

  it('is null for a template that no listing with onlyIf names', () => {
    const page = { key: 'default', fields: [toggle('show', 'Показывать')] }
    expect(listingSwitchIn([catalog, page], 'default')).toBeNull()
    expect(listingSwitchIn([catalog], 'unknown')).toBeNull()
  })

  it('is null when the template does not have the field the listing asks for', () => {
    const product = { key: 'product', fields: [toggle('other', 'Другое')] }
    expect(listingSwitchIn([catalog, product], 'product')).toBeNull()
  })

  it('still finds a field called "catalog" for the sites whose listing names no switch', () => {
    const legacyListing = { key: 'catalog', listing: { of: ['product'], label: 'Товары' } }
    const product = { key: 'product', fields: [toggle('catalog', 'Показывать в каталоге')] }
    expect(listingSwitchIn([legacyListing, product], 'product')).toEqual({ key: 'catalog', label: 'Показывать в каталоге' })
  })

  it('prefers the listing over the old field name', () => {
    const product = { key: 'product', fields: [toggle('catalog', 'Старое'), toggle('show', 'Новое')] }
    expect(listingSwitchIn([catalog, product], 'product').key).toBe('show')
  })

  it('has a general label when the field has none', () => {
    const product = { key: 'product', fields: [{ k: 'show', t: 'toggle' }] }
    expect(listingSwitchIn([catalog, product], 'product')).toEqual({ key: 'show', label: 'Показывать' })
  })
})
