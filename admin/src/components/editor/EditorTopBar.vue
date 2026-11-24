<script setup>
// Top bar of the page editor: ← Страницы, page name with a dropdown of pages, status chip, undo / redo, save state,
// Компьютер / Телефон, «Структура», Предпросмотр, «Опубликовать» with the change count.
// Multilingual sites: a language select with status dots next to the page name (PageLangSelect), «Удалить <язык> версию» in the page menu (never for the main language),
// and the publish button acts on the language on screen. A page without a version in that language shows only the left part (back, name, language select).
// RIGHTS: without «Править» there is no undo / save state / publish (the page is only looked at); without «Публиковать» the main button says «Отправить на публикацию» (and «Ждёт публикации» once sent);
// a version that waits gets an amber chip, and the person who may publish it gets «Вернуть автору» next to «Опубликовать».
// Plugins of the site add buttons here (window.ITK.registerPlugin({ editorActions }), plugins/state.js): after «Предпросмотр», in «⋯» on a phone; the autosave is flushed before one runs.
import { computed, inject, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import Popover from '@/components/ui/Popover.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import PublishPopover from './PublishPopover.vue'
import PageLangSelect from './PageLangSelect.vue'
import HistoryDrawer from '@/components/history/HistoryDrawer.vue'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { toast } from '@/components/ui/useToast.js'
import { http } from '@/api/http.js'
import { pluginEditorActions } from '@/plugins/state.js'
import { app, can } from '@/stores/app.js'
import { isMain, isMulti } from '@/utils/locales.js'
import { versionNone } from '@/components/pages/langWords.js'
import { cap, homeHeldText, onLang, version } from '@/utils/langWords.js'
import { homeVersionHeld } from '@/components/pages/pageRules.js'
import { getTemplate } from '@/schema/templates.js'
import { clone } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'
import { vp } from '@/utils/viewport.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
const router = useRouter()
const confirm = useConfirm()

const pgBtn = ref(null)
const pgOpen = ref(false)
const pubBtn = ref(null)
const pubOpen = ref(false)
const histOpen = ref(false)

const multi = computed(() => isMulti())
const ver = computed(() => version(ed.locale))
const n = computed(() => ed.changes.length)
const canRemoveVersion = computed(() => multi.value && !isMain(ed.locale) && !!ed.page && ed.canDelete)
const submitMode = computed(() => !ed.canPublish) // «Отправить на публикацию» instead of «Опубликовать»
// Removing a home translation is blocked while that language has other pages on the site.
const held = computed(() => canRemoveVersion.value && homeVersionHeld(ed.pages, ed.shell, ed.locale))
// an article or a product goes back to its flat list («Статьи блога», «Товары»), any other page to «Страницы»
const backTo = computed(() => { const t = ed.template; return t && t.shortcut && getTemplate(t.key) ? { path: '/pages', query: { template: t.key } } : '/pages' })
const backLabel = computed(() => (ed.template && ed.template.shortcut) || t('editor.back_pages'))
const saveText = computed(() => (ed.save === 'busy' ? t('editor.save_busy') : ed.save === 'error' ? t('editor.save_error') : t('editor.save_done')))

/* a PHONE: two rows. Row 1: back, name with its state, «⋯», publish. Row 2: «Форма | Предпросмотр» and undo / redo. Everything else is in «⋯» */
const moreBtn = ref(null)
const moreOpen = ref(false)
const STATE = { published: t('editor.state_published'), draft: t('editor.state_draft'), changed: t('editor.state_changed') }
const stateText = computed(() => (ed.awaiting ? t('editor.state_awaiting') : STATE[ed.status] || ''))
const subText = computed(() => (ed.readOnly ? t('editor.view_only') : ed.save === 'saved' ? stateText.value || saveText.value : saveText.value))
const pubText = computed(() => (!submitMode.value ? t('editor.publish') : ed.awaiting ? t('editor.pub_waits') : t('editor.pub_send_short')))
const inMore = (fn) => { moreOpen.value = false; return fn() }

/* the page list as a tree: parents first, children indented */
const rows = computed(() => {
  const out = []
  const list = ed.pages.filter((p) => can('pages', 'view', { template: p.template })) // only pages the role may open
  const walk = (parent, lvl) => list.filter((p) => (p.parentId || null) === parent).forEach((p) => { out.push([p, lvl]); walk(p.id, lvl + 1) })
  walk(null, 0)
  list.filter((p) => !out.some(([x]) => x.id === p.id)).forEach((p) => out.push([p, 0])) // orphans
  return out
})
function go(p) {
  pgOpen.value = false
  if (p.id === ed.pageId) return
  router.push(`/pages/${p.id}`)
}
async function removeVersion() {
  pgOpen.value = false
  const live = !!ed.published
  const title = ed.page.title
  const ok = await confirm({
    title: t('editor.remove_title', { ver: ver.value.acc, title }),
    message: live ? t('editor.remove_msg_live', { lang: onLang(ed.locale), url: ed.page.url }) : t('editor.remove_msg_draft'),
    confirmLabel: t('editor.remove_version', { ver: ver.value.acc }), danger: true,
  })
  if (!ok) return
  if (await ed.removeVersion()) toast.show(t('editor.removed_toast', { ver: cap(ver.value.nom), title, main: cap(version(app.languages.main).nom) }))
}
function settings() {
  pgOpen.value = false
  ui.openSettings()
}
function openPublish() {
  if (!n.value || (submitMode.value && ed.awaiting)) return
  pubOpen.value = !pubOpen.value
}
/* buttons of the plugins of the site */
// `page` is a copy of the draft taken when it is read (not on every keystroke): a plugin cannot change the editor's own object through it
const pluginCtx = () => ({
  pageId: ed.pageId, locale: ed.locale, title: ed.pageTitle, status: ed.status, template: ed.tplKey, readOnly: ed.readOnly,
  get page() { return clone(ed.page) },
  flush: () => ed.flushAll(), reload: () => ed.reloadDraft(), toast, http,
})
const pluginButtons = computed(() => {
  if (!ed.page) return []
  const ctx = pluginCtx()
  return pluginEditorActions().filter((a) => {
    if (!a.when) return true
    try { return !!a.when(ctx) } catch (e) { console.error(`[ITK] ${t('editor.plugin_when_failed', { name: a.name })}`, e); return false }
  })
})
async function runPlugin(a) {
  try {
    await ed.flushAll()
    await a.run(pluginCtx())
  } catch (e) {
    toast.error((e && e.message) || t('editor.plugin_failed', { label: a.label }))
  }
}

/** «История»: the last autosave goes to the server first, so the newest entry is the page as it is on screen. */
async function openHistory() {
  try {
    await ed.flushAll()
  } catch (e) {
    toast.error((e && e.message) || t('editor.err_save_page'))
    return
  }
  histOpen.value = true
}
/** A version came back from the history: the editor takes the new draft. */
async function onRestored() {
  try {
    await ed.reloadDraft()
  } catch (e) {
    toast.error((e && e.message) || t('editor.err_refresh_page'))
  } finally {
    histOpen.value = false
  }
}
async function giveBack() {
  const ok = await confirm({
    title: t('editor.return_title'),
    message: t('editor.return_msg', { title: ed.pageTitle }),
    confirmLabel: t('editor.return_to_author'), cancelLabel: t('editor.pub_not_now'),
  })
  if (ok) await ed.returnToAuthor()
}
</script>

<template>
  <header id="edTop" :class="['ed-top', { multi }]">
    <div class="ed-l">
      <router-link class="tb-back" :to="backTo" :aria-label="t('editor.back_to', { label: backLabel })"><Icon name="chevL" size="s16" /><span>{{ backLabel }}</span></router-link>
      <button
        ref="pgBtn" type="button" class="pg-sw" aria-haspopup="menu" :aria-expanded="pgOpen" :aria-label="t(ed.page ? 'editor.page_menu_label' : 'editor.page_menu_pick', { title: ed.pageTitle })"
        @click="pgOpen = !pgOpen"
      ><span class="pg-t"><b>{{ ed.pageTitle }}</b><small v-if="vp.phone && ed.page" class="pg-sub"><i v-if="!ed.readOnly" :class="['sv', ed.save]" /><span class="pg-sub-t" :title="subText">{{ subText }}</span></small></span><Icon name="chevD" size="s14" /></button>
      <PageLangSelect />
      <template v-if="ed.page && !vp.phone">
        <StatusChip :status="ed.status" />
        <StatusChip v-if="ed.awaiting" status="warn" :label="t('editor.state_awaiting')" :title="t('editor.sent_by', { by: ed.awaiting.by })" />
      </template>
      <span v-else-if="!ed.page" class="chip" :title="cap(t('editor.chip_no_version', { ver: versionNone(ed.locale) }))">{{ t('editor.no_translation') }}</span>
    </div>

    <div v-if="ed.page && !vp.phone" class="ed-c">
      <div v-if="!ed.readOnly" class="hist" role="group" :aria-label="t('editor.history')">
        <button id="undoBtn" type="button" class="ibtn" :aria-label="t('editor.undo')" :title="t('editor.undo_hint')" :disabled="!ed.canUndo" @click="ui.step(-1)"><Icon name="undo" /></button>
        <button id="redoBtn" type="button" class="ibtn" :aria-label="t('editor.redo')" :title="t('editor.redo_hint')" :disabled="!ed.canRedo" @click="ui.step(1)"><Icon name="redo" /></button>
      </div>
      <span v-if="!ed.readOnly" class="save-st" :data-s="ed.save === 'saved' ? '' : ed.save" role="status">{{ saveText }}</span>
      <div class="seg2 dev-sw" role="group" :aria-label="t('editor.view_mode')">
        <button type="button" :aria-pressed="ed.device === 'desktop'" :aria-label="t('editor.device_desktop')" :title="t('editor.device_desktop')" @click="ui.setDevice('desktop')"><Icon name="monitor" size="s16" /><span>{{ t('editor.device_desktop') }}</span></button>
        <button type="button" :aria-pressed="ed.device === 'phone'" :aria-label="t('editor.device_phone')" :title="t('editor.device_phone')" @click="ui.setDevice('phone')"><Icon name="phone2" size="s16" /><span>{{ t('editor.device_phone') }}</span></button>
      </div>
    </div>

    <div v-if="ed.page && vp.phone" class="ed-r">
      <button
        v-if="!ed.readOnly" id="pubBtn" ref="pubBtn" type="button" class="btn primary" aria-haspopup="dialog" :aria-expanded="pubOpen" :disabled="!n || (submitMode && !!ed.awaiting)"
        :aria-label="!submitMode ? t('editor.publish') : ed.awaiting ? t('editor.state_awaiting') : t('editor.submit')" @click="openPublish"
      >{{ pubText }}<span v-if="n && !(submitMode && ed.awaiting)" class="cnt">{{ n }}</span></button>
    </div>
    <div v-if="ed.page && vp.phone" class="ed-sub">
      <div class="seg2 ed-pane" role="group" :aria-label="t('editor.pane_label')">
        <button type="button" :aria-pressed="ed.pane === 'form'" @click="ui.setPane('form')"><Icon name="sliders" size="s16" />{{ t('editor.pane_form') }}</button>
        <button type="button" :aria-pressed="ed.pane === 'preview'" @click="ui.setPane('preview')"><Icon name="eye" size="s16" />{{ t('editor.preview') }}</button>
      </div>
      <div v-if="!ed.readOnly" class="hist" role="group" :aria-label="t('editor.history')">
        <button type="button" class="ibtn" :aria-label="t('editor.undo')" :disabled="!ed.canUndo" @click="ui.step(-1)"><Icon name="undo" /></button>
        <button type="button" class="ibtn" :aria-label="t('editor.redo')" :disabled="!ed.canRedo" @click="ui.step(1)"><Icon name="redo" /></button>
      </div>
      <button ref="moreBtn" type="button" class="btn icon" aria-haspopup="menu" :aria-expanded="moreOpen" :aria-label="t('editor.more_label')" @click="moreOpen = !moreOpen"><Icon name="more" size="s20" /></button>
    </div>

    <div v-if="ed.page && !vp.phone" class="ed-r">
      <button id="sbBtn" type="button" class="btn tgl" :aria-pressed="ed.sb === 'outline'" aria-controls="sb" :aria-label="t('editor.sb_outline')" :title="t('editor.sb_outline')" @click="ui.toggleSb()"><Icon name="layers" size="s16" /><span>{{ t('editor.outline_title') }}</span></button>
      <button type="button" class="btn" :aria-label="t('editor.history')" :title="t('editor.history')" @click="openHistory"><Icon name="clock" size="s16" /><span>{{ t('editor.history_btn') }}</span></button>
      <button type="button" class="btn" :aria-label="t('editor.preview')" :title="t('editor.preview')" @click="ui.openPreview()"><Icon name="eye" size="s16" /><span>{{ t('editor.preview') }}</span></button>
      <button v-for="a in pluginButtons" :key="a.name" type="button" class="btn" :aria-label="a.label" :title="a.title || a.label" @click="runPlugin(a)"><Icon :name="a.icon" size="s16" /><span>{{ a.label }}</span></button>
      <button v-if="!ed.readOnly && ed.canPublish && ed.awaiting" type="button" class="btn wide" @click="giveBack">{{ t('editor.return_to_author') }}</button>
      <button
        v-if="!ed.readOnly" id="pubBtn" ref="pubBtn" type="button" class="btn primary" aria-haspopup="dialog" :aria-expanded="pubOpen" :disabled="!n || (submitMode && !!ed.awaiting)"
        :title="submitMode ? (ed.awaiting ? t('editor.sent_already') : undefined) : multi ? t('editor.pub_all_multi', { ver: ver.acc }) : undefined" @click="openPublish"
      >{{ !submitMode ? t('editor.publish') : ed.awaiting ? t('editor.state_awaiting') : t('editor.submit') }}<span v-if="n && !(submitMode && ed.awaiting)" class="cnt">{{ n }}</span></button>
    </div>

    <Popover v-model:open="pgOpen" :anchor="pgBtn" align="left" cls="pg-pop">
      <template v-if="ed.page">
        <button v-if="!ed.readOnly" type="button" class="mi" role="menuitem" @click="settings"><Icon name="sliders" size="s16" /><span>{{ t('editor.settings_title') }}</span></button>
        <template v-if="canRemoveVersion">
          <hr v-if="!ed.readOnly">
          <button type="button" class="mi danger" role="menuitem" :disabled="held" @click="removeVersion"><Icon name="trash" size="s16" /><span>{{ t('editor.remove_version', { ver: ver.acc }) }}</span></button>
          <p v-if="held" class="pg-note">{{ homeHeldText(ed.locale) }}</p>
        </template>
        <hr v-if="!ed.readOnly || canRemoveVersion">
      </template>
      <button
        v-for="[p, l] in rows" :key="p.id" type="button" role="menuitem" :class="['mi', { cur: p.id === ed.pageId }]" :style="{ paddingLeft: 10 + l * 16 + 'px' }" @click="go(p)"
      >
        <span class="nm">{{ p.title }}</span><span v-if="multi && !p.translated" class="sub" :title="cap(t('editor.row_no_version', { ver: versionNone(ed.locale) }))">{{ t('editor.no_translation_sub') }}</span>
      </button>
    </Popover>
    <Popover v-if="vp.phone && ed.page" v-model:open="moreOpen" :anchor="moreBtn" align="right" :label="t('editor.more')">
      <button type="button" class="mi" role="menuitem" @click="inMore(() => ui.goSb('outline', { focus: 'head' }))"><Icon name="layers" size="s16" /><span>{{ t('editor.sb_outline') }}</span></button>
      <button type="button" class="mi" role="menuitem" @click="inMore(openHistory)"><Icon name="clock" size="s16" /><span>{{ t('editor.history') }}</span></button>
      <button v-if="!ed.readOnly" type="button" class="mi" role="menuitem" @click="inMore(() => ui.openSettings())"><Icon name="sliders" size="s16" /><span>{{ t('editor.settings_title') }}</span></button>
      <button type="button" class="mi" role="menuitem" @click="inMore(() => ui.openPreview())"><Icon name="monitor" size="s16" /><span>{{ t('editor.preview_full') }}</span></button>
      <button v-for="a in pluginButtons" :key="a.name" type="button" class="mi" role="menuitem" @click="inMore(() => runPlugin(a))"><Icon :name="a.icon" size="s16" /><span>{{ a.label }}</span></button>
      <button v-if="!ed.readOnly && ed.canPublish && ed.awaiting" type="button" class="mi" role="menuitem" @click="inMore(giveBack)"><Icon name="undo" size="s16" /><span>{{ t('editor.return_to_author') }}</span></button>
      <template v-if="canRemoveVersion">
        <hr>
        <button type="button" class="mi danger" role="menuitem" :disabled="held" @click="inMore(removeVersion)"><Icon name="trash" size="s16" /><span>{{ t('editor.remove_version', { ver: ver.acc }) }}</span></button>
      </template>
    </Popover>
    <PublishPopover v-if="ed.page && !ed.readOnly" v-model:open="pubOpen" :anchor="pubBtn" />
    <HistoryDrawer v-if="ed.page" v-model:open="histOpen" kind="page" :resource-id="ed.pageId" :locale="ed.locale" :can-restore="!ed.readOnly" @restored="onRestored" />
  </header>
</template>

<style scoped>
.ed-top .ed-l { gap: 2px; }
.ed-top .pg-sw { flex: 1 1 0; min-width: 0; max-width: none; padding-inline: 4px; }
.ed-top .pg-t { flex: 1; }
.ed-top .ed-r .btn.primary { padding-inline: 10px; }
@media (min-width: 768px) {
  .ed-top .ed-l { gap: 8px; }
  .ed-top .pg-sw { flex: 0 1 auto; max-width: 300px; padding-inline: 10px 8px; }
  .ed-top .pg-t { flex: initial; }
}
.pg-t { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; line-height: 1.2; }
.pg-t b { max-width: 100%; } /* the name shrinks with the bar (a flex-start column would keep its full text width and run under the buttons) */
.pg-sub { display: inline-flex; align-items: center; gap: 5px; max-width: 100%; font-size: var(--t-xs); font-weight: 450; letter-spacing: 0; color: var(--ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pg-sub-t { min-width: 0; overflow: hidden; text-overflow: ellipsis; } /* the ellipsis belongs to the text itself: the flex row around it does not clip */
.pg-sub .sv { width: 6px; height: 6px; border-radius: 50%; background: var(--green, #2e8b57); flex: none; }
.pg-sub .sv.busy { background: var(--cy); }
.pg-sub .sv.error { background: var(--red); }
.btn.tgl[aria-pressed="true"] { background: var(--cy-50); border-color: var(--cy-100); color: var(--cy-dd); }
.save-st[data-s="error"] { color: var(--red); font-weight: 560; }
.nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pop button.mi.danger[disabled] { color: #7B8A94; }
.pop button.mi.danger[disabled]:hover { background: none; }
.pg-note { margin: 0; padding: 6px 10px 6px; font-size: var(--t-xs); line-height: 1.4; color: var(--ink-2); }
/* 1280-1400 px: the secondary buttons keep their icon only (the title attribute and aria-label carry the name) */
@media (width < 1400px) {
  .ed-top { gap: 8px; padding: 0 12px; }
}
@media (768px <= width < 1400px) {
  .dev-sw button span, .ed-r .btn:not(.primary):not(.wide) span { display: none; }
  .dev-sw button { padding: 0 9px; }
  .ed-r .btn:not(.primary):not(.wide) { width: 34px; padding: 0; }
}
/* a multilingual site adds the language select and the version chip on the left: the secondary buttons keep their icon only up to 1560 px, so the page name still has room */
@media (1400px <= width < 1560px) {
  .ed-top.multi .dev-sw button span, .ed-top.multi .ed-r .btn:not(.primary):not(.wide) span { display: none; }
  .ed-top.multi .dev-sw button { padding: 0 9px; }
  .ed-top.multi .ed-r .btn:not(.primary):not(.wide) { width: 34px; padding: 0; }
}
</style>
