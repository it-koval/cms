<script setup>
// «Страницу изменили, пока она была открыта»: autosave is stopped and the person chooses what happens to the edits on screen.
//   <EditConflict v-if="conflict" what="page" :current="conflict" @show="…" @keep="…" />
// «Показать новую версию» drops the edits on screen and loads what the server has; «Оставить мои правки» saves them again over the new version.
// `current` is what the server says about the change that got in first: { by, at, via, viaName } (any of them may be empty).
import { computed } from 'vue'
import Icon from './Icon.vue'
import { fmtUpdated } from '@/utils/format.js'
import { whoLabel } from '@/components/history/historyWords.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  what: { type: String, default: 'page' }, // 'page' | 'item' | 'table'
  current: { type: Object, default: () => ({}) },
  busy: { type: Boolean, default: false },
})
defineEmits(['show', 'keep'])

const TITLES = { page: 'ui.conflict_page', item: 'ui.conflict_item', table: 'ui.conflict_table' }
const title = computed(() => t(TITLES[props.what] || TITLES.page))
// «Анна через ИИ-ассистента (Claude Desktop), 5 минут назад»; the author may be unknown (a change the history does not know)
const who = computed(() => {
  const c = props.current || {}
  const name = c.by || c.via ? whoLabel({ userName: c.by, via: c.via, viaName: c.viaName }) : ''
  return [name, c.at ? fmtUpdated(c.at) : ''].filter(Boolean).join(', ')
})
</script>

<template>
  <div class="alert warn-a edit-conflict" role="alert">
    <Icon name="clock" size="s16" />
    <div class="ec-body">
      <span><b>{{ title }}.</b>{{ who ? ` ${who}.` : '' }} {{ t('ui.conflict_unsaved') }}</span>
      <span class="ec-act">
        <button type="button" class="btn" :disabled="busy" @click="$emit('show')">{{ t('ui.conflict_show') }}</button>
        <button type="button" class="btn primary" :disabled="busy" @click="$emit('keep')">{{ t('ui.conflict_keep') }}</button>
      </span>
    </div>
  </div>
</template>

<style scoped>
.ec-body { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; flex: 1; min-width: 0; }
.ec-act { display: flex; flex-wrap: wrap; gap: 8px; }
@media (max-width: 767px) {
  .ec-act { flex: 1 1 100%; }
  .ec-act .btn { flex: 1 1 auto; }
}
</style>
