import { describe, expect, it } from 'vitest'
import slugCases from '../../../../packages/cms/tests/Fixtures/slug-cases.json'
import { autoUrl, normalizeUrl, pageWithUrl, slugify, urlError, urlKey } from './slug.js'

describe('slugify', () => {
  it.each(slugCases)('transliterates "$in" with a limit of $max', ({ in: text, out, max }) => {
    expect(slugify(text, max)).toBe(out)
  })

  it('transliterates Russian and drops everything that is no address character', () => {
    expect(slugify('Доставка и оплата')).toBe('dostavka-i-oplata')
    expect(slugify('Щётка № 5, «Ёж»!')).toBe('schetka-5-ezh')
    expect(slugify('')).toBe('')
  })

  it('cuts at the limit without a dangling dash', () => {
    expect(slugify('один два три', 8)).toBe('odin-dva')
  })

  it('keeps the default limit for an editor address', () => {
    expect(slugify('Қ'.repeat(70))).toBe('q'.repeat(48))
  })
})

describe('addresses', () => {
  const pages = [{ id: 'p1', title: 'О нас', url: '/about' }, { id: 'p2', title: 'Контакты', url: '/contacts/' }]

  it('compares addresses case-insensitively and without a trailing slash', () => {
    expect(urlKey('/About/')).toBe('/about')
    expect(urlKey('/')).toBe('/')
    expect(pageWithUrl(pages, '/CONTACTS').id).toBe('p2')
    expect(pageWithUrl(pages, '/about', 'p1')).toBeNull()
  })

  it('makes an address unique with a suffix', () => {
    expect(autoUrl('О нас', pages)).toBe('/o-nas')
    expect(autoUrl('About', pages)).toBe('/about-2')
    expect(autoUrl('!!!', pages)).toBe('')
  })

  it('tidies what was typed', () => {
    expect(normalizeUrl('  Мой  Адрес//x ')).toBe('/мой-адрес/x')
    expect(normalizeUrl('about')).toBe('/about')
    expect(normalizeUrl('')).toBe('')
  })

  it('checks an address and says why not, in Russian', () => {
    expect(urlError('', pages)).toMatch(/Укажите адрес/)
    expect(urlError('about', pages)).toMatch(/начинаться со слэша/)
    expect(urlError('/a b', pages)).toMatch(/пробелов/)
    expect(urlError('/a//b', pages)).toMatch(/Два слэша/)
    expect(urlError('/страница', pages)).toMatch(/латинские/)
    expect(urlError('/about', pages)).toMatch(/уже занят: страница «О нас»/)
    expect(urlError('/about', pages, 'p1')).toBe('')
    expect(urlError('/страница', pages, null, '/страница')).toBe('') // an address that came from the old site is not re-checked
    expect(urlError('/en/news', pages, null, '', ['en'])).toMatch(/принадлежат/)
    expect(urlError('/english', pages, null, '', ['en'])).toBe('')
  })
})
