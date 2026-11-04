<script setup>
// Route /trash?kind=page|item&key=<шаблон или коллекция> — «Корзина»: удалённые страницы и записи, которые можно вернуть.
// The list comes from the history (entries with the action «удалено» that nobody has restored). «Вернуть» brings the page or record back as a DRAFT
// (the server puts it where it was; if its place is gone, at the top level) and opens it. Rights: what the person may see; returning needs «Править».
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { api } from '@/api/index.js'
import { useToast } from '@/components/ui/useToast.js'
import { fmtDT } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { refreshCounters } from '@/stores/app.js'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const kind = ref(['page', 'item'].includes(route.query.kind) ? route.query.kind : '')
const key = String(route.query.key || '')
const rows = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(0)

const back = computed(() => (key && kind.value === 'item' ? { to: '/collections/' + key, label: t('ui.trash_to_list') } : { to: '/pages', label: t('ui.trash_pages') }))
const countText = computed(() => t('ui.trash_count', { count: rows.value.length }))

async function load() {
  loading.value = true
  error.value = ''
  try {
    rows.value = (await api.history.trash({ kind: kind.value, key })).items
  } catch (e) {
    error.value = e.message || t('ui.trash_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

function setKind(k) {
  kind.value = k
  load()
}
const goal = (r) => (r.kind === 'page' ? `/pages/${r.resourceId}` : `/collections/${r.key}/${r.resourceId}`)

async function restore(r) {
  if (busy.value) return
  busy.value = r.id
  try {
    await api.history.restore(r.id)
    refreshCounters()
    toast.show(t('ui.trash_restored', { title: r.title || t('ui.trash_untitled') }))
    router.push(goal(r))
  } catch (e) {
    toast.error(e.message || t('ui.trash_restore_failed'))
  } finally {
    busy.value = 0
  }
}
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div>
        <router-link class="back" :to="back.to"><Icon name="chevL" size="s14" />{{ back.label }}</router-link>
        <h1>{{ t('ui.trash_title') }}</h1>
        <p class="sub">{{ t('ui.trash_sub') }}</p>
      </div>
    </div>

    <div class="sheet">
      <div v-if="!key" class="toolbar">
        <div class="seg2 txt" role="group" :aria-label="t('ui.trash_show')">
          <button type="button" :aria-pressed="kind === ''" @click="setKind('')">{{ t('ui.trash_all') }}</button>
          <button type="button" :aria-pressed="kind === 'page'" @click="setKind('page')">{{ t('ui.trash_pages') }}</button>
          <button type="button" :aria-pressed="kind === 'item'" @click="setKind('item')">{{ t('ui.trash_items') }}</button>
        </div>
        <span class="grow num" aria-live="polite">{{ loading || error ? '' : countText }}</span>
      </div>

      <div v-if="loading" class="tr-pad" aria-busy="true"><Skeleton :rows="6" /></div>
      <div v-else-if="error" class="tr-pad"><div class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ error }}</span><button type="button" class="btn sm" @click="load">{{ t('ui.retry') }}</button></div></div>
      <table v-else-if="rows.length" class="t t-stack" :aria-label="t('ui.trash_table')">
        <thead><tr><th>{{ t('ui.trash_col_name') }}</th><th>{{ t('ui.trash_col_kind') }}</th><th>{{ t('ui.trash_col_by') }}</th><th>{{ t('ui.trash_col_when') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="ts-main">
              <b>{{ r.title || t('ui.trash_untitled') }}</b>
              <span v-if="r.wasLive" class="chip draft tr-chip" :title="t('ui.trash_was_live_hint')">{{ t('ui.trash_was_live') }}</span>
            </td>
            <td class="nw ts-sub">{{ t(r.kind === 'page' ? 'ui.trash_kind_page' : 'ui.trash_kind_item') }} · {{ r.keyTitle }}</td>
            <td class="nw ts-sub" :data-label="t('ui.trash_by_label')">{{ r.userName || t('ui.system') }}</td>
            <td class="nw num ts-sub">{{ fmtDT(r.deletedAt) }}</td>
            <td class="nw tr-act ts-acts">
              <button v-if="r.canRestore" type="button" :class="['btn sm', { loading: busy === r.id }]" :disabled="!r.restorable" :title="r.restorable ? undefined : t('ui.trash_not_restorable')" @click="restore(r)"><Icon name="undo" size="s14" />{{ t('ui.trash_restore') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else icon="trash" :title="t('ui.trash_empty_title')" :text="t('ui.trash_empty_text')" />
    </div>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: 4px; margin: 0 0 6px -2px; font-size: var(--t-sm); font-weight: 600; color: var(--brand-text); text-decoration: none; }
.back:hover { color: var(--brand-text-d); text-decoration: underline; text-underline-offset: .2em; }
.tr-pad { padding: 14px; }
.tr-chip { margin-left: 8px; }
.tr-act { text-align: right; }
</style>
