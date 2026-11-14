<script setup>
// «Настройки страницы»: title, address, template, parent page, SEO title and description (with length budgets). Saved into the draft.
// The template list holds only the templates the page may get; changing it asks first when fields would be lost.
// LANGUAGE (multilingual sites): the dialog edits the version on screen — it says so; a non-main language shows its address prefix fixed in front of the input
// (/en + /about-us); the parent page is shared by all languages and the help text says that.
import { computed, inject, ref, watch } from 'vue'
import Modal from '@/components/ui/Modal.vue'
import Icon from '@/components/ui/Icon.vue'
import FieldShell from '@/components/form/FieldShell.vue'
import SchemaForm from '@/components/form/SchemaForm.vue'
import { PAGE_FIELDS } from '@/schema/pages.js'
import { isMulti, pathPrefix, prefix as addPrefix, reservedCodes, unprefix } from '@/utils/locales.js'
import { normalizeUrl, urlError } from '@/components/pages/slug.js'
import { HOME_PARENT_HELP, HOME_TEMPLATE_HELP, HOME_URL_HELP, pageRules } from '@/components/pages/pageRules.js'
import { lostFieldsText, parentChoices, parentHelpText, pickParent, templateHelpText, templatePicks, topLevelOk } from '@/components/pages/pageTemplateRules.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import PageUrlInput from '@/components/pages/PageUrlInput.vue'
import { version } from '@/utils/langWords.js'
import { escapeHtml } from '@/utils/html.js'
import { t } from '@/i18n/index.js'
import { EDITOR_KEY } from './editorState.js'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['update:open'])
const ed = inject(EDITOR_KEY)
const confirm = useConfirm()

const multi = computed(() => isMulti())
const pre = computed(() => (multi.value ? pathPrefix(ed.locale) : '')) // '' = the main language edits the whole address
const ver = computed(() => version(ed.locale))

const model = ref(null) // url holds only the part after the prefix when `pre` is set
const err = ref('')
const urlErr = ref('')
const busy = ref(false)
watch(() => props.open, (v) => {
  if (!v) return
  const p = ed.page
  model.value = { title: p.title, url: pre.value ? unprefix(p.url, ed.locale) : p.url, parentId: p.parentId || '', template: p.template, seo: { title: (p.seo && p.seo.title) || '', description: (p.seo && p.seo.description) || '' } }
  err.value = ''
  urlErr.value = ''
}, { immediate: true })

const fullUrl = computed(() => (!model.value ? '' : pre.value ? addPrefix(model.value.url.trim() || '/', ed.locale) : model.value.url.trim()))
const urlBudget = computed(() => ({ n: [...fullUrl.value].length, max: PAGE_FIELDS[1].max }))

// the same rules as the dialog in the list of pages (components/pages/pageTemplateRules.js): the home page keeps its address, template and place;
// only the templates that fit the chosen parent are offered and only the parents the chosen template may stand under (the page itself, its branch and the home page are no parents)
const rules = computed(() => pageRules(ed.page))
const tplOpts = computed(() => templatePicks(ed.page, ed.pages, (model.value && model.value.parentId) || null).filter((c) => c.ok).map((c) => [c.template.key, c.template.name]))
const parents = computed(() => parentChoices(ed.pages, ed.page, model.value ? model.value.template : ed.page.template))
const parentOpts = computed(() => {
  const out = topLevelOk(model.value ? model.value.template : ed.page.template) ? [['', t('editor.settings_parent_none')]] : []
  parents.value.forEach(({ p, lvl }) => out.push([p.id, '— '.repeat(lvl) + p.title]))
  return out
})
// another template: the parent stays if it still fits, else the top level, else the first page the template fits under
watch(() => model.value && model.value.template, (k) => { if (k) model.value = { ...model.value, parentId: pickParent(parents.value, k, model.value.parentId) } })
const parentHelp = computed(() => (rules.value.isHome ? HOME_PARENT_HELP : parentHelpText(model.value ? model.value.template : ed.page.template, multi.value)))
const urlHelp = computed(() => {
  const r = rules.value
  if (r.isHome) return HOME_URL_HELP
  if (pre.value) return t(ed.published ? 'editor.settings_url_published' : 'editor.settings_url_draft', { ver: ver.value.gen, prefix: pre.value })
  return PAGE_FIELDS[1].help
})
// required marks are checked here, with wording that fits a page (the form engine's text speaks about sections)
const fTitle = computed(() => ({ ...PAGE_FIELDS[0], req: false, help: PAGE_FIELDS[0].help }))
const fUrl = computed(() => ({ ...PAGE_FIELDS[1], req: false, disabled: rules.value.lockUrl, help: urlHelp.value }))
const fTemplate = computed(() => ({ k: 'template', t: 'select', label: t('editor.settings_template'), disabled: rules.value.lockTemplate, help: rules.value.isHome ? HOME_TEMPLATE_HELP : templateHelpText(tplOpts.value.length, multi.value), opts: tplOpts.value }))
const fParent = computed(() => ({ k: 'parentId', t: 'select', label: t('editor.settings_parent'), disabled: rules.value.lockParent, help: parentHelp.value, opts: parentOpts.value }))
const main = computed(() => [fTitle.value, fUrl.value, fTemplate.value, fParent.value])
// the note on top of the dialog names the language version in bold; the name of a language is the client's text, so it is escaped
const langNote = computed(() => t(rules.value.lockParent ? 'editor.settings_lang_note' : 'editor.settings_lang_note_shared', { ver: escapeHtml(ver.value.acc) }))
const seo = [PAGE_FIELDS[2], PAGE_FIELDS[3]]

const close = () => emit('update:open', false)
function onUrlBlur() { if (model.value) model.value = { ...model.value, url: normalizeUrl(model.value.url) } }
function setUrl(v) { model.value = { ...model.value, url: v }; urlErr.value = '' }
async function submit() {
  const m = model.value
  if (!m.title.trim()) { err.value = t('editor.settings_need_title'); return }
  if (!rules.value.lockUrl) {
    const bad = pre.value && !m.url.trim() ? t('editor.settings_need_url') : urlError(fullUrl.value, ed.pages.filter((x) => x.translated !== false), ed.pageId, ed.page.url, reservedCodes(ed.locale))
    if (bad) { err.value = bad; urlErr.value = bad; return }
  }
  err.value = ''
  urlErr.value = ''
  if (m.template !== ed.page.template) {
    const lost = lostFieldsText(ed.page, m.template, ed.pages)
    if (lost) {
      const ok = await confirm({ title: t('editor.settings_tpl_title'), message: t('editor.settings_tpl_lost', { fields: lost }), confirmLabel: t('editor.settings_tpl_confirm'), danger: true })
      if (!ok) return
    }
  }
  busy.value = true
  try {
    await ed.saveMeta({ ...m, url: fullUrl.value })
    close()
  } catch (e) {
    err.value = (e && e.message) || t('editor.settings_save_failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="t('editor.settings_title')" :width="580" initial-focus="input[type=text]" @update:open="emit('update:open', $event)">
    <div v-if="model" class="ps">
      <div v-if="multi" class="alert info-a ps-lang"><Icon name="globe" size="s16" /><div v-html="langNote" /></div>
      <!-- main language / one-language site: one plain form, as before -->
      <SchemaForm v-if="!pre" id-prefix="pgs" :schema="main" :model-value="model" @update:model-value="model = $event" />
      <!-- other languages: the address has its prefix fixed in front of the input -->
      <template v-else>
        <SchemaForm id-prefix="pgst" :schema="[fTitle]" :model-value="model" @update:model-value="model = $event" />
        <FieldShell :label="fUrl.label" for-id="pgs-url" :budget="urlBudget" :error="urlErr" :help="urlHelp">
          <PageUrlInput id="pgs-url" :model-value="model.url" :prefix="pre" :disabled="rules.lockUrl" :invalid="!!urlErr" @update:model-value="setUrl" @blur="onUrlBlur" />
        </FieldShell>
        <SchemaForm id-prefix="pgsp" :schema="[fTemplate, fParent]" :model-value="model" @update:model-value="model = $event" />
      </template>
      <h3 class="ps-h">{{ t('editor.settings_seo_head') }}</h3>
      <SchemaForm id-prefix="pgsseo" :schema="seo" :model-value="model" @update:model-value="model = $event" />
      <div v-if="err && !(pre && urlErr)" class="alert err-a ps-err" role="alert"><span>{{ err }}</span></div>
    </div>
    <template #footer>
      <button type="button" class="btn" @click="close">{{ t('editor.cancel') }}</button>
      <button type="button" class="btn primary" :class="{ loading: busy }" @click="submit">{{ t('editor.save') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.ps-h { font-size: var(--t-sm); font-weight: 650; margin: 14px 0 0; padding-top: 14px; border-top: 1px solid var(--hair-2); }
.ps-err { margin-top: 10px; }
.ps-lang { margin-bottom: 4px; }
</style>
