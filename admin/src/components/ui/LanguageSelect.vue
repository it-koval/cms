<script setup>
// Content-language select: a trigger («RU · Русский» + chevron) that opens a dropdown (Popover) with one row per site language. It replaces every row of
// segmented language buttons, so the control stays the same size with 2 or 20 languages; the list scrolls when it is long. Only for sites with 2+ languages (the caller checks isMulti()).
//   <LanguageSelect :model-value="app.locale" :languages="app.languages.list" label="Язык контента" show-label variant="rail" @update:model-value="switchContentLocale" />
//   <LanguageSelect ref="sel" :model-value="LOC" :languages="app.languages.list" :statuses="states" label="Язык записи" @update:model-value="pickLang" />
// statuses (optional): { [code]: 'published' | 'changed' | 'draft' | 'missing' | { key, count } } — a dot in the trigger and a dot + word in every row
//   (the same four dots everywhere: green = опубликована, magenta = есть правки (count = how many), magenta ring = черновик, grey ring = перевода нет).
// The select does not switch anything itself: it emits update:modelValue (the caller decides, e.g. waits for a save). Keyboard: Enter / Space / ArrowDown open it on the selected row,
// ArrowUp / ArrowDown / Home / End move, Enter picks, Esc (or Tab) closes; the focus always comes back to the trigger. focus() is exposed (a screen re-mounted by a switch hands the focus back).
import { computed, ref } from 'vue'
import Icon from './Icon.vue'
import Popover from './Popover.vue'
import { langName, tag } from '@/utils/locales.js'
import { STATE_LABEL } from '@/utils/langWords.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  modelValue: { type: String, required: true }, // the picked language code
  languages: { type: Array, required: true }, // [{ code, name }]
  statuses: { type: Object, default: null },
  label: { type: String, default: () => t('ui.language') }, // aria-label of the trigger and the menu; shown above the trigger with show-label
  showLabel: { type: Boolean, default: false },
  variant: { type: String, default: 'field' }, // 'field' (light, next to buttons) | 'rail' (compact, full width, on the cyan rail)
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const btn = ref(null)
const popW = ref(0)

function state(code) {
  if (!props.statuses) return null
  const v = props.statuses[code]
  const o = typeof v === 'string' ? { key: v } : v || {}
  const key = STATE_LABEL[o.key] ? o.key : 'missing'
  return { key, text: STATE_LABEL[key] + (key === 'changed' && o.count ? ` · ${o.count}` : '') }
}
const cur = computed(() => props.languages.find((l) => l.code === props.modelValue) || { code: props.modelValue, name: langName(props.modelValue) })
const curState = computed(() => state(cur.value.code))
const items = computed(() => props.languages.map((l) => ({ code: l.code, name: l.name, tag: tag(l.code), on: l.code === props.modelValue, st: state(l.code) })))
const name = computed(() => `${props.label}: ${cur.value.name}${curState.value ? ', ' + curState.value.text.toLowerCase() : ''}`)

function show() {
  if (props.disabled) return
  popW.value = props.variant === 'rail' && btn.value ? btn.value.offsetWidth : 0 // in the rail the list is as wide as the trigger
  open.value = true
}
const toggle = () => (open.value ? (open.value = false) : show())
function pick(code) {
  open.value = false
  if (btn.value) btn.value.focus({ preventScroll: true })
  if (code !== props.modelValue) emit('update:modelValue', code)
}
// the Popover moves the focus with ArrowUp / ArrowDown; Home / End and Tab are ours
function onOptKey(e) {
  if (e.key === 'Tab') { e.preventDefault(); open.value = false; if (btn.value) btn.value.focus({ preventScroll: true }); return }
  if (e.key !== 'Home' && e.key !== 'End') return
  const rows = e.currentTarget.parentElement.querySelectorAll('button.mi')
  if (rows.length) rows[e.key === 'Home' ? 0 : rows.length - 1].focus()
  e.preventDefault()
}
defineExpose({ focus: () => btn.value && btn.value.focus({ preventScroll: true }) })
</script>

<template>
  <div :class="['ls', 'ls-' + variant]">
    <span v-if="showLabel" class="ls-lb" aria-hidden="true">{{ label }}</span>
    <button
      ref="btn" type="button" class="ls-btn" aria-haspopup="menu" :aria-expanded="open" :aria-label="name" :disabled="disabled"
      @click="toggle" @keydown.down.prevent="show" @keydown.up.prevent="show"
    >
      <i v-if="curState" :class="['ls-dot', curState.key]" aria-hidden="true" />
      <b class="ls-tag">{{ tag(cur.code) }}</b><span class="ls-nm">{{ cur.name }}</span>
      <Icon name="chevD" size="s14" class="ls-ch" />
    </button>
    <Popover v-model:open="open" :anchor="btn" align="left" :width="popW" :label="label" cls="ls-pop">
      <button
        v-for="o in items" :key="o.code" type="button" role="menuitemradio" :aria-checked="o.on" :data-autofocus="o.on ? '' : undefined"
        :class="['mi', 'ls-o', { cur: o.on }]" @click="pick(o.code)" @keydown="onOptKey"
      >
        <span class="ls-ck" aria-hidden="true"><Icon v-if="o.on" name="check" size="s14" /></span>
        <b class="ls-tag">{{ o.tag }}</b><span class="ls-nm">{{ o.name }}</span>
        <span v-if="o.st" class="ls-st"><i :class="['ls-dot', o.st.key]" aria-hidden="true" />{{ o.st.text }}</span>
      </button>
    </Popover>
  </div>
</template>

<style>
.ls { display: inline-flex; flex-direction: column; align-items: stretch; min-width: 0; }
.ls-lb { font-size: var(--t-xs); color: var(--ink-2); margin-bottom: 5px; }
.ls-btn {
  display: inline-flex; align-items: center; gap: 8px; height: 34px; padding: 0 10px 0 12px; max-width: 100%; border: 1px solid var(--hair); border-radius: var(--r);
  background: var(--sheet); box-shadow: var(--sh-1); color: var(--ink); font-size: var(--t-base); font-weight: 550; text-align: left;
  transition: background var(--dur), border-color var(--dur), color var(--dur);
}
.ls-btn:hover { background: var(--sheet-2); border-color: var(--ctl); }
.ls-btn[aria-expanded="true"] { border-color: var(--cy); background: var(--cy-50); color: var(--cy-dd); }
.ls-btn[disabled] { background: var(--hair-2); border-color: var(--hair-2); color: #7B8A94; box-shadow: none; cursor: not-allowed; }
.ls-tag { font-weight: 700; font-size: var(--t-sm); letter-spacing: .03em; flex: none; }
.ls-btn .ls-tag::after { content: "·"; margin-left: 8px; font-weight: 400; color: var(--ink-3); }
.ls-nm { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ls-ch { margin-left: 2px; color: var(--ink-3); transition: transform var(--dur); }
.ls-btn[aria-expanded="true"] .ls-ch { transform: rotate(180deg); }
.ls-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; box-sizing: border-box; background: var(--green); }
.ls-dot.changed { background: var(--mag); }
.ls-dot.draft { background: transparent; border: 1.5px solid var(--mag); }
.ls-dot.missing { background: transparent; border: 1.5px solid var(--ink-3); }

/* on the cyan rail: compact, as wide as the rail */
.ls.ls-rail { display: flex; }
.ls.ls-rail .ls-lb { color: var(--rail-ink-2); }
.ls.ls-rail .ls-btn { width: 100%; height: 32px; padding: 0 8px 0 10px; border: 0; background: var(--rail-shade); box-shadow: none; color: var(--brand-ink); font-size: var(--t-sm); font-weight: 560; }
.ls.ls-rail .ls-btn:hover, .ls.ls-rail .ls-btn[aria-expanded="true"] { background: var(--rail-shade-2); color: var(--brand-ink); }
.ls.ls-rail .ls-btn .ls-tag::after { color: var(--rail-ink-2); }
.ls.ls-rail .ls-nm { flex: 1; }
.ls.ls-rail .ls-ch { margin-left: auto; color: var(--rail-ink-2); }

/* the dropdown (a Popover): the list scrolls when there are many languages */
.pop.ls-pop { min-width: 220px; max-height: min(50vh, 320px); overflow-y: auto; }
.pop button.mi.ls-o { gap: 8px; }
.ls-ck { width: 16px; flex: none; display: grid; place-items: center; color: var(--brand-text); }
.ls-o .ls-tag { min-width: 24px; color: var(--ink-2); }
.ls-o.cur .ls-tag { color: var(--brand-text); }
.ls-o .ls-nm { flex: 1; }
.ls-st { display: inline-flex; align-items: center; gap: 6px; margin-left: 14px; color: var(--ink-3); font-size: var(--t-xs); font-weight: 500; white-space: nowrap; }
</style>
