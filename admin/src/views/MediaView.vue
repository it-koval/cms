<script setup>
// Медиатека: folder tree on the left; grid of photos and PDFs, search + type filter, upload (file input and drag-and-drop), details drawer.
// Uploads go through api.media.upload (into the open folder); «Где используется» is asked from the server for the file that is open (api.media.usage).
// Files go to a folder by dragging a tile onto the tree, or by ticking tiles and choosing «Перенести в…». The open folder is kept in ?folder= ('' all, 'none' no folder, or an id).
// RIGHTS (area media): without «Править» there is no upload (button and drop zone), no folders work and no moving, and the captions only read; without «Удалять» the drawer has no «Удалить».
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/index.js'
import { thumbUrl } from '@/utils/media.js'
import Icon from '@/components/ui/Icon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ListPager from '@/components/collections/list/ListPager.vue'
import { useListCursor } from '@/composables/useListCursor.js'
import MediaDrawer from '@/components/media/MediaDrawer.vue'
import { useMediaUsage } from '@/components/media/useMediaUsage.js'
import FolderTree from '@/components/media/FolderTree.vue'
import { FILES, hasDrag, startDrag } from '@/components/media/mediaDrag.js'
import { fileSize } from '@/components/media/fileSize.js'
import { useToast } from '@/components/ui/useToast.js'
import { can } from '@/stores/app.js'
import Modal from '@/components/ui/Modal.vue'
import { vp } from '@/utils/viewport.js'
import { t } from '@/i18n/index.js'

const canEdit = computed(() => can('media', 'edit'))
const toast = useToast()
const route = useRoute()
const router = useRouter()
const items = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const tree = ref({ folders: [], total: 0, unfiled: 0, ratios: undefined })
const picked = ref([]) // ids ticked for moving
const moveTarget = ref('')
const folder = computed({
  get: () => String(route.query.folder || ''),
  set: (v) => { router.replace({ query: { ...route.query, folder: v || undefined } }) },
})
const folderTitle = computed(() => (folder.value === 'none' ? t('forms.no_folder') : folder.value ? (tree.value.folders.find((f) => f.id === folder.value) || {}).name || t('forms.folder') : t('forms.tree_all')))
const loading = ref(true)
const replacing = ref(true)
const { cursors, position, accept, parameters } = useListCursor()
const loadError = ref('')
const q = ref('')
const type = ref('')
const selected = ref(null)
const selectedId = computed(() => selected.value?.id ?? null)
const { usage, usageError, retryUsage } = useMediaUsage(selectedId, (id) => api.media.usage(id))
const dragging = ref(false)
const progress = ref(null) // { done, total } while uploading
const fileInput = ref(null)
const camInput = ref(null)
const foldersOpen = ref(false) // a phone: the folder tree is a sheet
function chooseFolder(v) { folder.value = v; foldersOpen.value = false }

const countText = computed(() => t('forms.media_count', { shown: items.value.length, total: total.value }))
const folderOptions = computed(() => {
  const kids = new Map()
  for (const f of tree.value.folders) {
    const k = f.parentId || ''
    if (!kids.has(k)) kids.set(k, [])
    kids.get(k).push(f)
  }
  const out = []
  const walk = (parent, depth) => { for (const f of kids.get(parent) || []) { out.push({ id: f.id, label: '— '.repeat(depth) + f.name }); walk(f.id, depth + 1) } }
  walk('', 0)
  return out
})

async function loadTree() {
  try {
    tree.value = await api.media.folders()
    if (folder.value && folder.value !== 'none' && !tree.value.folders.some((f) => f.id === folder.value)) folder.value = '' // the folder is gone
  } catch (e) {
    /* the files still show; the tree stays as it was */
  }
}
let seq = 0
let timer = null
async function load(replace = false) {
  const my = ++seq
  loading.value = true
  if (replace) replacing.value = true
  loadError.value = ''
  try {
    const paging = parameters()
    const result = await api.media.list({ q: q.value, type: type.value, folder: folder.value, page: page.value, perPage: perPage.value, ...paging })
    if (my !== seq) return
    const last = Math.max(1, Math.ceil(result.total / result.perPage))
    if (!result.items.length && (result.page > last || paging.cursor)) return go(Math.min(result.page, last), true)
    accept(result)
    items.value = result.items
    total.value = result.total
    const fresh = items.value.find((m) => m.id === selectedId.value)
    if (fresh) selected.value = fresh
    replacing.value = false
  } catch (e) {
    if (my === seq) loadError.value = e.message || t('forms.media_load_failed')
  } finally {
    if (my === seq) loading.value = false
  }
}
function go(next, reset = false) {
  clearTimeout(timer)
  position(next, reset)
  page.value = next
  return load(true)
}
function setPerPage(size) {
  perPage.value = size
  go(1, true)
}
watch(folder, () => { selected.value = null; picked.value = []; go(1, true) })
watch(type, () => go(1, true))
watch(q, () => {
  ++seq
  position(1, true)
  page.value = 1
  replacing.value = loading.value = true
  clearTimeout(timer)
  timer = setTimeout(() => go(1, true), 180)
})
onBeforeUnmount(() => { ++seq; clearTimeout(timer) })
onMounted(() => { loadTree(); load() })
const refresh = () => Promise.all([loadTree(), load()])

function onChanged(item) {
  const belongs = !folder.value || (item.folderId || 'none') === folder.value
  if (!belongs) picked.value = picked.value.filter((id) => id !== item.id)
  if (item.id === selectedId.value) {
    selected.value = belongs ? item : null
  }
  refresh()
}
const canUploadTo = computed(() => (folder.value && folder.value !== 'none' ? folder.value : null))
function reset() { q.value = ''; type.value = '' }

async function uploadFiles(list) {
  const files = [...(list || [])]
  if (!files.length) return
  progress.value = { done: 0, total: files.length }
  let ok = 0
  let last = null
  const fails = []
  for (const file of files) {
    try { last = await api.media.upload(file, canUploadTo.value); ok++ } catch (e) { fails.push({ name: file.name, message: e.message || t('forms.upload_failed') }) }
    progress.value = { ...progress.value, done: progress.value.done + 1 }
  }
  progress.value = null
  await Promise.all([loadTree(), page.value === 1 ? load() : go(1, true)])
  if (ok) toast.show(ok === 1 ? t('forms.media_uploaded_one', { name: last.name }) : t('forms.media_uploaded_many', { count: ok }))
  if (fails.length === 1) toast.error(t('forms.media_fail_one', { name: fails[0].name, message: fails[0].message }))
  else if (fails.length) toast.error(t('forms.media_fail_many', { count: fails.length, message: fails[0].message }))
  if (ok === 1 && !fails.length && last) selected.value = last // one new file: show its details
}
function onPick(e) {
  const files = [...e.target.files]
  e.target.value = ''
  uploadFiles(files)
}
function onDrop(e) {
  dragging.value = false
  uploadFiles(e.dataTransfer && e.dataTransfer.files)
}
const onEnter = (e) => { if (!hasDrag(e)) dragging.value = true }

async function onDeleted(id) {
  if (selectedId.value === id) selected.value = null
  picked.value = picked.value.filter((pickedId) => pickedId !== id)
  await refresh()
}

// ---- moving files between folders
function togglePick(id) {
  picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id]
}
function onTileDrag(e, m) {
  startDrag(e, FILES, picked.value.includes(m.id) ? picked.value : [m.id])
}
async function moveFiles(ids, folderId) {
  if (!ids.length) return
  try {
    const r = await api.media.move(ids, folderId)
    const name = folderId ? (tree.value.folders.find((f) => f.id === folderId) || {}).name : ''
    const to = folderId ? name : t('forms.media_to_unfiled')
    const one = folderId && !name ? t('forms.media_moved_one_folder') : t('forms.media_moved_one', { to })
    const many = folderId && !name ? t('forms.media_moved_many_folder', { count: r.moved }) : t('forms.media_moved_many', { count: r.moved, to })
    toast.show(r.moved === 1 ? one : many)
    picked.value = []
    moveTarget.value = ''
    await refresh()
  } catch (e) {
    toast.error(e.message || t('forms.media_move_failed'))
  }
}
function moveChosen() {
  if (moveTarget.value === '') return
  moveFiles(picked.value, moveTarget.value === 'none' ? null : moveTarget.value)
}
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div>
        <h1>{{ t('forms.media_title') }}</h1>
        <p class="sub">{{ t('forms.media_sub') }}</p>
      </div>
      <div class="actions">
        <button v-if="canEdit" type="button" class="btn primary" :disabled="!!progress" @click="fileInput && fileInput.click()"><Icon name="upload" size="s16" />{{ vp.phone ? t('forms.media_upload_phone') : t('forms.media_upload') }}</button>
        <button v-if="canEdit && vp.phone" type="button" class="btn" :disabled="!!progress" @click="camInput && camInput.click()"><Icon name="image" size="s16" />{{ t('forms.media_camera') }}</button>
        <input v-if="canEdit && vp.phone" ref="camInput" type="file" accept="image/*" capture="environment" class="sr" tabindex="-1" aria-hidden="true" @change="onPick">
      </div>
    </div>

    <div class="ml">
    <div v-if="!vp.phone" class="sheet ml-tree">
      <FolderTree :model-value="folder" :folders="tree.folders" :total="tree.total" :unfiled="tree.unfiled" :can-edit="canEdit" @update:model-value="(v) => (folder = v)" @changed="refresh" @drop-files="moveFiles" />
    </div>

    <div class="sheet ml-main" :aria-busy="loading">
      <div class="toolbar">
        <h2 v-if="!vp.phone" class="ml-title">{{ folderTitle }}</h2>
        <button v-else type="button" class="btn ml-fold" aria-haspopup="dialog" @click="foldersOpen = true"><Icon name="folder" size="s16" /><span class="nm">{{ folderTitle }}</span><Icon name="chevD" size="s14" /></button>
        <div class="search">
          <label class="sr" for="mq">{{ t('forms.media_search') }}</label>
          <Icon name="search" size="s16" />
          <input id="mq" v-model="q" type="search" :placeholder="t('forms.media_search_placeholder')" autocomplete="off">
        </div>
        <select v-model="type" :aria-label="t('forms.media_type')">
          <option value="">{{ t('forms.media_type_any') }}</option>
          <option value="img">{{ t('forms.media_type_img') }}</option>
          <option value="pdf">{{ t('forms.media_type_pdf') }}</option>
        </select>
        <span class="grow num" aria-live="polite">{{ replacing ? '' : countText }}</span>
      </div>

      <label
        v-if="canEdit" :class="['drop', { on: dragging, busy: !!progress }]" for="mfile"
        @dragenter.prevent="onEnter" @dragover.prevent="onEnter" @dragleave.prevent="dragging = false" @drop.prevent="onDrop"
      >
        <span class="ill"><Icon name="upload" size="s20" /></span>
        <span v-if="progress"><b>{{ t('forms.media_progress', { current: progress.done + 1 > progress.total ? progress.total : progress.done + 1, total: progress.total }) }}</b><br><span class="muted md-small">{{ t('forms.media_wait') }}</span></span>
        <span v-else-if="vp.touch"><b>{{ t('forms.media_pick_touch') }}</b><br><span class="muted md-small">{{ t('forms.media_pick_touch_sub') }}</span></span>
        <span v-else><b>{{ t('forms.media_drop') }}</b><br><span class="muted md-small">{{ t('forms.media_drop_sub') }}</span></span>
        <input id="mfile" ref="fileInput" type="file" accept="image/*,.pdf,application/pdf" multiple class="sr" :disabled="!!progress" @change="onPick">
      </label>

      <div v-if="picked.length && canEdit" class="ml-bulk" role="region" :aria-label="t('forms.media_selected_region')">
        <b>{{ t('forms.media_selected', { count: picked.length }) }}</b>
        <select v-model="moveTarget" :aria-label="t('forms.media_move_label')" @change="moveChosen">
          <option value="" disabled>{{ t('forms.media_move_placeholder') }}</option>
          <option value="none">{{ t('forms.no_folder') }}</option>
          <option v-for="f in folderOptions" :key="f.id" :value="f.id">{{ f.label }}</option>
        </select>
        <button type="button" class="btn sm" @click="picked = []">{{ t('forms.media_unselect') }}</button>
      </div>

      <div v-if="loadError" class="md-pad"><div class="alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn sm" @click="load()">{{ t('forms.retry') }}</button></div></div>
      <div v-if="replacing && loading" class="md-pad"><Skeleton :rows="5" /></div>
      <div v-else-if="!replacing && items.length" class="mgrid">
        <div v-for="m in items" :key="m.id" class="mcell">
          <button
            type="button" class="mdi" aria-haspopup="dialog" :aria-pressed="picked.includes(m.id)" :draggable="canEdit"
            @click="($event.ctrlKey || $event.metaKey) && canEdit ? togglePick(m.id) : selected = m" @dragstart="onTileDrag($event, m)"
          >
            <span class="pic">
              <span v-if="m.type === 'pdf'" class="pdf"><Icon name="doc" size="s20" />PDF</span>
              <img v-else :src="m.thumb || thumbUrl(m.src)" alt="" loading="lazy" draggable="false">
            </span>
            <span class="nm">{{ m.name }}<small class="num">{{ fileSize(m.kb) }}</small></span>
          </button>
          <input v-if="canEdit" type="checkbox" class="mpick" :checked="picked.includes(m.id)" :aria-label="t('forms.media_pick_file', { name: m.name })" @change="togglePick(m.id)">
        </div>
      </div>
      <EmptyState v-else-if="!loadError && (q.trim() || type)" icon="search" :title="t('forms.media_none_title')" :text="t('forms.media_none_text')">
        <button type="button" class="btn" @click="reset">{{ t('forms.media_reset') }}</button>
      </EmptyState>
      <EmptyState v-else-if="!loadError && folder" icon="folder" :title="t('forms.media_folder_empty_title')" :text="canEdit ? t('forms.media_folder_empty_edit') : t('forms.media_folder_empty_read')" />
      <EmptyState v-else-if="!loadError" icon="image" :title="t('forms.media_empty_title')" :text="canEdit ? t('forms.media_empty_edit') : t('forms.media_empty_read')" />
      <ListPager v-if="total" :page="page" :per-page="perPage" :sizes="[25, 50, 100]" :total="total" :cursors="cursors" :busy="replacing && loading" @update:page="go" @update:per-page="setPerPage" />
    </div>
    </div>

    <Modal v-model:open="foldersOpen" :title="t('forms.tree_title')" :width="420">
      <FolderTree :model-value="folder" :folders="tree.folders" :total="tree.total" :unfiled="tree.unfiled" :can-edit="canEdit" @update:model-value="chooseFolder" @changed="refresh" @drop-files="moveFiles" />
    </Modal>
    <MediaDrawer :item="selected" :usage="usage" :usage-error="usageError" :folders="tree.folders" :ratios="tree.ratios" @retry-usage="retryUsage" @close="selected = null" @changed="onChanged" @deleted="onDeleted" />
  </div>
</template>

<style scoped>
.md-small{font-size:var(--t-sm)}
.ml{display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;align-items:start}
.ml-tree{position:sticky;top:20px;max-height:calc(100vh - 40px);overflow:auto}
.ml-main{min-width:0}
.ml-title{font-size:var(--t-md);font-weight:650;margin:0 6px 0 0}
.ml-bulk{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 14px;background:var(--cy-50);border-bottom:1px solid var(--cy-100)}
.ml-bulk select{width:auto;min-width:200px}
.mcell{position:relative;display:flex}
.mcell .mdi{flex:1;min-width:0}
.mpick{position:absolute;top:8px;left:8px;width:18px;height:18px;margin:0;opacity:0;cursor:pointer;accent-color:var(--cy)}
.mcell:hover .mpick,.mpick:focus-visible,.mpick:checked,.ml-main:has(.mpick:checked) .mpick{opacity:1}
@media (hover:none){.mpick{opacity:1}}
@media (max-width:767px){.ml{grid-template-columns:1fr}.ml-tree{position:static;max-height:none}}
.ml-fold{max-width:100%;justify-content:flex-start;gap:8px}
.ml-fold .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left;font-weight:600}
@media (max-width:767px){
  .mgrid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:0 12px 14px}
  /* the tick: a 24 px box inside a 44 px touch area */
  .mpick{appearance:none;-webkit-appearance:none;width:44px;height:44px;top:0;left:0;opacity:1;background:none;border:0;border-radius:0}
  .mpick::before{content:"";position:absolute;top:8px;left:8px;width:24px;height:24px;box-sizing:border-box;border:2px solid var(--ctl);border-radius:7px;background:#fff;box-shadow:0 1px 3px rgba(10,24,32,.25)}
  .mpick:checked::before{border-color:var(--cy);background:var(--cy) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='white' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3.5 8.5l3 3 6-6.5'/%3E%3C/svg%3E") center/16px no-repeat}
  .mpick:focus-visible::before{outline:3px solid var(--cy-100);outline-offset:1px}
  .ml-bulk{position:sticky;top:calc(52px + env(safe-area-inset-top));z-index:20;padding:8px 12px}
  .ml-bulk select{flex:1 1 100%;min-width:0}
  .ml-bulk .btn{flex:1 1 auto}
  .drop{margin:12px;padding:14px}
}
.md-pad{padding:14px}
.drop.busy{opacity:.7;cursor:progress}
.drop:focus-within{border-color:var(--cy);box-shadow:0 0 0 3px var(--cy-100)}
.mdi:focus-visible{outline-offset:2px}
</style>
