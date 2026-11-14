import { describe, expect, it } from 'vitest'
import { matrixWords } from '@/schema/matrix.js'
import { matrixChanges, pubVal } from './matrixChanges.js'

const W = matrixWords({ matrix: {} })
const table = (cols, rows) => ({ cols, rows })
const col = (id, name, price = '', note = '') => ({ id, name, price, note })
const row = (id, label, vals, kind = 'bool') => ({ _id: id, label, kind, vals })

describe('matrixChanges', () => {
  it('reports the first publication with the given words', () => {
    expect(matrixChanges(table([], []), null, W)).toEqual([{ label: W.first, val: 'первая публикация', old: '' }])
    expect(matrixChanges(table([], []), null, W, 'Таблица ещё не опубликована (en)')[0].label).toBe('Таблица ещё не опубликована (en)')
  })

  it('names a new and a removed column and row in the words of the collection', () => {
    const words = matrixWords({ matrix: { words: { colAdded: 'Новый тариф', colRemoved: 'Тариф удалён', rowAdded: 'Новая опция', rowGone: 'Опция удалена' } } })
    const pub = table([col('a', 'Базовый')], [row('r1', 'Домен', [true])])
    const cur = table([col('b', 'Про')], [row('r2', 'Почта', [true])])
    const labels = matrixChanges(cur, pub, words).map((c) => c.label)
    expect(labels).toEqual(expect.arrayContaining(['Новый тариф', 'Тариф удалён', 'Новая опция', 'Опция удалена']))
  })

  it('has general words without matrix.words', () => {
    const labels = matrixChanges(table([col('b', 'Про')], []), table([col('a', 'Базовый')], []), W).map((c) => c.label)
    expect(labels).toEqual(['Новый столбец', 'Столбец удалён'])
  })

  it('compares cells of the columns both versions have, a new column is reported once', () => {
    const pub = table([col('a', 'Базовый', '100')], [row('r', 'Домен', [false])])
    const cur = table([col('a', 'Базовый', '200'), col('b', 'Про')], [row('r', 'Домен', [true, false])])
    const out = matrixChanges(cur, pub, W)
    expect(out.map((c) => c.label)).toEqual([`${W.priceLabel} «Базовый»`, 'Новый столбец', '«Домен» · Базовый'])
    expect(out[2]).toMatchObject({ val: 'Да', old: 'Нет' })
  })

  it('notices a changed order of rows', () => {
    const pub = table([col('a', 'A')], [row('1', 'Один', [true]), row('2', 'Два', [true])])
    const cur = table([col('a', 'A')], [row('2', 'Два', [true]), row('1', 'Один', [true])])
    expect(matrixChanges(cur, pub, W).map((c) => c.label)).toEqual([W.rowOrder])
  })

  it('pubVal is undefined for a new column or row', () => {
    const pub = table([col('a', 'A')], [])
    expect(pubVal(pub, row('1', 'x', [5]), 'a')).toBe(5)
    expect(pubVal(pub, row('1', 'x', [5]), 'zzz')).toBeUndefined()
    expect(pubVal(null, row('1', 'x', [5]), 'a')).toBeUndefined()
  })
})
