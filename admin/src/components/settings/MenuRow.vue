<script setup>
// One menu item: reorder arrows, name, link (page of the site or an address), nesting, delete.
// `pages` are the pages that have a version in the menu's language ({ value: address, label: title, draft }); `flag` is the amber warning
// of a link to a page that is not on the site in that language (draft or missing): it never blocks saving.
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'
import { itemError } from './menuModel.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  item: { type: Object, required: true },
  child: { type: Boolean, default: false },
  canUp: { type: Boolean, default: false },
  canDown: { type: Boolean, default: false },
  canIndent: { type: Boolean, default: false },
  indentHint: { type: String, default: '' },
  pages: { type: Array, default: () => [] },
  flag: { type: String, default: '' },
  noPagesText: { type: String, default: () => t('settings.menu_no_pages') },
  idBase: { type: String, required: true },
})
const emit = defineEmits(['patch', 'up', 'down', 'indent', 'outdent', 'remove'])

const link = computed(() => props.item.link || { kind: 'page', page: '/', url: '' })
const error = computed(() => itemError(props.item))
const pageOpts = computed(() => {
  const list = props.pages.map((p) => ({ value: p.value, label: p.draft ? t('settings.page_draft_option', { label: p.label }) : p.label }))
  return link.value.page && !list.some((p) => p.value === link.value.page) ? [...list, { value: link.value.page, label: t('settings.page_missing_option', { page: link.value.page }) }] : list
})
const setLink = (patch) => emit('patch', { link: { ...link.value, ...patch } })
</script>

<template>
  <div :class="['mr', { child, bad: !!error, flagged: !!flag && !error }]">
    <div class="mr-main">
      <span class="mr-mv">
        <button type="button" class="btn ghost icon sm" :aria-label="t('settings.move_up', { label: item.label })" :disabled="!canUp" @click="emit('up')"><Icon name="up" size="s14" /></button>
        <button type="button" class="btn ghost icon sm" :aria-label="t('settings.move_down', { label: item.label })" :disabled="!canDown" @click="emit('down')"><Icon name="down" size="s14" /></button>
      </span>
      <div class="mr-f mr-name">
        <label class="sr" :for="idBase + '-l'">{{ t('settings.menu_item_name') }}</label>
        <input :id="idBase + '-l'" type="text" :value="item.label" maxlength="30" :placeholder="t('settings.menu_item_name')" autocomplete="off" :aria-invalid="error && !item.label.trim() ? 'true' : undefined" @input="emit('patch', { label: $event.target.value })">
      </div>
      <div class="mr-f mr-kind">
        <label class="sr" :for="idBase + '-k'">{{ t('settings.menu_link_kind') }}</label>
        <select :id="idBase + '-k'" :value="link.kind" @change="setLink({ kind: $event.target.value })">
          <option value="page">{{ t('settings.menu_kind_page') }}</option>
          <option value="url">{{ t('settings.menu_kind_url') }}</option>
        </select>
      </div>
      <div class="mr-f mr-to">
        <template v-if="link.kind === 'page'">
          <label class="sr" :for="idBase + '-p'">{{ t('settings.menu_page') }}</label>
          <select :id="idBase + '-p'" :value="link.page" :aria-invalid="flag ? 'true' : undefined" @change="setLink({ page: $event.target.value })">
            <option v-if="!link.page" value="">{{ pageOpts.length ? t('settings.menu_choose_page') : noPagesText }}</option>
            <option v-for="p in pageOpts" :key="p.value" :value="p.value">{{ p.label }}</option>
          </select>
        </template>
        <template v-else>
          <label class="sr" :for="idBase + '-u'">{{ t('settings.menu_address') }}</label>
          <input :id="idBase + '-u'" type="text" :value="link.url" :placeholder="t('settings.menu_address_placeholder')" autocomplete="off" @input="setLink({ url: $event.target.value })">
        </template>
      </div>
      <span class="mr-acts">
        <button v-if="child" type="button" class="btn ghost icon sm" :aria-label="t('settings.outdent_aria', { label: item.label })" :title="t('settings.outdent')" @click="emit('outdent')"><Icon name="chevL" size="s14" /></button>
        <button v-else type="button" class="btn ghost icon sm" :aria-label="t('settings.indent_aria', { label: item.label })" :title="indentHint || t('settings.indent')" :disabled="!canIndent" @click="emit('indent')"><Icon name="chevR" size="s14" /></button>
        <button type="button" class="btn ghost icon sm" :aria-label="t('settings.menu_item_delete_aria', { label: item.label })" :title="t('settings.delete')" @click="emit('remove')"><Icon name="trash" size="s14" /></button>
      </span>
    </div>
    <div v-if="error" class="err mr-err" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span></div>
    <div v-else-if="flag" class="mr-warn"><Icon name="warn" size="s16" /><span>{{ flag }}</span></div>
  </div>
</template>

<style scoped>
.mr{width:100%;border:1px solid var(--hair);border-radius:var(--r);background:#fff;padding:6px 8px 6px 4px}
.mr.child{background:var(--sheet-2)}
.mr.bad{border-color:var(--red)}
.mr.flagged{border-color:#E3C07A}
.mr-f select[aria-invalid=true]{border-color:#C79A3E;background:var(--amber-50)}
.mr-main{display:flex;align-items:center;gap:8px}
.mr-mv{display:flex;flex:none}
.mr-name{flex:0 0 210px}
.mr-kind{flex:0 0 176px}
.mr-to{flex:1;min-width:0}
.mr-f input,.mr-f select{height:34px}
.mr-f input[aria-invalid=true]{border-color:var(--red);background:#FFFAFA}
.mr-acts{display:flex;flex:none}
.mr-err{padding:4px 4px 2px 36px}
.mr-warn{display:flex;gap:8px;align-items:flex-start;padding:6px 8px 3px 36px;font-size:var(--t-sm);line-height:1.4;color:#5C3A00}
.mr-warn svg{flex:none;color:var(--amber);margin-top:1px}
/* phone: the fields stand one under another, the buttons in a row below */
@media (max-width:767px){
  .mr{padding:10px}
  .mr-main{flex-wrap:wrap;gap:8px}
  .mr-name,.mr-kind,.mr-to{flex:1 1 100%}
  .mr-f input,.mr-f select{height:44px}
  .mr-mv{order:5}
  .mr-acts{order:6;margin-left:auto}
  .mr-err,.mr-warn{padding-left:2px}
}
</style>
