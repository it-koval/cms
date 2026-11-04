<script setup>
// Пользователи: who can sign in to the admin of this site. List with search and a role filter, «Пригласить пользователя» (modal; when the site can send mail the person gets
// a link to set his own password, else the one-time password is shown once to the administrator, OneTimePasswordDialog), a row opens the drawer (UserDrawer: edit, reset password, block, delete). Everything is saved at once, like Переадресации.
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/index.js'
import { app, can, refreshCurrentUser } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import UserDrawer from './UserDrawer.vue'
import OneTimePasswordDialog from './OneTimePasswordDialog.vue'
import { lastSeen, statusOf } from './userMeta.js'
import { t } from '@/i18n/index.js'
import { covers, ROLE_TOO_BIG } from '@/utils/permissions.js'
import { useToast } from '@/components/ui/useToast.js'

const toast = useToast()
const mailReady = ref(false) // can the site send mail: then «Пригласить» and «Сбросить пароль» send a link

const users = ref([])
const roles = ref([])
const loading = ref(true)
const loadError = ref('')
const q = ref('')
const roleFilter = ref('')
const openId = ref(null)

async function load() {
  try {
    const [u, r, m] = await Promise.all([api.users.list(), api.users.listRoles(), api.users.mailReady().catch(() => ({ ready: false }))])
    users.value = u
    roles.value = r
    mailReady.value = !!(m && m.ready)
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('people.users_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

// rights of the person at the screen: without «Править» the list can only be looked at; without «Удалять» nobody can be deleted
const canEdit = computed(() => can('set:users', 'edit'))
const canDelete = computed(() => can('set:users', 'delete'))
// a role with more rights than the person at the screen has cannot be given
const assignable = (r) => covers(app.user, r)
const roleName = (id) => (roles.value.find((r) => r.id === id) || {}).name || t('people.users_no_role')
const meId = computed(() => (app.user ? app.user.id : null))
const openUser = computed(() => users.value.find((u) => u.id === openId.value) || null)
// the only active «Администратор» cannot be blocked, deleted or given another role
const lastAdminId = computed(() => {
  const admins = users.value.filter((u) => u.status === 'active' && (roles.value.find((r) => r.id === u.roleId) || {}).preset === 'admin')
  return admins.length === 1 ? admins[0].id : null
})

const shown = computed(() => {
  const needle = q.value.trim().toLowerCase()
  return users.value.filter((u) => (!roleFilter.value || u.roleId === roleFilter.value) && (!needle || `${u.name} ${u.email}`.toLowerCase().includes(needle)))
})
const resetFilters = () => { q.value = ''; roleFilter.value = '' }

/* ---- invite ---- */
// the one-time password of the new person, shown once
const issued = reactive({ open: false, name: '', email: '', password: '', mailError: '' })
function closeIssued() { Object.assign(issued, { open: false, password: '', mailError: '' }) }

const dlg = reactive({ open: false, name: '', email: '', roleId: '' })
const touched = reactive({ name: false, email: false })
const submitted = ref(false)
const saving = ref(false)
const serverError = ref('')

const errors = computed(() => {
  const e = { name: '', email: '' }
  if (!dlg.name.trim()) e.name = t('people.users_err_name')
  const mail = dlg.email.trim()
  if (!mail) e.email = t('people.users_err_email')
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) e.email = t('people.users_err_email_typo')
  else if (users.value.some((u) => u.email.toLowerCase() === mail.toLowerCase())) e.email = t('people.users_err_email_taken')
  return e
})
const showErr = (k) => (touched[k] || submitted.value ? errors.value[k] : '')
const inviteRole = computed(() => roles.value.find((r) => r.id === dlg.roleId) || null)

function openInvite() {
  Object.assign(dlg, { open: true, name: '', email: '', roleId: (roles.value.find((r) => r.id === 'editor' && assignable(r)) || roles.value.find(assignable) || {}).id || '' })
  Object.assign(touched, { name: false, email: false })
  submitted.value = false
  serverError.value = ''
}
async function invite() {
  submitted.value = true
  serverError.value = ''
  if (errors.value.name || errors.value.email) return
  saving.value = true
  try {
    const u = await api.users.invite({ name: dlg.name, email: dlg.email, roleId: dlg.roleId })
    dlg.open = false
    if (u.emailed) toast.show(t('people.users_mail_sent', { email: u.email }))
    else Object.assign(issued, { open: true, name: u.name, email: u.email, password: u.password, mailError: u.emailError || '' })
    await load()
    refreshCurrentUser() // «Смотреть как» lists the new person once he is active
  } catch (e) {
    serverError.value = e.message || t('people.users_invite_failed')
  } finally {
    saving.value = false
  }
}

async function onChanged(info = {}) {
  await load()
  refreshCurrentUser() // the rights follow when the person at the screen changed himself
}
</script>

<template>
  <ReadOnlyNote v-if="!canEdit" :text="t('people.users_readonly_note')" />
  <section class="sheet us">
    <div class="us-intro">
      <h2>{{ t('people.users_title') }}</h2>
      <p class="d">{{ t('people.users_intro', { roles: t('people.roles_title') }) }}</p>
    </div>
    <div class="toolbar">
      <div class="search">
        <label class="sr" for="us-q">{{ t('people.users_search_label') }}</label>
        <Icon name="search" size="s16" />
        <input id="us-q" v-model="q" type="search" :placeholder="t('people.users_search_placeholder')" autocomplete="off">
      </div>
      <select v-model="roleFilter" :aria-label="t('people.roles_th_role')">
        <option value="">{{ t('people.roles_all') }}</option>
        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
      </select>
      <span class="grow num">{{ t('people.users_count', { count: shown.length }) }}</span>
      <button v-if="canEdit" type="button" class="btn primary" :disabled="loading || !!loadError" @click="openInvite"><Icon name="plus" size="s16" />{{ t('people.users_invite') }}</button>
    </div>

    <div v-if="loading" class="us-pad"><Skeleton :rows="5" /></div>
    <div v-else-if="loadError" class="us-pad"><div class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('people.retry') }}</button></div></div>
    <table v-else-if="shown.length" class="t t-stack" :aria-label="t('people.users_title')">
      <thead><tr><th>{{ t('people.users_th_user') }}</th><th>{{ t('people.roles_th_role') }}</th><th>{{ t('people.users_th_status') }}</th><th>{{ t('people.users_th_last') }}</th></tr></thead>
      <tbody>
        <tr v-for="u in shown" :key="u.id" class="click" @click="openId = u.id">
          <td class="ts-main">
            <div class="us-who">
              <Avatar :name="u.name" :size="34" :muted="u.status !== 'active'" />
              <div class="us-txt">
                <button type="button" class="us-name" :aria-label="t('people.users_open_aria', { name: u.name })" @click.stop="openId = u.id">{{ u.name }}</button><span v-if="u.id === meId" class="us-me">{{ t('people.users_me') }}</span>
                <div class="us-mail">{{ u.email }}</div>
              </div>
            </div>
          </td>
          <td class="nw ts-sub"><span class="chip">{{ roleName(u.roleId) }}</span></td>
          <td class="nw ts-sub">
            <span :class="['chip', statusOf(u).cls]"><span v-if="statusOf(u).dot" class="dot" /><Icon v-else-if="statusOf(u).icon" :name="statusOf(u).icon" size="s14" />{{ statusOf(u).label }}</span>
          </td>
          <td class="nw muted ts-sub" :data-label="t('people.users_th_signin')">{{ lastSeen(u) }}</td>
        </tr>
      </tbody>
    </table>
    <EmptyState v-else icon="search" :title="t('people.users_empty_title')" :text="t('people.users_empty_text')">
      <button type="button" class="btn" @click="resetFilters">{{ t('people.users_reset_search') }}</button>
    </EmptyState>
  </section>

  <UserDrawer
    :user="openUser" :roles="roles" :me-id="meId" :last-admin-id="lastAdminId" :users="users" :can-edit="canEdit" :can-delete="canDelete" :mail-ready="mailReady"
    @close="openId = null" @changed="onChanged"
  />

  <OneTimePasswordDialog :open="issued.open" :name="issued.name" :email="issued.email" :password="issued.password" :mail-error="issued.mailError" invite @update:open="closeIssued" />

  <Modal v-model:open="dlg.open" :title="t('people.users_invite')" :width="520" initial-focus="#us-name">
    <form id="us-form" class="us-form" novalidate @submit.prevent="invite">
      <div :class="['field', { 'is-error': !!showErr('name') }]">
        <div class="lbl"><label for="us-name">{{ t('people.users_name') }}</label></div>
        <input id="us-name" v-model="dlg.name" type="text" maxlength="60" autocomplete="off" :placeholder="t('people.users_name_placeholder')" :aria-invalid="showErr('name') ? 'true' : undefined" @blur="touched.name = true">
        <div v-if="showErr('name')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ showErr('name') }}</span></div>
      </div>
      <div :class="['field', { 'is-error': !!showErr('email') }]">
        <div class="lbl"><label for="us-email">{{ t('people.users_email') }}</label></div>
        <input id="us-email" v-model="dlg.email" type="text" inputmode="email" maxlength="80" autocomplete="off" placeholder="name@example.com" :aria-invalid="showErr('email') ? 'true' : undefined" @blur="touched.email = true">
        <div v-if="mailReady" class="help">{{ t('people.users_invite_help_mail') }}</div>
        <div v-else class="help">{{ t('people.users_invite_help_nomail') }}</div>
        <div v-if="showErr('email')" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ showErr('email') }}</span></div>
      </div>
      <div class="field">
        <div class="lbl"><label for="us-role">{{ t('people.roles_th_role') }}</label></div>
        <select id="us-role" v-model="dlg.roleId">
          <option v-for="r in roles" :key="r.id" :value="r.id" :disabled="!assignable(r)" :title="assignable(r) ? undefined : ROLE_TOO_BIG">{{ r.name }}</option>
        </select>
        <div v-if="inviteRole" class="help">{{ inviteRole.description }}</div>
        <div v-if="roles.some((r) => !assignable(r))" class="help">{{ t('people.users_roles_too_big') }}</div>
      </div>
      <div v-if="serverError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ serverError }}</span></div>
    </form>
    <template #footer>
      <button type="button" class="btn" @click="dlg.open = false">{{ t('people.btn_cancel') }}</button>
      <button type="submit" form="us-form" :class="['btn', 'primary', { loading: saving }]">{{ t('people.users_invite_btn') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.us{margin-bottom:16px}
.us-intro{padding:20px 22px 12px}
.us-intro h2{font-size:var(--t-md);font-weight:650;margin-bottom:2px}
.us-intro .d{color:var(--ink-2);font-size:var(--t-sm);max-width:68ch}
.us-pad{padding:14px}
.nw{white-space:nowrap}
.us-who{display:flex;align-items:center;gap:12px}
.us-txt{min-width:0}
.us-name{background:none;border:0;padding:0;font:inherit;font-weight:580;color:var(--ink);text-align:left}
.us-name:hover{color:var(--brand-text)}
.us-me{margin-left:8px;font-size:var(--t-xs);font-weight:620;color:var(--brand-text)}
.us-mail{font-size:var(--t-sm);color:var(--ink-2)}
.us-form{display:flex;flex-direction:column}
.us-form .field{margin:0 -12px}
</style>
