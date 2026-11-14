<script setup>
// One colour of «Оформление админки»: a colour picker, the hex written out, the palette 50–950 the server made from it and the check «can text be read on it».
// The palette and the check come from the server (AdminTheme: OKLCH), so the picture here is exactly what a save would apply.
//   v-model  '#RRGGBB'     check { ink, ratio, level, ok, white, dark, text, textAdjusted }     ladder { 50: '#…', … }     error  text under the field
import { computed, ref, watch } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { formatNumber, t } from '@/i18n/index.js'

const props = defineProps({
  modelValue: { type: String, required: true },
  label: { type: String, required: true },
  help: { type: String, default: '' },
  id: { type: String, required: true },
  check: { type: Object, default: null },
  ladder: { type: Object, default: null },
  error: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const text = ref(props.modelValue)
const slip = ref('') // what was typed when it was not a colour (shown until the next edit)
// the field is not rewritten while the person types: «#FFD» is not turned into «#FFDDFF» under the cursor
watch(() => props.modelValue, (v) => { if (full(text.value) !== v) text.value = v })

const LIVE = /^#?[0-9a-f]{6}$/i // a colour is applied as soon as it has six digits
const SHORT = /^#?[0-9a-f]{3}$/i // ... the short form #abc when the field is left
function full(v) {
  let h = String(v).trim().replace(/^#/, '')
  if (h.length === 3) h = h.split('').map((c) => c + c).join('')
  return '#' + h.toUpperCase()
}
function onText(e) {
  text.value = e.target.value
  slip.value = ''
  if (LIVE.test(text.value.trim())) emit('update:modelValue', full(text.value))
}
function onBlur() {
  const v = text.value.trim()
  if (LIVE.test(v) || SHORT.test(v)) {
    emit('update:modelValue', full(v))
    text.value = full(v)
  } else {
    if (v) slip.value = t('settings.theme_color_slip', { value: v, kept: props.modelValue })
    text.value = props.modelValue
  }
}
const onPick = (e) => { slip.value = ''; emit('update:modelValue', e.target.value.toUpperCase()) }

const ratio = computed(() => (props.check ? formatNumber(props.check.ratio, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : ''))
const adjusted = t('settings.theme_text_adjusted').split('{color}') // the colour sits between the two halves of the sentence
const steps = computed(() => (props.ladder ? Object.entries(props.ladder) : []))
</script>

<template>
  <div :class="['field', 'tc', { 'is-error': error || (check && !check.ok) }]">
    <label :for="id + '-hex'">{{ label }}</label>
    <div v-if="help" class="help">{{ help }}</div>
    <div class="tc-row">
      <input :id="id" class="tc-pick" type="color" :value="modelValue.toLowerCase()" :disabled="disabled" :aria-label="t('settings.theme_color_pick', { label })" @input="onPick">
      <input :id="id + '-hex'" class="tc-hex" type="text" :value="text" maxlength="7" spellcheck="false" autocomplete="off" :disabled="disabled" @input="onText" @blur="onBlur">
      <span v-if="check" class="tc-sample" :style="{ background: modelValue, color: check.ink }" aria-hidden="true">{{ t('settings.theme_sample') }}</span>
    </div>
    <ul v-if="steps.length" class="tc-pal" :aria-label="t('settings.theme_palette_aria', { label })">
      <li v-for="[step, hex] in steps" :key="step" :title="`${step} · ${hex}`">
        <span class="tc-sw" :style="{ background: hex }" /><span class="tc-n">{{ step }}</span>
      </li>
    </ul>
    <div v-if="slip" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ slip }}</span></div>
    <div v-else-if="error" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span></div>
    <div v-else-if="check && check.ok" class="tc-check ok"><Icon name="check" size="s16" /><span>{{ check.ink === '#FFFFFF' ? t('settings.theme_readable_white', { ratio }) : t('settings.theme_readable_dark', { ratio }) }}</span></div>
    <div v-else-if="check" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ t('settings.theme_unreadable', { ratio }) }}</span></div>
    <div v-if="check && check.ok && check.textAdjusted" class="help tc-note">{{ adjusted[0] }}<span class="tc-dot" :style="{ background: check.text }" aria-hidden="true" /> {{ check.text }}{{ adjusted[1] }}</div>
  </div>
</template>

<style scoped>
.tc-row{display:flex;align-items:center;gap:10px}
.tc-pick{width:44px;height:36px;padding:2px;border:1px solid var(--ctl);border-radius:var(--r);background:#fff;cursor:pointer;flex:none}
.tc-pick:disabled{cursor:not-allowed;opacity:.6}
.tc-hex{width:120px!important;font-variant-numeric:tabular-nums;text-transform:uppercase}
.tc-sample{display:inline-grid;place-items:center;width:44px;height:36px;border-radius:var(--r);font-weight:650;border:1px solid var(--hair)}
.tc-pal{list-style:none;margin:6px 0 0;padding:0;display:grid;grid-template-columns:repeat(11,minmax(0,1fr));gap:3px;max-width:520px}
.tc-pal li{display:flex;flex-direction:column;align-items:center;gap:3px;min-width:0}
.tc-sw{display:block;width:100%;height:28px;border-radius:4px;box-shadow:inset 0 0 0 1px rgba(20,24,28,.1)}
.tc-n{font-size:var(--t-xs);color:var(--ink-3);font-variant-numeric:tabular-nums}
.tc-check{display:flex;align-items:flex-start;gap:6px;font-size:var(--t-sm);color:var(--green);margin-top:4px;line-height:1.4}
.tc-check .ic{margin-top:2px;flex:none}
.tc-note{margin-top:2px}
.tc-dot{display:inline-block;width:10px;height:10px;border-radius:50%;vertical-align:-1px;box-shadow:inset 0 0 0 1px rgba(20,24,28,.15)}
@media (max-width:767px){.tc-n{display:none}}
</style>
