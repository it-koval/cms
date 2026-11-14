import { afterEach, describe, expect, it, vi } from 'vitest'
import { addMessages, collator, formatDate, formatNumber, initI18n, locale, locales, t } from './index.js'

afterEach(async () => {
  vi.restoreAllMocks()
  await initI18n('ru')
})

describe('t', () => {
  it('answers in the language of the screen, English behind it, the key last', async () => {
    addMessages('en', { 'test.both': 'Both', 'test.only_en': 'English only' })
    addMessages('ru', { 'test.both': 'Оба' })
    expect(t('test.both')).toBe('Оба')
    expect(t('test.only_en')).toBe('English only')
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    expect(t('test.no_such_key')).toBe('test.no_such_key')
    expect(warn).toHaveBeenCalledOnce()
  })

  it('puts the parameters in, and leaves alone what it was not given', () => {
    addMessages('ru', { 'test.hi': 'Привет, {name}! {other}' })
    expect(t('test.hi', { name: 'Анна' })).toBe('Привет, Анна! {other}')
    expect(t('test.hi', { name: 0 })).toBe('Привет, 0! {other}')
  })

  it('chooses the Russian form by the number', () => {
    addMessages('ru', { 'test.pages': { one: '{count} страница', few: '{count} страницы', many: '{count} страниц', other: '{count} страницы' } })
    expect([1, 2, 5, 21, 22, 25, 11].map((count) => t('test.pages', { count }))).toEqual(['1 страница', '2 страницы', '5 страниц', '21 страница', '22 страницы', '25 страниц', '11 страниц'])
    expect(t('test.pages', { count: 1.5 })).toBe('1.5 страницы')
  })

  it('English has two forms, Kazakh one', async () => {
    addMessages('en', { 'test.pages': { one: '{count} page', other: '{count} pages' } })
    addMessages('kk', { 'test.pages': { other: '{count} бет' } })
    await initI18n('en')
    expect(locale).toBe('en')
    expect([t('test.pages', { count: 1 }), t('test.pages', { count: 2 })]).toEqual(['1 page', '2 pages'])
    await initI18n('kk')
    expect(locale).toBe('kk')
    expect([t('test.pages', { count: 1 }), t('test.pages', { count: 2 })]).toEqual(['1 бет', '2 бет'])
  })

  it('the catalogues of the bundle are loaded for the language', async () => {
    expect(t('i18n.language_label')).toBe('Язык интерфейса')
    await initI18n('kk')
    expect(t('i18n.language_label')).toBe('Интерфейс тілі')
  })
})

describe('the language', () => {
  it('a language the admin does not have falls back to English', async () => {
    await initI18n('de')
    expect(locale).toBe('en')
  })

  it('without a shell page the built-in languages are offered', () => {
    expect(locales().map((l) => l.code)).toEqual(['en', 'ru', 'kk'])
  })
})

describe('formats follow the language', () => {
  const day = new Date(2026, 9, 28, 12, 0)

  it('dates', async () => {
    expect(formatDate(day, { month: 'long' })).toBe('октябрь')
    expect(formatDate(day, { day: 'numeric', month: 'long' })).toBe('28 октября')
    await initI18n('en')
    expect(formatDate(day, { month: 'long' })).toBe('October')
    expect(formatDate(day.toISOString(), { year: 'numeric' })).toBe('2026')
  })

  it('an empty or wrong date is an empty string, not an error', () => {
    for (const value of [undefined, null, '', 'not a date', new Date(NaN)]) expect(formatDate(value)).toBe('')
  })

  it('numbers', async () => {
    expect(formatNumber(11000)).toBe('11 000')
    await initI18n('en')
    expect(formatNumber('11000')).toBe('11,000')
  })

  it('sorting', async () => {
    expect(['Ёж', 'Яблоко', 'Еда'].sort(collator.compare)).toEqual(['Еда', 'Ёж', 'Яблоко'])
    expect(['б10', 'б9'].sort(collator({ numeric: true }).compare)).toEqual(['б9', 'б10'])
  })
})

describe('a browser without Intl data for the language', () => {
  const day = new Date(2026, 10, 2, 12, 0)

  it('kk never shows a date template like «M11 2» or an English number', async () => {
    await initI18n('kk')
    for (const options of [{ day: 'numeric', month: 'long' }, { month: 'long' }, { day: 'numeric', month: 'short', year: 'numeric' }, { weekday: 'long', day: 'numeric', month: 'long' }]) {
      expect(formatDate(day, options)).not.toMatch(/M\d/)
    }
    expect(formatNumber(1234567.5)).not.toBe('1,234,567.5')
  })

  it('degrades to numbers when the data is missing', async () => {
    const real = Intl.DateTimeFormat
    // what Chrome without the ICU data of kk does: the month comes out as a template
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function (code, options) {
      return code === 'kk' ? { format: () => 'M11 2' } : new real(code, options)
    })
    await initI18n('kk')
    expect(formatDate(day, { day: 'numeric', month: 'long' })).toBe('02.11')
    expect(formatDate(day, { day: '2-digit', month: '2-digit', year: 'numeric' })).toBe('02.11.2026')
    expect(formatDate(day, { weekday: 'short' })).toMatch(/^[а-я]{2}$/)
    // the calendar passes timeZone next to the weekday: that is still a weekday on its own
    expect(formatDate(day, { timeZone: 'UTC', weekday: 'short' })).toMatch(/^[а-я]{2}$/)
  })
})
