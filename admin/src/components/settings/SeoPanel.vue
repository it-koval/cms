<script setup>
// SEO → «Правила адресов»: titles, descriptions and indexing for ANY address of the site, including those no page of the CMS lives at (a catalogue, a form, a service page).
// A rule laid over an address beats the SEO of the page that answers there, but only in the fields it fills in. Saved at once, no publish step.
// Two parts: the list of rules (search, a drawer to create / change) and «Проверить адрес» (what the address answers and which rule or page each tag comes from).
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import Drawer from '@/components/ui/Drawer.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { app, can } from '@/stores/app.js'
import { isMulti, tag } from '@/utils/locales.js'
import { t } from '@/i18n/index.js'

const toast = useToast()
const confirm = useConfirm()
const multi = computed(() => isMulti())
const canEdit = computed(() => can('set:seo', 'edit'))

const ROBOTS = [
  ['', t('integrations.seo_robots_default')],
  ['index,follow', t('integrations.seo_robots_index_follow')],
  ['index,nofollow', t('integrations.seo_robots_index_nofollow')],
  ['noindex,follow', t('integrations.seo_robots_noindex_follow')],
  ['noindex,nofollow', t('integrations.seo_robots_noindex_nofollow')],
]
const robotsLabel = (v) => (ROBOTS.find((r) => r[0] === v) || ['', v])[1]
const SOURCES = { rule: t('integrations.seo_source_rule'), page: t('integrations.seo_source_page'), title: t('integrations.seo_source_title'), none: t('integrations.seo_source_none') }
const TAGS = [['title', t('integrations.seo_tag_title')], ['description', t('integrations.seo_tag_description')], ['robots', t('integrations.seo_tag_robots')], ['canonical', t('integrations.seo_tag_canonical')], ['ogImage', t('integrations.seo_tag_og_image')]]

const rows = ref([])
const loading = ref(true)
const loadError = ref('')
const q = ref('')

async function load() {
  try { rows.value = await api.settings.listSeoRules(); loadError.value = '' } catch (e) { loadError.value = e.message || t('integrations.seo_load_failed') } finally { loading.value = false }
}
onMounted(load)

const shown = computed(() => {
  const needle = q.value.trim().toLowerCase()
  return needle ? rows.value.filter((r) => `${r.path} ${r.title} ${r.description}`.toLowerCase().includes(needle)) : rows.value
})

/** What a rule sets, as short words for the list. */
function sets(r) {
  const out = []
  if (r.title) out.push(t('integrations.seo_sets_title'))
  if (r.description) out.push(t('integrations.seo_sets_description'))
  if (r.robots) out.push(r.robots.startsWith('noindex') ? t('integrations.seo_sets_hidden') : t('integrations.seo_sets_indexing'))
  if (r.canonical) out.push(t('integrations.seo_sets_canonical'))
  if (r.ogImage) out.push(t('integrations.seo_sets_image'))
  return out
}

/* ---- the form ---- */
const blank = () => ({ id: null, path: '', match: 'exact', locale: '', title: '', description: '', robots: '', canonical: '', ogImage: '', active: true })
const dlg = reactive({ open: false, ...blank() })
const fieldErrors = ref({})
const serverError = ref('')
const saving = ref(false)

function openNew(path = '') {
  Object.assign(dlg, blank(), { open: true, path })
  fieldErrors.value = {}
  serverError.value = ''
}
function openEdit(r) {
  Object.assign(dlg, blank(), { ...r, locale: r.locale || '', open: true })
  fieldErrors.value = {}
  serverError.value = ''
}

async function save() {
  serverError.value = ''
  fieldErrors.value = {}
  saving.value = true
  const { id, open, ...rest } = dlg
  const body = { ...rest, locale: rest.locale || null }
  try {
    if (id) await api.settings.updateSeoRule(id, body)
    else await api.settings.createSeoRule(body)
    dlg.open = false
    await load()
    toast.show(id ? t('integrations.seo_saved') : t('integrations.seo_added'))
    if (checked.value) runCheck(checked.value.path) // the answer under the list follows the change
  } catch (e) {
    if (e.details && typeof e.details === 'object' && !Array.isArray(e.details)) fieldErrors.value = e.details
    else serverError.value = e.message || t('integrations.save_failed')
  } finally {
    saving.value = false
  }
}

async function toggle(r) {
  try {
    await api.settings.updateSeoRule(r.id, { active: !r.active })
    await load()
    toast.show(r.active ? t('integrations.seo_off_toast') : t('integrations.seo_on_toast'))
  } catch (e) { toast.error(e.message || t('integrations.seo_toggle_failed')) }
}

async function remove(r) {
  const ok = await confirm({
    title: t('integrations.seo_delete_title'),
    message: t('integrations.seo_delete_message', { path: r.path }),
    confirmLabel: t('integrations.btn_delete'), danger: true,
  })
  if (!ok) return
  try {
    await api.settings.removeSeoRule(r.id)
    await load()
    toast.show(t('integrations.seo_removed'), {
      undoLabel: t('integrations.toast_undo'),
      undo: async () => {
        try {
          const { id, createdAt, updatedAt, ...body } = r
          await api.settings.createSeoRule(body)
          await load()
        } catch (e) { toast.error(e.message) }
      },
    })
  } catch (e) { toast.error(e.message || t('integrations.delete_failed')) }
}

/* ---- «Проверить адрес» ---- */
const checkPath = ref('')
const checkLocale = ref('')
const checked = ref(null)
const checking = ref(false)
const checkError = ref('')

async function runCheck(path = checkPath.value) {
  const p = String(path || '').trim()
  if (!p) { checkError.value = t('integrations.seo_check_required'); checked.value = null; return }
  checking.value = true
  checkError.value = ''
  try {
    checked.value = await api.settings.checkSeoAddress({ path: p, locale: checkLocale.value || undefined })
    checkPath.value = checked.value.path
  } catch (e) {
    checked.value = null
    checkError.value = (e.details && e.details.path) || e.message || t('integrations.seo_check_failed')
  } finally {
    checking.value = false
  }
}
const kindIcon = { page: 'doc', route: 'globe', redirect: 'swap', missing: 'alert' }
const ruleAt = (id) => rows.value.find((r) => r.id === id)
const ruleChip = (r) => t(`integrations.seo_rule_${r.match === 'prefix' ? 'prefix' : 'exact'}_${r.winner ? 'wins' : 'covered'}`, { path: r.path })
</script>

<template>
  <section class="sheet seo">
    <div class="seo-intro">
      <h2>{{ t('integrations.seo_title') }}</h2>
      <p class="d">{{ t('integrations.seo_intro') }}</p>
    </div>

    <div class="seo-check">
      <form class="seo-check-f" novalidate @submit.prevent="runCheck()">
        <div class="field">
          <div class="lbl"><label for="seo-chk">{{ t('integrations.seo_check_label') }}</label></div>
          <div class="row seo-check-r">
            <input id="seo-chk" v-model="checkPath" type="text" placeholder="/catalog" autocomplete="off" maxlength="500" aria-describedby="seo-chk-h">
            <select v-if="multi" v-model="checkLocale" :aria-label="t('integrations.language')">
              <option value="">{{ t('integrations.main_language') }}</option>
              <option v-for="l in app.languages.list" :key="l.code" :value="l.code">{{ l.name }}</option>
            </select>
            <button type="submit" :class="['btn', { loading: checking }]"><Icon name="search" size="s16" />{{ t('integrations.seo_check_btn') }}</button>
          </div>
          <div id="seo-chk-h" class="help">{{ t('integrations.seo_check_help') }}</div>
          <div v-if="checkError" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ checkError }}</span></div>
        </div>
      </form>

      <div v-if="checked" class="seo-res" aria-live="polite">
        <div :class="['alert', checked.answer.kind === 'missing' ? 'err-a' : 'info-a']">
          <Icon :name="kindIcon[checked.answer.kind] || 'info'" size="s16" />
          <span><b class="num">{{ checked.answer.status }}</b> · {{ checked.answer.label }}</span>
        </div>
        <table v-if="checked.meta" class="t t-stack seo-meta" :aria-label="t('integrations.seo_tags_aria')">
          <thead><tr><th>{{ t('integrations.seo_col_tag') }}</th><th>{{ t('integrations.seo_col_result') }}</th><th>{{ t('integrations.seo_col_source') }}</th></tr></thead>
          <tbody>
            <tr v-for="g in TAGS" :key="g[0]">
              <td class="nw ts-top">{{ g[1] }}</td>
              <td class="seo-val ts-line">{{ checked.meta[g[0]].value ? (g[0] === 'robots' ? robotsLabel(checked.meta[g[0]].value) + ' (' + checked.meta[g[0]].value + ')' : checked.meta[g[0]].value) : '—' }}</td>
              <td class="nw ts-top"><span :class="['chip', { pub: checked.meta[g[0]].source === 'rule' }]">{{ SOURCES[checked.meta[g[0]].source] }}</span></td>
            </tr>
          </tbody>
        </table>
        <div v-if="checked.rules.length" class="seo-rl">
          <span class="muted">{{ t('integrations.seo_rules_match') }}</span>
          <template v-for="r in checked.rules" :key="r.id">
            <button v-if="ruleAt(r.id)" type="button" class="chip seo-rc" @click="openEdit(ruleAt(r.id))">{{ ruleChip(r) }}</button>
          </template>
        </div>
        <div v-else-if="checked.answer.kind !== 'redirect' && checked.answer.kind !== 'missing'" class="seo-rl">
          <span class="muted">{{ t('integrations.seo_no_rules_here') }}</span>
          <button v-if="canEdit" type="button" class="btn sm" @click="openNew(checked.path)"><Icon name="plus" size="s14" />{{ t('integrations.seo_create_rule') }}</button>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <div class="search">
        <label class="sr" for="seo-q">{{ t('integrations.seo_search_label') }}</label>
        <Icon name="search" size="s16" />
        <input id="seo-q" v-model="q" type="search" :placeholder="t('integrations.seo_search_ph')" autocomplete="off">
      </div>
      <span class="grow num">{{ t('integrations.seo_count', { count: rows.length }) }}</span>
      <button v-if="canEdit" type="button" class="btn primary" @click="openNew()"><Icon name="plus" size="s16" />{{ t('integrations.btn_add') }}</button>
    </div>

    <div v-if="loading" class="seo-pad"><Skeleton :rows="4" /></div>
    <div v-else-if="loadError" class="seo-pad"><div class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('integrations.btn_retry') }}</button></div></div>
    <table v-else-if="shown.length" class="t t-stack" :aria-label="t('integrations.seo_table_aria')">
      <thead><tr><th v-if="multi" class="seo-lg">{{ t('integrations.language') }}</th><th>{{ t('integrations.address') }}</th><th>{{ t('integrations.seo_col_sets') }}</th><th>{{ t('integrations.seo_col_state') }}</th><th><span class="sr">{{ t('integrations.actions') }}</span></th></tr></thead>
      <tbody>
        <tr v-for="r in shown" :key="r.id" :class="['click', { 'seo-off': !r.active }]" @click="openEdit(r)">
          <td v-if="multi" class="nw ts-sub"><span class="chip">{{ r.locale ? tag(r.locale) : t('integrations.seo_all') }}</span></td>
          <td class="seo-url ts-main">{{ r.path }}<span v-if="r.match === 'prefix'" class="muted"> {{ t('integrations.seo_and_inside') }}</span>
            <div v-if="r.title" class="seo-t">{{ r.title }}</div>
          </td>
          <td class="seo-sets ts-line">{{ sets(r).join(', ') }}</td>
          <td class="nw ts-sub"><span :class="['chip', { pub: r.active }]">{{ r.active ? t('integrations.seo_state_active') : t('integrations.seo_state_off') }}</span></td>
          <td class="nw seo-acts ts-acts" @click.stop>
            <button v-if="canEdit" type="button" class="btn ghost icon sm" :aria-label="r.active ? t('integrations.seo_turn_off_aria', { path: r.path }) : t('integrations.seo_turn_on_aria', { path: r.path })" :title="r.active ? t('integrations.seo_turn_off') : t('integrations.seo_turn_on')" @click="toggle(r)"><Icon :name="r.active ? 'eyeOff' : 'eye'" size="s14" /></button>
            <button type="button" class="btn ghost icon sm" :aria-label="t('integrations.seo_edit_aria', { path: r.path })" :title="t('integrations.btn_edit')" @click="openEdit(r)"><Icon name="edit" size="s14" /></button>
            <button v-if="can('set:seo', 'delete')" type="button" class="btn ghost icon sm" :aria-label="t('integrations.seo_delete_aria', { path: r.path })" :title="t('integrations.btn_delete')" @click="remove(r)"><Icon name="trash" size="s14" /></button>
          </td>
        </tr>
      </tbody>
    </table>
    <EmptyState v-else-if="rows.length" icon="search" :title="t('integrations.seo_no_match_title')" :text="t('integrations.search_check_text')">
      <button type="button" class="btn" @click="q = ''">{{ t('integrations.btn_reset_search') }}</button>
    </EmptyState>
    <EmptyState v-else icon="globe" :title="t('integrations.seo_empty_title')" :text="t('integrations.seo_empty_text')">
      <button v-if="canEdit" type="button" class="btn primary" @click="openNew()"><Icon name="plus" size="s16" />{{ t('integrations.seo_add_rule') }}</button>
    </EmptyState>
  </section>

  <Drawer v-model:open="dlg.open" :title="dlg.id ? t('integrations.seo_drawer_edit') : t('integrations.seo_drawer_new')" :width="520">
    <form id="seo-form" class="seo-form" novalidate @submit.prevent="save">
      <div :class="['field', { 'is-error': !!fieldErrors.path }]">
        <div class="lbl"><label for="seo-path">{{ t('integrations.address') }}</label></div>
        <input id="seo-path" v-model="dlg.path" type="text" placeholder="/catalog" autocomplete="off" maxlength="500" :disabled="!canEdit" :aria-invalid="fieldErrors.path ? 'true' : undefined">
        <div class="help">{{ t('integrations.seo_path_help') }}</div>
        <div v-if="fieldErrors.path" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.path }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!fieldErrors.match }]">
        <div class="lbl"><label for="seo-match">{{ t('integrations.seo_match_label') }}</label></div>
        <select id="seo-match" v-model="dlg.match" :disabled="!canEdit">
          <option value="exact">{{ t('integrations.seo_match_exact') }}</option>
          <option value="prefix">{{ t('integrations.seo_match_prefix') }}</option>
        </select>
        <div class="help">{{ t('integrations.seo_match_help') }}</div>
        <div v-if="fieldErrors.match" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.match }}</span></div>
      </div>
      <div v-if="multi" class="field">
        <div class="lbl"><label for="seo-loc">{{ t('integrations.language') }}</label></div>
        <select id="seo-loc" v-model="dlg.locale" :disabled="!canEdit">
          <option value="">{{ t('integrations.all_languages') }}</option>
          <option v-for="l in app.languages.list" :key="l.code" :value="l.code">{{ l.name }}</option>
        </select>
      </div>
      <div :class="['field', { 'is-error': !!fieldErrors.title }]">
        <div class="lbl"><label for="seo-title">{{ t('integrations.seo_title_label') }}</label></div>
        <input id="seo-title" v-model="dlg.title" type="text" autocomplete="off" maxlength="255" :disabled="!canEdit" :aria-invalid="fieldErrors.title ? 'true' : undefined">
        <div class="help">{{ t('integrations.seo_title_help') }}</div>
        <div v-if="fieldErrors.title" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.title }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!fieldErrors.description }]">
        <div class="lbl"><label for="seo-desc">{{ t('integrations.seo_desc_label') }}</label></div>
        <textarea id="seo-desc" v-model="dlg.description" rows="3" maxlength="500" :disabled="!canEdit" :aria-invalid="fieldErrors.description ? 'true' : undefined" />
        <div class="help">{{ t('integrations.seo_desc_help') }}</div>
        <div v-if="fieldErrors.description" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.description }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!fieldErrors.robots }]">
        <div class="lbl"><label for="seo-rob">{{ t('integrations.seo_tag_robots') }}</label></div>
        <select id="seo-rob" v-model="dlg.robots" :disabled="!canEdit">
          <option v-for="r in ROBOTS" :key="r[0]" :value="r[0]">{{ r[1] }}</option>
        </select>
        <div v-if="fieldErrors.robots" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.robots }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!fieldErrors.canonical }]">
        <div class="lbl"><label for="seo-can">{{ t('integrations.seo_tag_canonical') }}</label></div>
        <input id="seo-can" v-model="dlg.canonical" type="text" placeholder="/catalog" autocomplete="off" maxlength="512" :disabled="!canEdit" :aria-invalid="fieldErrors.canonical ? 'true' : undefined">
        <div class="help">{{ t('integrations.seo_canonical_help') }}</div>
        <div v-if="fieldErrors.canonical" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.canonical }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!fieldErrors.ogImage }]">
        <div class="lbl"><label for="seo-og">{{ t('integrations.seo_tag_og_image') }}</label></div>
        <input id="seo-og" v-model="dlg.ogImage" type="text" placeholder="/uploads/cover.jpg" autocomplete="off" maxlength="512" :disabled="!canEdit" :aria-invalid="fieldErrors.ogImage ? 'true' : undefined">
        <div class="help">{{ t('integrations.seo_og_help') }}</div>
        <div v-if="fieldErrors.ogImage" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ fieldErrors.ogImage }}</span></div>
      </div>
      <div class="field">
        <label class="sw"><input v-model="dlg.active" type="checkbox" :disabled="!canEdit"><span class="tr" /><span>{{ t('integrations.seo_active_label') }}</span></label>
        <div class="help">{{ t('integrations.seo_active_help') }}</div>
      </div>
      <div v-if="serverError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ serverError }}</span></div>
    </form>
    <template #footer>
      <button type="button" class="btn" @click="dlg.open = false">{{ canEdit ? t('integrations.btn_cancel') : t('integrations.btn_close') }}</button>
      <button v-if="canEdit" type="submit" form="seo-form" :class="['btn', 'primary', { loading: saving }]">{{ t('integrations.btn_save') }}</button>
    </template>
  </Drawer>
</template>

<style scoped>
.seo{margin-bottom:16px}
.seo-intro{padding:20px 22px 12px}
.seo-intro h2{font-size:var(--t-md);font-weight:650;margin-bottom:2px}
.seo-intro .d{color:var(--ink-2);font-size:var(--t-sm);max-width:70ch}
.seo-check{padding:4px 10px 14px;border-bottom:1px solid var(--line)}
.seo-check-r{gap:8px;align-items:center}
.seo-check-r input{flex:1;min-width:0}
.seo-res{padding:0 12px;display:flex;flex-direction:column;gap:10px}
.seo-meta{margin:0}
.seo-val{word-break:break-word;max-width:44ch}
.seo-rl{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:var(--t-sm)}
.seo-rc{cursor:pointer}
.seo-pad{padding:14px}
.seo-lg{width:72px}
.seo-url{word-break:break-all}
.seo-t{color:var(--ink-2);font-size:var(--t-sm);word-break:normal}
.seo-sets{color:var(--ink-2);font-size:var(--t-sm)}
.seo-off .seo-url,.seo-off .seo-sets{opacity:.6}
.seo-acts{text-align:right}
.seo-form{display:flex;flex-direction:column}
.seo-form .field{margin:0 -12px}
</style>
