<script setup>
// «Что требует внимания»: new requests (mark as seen in one click), what is not published yet, what the AI agent
// suggested (accept / reject here), and what was published recently. Data: api.site.getDashboard().
// On a site with 2+ languages every unpublished VERSION, AI proposal and new request carries a small language tag (EN). «Нет перевода» is never listed:
// a missing translation is a choice, not an error. A site with one language shows no tags.
// RIGHTS: every block is drawn only when the role may use it («Новые заявки» — Заявки; «Не опубликовано», «Недавно опубликовано» — pages or collections; «ИИ-агент» — a right to edit; «Просмотрено» — a right to edit requests)
// and lists only what the role may see (the template scope of pages included). Plugins of the site may add blocks under the sections (widgets, plugins/state.js). «Ждут публикации» is for people who may publish: what an author sent «на публикацию», with «Посмотреть» into the editor.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '@/api/index.js'
import { app, can, canAnyCol, refreshCounters } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useRouter } from 'vue-router'
import { fmtDT, fmtUpdated, MIGRATED_NOTE } from '@/utils/format.js'
import { formatDate, t } from '@/i18n/index.js'
import { workLabel } from '@/composables/requestMeta.js'
import ProposalItem from '@/components/today/ProposalItem.vue'
import YandexWebmasterCard from '@/components/today/YandexWebmasterCard.vue'
import LangTag from '@/components/ui/LangTag.vue'
import PluginSlot from '@/components/ui/PluginSlot.vue'
import { pluginWidgets } from '@/plugins/state.js'
import { isMulti } from '@/utils/locales.js'
import { schema } from '@/schema/registry.js'

const toast = useToast()
const router = useRouter()
const multi = computed(() => isMulti())

const data = ref(null)
const loadError = ref('')
const busyReq = ref(new Set())
const busyProp = ref(new Set())
const showAllUnpub = ref(false)
const UNPUB_LIMIT = 6

const dateLabel = (() => {
  const s = formatDate(new Date(), { weekday: 'long', day: 'numeric', month: 'long' })
  return s[0].toUpperCase() + s.slice(1)
})()

let alive = true
let dashboardSequence = 0
let requestSequence = 0
let requestRevision = 0
onBeforeUnmount(() => { alive = false; dashboardSequence++; requestSequence++ })

async function load() {
  const sequence = ++dashboardSequence
  const revision = requestRevision
  const requests = requestSequence
  try {
    const next = await api.site.getDashboard()
    if (!alive || sequence !== dashboardSequence) return
    // A whole-dashboard snapshot must not undo a status change or a newer request refill.
    if (data.value && (revision !== requestRevision || requests !== requestSequence)) {
      next.newRequests = data.value.newRequests
      next.newRequestCount = newRequestCount.value
    }
    data.value = next
    loadError.value = ''
  } catch (e) {
    if (alive && sequence === dashboardSequence) loadError.value = e.message || t('ui.today_load_failed')
  }
}

async function refreshNewRequests() {
  if (!alive) return
  const sequence = ++requestSequence
  const revision = requestRevision
  try {
    const next = await api.requests.list({ status: 'new', perPage: 8 })
    if (!alive || sequence !== requestSequence || revision !== requestRevision || !data.value) return
    data.value.newRequests = next.items
    data.value.newRequestCount = next.total
    requestRevision++
  } catch (e) {
    if (alive && sequence === requestSequence && revision === requestRevision) toast.error(e.message || t('ui.rq_load_failed'))
  }
}
onMounted(load)

/* ---- what the role may see ---- */
const seeReq = computed(() => can('requests'))
const editReq = computed(() => can('requests', 'edit'))
const seeContent = computed(() => can('pages') || canAnyCol())
const editContent = computed(() => can('pages', 'edit') || canAnyCol('edit'))
const publisher = computed(() => can('pages', 'publish') || canAnyCol('publish'))
const itemRight = (u, action) => (u.kind === 'page' ? can('pages', action, { template: u.template }) : can('col:' + u.collection, action))
const propRight = (p, action) => (p.kind === 'page' ? can('pages', action, { template: p.template }) : can('col:' + p.collection, action))
// what waits for the people who may publish it, and the rest of the unpublished (what is already in the queue of a publisher is not listed twice)
const awaiting = computed(() => (data.value && publisher.value ? data.value.awaiting.filter((u) => itemRight(u, 'publish')) : []))
const unpubList = computed(() => (data.value ? data.value.unpublished.filter((u) => itemRight(u, 'view') && !(u.awaiting && itemRight(u, 'publish'))) : []))
const proposalList = computed(() => (data.value ? data.value.proposals.filter((p) => propRight(p, 'edit')) : []))
const recentList = computed(() => (data.value ? data.value.recent : []))
const showAside = computed(() => seeContent.value)
const localeCounts = computed(() => Object.entries(data.value?.locales || {}))
const newRequestCount = computed(() => data.value?.newRequestCount ?? data.value?.newRequests.length ?? 0)

// blocks that plugins of the site put under the sections (window.ITK.registerPlugin({ widgets }))
const widgets = computed(() => pluginWidgets())

const unpubShown = computed(() => (showAllUnpub.value ? unpubList.value : unpubList.value.slice(0, UNPUB_LIMIT)))

function unpubMeta(u) {
  if (u.awaiting) return t('ui.today_meta_awaiting', { kind: u.pageKind, by: u.awaiting.by, when: fmtUpdated(u.awaiting.at) })
  const when = u.migrated ? MIGRATED_NOTE : t('ui.today_changed_at', { when: fmtUpdated(u.updatedAt) })
  if (u.draft) return t('ui.today_meta_draft', { kind: u.pageKind, when })
  return t('ui.today_meta_changes', { count: u.changes, when })
}

/** New request -> «В работе» in one click: it stops being «Новая», so it leaves this list and the rail badge. */
async function markSeen(r) {
  if (busyReq.value.has(r.id)) return
  busyReq.value = new Set(busyReq.value).add(r.id)
  try {
    await api.requests.update(r.id, { work: 'progress' })
    refreshCounters()
    if (!alive || !data.value) return
    requestRevision++
    if (data.value.newRequests.some((x) => x.id === r.id)) data.value.newRequestCount = Math.max(0, newRequestCount.value - 1)
    data.value.newRequests = data.value.newRequests.filter((x) => x.id !== r.id)
    toast.show(t('ui.today_seen_toast', { id: r.id, status: workLabel('progress') }), {
      undoLabel: t('ui.toast_return'),
      undo: async () => { await api.requests.update(r.id, { work: 'new' }); requestRevision++; await Promise.all([refreshNewRequests(), refreshCounters()]) },
    })
    await refreshNewRequests()
  } catch (e) {
    toast.error(e.message)
  } finally {
    const s = new Set(busyReq.value); s.delete(r.id); busyReq.value = s
  }
}

async function decide(p, accept) {
  if (busyProp.value.has(p.id)) return
  busyProp.value = new Set(busyProp.value).add(p.id)
  try {
    if (accept) {
      await api.proposals.accept(p.id)
      toast.show(t('ui.today_accepted'), { undoLabel: t('ui.toast_open'), undo: () => router.push(p.to) })
    } else {
      await api.proposals.reject(p.id)
      toast.show(t('ui.today_rejected'))
    }
    await load()
  } catch (e) {
    toast.error(e.message)
    await load()
  } finally {
    const s = new Set(busyProp.value); s.delete(p.id); busyProp.value = s
  }
}
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div><h1>{{ t('ui.today_title') }}</h1><p class="sub">{{ dateLabel }} · {{ app.siteInfo.name }}</p></div>
    </div>

    <div v-if="loadError" class="sheet" style="padding:22px">
      <div class="alert err-a" role="alert"><Icon name="alert" size="s16" /><div>{{ loadError }}</div></div>
      <button class="btn" type="button" style="margin-top:14px" @click="load">{{ t('ui.retry') }}</button>
    </div>
    <!-- a role with neither content nor requests (a new role ticked only for Медиатека, or for nothing) has nothing to show here -->
    <div v-else-if="!seeReq && !seeContent" class="sheet today-none">
      <EmptyState icon="today" :title="t('ui.today_none_title')" :text="t('ui.today_none_text')" />
    </div>
    <div v-else-if="!data" class="today" aria-busy="true">
      <div class="wl"><div class="sheet" style="padding:18px 22px"><Skeleton :rows="5" /></div><div class="sheet" style="padding:18px 22px"><Skeleton :rows="4" /></div></div>
      <div class="sheet" style="padding:18px 22px"><Skeleton :rows="4" /></div>
    </div>

    <div v-else :class="['today', { one: !showAside }]">
      <div class="wl">
        <!-- waiting for publication: only for people who may publish -->
        <section v-if="publisher" aria-labelledby="t-wait">
          <h2 id="t-wait">{{ t('ui.today_waiting') }}<span v-if="awaiting.length" class="chip warn">{{ awaiting.length }}</span></h2>
          <div class="sheet list">
            <template v-if="awaiting.length">
              <router-link v-for="u in awaiting" :key="u.kind + u.id + u.locale" class="row-i" :to="u.to">
                <span class="pdot"><span class="pmark" aria-hidden="true"><i /></span></span>
                <span class="main-c"><span class="ttl">{{ u.title }}</span><LangTag v-if="multi && u.locale" :code="u.locale" /><br><span class="meta">{{ t('ui.today_sent', { kind: u.pageKind, when: fmtUpdated(u.at), by: u.by }) }}</span></span>
                <span class="btn sm go-btn">{{ t('ui.today_view') }}</span>
              </router-link>
            </template>
            <div v-else class="empty" style="padding:26px"><p>{{ t('ui.today_waiting_empty') }}</p></div>
          </div>
        </section>

        <!-- new requests -->
        <section v-if="seeReq" aria-labelledby="t-req">
          <h2 id="t-req">{{ t('ui.today_new_requests') }}<span v-if="newRequestCount" class="chip new">{{ newRequestCount }}</span></h2>
          <div class="sheet list">
            <template v-if="data.newRequests.length">
              <div v-for="r in data.newRequests" :key="r.id" class="row-i">
                <router-link class="rl" :to="'/requests/' + r.id">
                  <span class="dot-new" aria-hidden="true" />
                  <span class="main-c">
                    <span class="ttl num">{{ r.phone }}</span><LangTag v-if="multi && r.locale" :code="r.locale" /><br>
                    <span class="meta">{{ [r.city, r.place].filter(Boolean).join(' · ') }}</span>
                    <span v-if="r.note" class="meta note">{{ t('ui.today_note_quote', { text: r.note }) }}</span>
                  </span>
                  <span class="when">{{ fmtDT(r.dt) }}</span>
                </router-link>
                <button v-if="editReq" type="button" class="btn sm seen" :disabled="busyReq.has(r.id)" :aria-label="t('ui.today_mark_seen_label', { id: r.id })" :title="t('ui.today_mark_seen_title')" @click="markSeen(r)"><Icon name="check" size="s14" />{{ t('ui.today_seen_btn') }}</button>
              </div>
            </template>
            <div v-else-if="!newRequestCount" class="empty" style="padding:26px"><p>{{ t('ui.today_requests_empty') }}</p></div>
            <router-link v-if="newRequestCount > data.newRequests.length" class="row-i" :to="{ name: 'requests', query: { status: 'new' } }">
              <span class="main-c"><span class="ttl">{{ t('ui.today_all_requests') }}</span></span><span class="go"><Icon name="chevR" /></span>
            </router-link>
            <router-link v-if="data.failedCount" class="row-i fail" :to="{ path: '/requests', query: { status: 'failed' } }">
              <span class="fi"><Icon name="alert" /></span>
              <span class="main-c"><span class="ttl">{{ t('ui.today_failed', { count: data.failedCount }) }}</span><br><span class="meta">{{ t('ui.today_failed_hint') }}</span></span>
              <span class="go"><Icon name="chevR" /></span>
            </router-link>
          </div>
        </section>

        <!-- not published -->
        <section v-if="seeContent" aria-labelledby="t-unpub">
          <h2 id="t-unpub">{{ t('ui.today_unpublished') }}<span v-if="unpubList.length" class="chip draft"><span class="dot" />{{ unpubList.length }}</span></h2>
          <div class="sheet list">
            <ul v-if="multi && localeCounts.length" class="t-locale-counts">
              <li v-for="[code, counts] in localeCounts" :key="code">
                <LangTag :code="code" />
                <span>{{ t('ui.today_locale_counts', { pages: counts.pages.count, records: counts.collections.count, drafts: counts.pages.unpublished + counts.collections.unpublished }) }}</span>
              </li>
            </ul>
            <template v-if="unpubList.length">
              <router-link v-for="u in unpubShown" :key="[u.kind, u.id, u.locale].join(':')" class="row-i" :to="u.to">
                <span class="pdot"><span class="pmark" aria-hidden="true"><i /></span></span>
                <span class="main-c"><span class="ttl">{{ u.title }}</span><LangTag v-if="multi && u.locale" :code="u.locale" /><span v-if="u.awaiting" class="chip warn wait-c">{{ t('ui.today_waiting_chip') }}</span><br><span class="meta">{{ unpubMeta(u) }}</span></span>
                <span class="go"><Icon name="chevR" /></span>
              </router-link>
              <button v-if="unpubList.length > UNPUB_LIMIT" type="button" class="all" @click="showAllUnpub = !showAllUnpub">
                {{ showAllUnpub ? t('ui.today_show_less') : t('ui.today_show_all', { count: unpubList.length }) }}
              </button>
            </template>
            <div v-else class="empty" style="padding:26px"><p>{{ t('ui.today_unpublished_empty') }}</p></div>
          </div>
        </section>

        <!-- AI agent -->
        <section v-if="schema.features.ai && editContent" aria-labelledby="t-ai">
          <h2 id="t-ai">{{ t('ui.today_ai') }}<span v-if="proposalList.length" class="chip ai">{{ proposalList.length }}</span></h2>
          <div class="sheet list">
            <template v-if="proposalList.length">
              <ProposalItem v-for="p in proposalList" :key="p.id" :proposal="p" :busy="busyProp.has(p.id)" @accept="decide($event, true)" @reject="decide($event, false)" />
            </template>
            <div v-else class="empty" style="padding:26px"><p>{{ t('ui.today_ai_empty') }}</p></div>
          </div>
        </section>

        <!-- Yandex Webmaster: loads and fails on its own, a role without the right gets no card -->
        <YandexWebmasterCard />

        <!-- widgets of the plugins of the site: each draws its own card -->
        <section v-for="w in widgets" :key="w.name" class="t-widget" :aria-label="w.name">
          <PluginSlot :component="w.component" :bind="{ locale: app.locale }" :label="`widget «${w.name}»`" />
        </section>
      </div>

      <!-- recently published -->
      <aside v-if="showAside" class="wl">
        <section class="done-l" aria-labelledby="t-rec">
          <h2 id="t-rec">{{ t('ui.today_recent') }}</h2>
          <div class="sheet list">
            <router-link v-for="r in recentList" :key="r.to" class="row-i" :to="r.to">
              <span class="ok"><Icon name="check" size="s16" /></span>
              <span class="main-c"><span class="ttl" style="font-weight:520">{{ r.label }}</span><LangTag v-if="multi && r.locale" :code="r.locale" /></span>
              <span class="when">{{ fmtDT(r.at) }}</span>
            </router-link>
            <div v-if="!recentList.length" class="empty" style="padding:26px"><p>{{ t('ui.today_recent_empty') }}</p></div>
          </div>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.today.one{grid-template-columns:minmax(0,760px)}
.today-none{max-width:760px}
.t-locale-counts{list-style:none;margin:0;padding:8px 12px;border-bottom:1px solid var(--hair-2);font-size:var(--t-sm);color:var(--ink-2)}
.t-locale-counts li{display:flex;align-items:baseline;gap:6px;padding:2px 0}
.go-btn{flex:none;pointer-events:none}
.wait-c{margin-left:8px;vertical-align:1px}
.row-i .rl{display:flex;gap:12px;align-items:center;flex:1;min-width:0;color:inherit;text-decoration:none}
.row-i .rl:hover{color:inherit}
.row-i .rl:focus-visible{outline-offset:4px}
.row-i .meta.note{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:1px}
.row-i .seen{flex:none}
@media (max-width:767px){.row-i:has(>.seen){flex-wrap:wrap}.row-i:has(>.seen) .rl{flex:1 1 100%}.row-i .seen{margin-left:auto}}
.row-i.fail{background:var(--red-50)}
.row-i.fail:hover{background:var(--red-50)}
.row-i.fail .fi{color:var(--red)}
.row-i.fail .ttl{color:var(--red)}
.all{display:block;width:100%;height:40px;border:0;border-top:1px solid var(--hair-2);background:var(--sheet-2);color:var(--brand-text);font-weight:580;font-size:var(--t-sm)}
.all:hover{background:var(--cy-50);color:var(--brand-text-d)}
.done-l .ok{display:inline-flex}
</style>
