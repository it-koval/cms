import { afterEach, describe, expect, it } from 'vitest'
import { initI18n } from '@/i18n/index.js'
import { describeChange, describeValue, duplicateItem, repeaterSummary, repeaterThumb, repeaterTitle, setRelationTitle } from './fields.js'

const variants = {
  k: 'variants', t: 'repeater', noun: 'вариант',
  itemTitle: ['{bike} · {yearFrom}–{yearTo}', '{title}'],
  itemSummary: ['{sku} · {price}'],
  sub: [
    { k: 'bike', t: 'relation', to: 'bikes', label: 'Мотоцикл' },
    { k: 'yearFrom', t: 'number', label: 'С' },
    { k: 'yearTo', t: 'number', label: 'По' },
    { k: 'title', t: 'text', label: 'Название' },
    { k: 'sku', t: 'text', label: 'Артикул' },
    { k: 'price', t: 'number', label: 'Цена' },
    { k: 'photos', t: 'photos', label: 'Фото' },
    { k: 'text', t: 'textarea', rich: true, label: 'Описание' },
  ],
}
const bikes = { 7: 'Beta RR 2T' }

describe('repeater row title and summary', () => {
  afterEach(() => setRelationTitle(null))

  it('a Relation is shown by the title of the record', () => {
    setRelationTitle((to, id) => (to === 'bikes' ? bikes[id] || '' : ''))
    expect(repeaterTitle(variants, { bike: '7', yearFrom: 2020, yearTo: 2022 }, 0)).toBe('Beta RR 2T · 2020–2022')
    expect(repeaterTitle(variants, { bike: '7' }, 0)).toBe('Beta RR 2T')
  })

  it('without a bike the next template, then the usual title', () => {
    expect(repeaterTitle(variants, { bike: '', title: 'Полная комплектация' }, 0)).toBe('Полная комплектация')
    expect(repeaterTitle(variants, { bike: '', yearFrom: '', yearTo: '', title: '' }, 1)).toBe('Вариант 2')
    expect(repeaterTitle({ ...variants, noun: undefined }, {}, 0)).toBe('Пункт 1')
  })

  it('a record that is not loaded yet or is gone leaves its place empty', () => {
    expect(repeaterTitle(variants, { bike: '99', yearFrom: 2020 }, 0)).toBe('2020')
  })

  it('a Select is shown by its label', () => {
    const f = { k: 'x', t: 'repeater', itemTitle: ['{kind}'], sub: [{ k: 'kind', t: 'select', opts: [['a', 'Альфа']] }] }
    expect(repeaterTitle(f, { kind: 'a' }, 0)).toBe('Альфа')
  })

  it('the summary follows its template, empty when it gives nothing', () => {
    expect(repeaterSummary(variants, { sku: 'A-1', price: 900 })).toBe('A-1 · 900')
    expect(repeaterSummary(variants, { sku: 'A-1' })).toBe('A-1')
    expect(repeaterSummary(variants, {})).toBe('')
  })

  it('a number with grouped() has thousands separators, a year does not', () => {
    const f = { k: 'x', t: 'repeater', itemSummary: ['{yearFrom} · {price} ₽'], sub: [{ k: 'yearFrom', t: 'number' }, { k: 'price', t: 'number', grouped: true }] }
    expect(repeaterSummary(f, { yearFrom: 2024, price: 110000 })).toBe('2024 · 110 000 ₽')
    expect(repeaterSummary(f, { price: '' })).toBe('')
  })

  it('grouped() follows the language of the admin and keeps every decimal of the number', async () => {
    const f = { k: 'x', t: 'repeater', itemSummary: ['{price}'], sub: [{ k: 'price', t: 'number', grouped: true }] }
    try {
      await initI18n('en')
      expect(repeaterSummary(f, { price: 110000.5 })).toBe('110,000.5')
      expect(repeaterSummary(f, { price: 0.12345 })).toBe('0.12345')
      await initI18n('ru')
      expect(repeaterSummary(f, { price: 110000.5 })).toBe('110 000,5')
      expect(repeaterSummary(f, { price: '1234567' })).toBe('1 234 567')
    } finally {
      await initI18n('ru')
    }
  })

  it('without templates the old rules hold', () => {
    const f = { k: 'x', t: 'repeater', sub: [{ k: 'title', t: 'text' }, { k: 'd', t: 'text' }] }
    expect(repeaterTitle(f, { title: 'Слайд' }, 0)).toBe('Слайд')
  })
})

describe('repeaterThumb', () => {
  it('without itemTitle / itemSummary only an image field makes a thumbnail, as before', () => {
    const plain = { sub: [{ k: 'ph', t: 'photos' }, { k: 'title', t: 'text' }] }
    expect(repeaterThumb(plain, { ph: ['/u/a.jpg'] })).toEqual({ has: false, src: '' })
    expect(repeaterThumb({ ...plain, itemTitle: [], itemSummary: [] }, { ph: ['/u/a.jpg'] })).toEqual({ has: false, src: '' })
    const withImg = { sub: [{ k: 'img', t: 'image' }, { k: 'ph', t: 'photos' }] }
    expect(repeaterThumb(withImg, { img: { src: '/u/i.jpg' }, ph: ['/u/p.jpg'] })).toEqual({ has: true, src: '/u/i.jpg' })
    expect(repeaterThumb(withImg, { img: { src: '' }, ph: ['/u/p.jpg'] })).toEqual({ has: true, src: '' })
    expect(repeaterThumb(withImg, {})).toEqual({ has: true, src: '' })
  })

  it('the first photo of a photos field, strings or { src }', () => {
    expect(repeaterThumb(variants, { photos: ['/u/a.jpg', '/u/b.jpg'] })).toEqual({ has: true, src: '/u/a.jpg' })
    expect(repeaterThumb(variants, { photos: [{ src: '/u/c.jpg', alt: '' }] })).toEqual({ has: true, src: '/u/c.jpg' })
  })

  it('a row without photos keeps an empty frame, bad values do not break it', () => {
    expect(repeaterThumb(variants, { photos: [] })).toEqual({ has: true, src: '' })
    expect(repeaterThumb(variants, {})).toEqual({ has: true, src: '' })
    expect(repeaterThumb(variants, { photos: [null, 5, '', { alt: 'x' }] })).toEqual({ has: true, src: '' })
    expect(repeaterThumb(variants, { photos: 'nope' })).toEqual({ has: true, src: '' })
  })

  it('an image field comes first, a repeater with neither has no thumbnail', () => {
    const f = { itemTitle: ['{t}'], sub: [{ k: 'img', t: 'image' }, { k: 'ph', t: 'photos' }] }
    expect(repeaterThumb(f, { img: { src: '/u/i.jpg' }, ph: ['/u/p.jpg'] }).src).toBe('/u/i.jpg')
    expect(repeaterThumb(f, { img: { src: '' }, ph: ['/u/p.jpg'] }).src).toBe('/u/p.jpg')
    expect(repeaterThumb({ itemTitle: ['{a}'], sub: [{ k: 'a', t: 'text' }] }, {})).toEqual({ has: false, src: '' })
  })
})

describe('duplicateItem', () => {
  it('gives the copy and the items nested in it new _id and leaves the rest alone', () => {
    const it = { _id: 'p1', title: 'A', photos: ['/u/a.jpg'], link: { kind: 'url', url: 'x' }, sub: [{ _id: 'q1', n: 1 }, { _id: 'q2', n: 2 }] }
    const copy = duplicateItem(it)
    expect(copy._id).toMatch(/^p/)
    expect(copy._id).not.toBe('p1')
    expect(copy.sub.map((x) => x._id)).not.toContain('q1')
    expect(copy.sub[0]._id).not.toBe(copy.sub[1]._id)
    expect({ ...copy, _id: 'p1', sub: copy.sub.map((x, i) => ({ ...x, _id: it.sub[i]._id })) }).toEqual(it)
    expect(it._id).toBe('p1')
    expect(it.sub[0]._id).toBe('q1')
    copy.photos.push('/u/z.jpg')
    expect(it.photos).toHaveLength(1)
  })
})

describe('photos in the lists of changes', () => {
  const photos = variants.sub.find((s) => s.k === 'photos')

  it('say how many photos', () => {
    expect(describeValue(photos, ['/a.jpg', '/b.jpg'])).toBe('2 фото')
    expect(describeValue(photos, [])).toBe('нет фото')
    expect(describeValue(photos, undefined)).toBe('пусто')
  })

  it('a change of photos inside a row names the row and does not fail', () => {
    const was = [{ _id: 'p1', bike: '', title: 'Полная', photos: [{ src: '/a.jpg' }] }]
    const now = [{ _id: 'p1', bike: '', title: 'Полная', photos: [{ src: '/a.jpg' }, '/b.jpg'] }]
    expect(describeChange(variants, now, was)).toEqual({ where: '«Полная»: Фото', val: '2 фото', old: '1 фото' })
  })
})
