import { describe, expect, it } from 'vitest'
import { fill, matrixWords } from './matrix.js'

describe('matrixWords: the wording of the options matrix comes from the collection', () => {
  it('has a general wording that names no business', () => {
    const w = matrixWords({ key: 'plans', matrix: {} })
    expect(w.colNew).toBe('Новый столбец')
    expect(w.rowNew).toBe('Новая строка')
    expect(JSON.stringify(w)).not.toMatch(/тариф|опци/i)
  })

  it('takes the label of the "new" button for the button only: it is a verb, not the name of a column', () => {
    expect(matrixWords({ newLabel: 'Добавить', matrix: {} }).colNew).toBe('Новый столбец')
    expect(matrixWords({ newLabel: 'Добавить тариф', matrix: {} }).colNew).toBe('Новый столбец')
    expect(matrixWords({ newLabel: 'Добавить тариф', matrix: {} }).colAdd).toBe('Добавить тариф')
  })

  it('names a new column only through matrix.words', () => {
    expect(matrixWords({ newLabel: 'Добавить тариф', matrix: { words: { colNew: 'Новый тариф' } } }).colNew).toBe('Новый тариф')
  })

  it('lets matrix.words override any phrase and ignores what is not a phrase', () => {
    const w = matrixWords({ newLabel: 'Новый тариф', matrix: { words: { colNew: 'Тариф без названия', rowNew: 'Новая опция', colPlural: ['тариф', 'тарифа', 'тарифов'], bogus: 5, corner: 7 } } })
    expect(w.colNew).toBe('Тариф без названия')
    expect(w.rowNew).toBe('Новая опция')
    expect(w.colPlural).toEqual(['тариф', 'тарифа', 'тарифов'])
    expect(w.corner).toBe('Строка') // a wrong type is dropped
    expect(w.bogus).toBeUndefined()
  })

  it('takes the heading line from the description of the collection', () => {
    expect(matrixWords({ description: 'Таблица на странице «Цены».', matrix: {} }).about).toBe('Таблица на странице «Цены».')
  })

  it('takes the name of the price row from the second column field', () => {
    expect(matrixWords({ matrix: { colFields: [{ label: 'Название' }, { label: 'Цена, ₽ в месяц' }, { label: 'Пометка' }] } }).priceLabel).toBe('Цена, ₽ в месяц')
  })

  it('works without a matrix block at all', () => {
    expect(matrixWords({}).first).toBe('Таблица ещё не опубликована')
    expect(matrixWords(null).first).toBe('Таблица ещё не опубликована')
  })
})

describe('fill', () => {
  it('puts the name into the phrase', () => {
    expect(fill('Удалить столбец «{name}»?', { name: 'Про' })).toBe('Удалить столбец «Про»?')
    expect(fill('Без подстановки')).toBe('Без подстановки')
  })
})
