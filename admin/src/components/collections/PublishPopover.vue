<script setup>
// «Что будет опубликовано»: the list of unpublished changes and the confirm button.
//   <PublishPopover v-model:open="o" :anchor="btnEl" :changes="[{ label, val, old }]" :busy="b" @confirm="publish" />
// `send`: the role may not publish — the same list says «Что уйдёт на публикацию» and the button is «Отправить на публикацию» (the screen then calls submit instead of publish).
// On a site with 2+ languages pass `lang-code`: the popover then says which language version goes live («Опубликовать английскую версию») and that the others are not touched.
import { computed } from 'vue'
import Popover from '@/components/ui/Popover.vue'
import { t } from '@/i18n/index.js'
import { cap, verAcc, verGen } from '@/utils/langWords.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  anchor: { type: Object, default: null },
  changes: { type: Array, default: () => [] },
  busy: { type: Boolean, default: false },
  subject: { type: String, default: '' }, // «в таблице»
  limit: { type: Number, default: 8 },
  langCode: { type: String, default: '' }, // the language version being published ('' on a one-language site)
  send: { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'confirm'])
const shown = computed(() => props.changes.slice(0, props.limit))
const rest = computed(() => Math.max(0, props.changes.length - props.limit))
const n = computed(() => props.changes.length)
const summary = computed(() => (props.langCode
  ? cap(t('collections.pp_count_lang', { count: n.value, version: verGen(props.langCode) }))
  : props.subject ? cap(t('collections.pp_count_subject', { count: n.value, subject: props.subject })) : t('collections.pp_count', { count: n.value })))
</script>

<template>
  <Popover :open="open" :anchor="anchor" align="right" :width="langCode ? 440 : 380" role="dialog" @update:open="emit('update:open', $event)">
    <div class="pp">
      <div class="pp-h">
        <b>{{ send ? t('collections.pp_send') : t('collections.pp_title') }}</b>
        <div class="muted pp-s">{{ summary }}</div>
        <div v-if="send" class="muted pp-s pp-o">{{ t('collections.pp_role_note') }}</div>
      </div>
      <ul class="pp-l">
        <li v-for="(c, i) in shown" :key="i">
          <span class="pmark" aria-hidden="true"><i /></span>
          <span>
            <b>{{ c.label }}</b>
            <template v-if="c.val || c.old"><br><span class="muted"><template v-if="c.old"><s>{{ c.old }}</s> → </template>{{ c.val }}</span></template>
          </span>
        </li>
        <li v-if="rest" class="more muted">{{ t('collections.pp_more', { count: rest }) }}</li>
      </ul>
      <div class="pp-f">
        <button type="button" class="btn" @click="emit('update:open', false)">{{ t('collections.not_now') }}</button>
        <button type="button" :class="['btn primary', { loading: busy }]" @click="emit('confirm')">{{ send ? t('collections.pp_send') : langCode ? cap(t('collections.pp_publish_version', { version: verAcc(langCode) })) : t('collections.pp_publish_all') }}</button>
      </div>
    </div>
  </Popover>
</template>

<style scoped>
.pp-h { padding: 12px 14px 8px; }
.pp-s { font-size: var(--t-sm); }
.pp-o { margin-top: 6px; }
.pp-l { list-style: none; margin: 0; padding: 0; max-height: 280px; overflow: auto; border-top: 1px solid var(--hair-2); border-bottom: 1px solid var(--hair-2); }
.pp-l li { display: grid; grid-template-columns: 14px 1fr; gap: 8px; padding: 7px 14px; font-size: var(--t-sm); align-items: start; }
.pp-l li .pmark { margin-top: 5px; }
.pp-l li b { font-weight: 620; }
.pp-l li.more { display: block; padding-left: 36px; }
.pp-f { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 14px; }
</style>
