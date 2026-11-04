<script setup>
// How a page looks in a list (an article in a blog, a product in a catalogue): a small card made from the template's `card` fields, live with the form.
// Client content is set in the site's face (Manrope), never in the admin's (Golos Text).
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { t } from '@/i18n/index.js'
import { fmtD } from '@/utils/format.js'
import { PREVIEW, thumbUrl } from '@/utils/media.js'
import { sanitizeHtml } from '@/utils/html.js'
import { listingSwitch } from '@/schema/templates.js'

const props = defineProps({
  tpl: { type: Object, required: true },
  fields: { type: Object, default: () => ({}) },
  title: { type: String, default: '' },
  parent: { type: String, default: '' }, // name of the parent page («Новости»): the line above an article's title
})
const c = computed(() => props.tpl.card || {})
const f = computed(() => props.fields || {})
const img = computed(() => f.value[c.value.image])
const name = computed(() => f.value[c.value.title] || props.title || t('editor.untitled'))
const text = computed(() => sanitizeHtml(f.value[c.value.text], 'rich'))
const kicker = computed(() => (c.value.date ? [props.parent, f.value[c.value.date] ? fmtD(f.value[c.value.date]) : ''].filter(Boolean).join(' · ') : f.value.info || ''))
const listSwitch = computed(() => listingSwitch(props.tpl.key)) // the switch that lists the page in the list of its parent page
const hidden = computed(() => !!listSwitch.value && listSwitch.value.key in f.value && !f.value[listSwitch.value.key]) // and it is off
</script>

<template>
  <div class="tcard">
    <div class="tc-cap">{{ t('editor.card_caption') }}</div>
    <div class="tc-box">
      <img v-if="img && img.src" class="tc-img" :src="thumbUrl(img.src, PREVIEW)" alt="" :class="{ contain: !c.date }">
      <div v-else class="tc-ph"><Icon name="image" size="s20" />{{ t('editor.card_no_photo') }}</div>
      <div class="tc-b">
        <div v-if="kicker" class="tc-kick">{{ kicker }}</div>
        <div class="tc-t">{{ name }}</div>
        <div v-if="text" class="tc-p tc-rich" v-html="text" />
        <div v-else class="tc-p">{{ t('editor.card_summary') }}</div>
        <span v-if="f.btn" class="tc-btn">{{ f.btn }}</span>
      </div>
    </div>
    <div v-if="hidden" class="alert warn-a"><Icon name="eyeOff" size="s16" /><span>{{ t('editor.card_hidden', { label: listSwitch.label }) }}</span></div>
  </div>
</template>

<style scoped>
.tcard { display: flex; flex-direction: column; gap: 8px; margin: 14px 0 6px; font-family: var(--font-site); }
.tc-cap { font-family: var(--font-chrome); font-size: var(--t-xs); font-weight: 620; color: var(--ink-2); }
.tc-box { border: 1px solid var(--hair); border-radius: 8px; overflow: hidden; background: #fff; color: var(--ink); }
.tc-img { width: 100%; display: block; background: #fff; aspect-ratio: 16/9; object-fit: cover; }
.tc-img.contain { aspect-ratio: 4/3; object-fit: contain; }
.tc-ph { aspect-ratio: 16/9; background: var(--hair-2); color: var(--ink-3); display: flex; flex-direction: column; gap: 4px; align-items: center; justify-content: center; font-size: var(--t-sm); font-family: var(--font-chrome); }
.tc-b { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 4px; }
.tc-kick { font-size: var(--t-xs); font-weight: 650; text-transform: uppercase; letter-spacing: .03em; color: var(--ink-2); }
.tc-t { font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.tc-p { font-size: var(--t-sm); color: var(--ink-2); line-height: 1.4; overflow-wrap: anywhere; }
.tc-rich :deep(p), .tc-rich :deep(ul), .tc-rich :deep(ol) { margin: 0; }
.tc-rich :deep(:is(p, ul, ol) + :is(p, ul, ol)) { margin-top: .5em; }
.tc-rich :deep(ul), .tc-rich :deep(ol) { padding-left: 1.2em; }
.tc-btn { align-self: flex-start; margin-top: 8px; padding: 6px 14px; border-radius: 2em; background: #000; color: #fff; font-size: var(--t-sm); font-weight: 700; }
</style>
