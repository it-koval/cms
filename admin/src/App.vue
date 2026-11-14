<script setup>
import { watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import ToastHost from '@/components/ui/ToastHost.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { app } from '@/stores/app.js'
import { refreshTitle } from '@/router/index.js'
import { t } from '@/i18n/index.js'

// Routes with meta.byLocale (pages, collections, settings: they work in the content language) are re-mounted when the content language changes.
const reload = () => window.location.reload()
const viewKey = (route) => (route.meta.byLocale ? app.locale : '')
// the browser tab title carries the site name
watch(() => app.siteInfo && app.siteInfo.name, () => refreshTitle())
</script>

<template>
  <div v-if="app.error" class="boot-err" role="alert">
    <h1>{{ t('core.app_open_failed') }}</h1>
    <p>{{ app.error }}</p>
    <button type="button" class="btn primary" @click="reload">{{ t('core.retry') }}</button>
  </div>
  <router-view v-else v-slot="{ Component, route }">
    <AppLayout v-if="route.meta.layout === 'app'"><component :is="Component" :key="viewKey(route)" /></AppLayout>
    <component :is="Component" v-else :key="viewKey(route)" />
  </router-view>
  <ToastHost />
  <ConfirmDialog />
</template>

<style>
.boot-err{max-width:520px;margin:18vh auto 0;padding:0 var(--s-5, 20px);display:flex;flex-direction:column;gap:12px;align-items:flex-start}
.boot-err h1{font-size:var(--t-xl, 22px);margin:0}
.boot-err p{margin:0;color:var(--ink-2)}
</style>
