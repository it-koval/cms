<script setup>
// Переадресации: old address -> new address with a code (301 permanent / 302 temporary). Saved at once, no publish step.
// Validation: address format, same address, a second redirect from the same old address, loops.
// LANGUAGE: ONE list for all languages (full addresses, an English one starts with /en/). On a site with 2+ languages every row carries a language tag
// and the list can be narrowed to one language (a select, like the other list filters); the page picker of the form lists the pages of every language.
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { usePageOptions } from '@/components/form/usePageOptions.js'
import { app, can } from '@/stores/app.js'
import { isMulti, pathPrefix, tag } from '@/utils/locales.js'
import { REDIRECT_CODES } from '@/schema/settings.js'
import { fmtD } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const pages = usePageOptions()
const multi = computed(() => isMulti())
const lang = ref('') // '' = every language
// pages of every language for the picker of the form (multilingual sites): [{ code, name, pages:[{ value, label }] }]
const pageGroups = ref([])
async function loadGroups() {
  if (!isMulti()) return
  try {
    pageGroups.value = await Promise.all(app.languages.list.map(async (l) => ({
      code: l.code, name: l.name,
      pages: (await api.pages.list({ locale: l.code })).filter((p) => p.translated).map((p) => ({ value: p.url, label: p.title })),
    })))
  } catch (e) { pageGroups.value = [] }
}
const prefixes = computed(() => app.languages.list.filter((l) => pathPrefix(l.code)).map((l) => pathPrefix(l.code) + '/…').join(', '))

const rows = ref([])
const loading = ref(true)
const loadError = ref('')
const q = ref('')

const dlg = reactive({ open: false, id: null, from: '', to: '', code: '301' })
const touched = reactive({ from: false, to: false })
const submitted = ref(false)
const saving = ref(false)
const serverError = ref('')

async function load() {
  try { rows.value = await api.settings.listRedirects(); loadError.value = '' } catch (e) { loadError.value = e.message || t('integrations.rd_load_failed') } finally { loading.value = false }
}
onMounted(() => { load(); loadGroups() })

const byLang = computed(() => (lang.value ? rows.value.filter((r) => r.locale === lang.value) : rows.value))
const shown = computed(() => {
  const needle = q.value.trim().toLowerCase()
  return needle ? byLang.value.filter((r) => `${r.from} ${r.to}`.toLowerCase().includes(needle)) : byLang.value
})
const resetFilters = () => { q.value = ''; lang.value = '' }
const codeLabel = (c) => (REDIRECT_CODES.find((x) => x[0] === Number(c)) || [c, c])[1]

function normalize(v) { return String(v || '').trim() }
/** Messages for the open form: { from, to }. */
const errors = computed(() => {
  const from = normalize(dlg.from)
  const to = normalize(dlg.to)
  const e = { from: '', to: '' }
  if (!from) e.from = t('integrations.rd_err_from_required')
  else if (!from.startsWith('/')) e.from = t('integrations.rd_err_from_slash')
  else if (/\s/.test(from)) e.from = t('integrations.rd_err_from_space')
  else if (rows.value.some((r) => r.id !== dlg.id && r.from === from)) e.from = t('integrations.rd_err_from_exists')
  if (!to) e.to = t('integrations.rd_err_to_required')
  else if (!/^(\/|https?:\/\/)\S*$/i.test(to)) e.to = t('integrations.rd_err_to_format')
  else if (from && to === from) e.to = t('integrations.rd_err_same')
  else if (from && !e.from && loops(from, to)) e.to = t('integrations.rd_err_loop')
  return e
})
// follow the chain new -> (its own redirect) -> ... ; reaching the old address means a loop
function loops(from, to) {
  const next = new Map(rows.value.filter((r) => r.id !== dlg.id).map((r) => [r.from, r.to]))
  let cur = to
  for (let i = 0; i < 12 && next.has(cur); i++) {
    cur = next.get(cur)
    if (cur === from) return true
  }
  return false
}
const showErr = (k) => (touched[k] || submitted.value ? errors.value[k] : '')

function openNew() {
  Object.assign(dlg, { open: true, id: null, from: '', to: '', code: '301' })
  Object.assign(touched, { from: false, to: false })
  submitted.value = false
  serverError.value = ''
}
function openEdit(r) {
  Object.assign(dlg, { open: true, id: r.id, from: r.from, to: r.to, code: String(r.code) })
  Object.assign(touched, { from: true, to: true })
  submitted.value = false
  serverError.value = ''
}
function pickPage(e) {
  if (e.target.value) { dlg.to = e.target.value; touched.to = true }
  e.target.value = ''
}

async function save() {
  submitted.value = true
  serverError.value = ''
  if (errors.value.from || errors.value.to) return
  saving.value = true
  const body = { from: normalize(dlg.from), to: normalize(dlg.to), code: Number(dlg.code) }
  try {
    if (dlg.id) await api.settings.updateRedirect(dlg.id, body)
    else await api.settings.createRedirect(body)
    dlg.open = false
    await load()
    toast.show(dlg.id ? t('integrations.rd_saved') : t('integrations.rd_added'))
  } catch (e) {
    serverError.value = e.message || t('integrations.save_failed')
  } finally {
    saving.value = false
  }
}

async function remove(r) {
  const ok = await confirm({
    title: t('integrations.rd_delete_title'),
    message: t('integrations.rd_delete_message', { from: r.from, to: r.to }),
    confirmLabel: t('integrations.btn_delete'), danger: true,
  })
  if (!ok) return
  try {
    await api.settings.removeRedirect(r.id)
    await load()
    toast.show(t('integrations.rd_removed'), {
      undoLabel: t('integrations.toast_undo'),
      undo: async () => { try { await api.settings.createRedirect({ from: r.from, to: r.to, code: r.code }); await load() } catch (e) { toast.error(e.message) } },
    })
  } catch (e) {
    toast.error(e.message || t('integrations.delete_failed'))
  }
}
</script>

<template>
  <section class="sheet rd">
    <div class="rd-intro">
      <h2>{{ t('integrations.rd_title') }}</h2>
      <p class="d">{{ t('integrations.rd_intro') }}</p>
    </div>
    <div class="toolbar">
      <div class="search">
        <label class="sr" for="rd-q">{{ t('integrations.rd_search_label') }}</label>
        <Icon name="search" size="s16" />
        <input id="rd-q" v-model="q" type="search" :placeholder="t('integrations.rd_search_ph')" autocomplete="off">
      </div>
      <select v-if="multi" v-model="lang" :aria-label="t('integrations.language')">
        <option value="">{{ t('integrations.all_languages') }}</option>
        <option v-for="l in app.languages.list" :key="l.code" :value="l.code">{{ l.name }}</option>
      </select>
      <span class="grow num">{{ t('integrations.rd_count', { count: byLang.length }) }}</span>
      <button type="button" class="btn primary" @click="openNew"><Icon name="plus" size="s16" />{{ t('integrations.btn_add') }}</button>
    </div>

    <div v-if="loading" class="rd-pad"><Skeleton :rows="4" /></div>
    <div v-else-if="loadError" class="rd-pad"><div class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('integrations.btn_retry') }}</button></div></div>
    <table v-else-if="shown.length" class="t t-stack" :aria-label="t('integrations.rd_table_aria')">
      <thead><tr><th v-if="multi" class="rd-lg">{{ t('integrations.language') }}</th><th>{{ t('integrations.rd_col_old') }}</th><th>{{ t('integrations.rd_col_new') }}</th><th>{{ t('integrations.rd_col_type') }}</th><th>{{ t('integrations.rd_col_added') }}</th><th><span class="sr">{{ t('integrations.actions') }}</span></th></tr></thead>
      <tbody>
        <tr v-for="r in shown" :key="r.id" class="click" @click="openEdit(r)">
          <td v-if="multi" class="nw ts-sub"><span class="chip">{{ tag(r.locale) }}</span></td>
          <td class="rd-url ts-main">{{ r.from }}</td>
          <td class="rd-url ts-line"><Icon name="chevR" size="s14" class="rd-arrow" />{{ r.to }}</td>
          <td class="nw ts-sub"><span class="chip" :title="codeLabel(r.code)">{{ t(Number(r.code) === 301 ? 'integrations.rd_chip_permanent' : 'integrations.rd_chip_temporary', { code: r.code }) }}</span></td>
          <td class="nw num muted ts-sub" :data-label="t('integrations.rd_col_added')">{{ fmtD(r.createdAt) }}</td>
          <td class="nw rd-acts ts-acts" @click.stop>
            <button type="button" class="btn ghost icon sm" :aria-label="t('integrations.seo_edit_aria', { path: r.from })" :title="t('integrations.btn_edit')" @click="openEdit(r)"><Icon name="edit" size="s14" /></button>
            <button v-if="can('set:redirects', 'delete')" type="button" class="btn ghost icon sm" :aria-label="t('integrations.seo_delete_aria', { path: r.from })" :title="t('integrations.btn_delete')" @click="remove(r)"><Icon name="trash" size="s14" /></button>
          </td>
        </tr>
      </tbody>
    </table>
    <EmptyState v-else-if="rows.length" icon="search" :title="lang && !q.trim() ? t('integrations.rd_none_lang_title') : t('integrations.rd_no_match_title')" :text="lang && !q.trim() ? t('integrations.rd_none_lang_text') : t('integrations.search_check_text')">
      <button type="button" class="btn" @click="resetFilters">{{ lang ? t('integrations.rd_show_all') : t('integrations.btn_reset_search') }}</button>
    </EmptyState>
    <EmptyState v-else icon="swap" :title="t('integrations.rd_empty_title')" :text="t('integrations.rd_empty_text')">
      <button type="button" class="btn primary" @click="openNew"><Icon name="plus" size="s16" />{{ t('integrations.rd_add_btn') }}</button>
    </EmptyState>
  </section>

  <Modal v-model:open="dlg.open" :title="dlg.id ? t('integrations.rd_modal_edit') : t('integrations.rd_modal_new')" :width="560" initial-focus="#rd-from">
    <form id="rd-form" class="rd-form" novalidate @submit.prevent="save">
      <div :class="['field', { 'is-error': !!showErr('from') }]">
        <div class="lbl"><label for="rd-from">{{ t('integrations.rd_col_old') }}</label></div>
        <input id="rd-from" v-model="dlg.from" type="text" placeholder="/old-page" autocomplete="off" maxlength="120" aria-describedby="rd-from-h" :aria-invalid="showErr('from') ? 'true' : undefined" @blur="touched.from = true">
        <div id="rd-from-h" class="help">{{ t('integrations.rd_from_help') }}<template v-if="multi"> {{ t('integrations.rd_from_prefix_note', { prefixes }) }}</template></div>
        <div v-if="showErr('from')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ showErr('from') }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!showErr('to') }]">
        <div class="lbl"><label for="rd-to">{{ t('integrations.rd_col_new') }}</label></div>
        <input id="rd-to" v-model="dlg.to" type="text" placeholder="/new-page" autocomplete="off" maxlength="160" :aria-invalid="showErr('to') ? 'true' : undefined" @blur="touched.to = true">
        <div class="row rd-pick">
          <label class="muted rd-pick-l" for="rd-page">{{ t('integrations.rd_pick_label') }}</label>
          <select id="rd-page" @change="pickPage">
            <option value="">{{ t('integrations.rd_pick_option') }}</option>
            <template v-if="multi">
              <optgroup v-for="g in pageGroups" :key="g.code" :label="g.name">
                <option v-for="p in g.pages" :key="p.value" :value="p.value">{{ p.label }} — {{ p.value }}</option>
              </optgroup>
            </template>
            <template v-else>
              <option v-for="p in pages" :key="p.value" :value="p.value">{{ p.label }} — {{ p.value }}</option>
            </template>
          </select>
        </div>
        <div v-if="showErr('to')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ showErr('to') }}</span></div>
      </div>
      <div class="field">
        <div class="lbl"><label for="rd-code">{{ t('integrations.rd_col_type') }}</label></div>
        <select id="rd-code" v-model="dlg.code">
          <option v-for="c in REDIRECT_CODES" :key="c[0]" :value="String(c[0])">{{ c[1] }}</option>
        </select>
        <div class="help">{{ t('integrations.rd_code_help') }}</div>
      </div>
      <div v-if="serverError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ serverError }}</span></div>
    </form>
    <template #footer>
      <button type="button" class="btn" @click="dlg.open = false">{{ t('integrations.btn_cancel') }}</button>
      <button type="submit" form="rd-form" :class="['btn', 'primary', { loading: saving }]">{{ t('integrations.btn_save') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.rd{margin-bottom:16px}
.rd-intro{padding:20px 22px 12px}
.rd-intro h2{font-size:var(--t-md);font-weight:650;margin-bottom:2px}
.rd-intro .d{color:var(--ink-2);font-size:var(--t-sm);max-width:64ch}
.rd-pad{padding:14px}
.rd-lg{width:64px}
.rd-url{font-variant-numeric:tabular-nums;word-break:break-all}
.rd-arrow{color:var(--ink-3);margin-right:6px;vertical-align:-2px}
.rd-acts{text-align:right}
.rd-form{display:flex;flex-direction:column}
.rd-form .field{margin:0 -12px}
.rd-pick{margin-top:2px}
.rd-pick-l{font-size:var(--t-sm);white-space:nowrap}
.rd-pick select{flex:1;min-width:0}
</style>
