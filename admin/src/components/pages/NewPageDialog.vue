<script setup>
// «Новая страница»: the template FIRST (cards: name + one sentence; a template that cannot be added is disabled and says why), then the title, the parent page (only pages the template
// may stand under), the address (made from the title until the person edits it) and the starting content (a first screen with the title, or a copy of another page's sections).
// `template` preselects a card («Добавить статью» in the list of articles); `parentId` = «Добавить дочернюю страницу»: the cards are then judged for that parent. The created page opens in the editor.
import { computed, nextTick, ref, watch } from 'vue'
import { t } from '@/i18n/index.js'
import Modal from '@/components/ui/Modal.vue'
import FieldShell from '@/components/form/FieldShell.vue'
import Icon from '@/components/ui/Icon.vue'
import { api } from '@/api/index.js'
import { useToast } from '@/components/ui/useToast.js'
import { app, can } from '@/stores/app.js'
import { isMulti, reservedCodes } from '@/utils/locales.js'
import { onLang } from '@/utils/langWords.js'
import { nid } from '@/utils/ids.js'
import { clone } from '@/utils/objects.js'
import { PAGE_FIELDS } from '@/schema/pages.js'
import { canPlace } from '@/schema/templates.js'
import { firstTemplate, flatWords, isRootPage, newPageChoices, parentsFor, pickParent, placementOf, topLevelOk } from './pageTemplateRules.js'
import { autoUrl, normalizeUrl, urlError } from './slug.js'
import { flatOptions, indent } from './tree.js'

const props = defineProps({ open: { type: Boolean, default: false }, pages: { type: Array, required: true }, parentId: { type: String, default: null }, template: { type: String, default: '' } })
const emit = defineEmits(['update:open', 'created'])

const toast = useToast()
const F = Object.fromEntries(PAGE_FIELDS.map((f) => [f.k, f]))

const title = ref('')
const url = ref('')
const urlManual = ref(false)
const parent = ref('')
const template = ref('default') // eslint-disable-line vue/no-dupe-keys -- the chosen card; the prop of the same name is only the starting point (read through props.template)
// opened from a flat list («Добавить статью»): the dialog speaks of an article / a product, otherwise of a page
const words = computed(() => flatWords(props.template))
const start = ref('empty') // 'empty' | 'copy'
const source = ref('')
const submitted = ref(false)
const busy = ref(false)
const serverError = ref('')

// the cards: opened under a given parent they are judged for the parent chosen below, otherwise a template is possible when it can stand somewhere
// only the templates the role may write (an author: articles)
const choicesFor = (under) => newPageChoices(props.pages, under).filter((c) => can('pages', 'edit', { template: c.template.key }))
const cards = computed(() => choicesFor(props.parentId ? props.pages.find((p) => p.id === parent.value) || null : undefined))
// pages the new page may stand under: not the home page, and only those its template allows
const parentOptions = computed(() => parentsFor(props.pages, template.value))
const topOk = computed(() => topLevelOk(template.value))
const sourceOptions = computed(() => flatOptions(props.pages))
// another template: the parent stays if it still fits, else the top level, else the first page the template fits under («Статья блога» -> «Блог»)
watch(template, (k) => { parent.value = pickParent(parentsFor(props.pages, k), k, parent.value) })

watch(() => props.open, (o) => {
  if (!o) return
  title.value = ''; url.value = ''; urlManual.value = false; start.value = 'empty'
  const under = props.parentId ? props.pages.find((p) => p.id === props.parentId) : null
  parent.value = under && !isRootPage(under) ? under.id : ''
  template.value = firstTemplate(choicesFor(props.parentId ? under : undefined), props.template, under).template.key
  parent.value = pickParent(parentsFor(props.pages, template.value), template.value, parent.value)
  source.value = ''; submitted.value = false; busy.value = false; serverError.value = ''
})

watch(title, (v) => { if (!urlManual.value) url.value = autoUrl(v, props.pages) })

const titleError = computed(() => (submitted.value && !title.value.trim() ? t('pages.title_required') : ''))
// while the title is empty the address (made from it) is empty too: do not scold twice
const reserved = computed(() => reservedCodes(app.languages.main)) // /en and /en/… are the English version's addresses
const urlErr = computed(() => (urlManual.value || (submitted.value && title.value.trim()) ? urlError(url.value, props.pages, null, '', reserved.value) : '') || serverError.value)
const sourceError = computed(() => (submitted.value && start.value === 'copy' && !source.value ? t('pages.copy_source_required') : ''))
const titleBudget = computed(() => ({ n: [...title.value].length, max: F.title.max }))
const urlBudget = computed(() => ({ n: [...url.value].length, max: F.url.max }))
const overLimit = computed(() => titleBudget.value.n > titleBudget.value.max || urlBudget.value.n > urlBudget.value.max)

function onUrlInput() { urlManual.value = true; serverError.value = '' }
function onUrlBlur() {
  url.value = normalizeUrl(url.value)
  if (!url.value) { urlManual.value = false; url.value = autoUrl(title.value, props.pages) }
}
function useAuto() { urlManual.value = false; serverError.value = ''; url.value = autoUrl(title.value, props.pages) }

const multi = computed(() => isMulti())
const mainOn = computed(() => onLang(app.languages.main))
const siteUrl = computed(() => `${app.siteInfo.domain}${url.value || '/…'}`)
const close = () => emit('update:open', false)

async function create() {
  submitted.value = true
  serverError.value = ''
  if (!title.value.trim() || urlError(url.value, props.pages, null, '', reserved.value) || overLimit.value) {
    await nextTick()
    const bad = document.querySelector('.np-dialog [aria-invalid="true"]')
    if (bad) bad.focus()
    return
  }
  if (start.value === 'copy' && !source.value) return
  const place = canPlace(template.value, parent.value ? props.pages.find((p) => p.id === parent.value) : null, props.pages)
  if (!place.ok) { toast.error(place.reason); return }
  busy.value = true
  try {
    let sections = null
    if (start.value === 'copy') {
      const src = await api.pages.get(source.value)
      sections = clone(src.sections).map((s) => ({ ...s, id: nid('s') }))
    }
    const page = await api.pages.create({ title: title.value.trim(), url: url.value.trim(), parentId: parent.value || null, template: template.value, sections })
    emit('created', page)
    close()
  } catch (e) {
    serverError.value = e.status === 409 ? e.message : ''
    if (!serverError.value) toast.error(e.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="words.title" :width="640" initial-focus="#np-title" :close-on-scrim="!title" @update:open="emit('update:open', $event)">
    <form class="np-dialog" novalidate @submit.prevent="create">
      <FieldShell :label="t('pages.template_label')" label-id="np-tpl-l">
        <div class="tpls" role="radiogroup" aria-labelledby="np-tpl-l">
          <label v-for="c in cards" :key="c.template.key" :class="['tp', { on: template === c.template.key, off: !c.ok }]">
            <input v-model="template" type="radio" name="np-tpl" :value="c.template.key" :disabled="!c.ok">
            <b>{{ c.template.name }}</b>
            <small v-if="c.ok">{{ c.template.about }}</small>
            <small v-else class="why"><Icon name="lock" size="s14" /><span>{{ c.reason }}</span></small>
          </label>
        </div>
      </FieldShell>

      <FieldShell :label="F.title.label" for-id="np-title" :budget="titleBudget" :error="titleError" required>
        <input id="np-title" v-model="title" type="text" autocomplete="off" :placeholder="words.ph" :aria-invalid="titleError ? 'true' : undefined">
      </FieldShell>

      <FieldShell :label="t('pages.parent_label')" for-id="np-parent" :help="placementOf(template)">
        <select id="np-parent" v-model="parent">
          <option v-if="topOk" value="">{{ t('pages.parent_none') }}</option>
          <option v-else value="" disabled>{{ t('pages.choose_page') }}</option>
          <option v-for="o in parentOptions" :key="o.p.id" :value="o.p.id">{{ indent(o.lvl) }}{{ o.p.title }}</option>
        </select>
      </FieldShell>

      <FieldShell :label="F.url.label" for-id="np-url" :budget="urlBudget" :error="urlErr" required>
        <input id="np-url" v-model="url" type="text" autocomplete="off" spellcheck="false" :placeholder="words.phUrl" :aria-invalid="urlErr ? 'true' : undefined" @input="onUrlInput" @blur="onUrlBlur">
        <div class="hint">
          <span>{{ t('pages.on_site') }} <b>{{ siteUrl }}</b></span>
          <span v-if="!urlManual" class="muted">{{ t('pages.url_auto_note') }}</span>
          <button v-else-if="title" type="button" class="lnk" @click="useAuto">{{ t('pages.url_auto_button') }}</button>
        </div>
      </FieldShell>

      <FieldShell :label="t('pages.start_label')" label-id="np-start-l">
        <div class="starts" role="radiogroup" aria-labelledby="np-start-l">
          <label :class="['st', { on: start === 'empty' }]">
            <input v-model="start" type="radio" name="np-start" value="empty">
            <span class="ico"><Icon name="doc" size="s20" /></span>
            <span class="tx"><b>{{ t('pages.start_empty') }}</b><small>{{ t('pages.start_empty_about') }}</small></span>
          </label>
          <label :class="['st', { on: start === 'copy' }]">
            <input v-model="start" type="radio" name="np-start" value="copy">
            <span class="ico"><Icon name="copy" size="s20" /></span>
            <span class="tx"><b>{{ t('pages.start_copy') }}</b><small>{{ t('pages.start_copy_about') }}</small></span>
          </label>
        </div>
        <div v-if="start === 'copy'" class="src">
          <label class="sr" for="np-src">{{ t('pages.copy_source_label') }}</label>
          <select id="np-src" v-model="source" :aria-invalid="sourceError ? 'true' : undefined">
            <option value="" disabled>{{ t('pages.choose_page') }}</option>
            <option v-for="o in sourceOptions" :key="o.p.id" :value="o.p.id">{{ indent(o.lvl) }}{{ o.p.title }} — {{ o.p.url }}</option>
          </select>
          <div v-if="sourceError" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ sourceError }}</span></div>
        </div>
      </FieldShell>

      <p class="note"><Icon name="info" size="s16" />{{ t('pages.new_draft_note') }}</p>
      <p v-if="multi" class="note"><Icon name="globe" size="s16" />{{ t('pages.new_main_note', { language: mainOn }) }}</p>
      <button type="submit" class="sr" tabindex="-1">{{ t('pages.create') }}</button>
    </form>

    <template #footer>
      <button class="btn" type="button" @click="close">{{ t('pages.cancel') }}</button>
      <button :class="['btn primary', { loading: busy }]" type="button" @click="create">{{ t('pages.create_open') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.np-dialog{display:flex;flex-direction:column;gap:2px}
.tpls{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.tp{position:relative;display:flex;flex-direction:column;gap:2px;padding:9px 12px 10px;border:1px solid var(--ctl);border-radius:var(--r);background:#fff;cursor:pointer;transition:border-color var(--dur),background var(--dur),box-shadow var(--dur)}
.tp:hover:not(.off){border-color:var(--ink-3)}
.tp input{position:absolute;opacity:0;width:1px;height:1px}
.tp.on{border-color:var(--cy);background:var(--cy-50);box-shadow:0 0 0 3px var(--cy-100)}
.tp:has(input:focus-visible){outline:2px solid var(--cy);outline-offset:2px}
.tp b{font-weight:620;line-height:1.25}
.tp small{font-size:var(--t-xs);color:var(--ink-2);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tp.off{background:var(--sheet-2);cursor:not-allowed}
.tp.off b{color:var(--ink-3)}
.tp small.why{display:flex;gap:5px;align-items:flex-start;color:var(--ink-2);-webkit-line-clamp:unset;overflow:visible}
.tp small.why .ic{flex:none;margin-top:1px;color:var(--ink-3)}
.hint{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:var(--t-sm);color:var(--ink-2);align-items:baseline}
.hint b{font-weight:600;color:var(--ink);overflow-wrap:anywhere}
.lnk{background:none;border:0;padding:0;color:var(--brand-text);font-weight:600;font-size:var(--t-sm);text-decoration:underline;text-underline-offset:.2em}
.lnk:hover{color:var(--brand-text-d)}
.starts{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.st{position:relative;display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--ctl);border-radius:var(--r);background:#fff;cursor:pointer;transition:border-color var(--dur),background var(--dur),box-shadow var(--dur)}
.st:hover{border-color:var(--ink-3)}
.st input{position:absolute;opacity:0;width:1px;height:1px}
.st.on{border-color:var(--cy);background:var(--cy-50);box-shadow:0 0 0 3px var(--cy-100)}
.st:has(input:focus-visible){outline:2px solid var(--cy);outline-offset:2px}
.st .ico{width:32px;height:32px;border-radius:var(--r);display:grid;place-items:center;background:var(--hair-2);color:var(--ink-2);flex:none}
.st.on .ico{background:var(--cy);color:var(--brand-ink)}
.st .tx{display:flex;flex-direction:column;gap:3px;min-width:0}
.st b{font-weight:620;line-height:1.25}
.st small{font-size:var(--t-xs);color:var(--ink-2);line-height:1.4}
.src{margin-top:8px;display:flex;flex-direction:column;gap:5px}
.note{display:flex;gap:8px;align-items:flex-start;margin-top:8px;font-size:var(--t-sm);color:var(--ink-2);line-height:1.45}
.note .ic{margin-top:2px;color:var(--ink-3)}
</style>
