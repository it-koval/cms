import { afterEach, describe, expect, it } from 'vitest'
import { PREVIEW, THUMB, thumbHtml, thumbUrl } from './media.js'

const images = { uploadUrl: '/uploads/', variantDir: '_v', widths: [320, 640, 960] }
const setImages = (cfg) => { globalThis.window = cfg ? { ITK_ADMIN: { images: cfg } } : { ITK_ADMIN: {} } }
afterEach(() => { delete globalThis.window })

describe('thumbUrl', () => {
  it('builds the address of the WebP variant of the nearest width', () => {
    setImages(images)
    expect(thumbUrl('/uploads/2026/10/a.jpg')).toBe('/uploads/_v/w320/2026/10/a.webp')
    expect(thumbUrl('/uploads/2026/10/a.PNG', PREVIEW)).toBe('/uploads/_v/w640/2026/10/a.webp')
    expect(thumbUrl('/uploads/a.jpg', 700)).toBe('/uploads/_v/w960/a.webp')
    expect(thumbUrl('/uploads/a.jpg', 5000)).toBe('/uploads/_v/w960/a.webp') // wider than any variant: the widest
    expect(THUMB).toBe(320)
  })

  it('leaves everything else as it is', () => {
    setImages(images)
    for (const src of ['/uploads/a.gif', '/uploads/a.svg', '/other/a.jpg', 'https://x.test/uploads/a.jpg', '/uploads/_v/w320/a.webp', '/uploads/../a.jpg', '/uploads/a.jpg?x=1', '', null]) {
      expect(thumbUrl(src)).toBe(src)
    }
  })

  it('does nothing while the scheme is unknown (npm run dev)', () => {
    setImages(null)
    expect(thumbUrl('/uploads/a.jpg')).toBe('/uploads/a.jpg')
  })

  it('rewrites <img> inside sanitised html', () => {
    setImages(images)
    expect(thumbHtml('<p><img src="/uploads/a.jpg?x=1&amp;y=2" alt=""></p><img src="/uploads/b.png">')).toBe('<p><img src="/uploads/a.jpg?x=1&amp;y=2" alt=""></p><img src="/uploads/_v/w640/b.webp">')
    expect(thumbHtml('<p>no pictures</p>')).toBe('<p>no pictures</p>')
  })
})
