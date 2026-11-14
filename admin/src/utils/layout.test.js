import { describe, expect, it } from 'vitest'
import { layoutBlocks, shownFields } from './layout.js'

const rows = (fields) => layoutBlocks(fields).map((b) => [b.section, b.items.map((f) => f.k)])

describe('layoutBlocks', () => {
  it('leaves fields without half or section one per row', () => {
    expect(rows([{ k: 'a' }, { k: 'b' }])).toEqual([['', ['a']], ['', ['b']]])
  })

  it('puts two neighbours with half in one row, a lone one stays alone', () => {
    expect(rows([{ k: 'from', half: true }, { k: 'to', half: true }, { k: 'sku' }])).toEqual([['', ['from', 'to']], ['', ['sku']]])
    expect(rows([{ k: 'a' }, { k: 'from', half: true }, { k: 'z' }])).toEqual([['', ['a']], ['', ['from']], ['', ['z']]])
  })

  it('a third half field begins the next row', () => {
    expect(rows([{ k: 'a', half: true }, { k: 'b', half: true }, { k: 'c', half: true }])).toEqual([['', ['a', 'b']], ['', ['c']]])
  })

  it('a heading starts a row and stays before its field', () => {
    expect(rows([{ k: 'a', half: true }, { k: 'b', half: true, section: 'Подбор' }, { k: 'c', half: true }])).toEqual([['', ['a']], ['Подбор', ['b', 'c']]])
  })

  it('works on items through fieldOf', () => {
    const items = [{ f: { k: 'a', half: true } }, { f: { k: 'b', half: true } }]
    expect(layoutBlocks(items, (r) => r.f)).toHaveLength(1)
    expect(layoutBlocks(undefined)).toEqual([])
  })
})

describe('shownFields', () => {
  const keys = (fields) => shownFields(fields).map((f) => [f.k, f.section || ''])

  it('leaves the fields that are all shown as they are', () => {
    const fields = [{ k: 'a' }, { k: 'b', section: 'B' }]
    expect(shownFields(fields)).toEqual(fields)
    expect(shownFields(undefined)).toEqual([])
  })

  it('drops fields with form: false', () => {
    expect(keys([{ k: 'a' }, { k: 'b', form: false }, { k: 'c' }])).toEqual([['a', ''], ['c', '']])
  })

  it('the heading of a hidden field goes to the next shown field', () => {
    expect(keys([{ k: 'a' }, { k: 'b', form: false, section: 'Подбор' }, { k: 'c' }, { k: 'd' }])).toEqual([['a', ''], ['c', 'Подбор'], ['d', '']])
  })

  it('a next field with its own heading keeps it, a heading at the end is dropped', () => {
    expect(keys([{ k: 'b', form: false, section: 'Старый' }, { k: 'c', section: 'Свой' }])).toEqual([['c', 'Свой']])
    expect(keys([{ k: 'a' }, { k: 'z', form: false, section: 'Конец' }])).toEqual([['a', '']])
  })

  it('does not change the fields it was given and works with the layout', () => {
    const fields = [{ k: 'b', form: false, section: 'Подбор' }, { k: 'c', half: true }, { k: 'd', half: true }]
    shownFields(fields)
    expect(fields[1].section).toBeUndefined()
    expect(rows(shownFields(fields))).toEqual([['Подбор', ['c', 'd']]])
  })
})
