<script setup>
// «Настройки страницы»: title, address, template, parent page and the search-engine card (title + description with length budgets).
// The home page keeps its address, template and place. The template list holds only the templates the page may get; changing it asks first when fields would be lost.
// LANGUAGE: the dialog edits ONE language version (page.locale = the row's language). A non-main version shows its address prefix fixed in front of the input
// (/en + /about-us) and the dialog says whose version it is; the parent page is shared by all languages (the help text says so).
import { computed, nextTick, ref, watch } from 'vue'
import { t } from '@/i18n/index.js'
import Modal from '@/components/ui/Modal.vue'
import FieldShell from '@/components/form/FieldShell.vue'
import Icon from '@/components/ui/Icon.vue'
import { api } from '@/api/index.js'
import { app } from '@/stores/app.js'
import { isMulti, pathPrefix, prefix as addPrefix, reservedCodes, unprefix } from '@/utils/locales.js'
import { useToast } from '@/components/ui/useToast.js'
import { PAGE_FIELDS } from '@/schema/pages.js'
import { same } from '@/utils/objects.js'
import { normalizeUrl, urlError } from './slug.js'
import { indent } from './tree.js'
import { HOME_PARENT_HELP, HOME_TEMPLATE_HELP, HOME_URL_HELP, pageRules } from './pageRules.js'
import { lostFieldsText, parentChoices, parentHelpText, pickParent, templateHelpText, templatePicks, topLevelOk } from './pageTemplateRules.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import PageUrlInput from './PageUrlInput.vue'
import { cap, version } from '@/utils/langWords.js'

const props = defineProps({ open: { type: Boolean, default: false }, page: { type: Object, default: null }, pages: { type: Array, required: true } })
const emit = defineEmits(['update:open', 'saved'])
const toast = useToast()
const confirm = useConfirm()
const F = Object.fromEntries(PAGE_FIELDS.map((f) => [f.k, f]))

const title = ref('')
const url = ref('')
const parent = ref('')
const template = ref('')
const seoTitle = ref('')
const seoDesc = ref('')
const submitted = ref(false)
const busy = ref(false)
const serverError = ref('')
const initial = ref(null)

const loc = computed(() => (props.page && props.page.locale) || app.locale)
const multi = computed(() => isMulti())
const pre = computed(() => (multi.value ? pathPrefix(loc.value) : '')) // '' for the main language, '/en' for the others
const ver = computed(() => version(loc.value))
/** the whole address of the version: prefix + what is typed ('/' = the language's home page) */
const fullUrl = computed(() => (pre.value ? addPrefix(url.value.trim() || '/', loc.value) : url.value.trim()))

const rules = computed(() => pageRules(props.page))
const isHome = computed(() => rules.value.isHome)
const lockTitleUrl = computed(() => rules.value.lockTitle)

const snapshot = () => ({ title: title.value.trim(), url: fullUrl.value, parent: parent.value, tpl: template.value, st: seoTitle.value, sd: seoDesc.value })

watch(() => [props.open, props.page && props.page.id], ([o]) => {
  if (!o || !props.page) return
  const p = props.page
  title.value = p.title; url.value = pre.value ? unprefix(p.url, loc.value) : p.url; parent.value = p.parentId || ''; template.value = p.template
  seoTitle.value = (p.seo && p.seo.title) || ''; seoDesc.value = (p.seo && p.seo.description) || ''
  submitted.value = false; busy.value = false; serverError.value = ''
  initial.value = snapshot()
}, { immediate: true })

const dirty = computed(() => !!initial.value && !same(initial.value, snapshot()))

// templates the page may get (only those that fit the chosen parent page and its own children), and the pages it may stand under with the chosen template: not itself, not its descendants, not the home page
const templateOptions = computed(() => (props.page ? templatePicks(props.page, props.pages, parent.value || null).filter((c) => c.ok) : []))
const parentOptions = computed(() => parentChoices(props.pages, props.page, template.value))
const topOk = computed(() => topLevelOk(template.value))
// another template: the parent stays if it still fits, else the top level, else the first page the template fits under
watch(template, (k) => { parent.value = pickParent(parentOptions.value, k, parent.value) })

const titleError = computed(() => (!lockTitleUrl.value && !title.value.trim() ? t('pages.title_required') : ''))
// uniqueness is checked among the versions that exist in THIS language (an untranslated row only carries the main language's address)
const sameLang = computed(() => props.pages.filter((x) => x.translated !== false))
const urlErr = computed(() => {
  if (!props.page || rules.value.lockUrl) return serverError.value
  if (pre.value && !url.value.trim()) return t('pages.url_required')
  return urlError(fullUrl.value, sameLang.value, props.page.id, props.page.url, reservedCodes(loc.value)) || serverError.value
})
const show = (e) => (submitted.value ? e : '')
const titleBudget = computed(() => ({ n: [...title.value].length, max: F.title.max }))
const urlBudget = computed(() => ({ n: [...fullUrl.value].length, max: F.url.max }))
const stBudget = computed(() => ({ n: [...seoTitle.value].length, max: F['seo.title'].max }))
const sdBudget = computed(() => ({ n: [...seoDesc.value].length, max: F['seo.description'].max }))
const over = computed(() => (!lockTitleUrl.value && (titleBudget.value.n > titleBudget.value.max || urlBudget.value.n > urlBudget.value.max)) || stBudget.value.n > stBudget.value.max || sdBudget.value.n > sdBudget.value.max)

// the snippet as a search engine would show it
const sTitle = computed(() => seoTitle.value.trim() || title.value.trim() || t('pages.untitled'))
const sUrl = computed(() => `${app.siteInfo.domain}${fullUrl.value || '/'}`)
const sDesc = computed(() => seoDesc.value.trim())

const close = () => emit('update:open', false)
function onUrlBlur() { url.value = normalizeUrl(url.value) }

const urlHelp = computed(() => {
  if (isHome.value) return HOME_URL_HELP
  const redirect = props.page && props.page.published && !lockTitleUrl.value
  if (pre.value) return cap(t(redirect ? 'pages.url_help_prefix_redirect' : 'pages.url_help_prefix', { version: ver.value.gen, prefix: pre.value }))
  return redirect ? F.url.help : t('pages.url_help_plain')
})
// the bold language is in the middle of the sentence, whose order differs between the languages: the text is cut at the place of the name
const MARK = '\u0001'
const note = computed(() => t(rules.value.lockParent ? 'pages.settings_note_home' : 'pages.settings_note', { version: MARK }).split(MARK))
const parentHelp = computed(() => {
  if (isHome.value) return HOME_PARENT_HELP
  return parentHelpText(template.value, multi.value)
})
const templateHelp = computed(() => {
  if (isHome.value) return HOME_TEMPLATE_HELP
  return templateHelpText(templateOptions.value.length, multi.value)
})

async function save() {
  submitted.value = true
  serverError.value = ''
  if (titleError.value || urlErr.value || over.value) {
    await nextTick()
    const bad = document.querySelector('.ps-dialog [aria-invalid="true"]')
    if (bad) bad.focus()
    return
  }
  if (template.value !== props.page.template) {
    const lost = lostFieldsText(props.page, template.value, props.pages)
    if (lost) {
      const ok = await confirm({ title: t('pages.template_change_title'), message: t('pages.template_change_message', { fields: lost }), confirmLabel: t('pages.template_change_confirm'), danger: true })
      if (!ok) return
    }
  }
  busy.value = true
  try {
    const page = await api.pages.update(props.page.id, loc.value, {
      title: title.value.trim(), url: fullUrl.value, parentId: parent.value || null, template: template.value,
      seo: { title: seoTitle.value.trim(), description: seoDesc.value.trim() },
    })
    emit('saved', page)
    close()
  } catch (e) {
    if (e.status === 409) serverError.value = e.message
    else toast.error(e.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open && !!page" :title="t('pages.settings_title')" :width="600" initial-focus="#ps-title" :close-on-scrim="!dirty" @update:open="emit('update:open', $event)">
    <form v-if="page" class="ps-dialog" novalidate @submit.prevent="save">
      <div v-if="multi" class="alert info-a"><Icon name="globe" size="s16" /><div>{{ note[0] }}<b>{{ ver.acc }}</b>{{ note[1] }}</div></div>
      <FieldShell :label="F.title.label" for-id="ps-title" :budget="titleBudget" :error="show(titleError)" :help="F.title.help">
        <input id="ps-title" v-model="title" type="text" autocomplete="off" :disabled="lockTitleUrl" :aria-invalid="show(titleError) ? 'true' : undefined">
      </FieldShell>

      <FieldShell :label="F.url.label" for-id="ps-url" :budget="urlBudget" :error="urlErr" :help="urlHelp">
        <PageUrlInput id="ps-url" v-model="url" :prefix="pre" :disabled="rules.lockUrl" :invalid="!!urlErr" @input="serverError = ''" @blur="onUrlBlur" />
      </FieldShell>

      <FieldShell :label="t('pages.template_label')" for-id="ps-tpl" :help="templateHelp">
        <select id="ps-tpl" v-model="template" :disabled="rules.lockTemplate">
          <option v-for="c in templateOptions" :key="c.template.key" :value="c.template.key">{{ c.template.name }}</option>
        </select>
      </FieldShell>

      <FieldShell :label="t('pages.parent_label')" for-id="ps-parent" :help="parentHelp">
        <select id="ps-parent" v-model="parent" :disabled="rules.lockParent">
          <option v-if="topOk" value="">{{ t('pages.parent_none') }}</option>
          <option v-for="o in parentOptions" :key="o.p.id" :value="o.p.id">{{ indent(o.lvl) }}{{ o.p.title }}</option>
        </select>
      </FieldShell>

      <h3 class="seo-h">{{ t('pages.seo_heading') }}</h3>
      <div class="snip" :aria-label="t('pages.seo_preview_label')">
        <div class="s-url">{{ sUrl }}</div>
        <div class="s-t">{{ sTitle }}</div>
        <div :class="['s-d', { empty: !sDesc }]">{{ sDesc || t('pages.seo_desc_empty') }}</div>
      </div>

      <FieldShell :label="F['seo.title'].label" for-id="ps-st" :budget="stBudget" :help="t('pages.seo_title_help')">
        <input id="ps-st" v-model="seoTitle" type="text" autocomplete="off" :placeholder="title" :aria-invalid="stBudget.n > stBudget.max ? 'true' : undefined">
      </FieldShell>
      <FieldShell :label="F['seo.description'].label" for-id="ps-sd" :budget="sdBudget" :help="t('pages.seo_desc_help')">
        <textarea id="ps-sd" v-model="seoDesc" rows="3" :aria-invalid="sdBudget.n > sdBudget.max ? 'true' : undefined" />
      </FieldShell>

      <p v-if="page.published && dirty" class="note"><Icon name="info" size="s16" />{{ multi ? t('pages.saved_note_multi', { version: ver.gen }) : t('pages.saved_note') }}</p>
      <button type="submit" class="sr" tabindex="-1">{{ t('pages.save') }}</button>
    </form>

    <template #footer>
      <button class="btn" type="button" @click="close">{{ t('pages.cancel') }}</button>
      <button :class="['btn primary', { loading: busy }]" type="button" :disabled="!dirty" @click="save">{{ t('pages.save') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.ps-dialog{display:flex;flex-direction:column;gap:2px}
.ps-dialog .alert{margin-bottom:6px}
.seo-h{font-size:var(--t-base);font-weight:650;margin:14px 0 8px;padding-top:14px;border-top:1px solid var(--hair)}
.snip{border:1px solid var(--hair);border-radius:var(--r);padding:12px 14px;background:var(--sheet-2);margin-bottom:4px;max-width:100%}
.s-url{font-size:var(--t-xs);color:var(--ink-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.s-t{font-size:var(--t-md);color:var(--brand-text);font-weight:520;line-height:1.3;margin:2px 0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.s-d{font-size:var(--t-sm);color:var(--ink-2);line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.s-d.empty{color:var(--ink-3);font-style:italic}
.note{display:flex;gap:8px;align-items:flex-start;margin-top:8px;font-size:var(--t-sm);color:var(--ink-2);line-height:1.45}
.note .ic{margin-top:2px;color:var(--ink-3)}
</style>
