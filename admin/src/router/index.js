// Hash-history router (the server serves one shell page at /admin, the screens live after the #). ALL routes are registered here; each points to a view file.
// meta.layout: 'app' = inside the rail layout, 'bare' = no rail (the full-screen page editor). Signing in is the server's page /admin/login.
// meta.byLocale: the screen works in the content language and is re-mounted when it changes (App.vue). The page editor has no flag: it follows app.locale itself.
// Links to a specific version carry ?lang=xx. Language switches share the screen's unsaved-change guard.
import { createRouter, createWebHashHistory } from 'vue-router'
import { app } from '@/stores/app.js'
import { preloadRichText } from '@/components/form/RichTextAsync.js'
import { t } from '@/i18n/index.js'
import { installContentLocaleRouting } from './contentLocale.js'

// The forms of these screens focus rich-text fields (a click on the canvas, a new record): the editor comes with the screen, so a field has it in its first render.
// A failed load does not close the screen: the field tries again when it is drawn (form/RichTextAsync.js).
const withRichText = (load) => () => Promise.all([load(), preloadRichText().catch(() => null)]).then(([view]) => view)

const routes = [
  { path: '/', name: 'today', component: () => import('@/views/TodayView.vue'), meta: { layout: 'app', title: t('core.area_today') } },
  { path: '/pages', name: 'pages', component: () => import('@/views/PagesView.vue'), meta: { layout: 'app', byLocale: true, title: t('core.area_pages') } },
  { path: '/pages/:id', name: 'page-editor', component: withRichText(() => import('@/views/PageEditorView.vue')), meta: { layout: 'bare', fullscreen: true, title: t('core.area_page_editor') } },
  { path: '/collections/:col', name: 'collection', component: () => import('@/views/CollectionListView.vue'), meta: { layout: 'app', byLocale: true, title: t('core.area_collections') } },
  { path: '/collections/:col/:id', name: 'collection-item', component: withRichText(() => import('@/views/CollectionItemView.vue')), meta: { layout: 'app', byLocale: true, title: t('core.area_collection_item') } },
  { path: '/requests/:id?', name: 'requests', component: () => import('@/views/RequestsView.vue'), meta: { layout: 'app', title: t('core.area_requests') } },
  { path: '/media', name: 'media', component: () => import('@/views/MediaView.vue'), meta: { layout: 'app', title: t('core.area_media') } },
  { path: '/settings/:tab?', name: 'settings', component: () => import('@/views/SettingsView.vue'), meta: { layout: 'app', byLocale: true, title: t('core.area_settings') } },
  // screens of plugins (window.ITK.registerPlugin({ panels })): /x/<panel>/<anything>
  { path: '/x/:panel/:rest(.*)*', name: 'plugin-panel', component: () => import('@/views/PluginPanelView.vue'), meta: { layout: 'app', title: t('core.area_panel') } },
  { path: '/trash', name: 'trash', component: () => import('@/views/TrashView.vue'), meta: { layout: 'app', title: t('core.area_trash') } },
  { path: '/health', name: 'health', component: () => import('@/views/HealthView.vue'), meta: { layout: 'app', title: t('core.area_health') } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved || { top: 0 },
})

installContentLocaleRouting(router)

export function refreshTitle(to = router.currentRoute.value) {
  document.title = `${to.meta.title || 'ITK CMS'} · ${app.siteInfo ? app.siteInfo.name : 'ITK CMS'}`
}
router.afterEach((to) => refreshTitle(to))
