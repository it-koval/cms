import { afterEach, describe, expect, it } from 'vitest'
import { initI18n } from '@/i18n/index.js'
import { recordChanges } from './changes.js'

describe('recordChanges of a matrix record', () => {
  afterEach(async () => { await initI18n('ru') })

  const rec = { cols: [{ id: 'a' }, { id: 'b' }], rows: [{ _id: '1', vals: [] }] }
  const pub = { cols: [{ id: 'a' }], rows: [{ _id: '1', vals: [] }] }

  it('names the changed parts as the collection does, not "Тарифы"', () => {
    const cfg = { view: 'matrix', matrix: { words: { colsTitle: 'Тарифы', rowsTitle: 'Опции' } }, tabs: [] }
    const out = recordChanges(cfg, rec, pub)
    expect(out).toHaveLength(1)
    expect(out[0].field.label).toBe('Тарифы')
    expect(out[0]).toMatchObject({ val: '2 колонок', old: '1 колонок' })
  })

  it('counts in the form of the language: one column, two columns', async () => {
    await initI18n('en')
    const out = recordChanges({ view: 'matrix', matrix: {}, tabs: [] }, rec, pub)
    expect(out[0]).toMatchObject({ val: '2 columns', old: '1 column' })
  })

  it('a collection may name the unit itself', () => {
    const out = recordChanges({ view: 'matrix', matrix: { words: { colsUnit: 'тарифа' } }, tabs: [] }, rec, pub)
    expect(out[0]).toMatchObject({ val: '2 тарифа', old: '1 тарифа' })
  })

  it('has general titles without matrix.words', () => {
    const out = recordChanges({ view: 'matrix', matrix: {}, tabs: [] }, rec, pub)
    expect(out[0].field.label).toBe('Столбцы')
    const rows = recordChanges({ view: 'matrix', matrix: {}, tabs: [] }, { cols: pub.cols, rows: [] }, pub)
    expect(rows[0].field.label).toBe('Строки')
  })

  it('nothing changed, nothing reported', () => {
    expect(recordChanges({ view: 'matrix', matrix: {}, tabs: [] }, pub, pub)).toEqual([])
  })
})
