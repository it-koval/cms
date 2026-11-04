<script setup>
// Роли: a role = a set of permissions. The list (name, what it allows, how many users, «Встроенная» tag) and the editor of one role: name, description, the permission
// matrix (PermissionMatrix, rows generated from the site's structure) and, for pages, the template scope.
// Built-in roles open read-only with «Дублировать, чтобы изменить»; custom roles can be created («Новая роль» / «Дублировать»), renamed, edited (saved with the bar at the
// bottom) and deleted (people who have the role are moved to another one first).
import { computed, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { api } from '@/api/index.js'
import { app, can, refreshCurrentUser, useLocaleGuard } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import ReadOnlyNote from '@/components/ui/ReadOnlyNote.vue'
import Modal from '@/components/ui/Modal.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import PermissionMatrix from './PermissionMatrix.vue'
import { articleTemplates, covers, permissionAreas, ROLE_TOO_BIG } from '@/utils/permissions.js'
import { clone, same } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'

import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'

const toast = useToast()
const confirm = useConfirm()

const roles = ref([])
const users = ref([])
const loading = ref(true)
const loadError = ref('')
const openId = ref(null)

const areas = computed(() => permissionAreas())
const articles = computed(() => articleTemplates())

async function load() {
  try {
    const [r, u] = await Promise.all([api.users.listRoles(), api.users.list()])
    roles.value = r
    users.value = u
    loadError.value = ''
  } catch (e) {
    loadError.value = e.message || t('people.roles_load_failed')
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---- the open role: a draft next to the saved role ---- */
const role = computed(() => roles.value.find((r) => r.id === openId.value) || null)
const draft = reactive({ name: '', description: '', perms: {}, templates: null })
const submitted = ref(false)
const saving = ref(false)
const serverError = ref('')
// rights of the person at the screen: without «Править» the roles can only be looked at; without «Удалять» they cannot be deleted
const canEdit = computed(() => can('set:users', 'edit'))
const canDelete = computed(() => can('set:users', 'delete'))
const readonly = computed(() => !!role.value && (role.value.builtin || !canEdit.value))

function startEdit(r) {
  openId.value = r.id
  Object.assign(draft, { name: r.name, description: r.description || '', perms: clone(r.perms), templates: clone(r.templates) })
  submitted.value = false
  serverError.value = ''
}
// the same rights in another order of keys are the same rights
const permsKey = (o) => Object.keys(o).sort().map((k) => k + ':' + o[k].join()).join('|')
const dirty = computed(() => !!role.value && !role.value.builtin && (
  draft.name.trim() !== role.value.name || draft.description.trim() !== (role.value.description || '') || permsKey(draft.perms) !== permsKey(role.value.perms) || !same(draft.templates, role.value.templates)
))
const nameError = computed(() => {
  const n = draft.name.trim()
  if (!n) return t('people.roles_name_required')
  if (n.length > 40) return t('people.roles_name_too_long')
  if (roles.value.some((r) => r.id !== openId.value && r.name.toLowerCase() === n.toLowerCase())) return t('people.roles_name_taken')
  return ''
})
const templateError = computed(() => (Array.isArray(draft.templates) && !draft.templates.length && (draft.perms.pages || []).length ? t('people.roles_template_required', { all: t('people.matrix_all_templates') }) : ''))
const usersOf = (r) => users.value.filter((u) => u.roleId === r.id)

async function save() {
  submitted.value = true
  serverError.value = ''
  if (nameError.value || templateError.value) return
  saving.value = true
  try {
    const next = await api.users.updateRole(openId.value, {
      name: draft.name, description: draft.description, perms: draft.perms,
      templates: (draft.perms.pages || []).length ? draft.templates : null,
    })
    await load()
    startEdit(roles.value.find((r) => r.id === next.id) || next)
    refreshCurrentUser() // the rights of the person at the screen may have changed
    toast.show(t('people.roles_saved', { name: next.name }))
  } catch (e) {
    serverError.value = e.message || t('people.roles_save_failed')
  } finally {
    saving.value = false
  }
}
function discard() {
  startEdit(role.value)
  toast.show(t('people.roles_discarded'))
}

/* ---- leaving with unsaved edits: back to the list, another tab, another screen, another content language ---- */
const confirmLeave = () => (dirty.value ? confirm({
  title: t('people.roles_leave_title'), message: t('people.roles_leave_message'),
  confirmLabel: t('people.roles_leave_confirm'), cancelLabel: t('people.btn_stay'), danger: true,
}) : true)
onBeforeRouteLeave(confirmLeave)
onBeforeRouteUpdate(confirmLeave)
useLocaleGuard(confirmLeave)
async function back() { if (await confirmLeave()) { openId.value = null; load() } }

/* ---- new role / duplicate ---- */
const dlg = reactive({ open: false, name: '', description: '' })
const dlgSubmitted = ref(false)
const dlgSaving = ref(false)
const dlgServer = ref('')
const dlgError = computed(() => {
  const n = dlg.name.trim()
  if (!n) return t('people.roles_name_required')
  if (n.length > 40) return t('people.roles_name_too_long')
  if (roles.value.some((r) => r.name.toLowerCase() === n.toLowerCase())) return t('people.roles_name_taken')
  return ''
})
function openNew() { Object.assign(dlg, { open: true, name: '', description: '' }); dlgSubmitted.value = false; dlgServer.value = '' }
async function create() {
  dlgSubmitted.value = true
  dlgServer.value = ''
  if (dlgError.value) return
  dlgSaving.value = true
  try {
    const r = await api.users.createRole({ name: dlg.name, description: dlg.description })
    dlg.open = false
    await load()
    startEdit(roles.value.find((x) => x.id === r.id) || r)
    toast.show(t('people.roles_created', { name: r.name }))
  } catch (e) {
    dlgServer.value = e.message || t('people.roles_create_failed')
  } finally {
    dlgSaving.value = false
  }
}
async function duplicate(r) {
  if (openId.value && !(await confirmLeave())) return
  let name = t('people.roles_copy_name', { name: r.name })
  for (let i = 2; roles.value.some((x) => x.name.toLowerCase() === name.toLowerCase()); i++) name = t('people.roles_copy_name_n', { name: r.name, n: i })
  try {
    const copy = await api.users.createRole({ name, description: r.description, copyOf: r.id })
    await load()
    startEdit(roles.value.find((x) => x.id === copy.id) || copy)
    const trimmed = permsKey(copy.perms) !== permsKey(r.perms) // nobody can hand out more than he has himself
    toast.show(t(trimmed ? 'people.roles_copied_trimmed' : 'people.roles_copied', { name: copy.name }))
  } catch (e) {
    toast.error(e.message || t('people.roles_copy_failed'))
  }
}

/* ---- delete ---- */
const del = reactive({ open: false, role: null, moveTo: '', saving: false, error: '' })
const moveTargets = computed(() => roles.value.filter((r) => del.role && r.id !== del.role.id))
async function finishDelete(r, moveTo) {
  const res = await api.users.removeRole(r.id, { moveTo })
  if (openId.value === r.id) openId.value = null
  await load()
  refreshCurrentUser()
  toast.show(res.moved ? t('people.roles_deleted_moved', { name: r.name, count: res.moved }) : t('people.roles_deleted', { name: r.name }))
}
async function askDelete(r) {
  if (r.builtin) return
  if (r.id === openId.value && !(await confirmLeave())) return
  if (r.users) {
    Object.assign(del, { open: true, role: r, moveTo: '', saving: false, error: '' })
    return
  }
  const ok = await confirm({ title: t('people.roles_delete_title', { name: r.name }), message: t('people.roles_delete_unassigned'), confirmLabel: t('people.btn_delete'), danger: true })
  if (!ok) return
  try { await finishDelete(r, null) } catch (e) { toast.error(e.message || t('people.roles_delete_failed')) }
}
async function confirmDelete() {
  if (!del.moveTo) return
  del.saving = true
  del.error = ''
  try {
    await finishDelete(del.role, del.moveTo)
    del.open = false
  } catch (e) {
    del.error = e.message || t('people.roles_delete_failed')
  } finally {
    del.saving = false
  }
}
const namesOf = (r) => usersOf(r).map((u) => u.name).join(', ')

/* ---- «Требовать двухэтапный вход»: saved at once, for built-in roles too (it is not a right) ---- */
const factorBusy = ref(false)
async function toggleFactor(event) {
  const on = event.target.checked
  factorBusy.value = true
  try {
    const next = await api.users.updateRole(role.value.id, { require2fa: on })
    const i = roles.value.findIndex((x) => x.id === next.id)
    if (i >= 0) roles.value[i] = { ...roles.value[i], require2fa: !!next.require2fa }
    toast.show(t(on ? 'people.roles_2fa_on' : 'people.roles_2fa_off', { name: next.name }))
  } catch (e) {
    event.target.checked = !on
    toast.error(e.message || t('people.save_failed'))
  } finally {
    factorBusy.value = false
  }
}
</script>

<template>
  <ReadOnlyNote v-if="!canEdit" :text="t('people.roles_readonly_note')" />
  <!-- the list of roles -->
  <section v-if="!role" class="sheet rl">
    <div class="rl-intro">
      <h2>{{ t('people.roles_title') }}</h2>
      <p class="d">{{ t('people.roles_intro') }}</p>
    </div>
    <div class="toolbar">
      <span class="num muted">{{ t('people.roles_count', { count: roles.length }) }}</span>
      <span class="grow" />
      <button v-if="canEdit" type="button" class="btn primary" :disabled="loading || !!loadError" @click="openNew"><Icon name="plus" size="s16" />{{ t('people.roles_new') }}</button>
    </div>
    <div v-if="loading" class="rl-pad"><Skeleton :rows="5" /></div>
    <div v-else-if="loadError" class="rl-pad"><div class="alert err-a"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load">{{ t('people.retry') }}</button></div></div>
    <table v-else class="t t-stack" :aria-label="t('people.roles_title')">
      <thead><tr><th>{{ t('people.roles_th_role') }}</th><th>{{ t('people.roles_th_what') }}</th><th class="rl-n">{{ t('people.roles_th_users') }}</th><th><span class="sr">{{ t('people.actions') }}</span></th></tr></thead>
      <tbody>
        <tr v-for="r in roles" :key="r.id" class="click" @click="startEdit(r)">
          <td class="rl-name ts-main">
            <button type="button" class="rl-open" :aria-label="t('people.roles_open_aria', { name: r.name })" @click.stop="startEdit(r)">{{ r.name }}</button>
            <span v-if="r.builtin" class="chip rl-tag"><Icon name="lock" size="s14" />{{ t('people.roles_builtin') }}</span>
          </td>
          <td class="rl-desc muted ts-line">{{ r.description }}</td>
          <td class="rl-n num ts-sub" :data-label="t('people.roles_th_users')">{{ r.users }}</td>
          <td class="nw rl-acts ts-acts" @click.stop>
            <button v-if="canEdit" type="button" class="btn ghost icon sm" :aria-label="t('people.roles_duplicate_aria', { name: r.name })" :title="t('people.roles_duplicate')" @click="duplicate(r)"><Icon name="copy" size="s14" /></button>
            <button v-if="!r.builtin && canDelete" type="button" class="btn ghost icon sm" :aria-label="t('people.roles_delete_aria', { name: r.name })" :title="t('people.btn_delete')" @click="askDelete(r)"><Icon name="trash" size="s14" /></button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <!-- one role -->
  <template v-else>
    <section class="sheet rl-one">
      <div class="rl-top">
        <button type="button" class="btn ghost sm rl-back" @click="back"><Icon name="chevR" size="s14" class="rl-flip" />{{ t('people.roles_all') }}</button>
        <span class="grow" />
        <button v-if="canEdit" type="button" class="btn sm" @click="duplicate(role)"><Icon name="copy" size="s14" />{{ t('people.roles_duplicate') }}</button>
        <button v-if="!role.builtin && canDelete" type="button" class="btn sm danger" @click="askDelete(role)"><Icon name="trash" size="s14" />{{ t('people.roles_delete') }}</button>
      </div>

      <div v-if="readonly" class="rl-head">
        <h2>{{ role.name }} <span v-if="role.builtin" class="chip rl-tag"><Icon name="lock" size="s14" />{{ t('people.roles_builtin') }}</span></h2>
        <p class="d">{{ role.description }}</p>
        <div v-if="role.builtin" class="alert info-a rl-ro">
          <Icon name="info" size="s16" />
          <span>{{ t('people.roles_builtin_note') }}<template v-if="canEdit"> {{ t('people.roles_builtin_copy_hint') }}</template></span>
          <button v-if="canEdit" type="button" class="btn primary sm" @click="duplicate(role)">{{ t('people.roles_duplicate_to_edit') }}</button>
        </div>
      </div>
      <div v-else class="rl-head rl-form">
        <div :class="['field', { 'is-error': submitted && !!nameError }]">
          <div class="lbl"><label for="rl-name">{{ t('people.roles_name_label') }}</label></div>
          <input id="rl-name" v-model="draft.name" type="text" maxlength="60" autocomplete="off" :aria-invalid="submitted && nameError ? 'true' : undefined">
          <div v-if="submitted && nameError" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ nameError }}</span></div>
        </div>
        <div class="field">
          <div class="lbl"><label for="rl-desc">{{ t('people.description') }}</label></div>
          <input id="rl-desc" v-model="draft.description" type="text" maxlength="200" autocomplete="off" :placeholder="t('people.roles_desc_placeholder')">
        </div>
      </div>

      <div class="rl-users muted"><Icon name="users" size="s14" />
        <template v-if="role.users">{{ t('people.roles_users_with', { count: role.users, names: namesOf(role) }) }}</template>
        <template v-else>{{ t('people.roles_unassigned') }}</template>
      </div>

      <div class="rl-2fa">
        <label class="rl-2fa-l"><input type="checkbox" :checked="!!role.require2fa" :disabled="!canEdit || factorBusy" @change="toggleFactor"> {{ t('people.roles_2fa_label') }}</label>
        <div class="help">{{ t('people.roles_2fa_help', { save: t('people.btn_save') }) }}</div>
      </div>

      <PermissionMatrix
        :areas="areas" :perms="readonly ? role.perms : draft.perms" :templates="readonly ? role.templates : draft.templates" :readonly="readonly" :keep="role.perms"
        :article-templates="articles" :template-error="submitted ? templateError : ''"
        @update:perms="(v) => (draft.perms = v)" @update:templates="(v) => (draft.templates = v)"
      />
      <div v-if="serverError" class="alert err-a rl-err" role="alert"><Icon name="alert" size="s16" /><span>{{ serverError }}</span></div>
    </section>

    <div v-if="dirty" class="savebar rl-bar" role="region" :aria-label="t('people.roles_unsaved_aria')">
      <span class="pmark" aria-hidden="true"><i /></span>
      <span>{{ t('people.roles_unsaved') }}</span>
      <button type="button" class="btn" :disabled="saving" @click="discard">{{ t('people.btn_revert') }}</button>
      <button type="button" :class="['btn', 'primary', { loading: saving }]" @click="save"><Icon name="save" size="s16" />{{ t('people.roles_save') }}</button>
    </div>
  </template>

  <Modal v-model:open="dlg.open" :title="t('people.roles_new')" :width="500" initial-focus="#rl-new-name">
    <form id="rl-form" class="rl-mform" novalidate @submit.prevent="create">
      <div :class="['field', { 'is-error': dlgSubmitted && !!dlgError }]">
        <div class="lbl"><label for="rl-new-name">{{ t('people.roles_name_label') }}</label></div>
        <input id="rl-new-name" v-model="dlg.name" type="text" maxlength="60" autocomplete="off" :placeholder="t('people.roles_name_placeholder')" :aria-invalid="dlgSubmitted && dlgError ? 'true' : undefined">
        <div v-if="dlgSubmitted && dlgError" class="err" role="alert"><Icon name="alert" size="s16" /><span>{{ dlgError }}</span></div>
      </div>
      <div class="field">
        <div class="lbl"><label for="rl-new-desc">{{ t('people.description') }}</label></div>
        <input id="rl-new-desc" v-model="dlg.description" type="text" maxlength="200" autocomplete="off" :placeholder="t('people.roles_desc_placeholder')">
        <div class="help">{{ t('people.roles_new_help', { duplicate: t('people.roles_duplicate') }) }}</div>
      </div>
      <div v-if="dlgServer" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ dlgServer }}</span></div>
    </form>
    <template #footer>
      <button type="button" class="btn" @click="dlg.open = false">{{ t('people.btn_cancel') }}</button>
      <button type="submit" form="rl-form" :class="['btn', 'primary', { loading: dlgSaving }]">{{ t('people.roles_create') }}</button>
    </template>
  </Modal>

  <Modal v-model:open="del.open" :title="del.role ? t('people.roles_delete_title', { name: del.role.name }) : t('people.roles_delete')" :width="500">
    <template v-if="del.role">
      <p class="muted rl-dp">{{ t('people.roles_delete_users', { count: del.role.users, names: namesOf(del.role) }) }}</p>
      <div class="field rl-mv">
        <div class="lbl"><label for="rl-move">{{ t('people.roles_move_label') }}</label></div>
        <select id="rl-move" v-model="del.moveTo">
          <option value="" disabled>{{ t('people.roles_pick') }}</option>
          <option v-for="r in moveTargets" :key="r.id" :value="r.id" :disabled="!covers(app.user, r)" :title="covers(app.user, r) ? undefined : ROLE_TOO_BIG">{{ r.name }}</option>
        </select>
        <div v-if="del.moveTo" class="help">{{ (moveTargets.find((r) => r.id === del.moveTo) || {}).description }}</div>
      </div>
      <div v-if="del.error" class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ del.error }}</span></div>
    </template>
    <template #footer>
      <button type="button" class="btn" @click="del.open = false">{{ t('people.btn_cancel') }}</button>
      <button type="button" :class="['btn', 'danger', { loading: del.saving }]" :disabled="!del.moveTo" @click="confirmDelete">{{ t('people.roles_move_delete') }}</button>
    </template>
  </Modal>
</template>

<style scoped>
.rl{margin-bottom:16px}
.rl-intro{padding:20px 22px 12px}
.rl-intro h2{font-size:var(--t-md);font-weight:650;margin-bottom:2px}
.rl-intro .d{color:var(--ink-2);font-size:var(--t-sm);max-width:68ch}
.rl-pad{padding:14px}
.rl-name{white-space:nowrap}
.rl-open{background:none;border:0;padding:0;font:inherit;font-weight:580;color:var(--ink);text-align:left}
.rl-open:hover{color:var(--brand-text)}
.rl-tag{margin-left:8px;vertical-align:1px}
.rl-desc{font-size:var(--t-sm);max-width:46ch;line-height:1.4}
.rl-n{width:110px;text-align:right}
th.rl-n{text-align:right}
.rl-acts{text-align:right}
.rl-one{margin-bottom:16px;padding-bottom:18px}
.rl-top{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid var(--hair)}
.rl-top .grow{flex:1}
.rl-back{padding-left:6px}
.rl-flip{transform:rotate(180deg)}
.rl-head{padding:18px 22px 4px}
.rl-head h2{font-size:var(--t-lg);font-weight:650;line-height:1.2}
.rl-head h2 .rl-tag{font-size:var(--t-xs);vertical-align:3px}
.rl-head .d{color:var(--ink-2);margin-top:4px;max-width:66ch}
.rl-ro{margin-top:14px;align-items:center}
.rl-ro span{flex:1}
.rl-form{display:grid;grid-template-columns:1fr 1.6fr;gap:0 24px}
.rl-form .field{margin:0 -12px}
.rl-2fa{padding:0 22px 14px}
.rl-2fa-l{display:flex;align-items:center;gap:8px;font-size:var(--t-sm);font-weight:600}
.rl-2fa .help{margin-top:4px;max-width:68ch}
.rl-users{display:flex;align-items:center;gap:8px;padding:6px 22px 14px;font-size:var(--t-sm)}
.rl-err{margin:14px 22px 0}
.rl-bar{left:calc(var(--rail-w) + (100vw - var(--rail-w)) / 2);bottom:78px} /* above the toasts, which sit at the bottom edge */
.rl-mform{display:flex;flex-direction:column}
.rl-mform .field{margin:0 -12px}
.rl-dp{max-width:52ch;margin-bottom:6px}
.rl-mv{margin:0 -12px}
</style>
