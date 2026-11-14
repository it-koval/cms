// Pictures in the admin are shown as small WebP variants, never as the originals (which can be several MB).
// thumbUrl(src, width) builds the same address as the server's ImageVariants::url: {uploadUrl}/{variantDir}/w{width}/Y/m/name.webp,
// where width is the nearest allowed one that is not below `width`. The server tells the scheme in window.ITK_ADMIN.images
// ({ uploadUrl, variantDir, widths }). Only JPG, PNG, WebP and AVIF under uploadUrl are changed; anything else (GIF, SVG, PDF,
// external addresses, data:) and every address while the scheme is unknown (`npm run dev`) is returned as it is.
// Only for display: stored values keep the original address.
import { boot } from '@/api/http.js'

const CONVERTIBLE = /\.(?:jpe?g|png|webp|avif)$/i

/** Width of a small preview (list rows, tiles) and of a larger one (cards, the media drawer). */
export const THUMB = 320
export const PREVIEW = 640

export function thumbUrl(src, width = THUMB) {
  const cfg = boot().images
  if (typeof src !== 'string' || !cfg || !cfg.uploadUrl || !cfg.variantDir || !Array.isArray(cfg.widths) || !cfg.widths.length) return src
  const base = cfg.uploadUrl.replace(/\/+$/, '') + '/'
  if (!src.startsWith(base)) return src
  const rel = src.slice(base.length)
  if (!CONVERTIBLE.test(rel) || /[?#\\]|\.\.|\/\/|^\//.test(rel) || rel.startsWith(cfg.variantDir + '/')) return src
  const w = cfg.widths.find((x) => x >= width) ?? cfg.widths[cfg.widths.length - 1]
  return `${base}${cfg.variantDir}/w${w}/${rel.replace(/\.[^./]+$/, '.webp')}`
}

/** The same for sanitized HTML that is shown with v-html: <img src="…"> inside it gets the variant address (the text itself is not stored from here). */
export function thumbHtml(html, width = PREVIEW) {
  return typeof html === 'string' && html.includes('<img ')
    ? html.replace(/(<img src=")([^"]*)(")/g, (_, a, src, c) => a + thumbUrl(src.replace(/&amp;/g, '&'), width).replace(/&/g, '&amp;') + c)
    : html
}
