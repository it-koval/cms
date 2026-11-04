<script setup>
// «Опубликовать»: the list of unpublished changes (each row jumps to the place), errors that block publishing,
// «Опубликовать всё» (api.pages.publish) and «Отменить все правки» (api.pages.revert).
// Multilingual sites: everything here acts on the language version on screen only, and the wording says which («Опубликовать английскую версию»);
// the other versions are published separately — a line tells if they have unpublished work of their own.
// A role without «Публиковать» sees the same list with «Отправить на публикацию» (ed.submit): the version then waits for somebody who may publish it.
import { computed, inject, nextTick, ref, watch } from 'vue'
import Popover from '@/components/ui/Popover.vue'
import Icon from '@/components/ui/Icon.vue'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { NO_H1_NOTICE, sectionName } from '@/schema/sections.js'
import { app } from '@/stores/app.js'
import { isMulti } from '@/utils/locales.js'
import { cap, version } from '@/utils/langWords.js'
import { stateOf } from '@/components/pages/langWords.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'

const props = defineProps({ open: { type: Boolean, default: false }, anchor: { type: Object, default: null } })
const emit = defineEmits(['update:open'])
const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
const confirm = useConfirm()
const busy = ref(false)
const confirmBtn = ref(null)

const multi = computed(() => isMulti())
const sendMode = computed(() => !ed.canPublish)
const ver = computed(() => version(ed.locale))
const n = computed(() => ed.changes.length)
/** other language versions with work of their own that this button does NOT publish: «русская версия — 2 правки» */
const others = computed(() => app.languages.list
  .filter((l) => l.code !== ed.locale && ed.locales[l.code])
  .map((l) => ({ code: l.code, v: ed.locales[l.code], s: stateOf(ed.locales[l.code]) }))
  .filter((o) => o.s.key === 'draft' || o.s.key === 'changed')
  .map((o) => (o.s.key === 'draft' ? t('editor.pub_other_draft', { ver: version(o.code).nom }) : t('editor.pub_other_edits', { ver: version(o.code).nom, count: o.v.changes }))))
const rows = computed(() => ed.changes.slice(0, 12))
const first = computed(() => ed.errors[0] || null)
/** A never published page: what goes live, in words («3 секции и поля шаблона «Статья блога»»), as a whole sentence about the page. */
const freshText = computed(() => {
  const count = ed.sections.length
  const sections = !!(count || !ed.hasFields)
  const what = sections ? (ed.hasFields ? 'both' : 'sections') : 'tpl'
  return cap(t(`editor.pub_fresh${multi.value ? '_multi' : ''}_${what}`, { ver: ver.value.nom, title: ed.pageTitle, url: ed.page.url, count, name: ed.template ? ed.template.name : '' }))
})
/** The changes of the page, as a whole sentence about it. */
const changesText = computed(() => t(multi.value ? 'editor.pub_changes_multi' : 'editor.pub_changes', { count: n.value, ver: ver.value.gen, title: ed.pageTitle }))
/** Which languages besides this one have work of their own. */
const othersText = computed(() => (others.value.length ? t('editor.pub_only', { ver: ver.value.nom, others: others.value.join('; ') }) : t('editor.pub_only_alone', { ver: ver.value.nom })))
const headText = computed(() => {
  if (sendMode.value) return multi.value ? t('editor.submit_multi', { ver: ver.value.nom }) : t('editor.submit')
  if (multi.value) return fresh.value ? t('editor.pub_first_multi', { ver: ver.value.nom }) : t('editor.pub_what_multi', { ver: ver.value.gen })
  return fresh.value ? t('editor.pub_first') : t('editor.pub_what')
})
const fresh = computed(() => !ed.published)
const close = () => emit('update:open', false)

// the Popover focuses its first button; the main action is the better start
watch(() => props.open, (v) => { if (v) setTimeout(() => nextTick(() => confirmBtn.value && !confirmBtn.value.disabled && confirmBtn.value.focus({ preventScroll: true })), 0) })

function jump(c) {
  close()
  ui.gotoChange(c)
}
function showError() {
  if (!first.value) return
  close()
  ui.gotoError(first.value)
}
async function publish() {
  busy.value = true
  const r = sendMode.value ? await ed.submit() : await ed.publish()
  busy.value = false
  if (r.ok) close()
  else if (r.error) {
    // the API refused: show the first empty required field
    close()
    if (r.error.scope === 'fields') { ui.gotoError({ scope: 'fields', path: r.error.path, field: r.error.field }); return }
    const sec = ed.secById(r.error.sectionId)
    if (sec) ui.gotoError({ section: sec, path: r.error.path, field: r.error.field })
  }
}
async function revert() {
  const ok = await confirm({
    title: multi.value ? t('editor.revert_title_multi', { ver: ver.value.gen }) : t('editor.revert_title'),
    message: multi.value ? t('editor.revert_msg_multi', { ver: cap(ver.value.nom) }) : t('editor.revert_msg'),
    confirmLabel: t('editor.revert_confirm'), cancelLabel: t('editor.revert_keep'), danger: true,
  })
  if (!ok) return
  close()
  await ed.revertAll()
}
</script>

<template>
  <Popover :open="open" :anchor="anchor" align="right" :width="400" role="dialog" cls="pub-pop" @update:open="emit('update:open', $event)">
    <div class="pp-h">
      <b>{{ headText }}</b>
      <div v-if="sendMode" class="muted pp-s">{{ t('editor.pub_send_note') }}</div>
      <div class="muted pp-s">
        <template v-if="fresh">{{ freshText }}</template>
        <template v-else>{{ changesText }}</template>
      </div>
      <div v-if="multi" class="muted pp-s pp-o">{{ othersText }}</div>
    </div>
    <ul class="pp-l">
      <li v-for="(c, i) in rows" :key="i">
        <button type="button" class="chg-b" :disabled="!c.sec && c.kind !== 'meta' && c.kind !== 'field'" @click="jump(c)">
          <span class="pmark" aria-hidden="true"><i /></span>
          <span>
            <b>{{ c.name }}</b> · {{ c.label }}
            <template v-if="c.val"><br><span class="muted"><template v-if="c.old"><s>{{ c.old }}</s> → </template>{{ c.val }}</span></template>
          </span>
        </button>
      </li>
      <li v-if="n > 12" class="muted pp-more">{{ t('editor.pub_more', { count: n - 12 }) }}</li>
    </ul>
    <div v-if="!ed.hasH1" class="alert warn-a pp-err" role="status">
      <Icon name="alert" size="s16" />
      <span>{{ NO_H1_NOTICE }} {{ t('editor.pub_h1_ok') }}</span>
    </div>
    <div v-if="first" class="alert err-a pp-err">
      <Icon name="alert" size="s16" />
      <span>
        <b>{{ sendMode ? t('editor.pub_cant_send') : t('editor.pub_cant_publish') }}</b>
        <template v-if="first.scope === 'fields'"> {{ t('editor.pub_err_tpl', { name: ed.template.name, field: first.field.label }) }}</template>
        <template v-else> {{ t('editor.pub_err_section', { name: sectionName(first.section.type), field: first.field.label }) }}</template>
        <button type="button" class="revert" @click="showError">{{ t('editor.pub_show') }}</button>
      </span>
    </div>
    <div class="pp-f">
      <button type="button" class="btn" @click="close">{{ t('editor.pub_not_now') }}</button>
      <button ref="confirmBtn" type="button" class="btn primary" :class="{ loading: busy }" :disabled="!!first || !n" @click="publish">{{ sendMode ? t('editor.submit') : multi ? t('editor.pub_all_multi', { ver: ver.acc }) : t('editor.pub_all') }}</button>
    </div>
    <div v-if="ed.published && n" class="pp-r">
      <button type="button" class="btn ghost danger sm" @click="revert"><Icon name="undo" size="s14" />{{ multi ? t('editor.revert_btn_multi', { ver: ver.gen }) : t('editor.revert_btn') }}</button>
    </div>
  </Popover>
</template>

<style scoped>
.pp-h { padding: 12px 14px 6px; }
.pp-s { font-size: var(--t-sm); margin-top: 2px; }
.pp-o { margin-top: 6px; }
.pp-l { list-style: none; margin: 0; padding: 0; max-height: 260px; overflow: auto; border-top: 1px solid var(--hair-2); border-bottom: 1px solid var(--hair-2); }
.pp-l b { font-weight: 620; }
.pp-more { padding: 7px 14px; font-size: var(--t-sm); }
.pp-err { margin: 10px 12px 0; }
.pp-err .revert { color: var(--ink); }
.pp-f { display: flex; gap: 8px; align-items: center; justify-content: flex-end; padding: 12px 14px; }
.pp-r { padding: 0 8px 8px; border-top: 1px solid var(--hair-2); padding-top: 8px; }
</style>
