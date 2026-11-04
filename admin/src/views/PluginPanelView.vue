<script setup>
// Route /x/:panel/:rest* — a screen added by a plugin of the site (window.ITK.registerPlugin({ panels: { reports: Component } }) + NavItem::make('Отчёты', '/x/reports') on the server).
// The component gets the rest of the address as `path` and the query string as `query`; it is drawn inside the rail layout. A plugin script that has not arrived yet is waited for
// (the registry is reactive); one that never comes leaves a calm «Такого раздела нет».
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { pluginPanel } from '@/plugins/state.js'
import EmptyState from '@/components/ui/EmptyState.vue'
import { t } from '@/i18n/index.js'

const route = useRoute()
const panel = computed(() => pluginPanel(String(route.params.panel || '')))
const rest = computed(() => [].concat(route.params.rest || []).join('/'))
</script>

<template>
  <component :is="panel" v-if="panel" :key="route.params.panel" :path="rest" :query="route.query" />
  <div v-else class="page">
    <div class="sheet"><EmptyState icon="search" :title="t('core.panel_missing_title')" :text="t('core.panel_missing_text')" /></div>
  </div>
</template>
