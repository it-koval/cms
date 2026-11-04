import { describe, expect, it } from 'vitest'
import { renderFirst, renderTemplate } from './itemTemplate.js'

const of = (o) => (k) => o[k]
const T = '{bike} · {yearFrom}–{yearTo}'

describe('renderTemplate', () => {
  it('prints everything that is there', () => {
    expect(renderTemplate(T, of({ bike: 'Beta', yearFrom: '2020', yearTo: '2022' }))).toBe('Beta · 2020–2022')
  })

  it('an empty value takes the text next to it away, never « · –»', () => {
    expect(renderTemplate(T, of({ bike: 'Beta' }))).toBe('Beta')
    expect(renderTemplate(T, of({ yearFrom: '2020', yearTo: '2022' }))).toBe('2020–2022')
    expect(renderTemplate(T, of({ bike: 'Beta', yearFrom: '2020' }))).toBe('Beta · 2020')
    expect(renderTemplate(T, of({ bike: 'Beta', yearTo: '2022' }))).toBe('Beta · 2022')
    expect(renderTemplate(T, of({ yearTo: '2022' }))).toBe('2022')
    expect(renderTemplate(T, of({}))).toBe('')
    expect(renderTemplate(T, of({ bike: ' ', yearFrom: null, yearTo: undefined }))).toBe('')
  })

  it('0 is a value, a number comes as it is', () => {
    expect(renderTemplate(T, of({ yearFrom: 0 }))).toBe('0')
    expect(renderTemplate(T, of({ yearFrom: 2020, yearTo: 2022 }))).toBe('2020–2022')
  })

  it('text before the first value and after the last one stays next to a value only', () => {
    expect(renderTemplate('№ {sku}, {price} ₽', of({ sku: 'A1', price: 900 }))).toBe('№ A1, 900 ₽')
    expect(renderTemplate('№ {sku}, {price} ₽', of({ sku: 'A1' }))).toBe('№ A1')
    expect(renderTemplate('№ {sku}, {price} ₽', of({ price: 900 }))).toBe('900 ₽')
    expect(renderTemplate('№ {sku}', of({}))).toBe('')
    expect(renderTemplate('{price} ₽', of({}))).toBe('')
  })

  it('a template without keys is a fixed text, an unknown key is empty', () => {
    expect(renderTemplate('Вариант', of({}))).toBe('Вариант')
    expect(renderTemplate('{nope}', of({ a: 'x' }))).toBe('')
  })

  it('collapses white space in values', () => {
    expect(renderTemplate('{a}', of({ a: '  one\n two ' }))).toBe('one two')
  })
})

describe('renderFirst', () => {
  it('takes the first template that gives something', () => {
    const tpls = [T, '{title}']
    expect(renderFirst(tpls, of({ title: 'Полная комплектация' }))).toBe('Полная комплектация')
    expect(renderFirst(tpls, of({ bike: 'Beta', title: 'X' }))).toBe('Beta')
    expect(renderFirst(tpls, of({}))).toBe('')
    expect(renderFirst(undefined, of({}))).toBe('')
    expect(renderFirst('{a}', of({ a: 'one' }))).toBe('one')
  })
})
