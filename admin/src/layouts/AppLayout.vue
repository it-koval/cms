<script setup>
// The shell around every screen except /login and the page editor: left cyan rail + content.
// Rail: site name and «Открыть сайт», the menu (app.nav: built by the SERVER for this person from the resources of the site, its extensions and the core items — see below),
// «Язык контента» select (LanguageSelect, only on a site with 2+ languages): sets app.locale, the language Pages, Collections, Settings texts and Menus work in.
// bottom: the current user (a click opens «Мой профиль»), «Выйти» and the product mark.
// THE MENU is data: nav = [{ key, group, icon, order, items:[{ key, label, to, icon, order, badge?, sub? }] }], already sorted and already limited to what the person may open.
// A group with a name gets a small heading (and its icon); a group without one (the core items «Сегодня», «Страницы»…, «Настройки») is drawn as a plain list. An item with `sub` is a quick link
// under the item before it. `to` is a route of this admin and may carry a query (/pages?template=product). The item that is ON is the most specific one that matches the route
// (the longest path, then the most query keys), so «Страницы» is not lit while «Товары» is. A new collection class, a NavItem of an extension: they appear here by themselves.
// RIGHTS: the server already left out what the person may not open; a deep link to a section the role does not include still shows «Нет доступа» instead of the screen
// (the screen is not mounted at all). The same check, per action, lives in the screens (can() of stores/app.js).
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import LanguageSelect from '@/components/ui/LanguageSelect.vue'
import Avatar from '@/components/ui/Avatar.vue'
import NoAccess from '@/components/ui/NoAccess.vue'
import ProfileDialog from '@/components/ui/ProfileDialog.vue'
import NotificationBell from '@/components/notifications/NotificationBell.vue'
import { app, can, anySettings, switchContentLocale } from '@/stores/app.js'
import { useOverlay } from '@/components/ui/focus.js'
import { vp } from '@/utils/viewport.js'
import { isMulti } from '@/utils/locales.js'
import { logoutUrl } from '@/api/http.js'
import { themeLogo, themeName } from '@/utils/theme.js'
import { siteOrigin } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { getCollectionConfig } from '@/schema/collections.js'
import { NAV, itemAreas, resolveTab } from '@/components/settings/settingsSchema.js'

const route = useRoute()
const logo = themeLogo() // the admin's own logo from the developer's config (itk_cms.admin.theme.logo); none = the built-in mark
const productName = themeName() // the name beside it (itk_cms.admin.theme.name); none = ITK CMS

/* ---------- the menu ---------- */
const parseTo = (to) => {
  const [path, q = ''] = String(to).split('?')
  return { path: path || '/', query: Object.fromEntries(new URLSearchParams(q)) }
}
const matches = (to) => {
  const { path, query } = parseTo(to)
  const onPath = path === '/' ? route.path === '/' : route.path === path || route.path.startsWith(path + '/')
  const keys = Object.keys(query)
  if (!onPath || !keys.every((k) => String(route.query[k] ?? '') === query[k])) return -1
  return path.length * 10 + keys.length
}
const items = computed(() => app.nav.flatMap((g) => g.items))
const activeKey = computed(() => {
  let best = ''
  let score = -1
  items.value.forEach((it) => {
    const m = matches(it.to)
    if (m > score) { score = m; best = it.key }
  })
  return best
})
const badgeOf = (it) => (it.key in app.badges ? app.badges[it.key] : it.badge)
/* rows of the rail: a heading, a link, or a block of quick links (`sub`) that belongs to the link before it */
const rows = computed(() => {
  const out = []
  app.nav.forEach((g) => {
    if (g.group) out.push({ type: 'head', key: 'h:' + g.key, label: g.group, icon: g.icon })
    g.items.forEach((it) => {
      if (it.sub && out.length && out[out.length - 1].type !== 'head') {
        const last = out[out.length - 1]
        if (last.type === 'sub') last.items.push(it)
        else out.push({ type: 'sub', key: 's:' + it.key, items: [it] })
      } else out.push({ type: 'link', key: it.key, item: it })
    })
  })
  return out
})
const showSettings = computed(() => anySettings())

/* the section the route belongs to; `denied` = the role does not include it */
const need = computed(() => {
  const r = route
  switch (r.name) {
    case 'pages': return { area: 'pages', what: t('core.area_pages') }
    case 'collection':
    case 'collection-item': { const cfg = getCollectionConfig(r.params.col); return cfg ? { area: 'col:' + cfg.key, what: cfg.title } : null }
    case 'requests': return { area: 'requests', what: t('core.area_requests') }
    case 'media': return { area: 'media', what: t('core.area_media') }
    case 'health': return { area: 'dev', what: t('core.area_health') }
    case 'settings': {
      const tab = resolveTab(r.params.tab)
      const it = tab && NAV.flatMap((n) => n.items).find((x) => x.key === tab.item)
      return it ? { areas: tab.group ? ['set:' + tab.group] : itemAreas(it), what: t('core.nav_settings_item', { name: it.label }) } : { any: true, what: t('core.area_settings') }
    }
    default: return null
  }
})
const denied = computed(() => {
  const n = need.value
  if (!n || !app.user) return false
  return n.any ? !showSettings.value : n.areas ? !n.areas.some((a) => can(a)) : !can(n.area)
})

/* ---------- phone and tablet: the rail is a drawer (burger in the top bar), desktop keeps it on the left ---------- */
const navOpen = ref(false)
const railEl = ref(null)
const burgerEl = ref(null)
const drawerOn = computed(() => vp.compact && navOpen.value)
const closeNav = () => { navOpen.value = false }
// Esc, Tab kept inside, focus on the current item when it opens and back on the burger when it closes
useOverlay(railEl, drawerOn, { onEsc: closeNav, initial: '[aria-current=page]' })
watch(() => route.fullPath, closeNav) // a tap on a link: the screen changes, the drawer goes away
watch(() => vp.compact, (v) => { if (!v) closeNav() }) // rotated or resized to a desktop window
watch(drawerOn, (v) => document.body.classList.toggle('nav-lock', v))
onBeforeUnmount(() => document.body.classList.remove('nav-lock'))
// the title of the top bar: the menu item the screen belongs to («Поездки» for a record of the collection too)
const barTitle = computed(() => {
  const it = items.value.find((x) => x.key === activeKey.value)
  return (it && it.label) || (route.meta && route.meta.title) || app.siteInfo.name
})
const openNav = async () => { navOpen.value = true; await nextTick() }

/* «Мой профиль» */
const profileOpen = ref(false)
// the server ends the session and shows its login page
const onLogout = () => window.location.assign(logoutUrl())
</script>

<template>
  <div :class="['app', { 'nav-open': navOpen }]">
    <header class="appbar">
      <button ref="burgerEl" type="button" class="appbar-b" :aria-label="t('core.nav_open_menu')" aria-controls="rail" :aria-expanded="navOpen" @click="openNav"><Icon name="menu" size="s20" /></button>
      <div class="appbar-t">{{ barTitle }}</div>
      <NotificationBell v-if="vp.compact" />
    </header>
    <div v-if="vp.compact && navOpen" class="rail-scrim" @click="closeNav" />
    <div class="rail-dlg" :role="vp.compact ? 'dialog' : undefined" :aria-label="vp.compact ? t('core.nav_menu_dialog') : undefined" :aria-modal="vp.compact && navOpen ? 'true' : undefined" :inert="vp.compact && !navOpen ? true : undefined">
    <nav id="rail" ref="railEl" class="rail" :aria-label="t('core.nav_sections')">
      <button v-if="vp.compact" type="button" class="rail-x" :aria-label="t('core.nav_close_menu')" @click="closeNav"><Icon name="x" size="s20" /></button>
      <div class="site-id">
        <NotificationBell v-if="!vp.compact" />
        <div class="nm">{{ app.siteInfo.name }}</div>
        <div class="dm">{{ app.siteInfo.domain }}</div>
        <a class="open" :href="siteOrigin(app.siteInfo.domain)" target="_blank" rel="noopener">{{ t('core.nav_open_site') }}<Icon name="external" size="s14" /></a>
      </div>

      <div v-if="isMulti()" class="lang-sw">
        <LanguageSelect :model-value="app.locale" :languages="app.languages.list" :label="t('core.nav_content_language')" show-label variant="rail" @update:model-value="switchContentLocale" />
      </div>

      <div class="nav">
        <template v-for="r in rows" :key="r.key">
          <div v-if="r.type === 'head'" class="nav-h"><Icon v-if="r.icon" :name="r.icon" size="s14" /><span>{{ r.label }}</span></div>
          <div v-else-if="r.type === 'sub'" class="sub">
            <router-link v-for="it in r.items" :key="it.key" v-slot="{ href, navigate }" :to="it.to" custom>
              <a :href="href" :aria-current="activeKey === it.key ? 'page' : undefined" @click="navigate">{{ it.label }}</a>
            </router-link>
          </div>
          <router-link v-else v-slot="{ href, navigate }" :to="r.item.to" custom>
            <a :href="href" :aria-current="activeKey === r.item.key ? 'page' : undefined" @click="navigate">
              <Icon :name="r.item.icon || 'doc'" /><span>{{ r.item.label }}</span><span v-if="badgeOf(r.item)" class="badge" :aria-label="t('core.nav_badge_new', { count: badgeOf(r.item) })">{{ badgeOf(r.item) }}</span>
            </a>
          </router-link>
        </template>
      </div>

      <div class="rail-foot">
        <div v-if="app.user" class="userline">
          <button type="button" class="who" :title="t('core.nav_my_profile')" aria-haspopup="dialog" @click="profileOpen = true; closeNav()">
            <Avatar :name="app.user.name" :size="28" />
            <span class="who-t"><b>{{ app.user.name }}</b><i>{{ app.user.roleName }}</i></span>
          </button>
          <button type="button" class="out" @click="onLogout">{{ t('core.nav_logout') }}</button>
        </div>
        <div class="brand">
          <img v-if="logo" class="brand-logo" :src="logo" alt="">
          <svg v-else viewBox="0 0 20 20" aria-hidden="true" style="color:var(--brand)"><rect x="1" y="1" width="18" height="18" rx="3" style="fill:var(--brand-ink)" /><rect x="4" y="4" width="12" height="3.2" rx=".8" fill="currentColor" /><rect x="4" y="8.6" width="12" height="3.2" rx=".8" fill="currentColor" opacity=".6" /><rect x="4" y="13.2" width="12" height="3" rx=".8" fill="currentColor" opacity=".3" /></svg>
          <span><b>{{ productName }}</b></span>
        </div>
      </div>
    </nav>
    </div>
    <main id="view" class="main" :inert="drawerOn ? true : undefined"><NoAccess v-if="denied" :what="need.what" /><slot v-else /></main>
    <ProfileDialog v-model:open="profileOpen" />
  </div>
</template>

<style>
.lang-sw{padding:0 8px 12px;margin-bottom:2px}
/* the bell of notifications in the corner of the site block; the name keeps clear of it */
.site-id{position:relative}
.site-id .bell-wrap{position:absolute;top:0;right:4px}
.site-id .nm,.site-id .dm{padding-right:40px}
/* a heading of a group of the menu («Коллекции», «Плагины»…): quiet, small caps, the links of the group follow */
.nav .nav-h{display:flex;align-items:center;gap:6px;margin:14px 0 2px;padding:0 10px;font-size:var(--t-xs);font-weight:650;letter-spacing:.06em;text-transform:uppercase;color:var(--rail-ink-2)}
.nav .nav-h:first-child{margin-top:4px}
/* the user line: the name area opens «Мой профиль» */
.userline .who{margin-left:0;display:flex;align-items:center;gap:9px;min-width:0;flex:1;text-align:left;padding:3px 6px 3px 3px;border-radius:var(--r);color:var(--rail-ink)}
.userline .who:hover{background:color-mix(in srgb,var(--brand-ink) 12%,transparent);color:var(--brand-ink)}
.userline .who .avatar{background:color-mix(in srgb,var(--brand-ink) 20%,transparent);color:var(--brand-ink)}
.who-t{display:flex;flex-direction:column;min-width:0;line-height:1.25}
.who-t b{font-weight:600;color:var(--brand-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.who-t i{font-style:normal;font-size:var(--t-xs);color:var(--rail-ink-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.userline .out{margin-left:auto;flex:none}
</style>
