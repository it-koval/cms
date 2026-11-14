// Section silhouettes: tiny abstract SVG thumbnails for the block library and the structure list. Ported from the prototype sil().
export const TONES = { dark: ['#16191C', '#fff'], gray: ['#E2E6E8', '#16191C'], white: ['#FFFFFF', '#16191C'], photo: ['#566872', '#fff'], blue: ['#E6F0FB', '#16191C'] }

let gid = 0

/** silhouette('hero', 'dark', 184, 96) -> SVG string (use with v-html, or the <Silhouette> component). */
export function silhouette(kind, tone, W = 184, H = 96) {
  const id = `gA${++gid}`
  const bg = TONES[tone] ? TONES[tone][0] : '#fff'
  const dark = tone === 'dark' || tone === 'photo'
  const f1 = dark ? 'rgba(255,255,255,.88)' : '#2B343A'
  const f2 = dark ? 'rgba(255,255,255,.34)' : '#9AA5AC'
  const f3 = dark ? 'rgba(255,255,255,.16)' : '#D3D9DD'
  const img = dark ? '#3B454B' : '#B9C3C9'
  const acc = `url(#${id})`
  let o = ''
  const R = (x, y, w, h, f, r = 2, extra = '') => { o += `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="${f}" ${extra}/>` }
  const C = (x, y, r, f) => { o += `<circle cx="${x}" cy="${y}" r="${r}" fill="${f}"/>` }
  switch (kind) {
    case 'hero': R(14, 18, 72, 15, acc, 2); R(14, 38, 98, 15, f1, 2); R(14, 62, 60, 4, f2); R(14, 70, 48, 4, f2); R(14, 84, 36, 10, f1, 5); R(108, 30, 66, H - 30, img, 6); C(141, 52, 12, f2); R(118, 70, 46, H - 70, f3, 4); break
    case 'hero2': R(14, 20, 90, 12, f1, 2); R(14, 38, 70, 12, f1, 2); R(14, 60, 80, 4, f2); R(14, 70, 36, 11, '#2D6CDF', 5); R(120, 22, 52, 66, img, 6); break
    case 'bgimg': R(0, 0, W, H, '#6B7F8A', 0); R(0, H * 0.45, W, H * 0.55, '#4B5C66', 0); R(12, 16, 90, H - 32, 'rgba(0,0,0,.55)', 6); R(22, 28, 36, 4, f2); R(22, 38, 70, 9, f1, 2); R(22, 51, 54, 9, f1, 2); R(22, 70, 34, 9, acc, 5); break
    case 'split': R(12, 14, 82, H - 28, '#F2F4F5', 4); R(20, 22, 30, 4, f2); R(20, 32, 60, 8, f1, 2); R(20, 44, 44, 8, acc, 2); R(20, 60, 64, 3, f2); R(20, 67, 56, 3, f2); R(20, 78, 34, 9, f1, 5); R(104, 0, 80, H, img, 0); C(144, H * 0.4, 15, f2); R(122, H * 0.62, 44, H * 0.38, f3, 4); break
    case 'struct': R(46, 10, 92, 10, f1, 2); R(60, 24, 64, 4, f2); R(70, 36, 44, H - 50, img, 6); for (let i = 0; i < 3; i++) { R(12, 38 + i * 24, 44, 3, f1); R(12, 45 + i * 24, 40, 3, f2); R(130, 38 + i * 24, 44, 3, f1); R(134, 45 + i * 24, 38, 3, f2) } break
    case 'form': R(40, 12, 104, 11, f1, 2); R(62, 28, 60, 5, f2); R(22, 42, 62, 11, 'none', 6, `stroke="${f2}"`); R(98, 42, 62, 11, 'none', 6, `stroke="${f2}"`); R(66, 60, 52, 10, acc, 5); break
    case 'slider': R(14, 14, 74, 9, f1, 2); R(14, 30, 70, 4, f2); R(14, 38, 66, 4, f2); R(14, 46, 70, 4, f2); R(14, 54, 54, 4, f2); R(100, 14, 72, H - 36, img, 5); C(124, H - 12, 3, f1); C(134, H - 12, 3, f2); C(144, H - 12, 3, f2); break
    case 'count': R(14, 14, 78, 22, f1, 3); R(14, 44, 64, 4, f2); R(14, 54, 36, 9, acc, 5); R(104, 0, 80, H, img, 0); C(144, H * 0.45, 15, f2); break
    case 'bubbles': R(34, 10, 116, 8, f1, 2); R(58, 22, 68, 4, f2); for (let i = 0; i < 6; i++) C(20 + i * 29, 46, 11, img); for (let i = 0; i < 5; i++) C(34 + i * 29, 76, 11, img); break
    case 'people': R(14, 10, 100, 8, f1, 2); for (let i = 0; i < 3; i++) { R(14 + i * 58, 28, 52, H - 40, f3, 4); R(14 + i * 58, 28, 52, 34, img, 4); R(20 + i * 58, 68, 30, 3, f1); R(20 + i * 58, 75, 38, 3, f2) } break
    case 'gallery': R(14, 10, 90, 8, f1, 2); R(14, 24, 74, H - 36, img, 3); R(92, 24, 36, 34, '#9DAAB1', 3); R(132, 24, 38, 34, '#C4CDD2', 3); R(92, 62, 36, H - 74, '#C4CDD2', 3); R(132, 62, 38, H - 74, '#9DAAB1', 3); break
    case 'cards3': R(14, 10, 100, 8, f1, 2); for (let i = 0; i < 3; i++) { R(14 + i * 56, 28, 50, H - 40, '#F2F4F5', 4); R(22 + i * 56, 36, 14, 14, '#2D6CDF', 7); R(22 + i * 56, 58, 34, 4, f1); R(22 + i * 56, 66, 34, 3, f2) } break
    case 'cards4': R(14, 10, 60, 8, f1, 2); for (let i = 0; i < 4; i++) { R(14 + i * 42, 28, 38, H - 38, '#F2F4F5', 4); R(20 + i * 42, 34, 24, 4, f1); R(20 + i * 42, 44, 22, 3, f2); R(20 + i * 42, 52, 22, 3, f2); R(20 + i * 42, H - 22, 26, 7, '#2D6CDF', 3) } break
    case 'faq': R(14, 10, 40, 8, f1, 2); for (let i = 0; i < 4; i++) { R(14, 26 + i * 14, 156, 10, '#fff', 3); R(20, 29 + i * 14, 70 + (i % 2) * 20, 4, f2) } break
    case 'text': R(14, 12, 80, 8, f1, 2); for (let i = 0; i < 5; i++) R(14, 28 + i * 9, 150 - (i % 3) * 22, 3, f2); break
    case 'textimg': R(14, 10, 70, 8, f1, 2); for (let i = 0; i < 3; i++) R(14 + i * 56, 28, 50, H - 38, img, 4); break
    case 'picture': R(10, 10, W - 20, H - 20, img, 5); C(60, H * 0.45, 10, f2); R(92, H * 0.6, 70, H * 0.22, f3, 3); break
    case 'video': R(10, 10, W - 20, H - 20, img, 5); C(W / 2, H / 2, 14, f1); o += `<path d="M${W / 2 - 4} ${H / 2 - 7} l12 7 l-12 7z" fill="${bg === '#FFFFFF' ? '#fff' : bg}"/>`; break
    case 'variants': for (let i = 0; i < 3; i++) { R(12 + i * 56, 12, 50, H - 24, '#F2F4F5', 4); R(12 + i * 56, 12, 50, 30, img, 4); R(18 + i * 56, 48, 30, 4, f1); R(18 + i * 56, 56, 38, 3, f2) } break
    case 'cards': for (let i = 0; i < 3; i++) { R(12 + i * 56, 18, 50, H - 36, '#F2F4F5', 4); R(20 + i * 56, 28, 26, 10, f1, 2); R(20 + i * 56, 46, 32, 3, f2) } break
    case 'steps': for (let i = 0; i < 4; i++) { C(24 + i * 42, 32, 8, '#2B343A'); R(14 + i * 42, 48, 20, 3, f1); R(12 + i * 42, 56, 26, 3, f2) } R(30, 32, 124, 1, f2); break
    case 'contacts': R(14, 14, 60, 8, f1, 2); R(14, 30, 50, 4, f2); R(14, 38, 44, 4, f2); R(14, 46, 54, 4, f2); R(88, 12, 82, H - 24, img, 5); break
    case 'reviews': for (let i = 0; i < 3; i++) { R(12 + i * 56, 20, 50, H - 40, '#F2F4F5', 4); R(18 + i * 56, 28, 36, 4, f1); R(18 + i * 56, 38, 38, 3, f2); R(18 + i * 56, 45, 30, 3, f2) } break
    default: R(14, 14, 80, 8, f1, 2); R(14, 30, 140, 4, f2)
  }
  const defs = `<defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#01CDC1"/><stop offset=".5" stop-color="#2BE62B"/><stop offset="1" stop-color="#ADFF00"/></linearGradient></defs>`
  return `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid slice" aria-hidden="true">${defs}<rect width="${W}" height="${H}" fill="${bg}"/>${o}</svg>`
}

/** Silhouette of a section type (uses its sil and tone). */
export const silhouetteFor = (type, W, H) => (type ? silhouette(type.sil, type.tone, W, H) : silhouette('text', 'white', W, H))
