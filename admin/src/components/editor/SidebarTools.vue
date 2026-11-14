<script setup>
// The buttons at the right of every sidebar header: на весь экран (everywhere but a phone, where the sidebar is already the whole screen) · свернуть.
import { inject } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY, EDITOR_UI_KEY } from './editorState.js'
import { vp } from '@/utils/viewport.js'

const ed = inject(EDITOR_KEY)
const ui = inject(EDITOR_UI_KEY)
</script>

<template>
  <button
    v-if="!vp.phone" class="btn ghost icon sm" type="button" data-act="sb-full" :aria-pressed="ed.sbFull"
    :aria-label="ed.sbFull ? t('editor.tools_shrink') : t('editor.tools_expand')" :title="ed.sbFull ? t('editor.tools_shrink_hint') : t('editor.tools_expand_hint')" @click="ui.toggleFull()"
  ><Icon :name="ed.sbFull ? 'shrink' : 'expand'" size="s16" /></button>
  <button class="btn ghost icon sm" type="button" :aria-label="vp.phone ? t('editor.tools_show_page') : t('editor.tools_collapse')" :title="vp.phone ? t('editor.tools_show_page') : t('editor.tools_collapse_hint')" @click="ui.collapseSb(true)"><Icon :name="vp.phone ? 'eye' : 'panelL'" size="s16" /></button>
</template>
