<script setup>
// Folder tree on the left of the media library: «Все файлы», «Без папки» and the folders (nested, collapsible).
// Drop files on a folder to move them; drop a folder on another folder (or on «Без папки») to nest it.
// With the right to edit: create (also inside a folder), rename, delete. A folder with files or folders inside is deleted together with moving them elsewhere.
// Props: folders [{ id, parentId, name, position, files }], total, unfiled, modelValue (the open folder: '' all files, 'none' no folder, or an id).
// Emits: update:modelValue; changed (the tree must be loaded again); drop-files (ids, folderId|null).
import { computed, nextTick, ref } from 'vue'
import { api } from '@/api/index.js'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/ui/Modal.vue'
import { useToast } from '@/components/ui/useToast.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { FILES, FOLDER, hasDrag, readDrag, startDrag } from './mediaDrag.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  folders: { type: Array, default: () => [] },
  total: { type: Number, default: 0 },
  unfiled: { type: Number, default: 0 },
  modelValue: { type: String, default: '' },
  canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'changed', 'drop-files'])
const toast = useToast()
const confirm = useConfirm()

const collapsed = ref(new Set())
const editing = ref(null) // { id, parentId, name } id null: a new folder
const over = ref('') // the row a drag is over: a folder id or 'none'
const removing = ref(null) // the folder asked about in the «куда перенести» dialog
const moveTo = ref('root')
const busy = ref(false)
const input = ref(null)

const byParent = computed(() => {
  const map = new Map()
  for (const f of props.folders) {
    const key = f.parentId || ''
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(f)
  }
  return map
})
const rows = computed(() => {
  const out = []
  const walk = (parent, depth) => {
    for (const f of byParent.value.get(parent) || []) {
      const kids = (byParent.value.get(f.id) || []).length
      out.push({ ...f, depth, kids })
      if (kids && !collapsed.value.has(f.id)) walk(f.id, depth + 1)
    }
  }
  walk('', 0)
  return out
})
const byId = computed(() => new Map(props.folders.map((f) => [f.id, f])))

const choose = (key) => emit('update:modelValue', key)
function toggle(id) {
  const next = new Set(collapsed.value)
  next.has(id) ? next.delete(id) : next.add(id)
  collapsed.value = next
}

/** Ids of a folder and everything below it. */
function branch(id) {
  const out = [id]
  for (const k of byParent.value.get(id) || []) out.push(...branch(k.id))
  return out
}
const depthOf = (id) => { let n = 0; for (let f = byId.value.get(id); f; f = byId.value.get(f.parentId)) n++; return n }
const label = (f) => '— '.repeat(depthOf(f.id) - 1) + f.name

// ---- create / rename
async function startEdit(f, parentId = null) {
  if (parentId) { const s = new Set(collapsed.value); s.delete(parentId); collapsed.value = s }
  editing.value = f ? { id: f.id, parentId: f.parentId, name: f.name } : { id: null, parentId, name: '' }
  await nextTick()
  const el = input.value && (Array.isArray(input.value) ? input.value[0] : input.value)
  if (el) { el.focus(); el.select() }
}
async function saveEdit() {
  const e = editing.value
  if (!e || busy.value) return
  const name = e.name.trim()
  if (!name) { editing.value = null; return }
  busy.value = true
  try {
    if (e.id) {
      const old = byId.value.get(e.id)
      if (!old || old.name !== name) await api.media.updateFolder(e.id, { name })
    } else {
      const f = await api.media.createFolder(name, e.parentId)
      choose(f.id)
    }
    editing.value = null
    emit('changed')
  } catch (err) {
    toast.error(err.message || t('forms.tree_save_failed'))
  } finally {
    busy.value = false
  }
}
const cancelEdit = () => { editing.value = null }

// ---- delete
async function askRemove(f) {
  const kids = (byParent.value.get(f.id) || []).length
  if (!f.files && !kids) {
    const ok = await confirm({ title: t('forms.tree_delete_empty_title'), message: t('forms.tree_delete_empty', { name: f.name }), confirmLabel: t('forms.delete'), danger: true })
    if (ok) await doRemove(f, '')
    return
  }
  moveTo.value = f.parentId || 'root'
  removing.value = f
}
async function doRemove(f, target) {
  busy.value = true
  try {
    await api.media.removeFolder(f.id, target)
    if (props.modelValue === f.id) choose(target && target !== 'root' ? target : '')
    removing.value = null
    toast.show(t('forms.tree_deleted', { name: f.name }))
    emit('changed')
  } catch (err) {
    toast.error(err.message || t('forms.tree_delete_failed'))
  } finally {
    busy.value = false
  }
}
const moveTargets = computed(() => {
  const gone = removing.value ? new Set(branch(removing.value.id)) : new Set()
  return props.folders.filter((f) => !gone.has(f.id))
})
const removeText = computed(() => {
  const f = removing.value
  if (!f) return ''
  const kids = (byParent.value.get(f.id) || []).length
  const parts = []
  if (f.files) parts.push(t('forms.tree_files', { count: f.files }))
  if (kids) parts.push(t('forms.tree_folders', { count: kids }))
  return t('forms.tree_remove_text', { name: f.name, what: parts.join(t('forms.list_and')) })
})

// ---- drag and drop
function dragOver(e, key) {
  if (!props.canEdit || !hasDrag(e)) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  over.value = key
}
function dragLeave(key) { if (over.value === key) over.value = '' }
async function drop(e, folderId) {
  over.value = ''
  const d = readDrag(e)
  if (!props.canEdit || !d) return
  e.preventDefault()
  if (d.type === FILES) { emit('drop-files', d.payload, folderId); return }
  if (d.type === FOLDER) {
    const id = d.payload
    if (id === folderId || (folderId && branch(id).includes(folderId))) { toast.error(t('forms.tree_into_self')); return }
    if ((byId.value.get(id) || {}).parentId === folderId) return
    try {
      await api.media.updateFolder(id, { parentId: folderId })
      if (folderId) { const s = new Set(collapsed.value); s.delete(folderId); collapsed.value = s }
      emit('changed')
    } catch (err) {
      toast.error(err.message || t('forms.tree_move_failed'))
    }
  }
}
</script>

<template>
  <nav class="ft" :aria-label="t('forms.tree_aria')">
    <div class="ft-h">
      <span>{{ t('forms.tree_title') }}</span>
      <button v-if="canEdit" type="button" class="btn ghost icon sm" :aria-label="t('forms.tree_new')" :title="t('forms.tree_new')" @click="startEdit(null, null)"><Icon name="plus" size="s16" /></button>
    </div>

    <button type="button" :class="['ft-row', { on: modelValue === '' }]" :aria-current="modelValue === '' ? 'true' : undefined" @click="choose('')">
      <Icon name="image" size="s16" /><span class="nm">{{ t('forms.tree_all') }}</span><span class="n num">{{ total }}</span>
    </button>
    <button
      type="button" :class="['ft-row', { on: modelValue === 'none', drop: over === 'none' }]" :aria-current="modelValue === 'none' ? 'true' : undefined"
      @click="choose('none')" @dragover="dragOver($event, 'none')" @dragleave="dragLeave('none')" @drop="drop($event, null)"
    >
      <Icon name="folder" size="s16" /><span class="nm">{{ t('forms.no_folder') }}</span><span class="n num">{{ unfiled }}</span>
    </button>

    <div v-if="editing && editing.id === null && !editing.parentId" class="ft-edit">
      <input ref="input" v-model="editing.name" type="text" maxlength="120" :placeholder="t('forms.tree_name')" :aria-label="t('forms.tree_new_name')" @keydown.enter.prevent="saveEdit" @keydown.esc.prevent="cancelEdit" @blur="saveEdit">
    </div>

    <template v-for="f in rows" :key="f.id">
      <div v-if="editing && editing.id === f.id" class="ft-edit" :style="{ paddingLeft: 8 + f.depth * 16 + 'px' }">
        <input ref="input" v-model="editing.name" type="text" maxlength="120" :aria-label="t('forms.tree_renamed_name')" @keydown.enter.prevent="saveEdit" @keydown.esc.prevent="cancelEdit" @blur="saveEdit">
      </div>
      <div
        v-else
        :class="['ft-line', { on: modelValue === f.id, drop: over === f.id }]" :style="{ paddingLeft: 4 + f.depth * 16 + 'px' }"
        :draggable="canEdit" @dragstart="startDrag($event, FOLDER, f.id)" @dragover="dragOver($event, f.id)" @dragleave="dragLeave(f.id)" @drop="drop($event, f.id)"
      >
        <button v-if="f.kids" type="button" class="ft-tg" :aria-label="collapsed.has(f.id) ? t('forms.tree_expand') : t('forms.tree_collapse')" :aria-expanded="!collapsed.has(f.id)" @click="toggle(f.id)">
          <Icon name="chevR" size="s14" :style="{ transform: collapsed.has(f.id) ? 'none' : 'rotate(90deg)' }" />
        </button>
        <span v-else class="ft-tg" />
        <button type="button" class="ft-row in" :aria-current="modelValue === f.id ? 'true' : undefined" @click="choose(f.id)">
          <Icon name="folder" size="s16" /><span class="nm">{{ f.name }}</span><span class="n num">{{ f.files }}</span>
        </button>
        <span v-if="canEdit" class="ft-act">
          <button type="button" class="btn ghost icon sm" :aria-label="t('forms.tree_new_inside', { name: f.name })" :title="t('forms.tree_new_nested')" @click="startEdit(null, f.id)"><Icon name="plus" size="s14" /></button>
          <button type="button" class="btn ghost icon sm" :aria-label="t('forms.tree_rename_aria', { name: f.name })" :title="t('forms.tree_rename')" @click="startEdit(f)"><Icon name="edit" size="s14" /></button>
          <button type="button" class="btn ghost icon sm" :aria-label="t('forms.tree_delete_aria', { name: f.name })" :title="t('forms.delete')" @click="askRemove(f)"><Icon name="trash" size="s14" /></button>
        </span>
      </div>
      <div v-if="editing && editing.id === null && editing.parentId === f.id" class="ft-edit" :style="{ paddingLeft: 8 + (f.depth + 1) * 16 + 'px' }">
        <input ref="input" v-model="editing.name" type="text" maxlength="120" :placeholder="t('forms.tree_name')" :aria-label="t('forms.tree_new_name')" @keydown.enter.prevent="saveEdit" @keydown.esc.prevent="cancelEdit" @blur="saveEdit">
      </div>
    </template>

    <p v-if="!folders.length && !editing" class="ft-empty muted">{{ canEdit ? t('forms.tree_empty_edit') : t('forms.tree_empty') }}</p>

    <Modal :open="!!removing" :title="t('forms.tree_remove_title')" :width="460" @update:open="(v) => !v && (removing = null)">
      <p class="ft-p">{{ removeText }}</p>
      <div class="field ft-sel">
        <div class="lbl"><label for="ft-move">{{ t('forms.tree_move_to') }}</label></div>
        <select id="ft-move" v-model="moveTo">
          <option value="root">{{ t('forms.tree_move_root') }}</option>
          <option v-for="f in moveTargets" :key="f.id" :value="f.id">{{ label(f) }}</option>
        </select>
      </div>
      <template #footer>
        <button type="button" class="btn" @click="removing = null">{{ t('forms.cancel') }}</button>
        <button type="button" class="btn danger" :disabled="busy" @click="doRemove(removing, moveTo)"><Icon name="trash" size="s14" />{{ t('forms.tree_move_delete') }}</button>
      </template>
    </Modal>
  </nav>
</template>

<style scoped>
.ft{display:flex;flex-direction:column;gap:1px;padding:10px 8px 14px}
.ft-h{display:flex;align-items:center;justify-content:space-between;padding:2px 4px 6px 8px;font-size:var(--t-xs);font-weight:650;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-3)}
.ft-row{display:flex;align-items:center;gap:8px;min-height:32px;padding:4px 8px;border:0;border-radius:var(--r);background:transparent;color:var(--ink);text-align:left;width:100%;min-width:0}
.ft-row:hover{background:var(--hair-2)}
.ft-row .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ft-row .n{font-size:var(--t-xs);color:var(--ink-3)}
.ft-row.on,.ft-line.on{background:var(--cy-50);color:var(--cy-dd);font-weight:600}
.ft-row.drop,.ft-line.drop{background:var(--cy-100);box-shadow:inset 0 0 0 2px var(--cy)}
.ft-line{display:flex;align-items:center;border-radius:var(--r);min-width:0}
.ft-line:hover{background:var(--hair-2)}
.ft-line.on:hover{background:var(--cy-50)}
.ft-line .ft-row{background:transparent}
.ft-line.drop .ft-row{background:transparent}
.ft-tg{width:22px;height:28px;flex:none;display:grid;place-items:center;border:0;background:transparent;color:var(--ink-3);padding:0}
.ft-tg svg{transition:transform var(--dur)}
.ft-act{display:none;gap:0;flex:none}
.ft-line:hover .ft-act,.ft-line:focus-within .ft-act{display:flex}
.ft-edit{padding:3px 4px}
.ft-edit input{height:32px}
.ft-empty{font-size:var(--t-sm);padding:8px;line-height:1.4}
.ft-p{margin:0 0 10px;line-height:1.45}
.ft-sel{margin:0}
@media (hover:none){.ft-act{display:flex}}
@media (max-width:767px){
  .ft{padding:0 0 8px}
  .ft-row{min-height:44px}
  .ft-tg{width:36px;height:44px}
  .ft-act{display:flex}
  .ft-edit input{height:44px}
}
</style>
