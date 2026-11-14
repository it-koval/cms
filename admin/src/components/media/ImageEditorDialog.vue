<script setup>
// Image editor: focal point and frames of a photo.
//   Точка фокуса — главное место на фото. Когда сайт показывает фото в другой пропорции, обрезка идёт вокруг неё.
//   Кадр — рамка, которую вы задали для одной пропорции вручную; она важнее точки фокуса.
// Props: open, item (a media item with w, h, src, focal, crops), ratios (['16:9', …] from itk_cms.images.ratios). Emits update:open, saved(item).
// Frames are fractions of the picture ({ x, y, w, h }, 0..1), saved with PATCH media/:id { focal, crops }; «Своя рамка» is saved under the nearest simple ratio.
import { vp } from '@/utils/viewport.js'
import { computed, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import { api } from '@/api/index.js'
import { thumbUrl } from '@/utils/media.js'
import { useToast } from '@/components/ui/useToast.js'
import { approxRatio, autoFrame, clamp, exactFrame, frameAspect, parseRatio, ratioKey } from './cropMath.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  item: { type: Object, default: null },
  ratios: { type: Array, default: () => ['16:9', '4:3', '1:1', '3:4'] },
})
const emit = defineEmits(['update:open', 'saved'])
const toast = useToast()

const W = computed(() => props.item?.w || 1600)
const H = computed(() => props.item?.h || 900)
const focal = ref(null)
const crops = ref({})
const mode = ref('frame') // 'frame' | 'focal'
const active = ref('') // a ratio key, or 'free'
const frame = ref(null) // the frame on the picture now: { x, y, w, h }
const freeFrame = ref(null)
const saving = ref(false)
const stage = ref(null)
const viewport = ref(typeof window !== 'undefined' ? window.innerWidth : 1200)

const keys = computed(() => {
  const own = Object.keys(crops.value).filter((k) => !props.ratios.includes(k))
  return [...props.ratios, ...own]
})
const isFree = computed(() => active.value === 'free')
const dirty = computed(() => {
  const it = props.item
  if (!it) return false
  return JSON.stringify(focal.value) !== JSON.stringify(it.focal || null) || JSON.stringify(crops.value) !== JSON.stringify(it.crops || {})
})
const dialogWidth = computed(() => Math.min(920, viewport.value - 24))
const stageStyle = computed(() => ({ aspectRatio: `${W.value} / ${H.value}`, width: `min(100%, calc(52vh * ${W.value / H.value}))` }))
const src = computed(() => (props.item ? thumbUrl(props.item.src, 1280) : ''))

watch(() => [props.open, props.item && props.item.id], () => {
  if (!props.open || !props.item) return
  viewport.value = window.innerWidth
  focal.value = props.item.focal ? { ...props.item.focal } : null
  crops.value = JSON.parse(JSON.stringify(props.item.crops || {}))
  mode.value = 'frame'
  freeFrame.value = null
  select(props.ratios[0] || 'free')
}, { immediate: true })

function select(key) {
  active.value = key
  if (key === 'free') { frame.value = freeFrame.value || { x: 0.1, y: 0.1, w: 0.8, h: 0.8 }; return }
  const ratio = parseRatio(key)
  if (!ratio) { frame.value = null; return }
  frame.value = crops.value[key] ? { ...crops.value[key] } : autoFrame(W.value, H.value, ratio, focal.value)
}

/** Remember the frame after a drag: a ratio frame goes to the file's frames, a free one waits for «Сохранить». */
function commit() {
  if (isFree.value) { freeFrame.value = { ...frame.value }; return }
  if (active.value) crops.value = { ...crops.value, [active.value]: roundFrame(frame.value) }
}
const roundFrame = (f) => ({ x: +f.x.toFixed(4), y: +f.y.toFixed(4), w: +f.w.toFixed(4), h: +f.h.toFixed(4) })

function resetFrame() {
  if (isFree.value) { freeFrame.value = null; select('free'); return }
  const next = { ...crops.value }
  delete next[active.value]
  crops.value = next
  select(active.value)
}
const hasFrame = computed(() => (isFree.value ? !!freeFrame.value : !!crops.value[active.value]))

// ---- pointer work on the picture
let drag = null
const point = (e) => {
  const r = stage.value.getBoundingClientRect()
  return { x: clamp((e.clientX - r.left) / r.width, 0, 1), y: clamp((e.clientY - r.top) / r.height, 0, 1) }
}
function begin(e, kind) {
  if (!frame.value || mode.value !== 'frame') return
  e.preventDefault()
  e.stopPropagation()
  e.currentTarget.setPointerCapture?.(e.pointerId)
  const p = point(e)
  drag = { kind, p0: p, f0: { ...frame.value } }
}
function move(e) {
  if (!drag) return
  const p = point(e)
  const f0 = drag.f0
  if (drag.kind === 'move') {
    frame.value = { ...f0, x: clamp(f0.x + p.x - drag.p0.x, 0, 1 - f0.w), y: clamp(f0.y + p.y - drag.p0.y, 0, 1 - f0.h) }
    return
  }
  // a corner: the opposite corner stays where it is
  const west = drag.kind.includes('w')
  const north = drag.kind.includes('n')
  const ax = west ? f0.x + f0.w : f0.x
  const ay = north ? f0.y + f0.h : f0.y
  let w = Math.abs(p.x - ax)
  let h = Math.abs(p.y - ay)
  const roomX = west ? ax : 1 - ax
  const roomY = north ? ay : 1 - ay
  if (!isFree.value) {
    const k = frameAspect(W.value, H.value, parseRatio(active.value) || [1, 1])
    if (w / k > h) h = w / k
    else w = h * k
    const scale = Math.min(1, roomX / w, roomY / h)
    w *= scale
    h *= scale
  } else {
    w = Math.min(w, roomX)
    h = Math.min(h, roomY)
  }
  w = Math.max(w, 0.04)
  h = Math.max(h, 0.04)
  frame.value = { x: west ? ax - w : ax, y: north ? ay - h : ay, w, h }
}
function end() {
  if (!drag) return
  drag = null
  commit()
}
function onStageDown(e) {
  if (mode.value !== 'focal') return
  e.preventDefault()
  stage.value.setPointerCapture?.(e.pointerId)
  drag = { kind: 'focal' }
  setFocal(e)
}
function setFocal(e) { const p = point(e); focal.value = { x: +p.x.toFixed(4), y: +p.y.toFixed(4) } }
function stageMove(e) {
  if (drag && drag.kind === 'focal') setFocal(e)
  else move(e)
}
function stageUp() {
  if (drag && drag.kind === 'focal') { drag = null; if (!isFree.value && !crops.value[active.value]) select(active.value) } else end()
}
function nudge(e) {
  const step = e.shiftKey ? 0.05 : 0.01
  const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key]
  if (!d) return
  e.preventDefault()
  if (mode.value === 'focal') {
    const f = focal.value || { x: 0.5, y: 0.5 }
    focal.value = { x: +clamp(f.x + d[0], 0, 1).toFixed(4), y: +clamp(f.y + d[1], 0, 1).toFixed(4) }
    return
  }
  if (!frame.value) return
  frame.value = { ...frame.value, x: clamp(frame.value.x + d[0], 0, 1 - frame.value.w), y: clamp(frame.value.y + d[1], 0, 1 - frame.value.h) }
  commit()
}
function clearFocal() {
  focal.value = null
  if (!isFree.value && !crops.value[active.value]) select(active.value)
}
const setMode = (m) => { mode.value = m }

// ---- the small preview of the frame
const previewStyle = computed(() => {
  const f = frame.value
  if (!f) return {}
  const exact = isFree.value ? f : exactFrame(W.value, H.value, parseRatio(active.value) || [1, 1], f)
  return {
    aspectRatio: `${(exact.w * W.value) / (exact.h * H.value)}`,
    backgroundImage: `url("${thumbUrl(props.item.src, 640)}")`,
    backgroundSize: `${100 / exact.w}% ${100 / exact.h}%`,
    backgroundPosition: `${exact.w >= 1 ? 0 : (exact.x / (1 - exact.w)) * 100}% ${exact.h >= 1 ? 0 : (exact.y / (1 - exact.h)) * 100}%`,
  }
})
const frameLabel = computed(() => {
  if (!frame.value) return ''
  const px = (v, side) => Math.round(v * side)
  return `${px(frame.value.w, W.value)} × ${px(frame.value.h, H.value)} px`
})
const boxStyle = computed(() => frame.value ? { left: frame.value.x * 100 + '%', top: frame.value.y * 100 + '%', width: frame.value.w * 100 + '%', height: frame.value.h * 100 + '%' } : {})

async function save() {
  if (saving.value || !props.item) return
  const out = { ...crops.value }
  if (freeFrame.value) {
    const f = freeFrame.value
    out[ratioKey(approxRatio(f.w * W.value, f.h * H.value))] = roundFrame(f)
  }
  saving.value = true
  try {
    const m = await api.media.update(props.item.id, { focal: focal.value, crops: out })
    toast.show(t('forms.editor_saved'))
    emit('saved', m)
    emit('update:open', false)
  } catch (e) {
    toast.error(e.message || t('forms.editor_save_failed'))
  } finally {
    saving.value = false
  }
}
const close = () => emit('update:open', false)
</script>

<template>
  <Modal :open="open" :title="t('forms.editor_title')" :width="dialogWidth" :cls="vp.phone ? 'modal-full' : ''" @update:open="(v) => !v && close()">
    <template v-if="item">
      <p class="ie-help">
        {{ t('forms.editor_help') }}
      </p>

      <div class="ie-bar">
        <div class="ie-seg" role="group" :aria-label="t('forms.editor_what')">
          <button type="button" :class="['btn sm', { primary: mode === 'frame' }]" :aria-pressed="mode === 'frame'" @click="setMode('frame')"><Icon name="crop" size="s14" />{{ t('forms.editor_frame') }}</button>
          <button type="button" :class="['btn sm', { primary: mode === 'focal' }]" :aria-pressed="mode === 'focal'" @click="setMode('focal')"><Icon name="star" size="s14" />{{ t('forms.editor_focal') }}</button>
        </div>
        <span v-if="mode === 'focal'" class="muted ie-hint">{{ t('forms.editor_focal_hint') }}</span>
        <span v-else class="muted ie-hint">{{ vp.touch ? t('forms.editor_drag_touch') : t('forms.editor_drag') }}</span>
      </div>

      <div class="ie-ratios" role="group" :aria-label="t('forms.editor_ratio')">
        <button
          v-for="k in keys" :key="k" type="button" :class="['ie-chip', { on: active === k }]" :aria-pressed="active === k"
          @click="select(k)"
        >{{ k }}<i v-if="crops[k]" class="ie-dot" :title="t('forms.editor_manual')" /></button>
        <button type="button" :class="['ie-chip', { on: isFree }]" :aria-pressed="isFree" @click="select('free')">{{ t('forms.editor_free') }}</button>
      </div>

      <div class="ie-main">
        <div
          ref="stage" class="ie-stage" :style="stageStyle" tabindex="0" :aria-label="mode === 'focal' ? t('forms.editor_stage_focal') : t('forms.editor_stage')"
          @pointerdown="onStageDown" @pointermove="stageMove" @pointerup="stageUp" @pointercancel="stageUp" @keydown="nudge"
        >
          <img :src="src" alt="" draggable="false">
          <template v-if="frame && mode === 'frame'">
            <div class="ie-box" :style="boxStyle" @pointerdown="begin($event, 'move')">
              <span class="ie-h nw" @pointerdown="begin($event, 'nw')" />
              <span class="ie-h ne" @pointerdown="begin($event, 'ne')" />
              <span class="ie-h sw" @pointerdown="begin($event, 'sw')" />
              <span class="ie-h se" @pointerdown="begin($event, 'se')" />
            </div>
          </template>
          <div v-else-if="frame" class="ie-box ghost" :style="boxStyle" />
          <span v-if="focal" class="ie-focal" :style="{ left: focal.x * 100 + '%', top: focal.y * 100 + '%' }" aria-hidden="true" />
        </div>

        <aside class="ie-side">
          <div class="ie-pv-h">{{ t('forms.editor_result') }}</div>
          <div class="ie-pv" :style="previewStyle" role="img" :aria-label="isFree ? t('forms.editor_preview_free') : t('forms.editor_preview_ratio', { ratio: active })" />
          <div class="muted ie-meta num">{{ frameLabel }}</div>
          <p v-if="!isFree && !hasFrame" class="muted ie-meta">{{ focal ? t('forms.editor_auto') : t('forms.editor_auto_center') }}</p>
          <p v-else-if="isFree" class="muted ie-meta">{{ frame ? t('forms.editor_free_saved_as', { ratio: ratioKey(approxRatio(frame.w * W, frame.h * H)) }) : t('forms.editor_free_saved') }}</p>
          <button v-if="hasFrame" type="button" class="btn sm" @click="resetFrame"><Icon name="undo" size="s14" />{{ t('forms.editor_reset') }}</button>
          <button v-if="focal" type="button" class="btn sm" @click="clearFocal"><Icon name="x" size="s14" />{{ t('forms.editor_clear_focal') }}</button>
        </aside>
      </div>
    </template>
    <template #footer>
      <button type="button" class="btn" @click="close">{{ t('forms.cancel') }}</button>
      <button type="button" class="btn primary" :disabled="saving || (!dirty && !freeFrame)" @click="save"><Icon name="save" size="s16" />{{ t('forms.save') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.ie-help{margin:0 0 12px;color:var(--ink-2);line-height:1.45;max-width:66ch}
.ie-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.ie-seg{display:flex;gap:6px}
.ie-hint{font-size:var(--t-sm)}
.ie-ratios{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.ie-chip{height:30px;padding:0 12px;border:1px solid var(--hair);border-radius:15px;background:#fff;font-size:var(--t-sm);font-weight:550;display:inline-flex;align-items:center;gap:6px}
.ie-chip:hover{border-color:var(--ctl)}
.ie-chip.on{background:var(--cy);border-color:var(--cy);color:var(--brand-ink)}
.ie-dot{width:7px;height:7px;border-radius:50%;background:var(--mag)}
.ie-chip.on .ie-dot{background:#fff}
.ie-main{display:grid;grid-template-columns:minmax(0,1fr) 210px;gap:16px;align-items:start}
.ie-stage{position:relative;margin:0 auto;border-radius:var(--r);overflow:hidden;background:var(--hair-2);touch-action:none;user-select:none;max-width:100%;outline-offset:2px}
.ie-stage img{position:absolute;inset:0;width:100%;height:100%;display:block;pointer-events:none}
.ie-box{position:absolute;box-shadow:0 0 0 9999px rgba(20,24,28,.5);border:2px solid #fff;cursor:move;touch-action:none}
.ie-box.ghost{pointer-events:none;border-style:dashed}
.ie-h{position:absolute;width:16px;height:16px;background:#fff;border:2px solid var(--cy);border-radius:3px}
.ie-h.nw{left:-9px;top:-9px;cursor:nwse-resize}
.ie-h.ne{right:-9px;top:-9px;cursor:nesw-resize}
.ie-h.sw{left:-9px;bottom:-9px;cursor:nesw-resize}
.ie-h.se{right:-9px;bottom:-9px;cursor:nwse-resize}
.ie-focal{position:absolute;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;border:3px solid var(--mag);background:rgba(255,255,255,.55);box-shadow:0 0 0 2px #fff;pointer-events:none}
.ie-stage:focus-visible{outline:2px solid var(--cy)}
.ie-side{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.ie-pv-h{font-weight:620}
.ie-pv{width:100%;max-height:200px;border-radius:var(--r);border:1px solid var(--hair);background-color:var(--hair-2);background-repeat:no-repeat}
.ie-meta{font-size:var(--t-sm);margin:0;line-height:1.4}
@media (pointer:coarse){
  .ie-h{width:22px;height:22px}
  .ie-h::after{content:"";position:absolute;inset:-12px}
  .ie-h.nw{left:-12px;top:-12px}.ie-h.ne{right:-12px;top:-12px}.ie-h.sw{left:-12px;bottom:-12px}.ie-h.se{right:-12px;bottom:-12px}
  .ie-focal{width:30px;height:30px;margin:-15px 0 0 -15px}
}
@media (max-width:767px){
  .ie-chip{height:44px;padding:0 16px}
  .ie-seg{width:100%}.ie-seg .btn{flex:1}
  .ie-stage{margin:6px auto 14px}
}
@media (max-width:767px){.ie-main{grid-template-columns:1fr}.ie-side{flex-direction:row;flex-wrap:wrap;align-items:center}.ie-pv{width:140px}}
</style>
