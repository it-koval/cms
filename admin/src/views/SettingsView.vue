<script setup>
// Настройки сайта: /settings/:tab? — a left navigation (NAV of components/settings/settingsSchema.js) and the panel of the chosen item:
//   Основное — ALL settings groups of the site schema on one page, one card per group, with a row of links that scroll to a card (/settings/<group> opens it scrolled to that card);
//   Меню (areas + their items), Переадресации, Уведомления (Telegram and the e-mail addresses for requests), Почта (own panel, saved at once), Пользователи и роли (segments; /settings/roles opens «Роли»), Шаблоны (read-only).
// Settings and menus are edited as drafts and saved together from the bottom bar (no publish step; saved = live).
// Redirects are saved at once, row by row; so are the menu AREAS (add / rename / delete, they are structure), while the ITEMS of the areas are drafts. Magenta marks and counters show what is not saved yet.
// Rights: an item the role cannot view is not in the navigation; a settings group it can only view is a read-only card (per card, not per page).
// LANGUAGE: the texts of the site (settings fields flagged `tr`) and the menus belong to the content
// language app.locale (fixed per mount: the route is re-mounted when it changes); every other setting, Telegram and redirects are shared by all languages.
// «SEO» has two segments, the rules of addresses (SeoPanel) and robots.txt / llms.txt (SeoFilesPanel, /settings/seo-files); both save at once, like «Пользователи» and «Роли».
// «Пользователи» and «Роли» (UsersPanel, RolesPanel) save at once and are not part of the save bar. On a site with 2+ languages every item says which of the two it is (LangScope above the panel, captions inside the cards).
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { api } from '@/api/index.js'
import { app, can, useLocaleGuard } from '@/stores/app.js'
import { isMulti, pathPrefix } from '@/utils/locales.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import SettingsFormTab from '@/components/settings/SettingsFormTab.vue'
import MenusEditor from '@/components/settings/MenusEditor.vue'
import TemplatesPanel from '@/components/settings/TemplatesPanel.vue'
import RedirectsPanel from '@/components/settings/RedirectsPanel.vue'
import SeoPanel from '@/components/settings/SeoPanel.vue'
import SeoFilesPanel from '@/components/settings/SeoFilesPanel.vue'
import NotificationsPanel from '@/components/settings/NotificationsPanel.vue'
import UsersPanel from '@/components/settings/UsersPanel.vue'
import ThemePanel from '@/components/settings/ThemePanel.vue'
import McpPanel from '@/components/settings/McpPanel.vue'
import MailPanel from '@/components/settings/MailPanel.vue'
import BotsPanel from '@/components/settings/BotsPanel.vue'
import RolesPanel from '@/components/settings/RolesPanel.vue'
import LangScope from '@/components/settings/LangScope.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import { domId } from '@/components/form/fieldProps.js'
import { onLang } from '@/utils/langWords.js'
import { textsLabel } from '@/components/settings/langText.js'
import { GROUPS, NAV, buildSections, groupOfKey, itemAreas, itemOfKey, resolveTab, settingsErrors } from '@/components/settings/settingsSchema.js'
import { getSettingsFields } from '@/schema/settings.js'
import { flattenItems, menuErrorCount } from '@/components/settings/menuModel.js'
import { changedKeys } from '@/utils/fields.js'
import { clone, same } from '@/utils/objects.js'
import { rebaseSettings } from '@/components/settings/rebaseSettings.js'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { t } from '@/i18n/index.js'
import { previewField } from '@/components/editor/previewTarget.js'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const LOC = app.locale

// the items this role may open, under their headings; an unknown or missing :tab opens the first of them
const navGroups = computed(() => NAV.map((n) => ({ heading: n.heading, items: n.items.filter((i) => itemAreas(i).some((a) => can(a))) })).filter((n) => n.items.length))
const target = computed(() => resolveTab(route.params.tab))
const item = computed(() => {
  const to = target.value
  const open = navGroups.value.flatMap((n) => n.items)
  return (to && open.find((i) => i.key === to.item) ? to.item : (open[0] || {}).key) || 'general'
})
// the segment of «Пользователи и роли» (users | roles) or of «SEO» (rules | files)
const segment = computed(() => (target.value && target.value.item === item.value && target.value.segment) || (item.value === 'seo' ? 'rules' : 'users'))
const SEGMENT_TAB = { users: 'users', roles: 'roles', rules: 'seo', files: 'seo-files' } // segment -> :tab of the route
const itemLabel = computed(() => (navGroups.value.flatMap((n) => n.items).find((i) => i.key === item.value) || {}).label || '')
// Menus, Redirects and Notifications with no «Править»: shown read-only. Users and Roles keep their rows clickable and hide their own buttons; Шаблоны is read-only for everybody;
// the cards of «Основное» are read-only one by one
const areaOf = (key) => (NAV.flatMap((n) => n.items).find((i) => i.key === key) || {}).area
const viewOnly = computed(() => ['menus', 'redirects', 'notifications'].includes(item.value) && !can(areaOf(item.value), 'edit'))
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const savedSettings = ref(null)
const draftSettings = ref(null)
const savedMenus = ref(null)
const draftMenus = ref(null)
const menuAreas = ref([])
const forms = ref([])
const settingsForms = ref([])

const READ_ONLY = ['tgFallback', 'tgReady', 'maxFallback', 'mailReady', 'maxReady'] // yes / no answers of the server, never sent back

function normalize(s) {
  const o = clone(s)
  o.tgOn ??= true
  o.maxOn ??= true
  if (!o.tgRoutes || Array.isArray(o.tgRoutes)) o.tgRoutes = {} // an empty PHP array arrives as []
  if (!o.maxRoutes || Array.isArray(o.maxRoutes)) o.maxRoutes = {}
  if (!o.mailRoutes || Array.isArray(o.mailRoutes)) o.mailRoutes = {}
  o.forms ??= true
  getSettingsFields().forEach((f) => { if (f.t === 'repeater') o[f.k] ??= [] })
  return o
}

async function load() {
  loading.value = true
  try {
    // menus need the right to Меню; a role without it gets none (the server answers 403)
    const menus = can('set:menus')
    const [s, m, f, areas] = await Promise.all([api.settings.get(LOC), menus ? api.settings.getMenus(LOC) : {}, api.requests.forms(), menus ? api.settings.getMenuAreas() : []])
    menuAreas.value = areas
    savedSettings.value = normalize(s)
    draftSettings.value = clone(savedSettings.value)
    savedMenus.value = m
    draftMenus.value = clone(m)
    forms.value = f
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('settings.load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---- «Основное»: one card per settings group the role may view ---- */
const fieldsOf = (secs) => secs.flatMap((s) => s.fields)
const changedOf = (secs) => new Set(draftSettings.value ? changedKeys(fieldsOf(secs), draftSettings.value, savedSettings.value) : [])
const cards = computed(() => GROUPS.filter((g) => can(g.area)).map((g) => {
  const sections = buildSections(g.key)
  return { ...g, sections, changed: changedOf(sections), editable: can(g.area, 'edit') }
}))

// the errors that stop saving: only of the places the role can edit (a group it only views cannot be fixed by it)
const errors = computed(() => {
  if (!draftSettings.value) return {}
  return Object.fromEntries(Object.entries(settingsErrors(draftSettings.value, changedPatch())).filter(([k]) => (['tgRoutes', 'maxRoutes', 'mailRoutes'].includes(k) ? can('set:notifications', 'edit') : can('set:' + groupOfKey(k), 'edit'))))
})

const counts = computed(() => {
  if (!draftSettings.value) return {}
  const d = draftSettings.value
  const s = savedSettings.value
  return {
    notifications: (same(d.tgOn, s.tgOn) ? 0 : 1) + (same(d.forms, s.forms) ? 0 : 1) + (same(d.tgRoutes, s.tgRoutes) ? 0 : 1) + (same(d.maxOn, s.maxOn) ? 0 : 1) + (same(d.maxRoutes, s.maxRoutes) ? 0 : 1) + (same(d.mailRoutes, s.mailRoutes) ? 0 : 1),
    menus: menuAreas.value.filter((a) => !same(draftMenus.value[a.key], savedMenus.value[a.key])).length,
    general: GROUPS.reduce((n, g) => n + changedOf(buildSections(g.key)).size, 0),
  }
})
const total = computed(() => (counts.value.notifications || 0) + (counts.value.menus || 0) + (counts.value.general || 0))

// what the line above the panel tells about languages (sites with 2+ languages only)
const scope = computed(() => {
  if (!isMulti() || ['templates', 'users', 'theme', 'mail', 'bots', 'mcp'].includes(item.value) || (item.value === 'seo' && segment.value === 'files')) return null
  const other = app.languages.list.map((l) => pathPrefix(l.code)).find(Boolean) || '/en'
  switch (item.value) {
    case 'menus': return { mode: 'lang', title: t('settings.scope_menus_title', { lang: onLang(LOC) }), text: t('settings.scope_menus_text') }
    case 'seo': return { mode: 'shared', text: t('settings.scope_seo', { example: `${other}/about-us` }) }
    case 'redirects': return { mode: 'shared', text: t('settings.scope_redirects', { example: `${other}/about-us` }) }
    case 'notifications': return { mode: 'shared', text: t('settings.scope_notifications') }
    default: return cards.value.some((c) => fieldsOf(c.sections).some((f) => f.tr))
      ? { mode: 'lang', title: textsLabel(LOC), text: t('settings.scope_texts') }
      : { mode: 'shared', text: t('settings.scope_shared') }
  }
})
const settingsDirty = computed(() => !!counts.value.general || !!counts.value.notifications)
const menusDirty = computed(() => !!counts.value.menus)

// an area was added / renamed / deleted / made flat (saved at once): the lists of items follow the new list of areas
function onAreas(next, extra = {}) {
  menuAreas.value = next
  const follow = (m) => Object.fromEntries(next.map((a) => [a.key, a.key === extra.flattened ? flattenItems(m[a.key]) : m[a.key] || []]))
  savedMenus.value = follow(savedMenus.value)
  draftMenus.value = follow(draftMenus.value)
}

// only what changed goes to the server: a role that edits one group must not send (and need rights for) the keys of the others
function changedPatch() {
  const d = draftSettings.value
  const s = savedSettings.value
  return Object.fromEntries(Object.keys(d).filter((k) => !READ_ONLY.includes(k) && !same(d[k], s[k])).map((k) => [k, d[k]]))
}

function setField(k, v) { draftSettings.value = { ...draftSettings.value, [k]: v } }
function revertField(k) {
  setField(k, clone(savedSettings.value[k]))
  toast.show(t('settings.change_reverted'))
}
function onBotsSaved(next) {
  for (const [key, bot] of [['tgReady', 'telegram'], ['maxReady', 'max']]) {
    savedSettings.value[key] = draftSettings.value[key] = next[bot].ready
  }
}
function discard() {
  draftSettings.value = clone(savedSettings.value)
  draftMenus.value = clone(savedMenus.value)
  toast.show(t('settings.changes_discarded'))
}

/* ---- navigation: items, segments, links to the cards of «Основное» ---- */
const smooth = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth')
function goItem(key) {
  if (key === item.value && (key !== 'general' || !(target.value && target.value.group))) return
  router.push({ name: 'settings', params: { tab: key } }).then(() => window.scrollTo({ top: 0 }))
}
function goSegment(key) { if (key !== segment.value) router.push({ name: 'settings', params: { tab: SEGMENT_TAB[key] } }) }

function scrollToGroup(key, behavior = smooth()) {
  activeGroup.value = key
  const card = document.getElementById('card-' + key)
  if (!card) return
  card.scrollIntoView({ block: 'start', behavior })
  const h = document.getElementById('sec-' + key)
  if (h) h.focus({ preventScroll: true })
}
// the card in view: the group link of the nav that is highlighted while the page scrolls (IntersectionObserver on the cards)
const activeGroup = ref('')
let spy = null
const seen = new Set()
function watchCards() {
  if (spy) spy.disconnect()
  spy = null
  seen.clear()
  if (loading.value || item.value !== 'general' || typeof IntersectionObserver === 'undefined') return
  spy = new IntersectionObserver((entries) => {
    entries.forEach((e) => (e.isIntersecting ? seen.add(e.target.id) : seen.delete(e.target.id)))
    // the last card that has reached the upper 40% of the window, else the first one on screen
    const shown = cards.value.filter((c) => seen.has('card-' + c.key))
    const reached = shown.filter((c) => document.getElementById('card-' + c.key).getBoundingClientRect().top <= window.innerHeight * 0.4)
    const pick = reached[reached.length - 1] || shown[0]
    if (pick) activeGroup.value = pick.key
  }, { rootMargin: '-40px 0px 0px 0px', threshold: Array.from({ length: 21 }, (_, i) => i / 20) })
  cards.value.forEach((c) => { const el = document.getElementById('card-' + c.key); if (el) spy.observe(el) })
}
watch([loading, item, () => cards.value.length], () => nextTick(watchCards), { immediate: true })
onBeforeUnmount(() => { if (spy) spy.disconnect() })

// an old link /settings/<group>: «Основное», scrolled to the card of the group
watch([() => route.params.tab, loading], ([, busy], old) => {
  const to = target.value
  if (busy || !to || !to.group) return
  nextTick(() => scrollToGroup(to.group, old && old[1] === false ? smooth() : 'auto'))
}, { immediate: true })

// the field with an error: «Основное» (or the item the error belongs to), scrolled to, in focus
async function reveal(k) {
  const to = itemOfKey(k)
  if (item.value !== to || (target.value && target.value.group)) await router.push({ name: 'settings', params: { tab: to } })
  await nextTick()
  const prefix = { tgRoutes: 'tn-', maxRoutes: 'mx-', mailRoutes: 'mn-' }[k]
  const el = prefix
    ? document.querySelector(`input[id^="${prefix}"][aria-invalid="true"]`)
    : document.getElementById(domId('set', k))
  const box = el && (el.closest('.field') || el)
  if (!box) return
  box.scrollIntoView({ block: 'center', behavior: smooth() })
  const control = el.matches('input,textarea,select,button,[tabindex]') ? el : box.querySelector('input,textarea,select,button')
  if (control) control.focus({ preventScroll: true })
}

watch([loading, () => route.query.field], async ([busy, path]) => {
  const field = previewField(getSettingsFields(), path)
  if (busy || loadError.value || !field || !can('set:' + groupOfKey(field.k), 'view')) return
  if (item.value !== 'general') await router.push({ name: 'settings', params: { tab: 'general' }, query: route.query })
  await nextTick()
  for (const form of settingsForms.value) {
    if (await form.focusField(path)) break
  }
})

/* ---- save ---- */
let savePromise = null

function save() {
  if (saving.value || !total.value) return
  const keys = Object.keys(errors.value)
  if (settingsDirty.value && keys.length) {
    reveal(keys.find((k) => itemOfKey(k) === item.value) || keys[0])
    toast.error(t('settings.save_fix_fields'))
    return
  }
  if (menusDirty.value && menuErrorCount(draftMenus.value)) {
    goItem('menus')
    toast.error(t('settings.save_menu_errors'))
    return
  }
  // Both requests use the data validated by this click. Edits made while either waits stay in the draft.
  const patch = settingsDirty.value ? clone(changedPatch()) : null
  const sentSettings = patch ? clone(draftSettings.value) : null
  const sentMenus = menusDirty.value ? clone(draftMenus.value) : null
  savePromise = persist(patch, sentSettings, sentMenus)
}

async function persist(patch, sentSettings, sentMenus) {
  saving.value = true
  try {
    if (patch) {
      const res = normalize(await api.settings.update(patch, LOC))
      savedSettings.value = res
      draftSettings.value = rebaseSettings(res, sentSettings, draftSettings.value)
    }
    if (sentMenus) {
      const res = await api.settings.saveMenus(sentMenus, LOC)
      savedMenus.value = clone(res)
      if (same(draftMenus.value, sentMenus)) draftMenus.value = clone(res)
    }
    toast.show(t('settings.saved'))
  } catch (e) {
    toast.error(e.message || t('settings.save_failed'))
  } finally {
    saving.value = false
    savePromise = null
  }
}

async function confirmUnsaved(languageSwitch = false) {
  if (savePromise) await savePromise
  if (!total.value) return true
  return confirm({
    title: t(languageSwitch ? 'settings.switch_title' : 'settings.leave_title'),
    message: t(languageSwitch ? 'settings.switch_message' : 'settings.leave_message', { total: total.value }),
    confirmLabel: t(languageSwitch ? 'settings.switch_confirm' : 'settings.leave_confirm'), cancelLabel: t('settings.stay'), danger: true,
  })
}

onBeforeRouteLeave((to) => to.name === 'settings' || confirmUnsaved())

const unsavedBar = t('settings.unsaved_bar').split('{total}') // the number stands in bold between the halves of the sentence

useLocaleGuard(() => confirmUnsaved(true))
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div>
        <h1>{{ t('settings.title') }}</h1>
        <p class="sub">{{ t('settings.subtitle') }}</p>
      </div>
    </div>

    <div class="set-wrap">
      <div class="settings">
        <nav class="snav" :aria-label="t('settings.nav_label')">
          <div v-for="n in navGroups" :key="n.heading" class="snav-g" role="group" :aria-label="n.heading">
            <div class="snav-h" aria-hidden="true">{{ n.heading }}</div>
            <template v-for="i in n.items" :key="i.key">
              <router-link v-slot="{ href }" :to="{ name: 'settings', params: { tab: i.key } }" custom>
                <a :href="href" :aria-current="item === i.key ? 'page' : undefined" @click.prevent="goItem(i.key)">
                  <span>{{ i.label }}</span>
                  <span v-if="counts[i.key]" class="cnt" :aria-label="t('settings.unsaved_count', { n: counts[i.key] })">{{ counts[i.key] }}</span>
                </a>
              </router-link>
              <div v-if="i.key === 'general' && item === 'general' && !loading && cards.length > 1" class="snav-sub" role="group" :aria-label="t('settings.groups_label')">
                <button v-for="c in cards" :key="c.key" type="button" :aria-current="activeGroup === c.key ? 'true' : undefined" @click="scrollToGroup(c.key)">
                  <span>{{ c.title }}</span>
                  <span v-if="c.changed.size" class="cnt" :aria-label="t('settings.unsaved_count', { n: c.changed.size })">{{ c.changed.size }}</span>
                </button>
              </div>
            </template>
          </div>
        </nav>
        <div class="snav-sel">
          <label class="sr" for="set-sel">{{ t('settings.section_label') }}</label>
          <select id="set-sel" @change="goItem($event.target.value)">
            <optgroup v-for="n in navGroups" :key="n.heading" :label="n.heading">
              <option v-for="i in n.items" :key="i.key" :value="i.key" :selected="item === i.key">{{ i.label }}{{ counts[i.key] ? ` (${counts[i.key]})` : '' }}</option>
            </optgroup>
          </select>
        </div>

        <section class="set-panel" :aria-label="itemLabel">
          <div v-if="loading" class="sheet set-load"><Skeleton :rows="7" /></div>
          <div v-else-if="loadError" class="alert err-a set-load"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('settings.retry') }}</button></div>
          <template v-else>
            <LangScope v-if="scope" :mode="scope.mode" :title="scope.title" :text="scope.text" />
            <template v-if="item === 'general'">
              <h2 class="sr">{{ t('settings.nav_general') }}</h2>
              <nav v-if="cards.length > 1" class="set-jump" :aria-label="t('settings.groups_label')">
                <button v-for="c in cards" :key="c.key" type="button" @click="scrollToGroup(c.key)">
                  {{ c.title }}
                  <span v-if="c.changed.size" class="cnt" :aria-label="t('settings.unsaved_count', { n: c.changed.size })">{{ c.changed.size }}</span>
                </button>
              </nav>
              <SettingsFormTab
                v-for="c in cards" :key="c.key" ref="settingsForms" :sections="c.sections" :data="draftSettings" :changed="c.changed" :errors="errors" :marks="isMulti()" :locale="LOC"
                :readonly="!c.editable" @set="setField" @revert="revertField"
              />
              <div v-if="!cards.length" class="sheet set-load">{{ t('settings.none_available') }}</div>
            </template>
            <template v-else-if="item === 'users'">
              <div class="seg2 txt set-seg" role="group" :aria-label="t('settings.segment_label')">
                <button type="button" :aria-pressed="segment === 'users'" @click="goSegment('users')">{{ t('settings.users') }}</button>
                <button type="button" :aria-pressed="segment === 'roles'" @click="goSegment('roles')">{{ t('settings.roles') }}</button>
              </div>
              <UsersPanel v-if="segment === 'users'" />
              <RolesPanel v-else />
            </template>
            <template v-else-if="item === 'seo'">
              <div class="seg2 txt set-seg" role="group" :aria-label="t('settings.segment_label')">
                <button type="button" :aria-pressed="segment === 'rules'" @click="goSegment('rules')">{{ t('settings.seo_rules') }}</button>
                <button type="button" :aria-pressed="segment === 'files'" @click="goSegment('files')">{{ t('settings.seo_files') }}</button>
              </div>
              <SeoPanel v-if="segment === 'rules'" />
              <SeoFilesPanel v-else />
            </template>
            <template v-else>
              <h2 v-if="item === 'templates'" class="sr">{{ t('settings.nav_templates') }}</h2>
              <ReadOnlyNote v-if="viewOnly" :text="t('settings.view_only')" />
              <div :class="{ 'ro-zone': viewOnly }" :inert="viewOnly || undefined">
                <MenusEditor v-if="item === 'menus'" v-model="draftMenus" :saved="savedMenus" :areas="menuAreas" @areas="onAreas" />
                <TemplatesPanel v-else-if="item === 'templates'" />
                <ThemePanel v-else-if="item === 'theme'" />
                <MailPanel v-else-if="item === 'mail'" />
                <BotsPanel v-else-if="item === 'bots'" @saved="onBotsSaved" />
                <McpPanel v-else-if="item === 'mcp'" />
                <RedirectsPanel v-else-if="item === 'redirects'" />
                <NotificationsPanel v-else-if="item === 'notifications'" :data="draftSettings" :saved="savedSettings" :forms="forms" @set="setField" />
              </div>
            </template>
          </template>
        </section>
      </div>
    </div>

    <div v-if="total" class="savebar set-bar" role="region" :aria-label="t('settings.unsaved_region')">
      <span class="pmark" aria-hidden="true"><i /></span>
      <span>{{ unsavedBar[0] }}<b class="num">{{ total }}</b>{{ unsavedBar[1] }}</span>
      <button type="button" class="btn" :disabled="saving" @click="discard">{{ t('settings.discard_all') }}</button>
      <button type="button" :class="['btn', 'primary', { loading: saving }]" @click="save"><Icon name="save" size="s16" />{{ t('settings.save') }}</button>
    </div>
  </div>
</template>

<style scoped>
.set-panel{min-width:0;max-width:900px}
.set-load{padding:18px 22px}
.set-seg{margin-bottom:14px}
@media (min-width:1200px){
  .set-bar{left:calc(var(--rail-w) + (100vw - var(--rail-w)) / 2);bottom:78px} /* above the toasts, which sit at the bottom edge */
}
</style>
