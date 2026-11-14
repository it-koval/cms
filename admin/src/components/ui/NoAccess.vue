<script setup>
// «Нет доступа»: what a deep link shows when the role of the person at the screen does not include the section.
//   <NoAccess what="Страницы" />            inside the rail layout
//   <NoAccess what="Страница" to="/pages" back="К списку страниц" />   (the full-screen page editor has no rail: the button leads somewhere)
//   `text` replaces the first sentence («У вашей роли … нет доступа к разделу …»); the line about whom to ask always follows it
import { computed } from 'vue'
import { app } from '@/stores/app.js'
import { t } from '@/i18n/index.js'
import EmptyState from './EmptyState.vue'

const props = defineProps({ what: { type: String, default: '' }, text: { type: String, default: '' }, to: { type: String, default: '/' }, back: { type: String, default: () => t('ui.noaccess_back') } })
const message = computed(() => {
  const lead = props.text || t(app.user ? (props.what ? 'ui.noaccess_role_what' : 'ui.noaccess_role') : (props.what ? 'ui.noaccess_what' : 'ui.noaccess_plain'), { role: app.user && app.user.roleName, what: props.what })
  return `${lead} ${t('ui.noaccess_ask')}`
})
</script>

<template>
  <div class="page noacc">
    <div class="sheet">
      <EmptyState icon="lock" :title="t('ui.noaccess_title')" :text="message">
        <router-link class="btn primary" :to="to">{{ back }}</router-link>
      </EmptyState>
    </div>
  </div>
</template>

<style scoped>
.noacc .sheet { max-width: 640px; margin: 40px auto 0; }
</style>
