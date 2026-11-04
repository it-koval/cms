<script setup>
// The bell in the rail with the number of unread notifications, and the panel «Уведомления» it opens (a drawer).
//   <NotificationBell />      the button; the panel is part of it. The number is asked of the server every 60 seconds (stores/notifications.js).
// The panel: «Все» / «Непрочитанные», «Отметить всё прочитанным», one row per notification (what happened, the details, when). A click on a row marks it read and goes to its
// link: a route of this admin goes through the router, a full address opens in a new tab; a row without a link is only marked read.
// RIGHTS: nothing to check here, the server sends each person only what is meant for him.
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Drawer from '@/components/ui/Drawer.vue'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useToast } from '@/components/ui/useToast.js'
import { notes, startPolling, stopPolling, openPanel, closePanel, setUnreadOnly, loadList, markRead, markAllRead } from '@/stores/notifications.js'
import { fmtUpdated } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

const router = useRouter()
const toast = useToast()

onMounted(startPolling)
onBeforeUnmount(stopPolling)

const shown = computed(() => (notes.unread > 99 ? '99+' : String(notes.unread)))
const label = computed(() => (notes.unread ? t('core.notes_label_unread', { count: notes.unread }) : t('core.notes_title')))
const LEVELS = { info: t('core.notes_level_info'), warning: t('core.notes_level_warning'), error: t('core.notes_level_error') }
const levelOf = (n) => LEVELS[n.level] || LEVELS.info

const onOpen = (v) => { if (v) openPanel(); else closePanel() }

async function go(n) {
  try { await markRead(n) } catch (e) { toast.error((e && e.message) || t('core.notes_mark_failed')) }
  if (!n.link) return
  if (n.link.startsWith('/')) {
    closePanel()
    router.push(n.link)
  } else {
    window.open(n.link, '_blank', 'noopener')
  }
}

async function readAll() {
  try { await markAllRead() } catch (e) { toast.error((e && e.message) || t('core.notes_mark_all_failed')) }
}
</script>

<template>
  <div class="bell-wrap">
    <button type="button" class="bell" :aria-label="label" aria-haspopup="dialog" :aria-expanded="notes.open" :title="label" @click="onOpen(true)">
      <Icon name="bell" />
      <span v-if="notes.unread" class="bell-n" aria-hidden="true">{{ shown }}</span>
    </button>

    <Drawer :open="notes.open" :title="t('core.notes_title')" :width="440" @update:open="onOpen">
      <div class="nt-bar">
        <div class="nt-tabs" role="group" :aria-label="t('core.notes_which')">
          <button type="button" :class="['btn sm', { primary: !notes.unreadOnly }]" :aria-pressed="!notes.unreadOnly" @click="setUnreadOnly(false)">{{ t('core.notes_all') }}</button>
          <button type="button" :class="['btn sm', { primary: notes.unreadOnly }]" :aria-pressed="notes.unreadOnly" @click="setUnreadOnly(true)">{{ t('core.notes_unread') }}<span v-if="notes.unread" class="nt-cnt">{{ notes.unread }}</span></button>
        </div>
        <button type="button" class="btn ghost sm" :disabled="!notes.unread" @click="readAll">{{ t('core.notes_mark_all') }}</button>
      </div>

      <div v-if="notes.loading && !notes.loaded" aria-busy="true"><Skeleton :rows="6" /></div>
      <div v-else-if="notes.error" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ notes.error }}</span><button type="button" class="btn sm" @click="loadList()">{{ t('core.retry') }}</button></div>
      <EmptyState v-else-if="!notes.items.length" icon="bell" :title="notes.unreadOnly ? t('core.notes_all_read') : t('core.notes_none')" :text="notes.unreadOnly ? t('core.notes_no_unread') : t('core.notes_empty_hint')" />
      <template v-else>
        <ul class="nt-list">
          <li v-for="n in notes.items" :key="n.id">
            <button type="button" :class="['nt-row', { unread: !n.read }]" @click="go(n)">
              <span class="nt-dot" :data-level="n.level" aria-hidden="true" />
              <span class="nt-main">
                <b>{{ n.title }}<span class="sr"> {{ t(n.read ? 'core.notes_sr_read' : 'core.notes_sr_unread', { level: levelOf(n) }) }}</span></b>
                <span v-if="n.body" class="nt-body">{{ n.body }}</span>
                <span class="muted nt-when">{{ fmtUpdated(n.createdAt) }}</span>
              </span>
              <Icon v-if="n.link" name="chevR" size="s14" />
            </button>
          </li>
        </ul>
        <p v-if="notes.items.length < notes.total" class="nt-more"><button type="button" :class="['btn', { loading: notes.more }]" @click="loadList({ append: true })">{{ t('core.notes_show_more') }}<span class="nt-left">{{ t('core.notes_left', { count: notes.total - notes.items.length }) }}</span></button></p>
      </template>
    </Drawer>
  </div>
</template>

<style>
/* the bell sits in the corner of the site block of the rail (AppLayout puts it there) */
.bell-wrap{display:inline-flex}
.bell{position:relative;display:inline-grid;place-items:center;width:34px;height:34px;border:0;border-radius:var(--r);background:color-mix(in srgb,var(--brand-ink) 10%,transparent);color:var(--brand-ink);cursor:pointer;transition:background var(--dur)}
.bell:hover{background:color-mix(in srgb,var(--brand-ink) 20%,transparent)}
.bell-n{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:var(--mag);color:var(--accent-ink);font-size:11px;font-weight:700;line-height:18px;text-align:center;font-variant-numeric:tabular-nums;box-shadow:0 0 0 2px var(--cy)}
.nt-bar{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;justify-content:space-between;margin-bottom:12px}
.nt-tabs{display:flex;gap:6px}
.nt-cnt{margin-left:6px;font-variant-numeric:tabular-nums}
.nt-list{list-style:none;margin:0;padding:0;border-top:1px solid var(--hair-2)}
.nt-list li{border-bottom:1px solid var(--hair-2)}
.nt-row{display:grid;grid-template-columns:10px 1fr 16px;gap:12px;align-items:start;width:100%;padding:12px 8px;background:none;border:0;text-align:left;cursor:pointer;font:inherit;color:inherit;border-radius:4px}
.nt-row:not(:has(svg)){grid-template-columns:10px 1fr}
.nt-row:hover,.nt-row:focus-visible{background:var(--cy-50)}
.nt-row.unread{background:var(--sheet-2)}
.nt-row.unread:hover{background:var(--cy-50)}
.nt-dot{width:8px;height:8px;margin-top:6px;border-radius:50%;background:var(--cy)}
.nt-dot[data-level="warning"]{background:var(--amber)}
.nt-dot[data-level="error"]{background:var(--red)}
.nt-row:not(.unread) .nt-dot{opacity:.35}
.nt-main{display:flex;flex-direction:column;gap:2px;min-width:0;font-size:var(--t-sm)}
.nt-main b{font-weight:400}
.nt-row.unread .nt-main b{font-weight:650}
.nt-body{color:var(--ink-2);overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.nt-when{font-size:var(--t-xs)}
.nt-more{margin:12px 0 0}
.nt-left{margin-left:8px;color:var(--ink-3);font-weight:400}
</style>
