// Frames of a photo, in fractions of the picture ({ x, y, w, h } 0..1). The same rules as the server's ImageVariants::cropRect().

export const parseRatio = (s) => {
  const m = /^\s*(\d{1,2})\s*[:xX/]\s*(\d{1,2})\s*$/.exec(String(s))
  if (!m || +m[1] < 1 || +m[2] < 1) return null
  const g = gcd(+m[1], +m[2])
  return [+m[1] / g, +m[2] / g]
}
const gcd = (a, b) => (b === 0 ? a : gcd(b, a % b))
export const ratioKey = ([a, b]) => `${a}:${b}`

export const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v))

/** w / h of a frame of ratio a:b on a picture of W × H pixels (the frame is a fraction of each side). */
export const frameAspect = (W, H, [a, b]) => (a / b) * (H / W)

/** The biggest frame of the ratio that fits, centred on the focal point (the middle when there is none) and kept inside the picture. */
export function autoFrame(W, H, ratio, focal) {
  const [a, b] = ratio
  let w
  let h
  if (W * b > H * a) { h = 1; w = (H * a) / (b * W) } else { w = 1; h = (W * b) / (a * H) }
  const fx = focal ? clamp(+focal.x, 0, 1) : 0.5
  const fy = focal ? clamp(+focal.y, 0, 1) : 0.5
  return { x: clamp(fx - w / 2, 0, 1 - w), y: clamp(fy - h / 2, 0, 1 - h), w, h }
}

/** A saved frame as the server will cut it: exactly the ratio, around the centre of the drawn one. */
export function exactFrame(W, H, ratio, frame) {
  const k = frameAspect(W, H, ratio)
  let { x, y, w, h } = frame
  if (w / h > k) { const nw = h * k; x += (w - nw) / 2; w = nw } else { const nh = w / k; y += (h - nh) / 2; h = nh }
  return { x, y, w, h }
}

/** A free frame of wpx × hpx as the nearest simple ratio (each side 1..99), e.g. 1200 × 850 → 7:5. */
export function approxRatio(wpx, hpx) {
  const r = wpx / hpx
  let best = null
  for (let b = 1; b <= 99; b++) {
    const a = Math.round(r * b)
    if (a < 1 || a > 99) continue
    const err = Math.abs(a / b - r) / r
    if (err < 0.01 && (!best || !best.ok || a + b < best.a + best.b)) best = { a, b, err, ok: true }
    else if (!best || (!best.ok && err < best.err)) best = { a, b, err, ok: false }
  }
  if (!best) return [1, 1]
  const g = gcd(best.a, best.b)
  return [best.a / g, best.b / g]
}
