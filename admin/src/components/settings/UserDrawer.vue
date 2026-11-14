<script setup>
// Drawer with one user: name, e-mail, role (saved with «Сохранить»), then actions that apply at once: reset password (a new one-time password is shown once, the site sends no mail),
// block / unblock, delete.
// Guards, each with its reason written next to the disabled button: you cannot block or delete yourself; the only active «Администратор» cannot be blocked,
// deleted or given another role; you cannot change your own role. The api checks the same.
import { computed, reactive, ref, watch } from 'vue'
import { api } from '@/api/index.js'
import Drawer from '@/components/ui/Drawer.vue'
import Icon from '@/components/ui/Icon.vue'
import Avatar from '@/components/ui/Avatar.vue'
import OneTimePasswordDialog from './OneTimePasswordDialog.vue'
import { lastSeen, statusOf, LAST_ADMIN } from './userMeta.js'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { fmtD } from '@/utils/format.js'
import { t } from '@/i18n/index.js'
import { app } from '@/stores/app.js'
import { covers, ROLE_TOO_BIG, USER_TOO_BIG } from '@/utils/permissions.js'

const props = defineProps({
  user: { type: Object, default: null },
  users: { type: Array, default: () => [] },
  roles: { type: Array, default: () => [] },
  meId: { type: String, default: null },
  lastAdminId: { type: String, default: null },
  canEdit: { type: Boolean, default: true }, // «Править» in Пользователи и роли: without it the drawer only shows the person
  canDelete: { type: Boolean, default: true },
  mailReady: { type: Boolean, default: false }, // the site can send mail: «Сбросить пароль» sends a link instead of a one-time password
})
const emit = defineEmits(['close', 'changed'])
const toast = useToast()
const confirm = useConfirm()

const form = reactive({ name: '', email: '', roleId: '' })
const submitted = ref(false)
const busy = ref('')
const serverError = ref('')

watch(() => props.user, (u) => {
  if (u) Object.assign(form, { name: u.name, email: u.email, roleId: u.roleId })
  submitted.value = false
  serverError.value = ''
}, { immediate: true })

const isMe = computed(() => !!props.user && props.user.id === props.meId)
const isLastAdmin = computed(() => !!props.user && props.user.id === props.lastAdminId)
const role = computed(() => props.roles.find((r) => r.id === form.roleId) || null)
// a person who has more rights than the one at the screen is shown read-only: a limited role cannot demote, block or delete an administrator
const heavier = computed(() => !!props.user && !covers(app.user, props.roles.find((r) => r.id === props.user.roleId)))
const editable = computed(() => props.canEdit && !heavier.value)
const deletable = computed(() => props.canDelete && !heavier.value)
const assignable = (r) => covers(app.user, r)
const dirty = computed(() => !!props.user && editable.value && (form.name.trim() !== props.user.name || form.email.trim() !== props.user.email || form.roleId !== props.user.roleId))
const errors = computed(() => {
  const e = { name: '', email: '' }
  if (!form.name.trim()) e.name = t('people.users_err_name')
  const mail = form.email.trim()
  if (!mail) e.email = t('people.users_err_email_short')
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) e.email = t('people.users_err_email_typo')
  else if (props.users.some((u) => u.id !== props.user?.id && u.email.toLowerCase() === mail.toLowerCase())) e.email = t('people.users_err_email_taken')
  return e
})
const blocked = computed(() => !!props.user && props.user.status === 'blocked')
const invited = computed(() => !!props.user && props.user.status === 'invited')
// why block / delete are not available (shown next to the buttons)
const whyBlock = computed(() => (isMe.value ? t('people.drawer_why_block_me') : isLastAdmin.value ? LAST_ADMIN : ''))
const whyDelete = computed(() => (isMe.value ? t('people.drawer_why_delete_me') : isLastAdmin.value ? LAST_ADMIN : ''))
// why the role cannot be changed (shown under the select)
const whyRole = computed(() => (isLastAdmin.value ? LAST_ADMIN : isMe.value ? t('people.drawer_why_role_me') : ''))

async function tryClose() {
  if (dirty.value && !(await confirm({ title: t('people.drawer_close_title'), message: t('people.drawer_close_message'), confirmLabel: t('people.drawer_close_confirm'), cancelLabel: t('people.btn_stay'), danger: true }))) return
  emit('close')
}

async function save() {
  submitted.value = true
  serverError.value = ''
  if (errors.value.name || errors.value.email || !dirty.value) return
  busy.value = 'save'
  try {
    const u = await api.users.update(props.user.id, { name: form.name, email: form.email, roleId: form.roleId })
    emit('changed', { self: isMe.value })
    toast.show(t('people.drawer_saved', { name: u.name }))
  } catch (e) {
    serverError.value = e.message || t('people.save_failed')
  } finally {
    busy.value = ''
  }
}

// the new one-time password, shown once in OneTimePasswordDialog
const issued = reactive({ open: false, name: '', email: '', password: '', mailError: '' })

function closeIssued() { Object.assign(issued, { open: false, password: '', mailError: '' }) }

async function resetPassword() {
  if (!(await confirm(props.mailReady
    ? {
      title: t('people.drawer_link_title', { name: props.user.name }),
      message: t('people.drawer_link_message', { email: props.user.email }),
      confirmLabel: t('people.drawer_send_mail'),
    }
    : {
      title: t('people.drawer_reset_title', { name: props.user.name }),
      message: t('people.drawer_reset_message'),
      confirmLabel: t('people.drawer_reset_pw'), danger: true,
    }))) return
  busy.value = 'pw'
  try {
    const r = await api.users.resetPassword(props.user.id)
    if (r.emailed) toast.show(t('people.users_mail_sent', { email: r.email }))
    else Object.assign(issued, { open: true, name: props.user.name, email: r.email, password: r.password, mailError: r.emailError || '' })
  } catch (e) {
    toast.error(e.message || t('people.drawer_reset_failed'))
  } finally {
    busy.value = ''
  }
}

async function resetTwoFactor() {
  if (!(await confirm({
    title: t('people.drawer_tf_title_q', { name: props.user.name }),
    message: t('people.drawer_tf_message'),
    confirmLabel: t('people.drawer_tf_reset_btn'), danger: true,
  }))) return
  busy.value = 'tf'
  try {
    await api.users.resetTwoFactor(props.user.id)
    emit('changed')
    toast.show(t('people.drawer_tf_done', { name: props.user.name }))
  } catch (e) {
    toast.error(e.message || t('people.drawer_tf_failed'))
  } finally {
    busy.value = ''
  }
}

async function setBlocked(on) {
  if (on && !(await confirm({
    title: t('people.drawer_block_title', { name: props.user.name }),
    message: t('people.drawer_block_message'),
    confirmLabel: t('people.drawer_block'), danger: true,
  }))) return
  busy.value = 'block'
  try {
    await api.users.setBlocked(props.user.id, on)
    emit('changed')
    toast.show(t(on ? 'people.drawer_blocked' : 'people.drawer_unblocked', { name: props.user.name }))
  } catch (e) {
    toast.error(e.message || t('people.drawer_block_failed'))
  } finally {
    busy.value = ''
  }
}

async function remove() {
  if (!(await confirm({
    title: t('people.drawer_delete_title_q', { name: props.user.name }),
    message: t('people.drawer_delete_message'),
    confirmLabel: t('people.btn_delete'), danger: true,
  }))) return
  busy.value = 'remove'
  try {
    await api.users.remove(props.user.id)
    const name = props.user.name
    emit('close')
    emit('changed')
    toast.show(t('people.drawer_deleted', { name }))
  } catch (e) {
    toast.error(e.message || t('people.drawer_delete_failed'))
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <Drawer :open="!!user" :title="user ? user.name : t('people.drawer_title')" :width="460" @update:open="(v) => !v && tryClose()">
    <template v-if="user">
      <div class="ud-head">
        <Avatar :name="form.name || user.name" :size="48" :muted="user.status !== 'active'" />
        <div class="ud-id">
          <span :class="['chip', statusOf(user).cls]"><span v-if="statusOf(user).dot" class="dot" /><Icon v-else-if="statusOf(user).icon" :name="statusOf(user).icon" size="s14" />{{ statusOf(user).label }}</span>
          <span v-if="isMe" class="ud-me">{{ t('people.users_me') }}</span>
          <div class="muted ud-seen">{{ user.lastSignIn ? t('people.drawer_last_signin', { when: lastSeen(user) }) : t('people.drawer_never') }}</div>
        </div>
      </div>

      <div v-if="heavier" class="alert info-a ud-lock"><Icon name="info" size="s16" /><span>{{ USER_TOO_BIG }}.</span></div>

      <form id="ud-form" class="ud-form" novalidate @submit.prevent="save">
        <div :class="['field', { 'is-error': submitted && !!errors.name }]">
          <div class="lbl"><label for="ud-name">{{ t('people.users_name') }}</label></div>
          <input id="ud-name" v-model="form.name" type="text" maxlength="60" autocomplete="off" :disabled="!editable" :aria-invalid="submitted && errors.name ? 'true' : undefined">
          <div v-if="submitted && errors.name" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.name }}</span></div>
        </div>
        <div :class="['field', { 'is-error': submitted && !!errors.email }]">
          <div class="lbl"><label for="ud-email">{{ t('people.users_email') }}</label></div>
          <input id="ud-email" v-model="form.email" type="text" inputmode="email" maxlength="80" autocomplete="off" :disabled="!editable" :aria-invalid="submitted && errors.email ? 'true' : undefined">
          <div v-if="submitted && errors.email" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ errors.email }}</span></div>
        </div>
        <div class="field">
          <div class="lbl"><label for="ud-role">{{ t('people.roles_th_role') }}</label></div>
          <select id="ud-role" v-model="form.roleId" :disabled="!!whyRole || !editable" aria-describedby="ud-role-h">
            <option v-for="r in roles" :key="r.id" :value="r.id" :disabled="!assignable(r) && r.id !== user.roleId" :title="assignable(r) || r.id === user.roleId ? undefined : ROLE_TOO_BIG">{{ r.name }}</option>
          </select>
          <div id="ud-role-h" class="help">{{ whyRole || (role ? role.description : '') }}<template v-if="!whyRole && !heavier && roles.some((r) => !assignable(r))"> {{ t('people.users_roles_too_big') }}</template></div>
        </div>
        <div v-if="serverError" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ serverError }}</span></div>
      </form>

      <dl class="kv ud-kv">
        <dt>{{ t('people.drawer_added') }}</dt><dd>{{ fmtD(user.createdAt) }}</dd>
      </dl>

      <section v-if="editable || deletable" class="ud-acts" :aria-label="t('people.actions')">
        <div v-if="editable" class="ud-act">
          <div class="ud-t">
            <b>{{ mailReady ? (invited ? t('people.drawer_act_resend') : t('people.drawer_act_link')) : (invited ? t('people.drawer_act_issue') : t('people.drawer_reset_pw')) }}</b>
            <span v-if="isMe">{{ t('people.drawer_pw_me') }}</span>
            <span v-else-if="mailReady">{{ t('people.drawer_pw_mail') }}</span>
            <span v-else>{{ t('people.drawer_pw_nomail') }}</span>
          </div>
          <button type="button" :class="['btn', 'sm', { loading: busy === 'pw' }]" :disabled="isMe" @click="resetPassword">{{ mailReady ? t('people.drawer_send_mail') : t('people.drawer_reset_btn') }}</button>
        </div>
        <div v-if="editable && user.twoFactor && !isMe" class="ud-act">
          <div class="ud-t">
            <b>{{ t('people.drawer_tf_title') }}</b>
            <span>{{ t('people.drawer_tf_help') }}</span>
          </div>
          <button type="button" :class="['btn', 'sm', { loading: busy === 'tf' }]" @click="resetTwoFactor">{{ t('people.drawer_tf_reset_btn') }}</button>
        </div>
        <div v-if="editable" class="ud-act">
          <div class="ud-t">
            <b>{{ blocked ? t('people.drawer_unblock') : t('people.drawer_block') }}</b>
            <span v-if="whyBlock && !blocked">{{ whyBlock }}</span>
            <span v-else>{{ blocked ? t('people.drawer_unblock_help') : t('people.drawer_block_help') }}</span>
          </div>
          <button type="button" :class="['btn', 'sm', { loading: busy === 'block' }]" :disabled="!blocked && !!whyBlock" @click="setBlocked(!blocked)">{{ blocked ? t('people.drawer_unblock') : t('people.drawer_block') }}</button>
        </div>
        <div v-if="deletable" class="ud-act">
          <div class="ud-t">
            <b>{{ t('people.drawer_delete_title') }}</b>
            <span v-if="whyDelete">{{ whyDelete }}</span>
            <span v-else>{{ t('people.drawer_delete_help') }}</span>
          </div>
          <button type="button" :class="['btn', 'sm', 'danger', { loading: busy === 'remove' }]" :disabled="!!whyDelete" @click="remove"><Icon name="trash" size="s14" />{{ t('people.btn_delete') }}</button>
        </div>
      </section>
    </template>
    <template #footer>
      <button type="button" class="btn" @click="tryClose">{{ dirty ? t('people.btn_cancel') : t('people.btn_close') }}</button>
      <button v-if="editable" type="submit" form="ud-form" :class="['btn', 'primary', { loading: busy === 'save' }]" :disabled="!dirty">{{ t('people.btn_save') }}</button>
    </template>
  </Drawer>
  <OneTimePasswordDialog :open="issued.open" :name="issued.name" :email="issued.email" :password="issued.password" :mail-error="issued.mailError" @update:open="closeIssued" />
</template>

<style scoped>
.ud-head{display:flex;align-items:center;gap:14px}
.ud-id{display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px}
.ud-me{font-size:var(--t-xs);font-weight:620;color:var(--brand-text)}
.ud-seen{flex:0 0 100%;font-size:var(--t-sm)}
.ud-lock{margin:14px 0 0}
.ud-form{display:flex;flex-direction:column}
.ud-form .field{margin:0 -12px}
.ud-kv{grid-template-columns:100px 1fr}
.ud-acts{border-top:1px solid var(--hair);display:flex;flex-direction:column}
.ud-act{display:flex;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid var(--hair-2)}
.ud-act:last-child{border-bottom:0}
.ud-t{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ud-t b{font-weight:600;font-size:var(--t-sm)}
.ud-t span{font-size:var(--t-sm);color:var(--ink-2);line-height:1.4}
</style>
